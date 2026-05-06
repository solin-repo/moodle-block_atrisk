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
 * Unit tests for the weekly grade snapshot writer.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\grade_snapshot_writer
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_snapshot_writer_test extends advanced_testcase {
    /**
     * Insert a block_atrisk instance into the given course context, and
     * force creation of the course-total grade item so the snapshot
     * writer has something to record.
     *
     * @param int $courseid
     */
    private function add_block_to_course(int $courseid): void {
        global $DB;
        $context = \context_course::instance($courseid);

        $DB->insert_record('block_instances', (object) [
            'blockname' => 'atrisk',
            'parentcontextid' => $context->id,
            'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Force creation of the course-total grade_item — fresh test
        // courses don't have one until something triggers it. Without
        // this, FR-22 ("no course-total → no rows written") would suppress
        // the snapshot.
        global $CFG;
        require_once($CFG->libdir . '/grade/grade_category.php');
        \grade_category::fetch_course_category($courseid)->load_grade_item();
    }

    public function test_snapshot_writes_one_row_per_active_student(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->add_block_to_course($course->id);
        $s1 = $this->getDataGenerator()->create_user();
        $s2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($s1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($s2->id, $course->id, 'student');

        $writer = new grade_snapshot_writer();
        $now = 1_700_000_000;
        $count = $writer->run($now);

        $this->assertEquals(2, $count);
        $rows = $DB->get_records('block_atrisk_grade_snapshots', ['courseid' => $course->id]);
        $this->assertCount(2, $rows);
    }

    public function test_finalgrade_null_when_student_has_no_grade(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->add_block_to_course($course->id);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $writer = new grade_snapshot_writer();
        $writer->run(1_700_000_000);

        $row = $DB->get_record('block_atrisk_grade_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]);
        $this->assertNotFalse($row);
        $this->assertNull($row->finalgrade);
    }

    public function test_upsert_does_not_duplicate_within_same_week(): void {
        // FR-118 upsert: same (courseid, userid, snapshotweek) → update,
        // not insert.
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->add_block_to_course($course->id);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $writer = new grade_snapshot_writer();
        $now = 1_700_000_000;
        $writer->run($now);
        $writer->run($now); // Same week, manual re-run.

        $rows = $DB->get_records('block_atrisk_grade_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]);
        $this->assertCount(1, $rows, 'second run within same ISO week must not duplicate');
    }

    public function test_skips_courses_without_block(): void {
        $this->resetAfterTest();
        global $DB;

        $courseno = $this->getDataGenerator()->create_course();
        $courseyes = $this->getDataGenerator()->create_course();
        $this->add_block_to_course($courseyes->id);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $courseno->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $courseyes->id, 'student');

        (new grade_snapshot_writer())->run(1_700_000_000);

        $this->assertEquals(0, $DB->count_records('block_atrisk_grade_snapshots', [
            'courseid' => $courseno->id,
        ]));
        $this->assertEquals(1, $DB->count_records('block_atrisk_grade_snapshots', [
            'courseid' => $courseyes->id,
        ]));
    }

    public function test_excludes_suspended_users(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->add_block_to_course($course->id);
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->getDataGenerator()->enrol_user($active->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($suspended->id, $course->id, 'student');

        (new grade_snapshot_writer())->run(1_700_000_000);

        $rows = $DB->get_records('block_atrisk_grade_snapshots', ['courseid' => $course->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals($active->id, $row->userid);
    }

    public function test_excludes_teachers(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->add_block_to_course($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        (new grade_snapshot_writer())->run(1_700_000_000);

        $rows = $DB->get_records('block_atrisk_grade_snapshots', ['courseid' => $course->id]);
        $this->assertCount(1, $rows);
        $this->assertEquals($student->id, reset($rows)->userid);
    }

    public function test_iso_week_format(): void {
        // 2026-01-04 falls in ISO week 2026-W01.
        $ts = strtotime('2026-01-04 12:00:00 UTC');
        $this->assertEquals(202601, grade_snapshot_writer::iso_week($ts));
    }
}
