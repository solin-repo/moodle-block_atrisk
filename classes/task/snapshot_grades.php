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

/**
 * Weekly task that snapshots course-total grades for active students in any
 * course where block_atrisk is instantiated. Pre-aggregation source for the
 * grade-trend signal — implements FR-117 to FR-119.
 *
 * The actual write logic lives in {@see \block_atrisk\local\grade_snapshot_writer}
 * so it can be unit-tested without invoking the scheduled-task harness.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot_grades extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_snapshot_grades', 'block_atrisk');
    }

    /**
     * Run the weekly snapshot pass via grade_snapshot_writer.
     */
    public function execute(): void {
        $writer = new \block_atrisk\local\grade_snapshot_writer();
        $writer->run(time());
    }
}
