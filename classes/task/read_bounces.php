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

use local_oksigeniaeguahe\local\bounces;

/**
 * Every quarter of an hour, the mail that came back to the mailbox that sends: each family whose mail does not arrive is
 * marked, to call it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class read_bounces extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_read_bounces', 'local_oksigeniaeguahe');
    }

    /**
     * Reads, and says how much, never an address.
     */
    public function execute() {
        $out = bounces::read(time());
        mtrace('Returned mail read: ' . $out['read'] . '; addresses marked: ' . $out['marked'] .
            ($out['error'] !== '' ? '; failed: ' . $out['error'] : ''));
    }
}
