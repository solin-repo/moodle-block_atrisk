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

namespace block_atrisk\privacy;

use advanced_testcase;
use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API tests for block_atrisk. Covers FR-130 to FR-133 and FR-222.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\privacy\provider
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends advanced_testcase {
    /**
     * Seed one dismissal row.
     *
     * @param int $courseid
     * @param int $userid Subject user.
     * @param int $teacherid Actor user.
     * @return int New row id.
     */
    private function seed_dismissal(int $courseid, int $userid, int $teacherid): int {
        global $DB;
        return $DB->insert_record('block_atrisk_dismissals', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'dismissed_by' => $teacherid,
            'dismissed_at' => time(),
            'dismissed_until' => time() + WEEKSECS,
        ]);
    }

    /**
     * Seed one grade-snapshot row.
     *
     * @param int $courseid
     * @param int $userid
     * @param float|null $finalgrade
     * @return int New row id.
     */
    private function seed_snapshot(int $courseid, int $userid, ?float $finalgrade = 50.0): int {
        global $DB;
        return $DB->insert_record('block_atrisk_grade_snapshots', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'snapshotweek' => 202601,
            'finalgrade' => $finalgrade,
            'timecreated' => time(),
        ]);
    }

    public function test_get_metadata_declares_both_tables(): void {
        $this->resetAfterTest();
        $collection = new collection('block_atrisk');
        provider::get_metadata($collection);
        $items = $collection->get_collection();

        $tablenames = [];
        foreach ($items as $item) {
            $tablenames[] = $item->get_name();
        }
        $this->assertContains('block_atrisk_dismissals', $tablenames);
        $this->assertContains('block_atrisk_grade_snapshots', $tablenames);
    }

    public function test_get_contexts_for_userid_finds_dismissals_as_subject(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $contexts = $contextlist->get_contexts();
        $this->assertCount(1, $contexts);
        $this->assertEquals(context_course::instance($course->id)->id, $contexts[0]->id);
    }

    public function test_get_contexts_for_userid_finds_dismissals_as_actor(): void {
        // FR-132: dismissed_by users should also surface their dismissal-
        // as-actor records.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);

        $contextlist = provider::get_contexts_for_userid($teacher->id);
        $contexts = $contextlist->get_contexts();
        $this->assertCount(1, $contexts);
        $this->assertEquals(context_course::instance($course->id)->id, $contexts[0]->id);
    }

    public function test_get_contexts_for_userid_finds_snapshots(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->seed_snapshot($course->id, $student->id);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $contexts = $contextlist->get_contexts();
        $this->assertCount(1, $contexts);
    }

    public function test_get_users_in_context_returns_subjects_actors_and_snapshot_users(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $this->seed_snapshot($course->id, $other->id);

        $context = context_course::instance($course->id);
        $userlist = new userlist($context, 'block_atrisk');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();
        sort($userids);
        $expected = [$student->id, $teacher->id, $other->id];
        sort($expected);
        $this->assertEquals($expected, $userids);
    }

    public function test_delete_data_for_user_removes_dismissals_as_subject(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approved = new approved_contextlist(
            \core_user::get_user($student->id),
            'block_atrisk',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approved);

        $this->assertEquals(0, $DB->count_records('block_atrisk_dismissals', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
    }

    public function test_delete_data_for_user_anonymises_dismissed_by(): void {
        // FR-132: teacher delete sets dismissed_by = 0 instead of dropping.
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $id = $this->seed_dismissal($course->id, $student->id, $teacher->id);

        $contextlist = provider::get_contexts_for_userid($teacher->id);
        $approved = new approved_contextlist(
            \core_user::get_user($teacher->id),
            'block_atrisk',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approved);

        $row = $DB->get_record('block_atrisk_dismissals', ['id' => $id]);
        $this->assertNotFalse($row, 'row must remain (subject student still has rights to it)');
        $this->assertEquals(0, $row->dismissed_by, 'actor anonymised to 0');
    }

    public function test_delete_data_for_user_removes_snapshots(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->seed_snapshot($course->id, $student->id);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approved = new approved_contextlist(
            \core_user::get_user($student->id),
            'block_atrisk',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approved);

        $this->assertEquals(0, $DB->count_records('block_atrisk_grade_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
    }

    public function test_delete_data_for_users_handles_subject_actor_and_snapshot(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $this->seed_snapshot($course->id, $student->id);

        $context = context_course::instance($course->id);
        $approved = new approved_userlist($context, 'block_atrisk', [$student->id]);
        provider::delete_data_for_users($approved);

        // Student-as-subject row gone; snapshot row gone.
        $this->assertEquals(0, $DB->count_records('block_atrisk_dismissals', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
        $this->assertEquals(0, $DB->count_records('block_atrisk_grade_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));

        // Teacher-as-actor anonymised when teacher is included.
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $approved2 = new approved_userlist($context, 'block_atrisk', [$teacher->id]);
        provider::delete_data_for_users($approved2);
        $this->assertEquals(0, $DB->count_records('block_atrisk_dismissals', [
            'dismissed_by' => $teacher->id,
        ]));
    }

    public function test_delete_data_for_all_users_in_context(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $this->seed_snapshot($course->id, $student->id);

        provider::delete_data_for_all_users_in_context(context_course::instance($course->id));

        $this->assertEquals(0, $DB->count_records(
            'block_atrisk_dismissals',
            ['courseid' => $course->id]
        ));
        $this->assertEquals(0, $DB->count_records(
            'block_atrisk_grade_snapshots',
            ['courseid' => $course->id]
        ));
    }

    public function test_export_user_data_writes_for_subject(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $this->seed_snapshot($course->id, $student->id);

        $contextlist = provider::get_contexts_for_userid($student->id);
        $approved = new approved_contextlist(
            \core_user::get_user($student->id),
            'block_atrisk',
            $contextlist->get_contextids()
        );
        provider::export_user_data($approved);

        $context = context_course::instance($course->id);
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data(), 'writer must have data for the subject student');
    }
}
