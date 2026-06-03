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
 * Unit tests for the cohort discovery and gating helper.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\cohort
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohort_test extends advanced_testcase {
    public function test_active_cohort_includes_only_students(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $teacher = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');

        $active = cohort::active($course->id);
        sort($active);
        $expected = [$student1->id, $student2->id];
        sort($expected);

        $this->assertEquals($expected, $active);
    }

    public function test_active_cohort_excludes_suspended_users(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->getDataGenerator()->enrol_user($active->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($suspended->id, $course->id, 'student');

        $cohortusers = cohort::active($course->id);
        $this->assertEquals([$active->id], $cohortusers);
    }

    public function test_active_cohort_excludes_completed_users(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $active = $this->getDataGenerator()->create_user();
        $completed = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($active->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($completed->id, $course->id, 'student');

        // Mark completed user as having completed the course.
        $DB->insert_record('course_completions', (object) [
            'userid' => $completed->id,
            'course' => $course->id,
            'timeenrolled' => time() - 90 * DAYSECS,
            'timestarted' => time() - 80 * DAYSECS,
            'timecompleted' => time() - DAYSECS,
            'reaggregate' => 0,
        ]);

        $cohortusers = cohort::active($course->id);
        $this->assertEquals([$active->id], $cohortusers);
    }

    public function test_active_cohort_excludes_suspended_enrolment(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Suspend the enrolment row.
        $DB->set_field('user_enrolments', 'status', 1, ['userid' => $student->id]);

        $this->assertEquals([], cohort::active($course->id));
    }

    public function test_active_cohort_with_group_filter(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $ingroup = $this->getDataGenerator()->create_user();
        $outgroup = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($ingroup->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($outgroup->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member([
            'userid' => $ingroup->id, 'groupid' => $group->id,
        ]);

        $cohortusers = cohort::active($course->id, $group->id);
        $this->assertEquals([$ingroup->id], $cohortusers);
    }

    public function test_gating_below_hard_floor(): void {
        $this->assertSame(cohort::GATING_DISABLED, cohort::gating(0));
        $this->assertSame(cohort::GATING_DISABLED, cohort::gating(9));
    }

    public function test_gating_in_small_cohort_zone(): void {
        $this->assertSame(cohort::GATING_SMALL, cohort::gating(10));
        $this->assertSame(cohort::GATING_SMALL, cohort::gating(15));
        $this->assertSame(cohort::GATING_SMALL, cohort::gating(19));
    }

    public function test_gating_at_or_above_soft_floor(): void {
        $this->assertSame(cohort::GATING_OK, cohort::gating(20));
        $this->assertSame(cohort::GATING_OK, cohort::gating(100));
    }

    public function test_custom_floors(): void {
        $this->assertSame(cohort::GATING_DISABLED, cohort::gating(4, 5, 10));
        $this->assertSame(cohort::GATING_SMALL, cohort::gating(7, 5, 10));
        $this->assertSame(cohort::GATING_OK, cohort::gating(15, 5, 10));
    }

    /**
     * Group-scoped active() with an array of ids returns the union of
     * memberships — needed for teachers in multiple groups under
     * SEPARATEGROUPS.
     */
    public function test_active_with_group_array_returns_union(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $carol = $this->getDataGenerator()->create_user();
        foreach ([$alice, $bob, $carol] as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        groups_add_member($groupa, $alice);
        groups_add_member($groupb, $bob);
        // Carol is in no group.

        // Single group: only alice.
        $this->assertEqualsCanonicalizing([$alice->id], cohort::active($course->id, $groupa->id));

        // Array union: alice + bob; carol excluded.
        $union = cohort::active($course->id, [$groupa->id, $groupb->id]);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id], $union);

        // Empty array behaves like null (whole course).
        $whole = cohort::active($course->id, []);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id, $carol->id], $whole);
    }

    /**
     * The bulk active_counts() must return, per course, exactly
     * count(active()) — including 0 for courses with no active learners.
     * This pins the bulk path to the single-course implementation so the
     * readiness export can use it without changing observable behavior.
     */
    public function test_active_counts_matches_per_course_active(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();

        // Course A: 2 active students, plus a teacher, a suspended user, and
        // a course-completed student — none of the latter three count.
        $ca = $gen->create_course();
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $teacher = $gen->create_user();
        $suspended = $gen->create_user(['suspended' => 1]);
        $completed = $gen->create_user();
        $gen->enrol_user($s1->id, $ca->id, 'student');
        $gen->enrol_user($s2->id, $ca->id, 'student');
        $gen->enrol_user($teacher->id, $ca->id, 'editingteacher');
        $gen->enrol_user($suspended->id, $ca->id, 'student');
        $gen->enrol_user($completed->id, $ca->id, 'student');
        $DB->insert_record('course_completions', (object) [
            'userid' => $completed->id,
            'course' => $ca->id,
            'timeenrolled' => time() - 90 * DAYSECS,
            'timestarted' => time() - 80 * DAYSECS,
            'timecompleted' => time() - DAYSECS,
            'reaggregate' => 0,
        ]);

        // Course B: 1 active student; a second student's enrolment is
        // suspended and must not count.
        $cb = $gen->create_course();
        $s3 = $gen->create_user();
        $s4 = $gen->create_user();
        $gen->enrol_user($s3->id, $cb->id, 'student');
        $gen->enrol_user($s4->id, $cb->id, 'student');
        $DB->set_field('user_enrolments', 'status', 1, ['userid' => $s4->id]);

        // Course C: created but no enrolments — must report 0, not be absent.
        $cc = $gen->create_course();

        $ids = [(int) $ca->id, (int) $cb->id, (int) $cc->id];
        $counts = cohort::active_counts($ids);

        // Equivalence with the per-course implementation, course by course.
        foreach ($ids as $cid) {
            $this->assertArrayHasKey($cid, $counts, "course {$cid} missing from counts");
            $this->assertSame(
                count(cohort::active($cid)),
                $counts[$cid],
                "active_counts disagreed with active() for course {$cid}"
            );
        }

        // Absolute expectations, as a guard against both paths sharing a bug.
        $this->assertSame(2, $counts[(int) $ca->id]);
        $this->assertSame(1, $counts[(int) $cb->id]);
        $this->assertSame(0, $counts[(int) $cc->id]);
    }

    /**
     * Empty input is a no-op returning an empty map.
     */
    public function test_active_counts_empty_input_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], cohort::active_counts([]));
    }
}
