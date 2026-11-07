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
     * Sends a mail of HTML and text, with its images inside the message (they show without asking to show images), to
     * someone who is not a user. In the name of the centre, from the no-reply address of the site and with the centre's
     * address to answer to. As Moodle does with its mail: nothing is sent when the site sends no mail, and what is
     * diverted goes to its diversion.
     *
     * @param \stdClass $centre
     * @param string $email
     * @param string $subject
     * @param string $html
     * @param string $text
     * @param array $images A list of data, cid, name, mime.
     * @return bool Whether it was sent.
     */
    public static function send_rich(
        \stdClass $centre,
        string $email,
        string $subject,
        string $html,
        string $text,
        array $images = []
    ): bool {
        global $CFG;
        if (!validate_email($email)) {
            return false;
        }
        if (!empty($CFG->noemailever)) {
            // A site that sends no mail: as Moodle does, it counts as done.
            return true;
        }
        if (email_should_be_diverted($email)) {
            $subject = "[DIVERTED {$email}] {$subject}";
            $email = $CFG->divertallemailsto;
        }
        $mail = get_mailer();
        if (!$mail) {
            return false;
        }
        $noreply = \core_user::get_noreply_user()->email;
        $mail->Sender = $noreply;
        $mail->From = $noreply;
        $mail->FromName = str_replace(["\r", "\n", '"'], ' ', format_string($centre->name));
        if (!empty($centre->email) && validate_email($centre->email)) {
            $mail->addReplyTo($centre->email, $mail->FromName);
        }
        $mail->addAddress($email);
        $mail->Subject = str_replace(["\r", "\n"], ' ', $subject);
        $mail->MessageID = generate_email_messageid();
        $mail->isHTML(true);
        $mail->Encoding = 'quoted-printable';
        $mail->Body = $html;
        $mail->AltBody = $text;
        foreach ($images as $image) {
            $mail->addStringEmbeddedImage($image['data'], $image['cid'], $image['name'], 'base64', $image['mime']);
        }
        return (bool) $mail->send();
    }
}
