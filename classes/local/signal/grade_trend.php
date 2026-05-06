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
 * Negative grade-trend signal — flags students whose course-total grade
 * declined across two consecutive weekly intervals (FR-19 to FR-23).
 *
 * Reads the plugin-owned {@code block_atrisk_grade_snapshots} table.
 * Never queries {@code grade_grades_history} at render time (FR-21).
 *
 * Firing rule: given the three most recent snapshots S1, S2, S3 (oldest
 * to newest), fire iff S1 > S2 AND S2 > S3 — strict decline both intervals.
 * Any null finalgrade in S1/S2/S3 disables the signal for that student
 * (FR-19): null means "no information", not a comparison value.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_trend implements signal_interface {
    /**
     * Evaluate the grade-trend signal across the cohort.
     *
     * @param int $courseid
     * @param array $userids Active-cohort user IDs.
     * @param array $config Reserved for future use; currently unused.
     * @param int|null $now Reference timestamp.
     * @return array<int,signal_result>
     */
    public function evaluate(int $courseid, array $userids, array $config, ?int $now = null): array {
        if (empty($userids)) {
            return [];
        }
        global $DB;

        // Fetch up to the three most recent snapshots per user. We over-
        // fetch using a single query then trim per-user in PHP.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $rows = $DB->get_records_sql(
            "SELECT id, userid, snapshotweek, finalgrade
             FROM {block_atrisk_grade_snapshots}
             WHERE courseid = :courseid AND userid {$insql}
             ORDER BY userid ASC, snapshotweek DESC",
            array_merge(['courseid' => $courseid], $inparams)
        );

        // Group by userid; keep only the 3 newest (we already ordered DESC).
        $byuser = [];
        foreach ($rows as $row) {
            $uid = (int) $row->userid;
            if (!isset($byuser[$uid])) {
                $byuser[$uid] = [];
            }
            if (count($byuser[$uid]) < 3) {
                $byuser[$uid][] = $row;
            }
        }

        $results = [];
        foreach ($userids as $userid) {
            $snapshots = $byuser[$userid] ?? [];
            if (count($snapshots) < 3) {
                $results[$userid] = signal_result::not_triggered(
                    !empty($snapshots) ? (float) ($snapshots[0]->finalgrade ?? 0) : null
                );
                continue;
            }

            // The $snapshots array is ordered newest-first. Map to
            // S1 (oldest-of-3), S2 (middle), S3 (newest).
            $s3 = $snapshots[0];
            $s2 = $snapshots[1];
            $s1 = $snapshots[2];

            // FR-19 null guard: any null breaks the comparison.
            if ($s1->finalgrade === null || $s2->finalgrade === null || $s3->finalgrade === null) {
                $results[$userid] = signal_result::not_triggered(
                    $s3->finalgrade !== null ? (float) $s3->finalgrade : null
                );
                continue;
            }

            $g1 = (float) $s1->finalgrade;
            $g2 = (float) $s2->finalgrade;
            $g3 = (float) $s3->finalgrade;

            if ($g1 > $g2 && $g2 > $g3) {
                $results[$userid] = new signal_result(
                    triggered: true,
                    explanation: get_string('signal_grade_trend_explanation', 'block_atrisk', (object) [
                        'old' => format_float($g1, 1),
                        'mid' => format_float($g2, 1),
                        'new' => format_float($g3, 1),
                    ]),
                    metric: $g3
                );
            } else {
                $results[$userid] = signal_result::not_triggered($g3);
            }
        }
        return $results;
    }

    /**
     * Low latest grade = more at risk.
     *
     * @return string
     */
    public static function metric_direction(): string {
        return self::DIRECTION_ASC;
    }
}
