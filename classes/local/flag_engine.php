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

use block_atrisk\local\signal\assessment_miss;
use block_atrisk\local\signal\forum_silence;
use block_atrisk\local\signal\grade_trend;
use block_atrisk\local\signal\inactivity;
use block_atrisk\local\signal\signal_interface;
use block_atrisk\local\signal\stalled_completion;

/**
 * Orchestrates a complete at-risk evaluation pass for a course:
 *
 *   1. Discover the active cohort (gradebookroles, suspended/completed
 *      filters, optional group scope).
 *   2. Compute the calibration phase per student.
 *   3. Run each enabled signal classifier — but in PHASE_GATED only the
 *      inactivity signal runs (FR-43).
 *   4. Collapse per-signal results into per-student severity.
 *   5. Apply dismissal filtering (FR-72 dismiss-for-one-week).
 *   6. Compute peer-percentile on the worst-fired signal's metric.
 *   7. Sort by (severity DESC, triggered_count DESC, lastname ASC).
 *
 * The engine returns the full flagged list; pagination and top-N
 * truncation happen at render time.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flag_engine {
    /**
     * Evaluate a course and return its flagged students.
     *
     * @param int $courseid
     * @param array $config Engine configuration. Keys:
     *   - signals: array<string,bool> enable map for the five signals
     *   - thresholds: array<string,int> per-signal thresholds (days etc.)
     *   - groupid: int|int[]|null  group-scope filter for cohort + percentile (int[] = union of groups)
     *   - calibration: ['gatedweeks' => int, 'tentativeweeks' => int]
     * @param int|null $now Reference timestamp.
     * @return flagged_student[] Indexed numerically, sorted.
     */
    public function evaluate_course(int $courseid, array $config = [], ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $signalsenabled = $config['signals'] ?? [
            'inactivity' => true,
            'assessment_miss' => true,
            'grade_trend' => true,
            'stalled_completion' => true,
            'forum_silence' => false,
        ];
        $thresholds = $config['thresholds'] ?? [];
        $groupid = $config['groupid'] ?? null;
        $calibrationcfg = $config['calibration'] ?? ['gatedweeks' => 2, 'tentativeweeks' => 2];

        $userids = cohort::active($courseid, $groupid);
        if (empty($userids)) {
            return [];
        }

        // Per-user calibration phase.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, startdate', MUST_EXIST);
        $window = new calibration_window(
            (int) ($calibrationcfg['gatedweeks'] ?? 2),
            (int) ($calibrationcfg['tentativeweeks'] ?? 2)
        );
        $enrolments = $this->load_enrolments($courseid, $userids);
        $phases = [];
        foreach ($userids as $uid) {
            $anchor = calibration_window::anchor_for($course, $enrolments[$uid] ?? null);
            $phases[$uid] = $window->phase($anchor, $now);
        }

        // Collect raw per-user, per-signal results. In PHASE_GATED only
        // the inactivity signal is evaluated.
        $signalresults = [];
        $signalfactories = [
            'inactivity' => fn() => new inactivity(),
            'assessment_miss' => fn() => new assessment_miss(),
            'grade_trend' => fn() => new grade_trend(),
            'stalled_completion' => fn() => new stalled_completion(),
            'forum_silence' => fn() => new forum_silence(),
        ];

        foreach ($signalfactories as $name => $factory) {
            if (empty($signalsenabled[$name])) {
                continue;
            }
            // Build the user-set this signal applies to: all users in
            // tentative/confident phase; in gated phase only inactivity
            // runs.
            $uidsforsignal = [];
            foreach ($userids as $uid) {
                if ($phases[$uid] === calibration_window::PHASE_GATED && $name !== 'inactivity') {
                    continue;
                }
                $uidsforsignal[] = $uid;
            }
            if (empty($uidsforsignal)) {
                $signalresults[$name] = [];
                continue;
            }
            // Per-signal config carries that signal's thresholds plus
            // the cross-cutting breaks calendar (top-level $config key).
            // Signals that ignore breaks just don't read it.
            $signalconfig = $thresholds[$name] ?? [];
            $signalconfig['breaks'] = $config['breaks'] ?? [];
            $signalresults[$name] = $factory()->evaluate(
                $courseid,
                $uidsforsignal,
                $signalconfig,
                $now
            );
        }

        // Filter out dismissed flags.
        $dismissed = $this->load_dismissed($courseid, $userids, $now);

        // Build flagged_student aggregate per user.
        $flagged = [];
        foreach ($userids as $uid) {
            if (isset($dismissed[$uid])) {
                continue;
            }
            $triggered = [];
            foreach ($signalresults as $name => $perusermap) {
                if (!isset($perusermap[$uid])) {
                    continue;
                }
                $r = $perusermap[$uid];
                if ($r->triggered) {
                    $triggered[$name] = $r;
                }
            }
            $sev = severity::classify(count($triggered));
            if (!severity::is_flagged($sev)) {
                continue;
            }
            $flagged[] = new flagged_student(
                userid: (int) $uid,
                severity: $sev,
                phase: $phases[$uid],
                triggered: $triggered,
                worstpercentile: $this->worst_percentile($triggered, $signalresults),
            );
        }

        // Sort the most-at-risk first. Ordering criteria, applied in
        // priority order until one disambiguates:
        // 1. Severity rank DESC (red before yellow).
        // 2. Triggered-signal count DESC (more signals = more at risk).
        // 3. Worst peer-percentile ASC (lower percentile = more at risk;
        // null sorts last so students with measurable risk appear above
        // students whose metrics are undefined).
        // 4. Lastname/firstname ASC for stable ordering on full ties.
        $names = $this->load_names(array_map(fn($f) => $f->userid, $flagged));
        usort($flagged, function (flagged_student $a, flagged_student $b) use ($names) {
            $cmp = severity::rank($b->severity) <=> severity::rank($a->severity);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b->triggered_count() <=> $a->triggered_count();
            if ($cmp !== 0) {
                return $cmp;
            }
            $ap = $a->worstpercentile ?? PHP_INT_MAX;
            $bp = $b->worstpercentile ?? PHP_INT_MAX;
            $cmp = $ap <=> $bp;
            if ($cmp !== 0) {
                return $cmp;
            }
            $an = $names[$a->userid] ?? '';
            $bn = $names[$b->userid] ?? '';
            return strcasecmp($an, $bn);
        });
        return $flagged;
    }

    /**
     * Compute peer-percentile on the worst-fired signal's underlying
     * metric, normalized to a uniform "lower percentile = more at risk"
     * convention. Returns null when undefined.
     *
     * For high-is-bad metrics (inactivity, miss count) the raw rank from
     * {@see peer_percentile::rank()} grows with risk, so we invert to
     * 100 − rank before comparing. The signal class declares its direction
     * via {@see signal_interface::metric_direction()}.
     *
     * @param array $triggered Map signal-name → signal_result for fired signals.
     * @param array $allresults Map signal-name → per-user signal_result list.
     * @return int|null Worst (lowest, post-normalization) percentile, or null.
     */
    public function worst_percentile(array $triggered, array $allresults): ?int {
        // Map of name → fully qualified signal class. Mirrors the factory
        // map in evaluate_course() and is the only place we look up the
        // signal class for direction, so the duplication is acceptable.
        static $classes = [
            'inactivity' => inactivity::class,
            'assessment_miss' => assessment_miss::class,
            'grade_trend' => grade_trend::class,
            'stalled_completion' => stalled_completion::class,
            'forum_silence' => forum_silence::class,
        ];

        $worst = null;
        foreach ($triggered as $name => $r) {
            if ($r->metric === null) {
                continue;
            }
            $cohortvalues = [];
            foreach ($allresults[$name] ?? [] as $other) {
                if ($other->metric === null) {
                    continue;
                }
                $cohortvalues[] = $other->metric;
            }
            $rank = peer_percentile::rank($r->metric, $cohortvalues);
            if ($rank === null) {
                continue;
            }
            $cls = $classes[$name] ?? null;
            if ($cls !== null && $cls::metric_direction() === signal_interface::DIRECTION_DESC) {
                $rank = 100 - $rank;
            }
            if ($worst === null || $rank < $worst) {
                $worst = $rank;
            }
        }
        return $worst;
    }

    /**
     * Load the most-relevant user_enrolments row per user for calibration.
     *
     * @param int $courseid Course ID.
     * @param array $userids User IDs to load enrolments for.
     * @return array userid → user_enrolments row.
     */
    private function load_enrolments(int $courseid, array $userids): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $rows = $DB->get_records_sql(
            "SELECT ue.id, ue.userid, ue.timestart, ue.timecreated, ue.timeend
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.status = 0
             WHERE ue.userid {$insql} AND ue.status = 0
             ORDER BY ue.userid ASC, ue.timestart ASC",
            array_merge(['courseid' => $courseid], $inparams)
        );
        $byuser = [];
        foreach ($rows as $r) {
            // Earliest active enrolment wins.
            if (!isset($byuser[(int) $r->userid])) {
                $byuser[(int) $r->userid] = $r;
            }
        }
        return $byuser;
    }

    /**
     * Load the set of users currently dismissed in this course.
     *
     * @param int $courseid Course ID.
     * @param array $userids User IDs to check for dismissals.
     * @param int $now Reference timestamp.
     * @return array userid → present iff actively dismissed.
     */
    private function load_dismissed(int $courseid, array $userids, int $now): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT userid
             FROM {block_atrisk_dismissals}
             WHERE courseid = :courseid
               AND userid {$insql}
               AND dismissed_until > :now",
            array_merge(['courseid' => $courseid, 'now' => $now], $inparams)
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->userid] = true;
        }
        return $map;
    }

    /**
     * Load lowercase "lastname firstname" strings for stable sorting.
     *
     * @param array $userids User IDs to load names for.
     * @return array userid → lowercase "lastname firstname".
     */
    private function load_names(array $userids): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $rows = $DB->get_records_select(
            'user',
            "id {$insql}",
            $inparams,
            '',
            'id, firstname, lastname'
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->id] = strtolower($r->lastname . ' ' . $r->firstname);
        }
        return $map;
    }
}
