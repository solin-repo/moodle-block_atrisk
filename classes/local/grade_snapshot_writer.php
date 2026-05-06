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
 * Weekly snapshot writer for the grade-trend signal (FR-117 to FR-119).
 *
 * For each course where block_atrisk is instantiated, writes one row per
 * actively-enrolled gradebook-role user with the current course-total
 * finalgrade. Upserts on (courseid, userid, snapshotweek). Prunes rows
 * older than 12 weeks on each run.
 *
 * Runs regardless of {@code course.enablecompletion} per FR-118: the
 * grade-trend signal runs independently of completion tracking.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_snapshot_writer {
    /** Number of weeks to retain snapshots. */
    public const RETENTION_WEEKS = 12;

    /**
     * Run a snapshot pass at the given reference time.
     *
     * @param int $now Reference timestamp.
     * @return int Number of snapshot rows written or updated.
     */
    public function run(int $now): int {
        $written = 0;
        foreach ($this->courses_with_block() as $courseid) {
            $written += $this->snapshot_course((int) $courseid, $now);
        }
        $this->prune($now);
        return $written;
    }

    /**
     * Snapshot one course's active cohort. Idempotent within a week —
     * existing rows for the same (courseid, userid, snapshotweek) are
     * UPDATEd rather than re-INSERTed.
     *
     * @param int $courseid
     * @param int $now Reference timestamp.
     * @return int Rows written or updated.
     */
    public function snapshot_course(int $courseid, int $now): int {
        global $DB;

        $week = self::iso_week($now);
        $courseitem = $this->course_total_item($courseid);
        if ($courseitem === null) {
            return 0; // Course has no total grade item — nothing to snapshot.
        }

        $userids = $this->active_cohort($courseid);
        if (empty($userids)) {
            return 0;
        }

        // Fetch finalgrade for each user — single batched query.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $grades = $DB->get_records_sql(
            "SELECT userid, finalgrade
             FROM {grade_grades}
             WHERE itemid = :itemid AND userid {$insql}",
            array_merge(['itemid' => $courseitem->id], $inparams)
        );

        // Existing rows for this (courseid, week).
        $existing = $DB->get_records('block_atrisk_grade_snapshots', [
            'courseid' => $courseid,
            'snapshotweek' => $week,
        ], '', 'userid, id');

        $count = 0;
        foreach ($userids as $userid) {
            $finalgrade = isset($grades[$userid]) && $grades[$userid]->finalgrade !== null
                ? (float) $grades[$userid]->finalgrade
                : null;

            if (isset($existing[$userid])) {
                $DB->update_record('block_atrisk_grade_snapshots', (object) [
                    'id' => $existing[$userid]->id,
                    'finalgrade' => $finalgrade,
                    'timecreated' => $now,
                ]);
            } else {
                $DB->insert_record('block_atrisk_grade_snapshots', (object) [
                    'courseid' => $courseid,
                    'userid' => $userid,
                    'snapshotweek' => $week,
                    'finalgrade' => $finalgrade,
                    'timecreated' => $now,
                ]);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Prune snapshot rows older than {@see self::RETENTION_WEEKS} weeks.
     */
    public function prune(int $now): int {
        global $DB;
        $cutoffweek = self::iso_week($now - self::RETENTION_WEEKS * WEEKSECS);
        return $DB->delete_records_select(
            'block_atrisk_grade_snapshots',
            'snapshotweek < :cutoff',
            ['cutoff' => $cutoffweek]
        ) ? 1 : 0;
    }

    /**
     * ISO-week stamp YYYYWW for a Unix timestamp.
     */
    public static function iso_week(int $timestamp): int {
        return (int) date('oW', $timestamp);
    }

    /**
     * Distinct courseids where any block_atrisk instance exists.
     *
     * @return array<int>
     */
    private function courses_with_block(): array {
        global $DB;
        $sql = "SELECT DISTINCT c.id
                FROM {course} c
                JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :clevel
                JOIN {block_instances} bi ON bi.parentcontextid = ctx.id
                WHERE bi.blockname = 'atrisk'";
        return $DB->get_fieldset_sql($sql, ['clevel' => CONTEXT_COURSE]);
    }

    /**
     * Look up the course-total grade_item for a course.
     *
     * @return \stdClass|null
     */
    private function course_total_item(int $courseid): ?\stdClass {
        global $DB;
        $rec = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'course',
        ], 'id', IGNORE_MISSING);
        return $rec === false ? null : $rec;
    }

    /**
     * Active gradebook-role enrollments in a course.
     *
     * @return array<int> userids.
     */
    private function active_cohort(int $courseid): array {
        global $CFG, $DB;

        $gradebookroles = trim($CFG->gradebookroles ?? '');
        if ($gradebookroles === '') {
            return [];
        }
        $roleids = array_map('intval', explode(',', $gradebookroles));

        $context = \context_course::instance($courseid);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');

        // Get users who have a gradebook role in this course context AND
        // have at least one non-suspended enrolment.
        $sql = "SELECT DISTINCT u.id
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :ctxid
                JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = 0
                WHERE u.deleted = 0 AND u.suspended = 0
                  AND ra.roleid {$rolesql}";
        $params = array_merge([
            'ctxid' => $context->id,
            'courseid' => $courseid,
        ], $roleparams);
        return $DB->get_fieldset_sql($sql, $params);
    }
}
