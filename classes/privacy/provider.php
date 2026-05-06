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

namespace block_atrisk\privacy;

use context;
use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API provider for block_atrisk.
 *
 * Owns four tables (FR-131): block_atrisk_dismissals (dismiss flag for
 * one week — both userid and dismissed_by are user references, plus an
 * optional signals_at_dismissal JSON column for recalibration analysis,
 * FR-72d), block_atrisk_grade_snapshots (weekly course-total grade
 * snapshots), block_atrisk_flag_snapshots (weekly per-signal flag state,
 * opt-in via flag_logging_enabled, FR-119 cluster), and
 * block_atrisk_course_shape (course-level operational-shape detection,
 * declared as a no-personal-data table per FR-106g).
 *
 * Per FR-132, dismissed_by anonymisation: when a teacher exercises a
 * delete request, their dismissal-as-actor records have dismissed_by
 * set to 0 rather than the row being dropped — the affected student
 * still has rights to retain or delete the dismissal as their own.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declare metadata for all four owned tables (FR-131).
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'block_atrisk_dismissals',
            [
                'courseid' => 'privacy:metadata:dismissals:courseid',
                'userid' => 'privacy:metadata:dismissals:userid',
                'dismissed_by' => 'privacy:metadata:dismissals:dismissed_by',
                'dismissed_at' => 'privacy:metadata:dismissals:dismissed_at',
                'dismissed_until' => 'privacy:metadata:dismissals:dismissed_until',
                'signals_at_dismissal' => 'privacy:metadata:dismissals:signals_at_dismissal',
            ],
            'privacy:metadata:dismissals'
        );
        $collection->add_database_table(
            'block_atrisk_grade_snapshots',
            [
                'courseid' => 'privacy:metadata:snapshots:courseid',
                'userid' => 'privacy:metadata:snapshots:userid',
                'snapshotweek' => 'privacy:metadata:snapshots:snapshotweek',
                'finalgrade' => 'privacy:metadata:snapshots:finalgrade',
                'timecreated' => 'privacy:metadata:snapshots:timecreated',
            ],
            'privacy:metadata:snapshots'
        );
        $collection->add_database_table(
            'block_atrisk_flag_snapshots',
            [
                'courseid' => 'privacy:metadata:flagsnapshots:courseid',
                'userid' => 'privacy:metadata:flagsnapshots:userid',
                'snapshotweek' => 'privacy:metadata:flagsnapshots:snapshotweek',
                'signal_name' => 'privacy:metadata:flagsnapshots:signal_name',
                'metric_value' => 'privacy:metadata:flagsnapshots:metric_value',
                'percentile' => 'privacy:metadata:flagsnapshots:percentile',
                'preset' => 'privacy:metadata:flagsnapshots:preset',
                'timecreated' => 'privacy:metadata:flagsnapshots:timecreated',
            ],
            'privacy:metadata:flagsnapshots'
        );
        // Course-shape table contains course-level metadata only — no
        // personal data (FR-106g). Declared here for completeness of the
        // privacy provider; its fields are aggregated counts, date
        // distributions, and timing spreads.
        $collection->add_database_table(
            'block_atrisk_course_shape',
            [
                'courseid' => 'privacy:metadata:courseshape:courseid',
                'shape' => 'privacy:metadata:courseshape:shape',
                'confidence' => 'privacy:metadata:courseshape:confidence',
                'features_json' => 'privacy:metadata:courseshape:features_json',
                'lastcomputed' => 'privacy:metadata:courseshape:lastcomputed',
            ],
            'privacy:metadata:courseshape'
        );
        return $collection;
    }

    /**
     * Find course contexts where the given user has plugin-owned data,
     * either as the dismissal subject or actor, or as a snapshot user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Dismissals — both as subject (userid) and as actor (dismissed_by).
        $sql = "SELECT DISTINCT ctx.id
                FROM {block_atrisk_dismissals} d
                JOIN {context} ctx ON ctx.instanceid = d.courseid
                                   AND ctx.contextlevel = :clevel
                WHERE d.userid = :userid OR d.dismissed_by = :userid2";
        $contextlist->add_from_sql($sql, [
            'clevel' => CONTEXT_COURSE,
            'userid' => $userid,
            'userid2' => $userid,
        ]);

        // Snapshots — userid only.
        $sql = "SELECT DISTINCT ctx.id
                FROM {block_atrisk_grade_snapshots} s
                JOIN {context} ctx ON ctx.instanceid = s.courseid
                                   AND ctx.contextlevel = :clevel
                WHERE s.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'clevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        // Flag snapshots — userid only.
        $sql = "SELECT DISTINCT ctx.id
                FROM {block_atrisk_flag_snapshots} f
                JOIN {context} ctx ON ctx.instanceid = f.courseid
                                   AND ctx.contextlevel = :clevel
                WHERE f.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'clevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Add users with plugin-owned data in the given context (subjects,
     * actors, and snapshot users).
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $params = ['courseid' => $context->instanceid];
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {block_atrisk_dismissals} WHERE courseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'dismissed_by',
            "SELECT dismissed_by FROM {block_atrisk_dismissals}
             WHERE courseid = :courseid AND dismissed_by > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {block_atrisk_grade_snapshots} WHERE courseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {block_atrisk_flag_snapshots} WHERE courseid = :courseid',
            $params
        );
    }

    /**
     * Export the user's dismissal and snapshot rows in each approved context.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            $courseid = $context->instanceid;

            // Dismissals — export rows where the user is the subject OR the
            // actor.
            $rows = $DB->get_records_select(
                'block_atrisk_dismissals',
                'courseid = :courseid AND (userid = :u1 OR dismissed_by = :u2)',
                ['courseid' => $courseid, 'u1' => $userid, 'u2' => $userid],
                'dismissed_at ASC'
            );
            if (!empty($rows)) {
                $exported = [];
                foreach ($rows as $row) {
                    $exported[] = [
                        'role' => $row->userid === (int) $userid ? 'subject' : 'actor',
                        'dismissed_at' => transform::datetime($row->dismissed_at),
                        'dismissed_until' => transform::datetime($row->dismissed_until),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_atrisk'), 'dismissals'],
                    (object) ['rows' => $exported]
                );
            }

            // Snapshots — export rows where the user is the subject.
            $rows = $DB->get_records('block_atrisk_grade_snapshots', [
                'courseid' => $courseid, 'userid' => $userid,
            ], 'snapshotweek ASC');
            if (!empty($rows)) {
                $exported = [];
                foreach ($rows as $row) {
                    $exported[] = [
                        'snapshotweek' => $row->snapshotweek,
                        'finalgrade' => $row->finalgrade,
                        'timecreated' => transform::datetime($row->timecreated),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_atrisk'), 'grade_snapshots'],
                    (object) ['rows' => $exported]
                );
            }

            // Flag snapshots — export rows where the user is the subject.
            $rows = $DB->get_records('block_atrisk_flag_snapshots', [
                'courseid' => $courseid, 'userid' => $userid,
            ], 'snapshotweek ASC, signal_name ASC');
            if (!empty($rows)) {
                $exported = [];
                foreach ($rows as $row) {
                    $exported[] = [
                        'snapshotweek' => $row->snapshotweek,
                        'signal_name' => $row->signal_name,
                        'metric_value' => $row->metric_value,
                        'percentile' => $row->percentile,
                        'preset' => $row->preset,
                        'timecreated' => transform::datetime($row->timecreated),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_atrisk'), 'flag_snapshots'],
                    (object) ['rows' => $exported]
                );
            }
        }
    }

    /**
     * Delete all plugin-owned data for the given course context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if (!$context instanceof context_course) {
            return;
        }
        $DB->delete_records('block_atrisk_dismissals', ['courseid' => $context->instanceid]);
        $DB->delete_records('block_atrisk_grade_snapshots', ['courseid' => $context->instanceid]);
        $DB->delete_records('block_atrisk_flag_snapshots', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete plugin-owned data for a single user across the approved
     * contexts. Subject rows are dropped; actor rows are anonymised
     * (dismissed_by → 0) per FR-132.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            $courseid = $context->instanceid;

            // Subject role: drop the user's dismissals.
            $DB->delete_records('block_atrisk_dismissals', [
                'courseid' => $courseid, 'userid' => $userid,
            ]);

            // Actor role: anonymise dismissed_by → 0 (FR-132).
            $DB->execute(
                "UPDATE {block_atrisk_dismissals}
                 SET dismissed_by = 0
                 WHERE courseid = :courseid AND dismissed_by = :userid",
                ['courseid' => $courseid, 'userid' => $userid]
            );

            // Snapshots — drop the user's rows.
            $DB->delete_records('block_atrisk_grade_snapshots', [
                'courseid' => $courseid, 'userid' => $userid,
            ]);
            $DB->delete_records('block_atrisk_flag_snapshots', [
                'courseid' => $courseid, 'userid' => $userid,
            ]);
        }
    }

    /**
     * Delete plugin-owned data for multiple users in a single context.
     * Subjects: drop. Actors: anonymise. Snapshot users: drop.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params = array_merge(['courseid' => $context->instanceid], $inparams);

        // Subject deletions.
        $DB->delete_records_select(
            'block_atrisk_dismissals',
            "courseid = :courseid AND userid {$insql}",
            $params
        );
        // Actor anonymisation.
        $DB->execute(
            "UPDATE {block_atrisk_dismissals}
             SET dismissed_by = 0
             WHERE courseid = :courseid AND dismissed_by {$insql}",
            $params
        );
        // Snapshot deletions.
        $DB->delete_records_select(
            'block_atrisk_grade_snapshots',
            "courseid = :courseid AND userid {$insql}",
            $params
        );
        $DB->delete_records_select(
            'block_atrisk_flag_snapshots',
            "courseid = :courseid AND userid {$insql}",
            $params
        );
    }
}
