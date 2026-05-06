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
 * Unit tests for the peer-percentile helper.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\peer_percentile
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class peer_percentile_test extends advanced_testcase {
    public function test_lowest_value_in_cohort(): void {
        // Subject is the unique lowest in [0, 1, 2, 3, 4].
        // strictlyless=0, equal=1 (subject), n=5 → rank = 0.5/5 = 10%.
        $this->assertEquals(10, peer_percentile::rank(0, [0, 1, 2, 3, 4]));
    }

    public function test_highest_value_in_cohort(): void {
        // Subject is the unique highest in [0, 1, 2, 3, 4].
        // strictlyless=4, equal=1, n=5 → 4.5/5 = 90%.
        $this->assertEquals(90, peer_percentile::rank(4, [0, 1, 2, 3, 4]));
    }

    public function test_middle_value(): void {
        // Subject = 2 in [0, 1, 2, 3, 4]. strictlyless=2, equal=1.
        // (2 + 0.5)/5 = 50%.
        $this->assertEquals(50, peer_percentile::rank(2, [0, 1, 2, 3, 4]));
    }

    public function test_all_equal_returns_50(): void {
        // Everyone equal: subject midrank in {5,5,5,5,5}.
        // strictlyless=0, equal=5, n=5 → 2.5/5 = 50%.
        $this->assertEquals(50, peer_percentile::rank(5, [5, 5, 5, 5, 5]));
    }

    public function test_below_minimum_returns_zero(): void {
        // Subject not in cohort, below minimum.
        // strictlyless=0, equal=0, n=5 → 0/5 = 0%.
        $this->assertEquals(0, peer_percentile::rank(-1, [0, 1, 2, 3, 4]));
    }

    public function test_above_maximum_returns_hundred(): void {
        // Subject not in cohort, above maximum.
        // strictlyless=5, equal=0, n=5 → 5/5 = 100%.
        $this->assertEquals(100, peer_percentile::rank(99, [0, 1, 2, 3, 4]));
    }

    public function test_nulls_excluded_from_cohort(): void {
        // Same shape as middle-value test but with nulls in the cohort.
        $this->assertEquals(50, peer_percentile::rank(2, [0, null, 1, 2, null, 3, 4]));
    }

    public function test_empty_cohort_returns_null(): void {
        $this->assertNull(peer_percentile::rank(5, []));
        $this->assertNull(peer_percentile::rank(5, [null, null, null]));
    }

    public function test_single_value_cohort(): void {
        // Cohort has only the subject. rank = 0.5/1 = 50%.
        $this->assertEquals(50, peer_percentile::rank(7, [7]));
    }
}
