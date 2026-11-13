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
use local_oksigeniaeguahe\local\roster;

/**
 * Keeps up to date, each night, the families already at each centre that brings them from its roll (the common family
 * record): new ones, and students who moved to another course.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_roster extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sync_roster', 'local_oksigeniaeguahe');
    }

    /**
     * Brings them, centre by centre.
     */
    public function execute() {
        if (!roster::available()) {
            return;
        }
        foreach (centres::all() as $centre) {
            if (roster::source($centre)) {
                try {
                    roster::sync($centre, time());
                } catch (\Throwable $e) {
                    mtrace('Eguahe: the roll of centre ' . $centre->id . ' failed: ' . $e->getMessage());
                }
            }
        }
    }
}
