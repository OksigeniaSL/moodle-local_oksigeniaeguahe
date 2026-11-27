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

use local_oksigeniaeguahe\local\phonebatches;

/**
 * Every five minutes, the next batch of each letter that goes by phone in batches, when it is its time and nothing says to
 * stop.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_phone_batches extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_send_phone_batches', 'local_oksigeniaeguahe');
    }

    /**
     * Sends what is due, and says how many families, never which.
     */
    public function execute() {
        mtrace('Letters by phone in batches: ' . phonebatches::due(time()) . ' families now.');
    }
}
