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

/**
 * Backup structure step for block_atrisk. Empty — per FR-115 we
 * deliberately do not back up the dismissals or grade-snapshots tables.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Empty structure step — owned tables are deliberately not backed up
 * per FR-115.
 */
class backup_atrisk_block_structure_step extends backup_block_structure_step {
    /**
     * Define the (placeholder) backup structure for the block.
     *
     * @return mixed
     */
    protected function define_structure() {
        // Define a placeholder root — no user data to back up.
        $atrisk = new backup_nested_element('atrisk', ['id'], []);
        return $this->prepare_block_structure($atrisk);
    }
}
