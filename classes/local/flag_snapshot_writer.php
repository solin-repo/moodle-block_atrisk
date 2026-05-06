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
 * Weekly snapshot writer for the flag-history log (FR-119 cluster).
 *
 * For each course where block_atrisk is instantiated, runs the engine and
 * writes one row per (courseid, userid, snapshotweek, signal_name) for
 * each currently-flagged student. The relational shape (one row per
 * fired signal) lets analysts aggregate by signal directly, GROUP BY
 * across the term, or reconstruct the flag with `(courseid, userid,
 * snapshotweek)` to get severity (1 = yellow, 2+ = red) and the full
 * signal set via `array_agg(signal_name)`.
 *
 * Opt-in: gated by the `flag_logging_enabled` site setting (default off).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flag_snapshot_writer {
    /**
     * Run a snapshot pass at the given reference time.
     *
     * @param int $now Reference timestamp.
     * @return int Number of rows written or updated.
     */
    public function run(int $now): int {
        if (!(bool) get_config('block_atrisk', 'flag_logging_enabled')) {
            return 0;
        }
        $written = 0;
        foreach ($this->courses_with_block() as $courseid) {
            $written += $this->snapshot_course((int) $courseid, $now);
        }
        return $written;
    }

    /**
     * Snapshot one course's flagged students. Idempotent within a week —
     * existing rows with the same unique key are UPDATEd rather than
     * re-INSERTed.
     *
     * @param int $courseid
     * @param int $now Reference timestamp.
     * @return int Rows written or updated.
     */
    public function snapshot_course(int $courseid, int $now): int {
        global $DB;

        $week = grade_snapshot_writer::iso_week($now);
        $instanceconfig = $this->load_block_instance_config($courseid);
        $preset = $instanceconfig->preset ?? 'default';
        $config = engine_config::build_for_preset($preset, $instanceconfig, $courseid);

        $engine = new flag_engine();
        $flagged = $engine->evaluate_course($courseid, $config, $now);
        if (empty($flagged)) {
            return 0;
        }

        $written = 0;
        foreach ($flagged as $f) {
            foreach ($f->triggered as $signalname => $result) {
                $existing = $DB->get_record('block_atrisk_flag_snapshots', [
                    'courseid' => $courseid,
                    'userid' => $f->userid,
                    'snapshotweek' => $week,
                    'signal_name' => $signalname,
                ], 'id', IGNORE_MISSING);
                $row = (object) [
                    'courseid' => $courseid,
                    'userid' => $f->userid,
                    'snapshotweek' => $week,
                    'signal_name' => $signalname,
                    'metric_value' => $result->metric === null ? null : (float) $result->metric,
                    'percentile' => $f->worstpercentile,
                    'preset' => $preset,
                    'timecreated' => $now,
                ];
                if ($existing !== false) {
                    $row->id = $existing->id;
                    $DB->update_record('block_atrisk_flag_snapshots', $row);
                } else {
                    $DB->insert_record('block_atrisk_flag_snapshots', $row);
                }
                $written++;
            }
        }
        return $written;
    }

    /**
     * Prune rows older than the configured retention window.
     *
     * @param int $now Reference timestamp.
     * @return int Rows deleted.
     */
    public function prune(int $now): int {
        global $DB;
        $days = (int) (get_config('block_atrisk', 'flag_log_retention_days') ?: 365);
        $cutoff = $now - $days * DAYSECS;
        return $DB->delete_records_select(
            'block_atrisk_flag_snapshots',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        ) ? 1 : 0;
    }

    /**
     * Distinct courseids where any block_atrisk instance exists.
     *
     * @return array<int>
     */
    private function courses_with_block(): array {
        global $DB;
        $sql = "SELECT DISTINCT c.id
                FROM {course} c
                JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :clevel
                JOIN {block_instances} bi ON bi.parentcontextid = ctx.id
                WHERE bi.blockname = 'atrisk'";
        return $DB->get_fieldset_sql($sql, ['clevel' => CONTEXT_COURSE]);
    }

    /**
     * Return the per-instance configdata for a course's atrisk block, or
     * an empty stdClass if none configured. (One block per course is
     * enforced via instance_allow_multiple = false.)
     */
    private function load_block_instance_config(int $courseid): \stdClass {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT bi.configdata
               FROM {block_instances} bi
               JOIN {context} ctx ON ctx.id = bi.parentcontextid
              WHERE bi.blockname = 'atrisk'
                AND ctx.contextlevel = :clevel
                AND ctx.instanceid = :courseid",
            ['clevel' => CONTEXT_COURSE, 'courseid' => $courseid]
        );
        if ($row === false || empty($row->configdata)) {
            return new \stdClass();
        }
        $decoded = unserialize(base64_decode($row->configdata));
        return is_object($decoded) ? $decoded : new \stdClass();
    }
}
