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
use local_oksigeniaeguahe\local\listmatch;

/**
 * Each morning, after the list of the centre came in during the night, the families of Eguahe that enrolled a child or
 * that already have one at the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class detect_enrolments extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_detect_enrolments', 'local_oksigeniaeguahe');
    }

    /**
     * Crosses them, centre by centre.
     */
    public function execute() {
        foreach (centres::all() as $centre) {
            try {
                [$enrolled, $incentre, $asked] = listmatch::detect($centre, time());
                if ($enrolled || $incentre || $asked) {
                    mtrace("Eguahe: centre {$centre->id}, {$enrolled} enrolled, {$incentre} of the centre, {$asked} to ask.");
                }
            } catch (\Throwable $e) {
                mtrace('Eguahe: the list of centre ' . $centre->id . ' failed: ' . $e->getMessage());
            }
        }
    }
}
