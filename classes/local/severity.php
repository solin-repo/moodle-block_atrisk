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
 * Severity tiering — FR-40 to FR-42.
 *
 * Mapping:
 *   0 triggered signals → not flagged (NONE)
 *   1 triggered signal  → yellow ("Watch")
 *   ≥ 2 triggered       → red    ("At risk")
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class severity {
    /** No flag. */
    public const NONE = 'none';
    /** One signal triggered — "Watch". */
    public const YELLOW = 'yellow';
    /** Two or more signals triggered — "At risk". */
    public const RED = 'red';

    /**
     * Classify a student given their triggered-signal count.
     *
     * @param int $triggered Number of signals that fired.
     * @return string One of NONE, YELLOW, RED.
     */
    public static function classify(int $triggered): string {
        if ($triggered <= 0) {
            return self::NONE;
        }
        if ($triggered === 1) {
            return self::YELLOW;
        }
        return self::RED;
    }

    /**
     * Is this severity flagged (i.e., should appear in the at-risk list)?
     *
     * @param string $level One of NONE, YELLOW, RED.
     * @return bool True for YELLOW or RED.
     */
    public static function is_flagged(string $level): bool {
        return $level === self::YELLOW || $level === self::RED;
    }

    /**
     * Numeric ordering for sort: red first, then yellow, then none.
     *
     * @param string $level One of NONE, YELLOW, RED.
     * @return int Sort rank (RED=2, YELLOW=1, NONE/other=0).
     */
    public static function rank(string $level): int {
        return match ($level) {
            self::RED => 2,
            self::YELLOW => 1,
            default => 0,
        };
    }
}
