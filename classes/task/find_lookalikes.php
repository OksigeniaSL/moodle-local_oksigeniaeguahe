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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lookalike;

/**
 * Every ten minutes, the households of each centre that may be the same family, compared ahead and kept: the inbox of a
 * large centre reads them ready instead of comparing them while a person waits.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_lookalikes extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_find_lookalikes', 'local_oksigeniaeguahe');
    }

    /**
     * Compares them, centre by centre: only real centres with a household in the window.
     */
    public function execute() {
        global $DB;
        $since = time() - lookalike::NEAR;
        $centres = $DB->get_fieldset_sql(
            'SELECT DISTINCT h.centreid
               FROM {' . households::T . '} h
               JOIN {' . centres::T . '} c ON c.id = h.centreid
              WHERE c.practice = 0 AND h.timecreated >= :since',
            ['since' => $since]
        );
        foreach ($centres as $centreid) {
            lookalike::refresh((int) $centreid, $since);
        }
    }
}
