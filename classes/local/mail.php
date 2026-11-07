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
 * Emails to families, who are not users of Moodle: in their language, from the no-reply address of the site with the
 * name of the centre, and only what they asked for.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mail {
    /**
     * The confirmation of a sign-up, with the private link of the household.
     *
     * @param \stdClass $centre
     * @param \stdClass $event
     * @param string $email
     * @param string $firstname
     * @param string $token
     * @param string $lang
     * @param \stdClass|null $when The turn of the family (timestart, timeend), when the event has turns.
     * @return bool Whether Moodle sent it.
     */
    public static function signed_up(
        \stdClass $centre,
        \stdClass $event,
        string $email,
        string $firstname,
        string $token,
        string $lang,
        ?\stdClass $when = null
    ): bool {
        $a = (object) [
            'name' => $firstname,
            'centre' => format_string($centre->name),
            'event' => format_string($event->name),
            'when' => self::when($when ?? $event, $lang),
            'place' => $event->place ? format_string($event->place) : format_string($centre->name),
            'link' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false),
        ];
        $subject = publiclang::str('mail_signedup_subject', $lang, $a);
        $text = publiclang::str('mail_signedup_body', $lang, $a);
        return self::send($centre, $email, $firstname, $subject, $text, $lang);
    }

    /**
     * The date and hours of an event, written in a language.
     *
     * @param \stdClass $event
     * @param string $lang
     * @return string
     */
    public static function when(\stdClass $event, string $lang): string {
        $locale = str_replace('_', '-', $lang === 'pt_br' ? 'pt_BR' : $lang);
        $zone = \core_date::get_server_timezone_object();
        if (class_exists('\IntlDateFormatter')) {
            $day = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $zone);
            $hour = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $zone);
            $text = $day->format((int) $event->timestart) . ', ' . $hour->format((int) $event->timestart);
            if ($event->timeend > $event->timestart) {
                $text .= '–' . $hour->format((int) $event->timeend);
            }
            return $text;
        }
        return userdate((int) $event->timestart, get_string('strftimedaydatetime', 'langconfig'));
    }

    /**
     * Only the hours of a turn, «10:00–11:00», for a list of turns of the same day.
     *
     * @param \stdClass $slot timestart and timeend.
     * @param string $lang
     * @return string
     */
    public static function hours(\stdClass $slot, string $lang): string {
        $locale = str_replace('_', '-', $lang === 'pt_br' ? 'pt_BR' : $lang);
        $zone = \core_date::get_server_timezone_object();
        if (class_exists('\IntlDateFormatter')) {
            $hour = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $zone);
            return $hour->format((int) $slot->timestart) . '–' . $hour->format((int) $slot->timeend);
        }
        return userdate((int) $slot->timestart, '%H:%M') . '–' . userdate((int) $slot->timeend, '%H:%M');
    }

    /**
     * Sends a plain email to someone who is not a user.
     *
     * @param \stdClass $centre
     * @param string $email
     * @param string $firstname
     * @param string $subject
     * @param string $text
     * @param string $lang
     * @return bool
     */
    protected static function send(
        \stdClass $centre,
        string $email,
        string $firstname,
        string $subject,
        string $text,
        string $lang
    ): bool {
        $to = \core_user::get_noreply_user();
        $to->email = $email;
        $to->firstname = $firstname;
        $to->lastname = '';
        $to->lang = $lang;
        $to->mailformat = FORMAT_PLAIN;
        $to->emailstop = 0;
        $from = \core_user::get_noreply_user();
        $from->firstname = format_string($centre->name);
        $from->lastname = '';
        return (bool) email_to_user($to, $from, $subject, $text);
    }
}
