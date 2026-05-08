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

namespace block_atrisk;

use advanced_testcase;
use block_atrisk\local\engine_config;
use context_block;
use context_course;

/**
 * Lifecycle tests covering add + remove of a block_atrisk instance on a course.
 *
 * Regression guard for the install.xml / upgrade.php schema completeness
 * issue that surfaced when adding a fresh instance hit
 * `course_shape::effective_shape()` before its table existed.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_instance_test extends advanced_testcase {
    public function test_add_block_instance_to_course_creates_row_and_engine_config_loads(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);

        $blockgen = $this->getDataGenerator()->get_plugin_generator('block_atrisk');
        $instance = $blockgen->create_instance(
            ['parentcontextid' => $coursecontext->id, 'pagetypepattern' => 'course-view-*'],
            []
        );

        $this->assertNotEmpty($instance->id);

        $row = $DB->get_record('block_instances', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('atrisk', $row->blockname);
        $this->assertEquals($coursecontext->id, $row->parentcontextid);

        // Block context is provisioned (used for capability checks at render).
        $this->assertNotNull(context_block::instance($instance->id, IGNORE_MISSING));

        // Build the engine config the way the renderer does. This exercises
        // the `course_shape::effective_shape()` path that hits
        // mdl_block_atrisk_course_shape — the table whose absence triggered
        // the original add-instance crash.
        $config = engine_config::build_for_preset('default', null, $course->id);
        $this->assertIsArray($config);
        $this->assertArrayHasKey('signals', $config);
        $this->assertArrayHasKey('thresholds', $config);
        $this->assertArrayHasKey('course_shape', $config);
    }

    public function test_remove_block_instance_deletes_row_and_block_context(): void {
        $this->resetAfterTest();
        global $DB, $CFG;
        require_once($CFG->dirroot . '/lib/blocklib.php');

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);

        $blockgen = $this->getDataGenerator()->get_plugin_generator('block_atrisk');
        $instance = $blockgen->create_instance(
            ['parentcontextid' => $coursecontext->id, 'pagetypepattern' => 'course-view-*'],
            []
        );

        $this->assertTrue($DB->record_exists('block_instances', ['id' => $instance->id]));
        $blockcontextid = context_block::instance($instance->id)->id;

        blocks_delete_instance($DB->get_record('block_instances', ['id' => $instance->id]));

        $this->assertFalse(
            $DB->record_exists('block_instances', ['id' => $instance->id]),
            'block_instances row should be removed'
        );
        $this->assertFalse(
            $DB->record_exists('block_positions', ['blockinstanceid' => $instance->id]),
            'block_positions rows should be cleaned up'
        );
        $this->assertFalse(
            $DB->record_exists('context', ['id' => $blockcontextid]),
            'block context should be removed'
        );
    }

    public function test_add_two_block_instances_on_same_course_both_persist(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);

        $blockgen = $this->getDataGenerator()->get_plugin_generator('block_atrisk');
        $first = $blockgen->create_instance(
            ['parentcontextid' => $coursecontext->id, 'pagetypepattern' => 'course-view-*'],
            []
        );
        $second = $blockgen->create_instance(
            ['parentcontextid' => $coursecontext->id, 'pagetypepattern' => 'course-view-*'],
            []
        );

        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(
            2,
            $DB->count_records('block_instances', [
                'blockname' => 'atrisk',
                'parentcontextid' => $coursecontext->id,
            ])
        );
    }
}
