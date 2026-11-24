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
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\invites;

/**
 * Sends, in the background, the invitation to an act to the families it may go to: a few at a time and by day, because
 * hundreds at once do not fit in a page and end in spam. Each family once; a second run only reaches who is left.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_invites extends \core\task\adhoc_task {
    /**
     * Sends them.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $centre = centres::get((int) ($data->centreid ?? 0));
        $event = $centre ? events::get((int) ($data->eventid ?? 0), (int) $centre->id) : null;
        if (!$event) {
            return;
        }
        $ids = array_map('intval', (array) ($data->ids ?? []));
        invites::send($event, $centre, (int) ($data->userid ?? 0), time(), array_slice($ids, 0, invites::BATCH));
        $left = array_slice($ids, invites::BATCH);
        if ($left && invites::fits($event, time())) {
            $next = new self();
            $next->set_custom_data(['eventid' => (int) $event->id, 'centreid' => (int) $centre->id,
                'userid' => (int) ($data->userid ?? 0), 'ids' => $left]);
            $next->set_next_run_time(invites::daytime(time() + invites::PAUSE));
            \core\task\manager::queue_adhoc_task($next);
        }
    }
}
