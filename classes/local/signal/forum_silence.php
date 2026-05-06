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
 * Forum-silence signal — flags students with zero posts in eligible
 * (non-news) forums during the lookback window, when peers are posting.
 *
 * Implements FR-27 to FR-31. Opt-in: ships disabled by default.
 *
 * Eligibility rules (FR-28):
 * - Forum type must NOT be 'news' (announcements are teacher-only).
 * - The course must have at least one eligible forum.
 *
 * Floor (FR-27): peer median post count ≥ 1; otherwise the signal
 * auto-disables (matches the stalled-completion floor pattern).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class forum_silence implements signal_interface {
    /**
     * Evaluate the forum-silence signal across the cohort.
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
        global $DB;

        $now = $now ?? time();
        $lookbackdays = (int) ($config['days'] ?? 14);
        $breaks = $config['breaks'] ?? [];
        $windowstart = $now - ($lookbackdays * DAYSECS);
        // Extend the lookback past any break that overlaps the window so
        // it reaches genuine pre-break activity.
        if (!empty($breaks)) {
            $overlap = \block_atrisk\local\breaks::overlap_seconds($breaks, $windowstart, $now);
            $windowstart -= $overlap;
        }

        // Step a: Eligible forum IDs.
        $eligibleforums = $DB->get_fieldset_select(
            'forum',
            'id',
            "course = :courseid AND type <> :news",
            ['courseid' => $courseid, 'news' => 'news']
        );
        if (empty($eligibleforums)) {
            // FR-30: signal disabled.
            $results = [];
            foreach ($userids as $userid) {
                $results[$userid] = signal_result::not_triggered(0);
            }
            return $results;
        }

        // Step b: Post counts per user in the window for those forums.
        [$fsql, $fparams] = $DB->get_in_or_equal($eligibleforums, SQL_PARAMS_NAMED, 'f');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        $rows = $DB->get_records_sql(
            "SELECT fp.userid, COUNT(*) AS n
             FROM {forum_posts} fp
             JOIN {forum_discussions} fd ON fd.id = fp.discussion
             WHERE fd.forum {$fsql}
               AND fp.userid {$usql}
               AND fp.created >= :windowstart
               AND fp.deleted = 0
             GROUP BY fp.userid",
            array_merge(
                ['windowstart' => $windowstart],
                $fparams,
                $uparams
            )
        );

        $counts = [];
        foreach ($userids as $userid) {
            $counts[$userid] = isset($rows[$userid]) ? (int) $rows[$userid]->n : 0;
        }

        // FR-27 floor.
        $median = stalled_completion::median(array_values($counts));
        if ($median < 1.0) {
            $results = [];
            foreach ($userids as $userid) {
                $results[$userid] = signal_result::not_triggered($counts[$userid]);
            }
            return $results;
        }

        $results = [];
        foreach ($userids as $userid) {
            $count = $counts[$userid];
            if ($count === 0) {
                $results[$userid] = new signal_result(
                    triggered: true,
                    explanation: get_string('signal_forum_silence_explanation', 'block_atrisk', (object) [
                        'days' => $lookbackdays,
                        'median' => (int) round($median),
                    ]),
                    metric: 0
                );
            } else {
                $results[$userid] = signal_result::not_triggered($count);
            }
        }
        return $results;
    }

    /**
     * Low post count = more at risk.
     *
     * @return string
     */
    public static function metric_direction(): string {
        return self::DIRECTION_ASC;
    }
}
