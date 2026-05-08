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
use core\task\manager as task_manager;
use tool_dataprivacy\api as dataprivacy_api;
use tool_dataprivacy\task\process_data_request_task;

/**
 * End-to-end test that drives the same tool_dataprivacy flow the admin UI
 * triggers: create_data_request -> approve_data_request -> run the adhoc
 * worker task. Verifies the block's privacy provider integrates cleanly
 * with the actual request pipeline (not just the bare Privacy API calls
 * exercised by provider_test).
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\privacy\provider
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dataprivacy_request_test extends advanced_testcase {
    /**
     * Seed a dismissal row.
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @param int $teacherid Teacher user ID who issued the dismissal.
     * @return int The new row's id.
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
     * Seed a grade-snapshot row.
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @return int The new row's id.
     */
    private function seed_snapshot(int $courseid, int $userid): int {
        global $DB;
        return $DB->insert_record('block_atrisk_grade_snapshots', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'snapshotweek' => 202601,
            'finalgrade' => 50.0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Drive the same path the tool_dataprivacy admin UI uses: create the
     * request, approve it, and run the queued adhoc worker.
     *
     * @param int $userid Subject user.
     * @param int $type api::DATAREQUEST_TYPE_EXPORT or DATAREQUEST_TYPE_DELETE.
     * @return \tool_dataprivacy\data_request The refreshed request record.
     */
    private function run_request(int $userid, int $type): \tool_dataprivacy\data_request {
        $datarequest = dataprivacy_api::create_data_request($userid, $type);
        $requestid = $datarequest->get('id');

        // Output buffering matches the core tests' pattern; the worker
        // emits progress lines we don't need in PHPUnit output.
        ob_start();
        dataprivacy_api::approve_data_request($requestid);
        $this->runAdhocTasks(process_data_request_task::class);
        ob_end_clean();

        return dataprivacy_api::get_request($requestid);
    }

    /**
     * Subject student with a dismissal: export request must be accepted
     * and a worker adhoc task queued. We cannot run the worker in PHPUnit
     * because Moodle swaps the privacy content_writer for a mock that
     * returns 'mock_path' from finalise_content(), which the file API
     * cannot open — that's why core's own test_approve_data_request stops
     * here too. The actual export bytes are covered by provider_test's
     * test_export_user_data_writes_for_subject.
     */
    public function test_export_request_queues_worker_for_subject_with_dismissal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $this->seed_snapshot($course->id, $student->id);

        $datarequest = dataprivacy_api::create_data_request(
            $student->id,
            dataprivacy_api::DATAREQUEST_TYPE_EXPORT
        );
        $requestid = $datarequest->get('id');

        ob_start();
        dataprivacy_api::approve_data_request($requestid);
        ob_end_clean();

        $request = dataprivacy_api::get_request($requestid);
        $this->assertEquals(
            dataprivacy_api::DATAREQUEST_STATUS_APPROVED,
            $request->get('status'),
            'export request must reach APPROVED'
        );
        $this->assertCount(
            1,
            task_manager::get_adhoc_tasks(process_data_request_task::class),
            'export must queue exactly one worker task'
        );
    }

    /**
     * Subject student with a dismissal: delete request must complete
     * (status DELETED) and the row must actually be gone.
     */
    public function test_delete_request_completes_and_removes_dismissal_for_subject(): void {
        $this->resetAfterTest();
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->seed_dismissal($course->id, $student->id, $teacher->id);
        $this->seed_snapshot($course->id, $student->id);

        $request = $this->run_request($student->id, dataprivacy_api::DATAREQUEST_TYPE_DELETE);

        $this->assertEquals(
            dataprivacy_api::DATAREQUEST_STATUS_DELETED,
            $request->get('status'),
            'delete request must reach DELETED (got ' . $request->get('status') . ')'
        );
        $this->assertEquals(0, $DB->count_records('block_atrisk_dismissals', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
        $this->assertEquals(0, $DB->count_records('block_atrisk_grade_snapshots', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
    }

    /**
     * Teacher (actor only) delete request: must complete, dismissal row must
     * remain (subject student still has rights to it) but dismissed_by must
     * be anonymised to 0 per FR-132.
     */
    public function test_delete_request_anonymises_dismissed_by_for_actor_only_user(): void {
        $this->resetAfterTest();
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $rowid = $this->seed_dismissal($course->id, $student->id, $teacher->id);

        $request = $this->run_request($teacher->id, dataprivacy_api::DATAREQUEST_TYPE_DELETE);

        $this->assertEquals(
            dataprivacy_api::DATAREQUEST_STATUS_DELETED,
            $request->get('status'),
            'delete request must reach DELETED (got ' . $request->get('status') . ')'
        );
        $row = $DB->get_record('block_atrisk_dismissals', ['id' => $rowid]);
        $this->assertNotFalse($row, 'row must remain (subject student still has rights to it)');
        $this->assertEquals(0, (int) $row->dismissed_by, 'actor anonymised to 0 per FR-132');
    }
}
