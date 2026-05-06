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
 * Daily task that removes expired dismissal rows. Retention is the
 * `dismissal_log_retention_days` site setting (default 365 days) past
 * the dismissal's `dismissed_until` — long enough that the
 * `signals_at_dismissal` JSON survives an end-of-term recalibration
 * pass. Active dismissal filtering is unaffected; the engine already
 * filters on `dismissed_until > now`, so old rows in the table just
 * sit there for analysis until pruned.
 *
 * Implements FR-72a / FR-111 / FR-72e.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_dismissals extends \core\task\scheduled_task {
    /**
     * Human-readable task name (shown on the scheduled-task admin page).
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_prune_dismissals', 'block_atrisk');
    }

    /**
     * Delete dismissal rows past the configured retention window.
     */
    public function execute(): void {
        global $DB;
        $days = (int) (get_config('block_atrisk', 'dismissal_log_retention_days') ?: 365);
        $cutoff = time() - $days * DAYSECS;
        $DB->delete_records_select(
            'block_atrisk_dismissals',
            'dismissed_until < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
