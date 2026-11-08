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

/**
 * The reminder of the day before: «Mañana los esperamos», to the families signed up for a visit tomorrow who let the
 * centre write to them by email. Once for each sign-up, from the morning of the day before.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reminders extends \core\task\scheduled_task {
    /** @var int The hour of the day before from which reminders go. */
    const FROMHOUR = 9;

    /** @var int|null The time to take as now, for the tests. */
    public static $testnow = null;

    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_send_reminders', 'local_oksigeniaeguahe');
    }

    /**
     * Sends the reminders due.
     */
    public function execute() {
        global $DB;
        $now = self::$testnow ?? time();
        if ((int) userdate($now, '%H') < self::FROMHOUR) {
            return;
        }
        $tomorrow = userdate($now + DAYSECS, '%Y%m%d');
        $rows = $DB->get_records_sql(
            'SELECT s.id, s.householdid, s.eventid, COALESCE(t.timestart, e.timestart) AS timestart
                                        FROM {local_oksigeniaeguahe_signup} s
                                        JOIN {local_oksigeniaeguahe_event} e ON e.id = s.eventid
                                   LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
                                       WHERE s.reminded = 0 AND COALESCE(t.timestart, e.timestart) > :now
                                             AND COALESCE(t.timestart, e.timestart) < :limit',
            ['now' => $now, 'limit' => $now + 2 * DAYSECS]
        );
        foreach ($rows as $row) {
            if (userdate((int) $row->timestart, '%Y%m%d') !== $tomorrow) {
                continue;
            }
            $DB->set_field('local_oksigeniaeguahe_signup', 'reminded', $now, ['id' => $row->id]);
            $household = $DB->get_record(households::T, ['id' => $row->householdid]);
            $centre = $household ? centres::get((int) $household->centreid) : null;
            if (!$household || !$centre) {
                continue;
            }
            $signup = familymail::signup($household, (int) $row->eventid);
            if ($signup && !familymail::may_email($household)) {
                // Without the email allowed, only on the phones the family joined with.
                messaging::notify('reminder', $household, $centre, $signup, $household->lang ?: $centre->lang);
                continue;
            }
            if ($signup) {
                try {
                    familymail::send('reminder', $household, $centre, $signup, $household->lang ?: $centre->lang);
                } catch (\Throwable $e) {
                    mtrace('Eguahe: reminder not sent (' . $e->getMessage() . ')');
                }
            }
        }
    }
}
