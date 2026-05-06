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
 * Inline pause / resume action for the at-risk block.
 *
 * action=pause adds a one-week break (today through today+6d) to the
 * per-instance course_breaks field. action=resume removes any per-
 * instance ranges that currently include "now" — site-level ranges are
 * untouched (those need an admin to edit settings).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$blockinstanceid = required_param('blockinstanceid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

if (!in_array($action, ['pause', 'resume'], true)) {
    throw new \moodle_exception('invalidaction');
}

$instance = $DB->get_record('block_instances', ['id' => $blockinstanceid], '*', MUST_EXIST);
$blockcontext = context_block::instance($blockinstanceid);
$parentcontext = $blockcontext->get_parent_context();
if ($parentcontext->contextlevel !== CONTEXT_COURSE) {
    throw new \moodle_exception('invalidcontext');
}
$courseid = $parentcontext->instanceid;
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
require_sesskey();
require_capability('block/atrisk:configureblock', $blockcontext);

$config = new stdClass();
if (!empty($instance->configdata)) {
    $config = unserialize(base64_decode($instance->configdata)) ?: new stdClass();
}
$existing = (string) ($config->course_breaks ?? '');

if ($action === 'pause') {
    // Append a 1-week range to today's-line block of breaks.
    $today = date('Y-m-d');
    $end = date('Y-m-d', time() + 6 * DAYSECS);
    $newline = "{$today}, {$end}";
    $config->course_breaks = $existing === '' ? $newline : rtrim($existing) . "\n" . $newline;
} else {
    // Remove any per-instance ranges containing now.
    $now = time();
    $kept = [];
    foreach (preg_split('/\R/', $existing) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            $kept[] = $line;
            continue;
        }
        try {
            $parsed = \block_atrisk\local\breaks::parse($line);
            $active = !empty($parsed) && \block_atrisk\local\breaks::active_at($parsed, $now) !== null;
            if (!$active) {
                $kept[] = $line;
            }
        } catch (\invalid_parameter_exception $e) {
            // Preserve unparseable lines verbatim — don't silently delete.
            $kept[] = $line;
        }
    }
    $config->course_breaks = implode("\n", $kept);
}

$DB->set_field(
    'block_instances',
    'configdata',
    base64_encode(serialize($config)),
    ['id' => $blockinstanceid]
);
\block_atrisk\local\cached_flag_engine::invalidate_course($courseid);

if ($returnurl !== '') {
    redirect(new moodle_url($returnurl));
}
redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
