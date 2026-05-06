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
 * Unit tests for the dismissal service.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\dismissal_service
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dismissal_service_test extends advanced_testcase {
    public function test_dismiss_creates_record_with_one_week_window(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $now = 1_700_000_000;
        $id = dismissal_service::dismiss($course->id, $student->id, $teacher->id, $now);

        $row = $DB->get_record('block_atrisk_dismissals', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals($course->id, $row->courseid);
        $this->assertEquals($student->id, $row->userid);
        $this->assertEquals($teacher->id, $row->dismissed_by);
        $this->assertEquals($now, $row->dismissed_at);
        $this->assertEquals($now + 7 * DAYSECS, $row->dismissed_until);
    }

    public function test_dismiss_is_idempotent_and_updates_window(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $first = dismissal_service::dismiss($course->id, $student->id, $teacher->id, 1_700_000_000);
        $second = dismissal_service::dismiss($course->id, $student->id, $teacher->id, 1_700_500_000);

        $this->assertSame($first, $second, 'second dismissal updates the same row');
        $this->assertEquals(1, $DB->count_records('block_atrisk_dismissals', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
        $row = $DB->get_record('block_atrisk_dismissals', ['id' => $first]);
        $this->assertEquals(1_700_500_000 + 7 * DAYSECS, $row->dismissed_until);
    }

    public function test_undo_removes_row(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        dismissal_service::dismiss($course->id, $student->id, $teacher->id);

        dismissal_service::undo($course->id, $student->id);

        $this->assertEquals(0, $DB->count_records('block_atrisk_dismissals', [
            'courseid' => $course->id, 'userid' => $student->id,
        ]));
    }

    public function test_is_dismissed_within_window(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $now = 1_700_000_000;
        dismissal_service::dismiss($course->id, $student->id, $teacher->id, $now);

        $this->assertTrue(dismissal_service::is_dismissed($course->id, $student->id, $now + 1 * DAYSECS));
        $this->assertTrue(dismissal_service::is_dismissed($course->id, $student->id, $now + 6 * DAYSECS));
    }

    public function test_is_dismissed_after_window_returns_false(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $now = 1_700_000_000;
        dismissal_service::dismiss($course->id, $student->id, $teacher->id, $now);

        $this->assertFalse(dismissal_service::is_dismissed(
            $course->id,
            $student->id,
            $now + 8 * DAYSECS
        ));
    }

    /**
     * Capturing the flag state at dismissal time (FR-72d) — the optional
     * signals_at_dismissal payload should round-trip through the column
     * as JSON so end-of-term recalibration analysis can read it.
     */
    public function test_dismiss_stores_signals_at_dismissal_payload(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $now = 1_700_000_000;
        $payload = [
            'signals' => ['inactivity', 'assessment_miss'],
            'severity' => 'red',
            'preset' => 'default',
        ];
        $id = dismissal_service::dismiss(
            $course->id,
            $student->id,
            $teacher->id,
            $now,
            $payload
        );

        $row = $DB->get_record('block_atrisk_dismissals', ['id' => $id], '*', MUST_EXIST);
        $this->assertNotNull($row->signals_at_dismissal);
        $this->assertEquals($payload, json_decode($row->signals_at_dismissal, true));
    }

    /**
     * Backward-compat: callers that don't pass signals_at_dismissal must
     * still produce a valid row (column simply NULL).
     */
    public function test_dismiss_without_signals_payload_leaves_column_null(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $id = dismissal_service::dismiss($course->id, $student->id, $teacher->id);
        $row = $DB->get_record('block_atrisk_dismissals', ['id' => $id], '*', MUST_EXIST);
        $this->assertNull($row->signals_at_dismissal);
    }
}
