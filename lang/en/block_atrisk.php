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
 * Language strings for block_atrisk (Solin Early Warning), en.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_collapse_all'] = 'Collapse all';
$string['action_dismiss'] = 'Dismiss for one week';
$string['action_expand_all'] = 'Expand all';
$string['action_message'] = 'Send message';
$string['action_outline'] = 'View outline';
$string['action_pause_week'] = 'Pause for one week';
$string['action_pause_week_tooltip'] = 'Adds a one-week break (today through six days from now) to this block\'s break list. Inactivity, missed assessments, and low forum activity that fall in that range won\'t flag students. Useful for an unplanned closure (snow day, fire drill, instructor unwell) or to suppress false positives over a short break that isn\'t in the institutional calendar.';
$string['action_resume'] = 'Resume now';
$string['action_resume_tooltip'] = 'Removes any per-block break range that currently includes today, ending the dampening for this block. Site-level break ranges are not affected — those need an admin to edit at Site administration → Plugins → Blocks → Solin Early Warning.';
$string['action_undismiss'] = 'Undo';
$string['atrisk:addinstance'] = 'Add a new Solin Early Warning block';
$string['atrisk:configureblock'] = 'Configure per-block-instance thresholds and sensitivity preset';
$string['atrisk:configuresite'] = 'Configure site-wide defaults';
$string['atrisk:dismissflag'] = 'Dismiss a student flag for one week';
$string['atrisk:messagestudent'] = 'Send a message to a flagged student from the block';
$string['atrisk:viewflags'] = 'View the flagged-student list';
$string['badge_tentative'] = 'Tentative';
$string['banner_completion_off'] = 'Activity completion tracking is off in this course. The assessment-miss and stalled-completion signals are unavailable until a teacher enables completion tracking under Course settings → Completion tracking. The other three signals continue to run.';
$string['breaks_dampened'] = 'Break dampening: {$a->days} day(s) of recent break time excluded from this metric window.';
$string['cachedef_rawsignals'] = 'Per-student raw signal values (lower-layer cache, group-agnostic)';
$string['confidence_high'] = 'high confidence';
$string['confidence_low'] = 'low confidence';
$string['confidence_medium'] = 'medium confidence';
$string['config_course_breaks'] = 'Course-specific breaks';
$string['config_course_breaks_desc'] = 'Optional. Additive to the site-wide breaks calendar. One range per line, ISO format <code>YYYY-MM-DD, YYYY-MM-DD</code>. Use this for course-specific pauses (e.g., the instructor was sick that week, a local event closed the school, etc.). The "Pause for one week" link in the block adds a range here.';
$string['config_course_breaks_help'] = 'Course-specific break date ranges, additive to the site-wide breaks calendar.

**Format**: one break per line, ISO 8601 dates separated by a comma:

YYYY-MM-DD, YYYY-MM-DD

Lines starting with the hash character are comments and ignored. Both endpoints are inclusive (full local-time days).

**What breaks do**:

* During a break (now is between start and end), the block hides the flagged list and shows a paused banner.
* After a break, the engine subtracts the break\'s overlap from each signal\'s time window: students aren\'t flagged for inactivity, missed assessments, or low forum activity over a holiday. The dampening is automatic; there is nothing else to configure.

**Use this field for**:

* A school-closure week not in the institutional calendar.
* The instructor was unavailable (illness, conference) for a stretch.
* A retroactive fix after returning from a break and finding everyone flagged.

For institution-wide academic-year holidays, prefer the site-level **Breaks calendar** (Site administration → Plugins → Blocks → Solin Early Warning).

