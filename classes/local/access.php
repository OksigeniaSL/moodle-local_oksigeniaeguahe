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
 * What keeps the private page of a family theirs when its link travels: a link forwarded, or sent to an address that is
 * not theirs, only shows the page. Downloading or erasing their data asks for a code of six figures sent to the email the
 * centre has for them; a link unused for months stops working and offers a new one, sent to that same email and never to
 * one written by whoever opens it; and changing an email or a phone tells the address there was before.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** @var string */
    const T = 'local_oksigeniaeguahe_code';

    /** @var string[] What asks for a code. */
    const WHAT = ['download', 'erase'];

    /** @var int How long a code works. */
    const TTL = 15 * MINSECS;

    /** @var int How many wrong tries a code takes. */
    const TRIES = 5;

    /** @var int How long a code shown right keeps the door open in that browser. */
    const PROOF = 10 * MINSECS;

    /** @var int A code is not sent again before this. */
    const RESEND = MINSECS;

    /** @var int A link stops working after this long without being opened. */
    const LINKDAYS = 120;

    /** @var int A new link is not sent again before this. */
    const NEWLINK = HOURSECS;

    /** @var int How many codes a family may get in a day, for download and erase together. */
    const DAILY = 5;

    /** @var int How long an email or a Telegram must have been the family's before a code goes there. */
    const TRUST = 7 * DAYSECS;

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The emails of the family. A code, and a new link, go only to those that were theirs before: never to one written
     * on the page in the last week by whoever had its link, who could otherwise send the code to themselves.
     *
     * @param \stdClass $household
     * @param bool $trusted Only those that have been theirs a week, or came from the team or the sign-up.
     * @param int $now
     * @return string[]
     */
    public static function emails(\stdClass $household, bool $trusted = false, int $now = 0): array {
        $now = $now ?: time();
        $out = [];
        foreach (households::full($household)->adults as $adult) {
            $email = trim((string) $adult->email);
            if ($trusted && (int) ($adult->emailpage ?? 0) > $now - self::TRUST) {
                continue;
            }
            if ($email !== '' && validate_email($email)) {
                $out[\core_text::strtolower($email)] = $email;
            }
        }
        return array_values($out);
    }

    /**
     * Where a code goes, as the page says it: the emails, masked, and their Telegram.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @return string[]
     */
    public static function destinations(\stdClass $household, \stdClass $centre, string $lang): array {
        $out = array_map([self::class, 'mask'], self::emails($household, true));
        if (self::telegram($household, $centre)) {
            $out[] = publiclang::str('pub_code_telegram', $lang);
        }
        return $out;
    }

    /**
     * An email as it is shown to whoever opens the page: «ma•••@gmail.com».
     *
     * @param string $email
     * @return string
     */
    public static function mask(string $email): string {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return \core_text::substr($user, 0, min(2, max(1, \core_text::strlen($user) - 1))) . '•••@' . $domain;
    }

    /**
     * The keyed hash of a code of a family.
     *
     * @param int $householdid
     * @param string $code
     * @return string
     */
    protected static function hash(int $householdid, string $code): string {
        global $CFG;
        return hash_hmac('sha256', $householdid . '|' . $code, (string) ($CFG->passwordsaltmain ?? '') . '|' . $CFG->wwwroot);
    }

    /**
     * The code of a family for something, if one was sent and still works.
     *
     * @param int $householdid
     * @param string $what
     * @param int $now
     * @return \stdClass|null
     */
    public static function pending(int $householdid, string $what, int $now = 0): ?\stdClass {
        global $DB;
        $now = $now ?: time();
        $row = $DB->get_record_select(
            self::T,
            'householdid = ? AND what = ? AND timecreated > ?',
            [$householdid, $what, $now - self::TTL],
            '*',
            IGNORE_MULTIPLE
        );
        return $row ?: null;
    }

    /**
     * Sends a code to the emails of the family. A code sent less than a minute ago is not sent again.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $what download or erase.
     * @param string $lang
     * @param int $now
     * @return string[] The masked addresses it went to; empty when the family has none.
     */
    public static function send(\stdClass $household, \stdClass $centre, string $what, string $lang, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $emails = self::emails($household, true, $now);
        $telegram = self::telegram($household, $centre, $now);
        if ((!$emails && !$telegram) || !in_array($what, self::WHAT, true)) {
            return [];
        }
        $masked = self::destinations($household, $centre, $lang);
        $last = self::pending((int) $household->id, $what, $now);
        if ($last && (int) $last->timecreated > $now - self::RESEND) {
            return $masked;
        }
        if (self::too_many($household, $now)) {
            return [];
        }
        $cache = \cache::make('local_oksigeniaeguahe', 'codessent');
        $times = array_values(array_filter((array) ($cache->get((int) $household->id) ?: []), fn($t) => $t > $now - DAYSECS));
        $times[] = $now;
        $cache->set((int) $household->id, $times);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $DB->delete_records(self::T, ['householdid' => $household->id, 'what' => $what]);
        $DB->insert_record(self::T, (object) ['householdid' => $household->id, 'what' => $what,
            'codehash' => self::hash((int) $household->id, $code), 'tries' => 0, 'timecreated' => $now]);
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $name = format_string($centre->name);
        $subject = $str('pub_code_mail_subject', (object) ['code' => $code, 'centre' => $name]);
        $lead = $str('pub_code_mail_lead_' . $what);
        $foot = $str('pub_code_mail_foot');
        [$html, $text] = self::letter($centre, $lang, $lead, '<p style="margin:18px 0; font-size:34px; font-weight:800; ' .
            'letter-spacing:6px; font-family:Menlo,Consolas,monospace;">' . s($code) . '</p>', $code, $foot);
        foreach ($emails as $email) {
            mail::send_rich($centre, $email, $subject, $html, $text);
        }
        // The team sees it in the history: if the family calls about a code, the file says why it came.
        households::note($household, 0, get_string_manager()->get_string(
            'code_noted_' . $what,
            self::C,
            implode(', ', $masked),
            (string) ($centre->lang ?? '') ?: current_language()
        ));
        if ($telegram) {
            // On their Telegram too, when they joined it (Marandu): urgent, and only while it works.
            try {
                \local_oksigeniamarandu\api::notify(
                    messaging::COMPONENT,
                    'access_code',
                    [$name, $code],
                    null,
                    (int) $household->id,
                    null,
                    true,
                    self::TTL,
                    [],
                    '',
                    '',
                    'telegram'
                );
            } catch (\Throwable $e) {
                debugging('Eguahe: the code did not go by Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $masked;
    }

    /**
     * Whether a family got as many codes as a day allows: then no new one goes until tomorrow.
     *
     * @param \stdClass $household
     * @param int $now
     * @return bool
     */
    public static function too_many(\stdClass $household, int $now = 0): bool {
        $now = $now ?: time();
        $times = (array) (\cache::make('local_oksigeniaeguahe', 'codessent')->get((int) $household->id) ?: []);
        return count(array_filter($times, fn($t) => $t > $now - DAYSECS)) >= self::DAILY;
    }

    /**
     * Whether the family joined its Telegram, where the code can go too.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return bool
     */
    public static function telegram(\stdClass $household, \stdClass $centre, int $now = 0): bool {
        $now = $now ?: time();
        if (!in_array('telegram', writeby::joined_on($household, $centre), true)) {
            return false;
        }
        // Every chat joined a week ago or more: whoever has the link could join their own Telegram from the page today.
        // Marandu says when the newest chat of the channel joined (since its 0.52); without it, no code by Telegram.
        try {
            $channels = \local_oksigeniamarandu\api::item_channels(messaging::COMPONENT, (int) $household->id);
            $since = (int) ($channels['telegram']['since'] ?? 0);
        } catch (\Throwable $e) {
            $since = 0;
        }
        return $since > 0 && $since <= $now - self::TRUST;
    }

    /**
     * Checks a code written by the family; right, it opens the door in this browser for a while.
     *
     * @param \stdClass $household
     * @param string $what
     * @param string $code
     * @param int $now
     * @return string ok, wrong, expired or tries.
     */
    public static function check(\stdClass $household, string $what, string $code, int $now = 0): string {
        global $DB, $SESSION;
        $now = $now ?: time();
        // One try at a time for a family and a code: many at once cannot pass the five.
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniaeguahe_code')
            ->get_lock('code' . (int) $household->id . $what, 5);
        if (!$lock) {
            return 'wrong';
        }
        try {
            $row = $DB->get_record(self::T, ['householdid' => $household->id, 'what' => $what], '*', IGNORE_MULTIPLE);
            if (!$row || (int) $row->timecreated <= $now - self::TTL) {
                return 'expired';
            }
            if ((int) $row->tries >= self::TRIES) {
                return 'tries';
            }
            $code = preg_replace('/\D/', '', $code);
            if (!hash_equals((string) $row->codehash, self::hash((int) $household->id, $code))) {
                $DB->set_field(self::T, 'tries', (int) $row->tries + 1, ['id' => $row->id]);
                return (int) $row->tries + 1 >= self::TRIES ? 'tries' : 'wrong';
            }
            $DB->delete_records(self::T, ['id' => $row->id]);
        } finally {
            $lock->release();
        }
        $proofs = (array) ($SESSION->local_oksigeniaeguahe_proof ?? []);
        $proofs[(int) $household->id . ':' . $what] = $now;
        $SESSION->local_oksigeniaeguahe_proof = $proofs;
        return 'ok';
    }

    /**
     * Whether this browser showed the code for something of the family a moment ago.
     *
     * @param \stdClass $household
     * @param string $what
     * @param int $now
     * @return bool
     */
    public static function proven(\stdClass $household, string $what, int $now = 0): bool {
        global $SESSION;
        $now = $now ?: time();
        $when = (int) (((array) ($SESSION->local_oksigeniaeguahe_proof ?? []))[(int) $household->id . ':' . $what] ?? 0);
        return $when > $now - self::PROOF;
    }

    /**
     * The row of a private link, to know when it was last opened.
     *
     * @param string $token
     * @return \stdClass|null
     */
    public static function link(string $token): ?\stdClass {
        global $DB;
        if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $token)) {
            return null;
        }
        return $DB->get_record(households::LINKS, ['linkhash' => hash('sha256', $token)]) ?: null;
    }

    /**
     * Whether a link stopped working: so long without being opened. Opening one that works keeps it working, counted once
     * a day.
     *
     * @param \stdClass $link
     * @param int $now
     * @return bool
     */
    public static function expired(\stdClass $link, int $now = 0): bool {
        global $DB;
        $now = $now ?: time();
        $last = max((int) $link->timecreated, (int) ($link->timeused ?? 0));
        if ($last < $now - self::LINKDAYS * DAYSECS) {
            return true;
        }
        if ((int) ($link->timeused ?? 0) < $now - DAYSECS) {
            $DB->set_field(households::LINKS, 'timeused', $now, ['id' => $link->id]);
        }
        return false;
    }

    /**
     * Sends a new link of their page to the emails of the family, at most once an hour.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param int $now
     * @return string[] The masked addresses it went to; empty when the family has none.
     */
    public static function send_new_link(\stdClass $household, \stdClass $centre, string $lang, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $emails = self::emails($household, true, $now);
        if (!$emails) {
            return [];
        }
        $masked = array_map([self::class, 'mask'], $emails);
        $recent = $DB->record_exists_select(
            households::LINKS,
            'householdid = ? AND reason = ? AND timecreated > ?',
            [$household->id, 'renewed', $now - self::NEWLINK]
        );
        if ($recent) {
            return $masked;
        }
        $token = households::new_link($household, 'renewed');
        $url = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false);
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $name = format_string($centre->name);
        $button = '<p style="margin:18px 0;"><a href="' . s($url) . '" style="display:inline-block; padding:12px 20px; ' .
            'border-radius:10px; background:#3d2a35; color:#ffffff; font-weight:700; text-decoration:none;">' .
            s($str('pub_newlink_mail_open')) . '</a></p>';
        [$html, $text] = self::letter(
            $centre,
            $lang,
            $str('pub_newlink_mail_lead', $name),
            $button,
            $url,
            $str('pub_newlink_mail_foot')
        );
        foreach ($emails as $email) {
            mail::send_rich($centre, $email, $str('pub_newlink_mail_subject', $name), $html, $text);
        }
        return $masked;
    }

    /**
     * Tells the address there was before that an email or a phone of the family changed on their page, without a link.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $before The adult as it was.
     * @param array $after firstname, email and phone as they are now.
     */
    public static function changed(\stdClass $household, \stdClass $centre, \stdClass $before, array $after): void {
        $lang = (string) ($household->lang ?: ($centre->lang ?? 'es'));
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $name = trim((string) ($after['firstname'] ?? $before->firstname));
        $lines = [];
        foreach (['email', 'phone'] as $field) {
            $was = (string) ($before->$field ?? '');
            $is = (string) ($after[$field] ?? '');
            if ($was !== '' && $was !== $is) {
                $value = $is === '' ? $str('pub_changed_mail_removed') : ($field === 'phone' ? phone::display($is) : $is);
                $lines[] = $str('pub_changed_mail_' . $field, (object) ['name' => $name, 'value' => $value,
                    'centre' => format_string($centre->name)]);
            }
        }
        // To every email the family had: the one that changed, too.
        $emails = self::emails($household);
        $old = trim((string) ($before->email ?? ''));
        if ($old !== '' && !in_array(\core_text::strtolower($old), array_map('core_text::strtolower', $emails), true)) {
            $emails[] = $old;
        }
        if (!$lines || !$emails) {
            return;
        }
        $contact = array_filter([(string) ($centre->email ?? ''), (string) ($centre->phone ?? '')]);
        $foot = $contact ? $str('pub_changed_mail_foot') . ' ' . implode(' · ', $contact) :
            rtrim($str('pub_changed_mail_foot'), ': ') . '.';
        [$html, $text] = self::letter(
            $centre,
            $lang,
            implode(' ', $lines),
            '',
            '',
            $foot
        );
        foreach ($emails as $email) {
            mail::send_rich($centre, $email, $str('pub_changed_mail_subject', format_string($centre->name)), $html, $text);
        }
    }

    /**
     * Tells the addresses the family already had that someone was added to its data from its page, without a link: as
     * with a changed email, so that nobody slips into a family unseen.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $added firstname, email and phone of the person added.
     */
    public static function added(\stdClass $household, \stdClass $centre, \stdClass $added): void {
        $emails = self::emails($household, true);
        $new = \core_text::strtolower(trim((string) ($added->email ?? '')));
        $emails = array_values(array_filter($emails, fn($email) => \core_text::strtolower($email) !== $new));
        if (!$emails) {
            return;
        }
        $lang = (string) ($household->lang ?: ($centre->lang ?? 'es'));
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $value = implode(' · ', array_filter([(string) ($added->email ?? ''),
            !empty($added->phone) ? phone::display((string) $added->phone) : '']));
        $contact = array_filter([(string) ($centre->email ?? ''), (string) ($centre->phone ?? '')]);
        $foot = $contact ? $str('pub_changed_mail_foot') . ' ' . implode(' · ', $contact) :
            rtrim($str('pub_changed_mail_foot'), ': ') . '.';
        [$html, $text] = self::letter($centre, $lang, $str('pub_added_mail_line', (object) [
            'name' => trim((string) $added->firstname . ' ' . (string) ($added->lastname ?? '')),
            'value' => $value, 'centre' => format_string($centre->name)]), '', '', $foot);
        foreach ($emails as $email) {
            mail::send_rich($centre, $email, $str('pub_changed_mail_subject', format_string($centre->name)), $html, $text);
        }
    }

    /**
     * The page before something delicate: send the code, then write it; or, without an email, write to the centre.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @param string $what download or erase.
     * @param string[] $sent The masked addresses the code went to; empty before sending it.
     * @param string $error wrong, expired, tries or ''.
     * @return array
     */
    public static function page_context(
        \stdClass $household,
        \stdClass $centre,
        string $lang,
        string $token,
        string $what,
        array $sent,
        string $error = ''
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $action = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false);
        $emails = self::destinations($household, $centre, $lang);
        $where = familypage::join($sent ?: $emails, $lang);
        $out = ['title' => $str($sent ? 'pub_code_sent_title' : 'pub_code_title_' . $what), 'note' => false,
            'error' => $error !== '' ? $str('pub_code_' . $error) : false, 'codeform' => false, 'sendform' => false,
            'nowhere' => false, 'back' => ['url' => $action, 'label' => $str('pub_code_back')]];
        if (!$emails) {
            // Without an email, the centre does it with them.
            $out['text'] = $str('pub_code_none');
            $out['nowhere'] = publicpage::reach($centre, $lang);
            return $out;
        }
        if (!$sent) {
            $out['text'] = $str('pub_code_to', $where);
            $out['note'] = $str('pub_code_why');
            $out['sendform'] = ['action' => $action, 'sesskey' => sesskey(), 'step' => 'codigo', 'para' => $what,
                'label' => $str('pub_code_send'), 'secondary' => false];
            return $out;
        }
        $out['text'] = $str('pub_code_sent', $where);
        $out['codeform'] = ['action' => $action, 'sesskey' => sesskey(), 'para' => $what, 'label' => $str('pub_code_label'),
            'go' => $str('pub_code_go')];
        $out['sendform'] = ['action' => $action, 'sesskey' => sesskey(), 'step' => 'codigo', 'para' => $what,
            'label' => $str('pub_code_again'), 'secondary' => true];
        return $out;
    }

    /**
     * The code was right a moment ago: the download, by a form of the page.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @return array
     */
    public static function ready_context(\stdClass $centre, string $lang, string $token): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $action = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false);
        return ['title' => $str('pub_code_ready_title'), 'text' => $str('pub_code_ready'), 'note' => false, 'error' => false,
            'codeform' => false, 'nowhere' => false, 'sendform' => ['action' => $action, 'sesskey' => sesskey(),
            'step' => 'download', 'para' => 'download', 'label' => $str('pub_data_download'), 'secondary' => false,
            'download' => true], 'back' => ['url' => $action, 'label' => $str('pub_code_back')]];
    }

    /**
     * The page of a link that stopped working: a new one to their email, never to one written here.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @param string[] $sent The masked addresses the new link went to; empty before sending it.
     * @return array
     */
    public static function expired_context(
        \stdClass $household,
        \stdClass $centre,
        string $lang,
        string $token,
        array $sent
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $action = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false);
        $emails = self::emails($household);
        $out = ['title' => $str('pub_link_expired_title'), 'note' => false, 'error' => false, 'codeform' => false,
            'sendform' => false, 'nowhere' => false, 'back' => false];
        if (!$emails) {
            $out['text'] = $str('pub_link_expired_none');
            $out['nowhere'] = publicpage::reach($centre, $lang);
        } else if ($sent) {
            $out['text'] = $str('pub_link_expired_sent', familypage::join($sent, $lang));
        } else {
            $out['text'] = $str('pub_link_expired');
            $out['sendform'] = ['action' => $action, 'sesskey' => sesskey(), 'step' => 'nuevoenlace', 'para' => '',
                'label' => $str('pub_link_expired_send'), 'secondary' => false];
        }
        return $out;
    }

    /**
     * A short letter of the centre, plain and in its colour: what it says, what stands out (a code, a button) and a line
     * at the foot.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param string $lead
     * @param string $middlehtml
     * @param string $middletext
     * @param string $foot
     * @return string[] [html, text]
     */
    protected static function letter(
        \stdClass $centre,
        string $lang,
        string $lead,
        string $middlehtml,
        string $middletext,
        string $foot
    ): array {
        $colour = centres::colour($centre);
        $dir = lists::dir($lang);
        $name = format_string($centre->name);
        $html = '<!DOCTYPE html><html lang="' . s($lang) . '" dir="' . $dir . '"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width, initial-scale=1"></head>' .
            '<body style="margin:0; padding:24px 12px; background:#f6f1ea; color:#2b2226; ' .
            'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">' .
            '<div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:14px; border-top:6px solid ' .
            s($colour) . '; padding:24px;">' .
            '<p style="margin:0 0 12px; font-size:13px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; ' .
            'color:#5f5459;">' . s($name) . '</p>' .
            '<p style="margin:0; font-size:17px; line-height:1.5;">' . s($lead) . '</p>' . $middlehtml .
            '<p style="margin:16px 0 0; font-size:14px; line-height:1.5; color:#5f5459;">' . s($foot) . '</p>' .
            '</div></body></html>';
        $text = $name . "\n\n" . $lead . "\n\n" . ($middletext !== '' ? $middletext . "\n\n" : '') . $foot . "\n";
        return [$html, $text];
    }
}
