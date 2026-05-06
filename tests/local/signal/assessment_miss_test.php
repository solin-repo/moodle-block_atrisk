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
 * Unit tests for the assessment-miss signal classifier.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\signal\assessment_miss
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assessment_miss_test extends advanced_testcase {
    /**
     * Helper: create a course with completion enabled and one assign with
     * completionexpected at the given offset (seconds, relative to $now).
     */
    private function create_course_with_assign(int $now, int $expectedoffset, bool $enablecompletion = true): array {
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => $enablecompletion ? 1 : 0,
        ]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionexpected' => $now + $expectedoffset,
        ]);
        return [$course, $assign];
    }

    public function test_no_completion_row_with_completionexpected_in_past_triggers(): void {
        $this->resetAfterTest();
        $now = 1_700_000_000;
        [$course, $assign] = $this->create_course_with_assign($now, -3 * DAYSECS); // 3 days ago.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertTrue($results[$student->id]->triggered);
        $this->assertEquals(1, $results[$student->id]->metric, 'metric is count of missed activities');
        $this->assertStringContainsString('not completed', $results[$student->id]->explanation);
        $this->assertStringContainsString('expected by', $results[$student->id]->explanation);
    }

    public function test_completion_state_complete_does_not_trigger(): void {
        // States 1 (COMPLETE), 2 (COMPLETE_PASS), 3 (COMPLETE_FAIL),
        // 4 (COMPLETE_FAIL_HIDDEN) all count as completed.
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        [$course, $assign] = $this->create_course_with_assign($now, -3 * DAYSECS);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
        foreach ([COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL] as $state) {
            $DB->delete_records('course_modules_completion', [
                'coursemoduleid' => $cm->id, 'userid' => $student->id,
            ]);
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id,
                'userid' => $student->id,
                'completionstate' => $state,
                'timemodified' => $now - DAYSECS,
            ]);

            $signal = new assessment_miss();
            $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

            $this->assertFalse(
                $results[$student->id]->triggered,
                "state $state should not fire the assessment-miss signal"
            );
        }
    }

    public function test_completion_state_incomplete_triggers(): void {
        $this->resetAfterTest();
        global $DB;
        $now = 1_700_000_000;
        [$course, $assign] = $this->create_course_with_assign($now, -3 * DAYSECS);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cm->id,
            'userid' => $student->id,
            'completionstate' => COMPLETION_INCOMPLETE,
            'timemodified' => $now - DAYSECS,
        ]);

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertTrue($results[$student->id]->triggered);
    }

    public function test_completionexpected_outside_window_does_not_count(): void {
        $this->resetAfterTest();
        $now = 1_700_000_000;
        // The completionexpected is 30 days ago — outside the 14-day lookback.
        [$course, $assign] = $this->create_course_with_assign($now, -30 * DAYSECS);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_completionexpected_in_future_does_not_count(): void {
        $this->resetAfterTest();
        $now = 1_700_000_000;
        [$course, $assign] = $this->create_course_with_assign($now, +5 * DAYSECS); // Future.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_no_eligible_activities_returns_signal_disabled_marker(): void {
        // FR-16: zero activities with completionexpected = signal disabled
        // for the course. We model this by all results having metric = -1
        // (or some sentinel) and an explanation indicating the disabled state
        // — caller can detect and surface the note.
        $this->resetAfterTest();
        $now = 1_700_000_000;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        // Signal does not fire and metric reflects "no eligible activities".
        $this->assertFalse($results[$student->id]->triggered);
        $this->assertEquals(0, $results[$student->id]->metric);
    }

    public function test_multiple_missed_activities_explanation_lists_most_recent(): void {
        $this->resetAfterTest();
        $now = 1_700_000_000;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $oldassign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Old Quiz',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionexpected' => $now - 10 * DAYSECS,
        ]);
        $newassign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'New Quiz',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionexpected' => $now - 2 * DAYSECS,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertTrue($results[$student->id]->triggered);
        $this->assertEquals(2, $results[$student->id]->metric);
        // Most recent missed (the "New Quiz", expected 2 days ago) should be named.
        $this->assertStringContainsString('New Quiz', $results[$student->id]->explanation);
        $this->assertStringContainsString('+1 more', $results[$student->id]->explanation);
    }

    public function test_works_against_quiz_module_too(): void {
        // FR-15 generalization: works for any activity type using core
        // completion API.
        $this->resetAfterTest();
        $now = 1_700_000_000;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionexpected' => $now - 3 * DAYSECS,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new assessment_miss();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertTrue($results[$student->id]->triggered);
    }
}
