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

/**
 * Persistence and lifecycle for "dismiss flag for one week" actions.
 *
 * Implements FR-72 (and FR-72a auto-prune timing). A dismissal is scoped
 * per (courseid, userid) — applies to all block instances in the course.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dismissal_service {
    /** The dismissal window length, in seconds (FR-72). */
    public const ONE_WEEK = 7 * DAYSECS;

    /**
     * Dismiss the flag for one week. Idempotent: a second call within the
     * same active window updates the existing record's dismissed_until.
     *
     * @param int $courseid
     * @param int $userid Flagged learner.
     * @param int $teacherid Teacher performing the dismissal.
     * @param int|null $now Reference timestamp (default: time()).
     * @param array|null $signalsatdismissal Optional snapshot of the flag
     *        state at click time, JSON-encoded into the new column for
     *        end-of-term recalibration analysis (FR-72d). Expected shape:
     *        ['signals' => ['inactivity', ...], 'severity' => 'yellow',
     *        'preset' => 'default'].
     * @return int The dismissal record id.
     */
    public static function dismiss(
        int $courseid,
        int $userid,
        int $teacherid,
        ?int $now = null,
        ?array $signalsatdismissal = null
    ): int {
        global $DB;
        $now = $now ?? time();
        $until = $now + self::ONE_WEEK;
        $payload = $signalsatdismissal !== null
            ? json_encode($signalsatdismissal, JSON_UNESCAPED_SLASHES)
            : null;

        $existing = $DB->get_record('block_atrisk_dismissals', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], '*', IGNORE_MULTIPLE);

        if ($existing !== false) {
            $DB->update_record('block_atrisk_dismissals', (object) [
                'id' => $existing->id,
                'dismissed_by' => $teacherid,
                'dismissed_at' => $now,
                'dismissed_until' => $until,
                'signals_at_dismissal' => $payload,
            ]);
            return (int) $existing->id;
        }
        return $DB->insert_record('block_atrisk_dismissals', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'dismissed_by' => $teacherid,
            'dismissed_at' => $now,
            'dismissed_until' => $until,
            'signals_at_dismissal' => $payload,
        ]);
    }

    /**
     * Undo a dismissal — remove the row entirely so the flag immediately
     * re-appears.
     */
    public static function undo(int $courseid, int $userid): void {
        global $DB;
        $DB->delete_records('block_atrisk_dismissals', [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
    }

    /**
     * Is this user's flag currently dismissed in this course?
     */
    public static function is_dismissed(int $courseid, int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        return $DB->record_exists_select(
            'block_atrisk_dismissals',
            'courseid = :courseid AND userid = :userid AND dismissed_until > :now',
            ['courseid' => $courseid, 'userid' => $userid, 'now' => $now]
        );
    }
}
