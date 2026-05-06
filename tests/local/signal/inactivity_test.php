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

namespace block_atrisk\local\signal;

use advanced_testcase;
use block_atrisk\local\signal_result;

/**
 * Unit tests for the inactivity signal classifier.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\signal\inactivity
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class inactivity_test extends advanced_testcase {
    public function test_no_lastaccess_row_treated_as_no_recorded_access(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // No user_lastaccess row for this user/course.
        $signal = new inactivity();
        $now = 1_700_000_000;
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 7], $now);

        $this->assertArrayHasKey($student->id, $results);
        $r = $results[$student->id];
        $this->assertInstanceOf(signal_result::class, $r);
        $this->assertTrue($r->triggered);
        $this->assertNull($r->metric, 'metric is null when no row exists');
        $this->assertStringContainsString('no recorded course access', $r->explanation);
    }

    public function test_recent_access_does_not_trigger(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = 1_700_000_000;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id,
            'courseid' => $course->id,
            'timeaccess' => $now - (3 * DAYSECS), // 3 days ago.
        ]);

        $signal = new inactivity();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 7], $now);

        $this->assertFalse($results[$student->id]->triggered);
        $this->assertEquals(3, $results[$student->id]->metric, 'metric is days since access');
    }

    public function test_old_access_triggers(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = 1_700_000_000;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id,
            'courseid' => $course->id,
            'timeaccess' => $now - (10 * DAYSECS), // 10 days ago, threshold 7.
        ]);

        $signal = new inactivity();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 7], $now);

        $this->assertTrue($results[$student->id]->triggered);
        $this->assertEquals(10, $results[$student->id]->metric);
        $this->assertStringContainsString('no course access since', $results[$student->id]->explanation);
        $this->assertStringContainsString('10', $results[$student->id]->explanation);
    }

    public function test_exact_threshold_triggers(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = 1_700_000_000;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id,
            'courseid' => $course->id,
            'timeaccess' => $now - (7 * DAYSECS), // Exactly 7 days ago.
        ]);

        $signal = new inactivity();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 7], $now);

        // FR-10: "≥ N days" — 7 days exactly should trigger.
        $this->assertTrue($results[$student->id]->triggered);
    }

    public function test_does_not_fall_back_to_user_lastaccess_site_field(): void {
        // FR-11 explicit: site-level user.lastaccess SHALL NOT be a fallback.
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = 1_700_000_000;
        // Update the user.lastaccess (site-level) to "very recent" — should
        // be ignored by the course-scoped signal.
        $DB->update_record('user', (object) [
            'id' => $student->id,
            'lastaccess' => $now - 60, // 1 minute ago.
        ]);
        // No user_lastaccess row though.

        $signal = new inactivity();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 7], $now);

        // Signal still fires — because course-level access is what counts.
        $this->assertTrue($results[$student->id]->triggered);
    }

    public function test_multiple_users_returned_in_one_call(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $userrecent = $this->getDataGenerator()->create_user();
        $userold = $this->getDataGenerator()->create_user();
        $usernever = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($userrecent->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($userold->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($usernever->id, $course->id, 'student');

        $now = 1_700_000_000;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $userrecent->id, 'courseid' => $course->id, 'timeaccess' => $now - 2 * DAYSECS,
        ]);
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $userold->id, 'courseid' => $course->id, 'timeaccess' => $now - 30 * DAYSECS,
        ]);

        $signal = new inactivity();
        $results = $signal->evaluate(
            $course->id,
            [$userrecent->id, $userold->id, $usernever->id],
            ['days' => 7],
            $now
        );

        $this->assertCount(3, $results);
        $this->assertFalse($results[$userrecent->id]->triggered);
        $this->assertTrue($results[$userold->id]->triggered);
        $this->assertTrue($results[$usernever->id]->triggered);
    }
}
