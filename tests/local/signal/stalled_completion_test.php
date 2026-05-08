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

/**
 * Unit tests for the stalled-completion signal.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\signal\stalled_completion
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stalled_completion_test extends advanced_testcase {
    /**
     * Insert N course-modules-completion rows for $userid in $course at
     * $cmids of distinct activities. Returns the array of cmids used.
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @param int $count Number of completion rows to create.
     * @param int $now Reference timestamp for the completion rows.
     * @param int $state Completion state to record.
     * @return array Course-module IDs used.
     */
    private function record_completions(int $courseid, int $userid, int $count, int $now, int $state = COMPLETION_COMPLETE): array {
        global $DB;
        $cmids = [];
        for ($i = 0; $i < $count; $i++) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'course' => $courseid,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ]);
            $cm = get_coursemodule_from_instance('assign', $assign->id, $courseid);
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id,
                'userid' => $userid,
                'completionstate' => $state,
                'timemodified' => $now - DAYSECS,
            ]);
            $cmids[] = $cm->id;
        }
        return $cmids;
    }

    public function test_bottom_quartile_with_peer_median_above_one_fires(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $now = 1_700_000_000;

        // 4 students. 3 of them have 5 completions each. The 4th has 0.
        // peer median = 5 (≥1), bottom-quartile cutoff includes user with 0.
        $userids = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->record_completions($course->id, $u->id, 5, $now);
            $userids[] = $u->id;
        }
        $stalled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($stalled->id, $course->id, 'student');
        $userids[] = $stalled->id;

        $signal = new stalled_completion();
        $results = $signal->evaluate($course->id, $userids, ['days' => 14], $now);

        $this->assertTrue($results[$stalled->id]->triggered);
        $this->assertEquals(0, $results[$stalled->id]->metric);
        // The three active students do not fire.
        foreach (array_slice($userids, 0, 3) as $uid) {
            $this->assertFalse($results[$uid]->triggered);
        }
    }

    public function test_peer_median_zero_disables_signal(): void {
        // FR-25: peer median = 0 → signal auto-disables for the cohort
        // (everyone's quiet — there's nothing to compare against).
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $now = 1_700_000_000;

        $userids = [];
        for ($i = 0; $i < 4; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $userids[] = $u->id;
        }

        $signal = new stalled_completion();
        $results = $signal->evaluate($course->id, $userids, ['days' => 14], $now);

        foreach ($userids as $uid) {
            $this->assertFalse($results[$uid]->triggered, 'no flag when whole cohort is quiet');
        }
    }

    public function test_completion_states_1_2_3_4_all_count(): void {
        // FR-24 generalisation: all four "completed" states count as
        // engagement.
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $now = 1_700_000_000;

        // Three peers with 5 COMPLETE_PASS completions each — establishes
        // a high peer median.
        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->record_completions($course->id, $u->id, 5, $now, COMPLETION_COMPLETE_PASS);
            $peers[] = $u->id;
        }

        // Subject student with 5 completions, one in each "completed" state.
        // Should NOT be in bottom quartile.
        $subject = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        $states = [
            COMPLETION_COMPLETE,
            COMPLETION_COMPLETE_PASS,
            COMPLETION_COMPLETE_FAIL,
            4, // COMPLETION_COMPLETE_FAIL_HIDDEN.
            COMPLETION_COMPLETE,
        ];
        foreach ($states as $state) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ]);
            $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id,
                'userid' => $subject->id,
                'completionstate' => $state,
                'timemodified' => $now - DAYSECS,
            ]);
        }

        $signal = new stalled_completion();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$subject->id]),
            ['days' => 14],
            $now
        );

        $this->assertFalse($results[$subject->id]->triggered, 'fail state still counts as engagement');
        $this->assertEquals(5, $results[$subject->id]->metric);
    }

    public function test_completions_outside_lookback_window_do_not_count(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $now = 1_700_000_000;

        // Three peers with 5 recent completions each.
        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->record_completions($course->id, $u->id, 5, $now);
            $peers[] = $u->id;
        }

        // Subject has 5 completions but ALL outside the 14-day window.
        $subject = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        for ($i = 0; $i < 5; $i++) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ]);
            $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id,
                'userid' => $subject->id,
                'completionstate' => COMPLETION_COMPLETE,
                'timemodified' => $now - 30 * DAYSECS,
            ]);
        }

        $signal = new stalled_completion();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$subject->id]),
            ['days' => 14],
            $now
        );

        $this->assertTrue($results[$subject->id]->triggered, 'old completions should not save the subject');
        $this->assertEquals(0, $results[$subject->id]->metric);
    }

    public function test_completionstate_zero_does_not_count(): void {
        // State 0 (INCOMPLETE) means started but not done.
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $now = 1_700_000_000;

        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->record_completions($course->id, $u->id, 5, $now);
            $peers[] = $u->id;
        }

        $subject = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        for ($i = 0; $i < 5; $i++) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ]);
            $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id, 'userid' => $subject->id,
                'completionstate' => COMPLETION_INCOMPLETE,
                'timemodified' => $now - DAYSECS,
            ]);
        }

        $signal = new stalled_completion();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$subject->id]),
            ['days' => 14],
            $now
        );
        $this->assertTrue($results[$subject->id]->triggered);
    }

    public function test_explanation_includes_count_and_peer_median(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $now = 1_700_000_000;

        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->record_completions($course->id, $u->id, 5, $now);
            $peers[] = $u->id;
        }
        $subject = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        $this->record_completions($course->id, $subject->id, 1, $now);

        $signal = new stalled_completion();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$subject->id]),
            ['days' => 14],
            $now
        );

        $this->assertStringContainsString('completed 1', $results[$subject->id]->explanation);
        $this->assertStringContainsString('14 days', $results[$subject->id]->explanation);
        $this->assertStringContainsString('peer median 5', $results[$subject->id]->explanation);
    }
}
