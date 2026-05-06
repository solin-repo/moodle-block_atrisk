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
 * Unit tests for {@see group_access} — the visibility classifier used by
 * the in-block render and view.php to decide whether the viewing user
 * sees the whole course, only their own groups, or nothing at all
 * (FR-56 v1).
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\group_access
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class group_access_test extends advanced_testcase {
    public function test_unrestricted_under_nogroups(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['groupmode' => NOGROUPS, 'groupmodeforce' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $access = group_access::for_user($course->id, $teacher->id);
        $this->assertSame(group_access::MODE_UNRESTRICTED, $access['mode']);
        $this->assertSame([], $access['groupids']);
    }

    public function test_unrestricted_under_visiblegroups(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'groupmode' => VISIBLEGROUPS, 'groupmodeforce' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        groups_add_member($group, $teacher);

        $access = group_access::for_user($course->id, $teacher->id);
        $this->assertSame(group_access::MODE_UNRESTRICTED, $access['mode']);
    }

    public function test_separategroups_with_accessallgroups_is_unrestricted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1,
        ]);
        // Editing teachers have moodle/site:accessallgroups by default.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'editingteacher');

        $access = group_access::for_user($course->id, $manager->id);
        $this->assertSame(group_access::MODE_UNRESTRICTED, $access['mode']);
    }

    public function test_separategroups_without_accessallgroups_returns_user_groups(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course([
            'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        // The non-editing 'teacher' archetype lacks accessallgroups by
        // default, but be explicit so the test is robust to site policy.
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        role_change_permission(
            $teacherrole->id,
            $context,
            'moodle/site:accessallgroups',
            CAP_PREVENT
        );

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupc = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        groups_add_member($groupa, $teacher);
        groups_add_member($groupb, $teacher);
        // Teacher is NOT in groupc.

        $access = group_access::for_user($course->id, $teacher->id);
        $this->assertSame(group_access::MODE_RESTRICTED, $access['mode']);
        $this->assertEqualsCanonicalizing([$groupa->id, $groupb->id], $access['groupids']);
    }

    public function test_separategroups_with_no_group_membership_returns_no_groups(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course([
            'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
        role_change_permission(
            $teacherrole->id,
            \context_course::instance($course->id),
            'moodle/site:accessallgroups',
            CAP_PREVENT
        );
        // No group memberships.

        $access = group_access::for_user($course->id, $teacher->id);
        $this->assertSame(group_access::MODE_NO_GROUPS, $access['mode']);
        $this->assertSame([], $access['groupids']);
    }

    public function test_userids_in_groups_returns_union(): void {
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
        // Carol in no group.

        $ids = group_access::userids_in_groups([$groupa->id, $groupb->id]);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id], $ids);

        // Empty input returns empty array (no DB hit).
        $this->assertSame([], group_access::userids_in_groups([]));
    }
}
