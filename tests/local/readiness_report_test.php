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
 * @covers     \block_atrisk\local\readiness_report
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
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
}
