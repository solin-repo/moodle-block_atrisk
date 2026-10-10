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
 * Unit tests for the readiness data export generator.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_atrisk\local\readiness_report::class)]
final class readiness_report_test extends advanced_testcase {
    public function test_schema_version_is_stable(): void {
        $this->resetAfterTest();
        $this->assertSame('1.0', readiness_report::SCHEMA_VERSION);
    }

    public function test_report_has_required_top_level_keys(): void {
        $this->resetAfterTest();
        $report = (new readiness_report())->build();
        $keys = [
            'schema_version', 'snapshot_at', 'contains_personal_data',
            'redacted_fields', 'site', 'settings', 'course_summary',
            'courses', 'warnings',
        ];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $report, "Missing top-level key: {$key}");
        }
    }

    public function test_report_self_declares_no_personal_data(): void {
        $this->resetAfterTest();
        $report = (new readiness_report())->build();
        $this->assertFalse($report['contains_personal_data']);
    }

    public function test_redaction_is_default_on(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'SECRET',
            'fullname' => 'Confidential Tutoring',
        ]);
        $this->add_atrisk_block_to($course);

        $report = (new readiness_report())->build();
        $this->assertContains('course.shortname', $report['redacted_fields']);
        foreach ($report['courses'] as $row) {
            $this->assertArrayNotHasKey('shortname', $row);
            $this->assertArrayNotHasKey('fullname', $row);
        }
    }

    public function test_includenames_flag_includes_course_names(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'shortname' => 'C-A',
            'fullname' => 'Course A',
        ]);
        $this->add_atrisk_block_to($course);

        $report = (new readiness_report())->build(true);
        $this->assertSame([], $report['redacted_fields']);
        $row = $report['courses'][0];
        $this->assertSame('C-A', $row['shortname']);
        $this->assertSame('Course A', $row['fullname']);
    }

    public function test_default_scope_only_includes_courses_with_block(): void {
        $this->resetAfterTest();
        $withblock = $this->getDataGenerator()->create_course();
        $this->add_atrisk_block_to($withblock);
        $withoutblock = $this->getDataGenerator()->create_course();

        $report = (new readiness_report())->build();
        $ids = array_column($report['courses'], 'id');
        $this->assertContains((int) $withblock->id, $ids);
        $this->assertNotContains((int) $withoutblock->id, $ids);
    }

    public function test_allcourses_flag_includes_courses_without_block(): void {
        $this->resetAfterTest();
        $withblock = $this->getDataGenerator()->create_course();
        $this->add_atrisk_block_to($withblock);
        $withoutblock = $this->getDataGenerator()->create_course();

        $report = (new readiness_report())->build(false, true);
        $ids = array_column($report['courses'], 'id');
        $this->assertContains((int) $withblock->id, $ids);
        $this->assertContains((int) $withoutblock->id, $ids);
    }

    public function test_below_hard_floor_warning_fires(): void {
        $this->resetAfterTest();
        // A course with one student is below the default hard floor of 10.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->add_atrisk_block_to($course);

        $report = (new readiness_report())->build();
        $codes = array_column($report['warnings'], 'code');
        $this->assertContains('below_hard_floor', $codes);
    }

    public function test_stream_output_matches_build(): void {
        $this->resetAfterTest();
        // A small mixed catalog: one block course with completion, one plain
        // course with a student so warnings and summary have something to say.
        $c1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->add_atrisk_block_to($c1);
        $c2 = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $c2->id, 'student');

        // Fixed reference time so snapshot_at is identical across both paths.
        $now = 1780000000;
        $report = new readiness_report();
        $expected = $report->build(false, true, $now);

        $handle = fopen('php://temp', 'r+');
        $report->stream($handle, false, true, $now);
        rewind($handle);
        $json = stream_get_contents($handle);
        fclose($handle);

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded, 'stream() must emit valid JSON');
        // Associative key order is ignored by assertEquals, so the streamed
        // ordering (courses before summary/warnings) does not matter; the
        // courses list is ordered by id ASC in both paths.
        $this->assertEquals($expected, $decoded);
    }

    public function test_chunked_survey_avoids_per_course_query_explosion(): void {
        $this->resetAfterTest();
        global $DB;

        // 30 courses, each with the block, an activity, and an enrolment.
        for ($i = 0; $i < 30; $i++) {
            $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
            $this->add_atrisk_block_to($course);
            $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        $before = $DB->perf_get_queries();
        $report = (new readiness_report())->build(false, true);
        $queries = $DB->perf_get_queries() - $before;

        $this->assertCount(30, $report['courses']);
        // The old per-course survey ran ~5 queries per course (~150 for 30).
        // Chunked bulk runs a handful per chunk (30 fits one chunk), so the
        // count stays far below the per-course explosion.
        $this->assertLessThan(30, $queries, "Expected bulk queries; got {$queries} for 30 courses");
    }

    /**
     * Helper — instantiate the at-risk block on a course context.
     *
     * @param \stdClass $course Course record to attach the block to.
     */
    private function add_atrisk_block_to(\stdClass $course): void {
        global $DB;
        $context = \context_course::instance($course->id);
        $now = time();
        $DB->insert_record('block_instances', (object) [
            'blockname' => 'atrisk',
            'parentcontextid' => $context->id,
            'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Settings that were never set are reported as null rather than false.
     */
    public function test_unset_settings_are_null(): void {
        $this->resetAfterTest();
        unset_config('breaks_calendar', 'block_atrisk');
        set_config('display_top_n', 7, 'block_atrisk');
        $settings = (new readiness_report())->build()['settings'];
        $this->assertNull($settings['breaks_calendar']);
        $this->assertSame('7', $settings['display_top_n']);
    }

    /**
     * Rows are identical whether the cursor is processed in one chunk or in
     * many chunks with a remainder.
     */
    public function test_chunk_boundaries_do_not_change_rows(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_course();
        }
        $now = time();
        $whole = (new readiness_report())->build(false, true, $now);
        $chunked = (new readiness_report(2))->build(false, true, $now);
        $this->assertGreaterThanOrEqual(5, count($whole['courses']));
        $this->assertEquals($whole, $chunked);
    }

    /**
     * Block configuration is described per course, and completion and format
     * characteristics are counted and raise their warnings.
     */
    public function test_block_config_and_course_characteristics(): void {
        $this->resetAfterTest();
        $config = (object) [
            'preset' => 'fewer', 'topn' => 3, 'peer_scope' => 'group',
            'forum_silence_enabled' => '0', 'course_breaks' => "2026-12-21, 2027-01-03\n2027-04-01, 2027-04-05",
        ];
        $withblock = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->get_plugin_generator('block_atrisk')->create_instance([
            'parentcontextid' => \context_course::instance($withblock->id)->id,
            'configdata' => base64_encode(serialize($config)),
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $withblock->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => time() + WEEKSECS,
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $withblock->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $social = $this->getDataGenerator()->create_course(['format' => 'social']);

        $report = (new readiness_report())->build(false, true);
        $rows = array_column($report['courses'], null, 'id');

        $this->assertSame([
            'preset' => 'fewer',
            'topn_override' => 3,
            'forum_silence_enabled' => '0',
            'peer_scope' => 'group',
            'course_breaks_lines' => 2,
        ], $rows[$withblock->id]['block_atrisk_instance']);
        $this->assertSame(1, $rows[$withblock->id]['activities_with_completionexpected']);
        $this->assertSame(1, $rows[$withblock->id]['activities_completion_no_expected']);
        $this->assertNull($rows[$social->id]['block_atrisk_instance']);
        $this->assertSame(1, $report['course_summary']['with_at_least_one_completionexpected']);

        $codes = array_column($report['warnings'], 'count', 'code');
        $this->assertSame(1, $codes['completion_no_expected']);
        $this->assertSame(1, $codes['awkward_format']);
    }
}
