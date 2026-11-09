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
use local_oksigeniaeguahe\local\places;

/**
 * Finds where the centres and the towns of their families are, a few at a time each hour, as the rules of use of the
 * sources ask; what was found is kept.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locate_places extends \core\task\scheduled_task {
    /** @var int The questions at most each hour, for the whole site. */
    const MAX = 40;

    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_locate_places', 'local_oksigeniaeguahe');
    }

    /**
     * Finds what is missing, centre after centre, until the questions of this hour are spent.
     */
    public function execute() {
        $left = self::MAX;
        foreach (centres::all() as $centre) {
            if ($left < 1) {
                break;
            }
            $left -= places::locate($centre, $left, time());
        }
    }
}
