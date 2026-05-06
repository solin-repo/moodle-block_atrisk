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
 * Calibration window phase computation — FR-43 to FR-47.
 *
 * Per-student timeline from {@code course.startdate} (when set), or
 * {@code user_enrolments.timestart} (when set), else
 * {@code user_enrolments.timecreated}. Per FR-46.
 *
 * Phases (default thresholds):
 *   weeks 1–2  → GATED      — only the inactivity signal runs (interpreted
 *                              as "no course access at all yet")
 *   weeks 3–4  → TENTATIVE  — all enabled signals run, displayed with a
 *                              tentative badge
 *   weeks 5+   → CONFIDENT  — full confidence, no badge
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calibration_window {
    /** Weeks 1–2: only the inactivity signal runs (FR-43). */
    public const PHASE_GATED = 'gated';
    /** Weeks 3–4: all signals run, displayed with a tentative badge. */
    public const PHASE_TENTATIVE = 'tentative';
    /** Week 5+: full confidence, no badge. */
    public const PHASE_CONFIDENT = 'confident';

    /**
     * Construct a calibration-window helper with optional phase lengths.
     *
     * @param int $gatedweeks Weeks of gated phase (default 2).
     * @param int $tentativeweeks Weeks of tentative phase (default 2).
     */
    public function __construct(
        /** @var int Weeks of gated phase. */
        private readonly int $gatedweeks = 2,
        /** @var int Weeks of tentative phase. */
        private readonly int $tentativeweeks = 2,
    ) {
    }

    /**
     * Determine the current phase given an anchor timestamp and reference
     * "now".
     *
     * @param int $anchor Per-student starting timestamp (course.startdate
     *        or user_enrolments.timestart fallback).
     * @param int $now Reference time.
     * @return string One of PHASE_GATED / PHASE_TENTATIVE / PHASE_CONFIDENT.
     */
    public function phase(int $anchor, int $now): string {
        if ($anchor <= 0) {
            // No reliable anchor — treat as confident (don't gate
            // signals indefinitely). This scenario should be unusual.
            return self::PHASE_CONFIDENT;
        }
        if ($now < $anchor) {
            // Before the course/enrolment has started.
            return self::PHASE_GATED;
        }
        $weekselapsed = (int) floor(($now - $anchor) / WEEKSECS);
        if ($weekselapsed < $this->gatedweeks) {
            return self::PHASE_GATED;
        }
        if ($weekselapsed < $this->gatedweeks + $this->tentativeweeks) {
            return self::PHASE_TENTATIVE;
        }
        return self::PHASE_CONFIDENT;
    }

    /**
     * Resolve the per-student calibration anchor per FR-46.
     *
     * Order of preference:
     *   1. course.startdate (when > 0)
     *   2. user_enrolments.timestart (when > 0)
     *   3. user_enrolments.timecreated
     *
     * @param \stdClass $course Course record (must have startdate column).
     * @param \stdClass|null $userenrolment Row from user_enrolments (or null
     *        when not available; falls back to course.startdate only).
     * @return int Anchor timestamp.
     */
    public static function anchor_for(\stdClass $course, ?\stdClass $userenrolment): int {
        $coursestart = (int) ($course->startdate ?? 0);
        if ($coursestart > 0) {
            return $coursestart;
        }
        if ($userenrolment !== null) {
            $timestart = (int) ($userenrolment->timestart ?? 0);
            if ($timestart > 0) {
                return $timestart;
            }
            return (int) ($userenrolment->timecreated ?? 0);
        }
        return 0;
    }
}
