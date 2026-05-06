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
 * Group-access classifier for the viewing user.
 *
 * Determines whether the viewer is restricted to specific groups when
 * looking at the at-risk block. Used by both the in-block render
 * ({@see \block_atrisk::get_content()}) and the paginated view-all page
 * ({@see view.php}) so they apply the same group-aware filter.
 *
 * The course's group mode and the viewer's `moodle/site:accessallgroups`
 * capability determine the outcome:
 * - SEPARATEGROUPS + viewer lacks accessallgroups → restricted to
 *   their own groups.
 * - Anything else (NOGROUPS, VISIBLEGROUPS, or accessallgroups present) →
 *   unrestricted.
 * - SEPARATEGROUPS + restricted + viewer is in no groups → "no groups"
 *   state with a distinct empty message rather than silent filtering.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class group_access {
    /** Viewer sees the whole course (no group filter applies). */
    public const MODE_UNRESTRICTED = 'unrestricted';
    /** Viewer sees only their accessible groups. */
    public const MODE_RESTRICTED = 'restricted';
    /** SEPARATEGROUPS + viewer in zero groups → empty list. */
    public const MODE_NO_GROUPS = 'no_groups';

    /**
     * Compute the viewing user's group access to a course.
     *
     * @param int $courseid
     * @param int $userid Viewing user's id.
     * @return array{mode: string, groupids: int[]} Tuple of mode constant
     *         and group ids the viewer can access. groupids is empty for
     *         MODE_UNRESTRICTED and MODE_NO_GROUPS.
     */
    public static function for_user(int $courseid, int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/lib/grouplib.php');

        $course = get_course($courseid);
        $groupmode = (int) groups_get_course_groupmode($course);

        // NOGROUPS or VISIBLEGROUPS → no restriction at the listing level.
        if ($groupmode !== SEPARATEGROUPS) {
            return ['mode' => self::MODE_UNRESTRICTED, 'groupids' => []];
        }

        $context = \context_course::instance($courseid);
        if (has_capability('moodle/site:accessallgroups', $context, $userid)) {
            return ['mode' => self::MODE_UNRESTRICTED, 'groupids' => []];
        }

        // The groups_get_user_groups() API returns [groupingid => [groupid, ...]]; flatten.
        $usergroups = groups_get_user_groups($courseid, $userid);
        $flat = [];
        foreach ($usergroups as $pergrouping) {
            foreach ($pergrouping as $gid) {
                $flat[(int) $gid] = true;
            }
        }
        $groupids = array_keys($flat);

        if (empty($groupids)) {
            return ['mode' => self::MODE_NO_GROUPS, 'groupids' => []];
        }
        return ['mode' => self::MODE_RESTRICTED, 'groupids' => $groupids];
    }

    /**
     * Resolve the set of userids visible to the viewer in this course.
     *
     * @param int[] $groupids Output of {@see self::for_user()}'s groupids.
     * @return int[] Userids in the union of those groups.
     */
    public static function userids_in_groups(array $groupids): array {
        if (empty($groupids)) {
            return [];
        }
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        $members = groups_get_groups_members($groupids);
        return $members === false ? [] : array_map('intval', array_keys($members));
    }
}
