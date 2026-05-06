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

namespace block_atrisk\local;

/**
 * Generator for the "Readiness data export" — produces a JSON-ready
 * array describing the at-risk plugin's site-level configuration plus a
 * structural survey of the course catalog. No personal data; intended
 * for self-review or sharing with a consultant.
 *
 * The output schema is versioned ({@see self::SCHEMA_VERSION}) so
 * downstream tooling can detect breaking changes between plugin
 * versions.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class readiness_report {
    /** Output schema version. Bump on backward-incompatible changes. */
    public const SCHEMA_VERSION = '1.0';

    /** All site-level settings the plugin owns. */
    private const SITE_SETTINGS = [
        'signal_inactivity_enabled',
        'signal_inactivity_days',
        'signal_assessment_miss_enabled',
        'signal_assessment_miss_days',
        'signal_grade_trend_enabled',
        'signal_stalled_completion_enabled',
        'signal_forum_silence_enabled',
        'signal_forum_silence_days',
        'cohort_hard_floor',
        'cohort_soft_floor',
        'display_top_n',
        'sensitivity_preset_enabled',
        'breaks_calendar',
        'flag_logging_enabled',
        'flag_log_retention_days',
        'dismissal_log_retention_days',
    ];

    /**
     * Build the report.
     *
     * @param bool $includenames When true, course shortname + fullname
     *        are included in per-course rows. Default false (numeric IDs
     *        only) since names can indirectly identify individuals.
     * @param bool $allcourses When true, all visible courses are
     *        included. Default false — only courses where the at-risk
     *        block is instantiated.
     * @param int|null $now Reference timestamp (default time()).
     * @return array Report data, suitable for json_encode().
     */
    public function build(bool $includenames = false, bool $allcourses = false, ?int $now = null): array {
        global $CFG, $DB;
        $now = $now ?? time();

        $courses = $this->survey_courses($allcourses, $includenames, $now);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_at' => date('c', $now),
            'contains_personal_data' => false,
            'redacted_fields' => $includenames ? [] : ['course.shortname', 'course.fullname'],
            'site' => [
                'moodle_version' => $CFG->version ?? null,
                'moodle_release' => $CFG->release ?? null,
                'php_version' => PHP_VERSION,
                'db_type' => $CFG->dbtype ?? null,
                'block_atrisk_version' => (int) (get_config('block_atrisk', 'version') ?: 0),
            ],
            'settings' => $this->collect_settings(),
            'course_summary' => $this->summarise($courses),
            'courses' => $courses,
            'warnings' => $this->derive_warnings($courses),
        ];
    }

    /**
     * Collect every site-level plugin setting into an associative array.
     */
    private function collect_settings(): array {
        $out = [];
        foreach (self::SITE_SETTINGS as $name) {
            $value = get_config('block_atrisk', $name);
            if ($value === false) {
                $value = null;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Produce a per-course row for every course in scope.
     *
     * @return array<int,array>
     */
    private function survey_courses(bool $allcourses, bool $includenames, int $now): array {
        global $DB;

        $blockcourseids = $this->courses_with_block();
        if ($allcourses) {
            $courses = $DB->get_records_select(
                'course',
                'id <> :siteid',
                ['siteid' => SITEID],
                'id ASC',
                'id, shortname, fullname, format, startdate, enablecompletion'
            );
        } else {
            if (empty($blockcourseids)) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($blockcourseids, SQL_PARAMS_NAMED, 'cid');
            $courses = $DB->get_records_select(
                'course',
                "id {$insql}",
                $inparams,
                'id ASC',
                'id, shortname, fullname, format, startdate, enablecompletion'
            );
        }

        $hasblock = array_flip($blockcourseids);
        $rows = [];
        foreach ($courses as $course) {
            $rows[] = $this->survey_one_course($course, isset($hasblock[$course->id]), $includenames, $now);
        }
        return $rows;
    }

    /**
     * Per-course row.
     */
    private function survey_one_course(\stdClass $course, bool $hasblock, bool $includenames, int $now): array {
        global $DB;

        $startweeksago = null;
        if (!empty($course->startdate)) {
            $startweeksago = max(0, (int) floor(($now - (int) $course->startdate) / WEEKSECS));
        }

        $activitycount = $DB->count_records('course_modules', ['course' => $course->id]);
        $withcompletion = $DB->count_records_select(
            'course_modules',
            'course = :cid AND completion > 0',
            ['cid' => $course->id]
        );
        $withexpected = $DB->count_records_select(
            'course_modules',
            'course = :cid AND completion > 0 AND completionexpected > 0',
            ['cid' => $course->id]
        );

        $row = [
            'id' => (int) $course->id,
            'format' => $course->format,
            'startdate_weeks_ago' => $startweeksago,
            'enablecompletion' => (bool) $course->enablecompletion,
            'active_enrolments' => count(cohort::active((int) $course->id)),
            'activity_count' => (int) $activitycount,
            'activities_with_completion' => (int) $withcompletion,
            'activities_with_completionexpected' => (int) $withexpected,
            'activities_completion_no_expected' => (int) $withcompletion - (int) $withexpected,
            'block_atrisk_instance' => $hasblock ? $this->describe_block_instance((int) $course->id) : null,
        ];

        if ($includenames) {
            $row['shortname'] = $course->shortname;
            $row['fullname'] = $course->fullname;
        }

        return $row;
    }

    /**
     * Describe the per-instance config of a course's atrisk block.
     */
    private function describe_block_instance(int $courseid): array {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT bi.configdata
               FROM {block_instances} bi
               JOIN {context} ctx ON ctx.id = bi.parentcontextid
              WHERE bi.blockname = 'atrisk'
                AND ctx.contextlevel = :clevel
                AND ctx.instanceid = :courseid",
            ['clevel' => CONTEXT_COURSE, 'courseid' => $courseid]
        );
        $config = new \stdClass();
        if ($row !== false && !empty($row->configdata)) {
            $decoded = unserialize(base64_decode($row->configdata));
            if (is_object($decoded)) {
                $config = $decoded;
            }
        }
        return [
            'preset' => $config->preset ?? null,
            'topn_override' => $config->topn ?? null,
            'forum_silence_enabled' => $config->forum_silence_enabled ?? null,
            'peer_scope' => $config->peer_scope ?? null,
            'course_breaks_lines' => empty($config->course_breaks)
                ? 0
                : count(array_filter(preg_split('/\R/', (string) $config->course_breaks))),
        ];
    }

    /**
     * Top-level summary of the surveyed courses.
     */
    private function summarise(array $courses): array {
        $total = count($courses);
        $withblock = 0;
        $withcompletion = 0;
        $withatleast1expected = 0;
        foreach ($courses as $c) {
            if ($c['block_atrisk_instance'] !== null) {
                $withblock++;
            }
            if (!empty($c['enablecompletion'])) {
                $withcompletion++;
            }
            if ($c['activities_with_completionexpected'] > 0) {
                $withatleast1expected++;
            }
        }
        return [
            'total_courses' => $total,
            'with_block_instance' => $withblock,
            'with_completion_enabled' => $withcompletion,
            'with_at_least_one_completionexpected' => $withatleast1expected,
        ];
    }

    /**
     * Derive aggregate warnings flagging structural mismatches likely to
     * produce false positives once the block runs.
     */
    private function derive_warnings(array $courses): array {
        $warnings = [];

        $hardfloor = (int) (get_config('block_atrisk', 'cohort_hard_floor') ?: 10);
        $belowhard = 0;
        $completionnoexp = 0;
        $awkwardformats = 0;
        foreach ($courses as $c) {
            if ($c['active_enrolments'] < $hardfloor) {
                $belowhard++;
            }
            if (!empty($c['enablecompletion']) && $c['activities_completion_no_expected'] > 0) {
                $completionnoexp++;
            }
            if (in_array($c['format'], ['social', 'singleactivity'], true)) {
                $awkwardformats++;
            }
        }
        if ($belowhard > 0) {
            $warnings[] = [
                'code' => 'below_hard_floor',
                'count' => $belowhard,
                'message' => "{$belowhard} course(s) below the hard floor "
                    . "(active enrolments < {$hardfloor}). Peer-relative "
                    . "signals auto-disable on these.",
            ];
        }
        if ($completionnoexp > 0) {
            $warnings[] = [
                'code' => 'completion_no_expected',
                'count' => $completionnoexp,
                'message' => "{$completionnoexp} course(s) have completion-tracked "
                    . "activities without completionexpected dates. The "
                    . "assessment-miss signal will not fire on those activities.",
            ];
        }
        if ($awkwardformats > 0) {
            $warnings[] = [
                'code' => 'awkward_format',
                'count' => $awkwardformats,
                'message' => "{$awkwardformats} course(s) use a format "
                    . "(social, singleactivity) where peer-relative comparisons "
                    . "may be structurally less informative.",
            ];
        }
        return $warnings;
    }

    /**
     * Distinct courseids where any block_atrisk instance exists.
     *
     * @return array<int>
     */
    private function courses_with_block(): array {
        global $DB;
        return array_map(
            'intval',
            $DB->get_fieldset_sql(
                "SELECT DISTINCT ctx.instanceid
                   FROM {context} ctx
                   JOIN {block_instances} bi ON bi.parentcontextid = ctx.id
                  WHERE bi.blockname = 'atrisk'
                    AND ctx.contextlevel = :clevel",
                ['clevel' => CONTEXT_COURSE]
            )
        );
    }
}
