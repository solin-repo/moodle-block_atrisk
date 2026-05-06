# Changelog

All notable changes to `block_atrisk` (Solin Early Warning) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-05-06 (initial public release)

### Added — breaks calendar (May 2026)
- **Site-level breaks calendar** (`breaks_calendar` setting) — admin enters institutional holidays once per academic year, ISO date ranges, one per line. Validated server-side with line-numbered error messages.
- **Per-instance `course_breaks` field** — additive to the site list, for course-specific pauses (instructor sick, local closure, retroactive fix after a forgotten pause).
- **"Pause for one week" inline link** in the block header for users with `block/atrisk:configureblock` — appends today, today+6d to the per-instance list. **"Resume now"** in the paused banner removes any per-instance range currently in effect.
- **Engine dampening** — when a configured break falls inside a signal's lookback, the engine subtracts the overlap. Inactivity doesn't count holiday idle time; forum-silence and stalled-completion lookbacks are extended past the break to reach pre-break activity; assessment-miss does the same plus suppresses activities whose `completionexpected` falls inside a break (you can't reasonably miss a quiz over Christmas). Grade-trend is naturally self-correcting.
- **Retroactive support** — entering past dates in the breaks calendar dampens the next render's metrics retrospectively, so a teacher returning from a forgotten pause to a flood of false positives can fix it in one form save.
- **Transparency note** — when past breaks are dampening the current render, a small line above the list explains why fewer students may be flagged.
- **Spec FR-105** (with sub-clauses) and dedicated README "Holidays and term breaks" subsection.
- 130 PHPUnit tests (added 16 for the breaks parser + integration); 7 Behat scenarios.

### Added (post-v1 UX iteration, May 2026)
- **Collapse rows** (FR-65): each flagged-student row renders collapsed by default, showing only severity + name + tentative badge. Native `<details>`/`<summary>` for accessibility and no-JS fallback. Per-row toggle plus a header "Expand all / Collapse all" control. On `view.php`, the expand-all state persists across pagination via `?expandall=1`.
- **Per-instance "Number of students to show"** with new default 12 (was site-wide 5). Block edit form lets a teacher override.
- **Per-instance forum-silence override**: tri-state select (Inherit site default / Force on / Force off) so courses that use forums as a participation channel can opt in (or out) regardless of the institution-wide default.
- **Per-instance peer-comparison scope** (FR-102): default whole-course; opt into "viewer's groups only" for courses where groups are structurally separate.
- **Group-aware visibility** (FR-56 v1): under SEPARATEGROUPS without `accessallgroups`, the rendered list filters to the viewer's accessible groups. Distinct empty state when the viewer is in zero groups.
- **Show: More / Default / Fewer** replaces the heuristic-perspective "Loose / Balanced / Strict" preset. Tooltips spell out the effect on each criterion. Legacy preset names aliased for stored block-instance configdata.
- **Sensitivity control on `view.php`** so the paginated full list runs against the same engine config as the in-block view (was silently using defaults). Preset action redirects back to `view.php` rather than the course homepage.
- All instance-level overrides grouped under a single collapsed "Advanced — overrides defaults" fieldset.

### Changed
- **Sort order** now uses worst peer-percentile ASC as a tiebreaker before name, so list order matches the displayed percentile (previously: a less-at-risk student could appear above a more-at-risk one purely on alphabetical name order).
- **Peer-percentile direction** normalised: signal classes declare `metric_direction()`, and the engine inverts ranks for high-is-bad signals (inactivity, miss count) so the displayed percentile uniformly reads "lower = more at risk".
- **Assessment-miss preset thresholds reversed** (10/14/21 → 21/14/10) so the "More = more flags" invariant holds across all three preset-tunable signals; longer assessment-miss window means more eligible activities and therefore more potential misses.
- **Cohort terminology** disambiguated everywhere user-visible (lang strings, README, spec) to "active enrolment" / "peer group" / "the class" — avoiding collision with the Moodle cohort entity (`mdl_cohort`). The internal `\block_atrisk\local\cohort` class kept the name (namespace-disambiguated); a top-level docblock spells out the distinction.

### Fixed
- **Cache thrash on preset switch**: removed `cached_flag_engine::invalidate_course()` from the preset endpoint. Each preset hashes into its own cache key, so switching between presets within the 5-minute TTL is now a cache hit rather than a recompute.
- `db/upgrade.php` purges the rawsignals MUC cache for version steps that change cached output shape (percentile direction, sort order), so existing installs don't keep serving stale data.
- `view.php` previously ran the engine with empty config, producing flag sets that could disagree with the in-block view. Now uses the same `engine_config::build_for_preset()` path as the block.

### Tests
- Added: percentile direction inversion (high-is-bad and low-is-bad cases), sort-tiebreaker (Mira/Naima reproduction), `cohort::active()` array-of-groupids union, `engine_config` instance overrides, `group_access` classifier across group modes, Behat scenario for collapsed-by-default rows + Expand all.
- Total: **114 PHPUnit tests** (232 assertions) + **6 Behat scenarios** (70 steps). 0 phpcs against `moodle-cs` v3.7.0.

### Multi-version verification
- Cloned and installed Moodle **5.1.4+**, **5.2+**, and **4.5.11+ LTS** locally; ran the full test suite against each.
- **All 114 PHPUnit tests pass on every version with zero compat shims.** The plugin's API surface — namespaced classes, MUC cache, Privacy API, backup/restore, `signal_interface` contract, group library calls — is stable across the LTS line.
- Per-version branches pushed: `MOODLE_405_STABLE` (`requires=2024100700`, `supported=[405,405]`) and `MOODLE_501_STABLE` (`supported=[501,501]`). `main` remains the 5.2 lead.

### Cross-database verification
- **PostgreSQL 16** — primary dev DB across all three Moodle versions (5.2, 5.1, 4.5). 114/114 tests on each.
- **MySQL 8.4 LTS** — verified via a dedicated database in the existing `mysql84-icm` Docker container. Parallel install at `_testenv/moodle-5.2-mysql/` with `dbtype=mysqli`. **114/114 PHPUnit tests pass on Moodle 5.2 + MySQL 8.4** (~45s; MySQL's transaction-rollback test isolation is slower than PostgreSQL's ~13s but fully functional).
- MariaDB shares Moodle's `mysqli` driver, so the MySQL run is a reasonable proxy; an explicit MariaDB run can be added if a customer requests it.

## [0.1.0] — May 2026 (internal v1 milestone, not publicly released)

### Added
- Initial v1 implementation targeting Moodle 5.2.
- Five-signal heuristic surface: inactivity, assessment-miss, grade-trend, stalled-completion (peer-relative), and forum-silence (opt-in).
- Per-block sensitivity preset (Loose / Balanced / Strict) — inline control.
- Severity tiering (one triggered → yellow; two or more → red).
- Calibration window (gated weeks 1–2, tentative weeks 3–4, confident week 5+); per-student timeline derives from `course.startdate` then `user_enrolments.timestart` then `timecreated`.
- Active-enrolment floors: hard floor 10 (peer-relative signals auto-disable), soft floor 20 (small-class caveat).
- Dismiss-for-one-week with auto-prune via daily scheduled task; course-wide across block instances.
- Weekly course-total grade snapshot task feeds the grade-trend signal.
- MUC cache wrapping the flag engine (`rawsignals` cache_application, ttl=300).
- Privacy API provider for both owned tables; `dismissed_by` anonymized to 0 rather than dropped on teacher delete.
- Block backup/restore round-tripping per-instance configuration; dismissals and snapshots intentionally excluded from backup.
- Top-N display in the block (default 5); paginated "view all" at 25 rows/page.
- 95 PHPUnit tests, all green on Moodle 5.2 + PHP 8.4 + PostgreSQL 16.
