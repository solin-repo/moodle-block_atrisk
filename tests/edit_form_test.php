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
use block_atrisk\local\course_shape;

/**
 * Tests for the per-instance block configuration form.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_atrisk_edit_form::class)]
final class edit_form_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /**
     * Load the form classes and create a course as admin.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/blocklib.php');
        require_once($CFG->dirroot . '/blocks/edit_form.php');
        require_once($CFG->dirroot . '/blocks/atrisk/edit_form.php');
        block_load_class('atrisk');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Build the edit form for a new block instance on the course.
     *
     * @return \block_atrisk_edit_form
     */
    private function make_form(): \block_atrisk_edit_form {
        global $DB, $PAGE;
        $context = \context_course::instance($this->course->id);
        $instance = $this->getDataGenerator()->get_plugin_generator('block_atrisk')->create_instance([
            'parentcontextid' => $context->id,
            'pagetypepattern' => 'course-view-*',
        ]);
        $PAGE = new \moodle_page();
        $PAGE->set_course($this->course);
        $PAGE->set_pagetype('course-view-topics');
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        // The block manager adds the effective region and weight when it loads blocks.
        $record = $DB->get_record('block_instances', ['id' => $instance->id]);
        $record->region = $record->defaultregion;
        $record->weight = $record->defaultweight;
        $block = block_instance('atrisk', $record, $PAGE);
        return new \block_atrisk_edit_form(null, ['block' => $block, 'page' => $PAGE]);
    }

    /**
     * Valid form data for the plugin's own fields.
     *
     * @param array $overrides
     * @return array
     */
    private function data(array $overrides = []): array {
        return $overrides + [
            'config_topn' => '',
            'config_course_breaks' => '',
        ];
    }

    /**
     * The form shows the site defaults it inherits and reports that no course
     * shape has been detected yet.
     */
    public function test_definition_shows_site_defaults_and_missing_detection(): void {
        set_config('display_top_n', 9, 'block_atrisk');
        set_config('signal_forum_silence_enabled', 1, 'block_atrisk');
        set_config('skip_ended_courses', 0, 'block_atrisk');

        $html = $this->make_form()->render();

        $this->assertStringContainsString('name="config_topn"', $html);
        $this->assertStringContainsString(
            get_string('config_topn_desc', 'block_atrisk', (object) ['sitedefault' => 9]),
            $html
        );
        $this->assertStringContainsString(get_string('config_forum_silence_inherit_on', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('config_skip_ended_courses_inherit_off', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('config_course_shape_not_detected', 'block_atrisk'), $html);
        $this->assertStringContainsString('name="config_course_breaks"', $html);
        $this->assertStringContainsString('name="config_peer_scope"', $html);
    }

    /**
     * With the opposite site defaults and a stored detection, the labels
     * follow and the detected shape and confidence are shown.
     */
    public function test_definition_shows_detected_shape(): void {
        set_config('signal_forum_silence_enabled', 0, 'block_atrisk');
        set_config('skip_ended_courses', 1, 'block_atrisk');
        course_shape::detect_and_store((int) $this->course->id);
        $detected = course_shape::load((int) $this->course->id);

        $html = $this->make_form()->render();

        $this->assertStringContainsString(get_string('config_forum_silence_inherit_off', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('config_skip_ended_courses_inherit_on', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('config_course_shape_detected', 'block_atrisk', (object) [
            'shape' => get_string('shape_' . $detected->shape, 'block_atrisk'),
            'confidence' => get_string('confidence_' . $detected->confidence, 'block_atrisk'),
        ]), $html);
    }

    /**
     * Validation accepts good input and rejects malformed breaks and a row
     * count above the cap.
     */
    public function test_validation(): void {
        $form = $this->make_form();

        $this->assertSame([], $form->validation($this->data(), []));
        $this->assertSame([], $form->validation($this->data([
            'config_topn' => (string) \block_atrisk::MAX_TOPN,
            'config_course_breaks' => '2026-12-21, 2027-01-03',
        ]), []));

        $errors = $form->validation($this->data(['config_course_breaks' => 'next week']), []);
        $this->assertArrayHasKey('config_course_breaks', $errors);

        $errors = $form->validation($this->data(['config_topn' => (string) (\block_atrisk::MAX_TOPN + 1)]), []);
        $this->assertSame(
            get_string('config_topn_max', 'block_atrisk', \block_atrisk::MAX_TOPN),
            $errors['config_topn']
        );
    }
}
