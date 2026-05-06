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
 * Unit tests for severity classification.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\severity
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class severity_test extends advanced_testcase {
    public function test_zero_triggered_is_none(): void {
        $this->assertSame(severity::NONE, severity::classify(0));
        $this->assertSame(severity::NONE, severity::classify(-1));
    }

    public function test_one_triggered_is_yellow(): void {
        $this->assertSame(severity::YELLOW, severity::classify(1));
    }

    public function test_two_or_more_triggered_is_red(): void {
        $this->assertSame(severity::RED, severity::classify(2));
        $this->assertSame(severity::RED, severity::classify(3));
        $this->assertSame(severity::RED, severity::classify(5));
    }

    public function test_is_flagged(): void {
        $this->assertTrue(severity::is_flagged(severity::YELLOW));
        $this->assertTrue(severity::is_flagged(severity::RED));
        $this->assertFalse(severity::is_flagged(severity::NONE));
    }

    public function test_rank_orders_red_first(): void {
        $this->assertGreaterThan(
            severity::rank(severity::YELLOW),
            severity::rank(severity::RED)
        );
        $this->assertGreaterThan(
            severity::rank(severity::NONE),
            severity::rank(severity::YELLOW)
        );
    }
}
