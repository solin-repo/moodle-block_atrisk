<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_atrisk\task;

use advanced_testcase;

/**
 * Tests for the plugin's scheduled tasks.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(detect_course_shapes::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prune_dismissals::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prune_flag_snapshots::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(snapshot_flags::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(snapshot_grades::class)]
final class tasks_test extends advanced_testcase {
    /**
     * Create a course with an atrisk block instance and one enrolled student.
     *
     * @return array [course, student]
     */
    private function course_with_block_and_student(): array {
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => time() - 10 * WEEKSECS,
        ]);
        $this->getDataGenerator()->get_plugin_generator('block_atrisk')->create_instance([
            'parentcontextid' => \context_course::instance($course->id)->id,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $student];
    }

    /**
     * Every task has a human-readable name from the language pack.
     */
    public function test_task_names(): void {
        $this->assertSame(
            get_string('task_detect_course_shapes', 'block_atrisk'),
            (new detect_course_shapes())->get_name()
        );
        $this->assertSame(get_string('task_prune_dismissals', 'block_atrisk'), (new prune_dismissals())->get_name());
        $this->assertSame(
            get_string('task_prune_flag_snapshots', 'block_atrisk'),
            (new prune_flag_snapshots())->get_name()
        );
        $this->assertSame(get_string('task_snapshot_flags', 'block_atrisk'), (new snapshot_flags())->get_name());
        $this->assertSame(get_string('task_snapshot_grades', 'block_atrisk'), (new snapshot_grades())->get_name());
    }

    /**
     * detect_course_shapes stores a detection for every course carrying the block,
     * and nothing for courses without it.
     */
    public function test_detect_course_shapes_stores_detection_for_block_courses(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_block_and_student();
        $other = $this->getDataGenerator()->create_course();

        (new detect_course_shapes())->execute();

        $this->assertTrue($DB->record_exists('block_atrisk_course_shape', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('block_atrisk_course_shape', ['courseid' => $other->id]));
    }

    /**
     * A failure for one course is logged and does not abort the task.
     */
    public function test_detect_course_shapes_logs_and_continues_on_failure(): void {
        global $DB;
        $realdb = $DB;
        $mock = $this->createMock(\moodle_database::class);
        $mock->method('get_records_sql')->willReturn([(object) ['courseid' => 4242]]);
        $mock->method('get_record')->willThrowException(new \dml_read_exception('simulated failure'));

        $DB = $mock;
        try {
            $this->expectOutputRegex('/course-shape detection failed for course 4242: .*simulated failure/');
            (new detect_course_shapes())->execute();
        } finally {
            $DB = $realdb;
        }
    }

    /**
     * prune_dismissals deletes dismissals that expired before the retention
     * window and keeps newer ones, honoring the configured retention.
     */
    public function test_prune_dismissals_respects_retention(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $row = fn(int $until) => (object) [
            'courseid' => 1, 'userid' => 2, 'dismissed_by' => 3,
            'dismissed_at' => $until - WEEKSECS, 'dismissed_until' => $until,
            'signals_at_dismissal' => '[]',
        ];
        $old = $DB->insert_record('block_atrisk_dismissals', $row($now - 400 * DAYSECS));
        $mid = $DB->insert_record('block_atrisk_dismissals', $row($now - 100 * DAYSECS));
        $new = $DB->insert_record('block_atrisk_dismissals', $row($now + DAYSECS));

        // Default retention is 365 days.
        unset_config('dismissal_log_retention_days', 'block_atrisk');
        (new prune_dismissals())->execute();
        $this->assertFalse($DB->record_exists('block_atrisk_dismissals', ['id' => $old]));
        $this->assertTrue($DB->record_exists('block_atrisk_dismissals', ['id' => $mid]));

        // A shorter configured retention prunes the 100-day-old row too.
        set_config('dismissal_log_retention_days', 30, 'block_atrisk');
        (new prune_dismissals())->execute();
        $this->assertFalse($DB->record_exists('block_atrisk_dismissals', ['id' => $mid]));
        $this->assertTrue($DB->record_exists('block_atrisk_dismissals', ['id' => $new]));
    }

    /**
     * prune_flag_snapshots removes snapshot rows past the retention window.
     */
    public function test_prune_flag_snapshots(): void {
        global $DB;
        $this->resetAfterTest();
        $row = fn(int $created) => (object) [
            'courseid' => 1, 'userid' => 2, 'snapshotweek' => (int) date('oW', $created),
            'signal_name' => 'inactivity', 'metric_value' => 10, 'percentile' => null,
            'preset' => 'default', 'timecreated' => $created,
        ];
        $old = $DB->insert_record('block_atrisk_flag_snapshots', $row(time() - 400 * DAYSECS));
        $recent = $DB->insert_record('block_atrisk_flag_snapshots', $row(time() - DAYSECS));

        (new prune_flag_snapshots())->execute();

        $this->assertFalse($DB->record_exists('block_atrisk_flag_snapshots', ['id' => $old]));
        $this->assertTrue($DB->record_exists('block_atrisk_flag_snapshots', ['id' => $recent]));
    }

    /**
     * snapshot_flags writes this week's flags when flag logging is on.
     */
    public function test_snapshot_flags_writes_rows_when_logging_enabled(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $student] = $this->course_with_block_and_student();
        set_config('flag_logging_enabled', 1, 'block_atrisk');

        (new snapshot_flags())->execute();

        $this->assertTrue($DB->record_exists('block_atrisk_flag_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id, 'signal_name' => 'inactivity',
        ]));
    }

    /**
     * snapshot_grades writes one row per active student in block courses.
     */
    public function test_snapshot_grades_writes_rows(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $student] = $this->course_with_block_and_student();
        // Creating a grade item makes sure the course total item exists.
        $this->getDataGenerator()->create_grade_item(['courseid' => $course->id]);

        (new snapshot_grades())->execute();

        $this->assertTrue($DB->record_exists('block_atrisk_grade_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
    }
}
