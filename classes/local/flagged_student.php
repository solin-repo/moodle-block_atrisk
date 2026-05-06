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
 * Per-student outcome of a full at-risk evaluation pass.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flagged_student {
    /**
     * Construct a per-student flagged outcome.
     *
     * @param int $userid
     * @param string $severity One of severity::NONE / YELLOW / RED.
     * @param string $phase Calibration phase
     *               ({@see calibration_window::PHASE_GATED} etc.).
     * @param array<string,signal_result> $triggered Map signal-name → result
     *               for signals that fired.
     * @param int|null $worstpercentile Peer-percentile on the worst-fired
     *               signal's underlying metric (0–100). Null when no
     *               percentile computable.
     */
    public function __construct(
        /** @var int User ID. */
        public readonly int $userid,
        /** @var string Severity level. */
        public readonly string $severity,
        /** @var string Calibration phase. */
        public readonly string $phase,
        /** @var array<string,signal_result> Map of triggered signals. */
        public readonly array $triggered,
        /** @var int|null Worst peer-percentile across triggered signals. */
        public readonly ?int $worstpercentile,
    ) {
    }

    /**
     * Count of signals that triggered for this student.
     *
     * @return int
     */
    public function triggered_count(): int {
        return count($this->triggered);
    }

    /**
     * Whether the student has at least yellow severity.
     *
     * @return bool
     */
    public function is_flagged(): bool {
        return severity::is_flagged($this->severity);
    }
}
