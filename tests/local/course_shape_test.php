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

use advanced_testcase;

/**
 * Unit tests for course-shape detection (FR-106 cluster, FR-221).
 *
 * Covers each classification path, the low-confidence small-sample
 * fallback, the per-instance override resolution, the per-shape engine
 * adjustments returned by {@see course_shape::adjustments_for()}, and
 * the engine_config integration that applies those adjustments.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_atrisk\local\course_shape::class)]
final class course_shape_test extends advanced_testcase {
    /**
     * Helper: build a synchronous-cohort feature bundle.
     *
     * @param int $coursestart Course start timestamp.
     * @return array Feature bundle for {@see course_shape::adjustments_for()}.
     */
    private function synchronous_features(int $coursestart): array {
        // 12 students, all activating within 3 days of course start.
        $timestarts = [];
        for ($i = 0; $i < 12; $i++) {
            $timestarts[] = $coursestart + ($i % 4) * DAYSECS;
        }
        // 10 activities with completion enabled, 8 with expected dates
        // clustered through the term.
        $expdates = [];
        for ($i = 1; $i <= 8; $i++) {
            $expdates[] = $coursestart + (7 * $i) * DAYSECS;
        }
        return [
            'enrolment_count' => 12,
            'enrolment_timestarts' => $timestarts,
            'activity_count' => 10,
            'completionexpected_count' => 8,
            'completionexpected_dates' => $expdates,
            'course_startdate' => $coursestart,
            'course_enddate' => $coursestart + 14 * 7 * DAYSECS,
        ];
    }

    public function test_classify_cohort_paced_high_confidence(): void {
        $coursestart = strtotime('2026-02-01');
        $features = $this->synchronous_features($coursestart);

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::SHAPE_COHORT_PACED, $result['shape']);
        $this->assertSame(course_shape::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function test_classify_self_paced_rolling_enrolments(): void {
        // Enrolments spread over 90 days = wide IQR.
        $start = strtotime('2026-01-01');
        $timestarts = [];
        for ($i = 0; $i < 15; $i++) {
            $timestarts[] = $start + $i * 6 * DAYSECS;
        }
        $features = [
            'enrolment_count' => 15,
            'enrolment_timestarts' => $timestarts,
            'activity_count' => 8,
            // Decent expected coverage so the rolling-IQR branch fires
            // (not the noexpected branch).
            'completionexpected_count' => 6,
            'completionexpected_dates' => array_map(
                fn($i) => $start + 14 * $i * DAYSECS,
                range(1, 6)
            ),
            'course_startdate' => $start,
            'course_enddate' => $start + 200 * DAYSECS,
        ];

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::SHAPE_SELF_PACED, $result['shape']);
        $this->assertContains(
            $result['confidence'],
            [course_shape::CONFIDENCE_HIGH, course_shape::CONFIDENCE_MEDIUM]
        );
    }

    public function test_classify_self_paced_no_expected_dates(): void {
        $start = strtotime('2026-01-01');
        // Synchronous activations but zero expected-completion dates =
        // self-paced (FR-106: "OR few-to-no completionexpected").
        $features = [
            'enrolment_count' => 20,
            'enrolment_timestarts' => array_fill(0, 20, $start),
            'activity_count' => 12,
            'completionexpected_count' => 0,
            'completionexpected_dates' => [],
            'course_startdate' => $start,
            'course_enddate' => 0,
        ];

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::SHAPE_SELF_PACED, $result['shape']);
    }

    public function test_classify_one_shot_compliance(): void {
        // 4-week course, 4 activities, single deadline cluster.
        $start = strtotime('2026-03-01');
        $end = $start + 28 * DAYSECS;
        $features = [
            'enrolment_count' => 50,
            'enrolment_timestarts' => array_fill(0, 50, $start),
            'activity_count' => 4,
            'completionexpected_count' => 1,
            'completionexpected_dates' => [$end],
            'course_startdate' => $start,
            'course_enddate' => $end,
        ];

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::SHAPE_ONE_SHOT_COMPLIANCE, $result['shape']);
    }

    public function test_classify_blended_intermediate_spread(): void {
        // Enrolments across ~14 days (intermediate), partial coverage.
        $start = strtotime('2026-02-01');
        $timestarts = [];
        for ($i = 0; $i < 12; $i++) {
            $timestarts[] = $start + $i * DAYSECS;
        }
        $features = [
            'enrolment_count' => 12,
            'enrolment_timestarts' => $timestarts,
            'activity_count' => 10,
            // 30% coverage — between the synchronous-clustered (>=50%) and
            // self-paced-noexpected (<20%) bands.
            'completionexpected_count' => 3,
            'completionexpected_dates' => [
                $start + 14 * DAYSECS,
                $start + 30 * DAYSECS,
                $start + 60 * DAYSECS,
            ],
            'course_startdate' => $start,
            'course_enddate' => $start + 90 * DAYSECS,
        ];

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::SHAPE_BLENDED, $result['shape']);
    }

    public function test_classify_unknown_when_empty(): void {
        $features = [
            'enrolment_count' => 0,
            'enrolment_timestarts' => [],
            'activity_count' => 0,
            'completionexpected_count' => 0,
            'completionexpected_dates' => [],
            'course_startdate' => 0,
            'course_enddate' => 0,
        ];

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::SHAPE_UNKNOWN, $result['shape']);
        $this->assertSame(course_shape::CONFIDENCE_LOW, $result['confidence']);
    }

    public function test_low_confidence_small_sample(): void {
        // 3 students, 2 activities. Even if shape signals point one way,
        // the sample is too small for confident classification (FR-106b).
        $start = strtotime('2026-02-01');
        $features = [
            'enrolment_count' => 3,
            'enrolment_timestarts' => array_fill(0, 3, $start),
            'activity_count' => 2,
            'completionexpected_count' => 2,
            'completionexpected_dates' => [
                $start + 14 * DAYSECS,
                $start + 28 * DAYSECS,
            ],
            'course_startdate' => $start,
            'course_enddate' => $start + 12 * 7 * DAYSECS,
        ];

        $result = course_shape::classify($features);

        $this->assertSame(course_shape::CONFIDENCE_LOW, $result['confidence']);
    }

    public function test_adjustments_per_shape(): void {
        $defaults = course_shape::adjustments_for(null);
        $this->assertSame(0, $defaults['inactivity_extra_days']);
        $this->assertFalse($defaults['disable_peer_relative']);

        $self = course_shape::adjustments_for(course_shape::SHAPE_SELF_PACED);
        $this->assertSame(14, $self['inactivity_extra_days']);
        $this->assertTrue(
            $self['disable_peer_relative'],
            'Self-paced courses must disable peer-relative signals (FR-106d).'
        );

        $oneshot = course_shape::adjustments_for(course_shape::SHAPE_ONE_SHOT_COMPLIANCE);
        $this->assertSame(7, $oneshot['inactivity_extra_days']);
        $this->assertFalse($oneshot['disable_peer_relative']);

        $blended = course_shape::adjustments_for(course_shape::SHAPE_BLENDED);
        $this->assertSame(7, $blended['inactivity_extra_days']);
        $this->assertFalse($blended['disable_peer_relative']);

        $cohort = course_shape::adjustments_for(course_shape::SHAPE_COHORT_PACED);
        $this->assertSame(0, $cohort['inactivity_extra_days']);
        $this->assertFalse($cohort['disable_peer_relative']);
    }

    public function test_effective_shape_falls_back_when_no_detection(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // No detection row yet — engine should fall back to defaults.
        $this->assertNull(course_shape::effective_shape($course->id));
    }

    public function test_effective_shape_falls_back_when_low_confidence(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_LOW,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);

        // Low confidence → engine ignores detection (FR-106b).
        $this->assertNull(course_shape::effective_shape($course->id));
    }

    public function test_effective_shape_returns_high_confidence_value(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);

        $this->assertSame(
            course_shape::SHAPE_SELF_PACED,
            course_shape::effective_shape($course->id)
        );
    }

    public function test_effective_shape_override_disable(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);

        $this->assertNull(course_shape::effective_shape($course->id, 'disable'));
    }

    public function test_effective_shape_override_force(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);

        $this->assertSame(
            course_shape::SHAPE_COHORT_PACED,
            course_shape::effective_shape($course->id, course_shape::SHAPE_COHORT_PACED)
        );
    }

    public function test_effective_shape_override_auto_passes_through(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_BLENDED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);

        $this->assertSame(
            course_shape::SHAPE_BLENDED,
            course_shape::effective_shape($course->id, 'auto')
        );
    }

    public function test_detect_and_store_inserts_then_updates(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // First call inserts.
        $first = course_shape::detect_and_store($course->id, time());
        $this->assertSame(1, $DB->count_records('block_atrisk_course_shape', ['courseid' => $course->id]));

        // Second call updates the same row (no duplicates).
        $second = course_shape::detect_and_store($course->id, time() + 60);
        $this->assertSame(1, $DB->count_records('block_atrisk_course_shape', ['courseid' => $course->id]));
        $this->assertSame($first['shape'], $second['shape']);
    }

    public function test_engine_config_applies_self_paced_adjustments(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);

        // Self-paced adds 14 days to inactivity threshold and disables
        // peer-relative signals.
        $cfg = engine_config::build_for_preset('default', null, $course->id);
        $expected = (int) (get_config('block_atrisk', 'signal_inactivity_days') ?: 7);
        $this->assertSame(
            $expected + 14,
            $cfg['thresholds']['inactivity']['days']
        );
        $this->assertFalse($cfg['signals']['stalled_completion']);
        $this->assertFalse($cfg['signals']['forum_silence']);
        $this->assertSame(course_shape::SHAPE_SELF_PACED, $cfg['course_shape']);
    }

    public function test_engine_config_low_confidence_falls_back_to_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_LOW,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);
        // Need stalled_completion turned on at the site level so we can
        // assert it stays on under the low-confidence fallback.
        set_config('signal_stalled_completion_enabled', 1, 'block_atrisk');

        $cfg = engine_config::build_for_preset('default', null, $course->id);
        $expected = (int) (get_config('block_atrisk', 'signal_inactivity_days') ?: 7);
        $this->assertSame(
            $expected,
            $cfg['thresholds']['inactivity']['days'],
            'Low-confidence detection must not extend inactivity (FR-106b).'
        );
        $this->assertTrue(
            $cfg['signals']['stalled_completion'],
            'Low-confidence detection must not silence peer-relative signals (FR-106b).'
        );
        $this->assertNull($cfg['course_shape']);
    }

    public function test_engine_config_per_instance_override_disable(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_SELF_PACED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);
        $instance = (object) ['course_shape_override' => 'disable'];

        $cfg = engine_config::build_for_preset('default', $instance, $course->id);
        $expected = (int) (get_config('block_atrisk', 'signal_inactivity_days') ?: 7);
        $this->assertSame($expected, $cfg['thresholds']['inactivity']['days']);
        $this->assertNull($cfg['course_shape']);
    }

    public function test_engine_config_per_instance_override_force(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id,
            'shape' => course_shape::SHAPE_COHORT_PACED,
            'confidence' => course_shape::CONFIDENCE_HIGH,
            'features_json' => '{}',
            'lastcomputed' => time(),
        ]);
        // Force a different shape than the detected one.
        $instance = (object) ['course_shape_override' => course_shape::SHAPE_SELF_PACED];

        $cfg = engine_config::build_for_preset('default', $instance, $course->id);
        $expected = (int) (get_config('block_atrisk', 'signal_inactivity_days') ?: 7);
        $this->assertSame(
            $expected + 14,
            $cfg['thresholds']['inactivity']['days']
        );
        $this->assertSame(course_shape::SHAPE_SELF_PACED, $cfg['course_shape']);
    }

    /**
     * Remaining classification branches: self-paced confidence levels, an
     * exact-index enrolment IQR, durations derived from expected dates, and
     * the unknown fall-through for enrolments without any activity.
     */
    public function test_classify_remaining_branches(): void {
        $day = DAYSECS;
        $start = 1_700_000_000;

        // Rolling enrolments AND no expected dates, large sample → high.
        $result = course_shape::classify([
            'enrolment_count' => 5,
            'enrolment_timestarts' => [$start, $start + 40 * $day, $start + 80 * $day, $start + 120 * $day, $start + 160 * $day],
            'activity_count' => 10,
            'completionexpected_count' => 0,
        ]);
        $this->assertSame(course_shape::SHAPE_SELF_PACED, $result['shape']);
        $this->assertSame(course_shape::CONFIDENCE_HIGH, $result['confidence']);
        // Five timestamps put Q1 and Q3 on exact indexes: 120 - 40 days.
        $this->assertSame(80, $result['features']['enrolment_iqr_days']);

        // Same shape on a small sample → low confidence.
        $result = course_shape::classify([
            'enrolment_count' => 2,
            'enrolment_timestarts' => [$start, $start + 60 * $day],
            'activity_count' => 10,
            'completionexpected_count' => 0,
        ]);
        $this->assertSame(course_shape::SHAPE_SELF_PACED, $result['shape']);
        $this->assertSame(course_shape::CONFIDENCE_LOW, $result['confidence']);

        // No end date: duration is the span of the expected dates.
        $result = course_shape::classify([
            'enrolment_count' => 1, 'activity_count' => 2, 'completionexpected_count' => 2,
            'completionexpected_dates' => [$start + 3 * $day, $start + 10 * $day],
        ]);
        $this->assertSame(7, $result['features']['course_duration_days']);

        // A start date and a single later expected date.
        $result = course_shape::classify([
            'enrolment_count' => 1, 'activity_count' => 1, 'completionexpected_count' => 1,
            'completionexpected_dates' => [$start + 9 * $day], 'course_startdate' => $start,
        ]);
        $this->assertSame(9, $result['features']['course_duration_days']);

        // A single expected date before the start date gives no duration.
        $result = course_shape::classify([
            'enrolment_count' => 1, 'activity_count' => 1, 'completionexpected_count' => 1,
            'completionexpected_dates' => [$start - $day], 'course_startdate' => $start,
        ]);
        $this->assertNull($result['features']['course_duration_days']);

        // One enrolment and no activities: nothing to classify on.
        $result = course_shape::classify(['enrolment_count' => 1, 'enrolment_timestarts' => [$start]]);
        $this->assertSame(course_shape::SHAPE_UNKNOWN, $result['shape']);
        $this->assertSame(course_shape::CONFIDENCE_LOW, $result['confidence']);
    }

    /**
     * Features are gathered from real enrolments and activities; a missing
     * course yields an empty bundle.
     */
    public function test_gather_features(): void {
        global $CFG;
        $this->resetAfterTest();
        $start = time() - 10 * WEEKSECS;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'startdate' => $start]);
        $this->getDataGenerator()->create_and_enrol($course, 'student', null, 'manual', $start);
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL, 'completionexpected' => $start + WEEKSECS,
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $features = course_shape::gather_features((int) $course->id);
        $this->assertSame(2, $features['enrolment_count']);
        $this->assertSame([$start], $features['enrolment_timestarts']);
        $this->assertSame(2, $features['activity_count']);
        $this->assertSame([$start + WEEKSECS], $features['completionexpected_dates']);

        // Without gradebook roles, the student archetype is used instead.
        $CFG->gradebookroles = '';
        $this->assertSame(2, course_shape::gather_features((int) $course->id)['enrolment_count']);

        $empty = course_shape::gather_features(999999);
        $this->assertSame(0, $empty['enrolment_count']);
        $this->assertSame(0, $empty['course_startdate']);
    }

    /**
     * A stored unknown shape is ignored even when its confidence is not low.
     */
    public function test_effective_shape_ignores_unknown(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('block_atrisk_course_shape', (object) [
            'courseid' => $course->id, 'shape' => course_shape::SHAPE_UNKNOWN,
            'confidence' => course_shape::CONFIDENCE_MEDIUM, 'features_json' => '{}', 'lastcomputed' => time(),
        ]);
        $this->assertNull(course_shape::effective_shape((int) $course->id));
    }

    /**
     * Only courses carrying the block are listed.
     */
    public function test_courses_with_block(): void {
        $this->resetAfterTest();
        $with = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->get_plugin_generator('block_atrisk')->create_instance([
            'parentcontextid' => \context_course::instance($with->id)->id,
        ]);
        $this->assertSame([(int) $with->id], course_shape::courses_with_block());
    }

    /**
     * A student enrolled through two methods counts once, with their
     * earliest start date, and causes no duplicate-key debugging.
     */
    public function test_gather_features_student_with_two_enrolments(): void {
        global $DB;
        $this->resetAfterTest();
        $start = time() - 10 * WEEKSECS;
        $course = $this->getDataGenerator()->create_course(['startdate' => $start]);
        // Self enrolment instances are disabled by default; enable it.
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $course->id, 'enrol' => 'self']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student', 'manual', $start + DAYSECS);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student', 'self', $start);

        $features = course_shape::gather_features((int) $course->id);

        $this->assertSame(1, $features['enrolment_count']);
        $this->assertSame([$start], $features['enrolment_timestarts']);
    }
}
