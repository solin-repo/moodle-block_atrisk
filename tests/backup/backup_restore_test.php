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
 * Course backup + restore round-trip coverage for block_atrisk.
 *
 * Asserts FR-114 (per-instance configdata round-trips) and FR-115
 * (plugin-owned tables are excluded from backups). Also exercises that
 * the restored block reads its overrides through the standard engine
 * pipeline.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_atrisk;

use advanced_testcase;
use backup;
use backup_controller;
use block_atrisk\local\engine_config;
use context_course;
use restore_controller;
use restore_dbops;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * @coversNothing
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Build a configdata blob with non-default values for every per-instance key
     * exposed by edit_form.php so any silently-dropped key fails the round-trip.
     */
    private function build_configdata(): string {
        $config = new stdClass();
        $config->topn = 7;
        $config->forum_silence_enabled = '1';
        $config->peer_scope = 'group';
        $config->course_shape_override = 'self_paced';
        $config->course_breaks = "2026-04-01, 2026-04-07\n";
        return base64_encode(serialize($config));
    }

    /**
     * Backup $course as TYPE_1COURSE and restore as a brand-new course; returns the new course id.
     */
    private function backup_and_restore(stdClass $course): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = backup::LOG_NONE;
        set_config('backup_general_users', 1, 'backup');

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $this->assertNotEmpty($file, 'Backup file was not produced.');
        $fp = get_file_packer('application/vnd.moodle.backup');
        $tmpdir = 'test-block-atrisk-' . uniqid();
        $filepath = $CFG->dataroot . '/temp/backup/' . $tmpdir;
        $file->extract_to_pathname($fp, $filepath);
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course(
            $course->fullname,
            $course->shortname . '_restored_' . uniqid(),
            $course->category
        );
        $rc = new restore_controller(
            $tmpdir,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    public function test_per_instance_configdata_round_trips_through_backup_and_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $coursecontext = context_course::instance($course->id);

        $blockgen = $this->getDataGenerator()->get_plugin_generator('block_atrisk');
        $blockgen->create_instance(
            [
                'parentcontextid' => $coursecontext->id,
                'pagetypepattern' => 'course-view-*',
                'configdata' => $this->build_configdata(),
            ],
            []
        );

        $newcourseid = $this->backup_and_restore($course);
        $newcontext = context_course::instance($newcourseid);

        $rows = $DB->get_records(
            'block_instances',
            ['blockname' => 'atrisk', 'parentcontextid' => $newcontext->id]
        );
        $this->assertCount(1, $rows, 'Restored course should have exactly one block_atrisk instance.');
        $row = reset($rows);

        $restored = unserialize_object(base64_decode($row->configdata));
        $this->assertIsObject($restored);
        $this->assertSame(7, (int) $restored->topn);
        $this->assertSame('1', (string) $restored->forum_silence_enabled);
        $this->assertSame('group', (string) $restored->peer_scope);
        $this->assertSame('self_paced', (string) $restored->course_shape_override);
        $this->assertSame(
            "2026-04-01, 2026-04-07\n",
            (string) $restored->course_breaks
        );
    }

    public function test_owned_user_data_tables_are_not_carried_into_the_restored_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $coursecontext = context_course::instance($course->id);

        $blockgen = $this->getDataGenerator()->get_plugin_generator('block_atrisk');
        $blockgen->create_instance(
            ['parentcontextid' => $coursecontext->id, 'pagetypepattern' => 'course-view-*'],
            []
        );

        $now = time();
        $DB->insert_record('block_atrisk_dismissals', (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
            'dismissed_by' => $teacher->id,
            'dismissed_at' => $now,
            'dismissed_until' => $now + WEEKSECS,
            'signals_at_dismissal' => '{}',
        ]);
        $DB->insert_record('block_atrisk_grade_snapshots', (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
            'snapshotweek' => 202618,
            'finalgrade' => 75.0,
            'timecreated' => $now,
        ]);
        $DB->insert_record('block_atrisk_flag_snapshots', (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
            'snapshotweek' => 202618,
            'signal_name' => 'inactivity',
            'metric_value' => 14.0,
            'percentile' => 5,
            'preset' => 'default',
            'timecreated' => $now,
        ]);
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => 'cohort_paced',
            'confidence' => 'high',
            'features_json' => '{}',
            'lastcomputed' => $now,
        ]);

        $newcourseid = $this->backup_and_restore($course);

        $this->assertSame(
            0,
            $DB->count_records('block_atrisk_dismissals', ['courseid' => $newcourseid]),
            'Dismissals must not be carried into a restored course (FR-115).'
        );
        $this->assertSame(
            0,
            $DB->count_records('block_atrisk_grade_snapshots', ['courseid' => $newcourseid]),
            'Grade snapshots must not be carried into a restored course (FR-115).'
        );
        $this->assertSame(
            0,
            $DB->count_records('block_atrisk_flag_snapshots', ['courseid' => $newcourseid]),
            'Flag snapshots must not be carried into a restored course (FR-115).'
        );
        $this->assertSame(
            0,
            $DB->count_records('block_atrisk_course_shape', ['courseid' => $newcourseid]),
            'Per-course shape rows must not be carried over — they are re-derived for the new course.'
        );

        // Sanity: the source course rows are untouched by the round-trip.
        $this->assertSame(1, $DB->count_records('block_atrisk_dismissals', ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('block_atrisk_grade_snapshots', ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('block_atrisk_flag_snapshots', ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('block_atrisk_course_shape', ['courseid' => $course->id]));
    }

    public function test_restored_block_feeds_overrides_through_engine_config(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Force the site default for forum-silence to OFF so the per-instance
        // override (set to '1') is the only thing that can turn the signal on.
        set_config('signal_forum_silence_enabled', 0, 'block_atrisk');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $coursecontext = context_course::instance($course->id);

        $blockgen = $this->getDataGenerator()->get_plugin_generator('block_atrisk');
        $blockgen->create_instance(
            [
                'parentcontextid' => $coursecontext->id,
                'pagetypepattern' => 'course-view-*',
                'configdata' => $this->build_configdata(),
            ],
            []
        );

        $newcourseid = $this->backup_and_restore($course);
        $newcontext = context_course::instance($newcourseid);

        $row = $DB->get_record(
            'block_instances',
            ['blockname' => 'atrisk', 'parentcontextid' => $newcontext->id],
            '*',
            MUST_EXIST
        );
        $instanceconfig = unserialize_object(base64_decode($row->configdata));
        $config = engine_config::build_for_preset('default', $instanceconfig, $newcourseid);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('signals', $config);
        $this->assertArrayHasKey(
            'forum_silence',
            $config['signals'],
            'Per-instance forum_silence_enabled=1 must turn the signal on after restore even though the site default is off.'
        );
        $this->assertArrayHasKey('breaks', $config);
        $this->assertNotEmpty(
            $config['breaks'],
            'Per-instance course_breaks must be parsed and threaded through the restored engine config.'
        );
    }
}
