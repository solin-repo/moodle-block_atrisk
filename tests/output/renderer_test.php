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

namespace block_atrisk\output;

use advanced_testcase;
use block_atrisk\local\calibration_window;
use block_atrisk\local\flagged_student;
use block_atrisk\local\severity;
use block_atrisk\local\signal_result;

/**
 * Tests for the block renderer's individual rendering branches.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(renderer::class)]
final class renderer_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /**
     * Course with a teacher logged in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher'));
    }

    /**
     * Get the plugin renderer on a fresh page.
     *
     * @return renderer
     */
    private function renderer(): renderer {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_course($this->course);
        $PAGE->set_url(new \moodle_url('/blocks/atrisk/view.php', ['courseid' => $this->course->id]));
        return $PAGE->get_renderer('block_atrisk');
    }

    /**
     * Base render context.
     *
     * @param array $overrides
     * @return array
     */
    private function context(array $overrides = []): array {
        return $overrides + [
            'courseid' => (int) $this->course->id,
            'blockinstanceid' => 7,
            'topn' => 10,
            'completionenabled' => true,
        ];
    }

    /**
     * Build a flagged student for a user.
     *
     * @param int $userid
     * @param string $severity
     * @param string $phase
     * @param int|null $percentile
     * @return flagged_student
     */
    private function flagged(
        int $userid,
        string $severity = severity::YELLOW,
        string $phase = calibration_window::PHASE_CONFIDENT,
        ?int $percentile = null
    ): flagged_student {
        return new flagged_student($userid, $severity, $phase, [
            'inactivity' => new signal_result(true, 'No access for <9> days', 9),
        ], $percentile);
    }

    /**
     * The view.php variant: an expand-all URL renders a real link, rows are
     * pre-expanded, and no JavaScript toggle is registered.
     */
    public function test_expand_all_url_variant(): void {
        global $PAGE;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $url = new \moodle_url('/blocks/atrisk/view.php', ['courseid' => $this->course->id, 'expandall' => 0]);

        $html = $this->renderer()->render_flagged_list([$this->flagged((int) $student->id)], $this->context([
            'expandall' => true,
            'expandallurl' => $url,
        ]));

        $this->assertStringContainsString('data-state="expanded"', $html);
        $this->assertStringContainsString(get_string('action_collapse_all', 'block_atrisk'), $html);
        $this->assertStringContainsString('expandall=0', $html);
        $this->assertStringContainsString('<details class="block_atrisk_details" open="open">', $html);
        // Explanations are escaped.
        $this->assertStringContainsString('No access for &lt;9&gt; days', $html);
        $this->assertStringNotContainsString('block_atrisk/expand_all', $PAGE->requires->get_end_code());
    }

    /**
     * The return URL is carried on the preset and pause links, and a legacy
     * preset name highlights its current equivalent.
     */
    public function test_return_url_and_legacy_preset_alias(): void {
        $returnurl = new \moodle_url('/blocks/atrisk/view.php', ['courseid' => $this->course->id]);
        $html = $this->renderer()->render_flagged_list([], $this->context([
            'showpresetcontrol' => true,
            'canconfigure' => true,
            'preset' => 'strict',
            'presetreturnurl' => $returnurl,
        ]));

        $this->assertSame(4, substr_count($html, 'returnurl=%2Fblocks%2Fatrisk%2Fview.php'));
        $this->assertMatchesRegularExpression('/block_atrisk_preset_btn_active"[^>]*preset=fewer/', $html);
    }

    /**
     * A tentative-phase row carries the badge; a percentile is shown when
     * known; an unknown severity has no label.
     */
    public function test_row_badge_percentile_and_severity_labels(): void {
        $red = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $none = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $html = $this->renderer()->render_flagged_list([
            $this->flagged((int) $red->id, severity::RED, calibration_window::PHASE_TENTATIVE, 4),
            $this->flagged((int) $none->id, severity::NONE),
        ], $this->context());

        $this->assertStringContainsString(get_string('severity_red', 'block_atrisk'), $html);
        $this->assertStringContainsString(get_string('badge_tentative', 'block_atrisk'), $html);
        $this->assertStringContainsString(
            get_string('percentile_label', 'block_atrisk', (object) ['rank' => 4]),
            $html
        );
        $this->assertStringContainsString('<span class="block_atrisk_severity_label"></span>', $html);
    }

    /**
     * A flagged user that no longer exists is skipped rather than rendered.
     */
    public function test_missing_user_is_skipped(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $html = $this->renderer()->render_flagged_list([
            $this->flagged(999999),
            $this->flagged((int) $student->id),
        ], $this->context());
        $this->assertSame(1, substr_count($html, 'block_atrisk_row '));
        $this->assertStringNotContainsString('id=999999', $html);
    }

    /**
     * The calibrating empty state has its own message.
     */
    public function test_calibrating_empty_state(): void {
        $html = $this->renderer()->render_flagged_list([], $this->context(['emptyreason' => 'calibrating']));
        $this->assertStringContainsString(get_string('empty_calibrating', 'block_atrisk'), $html);
    }

    /**
     * Without configureblock, the paused banner has no resume link.
     */
    public function test_paused_banner_without_configure(): void {
        $html = $this->renderer()->render_flagged_list([], $this->context([
            'activebreak' => ['start' => time() - DAYSECS, 'end' => time() + DAYSECS],
            'canconfigure' => false,
        ]));
        $this->assertStringContainsString('block_atrisk_paused', $html);
        $this->assertStringNotContainsString('action=resume', $html);
    }

    /**
     * Message and dismiss actions follow their capabilities; the outline link
     * is always present.
     */
    public function test_actions_follow_capabilities(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $context = \context_course::instance($this->course->id);
        assign_capability('block/atrisk:messagestudent', CAP_PROHIBIT, $roleid, $context->id);
        assign_capability('block/atrisk:dismissflag', CAP_PROHIBIT, $roleid, $context->id);

        $html = $this->renderer()->render_flagged_list([$this->flagged((int) $student->id)], $this->context());

        $this->assertStringContainsString(get_string('action_outline', 'block_atrisk'), $html);
        $this->assertStringNotContainsString(get_string('action_message', 'block_atrisk'), $html);
        $this->assertStringNotContainsString(get_string('action_dismiss', 'block_atrisk'), $html);
    }
}
