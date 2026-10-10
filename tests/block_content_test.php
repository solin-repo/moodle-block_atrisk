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
use context_course;

/**
 * Tests for the block class: content assembly for each viewer and course state.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_atrisk::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_atrisk\output\renderer::class)]
final class block_content_test extends advanced_testcase {
    /**
     * Load the block library and class.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/blocklib.php');
        block_load_class('atrisk');
        $this->resetAfterTest();
    }

    /**
     * Create a course that is past its calibration window.
     *
     * @param array $overrides Course record overrides.
     * @return \stdClass
     */
    private function make_course(array $overrides = []): \stdClass {
        return $this->getDataGenerator()->create_course($overrides + [
            'enablecompletion' => 1,
            'startdate' => time() - 10 * WEEKSECS,
        ]);
    }

    /**
     * Add the block to a course and return it, instantiated on a course page.
     *
     * @param \stdClass $course
     * @param \stdClass|null $config Per-instance configuration.
     * @return \block_atrisk
     */
    private function make_block(\stdClass $course, ?\stdClass $config = null): \block_atrisk {
        global $COURSE, $DB;
        $record = ['parentcontextid' => context_course::instance($course->id)->id, 'pagetypepattern' => 'course-view-*'];
        if ($config !== null) {
            $record['configdata'] = base64_encode(serialize($config));
        }
        $instance = $this->getDataGenerator()->get_plugin_generator('block_atrisk')->create_instance($record);
        $page = new \moodle_page();
        $page->set_course($course);
        $page->set_pagetype('course-view-topics');
        $page->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        // The block reads the global course, which only the global page sets.
        $COURSE = $course;
        return block_instance('atrisk', $DB->get_record('block_instances', ['id' => $instance->id]), $page);
    }

    /**
     * Render the block for a course as the given user.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param \stdClass|null $config Per-instance configuration.
     * @return string Block HTML.
     */
    private function render_as(\stdClass $course, \stdClass $user, ?\stdClass $config = null): string {
        $this->setUser($user);
        $block = $this->make_block($course, $config);
        return $block->get_content()->text;
    }

    /**
     * The block is course-view only, single-instance and has site settings.
     */
    public function test_block_declarations(): void {
        $block = new \block_atrisk();
        $this->assertSame(get_string('pluginname', 'block_atrisk'), $block->title);
        $this->assertSame(['all' => false, 'course-view' => true], $block->applicable_formats());
        $this->assertTrue($block->has_config());
        $this->assertFalse($block->instance_allow_multiple());
    }

    /**
     * A teacher sees flagged students, the preset and pause controls, and the
     * view-all link once the list is longer than the visible row count.
     */
    public function test_teacher_sees_flagged_students_and_controls(): void {
        set_config('sensitivity_preset_enabled', 1, 'block_atrisk');
        $course = $this->make_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student', ['lastname' => 'Aardvark']);
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student', ['lastname' => 'Zebra']);

        $this->setUser($teacher);
        $block = $this->make_block($course, (object) ['topn' => 1]);
        $content = $block->get_content();
        $html = $content->text;

        // Second call returns the same, already built content.
        $this->assertSame($content, $block->get_content());

        $this->assertStringContainsString('Aardvark', $html);
        $this->assertStringNotContainsString('Zebra', $html);
        $this->assertStringContainsString(get_string('viewallflagged', 'block_atrisk', (object) ['count' => 2]), $html);
        $this->assertStringContainsString('block_atrisk_preset_btn_active', $html);
        $this->assertStringContainsString('/blocks/atrisk/pause.php', $html);
        $this->assertStringContainsString(get_string('action_message', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('action_dismiss', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('action_outline', 'block_atrisk'), $html);
        $this->assertStringNotContainsString(get_string('banner_completion_off', 'block_atrisk'), $html);
        $this->assertStringContainsString('block_atrisk/expand_all', $block->page->requires->get_end_code());
    }

    /**
     * Without the preset setting, the sensitivity control is hidden.
     */
    public function test_preset_control_hidden_when_disabled(): void {
        set_config('sensitivity_preset_enabled', 0, 'block_atrisk');
        $course = $this->make_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $html = $this->render_as($course, $teacher);
        $this->assertStringNotContainsString('block_atrisk_preset_btn', $html);
    }

    /**
     * Viewers without the viewflags capability, and the site course, get empty content.
     */
    public function test_empty_content_without_capability_or_on_site_course(): void {
        global $COURSE;
        $course = $this->make_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertSame('', $this->render_as($course, $student));

        $this->setAdminUser();
        $block = $this->make_block($course);
        $COURSE = get_site();
        $this->assertSame('', $block->get_content()->text);
    }

    /**
     * Each empty state renders its own message.
     */
    public function test_empty_states(): void {
        global $DB;
        $course = $this->make_course(['enablecompletion' => 0]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        // No students at all; completion tracking off shows the banner too.
        $html = $this->render_as($course, $teacher);
        $this->assertStringContainsString(get_string('empty_no_enrolments', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('banner_completion_off', 'block_atrisk'), $html);

        // A student who is active: nobody flagged.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id, 'courseid' => $course->id, 'timeaccess' => time(),
        ]);
        \block_atrisk\local\cached_flag_engine::invalidate_course((int) $course->id);
        $this->assertStringContainsString(
            get_string('empty_none_flagged', 'block_atrisk'),
            $this->render_as($course, $teacher)
        );
    }

    /**
     * A course past its end date shows the "course has ended" state when
     * suppression is on, and the normal list when the block overrides it off.
     */
    public function test_ended_course(): void {
        $course = $this->make_course(['enddate' => time() - WEEKSECS]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['lastname' => 'Aardvark']);

        $html = $this->render_as($course, $teacher);
        $this->assertStringContainsString(get_string('empty_course_ended', 'block_atrisk'), $html);

        $html = $this->render_as($course, $teacher, (object) ['skip_ended_courses' => '0']);
        $this->assertStringContainsString('Aardvark', $html);
    }

    /**
     * An active break shows the paused banner with a resume link (and no
     * pause link); a recent past break shows the dampening note.
     */
    public function test_breaks_banner_and_dampening_note(): void {
        $course = $this->make_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        $today = date('Y-m-d');
        $nextweek = date('Y-m-d', time() + WEEKSECS);
        $html = $this->render_as($course, $teacher, (object) ['course_breaks' => "{$today}, {$nextweek}"]);
        $this->assertStringContainsString('block_atrisk_paused', $html);
        $this->assertStringContainsString('action=resume', $html);
        $this->assertStringNotContainsString('block_atrisk_pause_link', $html);

        $from = date('Y-m-d', time() - 20 * DAYSECS);
        $to = date('Y-m-d', time() - 18 * DAYSECS);
        $html = $this->render_as($course, $teacher, (object) ['course_breaks' => "{$from}, {$to}"]);
        $this->assertStringContainsString(get_string('breaks_dampened', 'block_atrisk', (object) ['days' => 3]), $html);
        $this->assertStringContainsString('block_atrisk_pause_link', $html);
    }

    /**
     * Under separate groups, a teacher without access to all groups sees only
     * students in their own groups, and a teacher in no group gets the
     * dedicated empty state.
     */
    public function test_separate_groups_restrict_the_list(): void {
        $course = $this->make_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $inside = $this->getDataGenerator()->create_and_enrol($course, 'student', ['lastname' => 'Aardvark']);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['lastname' => 'Zebra']);

        // Not in any group yet.
        $html = $this->render_as($course, $teacher);
        $this->assertStringContainsString(get_string('empty_no_group_assignment', 'block_atrisk'), $html);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $inside->id]);

        // Whole-course statistics, list trimmed to the teacher's group.
        $html = $this->render_as($course, $teacher);
        $this->assertStringContainsString('Aardvark', $html);
        $this->assertStringNotContainsString('Zebra', $html);

        // Group-scoped statistics: the engine only sees the group.
        $html = $this->render_as($course, $teacher, (object) ['peer_scope' => 'group']);
        $this->assertStringContainsString('Aardvark', $html);
        $this->assertStringNotContainsString('Zebra', $html);
    }
}
