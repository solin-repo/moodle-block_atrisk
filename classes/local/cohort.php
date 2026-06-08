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
 * Active-enrolment student-set discovery for at-risk evaluation. FR-50 to FR-56.
 *
 * Terminology note: "cohort" in this class name is the statistical sense
 * (the active-enrolment student set in a single course), NOT the Moodle
 * cohort entity at *Site administration → Users → Cohorts* (`mdl_cohort` /
 * {@see \core_cohort}). User-facing surfaces (lang strings, README, spec)
 * use "active enrolments" or "peer group" instead, and the spec glossary
 * spells out the distinction explicitly. The class name predates the
 * disambiguation and is namespaced to avoid clashing with the platform
 * construct; renaming would touch ~130 references for no functional gain.
 *
 * "Active" = enrolled, not suspended, not marked as course-completed,
 * holding a role from {@code $CFG->gradebookroles}. Per FR-55, gradebook
 * roles are preferred over the (less reliable) student archetype.
 *
 * The class also implements peer-relative gating (FR-50, FR-51): hard
 * floor (default 10) and soft floor (default 20) are exposed via
 * {@see self::gating()}.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohort {
    /** Peer-relative signals are off entirely (cohort below hard floor). */
    public const GATING_DISABLED = 'disabled';
    /** Peer-relative signals run with a "small cohort" caveat. */
    public const GATING_SMALL = 'small_cohort';
    /** Peer-relative signals run normally. */
    public const GATING_OK = 'ok';

    /**
     * Find the active learner cohort for a course.
     *
     * @param int $courseid
     * @param int|int[]|null $groupid When non-null, restricts to members of
     *        the given group(s) (FR-56 group-scoped peer comparisons). An
     *        int restricts to one group; an int[] restricts to the union
     *        of the listed groups (used when the viewing teacher belongs
     *        to multiple groups under SEPARATEGROUPS).
     * @return array<int> userids.
     */
    public static function active(int $courseid, int|array|null $groupid = null): array {
        global $CFG, $DB;

        $gradebookroles = trim((string) ($CFG->gradebookroles ?? ''));
        if ($gradebookroles === '') {
            // Fallback per FR-55: roles with archetype = student.
            $studentroleids = $DB->get_fieldset_select('role', 'id', 'archetype = :a', ['a' => 'student']);
            if (empty($studentroleids)) {
                return [];
            }
            $roleids = $studentroleids;
        } else {
            $roleids = array_filter(array_map('intval', explode(',', $gradebookroles)));
            if (empty($roleids)) {
                return [];
            }
        }

        $context = \context_course::instance($courseid);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');

        // Enrolment must be active *now*: not suspended, and within its
        // time window. This mirrors Moodle's canonical active-enrolment
        // definition (see {@see get_enrolled_join()}). The status=0 filter
        // alone misses enrolments whose duration has elapsed but whose
        // expiry action was "keep user enrolled" (timeend in the past),
        // and scheduled enrolments that have not started yet (timestart in
        // the future) — both must be excluded from the at-risk surface.
        $now = time();
        $sqlbase = "SELECT DISTINCT u.id
                    FROM {user} u
                    JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :ctxid
                    JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                                              AND ue.timestart <= :now1
                                              AND (ue.timeend = 0 OR ue.timeend > :now2)
                    JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = 0
                    LEFT JOIN {course_completions} cc ON cc.userid = u.id
                                                       AND cc.course = :coursecompletecid
                                                       AND cc.timecompleted IS NOT NULL
                    WHERE u.deleted = 0
                      AND u.suspended = 0
                      AND ra.roleid {$rolesql}
                      AND cc.id IS NULL";
        $params = array_merge([
            'ctxid' => $context->id,
            'courseid' => $courseid,
            'coursecompletecid' => $courseid,
            'now1' => $now,
            'now2' => $now,
        ], $roleparams);

        $gids = [];
        if (is_array($groupid)) {
            $gids = array_values(array_filter(array_map('intval', $groupid), fn($g) => $g > 0));
        } else if ($groupid !== null && $groupid > 0) {
            $gids = [(int) $groupid];
        }
        if (!empty($gids)) {
            [$gsql, $gparams] = $DB->get_in_or_equal($gids, SQL_PARAMS_NAMED, 'gid');
            $sqlbase .= " AND EXISTS (SELECT 1 FROM {groups_members} gm
                                      WHERE gm.userid = u.id AND gm.groupid {$gsql})";
            $params = array_merge($params, $gparams);
        }
        return array_map('intval', $DB->get_fieldset_sql($sqlbase, $params));
    }

    /**
     * Bulk active-enrolment counts for many courses in a single query.
     *
     * Returns, for each requested course, exactly {@code count(self::active($courseid))}
     * — same definition of "active" (enrolled, not suspended, not
     * course-completed, holding a {@code $CFG->gradebookroles} role assigned
     * at the course context). Courses with no active learners are present in
     * the result with a count of 0. Used by the readiness export so it can
     * survey a large catalog without one query per course.
     *
     * @param int[] $courseids Course IDs to count.
     * @return array<int,int> courseid → active-enrolment count.
     */
    public static function active_counts(array $courseids): array {
        global $CFG, $DB;

        $courseids = array_values(array_filter(
            array_map('intval', $courseids),
            fn($id) => $id > 0
        ));
        if (empty($courseids)) {
            return [];
        }

        // Same role precedence as active(): gradebookroles, else student archetype.
        $gradebookroles = trim((string) ($CFG->gradebookroles ?? ''));
        if ($gradebookroles === '') {
            $roleids = $DB->get_fieldset_select('role', 'id', 'archetype = :a', ['a' => 'student']);
        } else {
            $roleids = array_filter(array_map('intval', explode(',', $gradebookroles)));
        }

        // Every requested course starts at 0 so callers get a total map.
        $counts = array_fill_keys($courseids, 0);
        if (empty($roleids)) {
            return $counts;
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        [$cidsql, $cidparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

        // Mirror of active()'s join graph, grouped by course. The role
        // assignment is scoped to each course's own context via the context
        // join (ctx.instanceid = course, contextlevel = COURSE), matching
        // active()'s ra.contextid = <course context> exactly.
        // Same active-enrolment time-window as active() (timestart/timeend).
        $now = time();
        $sql = "SELECT e.courseid, COUNT(DISTINCT u.id) AS activecount
                  FROM {enrol} e
                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :clevel
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                                            AND ue.timestart <= :now1
                                            AND (ue.timeend = 0 OR ue.timeend > :now2)
                  JOIN {user} u ON u.id = ue.userid
                  JOIN {role_assignments} ra ON ra.userid = u.id
                                            AND ra.contextid = ctx.id
                                            AND ra.roleid {$rolesql}
             LEFT JOIN {course_completions} cc ON cc.userid = u.id
                                              AND cc.course = e.courseid
                                              AND cc.timecompleted IS NOT NULL
                 WHERE e.courseid {$cidsql}
                   AND e.status = 0
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND cc.id IS NULL
              GROUP BY e.courseid";
        $params = array_merge(
            ['clevel' => CONTEXT_COURSE, 'now1' => $now, 'now2' => $now],
            $roleparams,
            $cidparams
        );

        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $counts[(int) $row->courseid] = (int) $row->activecount;
        }
        return $counts;
    }

    /**
     * Determine the peer-relative gating state for a cohort.
     *
     * @param int $cohortsize Number of active learners.
     * @param int $hardfloor Hard floor (default 10) — below this peer-
     *        relative signals auto-disable (FR-50).
     * @param int $softfloor Soft floor (default 20) — between hard and
     *        soft, peer-relative signals run with a small-cohort caveat
     *        (FR-51).
     * @return string GATING_DISABLED / GATING_SMALL / GATING_OK.
     */
    public static function gating(int $cohortsize, int $hardfloor = 10, int $softfloor = 20): string {
        if ($cohortsize < $hardfloor) {
            return self::GATING_DISABLED;
        }
        if ($cohortsize < $softfloor) {
            return self::GATING_SMALL;
        }
        return self::GATING_OK;
    }
}
