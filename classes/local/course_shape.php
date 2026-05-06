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
 * Course-shape detection (FR-106 cluster).
 *
 * Real-world Moodle courses span very different operational shapes —
 * synchronous cohort-paced terms, rolling-enrolment self-paced courses,
 * short single-deadline compliance modules, blended models. The same
 * threshold defaults that work for a 14-week cohort-paced semester course
 * produce false positives in a self-paced course (low engagement is the
 * natural baseline) or a one-shot compliance module (the only meaningful
 * signal is missing the deadline). This class detects each course's
 * operational shape from observable course-level metadata and feeds the
 * flag engine per-shape signal adjustments (FR-106d).
 *
 * Inputs are course-level metadata only (FR-106a): enrolment activation
 * timestamps, course startdate/enddate, completionexpected distribution,
 * activity count, active student-role enrolment count. No per-student
 * behavioral data, no logstore reads — the table contains no personal
 * data and is declared as such in the privacy provider (FR-106g).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_shape {
    /** @var string Synchronous enrolment, deadlines clustered around the timeline. */
    public const SHAPE_COHORT_PACED = 'cohort_paced';

    /** @var string Rolling enrolment OR few-to-no expected-completion dates. */
    public const SHAPE_SELF_PACED = 'self_paced';

    /** @var string Short course (<6 weeks), low activity count, single tight deadline cluster. */
    public const SHAPE_ONE_SHOT_COMPLIANCE = 'one_shot_compliance';

    /** @var string Features fall between cohort and self-paced. */
    public const SHAPE_BLENDED = 'blended';

    /** @var string Insufficient signal to classify. */
    public const SHAPE_UNKNOWN = 'unknown';

    /** @var string High confidence in the classification. */
    public const CONFIDENCE_HIGH = 'high';

    /** @var string Medium confidence. */
    public const CONFIDENCE_MEDIUM = 'medium';

    /** @var string Low confidence — engine should fall back to defaults (FR-106b). */
    public const CONFIDENCE_LOW = 'low';

    /** @var int Synchronous enrolment IQR threshold (days). */
    private const ENROLMENT_IQR_SYNCHRONOUS_DAYS = 7;

    /** @var int Wide enrolment IQR threshold (days). */
    private const ENROLMENT_IQR_WIDE_DAYS = 30;

    /** @var int Short-course duration cap for one_shot_compliance (days). */
    private const ONE_SHOT_DURATION_DAYS = 42;

    /** @var int Minimum activity count for the high-confidence band. */
    private const MIN_ACTIVITIES_FOR_CONFIDENCE = 5;

    /** @var int Minimum active-enrolment count for the high-confidence band. */
    private const MIN_ENROLMENTS_FOR_CONFIDENCE = 3;

    /**
     * Classify a feature bundle into a (shape, confidence) pair.
     *
     * Pure function — no DB access. Take features as a struct so unit tests
     * can exercise edge cases without a Moodle generator setup.
     *
     * Expected $features keys (all optional; missing keys treated as null):
     *   - enrolment_count: int — active student-role enrolments.
     *   - enrolment_timestarts: int[] — UNIX timestamps of active enrolment activation.
     *   - activity_count: int — activities with completion tracking enabled.
     *   - completionexpected_count: int — subset of activities with completionexpected > 0.
     *   - completionexpected_dates: int[] — UNIX timestamps of those expected dates.
     *   - course_startdate: int — course->startdate, or 0 if unset.
     *   - course_enddate: int — course->enddate, or 0 if unset.
     *
     * @param array $features
     * @return array{shape:string,confidence:string,features:array}
     */
    public static function classify(array $features): array {
        $enrolcount = (int) ($features['enrolment_count'] ?? 0);
        $actcount = (int) ($features['activity_count'] ?? 0);
        $expcount = (int) ($features['completionexpected_count'] ?? 0);
        $startdate = (int) ($features['course_startdate'] ?? 0);
        $enddate = (int) ($features['course_enddate'] ?? 0);
        $expdates = $features['completionexpected_dates'] ?? [];
        $timestarts = $features['enrolment_timestarts'] ?? [];

        // Compute derived metrics for diagnostics + decisioning.
        $coverage = $actcount > 0 ? ($expcount / $actcount) : 0.0;
        $enroliqr = self::iqr($timestarts);
        $duration = self::course_duration_days($startdate, $enddate, $expdates);

        $diagnostics = [
            'enrolment_count' => $enrolcount,
            'activity_count' => $actcount,
            'completionexpected_count' => $expcount,
            'completionexpected_coverage' => round($coverage, 3),
            'enrolment_iqr_days' => $enroliqr,
            'course_duration_days' => $duration,
        ];

        // Confidence floor: very small courses are inherently low-signal.
        // Engine uses low-confidence as its fall-back trigger (FR-106b).
        $smallsample = (
            $actcount < self::MIN_ACTIVITIES_FOR_CONFIDENCE
            || $enrolcount < self::MIN_ENROLMENTS_FOR_CONFIDENCE
        );

        // Unknown: nothing meaningful to classify on.
        if ($actcount === 0 && $enrolcount === 0) {
            return [
                'shape' => self::SHAPE_UNKNOWN,
                'confidence' => self::CONFIDENCE_LOW,
                'features' => $diagnostics,
            ];
        }

        // One-shot compliance: short course, few activities, tight cluster.
        $shortcourse = ($duration !== null && $duration < self::ONE_SHOT_DURATION_DAYS);
        $fewactivities = ($actcount > 0 && $actcount <= 5);
        if ($shortcourse && $fewactivities && $expcount >= 1) {
            return [
                'shape' => self::SHAPE_ONE_SHOT_COMPLIANCE,
                'confidence' => $smallsample ? self::CONFIDENCE_LOW : self::CONFIDENCE_MEDIUM,
                'features' => $diagnostics,
            ];
        }

        // Self-paced: rolling activations OR no expected-completion dates.
        $rolling = ($enroliqr !== null && $enroliqr >= self::ENROLMENT_IQR_WIDE_DAYS);
        $noexpected = ($actcount > 0 && $coverage < 0.2);
        if ($rolling || $noexpected) {
            $confidence = self::CONFIDENCE_MEDIUM;
            if ($smallsample) {
                $confidence = self::CONFIDENCE_LOW;
            } else if ($rolling && $noexpected) {
                $confidence = self::CONFIDENCE_HIGH;
            }
            return [
                'shape' => self::SHAPE_SELF_PACED,
                'confidence' => $confidence,
                'features' => $diagnostics,
            ];
        }

        // Cohort-paced: synchronous activations AND clustered deadlines.
        $synchronous = ($enroliqr !== null && $enroliqr <= self::ENROLMENT_IQR_SYNCHRONOUS_DAYS);
        $clustered = ($coverage >= 0.5);
        if ($synchronous && $clustered) {
            return [
                'shape' => self::SHAPE_COHORT_PACED,
                'confidence' => $smallsample ? self::CONFIDENCE_LOW : self::CONFIDENCE_HIGH,
                'features' => $diagnostics,
            ];
        }

        // Intermediate spread / partial coverage → blended.
        if ($enroliqr !== null || $actcount > 0) {
            return [
                'shape' => self::SHAPE_BLENDED,
                'confidence' => $smallsample ? self::CONFIDENCE_LOW : self::CONFIDENCE_MEDIUM,
                'features' => $diagnostics,
            ];
        }

        return [
            'shape' => self::SHAPE_UNKNOWN,
            'confidence' => self::CONFIDENCE_LOW,
            'features' => $diagnostics,
        ];
    }

    /**
     * Gather the FR-106a feature bundle for one course directly from the DB.
     *
     * @param int $courseid
     * @return array Feature bundle for {@see self::classify()}.
     */
    public static function gather_features(int $courseid): array {
        global $DB, $CFG;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, startdate, enddate', IGNORE_MISSING);
        if (!$course) {
            return [
                'enrolment_count' => 0,
                'enrolment_timestarts' => [],
                'activity_count' => 0,
                'completionexpected_count' => 0,
                'completionexpected_dates' => [],
                'course_startdate' => 0,
                'course_enddate' => 0,
            ];
        }

        // Active student-role enrolments. Reuses the `gradebookroles` set the
        // rest of the plugin already treats as the canonical "is a student"
        // signal — keeps shape detection and signal evaluation consistent.
        $studentroles = self::student_role_ids();
        $timestarts = [];
        $usercount = 0;
        if (!empty($studentroles)) {
            [$rolesql, $roleparams] = $DB->get_in_or_equal($studentroles, SQL_PARAMS_NAMED, 'r');
            $context = \context_course::instance($courseid);
            $params = array_merge([
                'courseid' => $courseid,
                'contextid' => $context->id,
            ], $roleparams);
            $sql = "SELECT DISTINCT ue.userid, ue.timestart
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                                    AND e.courseid = :courseid
                                    AND e.status = 0
                      JOIN {role_assignments} ra ON ra.userid = ue.userid
                                                AND ra.contextid = :contextid
                                                AND ra.roleid {$rolesql}
                     WHERE ue.status = 0";
            $rows = $DB->get_records_sql($sql, $params);
            foreach ($rows as $r) {
                $usercount++;
                if ((int) $r->timestart > 0) {
                    $timestarts[] = (int) $r->timestart;
                }
            }
        }

        // Activities with completion enabled, plus those that also have an
        // expected-completion date.
        $sql = "SELECT cm.id, cm.completion, cm.completionexpected
                  FROM {course_modules} cm
                 WHERE cm.course = :courseid
                   AND cm.completion > 0
                   AND cm.deletioninprogress = 0";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $actcount = 0;
        $expdates = [];
        foreach ($rows as $r) {
            $actcount++;
            if ((int) $r->completionexpected > 0) {
                $expdates[] = (int) $r->completionexpected;
            }
        }

        return [
            'enrolment_count' => $usercount,
            'enrolment_timestarts' => $timestarts,
            'activity_count' => $actcount,
            'completionexpected_count' => count($expdates),
            'completionexpected_dates' => $expdates,
            'course_startdate' => (int) $course->startdate,
            'course_enddate' => (int) $course->enddate,
        ];
    }

    /**
     * Detect + persist the shape for a single course. Idempotent — re-runs
     * upsert into block_atrisk_course_shape.
     *
     * @param int $courseid
     * @param int|null $now Reference timestamp (defaults to time()).
     * @return array{shape:string,confidence:string,features:array}
     */
    public static function detect_and_store(int $courseid, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $features = self::gather_features($courseid);
        $result = self::classify($features);

        $existing = $DB->get_record('block_atrisk_course_shape', ['courseid' => $courseid]);
        $record = (object) [
            'courseid' => $courseid,
            'shape' => $result['shape'],
            'confidence' => $result['confidence'],
            'features_json' => json_encode($result['features']),
            'lastcomputed' => $now,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('block_atrisk_course_shape', $record);
        } else {
            $DB->insert_record('block_atrisk_course_shape', $record);
        }

        return $result;
    }

    /**
     * Read the stored detection for one course, or null if not yet computed.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function load(int $courseid): ?\stdClass {
        global $DB;
        $row = $DB->get_record('block_atrisk_course_shape', ['courseid' => $courseid]);
        return $row ?: null;
    }

    /**
     * Resolve the effective shape for the engine, honoring the per-instance
     * override (FR-106e). Returns null when shape adjustments should not
     * apply (no detection yet, low confidence, or override = disable).
     *
     * @param int $courseid
     * @param string|null $override Per-instance override value:
     *   - null or 'auto' → use detected shape (with low-confidence fallback).
     *   - 'disable' → no per-shape adjustments.
     *   - one of the SHAPE_* constants → force that shape.
     * @return string|null Effective shape, or null when defaults should apply.
     */
    public static function effective_shape(int $courseid, ?string $override = null): ?string {
        if ($override === 'disable') {
            return null;
        }
        if (
            $override !== null && $override !== '' && $override !== 'auto'
            && in_array($override, self::all_shapes(), true)
        ) {
            return $override;
        }
        $row = self::load($courseid);
        if ($row === null) {
            return null;
        }
        if ($row->confidence === self::CONFIDENCE_LOW) {
            return null;
        }
        if ($row->shape === self::SHAPE_UNKNOWN) {
            return null;
        }
        return $row->shape;
    }

    /**
     * Canonical list of detectable course shapes (FR-106).
     *
     * @return string[]
     */
    public static function all_shapes(): array {
        return [
            self::SHAPE_COHORT_PACED,
            self::SHAPE_SELF_PACED,
            self::SHAPE_ONE_SHOT_COMPLIANCE,
            self::SHAPE_BLENDED,
            self::SHAPE_UNKNOWN,
        ];
    }

    /**
     * Per-shape engine adjustments (FR-106d). The engine applies these to
     * the threshold map after the preset is resolved.
     *
     * Returned structure:
     *   - inactivity_extra_days: int — added to inactivity threshold (more lenient).
     *   - assessment_miss_extra_days: int — added to assessment-miss lookback.
     *   - forum_silence_extra_days: int — added to forum-silence lookback.
     *   - disable_peer_relative: bool — disable stalled_completion + forum_silence.
     *
     * @param string|null $shape
     * @return array
     */
    public static function adjustments_for(?string $shape): array {
        $defaults = [
            'inactivity_extra_days' => 0,
            'assessment_miss_extra_days' => 0,
            'forum_silence_extra_days' => 0,
            'disable_peer_relative' => false,
        ];
        if ($shape === self::SHAPE_SELF_PACED) {
            return [
                'inactivity_extra_days' => 14,
                'assessment_miss_extra_days' => 0,
                'forum_silence_extra_days' => 0,
                // No synchronous peer cohort exists in a self-paced course
                // (FR-106d).
                'disable_peer_relative' => true,
            ];
        }
        if ($shape === self::SHAPE_ONE_SHOT_COMPLIANCE) {
            return [
                'inactivity_extra_days' => 7,
                'assessment_miss_extra_days' => 0,
                'forum_silence_extra_days' => 0,
                'disable_peer_relative' => false,
            ];
        }
        if ($shape === self::SHAPE_BLENDED) {
            return [
                'inactivity_extra_days' => 7,
                'assessment_miss_extra_days' => 0,
                'forum_silence_extra_days' => 0,
                'disable_peer_relative' => false,
            ];
        }
        // Cohort_paced and unknown both use defaults (no adjustments).
        return $defaults;
    }

    /**
     * Course IDs where the block is currently instantiated.
     *
     * @return int[]
     */
    public static function courses_with_block(): array {
        global $DB;
        $sql = "SELECT DISTINCT ctx.instanceid AS courseid
                  FROM {block_instances} bi
                  JOIN {context} ctx ON ctx.id = bi.parentcontextid
                                    AND ctx.contextlevel = :clevel
                 WHERE bi.blockname = 'atrisk'";
        $rows = $DB->get_records_sql($sql, ['clevel' => CONTEXT_COURSE]);
        return array_map(fn($r) => (int) $r->courseid, array_values($rows));
    }

    /**
     * Resolve the gradebook role IDs (the canonical "student" set in this
     * plugin). Falls back to the `student` archetype if the setting is
     * empty.
     *
     * @return int[]
     */
    private static function student_role_ids(): array {
        global $DB, $CFG;
        $cfg = trim((string) ($CFG->gradebookroles ?? ''));
        if ($cfg !== '') {
            $ids = array_filter(array_map('intval', explode(',', $cfg)));
            if (!empty($ids)) {
                return array_values($ids);
            }
        }
        $rows = $DB->get_records('role', ['archetype' => 'student'], '', 'id');
        return array_map(fn($r) => (int) $r->id, array_values($rows));
    }

    /**
     * Interquartile range of a numeric series, in whole days. Returns null
     * when fewer than 2 data points (insufficient signal).
     *
     * @param int[] $timestamps
     * @return int|null Whole-day IQR, or null.
     */
    private static function iqr(array $timestamps): ?int {
        $n = count($timestamps);
        if ($n < 2) {
            return null;
        }
        sort($timestamps);
        $q1 = self::percentile($timestamps, 25);
        $q3 = self::percentile($timestamps, 75);
        $secs = max(0, $q3 - $q1);
        return (int) floor($secs / DAYSECS);
    }

    /**
     * Linear-interpolation percentile (R type 7). $sorted MUST be ascending.
     */
    private static function percentile(array $sorted, float $p): float {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sorted[$low];
        }
        $frac = $rank - $low;
        return $sorted[$low] + $frac * ($sorted[$high] - $sorted[$low]);
    }

    /**
     * Course duration in days. Prefer (enddate - startdate); fall back to
     * the span of completionexpected dates. Returns null when neither is
     * usable.
     *
     * @param int $startdate
     * @param int $enddate
     * @param int[] $expdates
     * @return int|null
     */
    private static function course_duration_days(int $startdate, int $enddate, array $expdates): ?int {
        if ($startdate > 0 && $enddate > $startdate) {
            return (int) floor(($enddate - $startdate) / DAYSECS);
        }
        if (count($expdates) >= 2) {
            $min = min($expdates);
            $max = max($expdates);
            return (int) floor(($max - $min) / DAYSECS);
        }
        if ($startdate > 0 && count($expdates) >= 1) {
            $max = max($expdates);
            if ($max > $startdate) {
                return (int) floor(($max - $startdate) / DAYSECS);
            }
        }
        return null;
    }
}
