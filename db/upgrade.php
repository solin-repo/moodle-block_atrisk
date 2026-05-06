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

/**
 * Upgrade steps for block_atrisk.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run upgrade steps for block_atrisk.
 *
 * @param int $oldversion The previously installed plugin version.
 * @return bool
 */
function xmldb_block_atrisk_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026050227) {
        // Percentile direction was inverted for high-is-bad signals
        // (inactivity, miss count) AND sort order now uses percentile as
        // a tiebreaker before name. Cached flagged_student lists from
        // earlier versions are stale on both axes; purge.
        \cache::make('block_atrisk', 'rawsignals')->purge();
        upgrade_block_savepoint(true, 2026050227, 'atrisk');
    }

    if ($oldversion < 2026050315) {
        $dbman = $DB->get_manager();

        // Add signals_at_dismissal column to existing dismissals table
        // (FR-72d).
        $table = new xmldb_table('block_atrisk_dismissals');
        $field = new xmldb_field(
            'signals_at_dismissal',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'dismissed_until'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create the flag_snapshots table (FR-119 cluster).
        $newtable = new xmldb_table('block_atrisk_flag_snapshots');
        if (!$dbman->table_exists($newtable)) {
            $newtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $newtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $newtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $newtable->add_field('snapshotweek', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, null);
            $newtable->add_field('signal_name', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $newtable->add_field('metric_value', XMLDB_TYPE_NUMBER, '10, 3', null, null, null, null);
            $newtable->add_field('percentile', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $newtable->add_field('preset', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $newtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $newtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $newtable->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $newtable->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $newtable->add_key(
                'course_user_week_signal',
                XMLDB_KEY_UNIQUE,
                ['courseid', 'userid', 'snapshotweek', 'signal_name']
            );

            $newtable->add_index('course_week', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'snapshotweek']);
            $newtable->add_index('signal_name', XMLDB_INDEX_NOTUNIQUE, ['signal_name']);

            $dbman->create_table($newtable);
        }

        upgrade_block_savepoint(true, 2026050315, 'atrisk');
    }

    if ($oldversion < 2026050400) {
        $dbman = $DB->get_manager();

        // Create the course_shape table (FR-106c).
        $shapetable = new xmldb_table('block_atrisk_course_shape');
        if (!$dbman->table_exists($shapetable)) {
            $shapetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $shapetable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $shapetable->add_field('shape', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $shapetable->add_field('confidence', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, null);
            $shapetable->add_field('features_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $shapetable->add_field('lastcomputed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $shapetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $shapetable->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);

            $shapetable->add_index('shape_confidence', XMLDB_INDEX_NOTUNIQUE, ['shape', 'confidence']);

            $dbman->create_table($shapetable);
        }

        upgrade_block_savepoint(true, 2026050400, 'atrisk');
    }

    return true;
}
