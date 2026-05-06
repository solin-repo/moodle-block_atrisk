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
 * Unit tests for the forum-silence signal.
 *
 * @package    block_atrisk
 * @covers     \block_atrisk\local\signal\forum_silence
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class forum_silence_test extends advanced_testcase {
    /**
     * Posts a forum message (creating a discussion if needed).
     */
    private function post_to_forum(int $forumid, int $userid, int $time): void {
        global $DB;
        // Find or create a discussion.
        $discussion = $DB->get_record('forum_discussions', ['forum' => $forumid], '*', IGNORE_MISSING);
        if ($discussion === false) {
            $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
            $discussionid = $DB->insert_record('forum_discussions', (object) [
                'course' => $forum->course,
                'forum' => $forumid,
                'name' => 'Test discussion',
                'firstpost' => 0,
                'userid' => $userid,
                'groupid' => -1,
                'assessed' => 0,
                'timemodified' => $time,
                'usermodified' => $userid,
                'timestart' => 0,
                'timeend' => 0,
            ]);
            $postid = $DB->insert_record('forum_posts', (object) [
                'discussion' => $discussionid,
                'parent' => 0,
                'userid' => $userid,
                'created' => $time,
                'modified' => $time,
                'mailed' => 0,
                'subject' => 'First post',
                'message' => 'Hello',
                'messageformat' => FORMAT_HTML,
                'messagetrust' => 0,
                'attachment' => '',
                'totalscore' => 0,
                'mailnow' => 0,
                'deleted' => 0,
                'privatereplyto' => 0,
                'wordcount' => 1,
                'charcount' => 5,
            ]);
            $DB->update_record('forum_discussions', (object) [
                'id' => $discussionid, 'firstpost' => $postid,
            ]);
        } else {
            $DB->insert_record('forum_posts', (object) [
                'discussion' => $discussion->id,
                'parent' => $discussion->firstpost,
                'userid' => $userid,
                'created' => $time,
                'modified' => $time,
                'mailed' => 0,
                'subject' => 'Re',
                'message' => 'Reply',
                'messageformat' => FORMAT_HTML,
                'messagetrust' => 0,
                'attachment' => '',
                'totalscore' => 0,
                'mailnow' => 0,
                'deleted' => 0,
                'privatereplyto' => 0,
                'wordcount' => 1,
                'charcount' => 5,
            ]);
        }
    }

    public function test_silence_with_active_peers_fires(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'type' => 'general',
        ]);
        $now = 1_700_000_000;

        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->post_to_forum($forum->id, $u->id, $now - 2 * DAYSECS);
            $this->post_to_forum($forum->id, $u->id, $now - 5 * DAYSECS);
            $peers[] = $u->id;
        }
        $silent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($silent->id, $course->id, 'student');

        $signal = new forum_silence();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$silent->id]),
            ['days' => 14],
            $now
        );

        $this->assertTrue($results[$silent->id]->triggered);
        $this->assertEquals(0, $results[$silent->id]->metric);
        foreach ($peers as $uid) {
            $this->assertFalse($results[$uid]->triggered);
        }
    }

    public function test_no_eligible_forum_disables_signal(): void {
        // FR-30: course has no eligible forum → signal disabled.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $now = 1_700_000_000;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new forum_silence();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_news_forum_alone_disables_signal(): void {
        // FR-28: announcements (forum.type = 'news') are excluded.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'type' => 'news',
        ]);
        $now = 1_700_000_000;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $signal = new forum_silence();
        $results = $signal->evaluate($course->id, [$student->id], ['days' => 14], $now);

        $this->assertFalse($results[$student->id]->triggered);
    }

    public function test_peer_median_zero_disables_signal(): void {
        // FR-27 floor: peer median ≥ 1 to fire.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'type' => 'general',
        ]);
        $now = 1_700_000_000;

        $userids = [];
        for ($i = 0; $i < 4; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $userids[] = $u->id;
        }

        $signal = new forum_silence();
        $results = $signal->evaluate($course->id, $userids, ['days' => 14], $now);

        foreach ($userids as $uid) {
            $this->assertFalse($results[$uid]->triggered);
        }
    }

    public function test_old_posts_outside_window_do_not_count(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'type' => 'general',
        ]);
        $now = 1_700_000_000;

        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->post_to_forum($forum->id, $u->id, $now - 2 * DAYSECS);
            $this->post_to_forum($forum->id, $u->id, $now - 5 * DAYSECS);
            $peers[] = $u->id;
        }
        $silent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($silent->id, $course->id, 'student');
        // Old post — 30 days ago.
        $this->post_to_forum($forum->id, $silent->id, $now - 30 * DAYSECS);

        $signal = new forum_silence();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$silent->id]),
            ['days' => 14],
            $now
        );

        $this->assertTrue($results[$silent->id]->triggered, 'old post does not save the silent student');
        $this->assertEquals(0, $results[$silent->id]->metric);
    }

    public function test_explanation_includes_peer_median_and_window(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'type' => 'general',
        ]);
        $now = 1_700_000_000;
        $peers = [];
        for ($i = 0; $i < 3; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $this->post_to_forum($forum->id, $u->id, $now - 2 * DAYSECS);
            $this->post_to_forum($forum->id, $u->id, $now - 5 * DAYSECS);
            $this->post_to_forum($forum->id, $u->id, $now - 10 * DAYSECS);
            $peers[] = $u->id;
        }
        $silent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($silent->id, $course->id, 'student');

        $signal = new forum_silence();
        $results = $signal->evaluate(
            $course->id,
            array_merge($peers, [$silent->id]),
            ['days' => 14],
            $now
        );

        $this->assertStringContainsString('no forum posts', $results[$silent->id]->explanation);
        $this->assertStringContainsString('14 days', $results[$silent->id]->explanation);
        $this->assertStringContainsString('peer median 3', $results[$silent->id]->explanation);
    }
}
