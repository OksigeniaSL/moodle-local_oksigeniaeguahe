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
 * The mail that comes back. Eguahe reads the returned mail in the mailbox that sends (the account of the outgoing mail of the
 * site, only reading), marks the address of each family that does not get it with why, in plain words, and asks a person to
 * call: they give the right address (and what did not arrive goes again), say that they want no mail, or do not answer. An
 * address written wrong on purpose stays as it is, once a person said so.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bounces {
    /** @var string */
    const T = 'local_oksigeniaeguahe_bounce';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] Why it does not arrive: the address does not exist, the mailbox is full, or the other side refuses it. */
    const KINDS = ['gone', 'full', 'refused'];

    /** @var string[] What a person said: open (to call), noanswer (called, nobody answered), fixed, nomail, leave. */
    const STATES = ['open', 'noanswer', 'fixed', 'nomail', 'leave'];

    /** @var string[] Those that still ask for a call. */
    const TOCALL = ['open', 'noanswer'];

    /** @var int The first reading looks this far back. */
    const FIRST = 14 * DAYSECS;

    /** @var int The most bytes read of one returned mail: what says why is at the start. */
    const MAXBYTES = 65536;

    /**
     * Where the returned mail is read: the mailbox of the outgoing mail of the site, by IMAP over TLS. The host is the one of
     * the setting or, without it, the one of the outgoing mail with «imap.» in place of «smtp.».
     *
     * @return array|null host, port, user and password; null if there is nowhere to read.
     */
    public static function source(): ?array {
        global $CFG;
        if (!get_config(self::C, 'bounces')) {
            return null;
        }
        $user = trim((string) ($CFG->smtpuser ?? ''));
        $pass = str_replace(' ', '', (string) ($CFG->smtppass ?? ''));
        $host = trim((string) get_config(self::C, 'bounceshost'));
        if ($host === '') {
            $first = trim(explode(';', (string) ($CFG->smtphosts ?? ''))[0]);
            $first = preg_replace('/:\d+$/', '', preg_replace('#^[a-z]+://#i', '', $first));
            $host = preg_match('/^smtp\.(.+)$/i', $first, $m) ? 'imap.' . $m[1] : '';
        }
        if ($host === '' || $user === '' || $pass === '') {
            return null;
        }
        [$host, $port] = array_pad(explode(':', $host, 2), 2, '993');
        return ['host' => $host, 'port' => (int) $port ?: 993, 'user' => $user, 'pass' => $pass];
    }

    /**
     * Reads what came back since the last time, in the inbox, the trash and the spam of the mailbox, and marks it.
     *
     * @param int $now
     * @param imapread|null $box Already open, for the tests.
     * @return array read (returned mail read), marked (addresses of families marked) and error (empty if none).
     */
    public static function read(int $now, ?imapread $box = null): array {
        $out = ['read' => 0, 'marked' => 0, 'error' => ''];
        try {
            if (!$box) {
                $source = self::source();
                if (!$source) {
                    return $out;
                }
                $box = imapread::open($source['host'], $source['port'], $source['user'], $source['pass']);
            }
            $done = (array) json_decode((string) get_config(self::C, 'bounceuids'), true);
            foreach ($box->folders() as $folder) {
                $validity = $box->examine($folder);
                [$was, $last] = array_pad((array) ($done[$folder] ?? []), 2, 0);
                if ((string) $was !== (string) $validity) {
                    $last = 0;
                }
                $uids = $box->returned($last, $last ? 0 : $now - self::FIRST);
                foreach ($uids as $uid) {
                    $dsn = self::parse($box->fetch($uid, self::MAXBYTES));
                    $out['read']++;
                    if ($dsn) {
                        $out['marked'] += self::found($dsn, $now);
                    }
                    $last = max($last, $uid);
                }
                $done[$folder] = [$validity, $last];
            }
            $box->close();
            set_config('bounceuids', json_encode($done), self::C);
        } catch (\Throwable $e) {
            // Said without the password or any address.
            $out['error'] = preg_replace('/\S+@\S+/', '…', \core_text::substr($e->getMessage(), 0, 200));
        }
        set_config('bouncesread', json_encode(['time' => $now] + $out), self::C);
        return $out;
    }

    /**
     * What a returned mail says: whom it did not reach, why, and what it was.
     *
     * @param string $raw The whole message.
     * @return array|null to (addresses), status, action, diag, kind, subject and sent (the original); null if it is not a returned
     *     mail that failed.
     */
    public static function parse(string $raw): ?array {
        $raw = str_replace("\r\n", "\n", $raw);
        // Folded lines of headers, back in one.
        $flat = preg_replace("/\n[ \t]+/", ' ', $raw);
        if (!preg_match('/^content-type:\s*multipart\/report/im', $flat) && !preg_match('/^x-failed-recipients:/im', $flat)) {
            return null;
        }
        $to = [];
        if (preg_match_all('/^(?:final-recipient|original-recipient):\s*rfc822;\s*<?([^\s>]+@[^\s>]+)>?/im', $flat, $m)) {
            $to = $m[1];
        }
        if (!$to && preg_match('/^x-failed-recipients:\s*(.+)$/im', $flat, $m)) {
            $to = preg_split('/[\s,;]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
        }
        $to = array_map(fn($a) => \core_text::strtolower(trim($a, " <>\t")), $to);
        $to = array_values(array_unique(array_filter($to, fn($a) => (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $a))));
        $action = preg_match('/^action:\s*(\S+)/im', $flat, $m) ? \core_text::strtolower($m[1]) : 'failed';
        $status = preg_match('/^status:\s*([245]\.\d{1,3}\.\d{1,3})/im', $flat, $m) ? $m[1] : '';
        $diag = preg_match('/^diagnostic-code:\s*(?:[a-z-]+;\s*)?(.+)$/im', $flat, $m) ? trim($m[1]) : '';
        if ($status === '' && preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $diag, $m)) {
            $status = $m[1];
        }
        $kind = self::kind($action, $status, $diag);
        if (!$to || $kind === null) {
            return null;
        }
        // What it was: the headers of the original, kept at the end of the report.
        $subject = '';
        $sent = 0;
        $at = max((int) strripos($flat, "\ncontent-type: text/rfc822-headers"), (int) strripos($flat, "\ncontent-type: message/rfc822"));
        if ($at) {
            $tail = substr($flat, $at);
            if (preg_match('/^subject:\s*(.+)$/im', $tail, $m)) {
                $subject = trim((string) iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
            }
            if (preg_match('/^date:\s*(.+)$/im', $tail, $m)) {
                $sent = (int) (strtotime(trim($m[1])) ?: 0);
            }
        }
        return ['to' => $to, 'status' => $status, 'action' => $action, 'diag' => \core_text::substr(clean_param($diag, PARAM_TEXT), 0, 255),
            'kind' => $kind, 'subject' => \core_text::substr(clean_param($subject, PARAM_TEXT), 0, 255), 'sent' => $sent];
    }

    /**
     * Why it does not arrive, from the code and the words of the server; null for a delay, which the server tries again.
     *
     * @param string $action failed, delayed…
     * @param string $status As 5.1.1.
     * @param string $diag
     * @return string|null gone, full or refused.
     */
    public static function kind(string $action, string $status, string $diag): ?string {
        $full = preg_match('/^[45]\.2\.2$/', $status) ||
            preg_match('/\b(quota|mailbox (is )?full|out of storage|insufficient (system )?storage|over ?quota|inbox is full)\b/i', $diag);
        if ($action !== 'failed' || ($status !== '' && $status[0] !== '5')) {
            return null;
        }
        if ($full) {
            return 'full';
        }
        if (
            preg_match('/^5\.1\.\d+$/', $status) || in_array($status, ['5.2.1', '5.4.1', '5.4.4', '5.4.310', '5.4.316'], true) ||
                preg_match('/\b(does not exist|doesn.t exist|no such user|user unknown|unknown user|invalid recipient|' .
                'recipient address rejected|mailbox unavailable|no mailbox|account (is )?disabled|address not found)\b/i', $diag)
        ) {
            return 'gone';
        }
        return 'refused';
    }

    /**
     * Marks the adults whose address did not get a mail: once each, with why and what did not arrive, and a line in the history
     * of their family. An address already said to be right, or let be, is not asked about again unless it changes.
     *
     * @param array $dsn From parse().
     * @param int $now
     * @return int How many adults were marked for the first time.
     */
    public static function found(array $dsn, int $now): int {
        global $DB;
        $new = 0;
        foreach ($dsn['to'] as $email) {
            $adults = $DB->get_records_select(
                households::ADULTS,
                $DB->sql_equal('email', ':email', false),
                ['email' => $email],
                'id ASC',
                'id, householdid, centreid, email'
            );
            foreach ($adults as $adult) {
                $key = self::key((string) $adult->email);
                $row = $DB->get_record(self::T, ['adultid' => $adult->id, 'emailkey' => $key]);
                $when = (int) ($dsn['sent'] ?: $now);
                if ($row) {
                    $changes = ['id' => $row->id, 'times' => (int) $row->times + 1, 'timebounced' => max((int) $row->timebounced, $when),
                        'kind' => $dsn['kind'], 'status' => $dsn['status'], 'diag' => $dsn['diag']];
                    if ($dsn['subject'] !== '') {
                        $changes += ['what' => $dsn['subject']];
                    }
                    if ($row->state === 'fixed') {
                        // Said right, and it came back again.
                        $changes += ['state' => 'open', 'saidby' => 0, 'timesaid' => 0];
                    }
                    $DB->update_record(self::T, (object) $changes);
                    continue;
                }
                $DB->insert_record(self::T, (object) ['centreid' => $adult->centreid, 'householdid' => $adult->householdid,
                    'adultid' => $adult->id, 'emailkey' => $key, 'kind' => $dsn['kind'], 'status' => $dsn['status'],
                    'diag' => $dsn['diag'], 'what' => $dsn['subject'] !== '' ? $dsn['subject'] : null, 'sent' => (int) $dsn['sent'],
                    'timebounced' => $when, 'times' => 1, 'state' => 'open', 'timecreated' => $now]);
                $household = $DB->get_record(households::T, ['id' => $adult->householdid]);
                if ($household) {
                    households::note($household, 0, get_string('bounce_note', self::C, (object) ['email' => $adult->email,
                        'why' => get_string('bounce_why_' . $dsn['kind'], self::C),
                        'what' => $dsn['subject'] !== '' ? $dsn['subject'] : '—']));
                }
                $new++;
            }
        }
        return $new;
    }

    /**
     * The key of an address, to know when it changed.
     *
     * @param string $email
     * @return string
     */
    public static function key(string $email): string {
        return sha1(\core_text::strtolower(trim($email)));
    }

    /**
     * The addresses of some adults that do not get the mail now: marked, and not said right since.
     *
     * @param int[] $adultids
     * @return array adult id => the row
     */
    public static function failing(array $adultids): array {
        global $DB;
        if (!$adultids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_values(array_unique(array_map('intval', $adultids))));
        $out = [];
        $rows = $DB->get_records_select(self::T, "adultid $in AND state <> ?", array_merge($params, ['fixed']), 'id ASC');
        $emails = $DB->get_records_select_menu(households::ADULTS, "id $in", $params, '', 'id, email');
        foreach ($rows as $row) {
            if (self::key((string) ($emails[$row->adultid] ?? '')) === $row->emailkey) {
                $out[(int) $row->adultid] = $row;
            }
        }
        return $out;
    }

    /**
     * The families of a centre to call because their mail comes back.
     *
     * @param int $centreid
     * @return int[] household ids, the newest first.
     */
    public static function to_call(int $centreid): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::TOCALL);
        $rows = $DB->get_records_select(
            self::T,
            "centreid = ? AND state $in",
            array_merge([$centreid], $params),
            'timebounced DESC, id DESC',
            'id, householdid, adultid'
        );
        $live = self::failing(array_column($rows, 'adultid'));
        $out = [];
        foreach ($rows as $row) {
            if (isset($live[(int) $row->adultid])) {
                $out[(int) $row->householdid] = (int) $row->householdid;
            }
        }
        return array_values($out);
    }

    /**
     * What a person says after calling: the right address (and what did not arrive goes again), no mail, nobody answered,
     * or let it be.
     *
     * @param \stdClass $household
     * @param int $bounceid
     * @param string $state fixed, nomail, noanswer or leave.
     * @param int $userid
     * @param string $email The right address, for fixed.
     * @return bool
     */
    public static function say(\stdClass $household, int $bounceid, string $state, int $userid, string $email = ''): bool {
        global $DB;
        $row = $DB->get_record(self::T, ['id' => $bounceid, 'householdid' => $household->id]);
        if (!$row || !in_array($state, ['fixed', 'nomail', 'noanswer', 'leave'], true)) {
            return false;
        }
        $adult = $DB->get_record(households::ADULTS, ['id' => $row->adultid, 'householdid' => $household->id]);
        if (!$adult) {
            return false;
        }
        $now = time();
        $centre = centres::get((int) $household->centreid);
        if ($state === 'fixed') {
            $email = \core_text::strtolower(trim($email));
            if (!validate_email($email) || self::key($email) === $row->emailkey) {
                return false;
            }
            households::set_adult_email($household, (int) $adult->id, $email, $userid);
        }
        if ($state === 'nomail' && $centre) {
            writeby::stop_email($household, $centre);
        }
        $DB->update_record(self::T, (object) ['id' => $row->id, 'state' => $state, 'saidby' => $userid, 'timesaid' => $now,
            'tries' => (int) $row->tries + ($state === 'noanswer' ? 1 : 0)]);
        households::note($household, $userid, get_string('bounce_said_' . $state, self::C, $state === 'fixed' ? $email :
            (string) $adult->email));
        if ($state === 'fixed') {
            self::again($household, $row);
        }
        return true;
    }

    /**
     * An address of the family changed (in its file or on its page): the mail that came back to the old one is said right,
     * and what did not arrive goes again.
     *
     * @param \stdClass $household
     * @param int $userid
     * @return int How many were said right.
     */
    public static function changed(\stdClass $household, int $userid): int {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::TOCALL);
        $rows = $DB->get_records_select(self::T, "householdid = ? AND state $in", array_merge([$household->id], $params));
        $n = 0;
        foreach ($rows as $row) {
            $email = (string) $DB->get_field(households::ADULTS, 'email', ['id' => $row->adultid]);
            if (trim($email) === '' || self::key($email) === $row->emailkey) {
                continue;
            }
            $DB->update_record(self::T, (object) ['id' => $row->id, 'state' => 'fixed', 'saidby' => $userid, 'timesaid' => time()]);
            households::note($household, $userid, get_string('bounce_said_fixed', self::C, $email));
            self::again($household, $row);
            $n++;
        }
        return $n;
    }

    /**
     * After the right address, what did not arrive goes again: the letter of an act still to come that was sent to it.
     *
     * @param \stdClass $household
     * @param \stdClass $row The returned mail.
     * @return int How many letters went again.
     */
    protected static function again(\stdClass $household, \stdClass $row): int {
        global $DB;
        $n = 0;
        $from = (int) ($row->sent ?: $row->timebounced) - DAYSECS;
        $invites = $DB->get_records_select(
            invites::T,
            "householdid = ? AND timecreated >= ? AND COALESCE(channel, '') IN ('', 'email', 'both')",
            [$household->id, $from],
            'id ASC'
        );
        foreach ($invites as $invite) {
            $event = $DB->get_record(events::T, ['id' => $invite->eventid]);
            if ($event && (int) $event->timestart > time() && empty($event->cancelled)) {
                $n += invites::send_again($event, $household) ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * What the file of a family shows of its mail that comes back: each address, why, since when, what did not arrive, the
     * phones to call, what to say, and the four answers.
     *
     * @param \stdClass $household
     * @param \stdClass $full From households::full().
     * @param bool $canact
     * @return array|false
     */
    public static function context(\stdClass $household, \stdClass $full, bool $canact) {
        global $DB;
        $rows = $DB->get_records(self::T, ['householdid' => $household->id], 'timebounced DESC');
        if (!$rows) {
            return false;
        }
        $live = self::failing(array_column($rows, 'adultid'));
        $c = self::C;
        $centre = centres::get((int) $household->centreid);
        $phones = [];
        foreach ($full->adults as $adult) {
            foreach (['phone', 'phone2'] as $field) {
                if (trim((string) ($adult->$field ?? '')) !== '') {
                    $phones[] = ['name' => trim($adult->firstname . ' ' . $adult->lastname), 'number' => phone::display((string) $adult->$field),
                        'href' => 'tel:' . preg_replace('/[^\d+]/', '', (string) $adult->$field)];
                }
            }
        }
        $out = [];
        foreach ($rows as $row) {
            if (!isset($live[(int) $row->adultid]) || !in_array($row->state, self::TOCALL, true)) {
                continue;
            }
            $adult = $full->adults[$row->adultid] ?? null;
            if (!$adult) {
                continue;
            }
            $out[] = ['id' => (int) $row->id, 'email' => (string) $adult->email,
                'why' => get_string('bounce_why_' . $row->kind, $c),
                'since' => userdate((int) $row->timebounced, get_string('strftimedatetimeshort', 'langconfig')),
                'what' => trim((string) $row->what) !== '' ? (string) $row->what : false,
                'tries' => (int) $row->tries ? get_string('bounce_tries', $c, (int) $row->tries) : false,
                'say' => get_string('bounce_say_' . $row->kind, $c, (object) ['email' => $adult->email,
                    'centre' => $centre ? share::from_centre($centre, current_language()) : '']),
                'code' => trim($row->status . ' ' . $row->diag)];
        }
        if (!$out) {
            return false;
        }
        return ['addresses' => $out, 'phones' => $phones, 'hasphones' => (bool) $phones, 'canact' => $canact,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id], 'correo-devuelto'))->out(false),
            'sesskey' => sesskey()];
    }

    /**
     * The families invited to an act whose mail came back after their letter went, and still to call.
     *
     * @param int $eventid
     * @return int[] household ids
     */
    public static function of_act(int $eventid): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::TOCALL);
        $rows = $DB->get_records_sql(
            'SELECT b.id, b.householdid, b.adultid
               FROM {' . self::T . '} b
               JOIN {' . invites::T . '} i ON i.householdid = b.householdid AND i.eventid = ?
              WHERE b.timebounced >= i.timecreated - 600 AND b.state ' . $in,
            array_merge([$eventid], $params)
        );
        $live = self::failing(array_column($rows, 'adultid'));
        $out = [];
        foreach ($rows as $row) {
            if (isset($live[(int) $row->adultid])) {
                $out[(int) $row->householdid] = (int) $row->householdid;
            }
        }
        return array_values($out);
    }

    /**
     * What was read last, in words, for the settings: a space and the sentence, or nothing before the first reading.
     *
     * @return string
     */
    public static function last_words(): string {
        $last = self::last();
        if (!$last) {
            return '';
        }
        $when = userdate((int) $last['time'], get_string('strftimedatetimeshort', 'langconfig'));
        return ' ' . ($last['error'] !== '' ? get_string('bounces_error', self::C, (object) ['time' => $when, 'error' => s($last['error'])]) :
            get_string('bounces_last', self::C, (object) ['time' => $when, 'read' => (int) $last['read'],
                'marked' => (int) $last['marked']]));
    }

    /**
     * What was read last, for the settings: when, how many and whether it failed.
     *
     * @return array|null
     */
    public static function last(): ?array {
        $read = json_decode((string) get_config(self::C, 'bouncesread'), true);
        return is_array($read) ? $read : null;
    }
}
