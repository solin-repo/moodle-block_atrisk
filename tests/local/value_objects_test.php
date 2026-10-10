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
 * Tests for the engine's value objects.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(flagged_student::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(signal_result::class)]
final class value_objects_test extends advanced_testcase {
    /**
     * signal_result keeps its fields; not_triggered() builds a silent result.
     */
    public function test_signal_result(): void {
        $hit = new signal_result(true, 'No login for 9 days', 9);
        $this->assertTrue($hit->triggered);
        $this->assertSame('No login for 9 days', $hit->explanation);
        $this->assertSame(9, $hit->metric);

        $miss = signal_result::not_triggered(0.5);
        $this->assertFalse($miss->triggered);
        $this->assertSame('', $miss->explanation);
        $this->assertSame(0.5, $miss->metric);
        $this->assertNull(signal_result::not_triggered()->metric);
    }

    /**
     * flagged_student counts its triggered signals and reports flagged
     * status from its severity.
     */
    public function test_flagged_student(): void {
        $triggered = [
            'inactivity' => new signal_result(true, 'a', 9),
            'assessment_miss' => new signal_result(true, 'b', 2),
        ];
        $red = new flagged_student(5, severity::RED, calibration_window::PHASE_CONFIDENT, $triggered, 10);
        $this->assertSame(2, $red->triggered_count());
        $this->assertTrue($red->is_flagged());
        $this->assertSame(10, $red->worstpercentile);

        $none = new flagged_student(6, severity::NONE, calibration_window::PHASE_CONFIDENT, [], null);
        $this->assertSame(0, $none->triggered_count());
        $this->assertFalse($none->is_flagged());
    }
}
