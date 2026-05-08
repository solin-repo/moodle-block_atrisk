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
 * Stalled-completion signal — peer-relative bottom-quartile of activity
 * completions in the last N days. FR-24 to FR-26.
 *
 * Engaged states are {@code 1, 2, 3, 4} (COMPLETE, COMPLETE_PASS,
 * COMPLETE_FAIL, COMPLETE_FAIL_HIDDEN) — all count as engagement because
 * the signal measures engagement volume, not success rate. State 0 and
 * "no row" do not count.
 *
 * Floor: peer median completed-activity count must be ≥ 1 to fire any
 * flags. If the whole class is quiet, the comparison is meaningless and
 * the signal auto-disables (FR-25, mirrors forum-silence).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stalled_completion implements signal_interface {
    /** Engaged completion states — FR-24. */
    private const ENGAGED_STATES = [
        \COMPLETION_COMPLETE,
        \COMPLETION_COMPLETE_PASS,
        \COMPLETION_COMPLETE_FAIL,
        \COMPLETION_COMPLETE_FAIL_HIDDEN,
    ];

    /**
     * Evaluate the stalled-completion signal across the cohort.
     *
     * @param int $courseid
     * @param array $userids Active-cohort user IDs.
     * @param array $config Threshold config (key 'days').
     * @param int|null $now Reference timestamp.
     * @return array<int,signal_result>
     */
    public function evaluate(int $courseid, array $userids, array $config, ?int $now = null): array {
        if (empty($userids)) {
            return [];
        }
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $now = $now ?? time();
        $lookbackdays = (int) ($config['days'] ?? 14);
        $breaks = $config['breaks'] ?? [];
        $windowstart = $now - ($lookbackdays * DAYSECS);
        if (!empty($breaks)) {
            $overlap = \block_atrisk\local\breaks::overlap_seconds($breaks, $windowstart, $now);
            $windowstart -= $overlap;
        }

        // Count completions per user in the lookback window. We only count
        // (cmid, userid) pairs from the course's modules.
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        [$ssql, $sparams] = $DB->get_in_or_equal(self::ENGAGED_STATES, SQL_PARAMS_NAMED, 's');

        $rows = $DB->get_records_sql(
            "SELECT cmc.userid, COUNT(*) AS n
             FROM {course_modules_completion} cmc
             JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
             WHERE cm.course = :courseid
               AND cmc.userid {$usql}
               AND cmc.completionstate {$ssql}
               AND cmc.timemodified >= :windowstart
             GROUP BY cmc.userid",
            array_merge(
                ['courseid' => $courseid, 'windowstart' => $windowstart],
                $uparams,
                $sparams
            )
        );

        // Build the per-user count, defaulting to 0 for users with no rows.
        $counts = [];
        foreach ($userids as $userid) {
            $counts[$userid] = isset($rows[$userid]) ? (int) $rows[$userid]->n : 0;
        }

        // FR-25 floor: peer median must be ≥ 1.
        $median = self::median(array_values($counts));
        if ($median < 1.0) {
            $results = [];
            foreach ($userids as $userid) {
                $results[$userid] = signal_result::not_triggered($counts[$userid]);
            }
            return $results;
        }

        // Bottom-quartile cutoff (Q1).
        $q1 = self::quartile1(array_values($counts));

        $results = [];
        foreach ($userids as $userid) {
            $count = $counts[$userid];
            // Strict < Q1 so a class with no spread (everyone equal) does
            // not flag everyone — the bottom-quartile concept needs an
            // actual lower tail.
            if ($count < $q1) {
                $results[$userid] = new signal_result(
                    triggered: true,
                    explanation: get_string('signal_stalled_completion_explanation', 'block_atrisk', (object) [
                        'count' => $count,
                        'days' => $lookbackdays,
                        'median' => (int) round($median),
                    ]),
                    metric: $count
                );
            } else {
                $results[$userid] = signal_result::not_triggered($count);
            }
        }
        return $results;
    }

    /**
     * Sample median of an array of numbers.
     *
     * @param array $values Numeric values.
     * @return float Median value (zero for an empty input).
     */
    public static function median(array $values): float {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = (int) floor($n / 2);
        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }
        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    /**
     * 25th-percentile (lower quartile) value. Uses linear interpolation.
     *
     * @param array $values Numeric values.
     * @return float Lower-quartile value (zero for an empty input).
     */
    public static function quartile1(array $values): float {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $pos = 0.25 * ($n - 1);
        $low = (int) floor($pos);
        $high = (int) ceil($pos);
        if ($low === $high) {
            return (float) $values[$low];
        }
        $frac = $pos - $low;
        return (float) $values[$low] + $frac * ((float) $values[$high] - (float) $values[$low]);
    }

    /**
     * Low completion count = more at risk.
     *
     * @return string
     */
    public static function metric_direction(): string {
        return self::DIRECTION_ASC;
    }
}
