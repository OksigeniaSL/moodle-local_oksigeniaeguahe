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
use local_oksigeniaeguahe\local\reports;

/**
 * The reports that are due: «How it goes» of the week that ended, each Monday, and «The year, cold» when a school year
 * of the centre ends; for every centre with families. It runs every morning, so a Monday missed is made the next day.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class make_reports extends \core\task\scheduled_task {
    /** @var int|null The time to take as now, for the tests. */
    public static $testnow = null;

    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_make_reports', 'local_oksigeniaeguahe');
    }

    /**
     * Makes the reports due.
     */
    public function execute() {
        global $DB;
        $now = self::$testnow ?? time();
        foreach ($DB->get_fieldset_select(centres::T, 'id', '1 = 1') as $id) {
            $centre = centres::get((int) $id);
            foreach ($centre ? reports::due($centre, $now) : [] as [$kind, $from, $to]) {
                try {
                    reports::make($centre, $kind, $from, $to, $now);
                } catch (\Throwable $e) {
                    // One centre that fails does not leave the others without their report.
                    mtrace('Eguahe: the report of centre ' . $id . ' failed: ' . $e->getMessage());
                }
                mtrace('Eguahe: ' . $kind . ' report of centre ' . $id . ' made.');
            }
        }
    }
}
