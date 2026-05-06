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
 * Builds the engine config array from a sensitivity preset and the
 * site-level signal-enable settings. Shared by the block class and the
 * paginated full-list page so both produce identical flags.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_config {
    /**
     * Build the engine config array for the given preset, with optional
     * per-block-instance overrides.
     *
     * Presets are teacher-facing labels for "Show": More widens the net
     * (looser thresholds, more students flagged), Fewer narrows it. The
     * legacy 'loose'/'balanced'/'strict' keys are accepted as aliases so
     * stored block-instance configdata from earlier versions keeps working.
     *
     * Per-instance overrides currently supported:
     * - {@code forum_silence_enabled} (string tri-state: 'site' = inherit,
     *   '1' = force on, '0' = force off). Some courses use forums as a
     *   participation channel, others don't (FR-27).
     * - {@code course_shape_override} (FR-106e): 'auto' (default) honours
     *   the detected shape, 'disable' suppresses per-shape adjustments, or
     *   one of the SHAPE_* constants forces a specific shape.
     *
     * Per-shape engine adjustments (FR-106d) are applied to the threshold
     * map after the preset is resolved: self-paced courses get an extended
     * inactivity window and disable peer-relative signals; blended and
     * one-shot-compliance shapes get a milder inactivity extension.
     * Low-confidence detections fall back to defaults (FR-106b).
     *
     * @param string $preset One of 'more', 'default', 'fewer'.
     * @param \stdClass|null $instance Per-instance configdata, or null to
     *        use site-wide settings only.
     * @param int|null $courseid Course context, used to resolve the detected
     *        shape. Pass null for code paths (tests, settings UI) that don't
     *        run inside a course.
     * @return array Engine config (signals, thresholds, calibration).
     */
    public static function build_for_preset(
        string $preset,
        ?\stdClass $instance = null,
        ?int $courseid = null
    ): array {
        // Legacy aliases — earlier versions used heuristic-perspective names
        // (loose = catches everything → mapped to 'more'; strict = catches
        // only severe → mapped to 'fewer').
        $aliases = [
            'loose' => 'more',
            'balanced' => 'default',
            'strict' => 'fewer',
        ];
        $preset = $aliases[$preset] ?? $preset;

        // For each signal, "More" picks the threshold that yields more
        // flags and "Fewer" picks the threshold that yields fewer.
        // Inactivity + forum-silence: short window = easier to qualify.
        // Assessment-miss: long window = more eligible activities = more
        // potential misses.
        $thresholds = [
            'more' => [
                'inactivity' => ['days' => 5],
                'assessment_miss' => ['days' => 21],
                'forum_silence' => ['days' => 10],
            ],
            'default' => [
                'inactivity' => ['days' => (int) (get_config('block_atrisk', 'signal_inactivity_days') ?: 7)],
                'assessment_miss' => ['days' => (int) (get_config('block_atrisk', 'signal_assessment_miss_days') ?: 14)],
                'forum_silence' => ['days' => (int) (get_config('block_atrisk', 'signal_forum_silence_days') ?: 14)],
            ],
            'fewer' => [
                'inactivity' => ['days' => 14],
                'assessment_miss' => ['days' => 10],
                'forum_silence' => ['days' => 21],
            ],
        ][$preset] ?? [];

        // Resolve effective course shape (FR-106d, FR-106e). null when
        // the detection hasn't run yet, the confidence is low, or the
        // teacher disabled per-shape adjustments via the override field.
        $effectiveshape = null;
        if ($courseid !== null) {
            $override = null;
            if ($instance !== null && isset($instance->course_shape_override)) {
                $override = (string) $instance->course_shape_override;
            }
            $effectiveshape = course_shape::effective_shape($courseid, $override);
        }
        $shapeadjust = course_shape::adjustments_for($effectiveshape);

        // Apply per-shape inactivity / window extensions to the resolved
        // threshold map. Adjustments are additive on top of the preset.
        if (isset($thresholds['inactivity']['days'])) {
            $thresholds['inactivity']['days'] += (int) $shapeadjust['inactivity_extra_days'];
        }
        if (isset($thresholds['assessment_miss']['days'])) {
            $thresholds['assessment_miss']['days'] += (int) $shapeadjust['assessment_miss_extra_days'];
        }
        if (isset($thresholds['forum_silence']['days'])) {
            $thresholds['forum_silence']['days'] += (int) $shapeadjust['forum_silence_extra_days'];
        }

        // Forum-silence: site-level setting, optionally overridden per
        // block instance.
        $forumsilenceenabled = (bool) get_config('block_atrisk', 'signal_forum_silence_enabled');
        if ($instance !== null && isset($instance->forum_silence_enabled)) {
            $override = (string) $instance->forum_silence_enabled;
            if ($override === '1') {
                $forumsilenceenabled = true;
            } else if ($override === '0') {
                $forumsilenceenabled = false;
            }
            // Anything else (including 'site' or unset) → inherit site default.
        }

        // Breaks calendar: site-wide list + optional per-instance list.
        // Both stored as multiline ISO-date strings; merged + sorted here.
        $breakranges = self::parse_combined_breaks(
            (string) (get_config('block_atrisk', 'breaks_calendar') ?: ''),
            $instance !== null ? (string) ($instance->course_breaks ?? '') : ''
        );

        $stalledenabled = (bool) get_config('block_atrisk', 'signal_stalled_completion_enabled');
        if ($shapeadjust['disable_peer_relative']) {
            // FR-106d: self-paced courses have no synchronous peer cohort,
            // so peer-relative signals are silenced regardless of the
            // site/instance enable flags.
            $stalledenabled = false;
            $forumsilenceenabled = false;
        }

        return [
            'signals' => [
                'inactivity' => (bool) get_config('block_atrisk', 'signal_inactivity_enabled'),
                'assessment_miss' => (bool) get_config('block_atrisk', 'signal_assessment_miss_enabled'),
                'grade_trend' => (bool) get_config('block_atrisk', 'signal_grade_trend_enabled'),
                'stalled_completion' => $stalledenabled,
                'forum_silence' => $forumsilenceenabled,
            ],
            'thresholds' => $thresholds,
            'calibration' => [
                'gatedweeks' => 2,
                'tentativeweeks' => 2,
            ],
            'breaks' => $breakranges,
            'course_shape' => $effectiveshape,
        ];
    }

    /**
     * Parse + merge the site and per-instance breaks-calendar strings.
     * Invalid input is silently dropped — both surfaces have validation
     * upstream of save (admin form + block edit form) so by the time we
     * read here, malformed config should already have been rejected.
     * The fallback is graceful: we treat unparseable input as "no breaks
     * configured" rather than crash render.
     *
     * @param string $sitetext
     * @param string $instancetext
     * @return array<int,array{start:int,end:int}>
     */
    private static function parse_combined_breaks(string $sitetext, string $instancetext): array {
        try {
            $site = breaks::parse($sitetext);
        } catch (\invalid_parameter_exception $e) {
            $site = [];
        }
        try {
            $instance = breaks::parse($instancetext);
        } catch (\invalid_parameter_exception $e) {
            $instance = [];
        }
        // Concatenate, then re-merge to dedupe overlaps across the two lists.
        $combined = array_merge($site, $instance);
        if (empty($combined)) {
            return [];
        }
        // The parse() method handles merging within one list; for the
        // union we sort and merge here directly (cheaper than
        // re-stringifying both lists).
        usort($combined, fn($a, $b) => $a['start'] <=> $b['start']);
        $merged = [$combined[0]];
        for ($i = 1; $i < count($combined); $i++) {
            $last = &$merged[count($merged) - 1];
            $r = $combined[$i];
            if ($r['start'] <= $last['end'] + 1) {
                $last['end'] = max($last['end'], $r['end']);
            } else {
                $merged[] = $r;
            }
        }
        return $merged;
    }
}
