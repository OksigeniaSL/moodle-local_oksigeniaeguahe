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
use local_oksigeniaeguahe\local\forecast;
use local_oksigeniaeguahe\local\opendata;

/**
 * Brings again, once a week, the open data of the areas of the centres that have it kept, so a new year of births
 * reaches the forecast by itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_opendata extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_fetch_opendata', 'local_oksigeniaeguahe');
    }

    /**
     * Fetches for each centre that fetched before.
     */
    public function execute() {
        foreach (centres::all() as $centre) {
            if (!isset(opendata::status((int) $centre->id)['istac'])) {
                continue;
            }
            try {
                opendata::fetch_istac((int) $centre->id, forecast::zone($centre));
            } catch (\moodle_exception $e) {
                mtrace('Eguahe: ' . $e->getMessage() . ' (centre ' . $centre->id . ')');
            }
        }
    }
}
