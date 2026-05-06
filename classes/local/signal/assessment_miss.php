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
 * Assessment-miss signal — flags students who have at least one
 * completion-tracked activity whose {@code completionexpected} fell within
 * the last N days and whose completion state is INCOMPLETE (or no row).
 *
 * Implements FR-14 to FR-18. Generalises across all activity types that
 * support the core completion API by reading {@code course_modules}
 * (filtered on completion+completionexpected) joined to
 * {@code course_modules_completion}.
 *
 * Per FR-15 implementation note: Moodle does not pre-populate completion
 * rows with state-0; rows exist only after interaction. We therefore
 * (a) fetch eligible activities, (b) fetch existing completion rows for
 * the cohort, (c) flag any (user, activity) combination that is missing
 * from the result OR has state=0. The mapping happens in PHP at the upper
 * cache layer — never via a CROSS JOIN.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assessment_miss implements signal_interface {
    /**
     * Evaluate the assessment-miss signal across the cohort.
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

        // Step a: Eligible activities — completion-tracked AND
        // completionexpected within the last N days (and not in the future).
        $activities = $DB->get_records_select(
            'course_modules',
            'course = :courseid AND completion > 0 AND completionexpected > 0
             AND completionexpected >= :windowstart AND completionexpected <= :now',
            ['courseid' => $courseid, 'windowstart' => $windowstart, 'now' => $now],
            'completionexpected ASC',
            'id, completionexpected, instance, module'
        );

        // Filter out activities whose completionexpected falls within a
        // configured break — students can't reasonably be expected to
        // complete an activity over Christmas.
        if (!empty($activities) && !empty($breaks)) {
            foreach ($activities as $cmid => $cm) {
                if (\block_atrisk\local\breaks::active_at($breaks, (int) $cm->completionexpected) !== null) {
                    unset($activities[$cmid]);
                }
            }
        }

        // FR-16: no eligible activities ⇒ signal disabled for this render.
        if (empty($activities)) {
            $results = [];
            foreach ($userids as $userid) {
                $results[$userid] = signal_result::not_triggered(0);
            }
            return $results;
        }

        // Pre-compute activity names (keyed by cmid) — one batched DB read
        // per module type. We only need it for the explanation string of
        // the most recent missed activity.
        $activitynames = $this->load_activity_names($activities);

        // Step b: Existing completion rows for our cohort × activity set.
        $cmids = array_keys($activities);
        [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $existing = $DB->get_records_sql(
            "SELECT id, coursemoduleid, userid, completionstate
             FROM {course_modules_completion}
             WHERE coursemoduleid {$cmsql} AND userid {$usql}",
            array_merge($cmparams, $uparams)
        );

        // Index existing rows: $byuser[userid][cmid] = state.
        $byuser = [];
        foreach ($existing as $row) {
            $byuser[(int) $row->userid][(int) $row->coursemoduleid] = (int) $row->completionstate;
        }

        // Step c: PHP-side mapping — each (userid, cmid) is "missed" if
        // no row OR state=0.
        $results = [];
        foreach ($userids as $userid) {
            $missed = []; // Map cmid → completionexpected.
            foreach ($activities as $cmid => $activity) {
                $state = $byuser[$userid][$cmid] ?? null;
                if ($state === null || $state === \COMPLETION_INCOMPLETE) {
                    $missed[$cmid] = (int) $activity->completionexpected;
                }
            }
            if (empty($missed)) {
                $results[$userid] = signal_result::not_triggered(0);
                continue;
            }

            // Order missed by completionexpected DESC (most recent first).
            arsort($missed);
            $cmids = array_keys($missed);
            $mostrecent = $cmids[0];
            $expected = $missed[$mostrecent];
            $count = count($missed);

            $a = (object) [
                'name' => $activitynames[$mostrecent] ?? '?',
                'date' => userdate($expected, get_string('strftimedate', 'core_langconfig')),
            ];
            if ($count === 1) {
                $explanation = get_string('signal_assessment_miss_explanation', 'block_atrisk', $a);
            } else {
                $a->more = $count - 1;
                $explanation = get_string('signal_assessment_miss_explanation_plural', 'block_atrisk', $a);
            }
            $results[$userid] = new signal_result(
                triggered: true,
                explanation: $explanation,
                metric: $count
            );
        }
        return $results;
    }

    /**
     * Loads activity names for the given course-module records.
     * One DB query per distinct module type, batched by cmid.
     *
     * @param array $activities Records from course_modules with `instance`
     *              and `module` columns.
     * @return array<int,string> Map cmid → activity name.
     */
    private function load_activity_names(array $activities): array {
        global $DB;

        // Group by module type → list of (cmid, instance).
        $bymodule = [];
        foreach ($activities as $cm) {
            $bymodule[(int) $cm->module][(int) $cm->id] = (int) $cm->instance;
        }

        // Map module id → table name.
        $moduleids = array_keys($bymodule);
        if (empty($moduleids)) {
            return [];
        }
        [$msql, $mparams] = $DB->get_in_or_equal($moduleids, SQL_PARAMS_NAMED, 'm');
        $modulenames = $DB->get_records_select_menu(
            'modules',
            "id {$msql}",
            $mparams,
            '',
            'id, name'
        );

        $names = [];
        foreach ($bymodule as $moduleid => $cminstance) {
            $tablename = $modulenames[$moduleid] ?? null;
            if ($tablename === null) {
                continue;
            }
            $instances = array_values($cminstance);
            [$isql, $iparams] = $DB->get_in_or_equal($instances, SQL_PARAMS_NAMED, 'i');
            $rows = $DB->get_records_select(
                $tablename,
                "id {$isql}",
                $iparams,
                '',
                'id, name'
            );
            foreach ($cminstance as $cmid => $instance) {
                if (isset($rows[$instance])) {
                    $names[$cmid] = $rows[$instance]->name;
                }
            }
        }
        return $names;
    }

    /**
     * High miss count = more at risk.
     *
     * @return string
     */
    public static function metric_direction(): string {
        return self::DIRECTION_DESC;
    }
}
