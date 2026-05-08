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

namespace block_atrisk\local;

use advanced_testcase;

/**
 * Unit tests for {@see flag_snapshot_writer} — the weekly recalibration
 * snapshot task (FR-119 cluster).
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\flag_snapshot_writer
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flag_snapshot_writer_test extends advanced_testcase {
    /**
     * Build a course past the calibration window with the at-risk block
     * instantiated and the given student enrolled.
     *
     * @param int $now Reference timestamp.
     * @return array Tuple of [course, student, blockinstanceid].
     */
    private function setup_course_with_block_and_student(int $now): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => $now - 10 * WEEKSECS,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Instantiate the block on the course context.
        $context = \context_course::instance($course->id);
        $DB->insert_record('block_instances', (object) [
            'blockname' => 'atrisk',
            'parentcontextid' => $context->id,
            'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return [$course, $student];
    }

    public function test_run_is_noop_when_logging_disabled(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('flag_logging_enabled', 0, 'block_atrisk');

        $now = 1_700_000_000;
        [$course, $student] = $this->setup_course_with_block_and_student($now);
        // Make student trigger inactivity.
        // No user_lastaccess row → inactivity fires.

        $writer = new flag_snapshot_writer();
        $written = $writer->run($now);
        $this->assertSame(0, $written);
        $this->assertSame(0, $DB->count_records('block_atrisk_flag_snapshots'));
    }

    public function test_run_writes_one_row_per_signal_when_enabled(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('flag_logging_enabled', 1, 'block_atrisk');
        // Enable inactivity signal at site-level.
        set_config('signal_inactivity_enabled', 1, 'block_atrisk');

        $now = 1_700_000_000;
        [$course, $student] = $this->setup_course_with_block_and_student($now);
        // Inactivity-no-access fires for this student (no user_lastaccess row).

        $writer = new flag_snapshot_writer();
        $written = $writer->run($now);

        $this->assertGreaterThan(0, $written);
        $rows = $DB->get_records('block_atrisk_flag_snapshots');
        $this->assertNotEmpty($rows);
        // The student should have at least one row for inactivity.
        $row = reset($rows);
        $this->assertSame((int) $course->id, (int) $row->courseid);
        $this->assertSame((int) $student->id, (int) $row->userid);
        $this->assertSame(grade_snapshot_writer::iso_week($now), (int) $row->snapshotweek);
        $this->assertSame('inactivity', $row->signal_name);
    }

    public function test_run_is_idempotent_within_same_week(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('flag_logging_enabled', 1, 'block_atrisk');
        set_config('signal_inactivity_enabled', 1, 'block_atrisk');

        $now = 1_700_000_000;
        [$course, $student] = $this->setup_course_with_block_and_student($now);

        $writer = new flag_snapshot_writer();
        $writer->run($now);
        $beforecount = $DB->count_records('block_atrisk_flag_snapshots');
        // Re-run within the same ISO week.
        $writer->run($now + 60);
        $aftercount = $DB->count_records('block_atrisk_flag_snapshots');

        $this->assertSame($beforecount, $aftercount, 'Re-running within same ISO week must not duplicate rows.');
    }

    public function test_prune_removes_rows_older_than_retention(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('flag_log_retention_days', 30, 'block_atrisk');

        $now = 1_700_000_000;
        // Insert one ancient row (60 days old) and one recent row (1 day old).
        $DB->insert_record('block_atrisk_flag_snapshots', (object) [
            'courseid' => 42, 'userid' => 1, 'snapshotweek' => 202740,
            'signal_name' => 'inactivity', 'metric_value' => 14, 'percentile' => 12,
            'preset' => 'default', 'timecreated' => $now - 60 * DAYSECS,
        ]);
        $DB->insert_record('block_atrisk_flag_snapshots', (object) [
            'courseid' => 42, 'userid' => 2, 'snapshotweek' => 202745,
            'signal_name' => 'inactivity', 'metric_value' => 7, 'percentile' => 30,
            'preset' => 'default', 'timecreated' => $now - 1 * DAYSECS,
        ]);

        $writer = new flag_snapshot_writer();
        $writer->prune($now);

        $remaining = $DB->get_records('block_atrisk_flag_snapshots');
        $this->assertCount(1, $remaining, 'Only the recent row should survive a 30-day retention prune.');
        $this->assertSame(2, (int) reset($remaining)->userid);
    }
}
