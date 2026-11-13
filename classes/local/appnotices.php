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

namespace local_oksigeniaeguahe\local;

/**
 * Notices to the phone, for the adults of a family with an account (the app of Oksigenia Aty, or the Moodle app): the
 * centre answered them by email, their visit is tomorrow, the centre asks whether each child goes on. Moodle notices of
 * their own (db/messages.php), on the bell and the phone by default; by email only if the person turns it on, as
 * Eguahe already writes to families by email. Each opens their page by a link that lasts a week.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class appnotices {
    /** @var int How long the link of a notice opens the page of the family. */
    const LASTS = 7 * DAYSECS;

    /**
     * The accounts of the adults of a household, through the families of the common record: the one Eguahe made for it
     * and the one a person joined it to.
     *
     * @param \stdClass $household
     * @return int[]
     */
    public static function users_of(\stdClass $household): array {
        $api = family::API;
        if (!family::available() || !method_exists($api, 'adult_users_of_family')) {
            return [];
        }
        $families = array_filter([(int) $api::family_by_key(family::SOURCE, family::key('h', (int) $household->id)),
            (int) ($household->familylink ?? 0)], fn($id) => $id > 0);
        $users = [];
        foreach (array_unique($families) as $familyid) {
            foreach ($api::adult_users_of_family($familyid) as $userid) {
                $users[(int) $userid] = (int) $userid;
            }
        }
        return array_values($users);
    }

    /**
     * Sends a notice to each account of the family, in the language of the family.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $name The kind of notice (db/messages.php).
     * @param string $key Its words: «_subject» and «_text».
     * @param mixed $a What goes in them.
     * @param string $anchor The part of their page it opens.
     * @return int How many were sent.
     */
    public static function send(\stdClass $household, \stdClass $centre, string $name, string $key, $a, string $anchor): int {
        $sent = 0;
        foreach (self::users_of($household) as $userid) {
            $user = \core_user::get_user($userid);
            if (!$user || !empty($user->suspended) || !empty($user->deleted)) {
                continue;
            }
            // In the language the family chose with the centre; else in that of the account.
            $lang = (string) ($household->lang ?: ($user->lang ?: ($centre->lang ?: 'es')));
            $message = new \core\message\message();
            $message->component = 'local_oksigeniaeguahe';
            $message->name = $name;
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = publiclang::str($key . '_subject', $lang, $a);
            $message->fullmessage = publiclang::str($key . '_text', $lang, $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = atycards::url($household, $userid, $anchor, time(), self::LASTS)->out(false);
            $message->contexturlname = publiclang::str('aty_seeall', $lang);
            if (message_send($message)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * The centre answered them by email.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return int
     */
    public static function replied(\stdClass $household, \stdClass $centre): int {
        return self::send($household, $centre, 'replied', 'appnotice_replied', null, '');
    }

    /**
     * Their visit is tomorrow, at its hour.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $signup familymail::signup()
     * @return int
     */
    public static function visit_tomorrow(\stdClass $household, \stdClass $centre, \stdClass $signup): int {
        $hour = publicpage::date_parts((int) $signup->timestart, $household->lang ?: ($centre->lang ?: 'es'))['hour'];
        $a = (object) ['event' => format_string((string) $signup->eventname), 'hour' => $hour];
        return self::send($household, $centre, 'visitsoon', 'appnotice_visit', $a, 'visita');
    }

    /**
     * The centre asks whether each child goes on next school year.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return int
     */
    public static function renewal_asked(\stdClass $household, \stdClass $centre): int {
        $year = (string) $centre->renewalyear;
        return self::send($household, $centre, 'renewalasked', 'appnotice_renewal', $year, 'rematricula');
    }
}
