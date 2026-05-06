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
 * Sensitivity-preset write endpoint (FR-80–82).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$blockinstanceid = required_param('blockinstanceid', PARAM_INT);
$preset = required_param('preset', PARAM_ALPHA);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

// Accept both the current (more/default/fewer) and legacy (loose/balanced/strict)
// preset keys; the engine config layer aliases the legacy keys.
if (!in_array($preset, ['more', 'default', 'fewer', 'loose', 'balanced', 'strict'], true)) {
    throw new \moodle_exception('invalidpreset', 'block_atrisk');
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
$config->preset = $preset;

$DB->set_field(
    'block_instances',
    'configdata',
    base64_encode(serialize($config)),
    ['id' => $blockinstanceid]
);
// No cache invalidation here: each preset hashes into its own cache key
// (signalconfig_hash), so switching to a previously-used preset within
// the cache TTL is a hit rather than a recompute. Invalidation is the
// dismiss path's responsibility (where the underlying data changes).

if ($returnurl !== '') {
    redirect(new moodle_url($returnurl));
}
redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
