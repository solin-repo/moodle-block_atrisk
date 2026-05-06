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

        $sqlbase = "SELECT DISTINCT u.id
                    FROM {user} u
                    JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :ctxid
                    JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
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
