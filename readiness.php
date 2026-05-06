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
 * Admin page: Readiness data export.
 *
 * GET renders the form (intro + what's included / not included +
 * options + button). POST generates a JSON snapshot via
 * {@see \block_atrisk\local\readiness_report} and forces a download.
 *
 * Capability: block/atrisk:configuresite (default: manager).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('block_atrisk_readiness');
require_capability('block/atrisk:configuresite', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'download') {
    require_sesskey();
    $includenames = (bool) optional_param('includenames', 0, PARAM_BOOL);
    $allcourses = (bool) optional_param('allcourses', 0, PARAM_BOOL);

    \core_php_time_limit::raise(300);
    $report = (new \block_atrisk\local\readiness_report())->build($includenames, $allcourses);
    $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sitename = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $SITE->shortname)) ?: 'site';
    $filename = "block_atrisk-readiness-{$sitename}-" . date('Ymd-Hi') . '.json';
    send_file(
        $payload,
        $filename,
        0,
        0,
        true,
        true,
        'application/json',
        false,
        ['cacheability' => 'no-store']
    );
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('readiness:title', 'block_atrisk'));

echo $OUTPUT->box(get_string('readiness:intro', 'block_atrisk'));

echo html_writer::start_div('block_atrisk_readiness_lists');
echo html_writer::tag('h3', get_string('readiness:included_heading', 'block_atrisk'));
echo get_string('readiness:included_list', 'block_atrisk');
echo html_writer::tag('h3', get_string('readiness:notincluded_heading', 'block_atrisk'));
echo get_string('readiness:notincluded_list', 'block_atrisk');
echo html_writer::end_div();

$action = new moodle_url('/blocks/atrisk/readiness.php', ['action' => 'download']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $action->out(false)]);
echo html_writer::input_hidden_params(new moodle_url('', ['sesskey' => sesskey()]));

echo html_writer::start_div('block_atrisk_readiness_options');
echo html_writer::checkbox(
    'includenames',
    1,
    false,
    ' ' . get_string('readiness:option_includenames', 'block_atrisk'),
    ['id' => 'id_includenames']
);
echo html_writer::tag(
    'div',
    get_string('readiness:option_includenames_desc', 'block_atrisk'),
    ['class' => 'block_atrisk_readiness_option_desc']
);

echo html_writer::checkbox(
    'allcourses',
    1,
    false,
    ' ' . get_string('readiness:option_allcourses', 'block_atrisk'),
    ['id' => 'id_allcourses']
);
echo html_writer::tag(
    'div',
    get_string('readiness:option_allcourses_desc', 'block_atrisk'),
    ['class' => 'block_atrisk_readiness_option_desc']
);
echo html_writer::end_div();

echo html_writer::tag(
    'button',
    get_string('readiness:button', 'block_atrisk'),
    ['type' => 'submit', 'class' => 'btn btn-primary']
);
echo html_writer::end_tag('form');

echo html_writer::tag(
    'details',
    html_writer::tag('summary', get_string('readiness:typical_uses_heading', 'block_atrisk')) .
    get_string('readiness:typical_uses', 'block_atrisk'),
    ['class' => 'block_atrisk_readiness_typical_uses']
);

echo html_writer::tag(
    'p',
    get_string('readiness:footer_no_external', 'block_atrisk'),
    ['class' => 'block_atrisk_readiness_footer']
);

echo $OUTPUT->footer();
