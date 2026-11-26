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
 * name of the centre (or by the service of sending of the centre, mailer), and only what they asked for.
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
     * @param string $html Empty for a mail of text only.
     * @param string $text
     * @param array $images A list of data, cid, name, mime.
     * @param string $unsubscribe The address of unsubscribing with one click from the mail program, if the mail has one.
     * @param string $way By which way (mailer::KINDS), to try one; empty, the one of the centre.
     * @return bool Whether it was sent.
     */
    public static function send_rich(
        \stdClass $centre,
        string $email,
        string $subject,
        string $html,
        string $text,
        array $images = [],
        string $unsubscribe = '',
        string $way = ''
    ): bool {
        global $CFG;
        mailer::$last = '';
        if (!validate_email($email)) {
            return false;
        }
        if (self::reserved($email) && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            // The sample data: nothing leaves for an address that bounces (the tests keep their mail in their sink).
            return false;
        }
        if (!empty($centre->practice)) {
            // The practice centre of the guided helps: as sent, and nothing leaves.
            return true;
        }
        if (!empty($CFG->noemailever)) {
            // A site that sends no mail: as Moodle does, it counts as done.
            return true;
        }
        if (email_should_be_diverted($email)) {
            $subject = "[DIVERTED {$email}] {$subject}";
            $email = $CFG->divertallemailsto;
        }
        // By the service of the centre, when it has one at work; if not, by the mail of the site.
        $way = $way !== '' ? $way : mailer::way($centre);
        if ($way === 'mailjet') {
            mailer::$last = mailer::by_api($centre, mailer::message($centre, $email, $subject, $html, $text, $images, $unsubscribe));
            return mailer::$last === '';
        }
        $mail = $way === 'smtp' ? mailer::smtp($centre) : get_mailer();
        if (!$mail) {
            return false;
        }
        [$from, $fromname] = $way === 'smtp' ? mailer::from($centre) : [\core_user::get_noreply_user()->email,
            str_replace(["\r", "\n", '"'], ' ', format_string($centre->name))];
        $mail->Sender = $from;
        $mail->From = $from;
        $mail->FromName = $fromname;
        if (!empty($centre->email) && validate_email($centre->email)) {
            $mail->addReplyTo($centre->email, $mail->FromName);
        }
        $mail->addAddress($email);
        // The prefix of the subject and the headers the site puts on all its mail, as Moodle does; by a service, its own.
        $prefix = $way === 'site' ? trim((string) ($CFG->emailsubjectprefix ?? '')) : '';
        $mail->Subject = str_replace(["\r", "\n"], ' ', ($prefix !== '' ? $prefix . ' ' : '') . $subject);
        foreach ($way === 'site' ? array_filter(array_map('trim', explode("\n", (string) ($CFG->emailheaders ?? '')))) : [] as $header) {
            $mail->addCustomHeader($header);
        }
        if ($unsubscribe !== '') {
            // Unsubscribing from the mail program itself, with one click (RFC 8058), as Gmail, Yahoo and Outlook expect.
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $mail->MessageID = generate_email_messageid();
        $mail->isHTML($html !== '');
        $mail->Encoding = 'quoted-printable';
        $mail->Body = $html !== '' ? $html : $text;
        $mail->AltBody = $html !== '' ? $text : '';
        foreach ($images as $image) {
            // Only the pictures the letter shows: one made but not shown, Gmail lists at its foot as an attachment.
            if (strpos($html, 'cid:' . $image['cid']) === false) {
                continue;
            }
            $mail->addStringEmbeddedImage($image['data'], $image['cid'], $image['name'], 'base64', $image['mime']);
        }
        // Signed with the DKIM key of the site, as Moodle signs its own: without it, a site that signs its mail sends this
        // one unsigned, and it may end in spam. A service signs with its own.
        if ($way === 'site' && !empty($CFG->emaildkimselector)) {
            $domain = substr((string) strrchr($mail->From, '@'), 1);
            $pempath = "{$CFG->dataroot}/dkim/{$domain}/{$CFG->emaildkimselector}.private";
            if ($domain !== '' && file_exists($pempath)) {
                $mail->DKIM_domain = $domain;
                $mail->DKIM_private = $pempath;
                $mail->DKIM_selector = $CFG->emaildkimselector;
                $mail->DKIM_identity = $mail->From;
                // The one click to unsubscribe only counts when the signature covers it.
                $mail->DKIM_extraHeaders = $unsubscribe !== '' ? ['List-Unsubscribe', 'List-Unsubscribe-Post'] : [];
            }
        }
        $sent = (bool) $mail->send();
        if ($way === 'smtp') {
            mailer::$last = $sent ? '' : \core_text::substr(clean_param((string) $mail->ErrorInfo, PARAM_TEXT), 0, 255);
            $mail->smtpClose();
        }
        return $sent;
    }

    /**
     * Whether an address is of a domain that never receives mail (example.com, .test, .invalid…): the ones of the sample
     * data. Mail sent there bounces, and bounces take away the good name of the domain that sends.
     *
     * @param string $email
     * @return bool
     */
    public static function reserved(string $email): bool {
        $domain = \core_text::strtolower(substr((string) strrchr($email, '@'), 1));
        return (bool) preg_match('/(^|\.)(example\.(com|net|org)|test|invalid|example|localhost)$/', $domain);
    }
}
