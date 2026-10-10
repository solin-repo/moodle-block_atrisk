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
 * Edge cases shared by all signals: empty cohorts, metric direction, and
 * lookback windows that are extended past configured breaks (FR-105d).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(inactivity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(forum_silence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(assessment_miss::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(stalled_completion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(grade_trend::class)]
final class signal_edge_cases_test extends advanced_testcase {
    /** @var int Fixed reference time. */
    private const NOW = 1_800_000_000;

    /**
     * A break range covering the given whole days before NOW.
     *
     * @param int $fromdaysago Start, in days before NOW.
     * @param int $todaysago End, in days before NOW.
     * @return array Parsed-range list as produced by breaks::parse().
     */
    private function break_between(int $fromdaysago, int $todaysago): array {
        return [['start' => self::NOW - $fromdaysago * DAYSECS, 'end' => self::NOW - $todaysago * DAYSECS]];
    }

    /**
     * Every signal returns no results for an empty cohort and declares
     * which metric direction means "more at risk".
     */
    public function test_empty_cohort_and_metric_direction(): void {
        $signals = [
            [new inactivity(), signal_interface::DIRECTION_DESC],
            [new forum_silence(), signal_interface::DIRECTION_ASC],
            [new assessment_miss(), signal_interface::DIRECTION_DESC],
            [new stalled_completion(), signal_interface::DIRECTION_ASC],
            [new grade_trend(), signal_interface::DIRECTION_ASC],
        ];
        foreach ($signals as [$signal, $direction]) {
            $this->assertSame([], $signal->evaluate(1, [], [], self::NOW), get_class($signal));
            $this->assertSame($direction, $signal::metric_direction(), get_class($signal));
        }
    }

    /**
     * Break days after the last access do not count as inactivity.
     */
    public function test_inactivity_discounts_break_after_last_access(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id, 'courseid' => $course->id, 'timeaccess' => self::NOW - 10 * DAYSECS,
        ]);
        $signal = new inactivity();

        $plain = $signal->evaluate($course->id, [$user->id], ['days' => 7], self::NOW);
        $this->assertTrue($plain[$user->id]->triggered);

        $config = ['days' => 7, 'breaks' => $this->break_between(9, 5)];
        $withbreak = $signal->evaluate($course->id, [$user->id], $config, self::NOW);
        $this->assertFalse($withbreak[$user->id]->triggered);
        $this->assertSame(6, $withbreak[$user->id]->metric);
    }

    /**
     * A break inside the forum lookback window extends it, so a post from
     * before the window still counts.
     */
    public function test_forum_silence_window_extends_past_break(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forumgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[$i] = $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        // Two active posters inside the window; the subject posted 12 days ago.
        foreach ([0 => 1, 1 => 1, 2 => 12] as $index => $daysago) {
            $forumgen->create_discussion((object) [
                'course' => $course->id, 'forum' => $forum->id, 'userid' => $users[$index]->id,
                'timemodified' => self::NOW - $daysago * DAYSECS,
                'created' => self::NOW - $daysago * DAYSECS,
            ]);
        }
        $userids = array_map(fn($u) => (int) $u->id, $users);
        $signal = new forum_silence();

        $plain = $signal->evaluate($course->id, $userids, ['days' => 10], self::NOW);
        $this->assertTrue($plain[$users[2]->id]->triggered);

        $withbreak = $signal->evaluate(
            $course->id,
            $userids,
            ['days' => 10, 'breaks' => $this->break_between(8, 5)],
            self::NOW
        );
        $this->assertFalse($withbreak[$users[2]->id]->triggered);
    }

    /**
     * Activities due inside a break are not expected; a break inside the
     * window extends it to reach an activity due just before it.
     */
    public function test_assessment_miss_break_handling(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $signal = new assessment_miss();

        // Due 3 days ago, during a break: not counted.
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => self::NOW - 3 * DAYSECS,
        ]);
        $config = ['days' => 14, 'breaks' => $this->break_between(4, 2)];
        $result = $signal->evaluate($course->id, [$user->id], $config, self::NOW);
        $this->assertFalse($result[$user->id]->triggered);
        $this->assertSame(0, $result[$user->id]->metric);

        // Due 15 days ago: outside a 14-day window, inside it once the
        // 3-day break extends the window.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => self::NOW - 15 * DAYSECS,
        ]);
        $this->assertFalse($signal->evaluate($course->id, [$user->id], ['days' => 14], self::NOW)[$user->id]->triggered);
        $result = $signal->evaluate($course->id, [$user->id], $config, self::NOW);
        $this->assertTrue($result[$user->id]->triggered);
        $this->assertSame(1, $result[$user->id]->metric);
    }

    /**
     * An activity whose module type no longer exists is still counted as
     * missed, with a placeholder name.
     */
    public function test_assessment_miss_unknown_module_type(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => self::NOW - DAYSECS,
        ]);
        $DB->set_field('course_modules', 'module', 999999, ['id' => $assign->cmid]);

        $result = (new assessment_miss())->evaluate($course->id, [$user->id], ['days' => 14], self::NOW);

        $this->assertTrue($result[$user->id]->triggered);
        $this->assertStringContainsString('?', $result[$user->id]->explanation);
    }

    /**
     * A break inside the completion lookback window extends it so earlier
     * completions still count.
     */
    public function test_stalled_completion_window_extends_past_break(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cms = [];
        for ($i = 0; $i < 2; $i++) {
            $cms[] = $this->getDataGenerator()->create_module('page', [
                'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            ])->cmid;
        }
        $users = [];
        for ($i = 0; $i < 4; $i++) {
            $users[$i] = $this->getDataGenerator()->create_and_enrol($course, 'student');
            // Peers complete both pages yesterday; the subject did so 16 days ago.
            $daysago = $i === 3 ? 16 : 1;
            foreach ($cms as $cmid) {
                $DB->insert_record('course_modules_completion', (object) [
                    'coursemoduleid' => $cmid, 'userid' => $users[$i]->id,
                    'completionstate' => COMPLETION_COMPLETE,
                    'timemodified' => self::NOW - $daysago * DAYSECS,
                ]);
            }
        }
        $userids = array_map(fn($u) => (int) $u->id, $users);
        $signal = new stalled_completion();

        $plain = $signal->evaluate($course->id, $userids, ['days' => 14], self::NOW);
        $this->assertTrue($plain[$users[3]->id]->triggered);

        $withbreak = $signal->evaluate(
            $course->id,
            $userids,
            ['days' => 14, 'breaks' => $this->break_between(10, 7)],
            self::NOW
        );
        $this->assertFalse($withbreak[$users[3]->id]->triggered);
        $this->assertSame(2, $withbreak[$users[3]->id]->metric);
    }

    /**
     * Median and lower quartile handle empty, odd and exact-index inputs.
     */
    public function test_median_and_quartile_edges(): void {
        $this->assertSame(0.0, stalled_completion::median([]));
        $this->assertSame(3.0, stalled_completion::median([5, 1, 3]));
        $this->assertSame(2.5, stalled_completion::median([4, 1, 3, 2]));

        $this->assertSame(0.0, stalled_completion::quartile1([]));
        // Five values: position 0.25 * 4 = 1, an exact index.
        $this->assertSame(2.0, stalled_completion::quartile1([5, 4, 3, 2, 1]));
        // Four values: position 0.75, interpolated between 1 and 2.
        $this->assertSame(1.75, stalled_completion::quartile1([1, 2, 3, 4]));
    }
}
