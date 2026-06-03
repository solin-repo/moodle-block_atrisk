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
 * description of the at-risk plugin's site-level configuration plus a
 * structural survey of the course catalog. No personal data; intended
 * for self-review or sharing with a consultant.
 *
 * Built to scale to large catalogs (thousands of courses) without a
 * memory spike: {@see self::stream()} writes the JSON incrementally and
 * the course survey runs off a forward-only recordset cursor processed in
 * fixed-size chunks, each chunk resolved with a handful of bulk queries
 * rather than per-course queries. {@see self::build()} returns the same
 * data as an in-memory array for callers that want the whole structure
 * (and for the bounded default scope).
 *
 * The output schema is versioned ({@see self::SCHEMA_VERSION}) so
 * downstream tooling can detect breaking changes between plugin versions.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class readiness_report {
    /** Output schema version. Bump on backward-incompatible changes. */
    public const SCHEMA_VERSION = '1.0';

    /** Courses surveyed per cursor chunk before bulk-resolving their data. */
    private const CHUNK = 200;

    /** JSON encode flags shared by build() and stream(). */
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
     * Build the full report as an in-memory array.
     *
     * Suitable for json_encode() and for the default (bounded) scope. For
     * a full-catalog export, prefer {@see self::stream()} to avoid holding
     * every course in memory at once.
     *
     * @param bool $includenames When true, course shortname + fullname are
     *        included in per-course rows. Default false (numeric IDs only)
     *        since names can indirectly identify individuals.
     * @param bool $allcourses When true, all visible courses are included.
     *        Default false — only courses where the block is instantiated.
     * @param int|null $now Reference timestamp (default time()).
     * @return array Report data, suitable for json_encode().
     */
    public function build(bool $includenames = false, bool $allcourses = false, ?int $now = null): array {
        $now = $now ?? time();
        $acc = $this->fresh_accumulator();
        $courses = [];
        foreach ($this->course_rows($allcourses, $includenames, $now) as $row) {
            $courses[] = $row;
            $this->accumulate($acc, $row);
        }

        $report = $this->envelope_head($includenames, $now);
        $report['course_summary'] = $this->finalize_summary($acc);
        $report['courses'] = $courses;
        $report['warnings'] = $this->finalize_warnings($acc);
        return $report;
    }

    /**
     * Stream the full report as JSON to an open file handle, bounded in
     * memory regardless of catalog size. Courses are written one at a time
     * straight off the cursor; the summary and warnings are accumulated as
     * rows pass and emitted at the end.
     *
     * @param resource $handle Writable stream (temp file, php://output…).
     * @param bool $includenames See {@see self::build()}.
     * @param bool $allcourses See {@see self::build()}.
     * @param int|null $now Reference timestamp (default time()).
     */
    public function stream($handle, bool $includenames = false, bool $allcourses = false, ?int $now = null): void {
        $now = $now ?? time();

        fwrite($handle, "{\n");
        foreach ($this->envelope_head($includenames, $now) as $key => $value) {
            fwrite($handle, json_encode((string) $key, self::JSON_FLAGS) . ': '
                . json_encode($value, self::JSON_FLAGS) . ",\n");
        }

        fwrite($handle, '"courses": [');
        $acc = $this->fresh_accumulator();
        $first = true;
        foreach ($this->course_rows($allcourses, $includenames, $now) as $row) {
            fwrite($handle, ($first ? '' : ',') . json_encode($row, self::JSON_FLAGS));
            $first = false;
            $this->accumulate($acc, $row);
        }
        fwrite($handle, "],\n");

        fwrite($handle, '"course_summary": '
            . json_encode($this->finalize_summary($acc), self::JSON_FLAGS) . ",\n");
        fwrite($handle, '"warnings": '
            . json_encode($this->finalize_warnings($acc), self::JSON_FLAGS) . "\n");
        fwrite($handle, "}\n");
    }

    /**
     * The report keys that do not depend on the per-course survey:
     * everything except course_summary, courses and warnings.
     *
     * @param bool $includenames Whether course names are included.
     * @param int $now Reference timestamp.
     * @return array Ordered head of the report.
     */
    private function envelope_head(bool $includenames, int $now): array {
        global $CFG;
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
     * Yield one report row per course in scope, off a forward-only cursor
     * processed in fixed-size chunks. Each chunk resolves its course_modules
     * aggregates, block-instance config and active-enrolment counts with one
     * bulk query apiece, so query volume scales with chunk count, not course
     * count.
     *
     * @param bool $allcourses Survey every course, not just block-instanced ones.
     * @param bool $includenames Include shortname/fullname in the rows.
     * @param int $now Reference timestamp.
     * @return \Generator<array> Per-course rows.
     */
    private function course_rows(bool $allcourses, bool $includenames, int $now): \Generator {
        $blockcourseids = $this->courses_with_block();
        $hasblock = array_flip($blockcourseids);

        $rs = $this->course_recordset($allcourses, $blockcourseids);
        $chunk = [];
        foreach ($rs as $course) {
            $chunk[(int) $course->id] = $course;
            if (count($chunk) >= self::CHUNK) {
                yield from $this->map_chunk($chunk, $hasblock, $includenames, $now);
                $chunk = [];
            }
        }
        $rs->close();
        if (!empty($chunk)) {
            yield from $this->map_chunk($chunk, $hasblock, $includenames, $now);
        }
    }

    /**
     * Forward-only recordset of the courses in scope.
     *
     * @param bool $allcourses All visible courses, or only block-instanced ones.
     * @param int[] $blockcourseids Courses that have a block instance.
     * @return \moodle_recordset
     */
    private function course_recordset(bool $allcourses, array $blockcourseids): \moodle_recordset {
        global $DB;
        $fields = 'id, shortname, fullname, format, startdate, enablecompletion';
        if ($allcourses) {
            return $DB->get_recordset_select(
                'course',
                'id <> :siteid',
                ['siteid' => SITEID],
                'id ASC',
                $fields
            );
        }
        if (empty($blockcourseids)) {
            // Forward-only empty set without inventing a sentinel course id.
            return $DB->get_recordset_select('course', '1 = 0', [], 'id ASC', $fields);
        }
        [$insql, $inparams] = $DB->get_in_or_equal($blockcourseids, SQL_PARAMS_NAMED, 'cid');
        return $DB->get_recordset_select('course', "id {$insql}", $inparams, 'id ASC', $fields);
    }

    /**
     * Resolve a chunk of course records into report rows using bulk queries.
     *
     * @param array $chunk courseid → course record (\stdClass).
     * @param array $hasblock Set of courseids with a block instance (as keys).
     * @param bool $includenames Include shortname/fullname.
     * @param int $now Reference timestamp.
     * @return array<array> Per-course rows.
     */
    private function map_chunk(array $chunk, array $hasblock, bool $includenames, int $now): array {
        $ids = array_keys($chunk);
        $aggregates = $this->module_aggregates($ids);
        $configs = $this->block_configdata($ids);
        $activecounts = cohort::active_counts($ids);

        $rows = [];
        foreach ($chunk as $cid => $course) {
            $startweeksago = null;
            if (!empty($course->startdate)) {
                $startweeksago = max(0, (int) floor(($now - (int) $course->startdate) / WEEKSECS));
            }
            $agg = $aggregates[$cid] ?? ['count' => 0, 'withcompletion' => 0, 'withexpected' => 0];
            $withcompletion = $agg['withcompletion'];
            $withexpected = $agg['withexpected'];

            $row = [
                'id' => (int) $cid,
                'format' => $course->format,
                'startdate_weeks_ago' => $startweeksago,
                'enablecompletion' => (bool) $course->enablecompletion,
                'active_enrolments' => (int) ($activecounts[$cid] ?? 0),
                'activity_count' => (int) $agg['count'],
                'activities_with_completion' => (int) $withcompletion,
                'activities_with_completionexpected' => (int) $withexpected,
                'activities_completion_no_expected' => (int) $withcompletion - (int) $withexpected,
                'block_atrisk_instance' => isset($hasblock[$cid])
                    ? $this->describe_configdata($configs[$cid] ?? null)
                    : null,
            ];
            if ($includenames) {
                $row['shortname'] = $course->shortname;
                $row['fullname'] = $course->fullname;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Bulk course_modules aggregates for a set of courses: total activities,
     * completion-tracked, and completion-tracked-with-expected-date. One query.
     *
     * @param int[] $courseids
     * @return array<int,array{count:int,withcompletion:int,withexpected:int}>
     */
    private function module_aggregates(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT course,
                       COUNT(*) AS cnt,
                       SUM(CASE WHEN completion > 0 THEN 1 ELSE 0 END) AS withcompletion,
                       SUM(CASE WHEN completion > 0 AND completionexpected > 0 THEN 1 ELSE 0 END) AS withexpected
                  FROM {course_modules}
                 WHERE course {$insql}
              GROUP BY course";
        $out = [];
        foreach ($DB->get_records_sql($sql, $inparams) as $r) {
            $out[(int) $r->course] = [
                'count' => (int) $r->cnt,
                'withcompletion' => (int) $r->withcompletion,
                'withexpected' => (int) $r->withexpected,
            ];
        }
        return $out;
    }

    /**
     * Bulk-load the raw configdata blob for each course's atrisk block. One query.
     *
     * @param int[] $courseids
     * @return array<int,string> courseid → configdata (only courses that have one).
     */
    private function block_configdata(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'bc');
        $sql = "SELECT ctx.instanceid AS courseid, bi.configdata
                  FROM {block_instances} bi
                  JOIN {context} ctx ON ctx.id = bi.parentcontextid
                 WHERE bi.blockname = 'atrisk'
                   AND ctx.contextlevel = :clevel
                   AND ctx.instanceid {$insql}";
        $params = array_merge(['clevel' => CONTEXT_COURSE], $inparams);
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $r) {
            $out[(int) $r->courseid] = (string) $r->configdata;
        }
        return $out;
    }

    /**
     * Describe a block instance's per-instance config from its raw configdata.
     *
     * @param string|null $configdata Base64+serialized configdata, or null.
     * @return array Per-instance configuration summary.
     */
    private function describe_configdata(?string $configdata): array {
        $config = new \stdClass();
        if (!empty($configdata)) {
            $decoded = unserialize(base64_decode($configdata));
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
     * A fresh accumulator for the running summary and warning counters.
     *
     * @return array Mutable counter set.
     */
    private function fresh_accumulator(): array {
        return [
            'hardfloor' => (int) (get_config('block_atrisk', 'cohort_hard_floor') ?: 10),
            'total' => 0,
            'withblock' => 0,
            'withcompletion' => 0,
            'withexpected' => 0,
            'belowhard' => 0,
            'completionnoexp' => 0,
            'awkward' => 0,
        ];
    }

    /**
     * Fold one row into the running summary/warning counters.
     *
     * @param array $acc Accumulator (by reference).
     * @param array $row A per-course report row.
     */
    private function accumulate(array &$acc, array $row): void {
        $acc['total']++;
        if ($row['block_atrisk_instance'] !== null) {
            $acc['withblock']++;
        }
        if (!empty($row['enablecompletion'])) {
            $acc['withcompletion']++;
        }
        if ($row['activities_with_completionexpected'] > 0) {
            $acc['withexpected']++;
        }
        if ($row['active_enrolments'] < $acc['hardfloor']) {
            $acc['belowhard']++;
        }
        if (!empty($row['enablecompletion']) && $row['activities_completion_no_expected'] > 0) {
            $acc['completionnoexp']++;
        }
        if (in_array($row['format'], ['social', 'singleactivity'], true)) {
            $acc['awkward']++;
        }
    }

    /**
     * Finalize the top-level course summary from the accumulator.
     *
     * @param array $acc Accumulator.
     * @return array Aggregate counts.
     */
    private function finalize_summary(array $acc): array {
        return [
            'total_courses' => $acc['total'],
            'with_block_instance' => $acc['withblock'],
            'with_completion_enabled' => $acc['withcompletion'],
            'with_at_least_one_completionexpected' => $acc['withexpected'],
        ];
    }

    /**
     * Finalize the warnings list from the accumulator. Same codes and
     * messages as the pre-streaming implementation.
     *
     * @param array $acc Accumulator.
     * @return array List of warning descriptors.
     */
    private function finalize_warnings(array $acc): array {
        $warnings = [];
        $hardfloor = $acc['hardfloor'];
        if ($acc['belowhard'] > 0) {
            $warnings[] = [
                'code' => 'below_hard_floor',
                'count' => $acc['belowhard'],
                'message' => "{$acc['belowhard']} course(s) below the hard floor "
                    . "(active enrolments < {$hardfloor}). Peer-relative "
                    . "signals auto-disable on these.",
            ];
        }
        if ($acc['completionnoexp'] > 0) {
            $warnings[] = [
                'code' => 'completion_no_expected',
                'count' => $acc['completionnoexp'],
                'message' => "{$acc['completionnoexp']} course(s) have completion-tracked "
                    . "activities without completionexpected dates. The "
                    . "assessment-miss signal will not fire on those activities.",
            ];
        }
        if ($acc['awkward'] > 0) {
            $warnings[] = [
                'code' => 'awkward_format',
                'count' => $acc['awkward'],
                'message' => "{$acc['awkward']} course(s) use a format "
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
