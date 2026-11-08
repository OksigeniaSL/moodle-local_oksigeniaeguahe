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
use local_oksigeniaeguahe\local\messaging;
use local_oksigeniaeguahe\local\surveys;

/**
 * The thanks after the visit: «Gracias por venir», a couple of hours after the end of their turn, to the families who
 * came, with the one open question, or the survey if the centre turned it on. By email if they allowed it, else only on
 * the phones they joined with. Once for each sign-up, and never for a visit of days ago.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_thanks extends \core\task\scheduled_task {
    /** @var int|null The time to take as now, for the tests. */
    public static $testnow = null;

    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_send_thanks', 'local_oksigeniaeguahe');
    }

    /**
     * Sends the thanks due.
     */
    public function execute() {
        global $DB;
        $now = self::$testnow ?? time();
        $rows = $DB->get_records_sql(
            'SELECT s.id, s.householdid, s.eventid
               FROM {local_oksigeniaeguahe_signup} s
               JOIN {local_oksigeniaeguahe_event} e ON e.id = s.eventid
          LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
              WHERE s.attended > 0 AND s.thanked = 0
                    AND COALESCE(t.timeend, e.timeend) <= :ready
                    AND COALESCE(t.timeend, e.timeend) >= :oldest',
            ['ready' => $now - surveys::THANKSAFTER * HOURSECS, 'oldest' => $now - surveys::THANKSUNTIL * DAYSECS]
        );
        foreach ($rows as $row) {
            $DB->set_field('local_oksigeniaeguahe_signup', 'thanked', $now, ['id' => $row->id]);
            $household = $DB->get_record(households::T, ['id' => $row->householdid]);
            $centre = $household ? centres::get((int) $household->centreid) : null;
            $signup = $household && $centre ? familymail::signup($household, (int) $row->eventid) : null;
            if (!$signup) {
                continue;
            }
            $lang = $household->lang ?: $centre->lang;
            if (!familymail::may_email($household)) {
                // Without the email allowed, only on the phones the family joined with.
                messaging::notify('thanks', $household, $centre, $signup, $lang);
                continue;
            }
            try {
                familymail::send('thanks', $household, $centre, $signup, $lang, households::new_link($household, 'thanks'));
            } catch (\Throwable $e) {
                mtrace('Eguahe: thanks not sent (' . $e->getMessage() . ')');
            }
        }
    }
}
