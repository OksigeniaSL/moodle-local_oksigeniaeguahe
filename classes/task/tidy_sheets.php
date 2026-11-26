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
use local_oksigeniaeguahe\local\teamnotes;

/**
 * Every night, after the sheets of the team were sent again: what a sheet read twice left twice goes, and the families that
 * were only called with nobody answering wait again. Nobody has to run anything.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tidy_sheets extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_tidy_sheets', 'local_oksigeniaeguahe');
    }

    /**
     * Centre by centre, only the real ones; it says how many, never who.
     */
    public function execute() {
        global $DB;
        $now = time();
        foreach ($DB->get_records(centres::T, ['practice' => 0], 'id ASC', 'id') as $row) {
            $centre = centres::get((int) $row->id);
            if (!$centre) {
                continue;
            }
            $repeated = teamnotes::repeated($centre, '', $now - 26 * HOURSECS, $now, true);
            $waiting = teamnotes::unreached($centre, true);
            if ($repeated['lines'] || $waiting) {
                mtrace('Centre ' . $centre->id . ': repeated lines taken out ' . count($repeated['lines']) .
                    ', waiting again ' . count($waiting));
            }
        }
    }
}
