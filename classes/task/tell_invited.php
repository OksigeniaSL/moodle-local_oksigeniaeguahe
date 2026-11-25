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
 * Tells, in the background, the families that have the letter of an act that it changed or that it is called off, when a
 * person said so: a few at a time, as the letters went.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tell_invited extends \core\task\adhoc_task {
    /** @var int How long between some and the next ones: sooner than the letters, for it is something they need to know. */
    const PAUSE = 5 * MINSECS;

    /**
     * Puts it in the queue.
     *
     * @param \stdClass $event As it is now.
     * @param string $kind change or cancelled.
     * @param string $message What the team adds.
     * @param int $userid
     * @param int[] $ids The families with its letter, as they were when the person said so.
     */
    public static function queue(\stdClass $event, string $kind, string $message, int $userid, array $ids): void {
        $task = new self();
        $task->set_custom_data(['eventid' => (int) $event->id, 'centreid' => (int) $event->centreid, 'kind' => $kind,
            'message' => $message, 'userid' => $userid, 'ids' => array_values(array_map('intval', $ids))]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Tells them.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $centre = centres::get((int) ($data->centreid ?? 0));
        $event = $centre ? events::get((int) ($data->eventid ?? 0), (int) $centre->id) : null;
        $kind = ($data->kind ?? '') === 'cancelled' ? 'cancelled' : 'change';
        if (!$event || (int) $event->timeend < time() || ((int) $event->cancelled > 0) !== ($kind === 'cancelled')) {
            // Gone by, or changed again since: there is another one for that.
            return;
        }
        $ids = array_map('intval', (array) ($data->ids ?? []));
        $still = invites::with_letter($event);
        foreach (array_slice($ids, 0, invites::BATCH) as $id) {
            if (isset($still[$id])) {
                invites::tell_change($event, $centre, $still[$id], $kind, (string) ($data->message ?? ''), (int) ($data->userid ?? 0));
            }
        }
        $left = array_slice($ids, invites::BATCH);
        if ($left) {
            $next = new self();
            $next->set_custom_data(['ids' => $left] + (array) $data);
            $next->set_next_run_time(time() + self::PAUSE);
            \core\task\manager::queue_adhoc_task($next);
        }
    }
}
