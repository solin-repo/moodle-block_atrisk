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

namespace block_atrisk\task;

use block_atrisk\local\course_shape;

/**
 * Daily task that classifies each course where block_atrisk is instantiated
 * and which has at least one active student-role enrolment into one of the
 * FR-106 operational shapes (cohort_paced, self_paced, one_shot_compliance,
 * blended, unknown). Output is stored in `block_atrisk_course_shape` and
 * read by the flag engine to apply per-shape signal adjustments (FR-106d).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class detect_course_shapes extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_detect_course_shapes', 'block_atrisk');
    }

    /**
     * Re-detect shape for every course currently hosting the block.
     */
    public function execute(): void {
        $courseids = course_shape::courses_with_block();
        foreach ($courseids as $courseid) {
            try {
                course_shape::detect_and_store($courseid);
            } catch (\Throwable $e) {
                // One bad course shouldn't sink the run. Log and continue —
                // the next daily pass will retry.
                mtrace("block_atrisk: course-shape detection failed for course {$courseid}: " . $e->getMessage());
            }
        }
    }
}
