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
 * Per-instance edit form for block_atrisk (Solin Early Warning).
 *
 * Lets a teacher override site-level defaults for a single course block:
 * - Visible row count (FR-60)
 * - Forum-silence signal on/off (FR-27 — some courses use forums as a
 *   participation channel, others don't)
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block instance configuration form.
 */
class block_atrisk_edit_form extends block_edit_form {
    /**
     * Add the per-instance configuration fields.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform): void {
        // Standard "Block settings" header, expanded by default. The
        // visible row count is always shown (it's a sizing concern that
        // teachers may want to tweak quickly when changing screens).
        // The other overrides are policy decisions that get configured
        // once and rarely revisited, so they're marked advanced and
        // hidden behind Moodle's "Show more..." link.
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // Visible row count override (always visible, not advanced).
        $sitetopn = (int) (get_config('block_atrisk', 'display_top_n') ?: 12);
        $mform->addElement(
            'text',
            'config_topn',
            get_string('config_topn', 'block_atrisk'),
            ['size' => 4]
        );
        $mform->setType('config_topn', PARAM_INT);
        $mform->addElement(
            'static',
            'config_topn_desc',
            '',
            get_string('config_topn_desc', 'block_atrisk', (object) ['sitedefault' => $sitetopn])
        );

        // Forum-silence signal override (tri-state: inherit site / force on / force off).
        $sitefssen = (bool) get_config('block_atrisk', 'signal_forum_silence_enabled');
        $sitelabel = $sitefssen
            ? get_string('config_forum_silence_inherit_on', 'block_atrisk')
            : get_string('config_forum_silence_inherit_off', 'block_atrisk');
        $mform->addElement(
            'select',
            'config_forum_silence_enabled',
            get_string('config_forum_silence_enabled', 'block_atrisk'),
            [
                'site' => $sitelabel,
                '1' => get_string('config_forum_silence_on', 'block_atrisk'),
                '0' => get_string('config_forum_silence_off', 'block_atrisk'),
            ]
        );
        $mform->setType('config_forum_silence_enabled', PARAM_ALPHANUM);
        $mform->setDefault('config_forum_silence_enabled', 'site');
        $mform->setAdvanced('config_forum_silence_enabled');
        $mform->addElement(
            'static',
            'config_forum_silence_enabled_desc',
            '',
            get_string('config_forum_silence_enabled_desc', 'block_atrisk')
        );
        $mform->setAdvanced('config_forum_silence_enabled_desc');

        // Peer-comparison scope (FR-102). Default: whole course.
        $mform->addElement(
            'select',
            'config_peer_scope',
            get_string('config_peer_scope', 'block_atrisk'),
            [
                'course' => get_string('config_peer_scope_course', 'block_atrisk'),
                'group' => get_string('config_peer_scope_group', 'block_atrisk'),
            ]
        );
        $mform->setType('config_peer_scope', PARAM_ALPHA);
        $mform->setDefault('config_peer_scope', 'course');
        $mform->setAdvanced('config_peer_scope');
        $mform->addElement(
            'static',
            'config_peer_scope_desc',
            '',
            get_string('config_peer_scope_desc', 'block_atrisk')
        );
        $mform->setAdvanced('config_peer_scope_desc');

        // Course-shape override (FR-106e). Tri-state: Auto-detect (default) /
        // Force-set as <shape> / Force-disable per-shape adjustments. The
        // detected shape and confidence are surfaced as static help text so
        // the teacher can verify the detection before deciding to override
        // (FR-106f).
        $shapeoptions = [
            'auto' => get_string('config_course_shape_override_auto', 'block_atrisk'),
            'disable' => get_string('config_course_shape_override_disable', 'block_atrisk'),
            \block_atrisk\local\course_shape::SHAPE_COHORT_PACED =>
                get_string('shape_cohort_paced', 'block_atrisk'),
            \block_atrisk\local\course_shape::SHAPE_SELF_PACED =>
                get_string('shape_self_paced', 'block_atrisk'),
            \block_atrisk\local\course_shape::SHAPE_ONE_SHOT_COMPLIANCE =>
                get_string('shape_one_shot_compliance', 'block_atrisk'),
            \block_atrisk\local\course_shape::SHAPE_BLENDED =>
                get_string('shape_blended', 'block_atrisk'),
        ];
        $mform->addElement(
            'select',
            'config_course_shape_override',
            get_string('config_course_shape_override', 'block_atrisk'),
            $shapeoptions
        );
        $mform->setType('config_course_shape_override', PARAM_ALPHANUMEXT);
        $mform->setDefault('config_course_shape_override', 'auto');
        $mform->setAdvanced('config_course_shape_override');
        // Show the detected shape/confidence so the teacher can decide whether
        // to override (FR-106f).
        $detected = null;
        if (!empty($this->block) && !empty($this->block->instance->parentcontextid)) {
            $parent = \context::instance_by_id($this->block->instance->parentcontextid, IGNORE_MISSING);
            if ($parent && $parent instanceof \context_course) {
                $detected = \block_atrisk\local\course_shape::load($parent->instanceid);
            }
        }
        if ($detected !== null) {
            $detail = (object) [
                'shape' => get_string('shape_' . $detected->shape, 'block_atrisk'),
                'confidence' => get_string('confidence_' . $detected->confidence, 'block_atrisk'),
            ];
            $detectedtext = get_string('config_course_shape_detected', 'block_atrisk', $detail);
        } else {
            $detectedtext = get_string('config_course_shape_not_detected', 'block_atrisk');
        }
        $mform->addElement(
            'static',
            'config_course_shape_override_desc',
            '',
            $detectedtext . '<br>' . get_string('config_course_shape_override_desc', 'block_atrisk')
        );
        $mform->setAdvanced('config_course_shape_override_desc');

        // Course-specific break ranges, additive to the site-level
        // breaks_calendar (FR-105 v1).
        $mform->addElement(
            'textarea',
            'config_course_breaks',
            get_string('config_course_breaks', 'block_atrisk'),
            ['rows' => 4, 'cols' => 40, 'style' => 'font-family: monospace;']
        );
        $mform->setType('config_course_breaks', PARAM_RAW);
        $mform->addHelpButton('config_course_breaks', 'config_course_breaks', 'block_atrisk');
        $mform->setAdvanced('config_course_breaks');
        $mform->addElement(
            'static',
            'config_course_breaks_desc',
            '',
            get_string('config_course_breaks_desc', 'block_atrisk')
        );
        $mform->setAdvanced('config_course_breaks_desc');
    }

    /**
     * Server-side validation for per-instance fields.
     *
     * @param array $data
     * @param array $files
     * @return array Field-keyed error messages.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['config_course_breaks'])) {
            $msg = \block_atrisk\local\breaks::validate((string) $data['config_course_breaks']);
            if ($msg !== null) {
                $errors['config_course_breaks'] = $msg;
            }
        }
        return $errors;
    }
}
