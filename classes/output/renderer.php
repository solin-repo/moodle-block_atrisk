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

use block_atrisk\local\calibration_window;
use block_atrisk\local\flagged_student;
use block_atrisk\local\severity;

/**
 * Plain-PHP renderer for the at-risk block. No external JS framework;
 * the dismiss/preset actions degrade to standard form posts when JS is
 * disabled (FR-172). All output passes through Moodle's escaping helpers
 * per FR-203.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the entire flagged list area.
     *
     * @param flagged_student[] $flagged Sorted list (engine output).
     * @param array $context Render context: courseid, blockinstanceid,
     *               topn (visible row count), preset, completionenabled,
     *               showpresetcontrol, dismissedstudentnames.
     */
    public function render_flagged_list(array $flagged, array $context): string {
        global $DB;

        $courseid = (int) $context['courseid'];
        $blockinstanceid = (int) ($context['blockinstanceid'] ?? 0);
        $topn = (int) ($context['topn'] ?? 5);
        $showpreset = !empty($context['showpresetcontrol']);
        $preset = $context['preset'] ?? 'default';
        $completionoff = empty($context['completionenabled']);
        $expandall = !empty($context['expandall']);
        // When the caller (view.php) provides an expand-all URL, we render
        // the toggle as a real link that reloads with the param flipped.
        // When absent (in-block render), JS handles the toggle ephemerally.
        $expandallurl = $context['expandallurl'] ?? null;
        $rootid = 'block_atrisk_root_' . ($blockinstanceid > 0
            ? $blockinstanceid
            : 'view_' . $courseid);

        $html = \html_writer::start_div('block_atrisk', [
            'id' => $rootid,
            'data-blockinstanceid' => $blockinstanceid,
        ]);

        if ($showpreset && $blockinstanceid > 0) {
            $html .= $this->render_preset_control(
                $blockinstanceid,
                $preset,
                $courseid,
                $context['presetreturnurl'] ?? null
            );
        }
        if (!empty($context['canconfigure']) && $blockinstanceid > 0 && empty($context['activebreak'])) {
            $html .= $this->render_pause_link(
                $blockinstanceid,
                $courseid,
                $context['presetreturnurl'] ?? null
            );
        }
        if ($completionoff) {
            $html .= $this->render_completion_banner();
        }
        // If a configured break currently includes "now", show an
        // informational banner above the list (FR-105). The list itself
        // still renders — pre-break data remains useful for teachers
        // preparing for resumption, with break time correctly excluded
        // from the calculations.
        if (!empty($context['activebreak'])) {
            $html .= $this->render_pause_banner(
                $context['activebreak'],
                $blockinstanceid,
                $context['canconfigure'] ?? false
            );
        } else if (!empty($context['breaksdampeneddays'])) {
            $html .= $this->render_dampening_note((int) $context['breaksdampeneddays']);
        }

        if (empty($flagged)) {
            $html .= $this->render_empty_state($context);
            $html .= \html_writer::end_div();
            return $html;
        }

        // Top-N truncated row list. Remainder behind a "view all" link.
        $visible = array_slice($flagged, 0, $topn);
        $totalcount = count($flagged);

        // Look up display-friendly user names for the visible set.
        // We select the standard name fields directly rather than via
        // {@see \core_user\fields} to keep the SQL simple — fullname()
        // tolerates extra/missing optional fields.
        $userids = array_map(fn($f) => $f->userid, $visible);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $users = $DB->get_records_select(
            'user',
            "id {$insql}",
            $inparams,
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, ' .
            'middlename, alternatename'
        );

        $html .= $this->render_expand_toggle($expandall, $expandallurl);

        $html .= \html_writer::start_tag('ul', ['class' => 'block_atrisk_list']);
        foreach ($visible as $f) {
            $user = $users[$f->userid] ?? null;
            if ($user === null) {
                continue;
            }
            $html .= $this->render_row($f, $user, $courseid, $expandall);
        }
        $html .= \html_writer::end_tag('ul');

        if ($totalcount > $topn) {
            $url = new \moodle_url('/blocks/atrisk/view.php', ['courseid' => $courseid]);
            $html .= \html_writer::div(
                \html_writer::link($url, get_string(
                    'viewallflagged',
                    'block_atrisk',
                    (object) ['count' => $totalcount]
                )),
                'block_atrisk_view_all'
            );
        }
        $html .= \html_writer::end_div();

        // Wire up the JS toggle for the in-block (no expandallurl) variant.
        if ($expandallurl === null) {
            $this->page->requires->js_call_amd(
                'block_atrisk/expand_all',
                'init',
                [
                    '#' . $rootid,
                    get_string('action_expand_all', 'block_atrisk'),
                    get_string('action_collapse_all', 'block_atrisk'),
                ]
            );
        }
        return $html;
    }

    /**
     * Render the Expand all / Collapse all control above the row list.
     *
     * If $expandallurl is null, the link is rendered as a JS-driven toggle
     * (in-block view). Otherwise, the link points at $expandallurl with
     * the expand-all state flipped — the URL form is used by view.php so
     * the choice survives pagination (FR-65).
     *
     * @param bool $expandall
     * @param \moodle_url|null $expandallurl
     * @return string HTML.
     */
    private function render_expand_toggle(bool $expandall, ?\moodle_url $expandallurl): string {
        $label = $expandall
            ? get_string('action_collapse_all', 'block_atrisk')
            : get_string('action_expand_all', 'block_atrisk');
        $attrs = [
            'class' => 'block_atrisk_expand_toggle',
            'role' => 'button',
            'data-state' => $expandall ? 'expanded' : 'collapsed',
        ];
        if ($expandallurl === null) {
            $attrs['href'] = '#';
            $link = \html_writer::link('#', $label, $attrs);
        } else {
            $link = \html_writer::link($expandallurl, $label, $attrs);
        }
        return \html_writer::div($link, 'block_atrisk_expand_toggle_wrap');
    }

    /**
     * Render the "Heuristics paused for break" banner with a "Resume now"
     * link for users with block/atrisk:configureblock.
     *
     * @param array $activebreak Currently active break range.
     * @param int $blockinstanceid Block instance ID.
     * @param bool $canconfigure Whether the viewer can configure the block.
     * @return string HTML.
     */
    private function render_pause_banner(array $activebreak, int $blockinstanceid, bool $canconfigure): string {
        $datestr = userdate($activebreak['end'], get_string('strftimedate', 'core_langconfig'));
        $body = get_string('paused_until', 'block_atrisk', (object) ['date' => $datestr]);
        if ($canconfigure && $blockinstanceid > 0) {
            $resumeurl = new \moodle_url('/blocks/atrisk/pause.php', [
                'blockinstanceid' => $blockinstanceid,
                'action' => 'resume',
                'sesskey' => sesskey(),
            ]);
            $tooltip = get_string('action_resume_tooltip', 'block_atrisk');
            $body .= ' ' . \html_writer::link(
                $resumeurl,
                get_string('action_resume', 'block_atrisk'),
                ['class' => 'block_atrisk_resume', 'title' => $tooltip, 'aria-label' => $tooltip]
            );
        }
        return \html_writer::div($body, 'block_atrisk_paused alert alert-info');
    }

    /**
     * Render a small "Pause for one week" inline link for users with
     * configureblock capability.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param int $courseid Course ID.
     * @param \moodle_url|null $returnurl URL to return to after the action.
     * @return string HTML.
     */
    private function render_pause_link(int $blockinstanceid, int $courseid, ?\moodle_url $returnurl): string {
        $params = [
            'blockinstanceid' => $blockinstanceid,
            'action' => 'pause',
            'sesskey' => sesskey(),
        ];
        if ($returnurl !== null) {
            $params['returnurl'] = $returnurl->out_as_local_url(false);
        }
        $url = new \moodle_url('/blocks/atrisk/pause.php', $params);
        $tooltip = get_string('action_pause_week_tooltip', 'block_atrisk');
        return \html_writer::div(
            \html_writer::link(
                $url,
                get_string('action_pause_week', 'block_atrisk'),
                ['class' => 'block_atrisk_pause_link', 'title' => $tooltip, 'aria-label' => $tooltip]
            ),
            'block_atrisk_pause_link_wrap'
        );
    }

    /**
     * Render a one-line note when past breaks have dampened the metrics
     * (transparency for teachers wondering why fewer students are flagged).
     *
     * @param int $totaldays Total days of dampening across past breaks.
     * @return string HTML.
     */
    private function render_dampening_note(int $totaldays): string {
        $body = get_string('breaks_dampened', 'block_atrisk', (object) ['days' => $totaldays]);
        return \html_writer::div($body, 'block_atrisk_breaks_note');
    }

    /**
     * Render the inline Loose / Balanced / Strict preset control.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param string $preset Currently-selected preset.
     * @param int $courseid Course ID.
     * @param \moodle_url|null $returnurl URL to return to after the action.
     * @return string HTML.
     */
    private function render_preset_control(
        int $blockinstanceid,
        string $preset,
        int $courseid,
        ?\moodle_url $returnurl = null
    ): string {
        $html = \html_writer::start_div('block_atrisk_preset');
        $html .= \html_writer::tag(
            'span',
            get_string('sensitivity_label', 'block_atrisk') . ': ',
            ['class' => 'block_atrisk_preset_label']
        );
        // Treat legacy preset values stored in instance config as their
        // current equivalents so the active button still highlights.
        $aliases = ['loose' => 'more', 'balanced' => 'default', 'strict' => 'fewer'];
        $activepreset = $aliases[$preset] ?? $preset;

        foreach (['more', 'default', 'fewer'] as $option) {
            $params = [
                'blockinstanceid' => $blockinstanceid,
                'preset' => $option,
                'sesskey' => sesskey(),
            ];
            if ($returnurl !== null) {
                $params['returnurl'] = $returnurl->out_as_local_url(false);
            }
            $url = new \moodle_url('/blocks/atrisk/preset.php', $params);
            $classes = 'block_atrisk_preset_btn';
            if ($option === $activepreset) {
                $classes .= ' block_atrisk_preset_btn_active';
            }
            $tooltip = get_string('preset_' . $option . '_tooltip', 'block_atrisk');
            $html .= \html_writer::link(
                $url,
                get_string('preset_' . $option, 'block_atrisk'),
                ['class' => $classes, 'title' => $tooltip, 'aria-label' => $tooltip]
            );
        }
        $html .= \html_writer::end_div();
        return $html;
    }

    /**
     * Render the "completion tracking is off" banner per FR-63a.
     *
     * @return string HTML.
     */
    private function render_completion_banner(): string {
        return \html_writer::div(
            get_string('banner_completion_off', 'block_atrisk'),
            'block_atrisk_banner alert alert-info'
        );
    }

    /**
     * Render one of the three mutually-exclusive empty states (FR-63).
     *
     * @param array $context Render context (uses 'emptyreason').
     * @return string HTML.
     */
    private function render_empty_state(array $context): string {
        // Mutually-exclusive states (FR-63), in priority order.
        $reason = $context['emptyreason'] ?? 'none';
        $key = match ($reason) {
            'nogroupassignment' => 'empty_no_group_assignment',
            'noenrolments' => 'empty_no_enrolments',
            'calibrating' => 'empty_calibrating',
            default => 'empty_none_flagged',
        };
        return \html_writer::div(
            get_string($key, 'block_atrisk'),
            'block_atrisk_empty'
        );
    }

    /**
     * Render one flagged-student row.
     *
     * @param flagged_student $f Flagged-student outcome.
     * @param \stdClass $user User record (must include name fields).
     * @param int $courseid Course ID.
     * @param bool $expandall Whether to render the row pre-expanded.
     * @return string HTML.
     */
    private function render_row(flagged_student $f, \stdClass $user, int $courseid, bool $expandall = false): string {
        $namelink = \html_writer::link(
            new \moodle_url('/user/view.php', ['id' => $f->userid, 'course' => $courseid]),
            fullname($user)
        );

        $sevclass = 'block_atrisk_severity_' . $f->severity;
        $sevlabel = match ($f->severity) {
            severity::RED => get_string('severity_red', 'block_atrisk'),
            severity::YELLOW => get_string('severity_yellow', 'block_atrisk'),
            default => '',
        };

        // Summary (collapsed first line) — severity, name, tentative badge.
        $summary = \html_writer::tag(
            'span',
            s($sevlabel),
            ['class' => 'block_atrisk_severity_label']
        ) . ' ' . $namelink;
        if ($f->phase === calibration_window::PHASE_TENTATIVE) {
            $summary .= ' ' . \html_writer::tag(
                'span',
                get_string('badge_tentative', 'block_atrisk'),
                ['class' => 'block_atrisk_badge_tentative']
            );
        }

        // Body (revealed on expand) — signal explanations, percentile, actions.
        $body = \html_writer::start_tag('ul', ['class' => 'block_atrisk_signals']);
        foreach ($f->triggered as $r) {
            $body .= \html_writer::tag('li', s($r->explanation));
        }
        $body .= \html_writer::end_tag('ul');

        if ($f->worstpercentile !== null) {
            $body .= \html_writer::tag(
                'span',
                get_string(
                    'percentile_label',
                    'block_atrisk',
                    (object) ['rank' => $f->worstpercentile]
                ),
                ['class' => 'block_atrisk_percentile']
            );
        }

        $body .= $this->render_actions($f, $courseid);

        $detailsattrs = ['class' => 'block_atrisk_details'];
        if ($expandall) {
            $detailsattrs['open'] = 'open';
        }

        $row = \html_writer::start_tag('li', ['class' => 'block_atrisk_row ' . $sevclass]);
        $row .= \html_writer::start_tag('details', $detailsattrs);
        $row .= \html_writer::tag('summary', $summary, ['class' => 'block_atrisk_summary']);
        $row .= \html_writer::div($body, 'block_atrisk_body');
        $row .= \html_writer::end_tag('details');
        $row .= \html_writer::end_tag('li');
        return $row;
    }

    /**
     * Render the per-row action links (message, outline, dismiss) gated
     * by capabilities.
     *
     * @param flagged_student $f
     * @param int $courseid
     * @return string HTML (empty when no actions are available).
     */
    private function render_actions(flagged_student $f, int $courseid): string {
        $context = \context_course::instance($courseid);
        $actions = [];
        if (has_capability('block/atrisk:messagestudent', $context)) {
            $actions[] = \html_writer::link(
                new \moodle_url('/message/index.php', ['id' => $f->userid]),
                get_string('action_message', 'block_atrisk'),
                ['class' => 'block_atrisk_action']
            );
        }
        $actions[] = \html_writer::link(
            new \moodle_url('/course/user.php', [
                'id' => $courseid, 'user' => $f->userid, 'mode' => 'outline',
            ]),
            get_string('action_outline', 'block_atrisk'),
            ['class' => 'block_atrisk_action']
        );
        if (has_capability('block/atrisk:dismissflag', $context)) {
            $actions[] = \html_writer::link(
                new \moodle_url('/blocks/atrisk/dismiss.php', [
                    'courseid' => $courseid,
                    'userid' => $f->userid,
                    'sesskey' => sesskey(),
                ]),
                get_string('action_dismiss', 'block_atrisk'),
                ['class' => 'block_atrisk_action']
            );
        }
        if (empty($actions)) {
            return '';
        }
        return \html_writer::div(implode(' · ', $actions), 'block_atrisk_actions');
    }
}
