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
use local_oksigeniaeguahe\local\teamnotice;

/**
 * Every five minutes, the team hears of the families that came on their own since the last time. The first time for a
 * centre, it only marks the time: what came before is not sent all at once.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_team extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_notify_team', 'local_oksigeniaeguahe');
    }

    /**
     * Centre by centre, only the real ones.
     */
    public function execute() {
        global $DB;
        $now = time();
        foreach ($DB->get_records(centres::T, ['practice' => 0], 'id ASC', 'id') as $row) {
            $full = centres::get((int) $row->id);
            if ($full) {
                teamnotice::silences($full, $now);
            }
            $name = 'notifiedto_' . $row->id;
            $last = (int) get_config('local_oksigeniaeguahe', $name);
            set_config($name, $now, 'local_oksigeniaeguahe');
            if (!$last) {
                continue;
            }
            $centre = centres::get((int) $row->id);
            $new = teamnotice::arrived((int) $row->id, $last, $now);
            if ($centre && $new) {
                $sent = teamnotice::send($centre, $new);
                mtrace('Centre ' . $row->id . ': ' . count($new) . ' families, ' . $sent . ' notices.');
            }
        }
    }
}
