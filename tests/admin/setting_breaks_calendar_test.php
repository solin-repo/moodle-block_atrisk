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

namespace block_atrisk\admin;

use advanced_testcase;

/**
 * Tests for the breaks-calendar admin setting.
 *
 * @package    block_atrisk
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(setting_breaks_calendar::class)]
final class setting_breaks_calendar_test extends advanced_testcase {
    /**
     * Build the setting as settings.php does.
     *
     * @return setting_breaks_calendar
     */
    private function make_setting(): setting_breaks_calendar {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new setting_breaks_calendar('block_atrisk/breaks_calendar', 'Breaks', '', '');
    }

    /**
     * Empty input, comments and well-formed ranges are accepted.
     */
    public function test_valid_input_is_accepted(): void {
        $setting = $this->make_setting();
        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate("# Winter break\n2026-12-21, 2027-01-03"));
    }

    /**
     * Malformed input returns the parser's one-line error message.
     */
    public function test_invalid_input_returns_message(): void {
        $setting = $this->make_setting();
        $this->assertStringContainsString('Line 1', $setting->validate('2026-12-21'));
        $this->assertStringContainsString('start date is after end date', $setting->validate('2027-01-03, 2026-12-21'));
    }

    /**
     * Saving through the admin API stores valid input and rejects invalid input.
     */
    public function test_write_setting_validates(): void {
        $this->resetAfterTest();
        $setting = $this->make_setting();
        $this->assertSame('', $setting->write_setting('2026-12-21, 2027-01-03'));
        $this->assertSame('2026-12-21, 2027-01-03', get_config('block_atrisk', 'breaks_calendar'));

        $this->assertNotSame('', $setting->write_setting('not a date, 2027-01-03'));
        $this->assertSame('2026-12-21, 2027-01-03', get_config('block_atrisk', 'breaks_calendar'));
    }
}
