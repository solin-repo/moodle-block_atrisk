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
 * Integration tests for the flag engine — orchestrates the full
 * evaluation pass.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\flag_engine
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flag_engine_test extends advanced_testcase {
    /**
     * Make a course confidently past its calibration window.
     */
    private function make_course_past_calibration(int $now): \stdClass {
        return $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => $now - 10 * WEEKSECS,
        ]);
    }

    public function test_flagged_student_with_inactivity_only_is_yellow(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // No user_lastaccess row → inactivity fires.

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [], $now);

        $this->assertCount(1, $flagged);
        $this->assertEquals($student->id, $flagged[0]->userid);
        $this->assertEquals(severity::YELLOW, $flagged[0]->severity);
        $this->assertEquals(calibration_window::PHASE_CONFIDENT, $flagged[0]->phase);
        $this->assertArrayHasKey('inactivity', $flagged[0]->triggered);
    }

    public function test_two_signals_yields_red(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);

        // Set up a peer baseline for stalled-completion: 3 active peers
        // each with 5 completions.
        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $u->id, 'courseid' => $course->id,
                'timeaccess' => $now - 1 * DAYSECS,
            ]);
            for ($j = 0; $j < 5; $j++) {
                $assign = $this->getDataGenerator()->create_module('assign', [
                    'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
                ]);
                $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
                $DB->insert_record('course_modules_completion', (object) [
                    'coursemoduleid' => $cm->id, 'userid' => $u->id,
                    'completionstate' => COMPLETION_COMPLETE,
                    'timemodified' => $now - DAYSECS,
                ]);
            }
            $peers[] = $u->id;
        }

        // Subject: inactive AND stalled.
        $subject = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        // No user_lastaccess row + no completions → both fire.

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [
            'signals' => [
                'inactivity' => true, 'assessment_miss' => false,
                'grade_trend' => false, 'stalled_completion' => true,
                'forum_silence' => false,
            ],
        ], $now);

        $subjectresult = null;
        foreach ($flagged as $f) {
            if ($f->userid === (int) $subject->id) {
                $subjectresult = $f;
                break;
            }
        }
        $this->assertNotNull($subjectresult, 'subject must be flagged');
        $this->assertEquals(severity::RED, $subjectresult->severity);
        $this->assertEquals(2, $subjectresult->triggered_count());
    }

    public function test_dismissed_student_is_excluded(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Active dismissal.
        $DB->insert_record('block_atrisk_dismissals', (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
            'dismissed_by' => $teacher->id,
            'dismissed_at' => $now - DAYSECS,
            'dismissed_until' => $now + 6 * DAYSECS,
        ]);

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [], $now);

        $this->assertEmpty($flagged, 'dismissed student should not appear');
    }

    public function test_expired_dismissal_does_not_exclude(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Expired dismissal — dismissed_until in the past.
        $DB->insert_record('block_atrisk_dismissals', (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
            'dismissed_by' => $teacher->id,
            'dismissed_at' => $now - 14 * DAYSECS,
            'dismissed_until' => $now - 1 * DAYSECS,
        ]);

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [], $now);

        $this->assertCount(1, $flagged);
    }

    public function test_gated_phase_runs_only_inactivity(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        // Course started today — every student is in week 1 (gated).
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => $now,
        ]);

        // Three peers with 5 completions each, recent access.
        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $u->id, 'courseid' => $course->id,
                'timeaccess' => $now - 1 * DAYSECS,
            ]);
            for ($j = 0; $j < 5; $j++) {
                $assign = $this->getDataGenerator()->create_module('assign', [
                    'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
                ]);
                $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
                $DB->insert_record('course_modules_completion', (object) [
                    'coursemoduleid' => $cm->id, 'userid' => $u->id,
                    'completionstate' => COMPLETION_COMPLETE,
                    'timemodified' => $now - DAYSECS,
                ]);
            }
            $peers[] = $u->id;
        }

        // Subject — would normally fire stalled-completion + inactivity in
        // confident phase; but in gated phase only inactivity should fire.
        $subject = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [], $now);

        $subjectresult = null;
        foreach ($flagged as $f) {
            if ($f->userid === (int) $subject->id) {
                $subjectresult = $f;
            }
        }
        $this->assertNotNull($subjectresult);
        $this->assertEquals(calibration_window::PHASE_GATED, $subjectresult->phase);
        $this->assertArrayHasKey('inactivity', $subjectresult->triggered);
        $this->assertArrayNotHasKey('stalled_completion', $subjectresult->triggered);
    }

    public function test_sort_order_red_before_yellow(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);

        // Peer baseline.
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $u->id, 'courseid' => $course->id,
                'timeaccess' => $now - 1 * DAYSECS,
            ]);
            for ($j = 0; $j < 5; $j++) {
                $assign = $this->getDataGenerator()->create_module('assign', [
                    'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
                ]);
                $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
                $DB->insert_record('course_modules_completion', (object) [
                    'coursemoduleid' => $cm->id, 'userid' => $u->id,
                    'completionstate' => COMPLETION_COMPLETE,
                    'timemodified' => $now - DAYSECS,
                ]);
            }
        }

        $yellowuser = $this->getDataGenerator()->create_user(['lastname' => 'AAA']);
        $reduser = $this->getDataGenerator()->create_user(['lastname' => 'ZZZ']);
        $this->getDataGenerator()->enrol_user($yellowuser->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($reduser->id, $course->id, 'student');

        // Yellow user: only inactivity (recent dismissed via user_lastaccess
        // missing — so inactivity DOES fire). Give them completions to avoid
        // stalled.
        for ($j = 0; $j < 5; $j++) {
            $assign = $this->getDataGenerator()->create_module('assign', [
                'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ]);
            $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id, 'userid' => $yellowuser->id,
                'completionstate' => COMPLETION_COMPLETE,
                'timemodified' => $now - DAYSECS,
            ]);
        }
        // Red user: inactivity + stalled completion (no completions at all).

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [
            'signals' => [
                'inactivity' => true, 'assessment_miss' => false,
                'grade_trend' => false, 'stalled_completion' => true,
                'forum_silence' => false,
            ],
        ], $now);

        $this->assertCount(2, $flagged);
        // Red ZZZ user must come first despite alphabetical disadvantage.
        $this->assertEquals($reduser->id, $flagged[0]->userid);
        $this->assertEquals(severity::RED, $flagged[0]->severity);
        $this->assertEquals(severity::YELLOW, $flagged[1]->severity);
    }

    /**
     * For high-is-bad signals (inactivity, miss count) the displayed
     * percentile must be inverted so "lower percentile = more at risk"
     * holds uniformly. A student with the highest days-idle metric in
     * a cohort should report the LOWEST percentile, not the highest.
     */
    public function test_worst_percentile_inverts_high_is_bad_signal(): void {
        $engine = new flag_engine();

        // Cohort: five students, days-idle spread.
        $cohortinactivity = [
            1 => new signal_result(true, '', 1),
            2 => new signal_result(true, '', 3),
            3 => new signal_result(true, '', 7),
            4 => new signal_result(true, '', 14),
            5 => new signal_result(true, '', 21),
        ];

        // Student 5 is the most idle. Raw rank from peer_percentile would
        // place them at the high end (~90); after direction inversion the
        // engine should report a low percentile.
        $worstuser = ['inactivity' => $cohortinactivity[5]];
        $rank = $engine->worst_percentile($worstuser, ['inactivity' => $cohortinactivity]);
        $this->assertNotNull($rank);
        $this->assertLessThan(50, $rank);

        // The least-idle student must report the HIGHEST percentile.
        $bestuser = ['inactivity' => $cohortinactivity[1]];
        $bestrank = $engine->worst_percentile($bestuser, ['inactivity' => $cohortinactivity]);
        $this->assertNotNull($bestrank);
        $this->assertGreaterThan(50, $bestrank);
    }

    /**
     * Same-severity students with the same triggered-signal count must
     * be ordered by their worst peer-percentile (lower = more at risk),
     * NOT alphabetically by name. Captures the bug where a more-idle
     * student appeared lower in the list because of a name tiebreaker.
     */
    public function test_sort_uses_percentile_before_name_for_same_tier(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);

        // Peer baseline so percentile rank has spread: three active peers.
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $u->id, 'courseid' => $course->id,
                'timeaccess' => $now - 1 * DAYSECS,
            ]);
        }

        // Two flagged students, same severity (yellow / 1 signal) — only
        // inactivity fires. Names chosen so alphabetical order DISAGREES
        // with risk order: less-idle "Mostafa" would otherwise sort above
        // more-idle "Nguyen".
        $lessidle = $this->getDataGenerator()->create_user(['lastname' => 'Mostafa']);
        $moreidle = $this->getDataGenerator()->create_user(['lastname' => 'Nguyen']);
        $this->getDataGenerator()->enrol_user($lessidle->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($moreidle->id, $course->id, 'student');
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $lessidle->id, 'courseid' => $course->id,
            'timeaccess' => $now - 6 * DAYSECS,
        ]);
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $moreidle->id, 'courseid' => $course->id,
            'timeaccess' => $now - 12 * DAYSECS,
        ]);

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [
            'signals' => [
                'inactivity' => true, 'assessment_miss' => false,
                'grade_trend' => false, 'stalled_completion' => false,
                'forum_silence' => false,
            ],
            'thresholds' => ['inactivity' => ['days' => 5]],
        ], $now);

        $this->assertCount(2, $flagged);
        $this->assertEquals(
            $moreidle->id,
            $flagged[0]->userid,
            'More-idle student must sort first despite lexicographic disadvantage.'
        );
        $this->assertEquals($lessidle->id, $flagged[1]->userid);
        $this->assertLessThan(
            $flagged[1]->worstpercentile,
            $flagged[0]->worstpercentile,
            'Top row must have a lower (worse) percentile than the row below.'
        );
    }

    /**
     * A student inactive across a configured break should have the
     * break's overlap subtracted from their elapsed-idle time. With a
     * 14-day break ending 2 days ago and a 7-day inactivity threshold,
     * a student last seen the day before the break (~16 days ago raw
     * but only 2 days "real" idle) must NOT trigger inactivity.
     */
    public function test_breaks_dampen_inactivity(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        $course = $this->make_course_past_calibration($now);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        // Last access was 16 days ago — would normally trigger inactivity
        // at the default 7-day threshold.
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id, 'courseid' => $course->id,
            'timeaccess' => $now - 16 * DAYSECS,
        ]);

        // Break ran from 15 days ago through 2 days ago (13 day overlap).
        $breaktext = sprintf(
            "%s, %s",
            date('Y-m-d', $now - 15 * DAYSECS),
            date('Y-m-d', $now - 2 * DAYSECS)
        );
        $breaks = \block_atrisk\local\breaks::parse($breaktext);

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($course->id, [
            'signals' => [
                'inactivity' => true, 'assessment_miss' => false,
                'grade_trend' => false, 'stalled_completion' => false,
                'forum_silence' => false,
            ],
            'thresholds' => ['inactivity' => ['days' => 7]],
            'breaks' => $breaks,
        ], $now);

        // Without breaks dampening, the student would be flagged. With it,
        // they shouldn't be: 16 raw days idle - 13 day break overlap = ~3
        // effective days, below the 7-day threshold.
        $this->assertCount(0, $flagged, 'Break should dampen inactivity below threshold.');
    }

    /**
     * For low-is-bad signals (forum_silence, stalled_completion) the
     * percentile is NOT inverted: a student with the lowest post count
     * is at the worst end and should report the lowest percentile.
     */
    public function test_worst_percentile_passes_through_low_is_bad_signal(): void {
        $engine = new flag_engine();

        // Cohort: five students, post-count spread.
        $cohortposts = [
            1 => new signal_result(true, '', 0),
            2 => new signal_result(true, '', 2),
            3 => new signal_result(true, '', 4),
            4 => new signal_result(true, '', 6),
            5 => new signal_result(true, '', 8),
        ];

        // Student 1 has the fewest posts → most at risk → lowest percentile.
        $worstuser = ['forum_silence' => $cohortposts[1]];
        $rank = $engine->worst_percentile($worstuser, ['forum_silence' => $cohortposts]);
        $this->assertNotNull($rank);
        $this->assertLessThan(50, $rank);
    }
}
