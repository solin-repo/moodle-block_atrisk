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
 * Restore task for block_atrisk.
 *
 * The default restore_block_task handles configdata round-tripping —
 * that satisfies FR-114. No user data was backed up so nothing to
 * restore beyond block_instances.configdata.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore task for block_atrisk. Per-instance configdata round-trips via
 * the framework default; no plugin-owned data is in the backup.
 */
class restore_atrisk_block_task extends restore_block_task {
    /**
     * No restore settings beyond the framework defaults.
     */
    protected function define_my_settings(): void {
        // Intentionally empty.
    }

    /**
     * No custom restore steps — configdata is handled by the parent.
     */
    protected function define_my_steps(): void {
        // Intentionally empty.
    }

    /**
     * No file areas to restore.
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
     * No content to decode in restored backups.
     *
     * @return array
     */
    public static function define_decode_contents(): array {
        return [];
    }

    /**
     * No URL-decoding rules required.
     *
     * @return array
     */
    public static function define_decode_rules(): array {
        return [];
    }
}
