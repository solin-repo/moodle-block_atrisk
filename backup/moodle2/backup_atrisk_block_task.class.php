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
 * Backup task for block_atrisk.
 *
 * The default backup_block_task handles block_instances.configdata
 * round-tripping (sensitivity preset, threshold overrides, peer-comparison
 * scope) — that satisfies FR-114.
 *
 * Per FR-115 the plugin's owned tables (block_atrisk_dismissals and
 * block_atrisk_grade_snapshots) are intentionally NOT included in
 * backup: dismissals are time-bounded teacher actions that lose meaning
 * in a different course context; grade snapshots are re-derived by the
 * next weekly task run.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/atrisk/backup/moodle2/backup_atrisk_stepslib.php');

/**
 * Backup task for block_atrisk. Handles per-instance configdata via the
 * default block_task framework; owned tables are intentionally excluded.
 */
class backup_atrisk_block_task extends backup_block_task {
    /**
     * No backup settings beyond the framework defaults.
     */
    protected function define_my_settings(): void {
        // Intentionally empty.
    }

    /**
     * Add the (essentially empty) structure step.
     */
    protected function define_my_steps(): void {
        $this->add_step(new backup_atrisk_block_structure_step('atrisk_structure', 'atrisk.xml'));
    }

    /**
     * No file areas owned by the block.
     *
     * @return array
     */
    public function get_fileareas(): array {
        return [];
    }

    /**
     * No encoded configdata attributes.
     *
     * @return array
     */
    public function get_configdata_encoded_attributes(): array {
        return [];
    }

    /**
     * No content-link encoding required for this block.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content): string {
        return $content;
    }
}
