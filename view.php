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
 * Paginated "view all flagged students" page (FR-61).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$expandall = (bool) optional_param('expandall', 0, PARAM_BOOL);
$perpage = 25; // FR-61.

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($courseid);
require_capability('block/atrisk:viewflags', $context);

$pageurlparams = ['courseid' => $courseid];
if ($expandall) {
    $pageurlparams['expandall'] = 1;
}
$PAGE->set_url('/blocks/atrisk/view.php', $pageurlparams);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'block_atrisk'));
$PAGE->set_heading($course->fullname);

// Resolve the course's block_atrisk instance (one per course; FR-110) and
// its preset, so this page mirrors what the in-block view shows.
$blockinstanceid = 0;
$preset = 'default';
$instanceconfig = null;
$blockrow = $DB->get_record_sql(
    "SELECT bi.id, bi.configdata
       FROM {block_instances} bi
      WHERE bi.blockname = :name AND bi.parentcontextid = :ctxid",
    ['name' => 'atrisk', 'ctxid' => $context->id]
);
if ($blockrow) {
    $blockinstanceid = (int) $blockrow->id;
    if (!empty($blockrow->configdata)) {
        $decoded = unserialize(base64_decode($blockrow->configdata));
        if (is_object($decoded)) {
            $instanceconfig = $decoded;
            if (!empty($decoded->preset)) {
                $preset = $decoded->preset;
            }
        }
    }
}
$peerscope = (is_object($instanceconfig) && !empty($instanceconfig->peer_scope))
    ? $instanceconfig->peer_scope
    : 'course';

// Group access — visibility filter and statistics scope (FR-56, FR-102).
global $USER;
$groupaccess = \block_atrisk\local\group_access::for_user($courseid, (int) $USER->id);
$nogroups = $groupaccess['mode'] === \block_atrisk\local\group_access::MODE_NO_GROUPS;

$engineconfig = \block_atrisk\local\engine_config::build_for_preset($preset, $instanceconfig, $courseid);
if (
    !$nogroups
    && $peerscope === 'group'
    && $groupaccess['mode'] === \block_atrisk\local\group_access::MODE_RESTRICTED
) {
    $engineconfig['groupid'] = $groupaccess['groupids'];
}

$engine = new \block_atrisk\local\flag_engine();
if ($nogroups) {
    $flagged = [];
} else {
    $flagged = $engine->evaluate_course($courseid, $engineconfig);
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
}
$total = count($flagged);
$start = $page * $perpage;
$slice = array_slice($flagged, $start, $perpage);

// Toggle URL flips the expand-all state and resets pagination to keep the
// state sticky across both pagination AND the toggle itself (FR-65).
$toggleparams = ['courseid' => $courseid];
if (!$expandall) {
    $toggleparams['expandall'] = 1;
}
$expandallurl = new moodle_url('/blocks/atrisk/view.php', $toggleparams);

// Build a returnurl (this page, with current expand-all state) so the
// preset action redirects back here instead of to the course page.
$returnurlparams = ['courseid' => $courseid];
if ($expandall) {
    $returnurlparams['expandall'] = 1;
}
$returnurl = new moodle_url('/blocks/atrisk/view.php', $returnurlparams);

$canconfigure = has_capability('block/atrisk:configureblock', $context);
$showpresetcontrol = $blockinstanceid > 0
    && $canconfigure
    && (bool) get_config('block_atrisk', 'sensitivity_preset_enabled');

// Active break detection (FR-105) — paused banner short-circuits render.
$activebreak = \block_atrisk\local\breaks::active_at($engineconfig['breaks'] ?? [], time());
$dampenedays = 0;
if ($activebreak === null && !empty($engineconfig['breaks'])) {
    $now = time();
    $dampenedays = \block_atrisk\local\breaks::overlap_days(
        $engineconfig['breaks'],
        $now - 30 * DAYSECS,
        $now
    );
}

$rendercontext = [
    'courseid' => $courseid,
    'blockinstanceid' => $blockinstanceid,
    'topn' => $perpage,
    'preset' => $preset,
    'completionenabled' => !empty($course->enablecompletion),
    'showpresetcontrol' => $showpresetcontrol,
    'emptyreason' => $nogroups ? 'nogroupassignment' : ($total === 0 ? 'noflagged' : 'none'),
    'expandall' => $expandall,
    'expandallurl' => $expandallurl,
    'presetreturnurl' => $returnurl,
    'canconfigure' => $canconfigure,
    'activebreak' => $activebreak,
    'breaksdampeneddays' => $dampenedays,
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'block_atrisk'));
echo $PAGE->get_renderer('block_atrisk')->render_flagged_list($slice, $rendercontext);
// Pagination must preserve the expand-all state.
$pagingurlparams = ['courseid' => $courseid];
if ($expandall) {
    $pagingurlparams['expandall'] = 1;
}
echo $OUTPUT->paging_bar(
    $total,
    $page,
    $perpage,
    new moodle_url('/blocks/atrisk/view.php', $pagingurlparams)
);
echo $OUTPUT->footer();
