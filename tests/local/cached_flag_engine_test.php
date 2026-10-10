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
 * Tests for the MUC-backed engine wrapper (FR-124).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cached_flag_engine::class)]
final class cached_flag_engine_test extends advanced_testcase {
    /**
     * Results are served from the cache until the course is invalidated, and
     * a different configuration uses its own cache entry.
     */
    public function test_results_are_cached_per_config_until_invalidated(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => $now - 10 * WEEKSECS,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $config = engine_config::build_for_preset('default', null, (int) $course->id);
        $engine = new cached_flag_engine();

        // No access at all: inactivity flags the student.
        $first = $engine->evaluate_course((int) $course->id, $config, $now);
        $this->assertCount(1, $first);
        $this->assertEquals($student->id, $first[0]->userid);

        // The student becomes active; the cached result is still served.
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id, 'courseid' => $course->id, 'timeaccess' => $now,
        ]);
        $this->assertCount(1, $engine->evaluate_course((int) $course->id, $config, $now));

        // A different configuration is a different cache key: fresh evaluation.
        $other = engine_config::build_for_preset('fewer', null, (int) $course->id);
        $this->assertCount(0, $engine->evaluate_course((int) $course->id, $other, $now));

        // Invalidation evicts the stale entry for the original configuration.
        cached_flag_engine::invalidate_course((int) $course->id);
        $this->assertCount(0, $engine->evaluate_course((int) $course->id, $config, $now));
    }

    /**
     * An empty configuration works and uses the engine defaults.
     */
    public function test_default_config(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - 10 * WEEKSECS]);
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertCount(1, (new cached_flag_engine())->evaluate_course((int) $course->id));
    }
}
