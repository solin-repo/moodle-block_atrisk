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

/**
 * Peer-percentile rank computation. Cross-cutting helper for FR-32 to
 * FR-34: alongside each signal explanation, surface the student's
 * percentile rank within the comparison cohort on the underlying metric.
 *
 * Implementation note: this is the standard "percentile rank" definition
 * (proportion of values ≤ subject), expressed as 0..100. Group-scope
 * filtering (FR-34) is applied UPSTREAM by the caller — pass in only the
 * cohort that should be the comparison set.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class peer_percentile {
    /**
     * Compute percentile rank for $subject within $cohort.
     *
     * Percentile rank = (count of values strictly less than $subject + 0.5
     * × count of values equal to $subject) / total × 100. This is the
     * "midrank" definition, which avoids the asymmetry of strict and
     * inclusive comparisons.
     *
     * Returns null when the cohort is empty or contains only nulls.
     *
     * @param int|float $subject The subject's metric value.
     * @param array<int|float|null> $cohort Metric values for the cohort
     *        (may include nulls; nulls are excluded from the comparison
     *        set since they represent "no information").
     * @return int|null Integer percentile 0..100, or null if undefined.
     */
    public static function rank(int|float $subject, array $cohort): ?int {
        $values = array_values(array_filter(
            $cohort,
            static fn($v) => $v !== null
        ));
        if (empty($values)) {
            return null;
        }
        $n = count($values);
        $strictlyless = 0;
        $equal = 0;
        foreach ($values as $v) {
            if ((float) $v < (float) $subject) {
                $strictlyless++;
            } else if ((float) $v === (float) $subject) {
                $equal++;
            }
        }
        $rank = ($strictlyless + 0.5 * $equal) / $n;
        return (int) round($rank * 100);
    }
}
