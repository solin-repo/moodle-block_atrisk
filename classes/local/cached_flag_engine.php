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
 * Caching wrapper for {@see flag_engine}. Implements the lower-layer
 * cache from FR-124 with a 5-minute TTL keyed on (courseid,
 * signalconfig_hash, groupid).
 *
 * Notes:
 * - The cache's TTL is enforced via the {@see db/caches.php} 'rawsignals'
 *   definition (ttl=300).
 * - Dismissals do NOT invalidate the cache: the engine handles dismissal
 *   filtering at every call (see {@see flag_engine::evaluate_course()}).
 *   The cache stores RAW signal output; per-render dismissal state is
 *   applied via the engine's dismissal-load step which is fast enough
 *   to run uncached.
 * - {@see self::invalidate_course()} is called from the dismiss/preset
 *   endpoints to evict on configuration change.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cached_flag_engine {
    /**
     * Construct the cache-aware engine wrapper.
     *
     * @param flag_engine $inner The engine to wrap. Defaults to a fresh
     *        instance; injection point for tests.
     */
    public function __construct(
        /** @var flag_engine The wrapped engine. */
        private readonly flag_engine $inner = new flag_engine()
    ) {
    }

    /**
     * Cache-aware wrapper around {@see flag_engine::evaluate_course()}.
     *
     * @return flagged_student[]
     */
    public function evaluate_course(int $courseid, array $config = [], ?int $now = null): array {
        $key = self::cache_key($courseid, $config);
        $cache = \cache::make('block_atrisk', 'rawsignals');
        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }
        $value = $this->inner->evaluate_course($courseid, $config, $now);
        $cache->set($key, $value);
        return $value;
    }

    /**
     * Invalidate the cache for one course. Call from preset/dismiss
     * endpoints.
     */
    public static function invalidate_course(int $courseid): void {
        $cache = \cache::make('block_atrisk', 'rawsignals');
        // We don't track the exact key (signalconfig_hash varies); purge
        // the whole store for simplicity. With block_application+ttl=300
        // this is cheap.
        $cache->purge();
    }

    /**
     * Compute a stable cache key from the courseid + signal config.
     * The key format matches the FR-124 lower-layer key shape:
     * (courseid, signalconfig_hash, groupid).
     */
    private static function cache_key(int $courseid, array $config): string {
        $signature = [
            'signals' => $config['signals'] ?? [],
            'thresholds' => $config['thresholds'] ?? [],
            'calibration' => $config['calibration'] ?? [],
            'groupid' => $config['groupid'] ?? 0,
            'breaks' => $config['breaks'] ?? [],
        ];
        ksort($signature);
        return $courseid . '_' . sha1(json_encode($signature));
    }
}
