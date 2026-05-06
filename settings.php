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
 * Site administration settings for block_atrisk (Solin Early Warning).
 *
 * Maps to FR-90 to FR-96 of the functional spec.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Per-signal enable/threshold.
    $settings->add(new admin_setting_heading(
        'block_atrisk/signals_heading',
        get_string('settings_signals_heading', 'block_atrisk'),
        get_string('settings_signals_heading_desc', 'block_atrisk')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/signal_inactivity_enabled',
        get_string('settings_signal_inactivity_enabled', 'block_atrisk'),
        get_string('settings_signal_inactivity_enabled_desc', 'block_atrisk'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/signal_inactivity_days',
        get_string('settings_signal_inactivity_days', 'block_atrisk'),
        get_string('settings_signal_inactivity_days_desc', 'block_atrisk'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/signal_assessment_miss_enabled',
        get_string('settings_signal_assessment_miss_enabled', 'block_atrisk'),
        get_string('settings_signal_assessment_miss_enabled_desc', 'block_atrisk'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/signal_assessment_miss_days',
        get_string('settings_signal_assessment_miss_days', 'block_atrisk'),
        get_string('settings_signal_assessment_miss_days_desc', 'block_atrisk'),
        14,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/signal_grade_trend_enabled',
        get_string('settings_signal_grade_trend_enabled', 'block_atrisk'),
        get_string('settings_signal_grade_trend_enabled_desc', 'block_atrisk'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/signal_stalled_completion_enabled',
        get_string('settings_signal_stalled_completion_enabled', 'block_atrisk'),
        get_string('settings_signal_stalled_completion_enabled_desc', 'block_atrisk'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/signal_forum_silence_enabled',
        get_string('settings_signal_forum_silence_enabled', 'block_atrisk'),
        get_string('settings_signal_forum_silence_enabled_desc', 'block_atrisk'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/signal_forum_silence_days',
        get_string('settings_signal_forum_silence_days', 'block_atrisk'),
        get_string('settings_signal_forum_silence_days_desc', 'block_atrisk'),
        14,
        PARAM_INT
    ));

    // Cohort floors.
    $settings->add(new admin_setting_heading(
        'block_atrisk/cohort_heading',
        get_string('settings_cohort_heading', 'block_atrisk'),
        get_string('settings_cohort_heading_desc', 'block_atrisk')
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/cohort_hard_floor',
        get_string('settings_cohort_hard_floor', 'block_atrisk'),
        get_string('settings_cohort_hard_floor_desc', 'block_atrisk'),
        10,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/cohort_soft_floor',
        get_string('settings_cohort_soft_floor', 'block_atrisk'),
        get_string('settings_cohort_soft_floor_desc', 'block_atrisk'),
        20,
        PARAM_INT
    ));

    // Display.
    $settings->add(new admin_setting_heading(
        'block_atrisk/display_heading',
        get_string('settings_display_heading', 'block_atrisk'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/display_top_n',
        get_string('settings_display_top_n', 'block_atrisk'),
        get_string('settings_display_top_n_desc', 'block_atrisk'),
        12,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/sensitivity_preset_enabled',
        get_string('settings_sensitivity_preset_enabled', 'block_atrisk'),
        get_string('settings_sensitivity_preset_enabled_desc', 'block_atrisk'),
        1
    ));

    // Breaks calendar (institutional holidays / term breaks).
    $settings->add(new admin_setting_heading(
        'block_atrisk/breaks_heading',
        get_string('settings_breaks_heading', 'block_atrisk'),
        get_string('settings_breaks_heading_desc', 'block_atrisk')
    ));
    $settings->add(new \block_atrisk\admin\setting_breaks_calendar(
        'block_atrisk/breaks_calendar',
        get_string('settings_breaks_calendar', 'block_atrisk'),
        get_string('settings_breaks_calendar_desc', 'block_atrisk'),
        '',
        PARAM_RAW,
        60,
        8
    ));

    // Recalibration data — flag-history snapshots + dismissal signal capture.
    $settings->add(new admin_setting_heading(
        'block_atrisk/calibration_heading',
        get_string('settings_calibration_heading', 'block_atrisk'),
        get_string('settings_calibration_heading_desc', 'block_atrisk')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'block_atrisk/flag_logging_enabled',
        get_string('settings_flag_logging_enabled', 'block_atrisk'),
        get_string('settings_flag_logging_enabled_desc', 'block_atrisk'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/flag_log_retention_days',
        get_string('settings_flag_log_retention_days', 'block_atrisk'),
        get_string('settings_flag_log_retention_days_desc', 'block_atrisk'),
        365,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'block_atrisk/dismissal_log_retention_days',
        get_string('settings_dismissal_log_retention_days', 'block_atrisk'),
        get_string('settings_dismissal_log_retention_days_desc', 'block_atrisk'),
        365,
        PARAM_INT
    ));

    // Commercial extensions and support — informational only. No feature
    // gating; all plugin functionality is GPL and unrestricted.
    $settings->add(new admin_setting_heading(
        'block_atrisk/commercial_heading',
        get_string('settings_commercial_heading', 'block_atrisk'),
        get_string('settings_commercial_heading_desc', 'block_atrisk')
    ));
}

// Readiness data export — admin externalpage. Registered unconditionally
// (outside $ADMIN->fulltree) so it appears in the admin tree as a sibling
// of the settings page.
$ADMIN->add('blocksettings', new admin_externalpage(
    'block_atrisk_readiness',
    get_string('readiness:menu', 'block_atrisk'),
    new moodle_url('/blocks/atrisk/readiness.php'),
    'block/atrisk:configuresite'
));
