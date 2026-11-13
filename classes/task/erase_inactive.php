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

use local_oksigeniaeguahe\local\households;

/**
 * Keeps the promise families read about their data: a household with no activity for the months its centre says is
 * erased, as if it had asked for it. Households with a child enrolled stay: their data passes to the record of
 * families of the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class erase_inactive extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_erase_inactive', 'local_oksigeniaeguahe');
    }

    /**
     * Erases what is past its time.
     */
    public function execute() {
        foreach (households::expired(time()) as $household) {
            // One family that fails to go never stops the others.
            try {
                households::erase($household);
            } catch (\Throwable $e) {
                mtrace('Eguahe: household ' . $household->id . ' could not be erased: ' . $e->getMessage());
            }
        }
        households::tidy(time());
    }
}
