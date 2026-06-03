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
 * Tests for the plugin's declared capabilities.
 *
 * @package    block_atrisk
 * @coversNothing
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access_test extends advanced_testcase {
    /**
     * Every block must declare both addinstance and myaddinstance; the
     * latter was previously missing (moodle.org reviewer finding).
     */
    public function test_block_declares_addinstance_capabilities(): void {
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/blocks/atrisk/db/access.php');

        $this->assertArrayHasKey('block/atrisk:addinstance', $capabilities);
        $this->assertArrayHasKey('block/atrisk:myaddinstance', $capabilities);
        // The myaddinstance capability sits at system context and is cloned
        // from the standard dashboard-block permission.
        $this->assertSame(CONTEXT_SYSTEM, $capabilities['block/atrisk:myaddinstance']['contextlevel']);
        $this->assertSame(
            'moodle/my:manageblocks',
            $capabilities['block/atrisk:myaddinstance']['clonepermissionsfrom']
        );
    }

    /**
     * The capability must be installed (synced from access.php) and resolve
     * via the access API after upgrade.
     */
    public function test_myaddinstance_capability_is_installed(): void {
        $this->resetAfterTest();
        $this->assertNotEmpty(get_capability_info('block/atrisk:myaddinstance'));
    }
}
