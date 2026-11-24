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

namespace local_oksigeniaeguahe\task;

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\workdays;

/**
 * Every night, the days each centre closes, from the calendar it gives.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_closed extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_fetch_closed', 'local_oksigeniaeguahe');
    }

    /**
     * Centre by centre, only the real ones that give a calendar.
     */
    public function execute() {
        global $DB;
        foreach ($DB->get_records_select(centres::T, "practice = 0 AND closedics <> ''", [], 'id ASC') as $centre) {
            mtrace('Centre ' . $centre->id . ': ' . (workdays::fetch($centre) ? 'read.' : 'could not be read.'));
        }
    }
}