**Quick action**: the "Pause for one week" link in the block adds a range to this field automatically.';
$string['config_course_shape_detected'] = 'Detected shape: <strong>{$a->shape}</strong> ({$a->confidence}).';
$string['config_course_shape_not_detected'] = 'Detected shape: not yet computed. The daily task will populate this on its next run.';
$string['config_course_shape_override'] = 'Course shape';
$string['config_course_shape_override_auto'] = 'Auto-detect (default)';
$string['config_course_shape_override_desc'] = 'The plugin classifies each course into one of cohort-paced, self-paced, one-shot compliance, blended, or unknown, and tunes signal sensitivity accordingly. Use this field to force a specific shape (when auto-detection is wrong) or to disable shape-based adjustments entirely (back to raw thresholds).';
$string['config_course_shape_override_disable'] = 'Disable per-shape adjustments';
$string['config_forum_silence_enabled'] = 'Forum-silence signal';
$string['config_forum_silence_enabled_desc'] = 'Override the site-level forum-silence setting for this block. Use "Force on" if this course relies on the forum as a participation channel; use "Force off" if forum activity is optional or irrelevant here.';
$string['config_forum_silence_inherit_off'] = 'Inherit site default (currently: off)';
$string['config_forum_silence_inherit_on'] = 'Inherit site default (currently: on)';
$string['config_forum_silence_off'] = 'Force off';
$string['config_forum_silence_on'] = 'Force on';
$string['config_peer_scope'] = 'Peer-comparison scope';
$string['config_peer_scope_course'] = 'Whole course (default)';
$string['config_peer_scope_desc'] = 'Which set of students to use as the comparison baseline for peer-relative signals (stalled-completion, forum-silence) and for the displayed percentile. "Whole course" is the right choice for nearly all classes; "Only the viewer\'s groups" is for courses where groups are structurally separate (e.g., different teachers, different paces) and cross-group comparisons would be misleading.';
$string['config_peer_scope_group'] = 'Only the viewer\'s groups';
$string['config_topn'] = 'Number of students to show';
$string['config_topn_desc'] = 'How many flagged students to show in this block. Leave blank to inherit the site default ({$a->sitedefault}).';
$string['empty_calibrating'] = 'Heuristics are calibrating — full signals activate from week 3.';
$string['empty_no_enrolments'] = 'Heuristics will activate when students enrol.';
$string['empty_no_group_assignment'] = 'You are not a member of any group in this course. The course uses separate groups, so there are no students to show. Ask the course administrator to add you to a group, or to grant the "Access all groups" capability.';
$string['empty_none_flagged'] = 'No students currently flagged.';
$string['invalidcontext'] = 'Block must be in a course context.';
$string['invalidpreset'] = 'Invalid sensitivity preset.';
$string['paused_until'] = 'Currently in a configured break (until {$a->date}). The list reflects pre-break activity; break time is excluded from the calculations.';
$string['percentile_label'] = '{$a->rank}th percentile';
$string['pluginname'] = 'Solin Early Warning';
$string['preset_default'] = 'Default';
$string['preset_default_tooltip'] = 'Site-default thresholds. The middle ground between More and Fewer.';
$string['preset_fewer'] = 'Fewer';
$string['preset_fewer_tooltip'] = 'Show fewer flagged students by raising the thresholds (e.g., flag inactivity only after 14+ days).';
$string['preset_more'] = 'More';
$string['preset_more_tooltip'] = 'Show more flagged students by lowering the thresholds (e.g., flag inactivity at 5+ days instead of 7).';
$string['privacy:metadata:courseshape'] = 'Course-level operational-shape detection (cohort-paced, self-paced, one-shot compliance, blended, unknown). Course-level metadata only — contains no personal data, no user identifiers, no per-student values. Declared here for completeness of the privacy provider per FR-106g.';
$string['privacy:metadata:courseshape:confidence'] = 'Detection confidence: high, medium, or low.';
$string['privacy:metadata:courseshape:courseid'] = 'The course this row classifies.';
$string['privacy:metadata:courseshape:features_json'] = 'Aggregated feature values used for classification (counts, date spreads). No user identifiers, no per-student values.';
$string['privacy:metadata:courseshape:lastcomputed'] = 'When the row was last refreshed by the daily detection task.';
$string['privacy:metadata:courseshape:shape'] = 'Detected operational shape.';
$string['privacy:metadata:dismissals'] = 'Records of teacher dismissals of student at-risk flags. Each row represents one dismissal-for-one-week action.';
$string['privacy:metadata:dismissals:courseid'] = 'The course in which the dismissal occurred.';
$string['privacy:metadata:dismissals:dismissed_at'] = 'When the dismissal was performed.';
$string['privacy:metadata:dismissals:dismissed_by'] = 'The teacher who performed the dismissal. Set to 0 when the actor user has been anonymised.';
$string['privacy:metadata:dismissals:dismissed_until'] = 'When the dismissal expires (one week after dismissed_at).';
$string['privacy:metadata:dismissals:signals_at_dismissal'] = 'JSON record of which at-risk signals were firing for the student at the moment of dismissal (signal names, severity tier, sensitivity preset). Purpose: end-of-term recalibration of signal thresholds against teacher false-positive judgements. Never used for active dismissal filtering. Retention controlled by the dismissal_log_retention_days site setting.';
$string['privacy:metadata:dismissals:userid'] = 'The flagged student whose flag was dismissed.';
$string['privacy:metadata:flagsnapshots'] = 'Opt-in weekly per-signal flag-state log. Disabled by default — an admin enables it under the plugin settings. Purpose: end-of-term recalibration of at-risk detection thresholds against actual outcomes (course completion, final grades). Each row records one signal that fired for one student in one ISO week. Lawful basis under GDPR is typically legitimate interest in service improvement (institutional decision; the plugin does not assume the basis on the controller\'s behalf). Retention controlled by the flag_log_retention_days site setting.';
$string['privacy:metadata:flagsnapshots:courseid'] = 'The course the snapshot belongs to.';
$string['privacy:metadata:flagsnapshots:metric_value'] = 'The signal\'s underlying metric value (days idle, miss count, etc.).';
$string['privacy:metadata:flagsnapshots:percentile'] = 'Worst peer-percentile across the student\'s firing signals at snapshot time.';
$string['privacy:metadata:flagsnapshots:preset'] = 'Sensitivity preset (more / default / fewer) active when the snapshot was taken.';
$string['privacy:metadata:flagsnapshots:signal_name'] = 'The name of the signal that fired (e.g., inactivity, assessment_miss).';
$string['privacy:metadata:flagsnapshots:snapshotweek'] = 'The ISO week the snapshot was taken.';
$string['privacy:metadata:flagsnapshots:timecreated'] = 'When the snapshot row was written.';
$string['privacy:metadata:flagsnapshots:userid'] = 'The student who was flagged.';
$string['privacy:metadata:snapshots'] = 'Weekly snapshots of each active student\'s course-total grade. Source data for the grade-trend at-risk signal.';
$string['privacy:metadata:snapshots:courseid'] = 'The course the snapshot belongs to.';
$string['privacy:metadata:snapshots:finalgrade'] = 'The student\'s course-total grade at snapshot time.';
$string['privacy:metadata:snapshots:snapshotweek'] = 'The ISO week the snapshot was taken.';
$string['privacy:metadata:snapshots:timecreated'] = 'When the snapshot row was written.';
$string['privacy:metadata:snapshots:userid'] = 'The student whose grade is snapshotted.';
$string['readiness:button'] = 'Generate JSON file';
$string['readiness:footer_no_external'] = 'Downloading this file does not contact any external service.';
$string['readiness:included_heading'] = 'What\'s included';
$string['readiness:included_list'] = '<ul>
<li>Plugin site-level settings (signal thresholds, breaks calendar, retention windows)</li>
<li>Per-course aggregates: course ID, format, completion configuration, active enrolment count, activity count, whether the block is instantiated</li>
<li>Aggregate warnings: courses below the cohort floor, completion-tracked activities without expected dates, course formats where peer-relative comparisons may be less informative</li>
</ul>';
$string['readiness:intro'] = 'Generate a JSON file describing this site\'s at-risk plugin configuration and a structural survey of the course catalog. May be used to diagnose your settings, plan a rollout, or share with a consultant for review. The plugin does not transmit anything; you download the file and decide what to do with it.';
$string['readiness:menu'] = 'Solin Early Warning: readiness data export';
$string['readiness:notincluded_heading'] = 'What\'s NOT included';
$string['readiness:notincluded_list'] = '<ul>
<li>Usernames, emails, grades, or any personally identifying data</li>
<li>Per-student records of any kind</li>
<li>Course shortnames or fullnames — unless the option below is ticked; default off</li>
</ul>';
$string['readiness:option_allcourses'] = 'Include all courses';
$string['readiness:option_allcourses_desc'] = 'When ticked, every visible course on this site is surveyed. Default: only courses where the at-risk block is currently instantiated.';
$string['readiness:option_includenames'] = 'Include course names';
$string['readiness:option_includenames_desc'] = 'When ticked, course shortnames and fullnames are included in the export. Default: redacted to numeric IDs, since names can sometimes indirectly identify individuals.';
$string['readiness:title'] = 'Readiness data export';
$string['readiness:typical_uses'] = '<ul>
<li><strong>Self-review</strong> — open the JSON and inspect the warnings array; cross-reference against your settings to identify configuration gaps before installing the block on customer-facing courses.</li>
<li><strong>External review</strong> — share the file with a Moodle consultant for a configuration review and recommendations.</li>
<li><strong>Periodic re-check</strong> — re-run after significant changes to your course catalog (new academic year, format migrations) to spot drift.</li>
</ul>';
$string['readiness:typical_uses_heading'] = 'Typical uses';
$string['sensitivity_label'] = 'Show';
$string['settings_breaks_calendar'] = 'Breaks calendar';
$string['settings_breaks_calendar_desc'] = 'One break per line, ISO format: <code>YYYY-MM-DD, YYYY-MM-DD</code>. Lines starting with <code>#</code> are comments. Both endpoints are inclusive (full days). Example for a Dutch academic year:<pre># 2026/2027 academic year
2026-10-13, 2026-10-19
2026-12-22, 2027-01-04
2027-02-23, 2027-03-01
2027-04-27, 2027-05-08
2027-07-13, 2027-08-23</pre>';
$string['settings_breaks_heading'] = 'Holidays and term breaks';
$string['settings_breaks_heading_desc'] = 'Date ranges configured here are treated as institutional break periods. While a break is in progress the at-risk block renders a paused banner; for past breaks, the engine subtracts the break\'s overlap from each signal\'s time window so students aren\'t flagged for inactivity, missed assessments, or low forum activity over a holiday.';
$string['settings_calibration_heading'] = 'Recalibration data';
$string['settings_calibration_heading_desc'] = 'Optional logging used to recalibrate signal thresholds against real outcomes (e.g., "did students dismissed as false positives go on to complete the course?"). <strong>Personal data implications</strong>: enabling this stores per-student flag history and dismissal context. Under GDPR / UK GDPR your institution acts as data controller — you must document a lawful basis (legitimate interest under Art. 6(1)(f) is the typical fit for service improvement), update your privacy notice to mention the recalibration use, and confirm the retention period below aligns with institutional policy. See the plugin README for sample privacy-notice language.';
$string['settings_cohort_hard_floor'] = 'Hard floor (active enrolments)';
$string['settings_cohort_hard_floor_desc'] = 'Default 10 actively-enrolled students.';
$string['settings_cohort_heading'] = 'Active-enrolment floors';
$string['settings_cohort_heading_desc'] = 'Below the hard floor, peer-relative signals auto-disable for the course. Between hard and soft floors, peer-relative signals run with a small-class caveat. ("Active enrolment" here means students with an active, non-suspended course enrolment — it is not related to the Moodle cohort entity.)';
$string['settings_cohort_soft_floor'] = 'Soft floor (active enrolments)';
$string['settings_cohort_soft_floor_desc'] = 'Default 20 actively-enrolled students.';
$string['settings_commercial_heading'] = 'Commercial extensions and support';
$string['settings_commercial_heading_desc'] = 'Solin offers a paid <strong>Early Warning Dashboard</strong> plugin for institution-wide rollups across courses, plus fixed-price configuration and calibration services. This block is fully functional standalone and is not feature-gated. Details at <a href="https://solin.co/early-warning" target="_blank" rel="noopener">solin.co/early-warning</a>.';
$string['settings_dismissal_log_retention_days'] = 'Dismissal record retention (days)';
$string['settings_dismissal_log_retention_days_desc'] = 'Default 365. Dismissal records (including the captured signals at dismissal, used for false-positive analysis) are retained for this long after the dismissal\'s active window expires. Active dismissal filtering is unaffected — this only controls how long the historical record sticks around for analysis. Set lower if institutional policy requires shorter retention for teacher-action records.';
$string['settings_display_heading'] = 'Display';
$string['settings_display_top_n'] = 'Default visible row count';
$string['settings_display_top_n_desc'] = 'Default 12. The top-N flagged students shown in the block; remaining flags appear behind a paginated "View all" link. Teachers can override this on individual blocks via the block edit form.';
$string['settings_flag_log_retention_days'] = 'Flag-snapshot retention (days)';
$string['settings_flag_log_retention_days_desc'] = 'Default 365 (one academic year — covers the term-end review use case). Set lower to align with your institutional data-retention policy. Rows older than this are removed by the daily prune task. Storage limitation principle (GDPR Art. 5(1)(e)) — keep this no longer than necessary for the recalibration purpose.';
$string['settings_flag_logging_enabled'] = 'Log weekly flag snapshots';
$string['settings_flag_logging_enabled_desc'] = 'When enabled, a weekly task writes one row per (course, student, signal) for every currently-flagged student to <code>block_atrisk_flag_snapshots</code>. <strong>Purpose</strong>: end-of-term precision/recall analysis against <code>course_completions</code> / <code>grade_grades</code> to recalibrate signal thresholds. Personal data: courseid, userid, signal name, metric value, percentile, preset. Owned by this plugin and exposed via the Privacy API (export + erasure). Disable to stop new collection; existing rows are removed by the next prune cycle or via a data-subject erasure request.';
$string['settings_sensitivity_preset_enabled'] = 'Show: More/Default/Fewer control enabled';
$string['settings_sensitivity_preset_enabled_desc'] = 'Show the inline Show: More/Default/Fewer control on each block. Disable to enforce institution-wide thresholds.';
$string['settings_signal_assessment_miss_days'] = 'Assessment-miss lookback (days)';
$string['settings_signal_assessment_miss_days_desc'] = 'Default 14. Activities with completionexpected later than this many days ago are not considered missed yet.';
$string['settings_signal_assessment_miss_enabled'] = 'Assessment-miss signal: enabled';
$string['settings_signal_assessment_miss_enabled_desc'] = 'Flag students who have not completed an activity whose expected-completion date is within the lookback window.';
$string['settings_signal_forum_silence_days'] = 'Forum-silence lookback (days)';
$string['settings_signal_forum_silence_days_desc'] = 'Default 14. Window over which forum activity is measured.';
$string['settings_signal_forum_silence_enabled'] = 'Forum-silence signal: enabled (opt-in)';
$string['settings_signal_forum_silence_enabled_desc'] = 'Flag students with zero forum posts while peers are posting. Disabled by default; enable per institution where forum participation is structurally central.';
$string['settings_signal_grade_trend_enabled'] = 'Grade-trend signal: enabled';
$string['settings_signal_grade_trend_enabled_desc'] = 'Flag students whose course-total grade has declined for two consecutive weekly snapshots.';
$string['settings_signal_inactivity_days'] = 'Inactivity threshold (days)';
$string['settings_signal_inactivity_days_desc'] = 'Default 7. Students with no recorded course access for at least this many days are flagged.';
$string['settings_signal_inactivity_enabled'] = 'Inactivity signal: enabled';
$string['settings_signal_inactivity_enabled_desc'] = 'Flag students who have not accessed the course for N days.';
$string['settings_signal_stalled_completion_enabled'] = 'Stalled-completion signal: enabled';
$string['settings_signal_stalled_completion_enabled_desc'] = 'Flag students in the bottom quartile of activity completions over the last 14 days. Peer-relative; auto-disables when the class is too small or too quiet.';
$string['settings_signals_heading'] = 'Signals';
$string['settings_signals_heading_desc'] = 'Enable or disable individual at-risk signals and tune their thresholds. Per-block-instance overrides are still available; these are the institution-wide defaults.';
$string['severity_red'] = 'At risk';
$string['severity_yellow'] = 'Watch';
$string['shape_blended'] = 'Blended';
$string['shape_cohort_paced'] = 'Cohort-paced';
$string['shape_one_shot_compliance'] = 'One-shot compliance';
$string['shape_self_paced'] = 'Self-paced';
$string['shape_unknown'] = 'Unknown';
$string['signal_assessment_miss_explanation'] = 'not completed: {$a->name} (expected by {$a->date})';
$string['signal_assessment_miss_explanation_plural'] = 'not completed: {$a->name} (expected by {$a->date}) (+{$a->more} more)';
$string['signal_forum_silence_explanation'] = 'no forum posts in {$a->days} days; peer median {$a->median} in same window';
$string['signal_grade_trend_explanation'] = 'course total declined for 2 consecutive weeks (from {$a->old} to {$a->mid} to {$a->new})';
$string['signal_inactivity_explanation'] = 'no course access since {$a->date} ({$a->days} days)';
$string['signal_inactivity_no_access'] = 'no recorded course access';
$string['signal_stalled_completion_explanation'] = 'completed {$a->count} activities in last {$a->days} days; bottom quartile (peer median {$a->median})';
$string['task_detect_course_shapes'] = 'Detect course operational shape (cohort-paced, self-paced, etc.)';
$string['task_prune_dismissals'] = 'Prune expired flag dismissals';
$string['task_prune_flag_snapshots'] = 'Prune expired flag-history snapshots';
$string['task_snapshot_flags'] = 'Snapshot weekly flag state for recalibration analysis';
$string['task_snapshot_grades'] = 'Snapshot course-total grades for active students';
$string['viewallflagged'] = 'View all flagged students ({$a->count})';
