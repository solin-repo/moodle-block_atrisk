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
 * Per-student outcome of evaluating one signal.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class signal_result {
    /**
     * Construct a per-student signal result.
     *
     * @param bool $triggered Whether the signal fired for this student.
     * @param string $explanation One-line human-readable reason (already
     *               localised). Empty when not triggered.
     * @param int|float|null $metric The underlying metric value (e.g. days
     *               since last access, count of completions). Used by the
     *               peer-percentile helper. Null = "no information".
     */
    public function __construct(
        /** @var bool Whether the signal fired for this student. */
        public readonly bool $triggered,
        /** @var string One-line human-readable reason. */
        public readonly string $explanation,
        /** @var int|float|null Underlying metric value. */
        public readonly int|float|null $metric,
    ) {
    }

    /**
     * Convenience factory for the "not triggered" case.
     *
     * @param int|float|null $metric Optional metric value.
     * @return self
     */
    public static function not_triggered(int|float|null $metric = null): self {
        return new self(false, '', $metric);
    }
}
