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

namespace block_atrisk;

use advanced_testcase;

/**
 * Tests for the in-block visible row count resolution and clamp (#4).
 *
 * @package    block_atrisk
 * @coversNothing
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class topn_test extends advanced_testcase {
    /**
     * resolve_topn() applies the site default, honors a positive
     * per-instance override, falls back on a non-positive override, and
     * clamps both the override and the site default to MAX_TOPN so the
     * in-block list can never be unbounded.
     */
    public function test_resolve_topn_defaults_overrides_and_clamps(): void {
        global $CFG;
        require_once($CFG->dirroot . '/lib/blocklib.php');
        require_once($CFG->dirroot . '/blocks/atrisk/block_atrisk.php');
        $this->resetAfterTest();

        set_config('display_top_n', 12, 'block_atrisk');

        // No per-instance config → site default.
        $this->assertSame(12, \block_atrisk::resolve_topn(null));

        // Positive per-instance override is honored.
        $this->assertSame(5, \block_atrisk::resolve_topn((object) ['topn' => 5]));

        // Non-positive override falls back to the site default.
        $this->assertSame(12, \block_atrisk::resolve_topn((object) ['topn' => 0]));

        // An override above the cap is clamped.
        $this->assertSame(
            \block_atrisk::MAX_TOPN,
            \block_atrisk::resolve_topn((object) ['topn' => 100000])
        );

        // A site default above the cap is clamped too.
        set_config('display_top_n', 100000, 'block_atrisk');
        $this->assertSame(\block_atrisk::MAX_TOPN, \block_atrisk::resolve_topn(null));

        // With no site default at all, it falls back to 12 (within the cap).
        unset_config('display_top_n', 'block_atrisk');
        $this->assertSame(12, \block_atrisk::resolve_topn(null));
    }
}
