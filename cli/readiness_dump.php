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
 * CLI variant of the readiness data export.
 *
 * Same generator as the admin web page; outputs JSON to stdout.
 *
 *     php blocks/atrisk/cli/readiness_dump.php > readiness.json
 *     php blocks/atrisk/cli/readiness_dump.php --include-names --all-courses
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'include-names' => false,
        'all-courses' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL, $unrecognized)));
}

if ($options['help']) {
    cli_writeln(<<<EOT
Generate a JSON readiness data export.

Usage:
  php blocks/atrisk/cli/readiness_dump.php [OPTIONS] > readiness.json

Options:
  -h, --help          Show this help.
  --include-names     Include course shortnames and fullnames.
                      Default: redacted to numeric IDs.
  --all-courses       Include all courses, not just those with the block.

Output: pretty-printed JSON to stdout. The file is intended for review
before sharing; no personal data is included by default.
EOT);
    exit(0);
}

$report = (new \block_atrisk\local\readiness_report())->build(
    (bool) $options['include-names'],
    (bool) $options['all-courses']
);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
