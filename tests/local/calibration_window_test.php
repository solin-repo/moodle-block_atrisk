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
 * Unit tests for calibration window logic.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\calibration_window
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calibration_window_test extends advanced_testcase {
    public function test_within_gated_weeks_returns_gated(): void {
        $cw = new calibration_window();
        $anchor = 1_700_000_000;
        $this->assertSame(
            calibration_window::PHASE_GATED,
            $cw->phase($anchor, $anchor + 0 * WEEKSECS) // Day 0.
        );
        $this->assertSame(
            calibration_window::PHASE_GATED,
            $cw->phase($anchor, $anchor + 1 * WEEKSECS + 6 * DAYSECS) // About 13 days.
        );
    }

    public function test_within_tentative_weeks_returns_tentative(): void {
        $cw = new calibration_window();
        $anchor = 1_700_000_000;
        $this->assertSame(
            calibration_window::PHASE_TENTATIVE,
            $cw->phase($anchor, $anchor + 2 * WEEKSECS) // Exactly week 3.
        );
        $this->assertSame(
            calibration_window::PHASE_TENTATIVE,
            $cw->phase($anchor, $anchor + 3 * WEEKSECS + 6 * DAYSECS) // About 27 days.
        );
    }

    public function test_after_calibration_returns_confident(): void {
        $cw = new calibration_window();
        $anchor = 1_700_000_000;
        $this->assertSame(
            calibration_window::PHASE_CONFIDENT,
            $cw->phase($anchor, $anchor + 4 * WEEKSECS) // Start of week 5.
        );
        $this->assertSame(
            calibration_window::PHASE_CONFIDENT,
            $cw->phase($anchor, $anchor + 100 * WEEKSECS)
        );
    }

    public function test_before_anchor_returns_gated(): void {
        $cw = new calibration_window();
        $anchor = 1_700_000_000;
        $this->assertSame(
            calibration_window::PHASE_GATED,
            $cw->phase($anchor, $anchor - 100) // 100 sec before.
        );
    }

    public function test_unset_anchor_returns_confident(): void {
        // FR-46 fallback: no anchor at all → confident (don't permanently gate).
        $cw = new calibration_window();
        $this->assertSame(
            calibration_window::PHASE_CONFIDENT,
            $cw->phase(0, 1_700_000_000)
        );
    }

    public function test_custom_phase_lengths(): void {
        $cw = new calibration_window(gatedweeks: 1, tentativeweeks: 1);
        $anchor = 1_700_000_000;

        $this->assertSame(
            calibration_window::PHASE_GATED,
            $cw->phase($anchor, $anchor)
        );
        $this->assertSame(
            calibration_window::PHASE_TENTATIVE,
            $cw->phase($anchor, $anchor + 1 * WEEKSECS)
        );
        $this->assertSame(
            calibration_window::PHASE_CONFIDENT,
            $cw->phase($anchor, $anchor + 2 * WEEKSECS)
        );
    }

    public function test_anchor_for_prefers_course_startdate(): void {
        $course = (object) ['startdate' => 1_700_000_000];
        $enrolment = (object) ['timestart' => 1_650_000_000, 'timecreated' => 1_640_000_000];

        $this->assertEquals(
            1_700_000_000,
            calibration_window::anchor_for($course, $enrolment)
        );
    }

    public function test_anchor_for_falls_back_to_timestart(): void {
        // FR-46: timestart preferred over timecreated for rolling enrolment.
        $course = (object) ['startdate' => 0];
        $enrolment = (object) ['timestart' => 1_650_000_000, 'timecreated' => 1_640_000_000];

        $this->assertEquals(
            1_650_000_000,
            calibration_window::anchor_for($course, $enrolment)
        );
    }

    public function test_anchor_for_falls_back_to_timecreated(): void {
        // FR-46: when timestart is 0, fall back to timecreated.
        $course = (object) ['startdate' => 0];
        $enrolment = (object) ['timestart' => 0, 'timecreated' => 1_640_000_000];

        $this->assertEquals(
            1_640_000_000,
            calibration_window::anchor_for($course, $enrolment)
        );
    }
}
