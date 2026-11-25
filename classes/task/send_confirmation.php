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
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\notices;

/**
 * The confirmation of a family that said it comes with one touch, from the letter of an invitation: after its page has
 * answered, which would otherwise wait for the mail to go. If by then it has said it does not come, nothing is sent.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_confirmation extends \core\task\adhoc_task {
    /**
     * Sends it.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $centre = centres::get((int) ($data->centreid ?? 0));
        $household = $centre ? households::get((int) ($data->householdid ?? 0), (int) $centre->id) : null;
        $signup = $household ? familymail::signup($household, (int) ($data->eventid ?? 0)) : null;
        if (!$signup) {
            return;
        }
        try {
            $lang = (string) ($data->lang ?? '') ?: ($household->lang ?: $centre->lang);
            familymail::send('confirmation', $household, $centre, $signup, $lang, households::new_link($household));
        } catch (\Throwable $e) {
            // Never tried again: what went wrong may have come after it was sent. The file of the family says it.
            notices::log($household, 'confirmation', $signup, 'email', 'failed', 'mail');
            mtrace('Eguahe: confirmation not sent (' . $e->getMessage() . ')');
        }
    }

    /**
     * Queues the confirmation of a family for an act, once.
     *
     * @param \stdClass $household
     * @param \stdClass $event
     * @param string $lang
     */
    public static function queue(\stdClass $household, \stdClass $event, string $lang): void {
        $task = new self();
        $task->set_custom_data(['centreid' => (int) $household->centreid, 'householdid' => (int) $household->id,
            'eventid' => (int) $event->id, 'lang' => $lang]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
