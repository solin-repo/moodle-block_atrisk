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
 * Block class for block_atrisk (Solin Early Warning).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Solin Early Warning block — entry point for course-view rendering.
 *
 * Surfaces a ranked list of students at risk of disengagement based on
 * multi-signal heuristics over data Moodle already collects.
 */
class block_atrisk extends block_base {
    /**
     * Initialise the block — sets the title from language strings.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_atrisk');
    }

    /**
     * Restrict the block to course-view contexts only (FR-03).
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'all' => false,
            'course-view' => true,
        ];
    }

    /**
     * Declare the plugin has site-level admin settings.
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Disallow multiple instances per course — FR-110 dismissal scope is
     * course-wide and would be ambiguous with multiple block instances.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Build the block's content. Runs the flag engine and returns the
     * rendered top-N list, or an appropriate empty state.
     *
     * @return stdClass
     */
    public function get_content() {
        global $COURSE, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isset($COURSE) || empty($COURSE->id) || $COURSE->id == SITEID) {
            return $this->content;
        }

        $context = context_course::instance($COURSE->id);
        if (!has_capability('block/atrisk:viewflags', $context)) {
            return $this->content;
        }

        // Resolve config: site defaults overridden by per-instance.
        $instanceconfig = $this->config ?? new stdClass();
        $preset = $instanceconfig->preset ?? 'default';
        $peerscope = $instanceconfig->peer_scope ?? 'course';

        // Per-instance topn override falls back to site default (FR-60).
        $sitedefault = (int) (get_config('block_atrisk', 'display_top_n') ?: 12);
        $topn = $sitedefault;
        if (isset($instanceconfig->topn) && (int) $instanceconfig->topn > 0) {
            $topn = (int) $instanceconfig->topn;
        }

        $canconfigure = has_capability('block/atrisk:configureblock', $context);
        $rendercontext = [
            'courseid' => (int) $COURSE->id,
            'blockinstanceid' => (int) ($this->instance->id ?? 0),
            'topn' => $topn,
            'preset' => $preset,
            'completionenabled' => !empty($COURSE->enablecompletion),
            'showpresetcontrol' => $canconfigure
                && (bool) get_config('block_atrisk', 'sensitivity_preset_enabled'),
            'canconfigure' => $canconfigure,
        ];

        // Group access — visibility filter and statistics scope (FR-56, FR-102).
        $groupaccess = \block_atrisk\local\group_access::for_user(
            (int) $COURSE->id,
            (int) $USER->id
        );
        if ($groupaccess['mode'] === \block_atrisk\local\group_access::MODE_NO_GROUPS) {
            // Teacher in no groups under SEPARATEGROUPS — empty list, distinct message.
            $rendercontext['emptyreason'] = 'nogroupassignment';
            $renderer = $this->page->get_renderer('block_atrisk');
            $this->content->text = $renderer->render_flagged_list([], $rendercontext);
            return $this->content;
        }

        $config = \block_atrisk\local\engine_config::build_for_preset(
            $preset,
            $instanceconfig,
            (int) $COURSE->id
        );
        if (
            $peerscope === 'group'
            && $groupaccess['mode'] === \block_atrisk\local\group_access::MODE_RESTRICTED
        ) {
            // Statistics scoped to the viewer's groups.
            $config['groupid'] = $groupaccess['groupids'];
        }

        // Surface the active break (if any) as an informational banner
        // alongside the flagged list — the list itself stays visible so
        // a teacher preparing for the post-break week can still see
        // pre-break flags. Dampening (FR-105d) keeps break time out of
        // the metric windows.
        $now = time();
        $rendercontext['activebreak'] = \block_atrisk\local\breaks::active_at(
            $config['breaks'] ?? [],
            $now
        );
        $rendercontext['breaksdampeneddays'] = $this->dampened_days($config['breaks'] ?? [], $now);

        $engine = new \block_atrisk\local\cached_flag_engine();
        $flagged = $engine->evaluate_course((int) $COURSE->id, $config);

        // Visibility filter when peer_scope=course but the viewer is
        // group-restricted: engine ran whole-course; trim the rendered
        // list to the viewer's groups.
        if (
            $groupaccess['mode'] === \block_atrisk\local\group_access::MODE_RESTRICTED
            && $peerscope === 'course'
        ) {
            $allowedids = array_flip(\block_atrisk\local\group_access::userids_in_groups(
                $groupaccess['groupids']
            ));
            $flagged = array_values(array_filter(
                $flagged,
                fn($f) => isset($allowedids[$f->userid])
            ));
        }

        $rendercontext['emptyreason'] = $this->detect_empty_reason((int) $COURSE->id, $flagged);

        $renderer = $this->page->get_renderer('block_atrisk');
        $this->content->text = $renderer->render_flagged_list($flagged, $rendercontext);
        return $this->content;
    }

    /**
     * Total days "dampened" by past break ranges within a relevant
     * lookback window. Used by the renderer to surface a transparency
     * note when past breaks are subtracting from current metrics.
     *
     * @param array<int,array{start:int,end:int}> $breaks Parsed ranges.
     * @param int $now
     * @return int Total dampened days (zero if none).
     */
    private function dampened_days(array $breaks, int $now): int {
        if (empty($breaks)) {
            return 0;
        }
        // 30 days back is a generous proxy for "any signal's lookback
        // window" — strict 21-day assessment-miss is the longest.
        $windowstart = $now - 30 * DAYSECS;
        return \block_atrisk\local\breaks::overlap_days($breaks, $windowstart, $now);
    }

    /**
     * Decide which empty-state message applies (used by the renderer).
     *
     * @param int $courseid
     * @param array $flagged
     * @return string One of 'none', 'noenrolments', 'noflagged'.
     */
    private function detect_empty_reason(int $courseid, array $flagged): string {
        if (!empty($flagged)) {
            return 'none';
        }
        $cohort = \block_atrisk\local\cohort::active($courseid);
        if (empty($cohort)) {
            return 'noenrolments';
        }
        return 'noflagged';
    }
}
