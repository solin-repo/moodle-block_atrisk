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
 * Parser and overlap math for institutional break calendars.
 *
 * The plugin lets admins (site-wide) and teachers (per-instance) declare
 * date ranges during which the course is on break — vacation weeks,
 * holidays, term gaps. Break ranges affect the at-risk engine in two
 * ways:
 *
 * - **Currently active break**: while now is inside any range, the block
 *   renders a paused banner instead of the flagged list — there is no
 *   meaningful "at risk" reading during a break.
 * - **Past break**: the engine subtracts each range's overlap with a
 *   metric's relevant time window. Inactivity subtracts the overlap
 *   from elapsed-time-since-last-access; windowed signals
 *   (forum-silence, stalled-completion, assessment-miss) extend their
 *   lookback by the overlap so the window reaches pre-break activity.
 *
 * Format: one break per line, ISO 8601 dates separated by a comma:
 * "YYYY-MM-DD, YYYY-MM-DD". Empty lines and lines starting with #
 * (comments) are ignored. Each parsed range covers the full local-time
 * day for both endpoints (00:00:00 of start through 23:59:59 of end).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class breaks {
    /**
     * Parse a multiline breaks-calendar string into a normalized list of
     * ranges. Overlapping or adjacent ranges are merged; the result is
     * sorted by start.
     *
     * @param string $text Stored breaks-calendar config.
     * @return array<int,array{start:int,end:int}> Sorted, merged ranges.
     * @throws \invalid_parameter_exception When a line is malformed.
     */
    public static function parse(string $text): array {
        $ranges = [];
        $lines = preg_split('/\R/', $text);
        foreach ($lines as $lineno => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $trimmed));
            if (count($parts) !== 2) {
                throw new \invalid_parameter_exception(
                    'Line ' . ($lineno + 1) . ': expected "YYYY-MM-DD, YYYY-MM-DD".'
                );
            }
            [$startstr, $endstr] = $parts;
            $start = self::parse_iso_date($startstr, $lineno + 1);
            $end = self::parse_iso_date($endstr, $lineno + 1);
            // Each end-date timestamp covers through 23:59:59 of that day.
            $end += DAYSECS - 1;
            if ($start > $end) {
                throw new \invalid_parameter_exception(
                    'Line ' . ($lineno + 1) . ": start date is after end date."
                );
            }
            $ranges[] = ['start' => $start, 'end' => $end];
        }
        return self::merge_ranges($ranges);
    }

    /**
     * Validate a breaks-calendar string. Returns null on success, or a
     * one-line error message suitable for form-level display.
     *
     * @param string $text
     * @return string|null
     */
    public static function validate(string $text): ?string {
        try {
            self::parse($text);
            return null;
        } catch (\invalid_parameter_exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Sum, in seconds, the overlap between the given ranges and a
     * specific time window.
     *
     * @param array<int,array{start:int,end:int}> $ranges Output of {@see parse()}.
     * @param int $windowstart Window start (inclusive).
     * @param int $windowend Window end (inclusive).
     * @return int Total overlap in seconds (zero if no overlap).
     */
    public static function overlap_seconds(array $ranges, int $windowstart, int $windowend): int {
        if ($windowstart > $windowend) {
            return 0;
        }
        $total = 0;
        foreach ($ranges as $r) {
            $lo = max($r['start'], $windowstart);
            $hi = min($r['end'], $windowend);
            if ($hi > $lo) {
                $total += $hi - $lo;
            }
        }
        return $total;
    }

    /**
     * Convenience wrapper around {@see overlap_seconds()} returning days
     * (rounded down) — the unit signals work in.
     */
    public static function overlap_days(array $ranges, int $windowstart, int $windowend): int {
        return (int) floor(self::overlap_seconds($ranges, $windowstart, $windowend) / DAYSECS);
    }

    /**
     * Find the first range containing $now (if any). Used to drive the
     * "Heuristics paused for break" render state.
     *
     * @param array<int,array{start:int,end:int}> $ranges
     * @param int $now
     * @return array{start:int,end:int}|null
     */
    public static function active_at(array $ranges, int $now): ?array {
        foreach ($ranges as $r) {
            if ($now >= $r['start'] && $now <= $r['end']) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Merge overlapping or adjacent (touching) ranges into a sorted,
     * non-overlapping list.
     *
     * @param array<int,array{start:int,end:int}> $ranges
     * @return array<int,array{start:int,end:int}>
     */
    private static function merge_ranges(array $ranges): array {
        if (count($ranges) <= 1) {
            return $ranges;
        }
        usort($ranges, fn($a, $b) => $a['start'] <=> $b['start']);
        $merged = [$ranges[0]];
        for ($i = 1; $i < count($ranges); $i++) {
            $last = &$merged[count($merged) - 1];
            $r = $ranges[$i];
            // Adjacent ranges (last ends just before r starts) also merge.
            if ($r['start'] <= $last['end'] + 1) {
                $last['end'] = max($last['end'], $r['end']);
            } else {
                $merged[] = $r;
            }
        }
        return $merged;
    }

    /**
     * Parse a single ISO date (YYYY-MM-DD) into a midnight timestamp in
     * the server's local timezone. Throws on malformed input.
     */
    private static function parse_iso_date(string $iso, int $lineno): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            throw new \invalid_parameter_exception(
                "Line {$lineno}: '{$iso}' is not a valid YYYY-MM-DD date."
            );
        }
        $ts = strtotime($iso . ' 00:00:00');
        if ($ts === false) {
            throw new \invalid_parameter_exception(
                "Line {$lineno}: '{$iso}' could not be parsed as a date."
            );
        }
        return $ts;
    }
}
