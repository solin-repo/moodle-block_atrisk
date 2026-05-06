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

namespace block_atrisk\local\signal;

use advanced_testcase;

/**
 * Unit tests for the grade-trend signal classifier.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\signal\grade_trend
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_trend_test extends advanced_testcase {
    /**
     * Insert one grade-snapshot row.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $week ISO-week stamp (YYYYWW).
     * @param float|null $finalgrade
     * @param int $now Reference timestamp for timecreated.
     */
    private function insert_snapshot(int $courseid, int $userid, int $week, ?float $finalgrade, int $now): void {
        global $DB;
        $DB->insert_record('block_atrisk_grade_snapshots', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'snapshotweek' => $week,
            'finalgrade' => $finalgrade,
            'timecreated' => $now,
        ]);
    }

    public function test_two_consecutive_declines_fires(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $now = 1_700_000_000;

        // S1=80, S2=75, S3=70 — two consecutive declines.
        $this->insert_snapshot($course->id, $student->id, 202601, 80.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202602, 75.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202603, 70.0, $now);

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], $now);

        $this->assertTrue($results[$student->id]->triggered);
        $this->assertEquals(70.0, $results[$student->id]->metric);
        $this->assertStringContainsString('declined for 2 consecutive weeks', $results[$student->id]->explanation);
    }

    public function test_one_decline_does_not_fire(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $now = 1_700_000_000;

        // S1=80, S2=85, S3=70 — one decline (S2→S3), but S1→S2 is up.
        $this->insert_snapshot($course->id, $student->id, 202601, 80.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202602, 85.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202603, 70.0, $now);

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_only_two_snapshots_does_not_fire(): void {
        // FR-20: requires ≥ 3 snapshots before firing.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $now = 1_700_000_000;

        $this->insert_snapshot($course->id, $student->id, 202601, 80.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202602, 70.0, $now);

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_no_snapshots_does_not_fire(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], 1_700_000_000);

        $this->assertFalse($results[$student->id]->triggered);
        $this->assertNull($results[$student->id]->metric);
    }

    public function test_null_in_any_snapshot_does_not_fire(): void {
        // FR-19 null behaviour: if any of S1/S2/S3 is null, signal does not
        // fire. Null = "no information", not a comparison value.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $now = 1_700_000_000;

        $this->insert_snapshot($course->id, $student->id, 202601, 80.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202602, null, $now);
        $this->insert_snapshot($course->id, $student->id, 202603, 60.0, $now);

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_uses_three_most_recent_snapshots(): void {
        // Older snapshots should be ignored. The signal looks only at the
        // three most recent.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $now = 1_700_000_000;

        // Older history: 50→60→70 (rising). Then most-recent 3: 90→80→70 (declining).
        $this->insert_snapshot($course->id, $student->id, 202550, 50.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202551, 60.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202552, 70.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202601, 90.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202602, 80.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202603, 70.0, $now);

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], $now);

        $this->assertTrue($results[$student->id]->triggered);
    }

    public function test_flat_does_not_fire(): void {
        // S1=70, S2=70, S3=70 — flat, not declining. Should not fire.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $now = 1_700_000_000;

        $this->insert_snapshot($course->id, $student->id, 202601, 70.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202602, 70.0, $now);
        $this->insert_snapshot($course->id, $student->id, 202603, 70.0, $now);

        $signal = new grade_trend();
        $results = $signal->evaluate($course->id, [$student->id], [], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_signal_scoped_to_courseid(): void {
        // Snapshots from another course should not influence this one.
        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $course2->id, 'student');
        $now = 1_700_000_000;

        // Decline in course2.
        $this->insert_snapshot($course2->id, $student->id, 202601, 90.0, $now);
        $this->insert_snapshot($course2->id, $student->id, 202602, 80.0, $now);
        $this->insert_snapshot($course2->id, $student->id, 202603, 70.0, $now);
        // Course1 has no snapshots.

        $signal = new grade_trend();
        $results = $signal->evaluate($course1->id, [$student->id], [], $now);
        $this->assertFalse($results[$student->id]->triggered);
    }
}
