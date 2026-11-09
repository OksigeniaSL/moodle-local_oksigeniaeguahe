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

use local_oksigeniaeguahe\local\report_ai as ask;

/**
 * Asks the AI of a centre for one of its reports, once, away from the page: the themes of what the families wrote,
 * a reading of the period and advice of its own.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_ai extends \core\task\adhoc_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_report_ai', 'local_oksigeniaeguahe');
    }

    /**
     * Asks and keeps what it says.
     */
    public function execute() {
        $data = $this->get_custom_data();
        ask::run((int) ($data->reportid ?? 0), (int) ($data->userid ?? 0));
    }
}
