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
 * Unit tests for {@see breaks} — parsing, merging, overlap math, and
 * active-range detection for the breaks-calendar feature.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\breaks
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class breaks_test extends advanced_testcase {
    public function test_empty_input_returns_empty_list(): void {
        $this->assertSame([], breaks::parse(''));
        $this->assertSame([], breaks::parse("\n\n   \n"));
    }

    public function test_comments_and_blank_lines_ignored(): void {
        $text = "# Dutch academic breaks 2026/2027\n\n2026-10-13, 2026-10-19\n# Christmas\n2026-12-22, 2027-01-04\n";
        $ranges = breaks::parse($text);
        $this->assertCount(2, $ranges);
    }

    public function test_single_range_endpoints_inclusive(): void {
        $ranges = breaks::parse('2026-10-13, 2026-10-19');
        $this->assertCount(1, $ranges);
        $this->assertSame(strtotime('2026-10-13 00:00:00'), $ranges[0]['start']);
        // End is the last second of 2026-10-19.
        $this->assertSame(strtotime('2026-10-19 23:59:59'), $ranges[0]['end']);
    }

    public function test_invalid_format_throws(): void {
        // Each input below is malformed in one specific way: not a date,
        // missing comma, wrong separator, empty second part, invalid month.
        $cases = [
            'not-a-date',
            '2026-10-13',
            '2026/10/13, 2026/10/19',
            '2026-10-13, ',
            '2026-13-13, 2026-10-19',
        ];
        foreach ($cases as $bad) {
            try {
                breaks::parse($bad);
                $this->fail("Expected exception for: {$bad}");
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_start_after_end_throws(): void {
        $this->expectException(\invalid_parameter_exception::class);
        breaks::parse('2026-12-31, 2026-12-01');
    }

    public function test_overlapping_ranges_merged(): void {
        $text = "2026-12-22, 2026-12-28\n2026-12-26, 2027-01-04";
        $ranges = breaks::parse($text);
        $this->assertCount(1, $ranges);
        $this->assertSame(strtotime('2026-12-22 00:00:00'), $ranges[0]['start']);
        $this->assertSame(strtotime('2027-01-04 23:59:59'), $ranges[0]['end']);
    }

    public function test_adjacent_ranges_merged(): void {
        // Touching ranges (next-day boundary) should merge into one.
        $text = "2026-12-22, 2026-12-28\n2026-12-29, 2027-01-04";
        $ranges = breaks::parse($text);
        $this->assertCount(1, $ranges);
    }

    public function test_disjoint_ranges_kept_sorted(): void {
        $text = "2027-04-27, 2027-05-08\n2026-10-13, 2026-10-19\n2026-12-22, 2027-01-04";
        $ranges = breaks::parse($text);
        $this->assertCount(3, $ranges);
        $this->assertLessThan($ranges[1]['start'], $ranges[0]['start']);
        $this->assertLessThan($ranges[2]['start'], $ranges[1]['start']);
    }

    public function test_validate_returns_null_on_success(): void {
        $this->assertNull(breaks::validate("2026-12-22, 2027-01-04"));
        $this->assertNull(breaks::validate(""));
    }

    public function test_validate_returns_message_on_failure(): void {
        $msg = breaks::validate("not-a-date, 2026-10-19");
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Line 1', $msg);
    }

    public function test_overlap_seconds_returns_zero_for_disjoint(): void {
        $ranges = breaks::parse('2026-12-22, 2027-01-04');
        $start = strtotime('2027-02-01 00:00:00');
        $end = strtotime('2027-02-15 00:00:00');
        $this->assertSame(0, breaks::overlap_seconds($ranges, $start, $end));
    }

    public function test_overlap_seconds_partial(): void {
        // The break runs from December 22 through January 4 (14 days).
        $ranges = breaks::parse('2026-12-22, 2027-01-04');
        // The window runs from December 29 to January 10 — overlap should
        // be from window-start through break-end, roughly seven days.
        $windowstart = strtotime('2026-12-29 00:00:00');
        $windowend = strtotime('2027-01-10 00:00:00');
        // Overlap is from windowstart (Dec 29 00:00) to break end (Jan 4 23:59:59) = 6d23h59m59s.
        $expected = strtotime('2027-01-04 23:59:59') - $windowstart;
        $this->assertSame($expected, breaks::overlap_seconds($ranges, $windowstart, $windowend));
    }

    public function test_overlap_days_rounds_down(): void {
        // Single 14-day break, window covering it entirely.
        $ranges = breaks::parse('2026-12-22, 2027-01-04');
        $windowstart = strtotime('2026-12-01 00:00:00');
        $windowend = strtotime('2027-02-01 00:00:00');
        $this->assertSame(13, breaks::overlap_days($ranges, $windowstart, $windowend));
    }

    public function test_active_at_finds_current_break(): void {
        $ranges = breaks::parse("2026-10-13, 2026-10-19\n2026-12-22, 2027-01-04");
        $now = strtotime('2026-12-25 12:00:00');
        $active = breaks::active_at($ranges, $now);
        $this->assertNotNull($active);
        $this->assertSame(strtotime('2026-12-22 00:00:00'), $active['start']);
    }

    public function test_active_at_returns_null_outside_breaks(): void {
        $ranges = breaks::parse('2026-12-22, 2027-01-04');
        $now = strtotime('2027-01-15 12:00:00');
        $this->assertNull(breaks::active_at($ranges, $now));
    }
}
