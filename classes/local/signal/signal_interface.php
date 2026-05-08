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

use block_atrisk\local\signal_result;

/**
 * Common contract for the five at-risk signal classifiers.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface signal_interface {
    /** Lower metric value indicates higher risk (e.g., grade, post count). */
    public const DIRECTION_ASC = 'asc';
    /** Higher metric value indicates higher risk (e.g., days inactive). */
    public const DIRECTION_DESC = 'desc';

    /**
     * Evaluate the signal across the given course cohort.
     *
     * @param int $courseid Course ID.
     * @param array $userids Active-cohort user IDs to evaluate.
     * @param array $config Signal-specific configuration — thresholds,
     *        lookback windows, etc. Keys are signal-specific.
     * @param int|null $now Reference timestamp for time-based comparisons,
     *        default {@see time()}. Passed in tests for determinism.
     * @return array Per-user signal_result, indexed by userid.
     */
    public function evaluate(int $courseid, array $userids, array $config, ?int $now = null): array;

    /**
     * Direction in which the underlying metric correlates with risk.
     *
     * Used by the engine to normalize peer-percentile output to a uniform
     * "lower percentile = more at risk" convention regardless of whether
     * the raw metric is high-is-bad (inactivity, miss count) or low-is-bad
     * (grade, completion count, post count).
     *
     * @return string One of {@see self::DIRECTION_ASC} or {@see self::DIRECTION_DESC}.
     */
    public static function metric_direction(): string;
}
