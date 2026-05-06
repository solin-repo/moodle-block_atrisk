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
 * Dismiss-flag-for-one-week endpoint.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$action = optional_param('action', 'dismiss', PARAM_ALPHA);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
require_sesskey();

$context = context_course::instance($courseid);
require_capability('block/atrisk:dismissflag', $context);

global $USER;
if ($action === 'undo') {
    \block_atrisk\local\dismissal_service::undo($courseid, $userid);
} else {
    // Capture the flag state at click time for recalibration analysis
    // (FR-72d). One extra engine pass per click — small cost; the flag
    // engine is cached so this typically hits the existing entry.
    $signalsnapshot = null;
    $blockrow = $DB->get_record_sql(
        "SELECT bi.configdata
           FROM {block_instances} bi
          WHERE bi.blockname = 'atrisk' AND bi.parentcontextid = :ctxid",
        ['ctxid' => $context->id]
    );
    $instanceconfig = new stdClass();
    if ($blockrow !== false && !empty($blockrow->configdata)) {
        $decoded = unserialize(base64_decode($blockrow->configdata));
        if (is_object($decoded)) {
            $instanceconfig = $decoded;
        }
    }
    $preset = $instanceconfig->preset ?? 'default';
    $engineconfig = \block_atrisk\local\engine_config::build_for_preset($preset, $instanceconfig, $courseid);
    $engine = new \block_atrisk\local\cached_flag_engine();
    $flagged = $engine->evaluate_course($courseid, $engineconfig);
    foreach ($flagged as $f) {
        if ($f->userid === $userid) {
            $signalsnapshot = [
                'signals' => array_keys($f->triggered),
                'severity' => $f->severity,
                'preset' => $preset,
            ];
            break;
        }
    }
    \block_atrisk\local\dismissal_service::dismiss(
        $courseid,
        $userid,
        $USER->id,
        null,
        $signalsnapshot
    );
}
\block_atrisk\local\cached_flag_engine::invalidate_course($courseid);

redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
