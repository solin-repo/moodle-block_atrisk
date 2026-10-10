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

use advanced_testcase;

/**
 * Unit tests for {@see engine_config}.
 *
 * Covers the per-preset threshold map, legacy preset-name aliasing, and
 * the per-instance forum-silence override (FR-29 v1 implementation).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_atrisk\local\engine_config::class)]
final class engine_config_test extends advanced_testcase {
    public function test_more_preset_yields_more_flags_per_signal(): void {
        $this->resetAfterTest();
        $more = engine_config::build_for_preset('more');

        // Inactivity + forum-silence: shorter window = more flags.
        $this->assertSame(5, $more['thresholds']['inactivity']['days']);
        $this->assertSame(10, $more['thresholds']['forum_silence']['days']);
        // Assessment-miss: longer window = more flags (more eligible activities).
        $this->assertSame(21, $more['thresholds']['assessment_miss']['days']);
    }

    public function test_fewer_preset_inverts_more(): void {
        $this->resetAfterTest();
        $fewer = engine_config::build_for_preset('fewer');

        $this->assertSame(14, $fewer['thresholds']['inactivity']['days']);
        $this->assertSame(21, $fewer['thresholds']['forum_silence']['days']);
        $this->assertSame(10, $fewer['thresholds']['assessment_miss']['days']);
    }

    public function test_default_preset_uses_site_config(): void {
        $this->resetAfterTest();
        set_config('signal_inactivity_days', 9, 'block_atrisk');
        set_config('signal_assessment_miss_days', 11, 'block_atrisk');
        set_config('signal_forum_silence_days', 13, 'block_atrisk');

        $default = engine_config::build_for_preset('default');

        $this->assertSame(9, $default['thresholds']['inactivity']['days']);
        $this->assertSame(11, $default['thresholds']['assessment_miss']['days']);
        $this->assertSame(13, $default['thresholds']['forum_silence']['days']);
    }

    /**
     * Legacy preset names from earlier drafts must still resolve so
     * stored block-instance configdata from prior versions keeps working.
     */
    public function test_legacy_preset_aliases(): void {
        $this->resetAfterTest();
        $loose = engine_config::build_for_preset('loose');
        $more = engine_config::build_for_preset('more');
        $this->assertSame($more['thresholds'], $loose['thresholds']);

        $strict = engine_config::build_for_preset('strict');
        $fewer = engine_config::build_for_preset('fewer');
        $this->assertSame($fewer['thresholds'], $strict['thresholds']);

        $balanced = engine_config::build_for_preset('balanced');
        $default = engine_config::build_for_preset('default');
        $this->assertSame($default['thresholds'], $balanced['thresholds']);
    }

    public function test_unknown_preset_returns_empty_thresholds(): void {
        $this->resetAfterTest();
        $cfg = engine_config::build_for_preset('does-not-exist');
        $this->assertSame([], $cfg['thresholds']);
        // Site-level signal-enable flags should still be present.
        $this->assertArrayHasKey('signals', $cfg);
    }

    public function test_forum_silence_inherits_site_when_no_instance_override(): void {
        $this->resetAfterTest();
        set_config('signal_forum_silence_enabled', 1, 'block_atrisk');
        $cfg = engine_config::build_for_preset('default');
        $this->assertTrue($cfg['signals']['forum_silence']);

        set_config('signal_forum_silence_enabled', 0, 'block_atrisk');
        $cfg = engine_config::build_for_preset('default');
        $this->assertFalse($cfg['signals']['forum_silence']);
    }

    public function test_forum_silence_instance_override_force_on(): void {
        $this->resetAfterTest();
        set_config('signal_forum_silence_enabled', 0, 'block_atrisk');
        $instance = (object) ['forum_silence_enabled' => '1'];
        $cfg = engine_config::build_for_preset('default', $instance);
        $this->assertTrue(
            $cfg['signals']['forum_silence'],
            'Instance "force on" must override site default off.'
        );
    }

    public function test_forum_silence_instance_override_force_off(): void {
        $this->resetAfterTest();
        set_config('signal_forum_silence_enabled', 1, 'block_atrisk');
        $instance = (object) ['forum_silence_enabled' => '0'];
        $cfg = engine_config::build_for_preset('default', $instance);
        $this->assertFalse(
            $cfg['signals']['forum_silence'],
            'Instance "force off" must override site default on.'
        );
    }

    public function test_forum_silence_instance_inherit_keeps_site(): void {
        $this->resetAfterTest();
        set_config('signal_forum_silence_enabled', 1, 'block_atrisk');
        // The 'site' value is the form's "inherit" sentinel; anything other
        // than '0' or '1' should fall through to the site setting.
        foreach (['site', '', null] as $sentinel) {
            $instance = (object) ['forum_silence_enabled' => $sentinel];
            $cfg = engine_config::build_for_preset('default', $instance);
            $this->assertTrue(
                $cfg['signals']['forum_silence'],
                "Sentinel " . var_export($sentinel, true) . " should inherit site default."
            );
        }
    }

    /**
     * The engine config exposes the resolved "skip ended courses" flag,
     * defaulting to on when the site setting has never been written.
     */
    public function test_skip_ended_courses_defaults_on(): void {
        $this->resetAfterTest();
        $cfg = engine_config::build_for_preset('default');
        $this->assertTrue($cfg['skip_ended_courses']);
    }

    public function test_skip_ended_courses_site_off(): void {
        $this->resetAfterTest();
        set_config('skip_ended_courses', 0, 'block_atrisk');
        $cfg = engine_config::build_for_preset('default');
        $this->assertFalse($cfg['skip_ended_courses']);
    }

    public function test_skip_ended_courses_instance_override(): void {
        $this->resetAfterTest();

        // Site on, instance forces off.
        set_config('skip_ended_courses', 1, 'block_atrisk');
        $off = engine_config::build_for_preset('default', (object) ['skip_ended_courses' => '0']);
        $this->assertFalse($off['skip_ended_courses']);

        // Site off, instance forces on.
        set_config('skip_ended_courses', 0, 'block_atrisk');
        $on = engine_config::build_for_preset('default', (object) ['skip_ended_courses' => '1']);
        $this->assertTrue($on['skip_ended_courses']);

        // The 'site' sentinel inherits the site setting (currently off).
        $inherit = engine_config::build_for_preset('default', (object) ['skip_ended_courses' => 'site']);
        $this->assertFalse($inherit['skip_ended_courses']);
    }

    /**
     * With a course, the effective shape is resolved through the instance
     * override, and a self-paced shape silences the peer-relative signals.
     */
    public function test_course_shape_override_and_self_paced_silencing(): void {
        $this->resetAfterTest();
        set_config('signal_stalled_completion_enabled', 1, 'block_atrisk');
        set_config('signal_forum_silence_enabled', 1, 'block_atrisk');
        $course = $this->getDataGenerator()->create_course();

        $config = engine_config::build_for_preset('default', null, (int) $course->id);
        $this->assertNull($config['course_shape']);
        $this->assertTrue($config['signals']['stalled_completion']);

        $instance = (object) ['course_shape_override' => course_shape::SHAPE_SELF_PACED];
        $config = engine_config::build_for_preset('default', $instance, (int) $course->id);
        $this->assertSame(course_shape::SHAPE_SELF_PACED, $config['course_shape']);
        $this->assertFalse($config['signals']['stalled_completion']);
        $this->assertFalse($config['signals']['forum_silence']);
    }

    /**
     * Site and course breaks are merged; overlapping and adjacent ranges
     * collapse, and an unparseable list is ignored rather than fatal.
     */
    public function test_breaks_are_merged_and_bad_input_ignored(): void {
        $this->resetAfterTest();
        set_config('breaks_calendar', "2026-12-21, 2026-12-31\n2027-03-01, 2027-03-05", 'block_atrisk');
        $instance = (object) ['course_breaks' => "2027-01-01, 2027-01-03\n2026-12-24, 2026-12-26"];

        $breaks = engine_config::build_for_preset('default', $instance)['breaks'];
        $this->assertSame([
            ['start' => strtotime('2026-12-21 00:00:00'), 'end' => strtotime('2027-01-03 00:00:00') + DAYSECS - 1],
            ['start' => strtotime('2027-03-01 00:00:00'), 'end' => strtotime('2027-03-05 00:00:00') + DAYSECS - 1],
        ], $breaks);

        // A broken course list keeps the site list; a broken site list keeps the course list.
        $breaks = engine_config::build_for_preset('default', (object) ['course_breaks' => 'soon'])['breaks'];
        $this->assertCount(2, $breaks);
        set_config('breaks_calendar', 'whenever', 'block_atrisk');
        $breaks = engine_config::build_for_preset('default', $instance)['breaks'];
        $this->assertSame(strtotime('2026-12-24 00:00:00'), $breaks[0]['start']);
        $this->assertSame(strtotime('2027-01-01 00:00:00'), $breaks[1]['start']);
        $this->assertSame([], engine_config::build_for_preset('default', (object) ['course_breaks' => ''])['breaks']);
    }
}
