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
 * Inactivity signal — flags students who have not accessed the course for
 * at least N days. Maps to FR-10 to FR-13.
 *
 * Source data: {@code mdl_user_lastaccess.timeaccess} only. The plugin
 * deliberately does NOT fall back to {@code mdl_user.lastaccess} (site-level
 * access), per FR-11: a student logging into Moodle daily for a different
 * course would otherwise mask inactivity in this one.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class inactivity implements signal_interface {
    /**
     * Evaluate the inactivity signal across the cohort.
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
        $thresholddays = (int) ($config['days'] ?? 7);
        $thresholdseconds = $thresholddays * DAYSECS;
        $breaks = $config['breaks'] ?? [];

        // One indexed query for the whole cohort.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $rows = $DB->get_records_select(
            'user_lastaccess',
            "courseid = :courseid AND userid {$insql}",
            array_merge(['courseid' => $courseid], $inparams),
            '',
            'userid, timeaccess'
        );

        $results = [];
        foreach ($userids as $userid) {
            if (!isset($rows[$userid])) {
                // FR-11: no row = "no recorded course access". Fire.
                $results[$userid] = new signal_result(
                    triggered: true,
                    explanation: get_string('signal_inactivity_no_access', 'block_atrisk'),
                    metric: null
                );
                continue;
            }

            $timeaccess = (int) $rows[$userid]->timeaccess;
            $rawsecondsinactive = max(0, $now - $timeaccess);
            // Subtract any institutional break that fell after the user's
            // last access — a student idle straight across Christmas
            // shouldn't count those weeks as inactive.
            $breakoverlap = !empty($breaks)
                ? \block_atrisk\local\breaks::overlap_seconds($breaks, $timeaccess, $now)
                : 0;
            $secondsinactive = max(0, $rawsecondsinactive - $breakoverlap);
            $daysinactive = (int) floor($secondsinactive / DAYSECS);

            if ($secondsinactive >= $thresholdseconds) {
                $results[$userid] = new signal_result(
                    triggered: true,
                    explanation: get_string('signal_inactivity_explanation', 'block_atrisk', (object) [
                        'date' => userdate($timeaccess, get_string('strftimedate', 'core_langconfig')),
                        'days' => $daysinactive,
                    ]),
                    metric: $daysinactive
                );
            } else {
                $results[$userid] = signal_result::not_triggered($daysinactive);
            }
        }
        return $results;
    }

    /**
     * High days-inactive = more at risk.
     *
     * @return string
     */
    public static function metric_direction(): string {
        return self::DIRECTION_DESC;
    }
}
