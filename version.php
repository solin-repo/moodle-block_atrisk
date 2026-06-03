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
 * Plugin version metadata for block_atrisk (Solin Early Warning).
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_atrisk';
$plugin->version   = 2026060300;
$plugin->release   = '1.0.1';
$plugin->maturity  = MATURITY_BETA;
$plugin->requires  = 2025100600; // Moodle 5.1 baseline; blocks install on 5.0 (untested).
$plugin->supported = [501, 502]; // Combined 5.1 -> 5.2 range; excludes 5.0 (FR-181).
