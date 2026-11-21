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

use local_oksigeniaeguahe\local\alumni;

/**
 * Looks for every adult of a centre in its list of former students, in the background: with thousands of families and
 * a list of thousands, writing what it finds does not fit in the page that imports the list.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_alumni extends \core\task\adhoc_task {
    /**
     * Looks them up.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $centreid = (int) ($data->centreid ?? 0);
        if ($centreid) {
            [$yes, $maybe] = alumni::match_centre($centreid);
            mtrace("Eguahe: centre $centreid, former students found $yes, to ask $maybe");
        }
    }

    /**
     * Queues the look-up of a centre, once: a second import while it waits needs no second task.
     *
     * @param int $centreid
     */
    public static function queue(int $centreid): void {
        $task = new self();
        $task->set_custom_data(['centreid' => $centreid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
