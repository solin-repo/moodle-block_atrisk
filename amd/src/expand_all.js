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
 * Expand all / collapse all toggle for the in-block at-risk row list (FR-65).
 *
 * @module     block_atrisk/expand_all
 * @copyright  2026 Solin (Onno Schuit) <o.schuit@solin.nl>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = (rootSelector, expandLabel, collapseLabel) => {
    const root = document.querySelector(rootSelector);
    if (!root) {
        return;
    }
    const toggle = root.querySelector('.block_atrisk_expand_toggle');
    if (!toggle) {
        return;
    }
    toggle.addEventListener('click', (e) => {
        e.preventDefault();
        const list = root.querySelectorAll('details.block_atrisk_details');
        const anyClosed = Array.from(list).some((d) => !d.open);
        list.forEach((d) => {
            d.open = anyClosed;
        });
        toggle.textContent = anyClosed ? collapseLabel : expandLabel;
        toggle.dataset.state = anyClosed ? 'expanded' : 'collapsed';
    });
};
