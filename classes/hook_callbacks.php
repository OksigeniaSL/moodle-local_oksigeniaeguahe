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

namespace local_oksigeniaeguahe;

use local_oksigeniaeguahe\local\centres;

/**
 * The way in for the team of a centre, who are not administrators: «Admissions» in the main bar.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * «Admissions» in the main bar, for whoever works for a centre.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        global $USER;
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        if (!centres::for_user((int) $USER->id)) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('navname', 'local_oksigeniaeguahe'),
            new \moodle_url('/local/oksigeniaeguahe/index.php'),
            \navigation_node::TYPE_ROOTNODE,
            null,
            'local_oksigeniaeguahe'
        );
    }
}
