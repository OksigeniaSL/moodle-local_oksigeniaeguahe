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
 * What the families answer by mail to the letters of the centre: its mailbox (the one the letters say to answer to), read
 * every quarter of an hour, only reading (EXAMINE and BODY.PEEK: nothing is marked, moved or deleted), from where it stopped.
 * Each mail of an adult of a family of the centre goes to its file, without what it quotes, and waits for an answer; what the
 * team answers from the mailbox itself (its folder of sent mail) goes too, and the family stops waiting. When it
 * answers the invitation to an act («we cannot come», «we will be there»), it is proposed as such, for a person to confirm with
 * a touch: never noted by itself. Mail of nobody of the centre stays in the mailbox, untouched.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replies {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string What was taken in, once each, and what it proposes. */
    const T = 'local_oksigeniaeguahe_mailreply';

    /** @var int The first reading looks this far back. */
    const FIRST = 14 * DAYSECS;

    /** @var int Of a mail, its first bytes: the text, not the attachments. */
    const MAXBYTES = 300000;

    /** @var string[] Saying they cannot come, in the languages of the families, without accents and in small letters. */
    const NO = [
        '/\bno (podemos|puedo|podremos|podre|podra|podran|podriamos|iremos|vamos a (poder )?ir|asistiremos|asistire|estaremos|estare)\b/',
        '/\b(imposible|esta vez no|no nos (viene|va) bien|no sera posible|lamentablemente no|no podemos asistir)\b/',
        '/\b(can ?not|can\'?t|won\'?t be able to|will not be able to|unable to) (come|attend|make it|go)\b/',
        '/\bnao (podemos|posso|poderemos|vamos|iremos|conseguimos)\b/',
    ];

    /** @var string[] Saying they come. */
    const YES = [
        '/\b(alli|ahi|alla) estaremos\b/', '/\bestaremos (alli|ahi)\b/', '/\b(iremos|asistiremos)\b/', '/\bvamos a ir\b/',
        '/\b(contad|cuenten|cuenta) con nosotros\b/', '/\bnos apuntamos\b/', '/\bconfirmo (la )?asistencia\b/',
        '/\b(we\'?ll|we will) (be there|come|attend)\b/', '/\bcount us in\b/',
        '/\b(estaremos la|vamos sim|contem connosco|contem com a gente)\b/',
    ];

    /**
     * What a centre said of its mailbox of answers; its password, never shown again.
     *
     * @param int $centreid
     * @return array host, port, user, pass, on, error, since (when it was first read: what came before is history, already
     *     answered elsewhere), uids (by folder: [validity, last]), last (time, read, kept, error).
     */
    public static function settings(int $centreid): array {
        $saved = json_decode((string) get_config(self::C, 'replies_' . $centreid), true);
        return (is_array($saved) ? $saved : []) + ['host' => '', 'port' => 993, 'user' => '', 'pass' => '', 'on' => 0, 'error' => '',
            'since' => 0, 'uids' => [], 'last' => []];
    }

    /**
     * Keeps what a centre said.
     *
     * @param int $centreid
     * @param array $settings
     */
    protected static function keep(int $centreid, array $settings): void {
        set_config('replies_' . $centreid, json_encode($settings), self::C);
    }

    /**
     * What a person wrote for the mailbox of answers of its centre, kept and tried (it opens and logs in): read only if it did.
     * An empty password keeps the one before; an empty mailbox stops the reading.
     *
     * @param \stdClass $centre
     * @param array $in host, port, user, pass.
     * @param imapread|null $box For the tests: one already open.
     * @return string Empty when it works or was stopped; why not, if not.
     */
    public static function save(\stdClass $centre, array $in, ?imapread $box = null): string {
        $c = self::C;
        $s = self::settings((int) $centre->id);
        $s['host'] = trim((string) ($in['host'] ?? ''));
        $s['port'] = max(1, min(65535, (int) ($in['port'] ?? 993) ?: 993));
        $s['user'] = trim((string) ($in['user'] ?? ''));
        if (trim((string) ($in['pass'] ?? '')) !== '') {
            $s['pass'] = trim((string) $in['pass']);
        }
        $s['on'] = 0;
        $s['error'] = '';
        if ($s['host'] === '' || $s['user'] === '') {
            self::keep((int) $centre->id, $s);
            return '';
        }
        try {
            $box = $box ?? imapread::open($s['host'], (int) $s['port'], $s['user'], (string) $s['pass']);
            $box->folders();
            $box->close();
            $s['on'] = 1;
            // What came before it was read was answered from the mailbox itself: to the files, never waiting.
            $s['since'] = $s['since'] ?: time();
        } catch (\Throwable $e) {
            $s['error'] = get_string('replies_err_open', $c, preg_replace('/\S+@\S+/', '…', \core_text::substr($e->getMessage(), 0, 200)));
        }
        self::keep((int) $centre->id, $s);
        return $s['error'];
    }

    /**
     * Reads the mailbox of answers of every centre that has one.
     *
     * @param int $now
     * @return int How many answers were taken in.
     */
    public static function read_all(int $now): int {
        $kept = 0;
        foreach (centres::all() as $centre) {
            if (empty($centre->practice) && !empty(self::settings((int) $centre->id)['on'])) {
                $kept += self::read($centre, $now)['kept'];
            }
        }
        return $kept;
    }

    /**
     * Reads what came since the last time to the inbox of a centre, and takes in what families wrote.
     *
     * @param \stdClass $centre
     * @param int $now
     * @param imapread|null $box Already open, for the tests.
     * @return array read, kept (mail of families taken in), answered (families answered from the mailbox) and error.
     */
    public static function read(\stdClass $centre, int $now, ?imapread $box = null): array {
        $out = ['read' => 0, 'kept' => 0, 'answered' => 0, 'error' => ''];
        $s = self::settings((int) $centre->id);
        try {
            $box = $box ?? imapread::open($s['host'], (int) $s['port'], $s['user'], (string) $s['pass']);
            $folder = 'INBOX';
            $validity = $box->examine($folder);
            [$was, $last] = array_pad((array) ($s['uids'][$folder] ?? []), 2, 0);
            if ((string) $was !== (string) $validity) {
                $last = 0;
            }
            foreach ($box->since((int) $last, $last ? 0 : $now - self::FIRST) as $uid) {
                $out['read']++;
                $raw = $box->fetch($uid, self::MAXBYTES);
                $out['kept'] += self::take($centre, inbound::parse($raw), $now, (int) $s['since'], self::attachments($raw)) ? 1 : 0;
                $last = max((int) $last, $uid);
            }
            $s['uids'][$folder] = [$validity, $last];
            // What the team answered from the mailbox itself, without Eguahe: to the file, and the family stops waiting.
            $sent = $box->sent();
            if ($sent !== '') {
                $validity = $box->examine($sent);
                [$was, $last] = array_pad((array) ($s['uids'][$sent] ?? []), 2, 0);
                if ((string) $was !== (string) $validity) {
                    $last = 0;
                }
                foreach ($box->since((int) $last, $last ? 0 : $now - self::FIRST) as $uid) {
                    $out['read']++;
                    $out['answered'] = ($out['answered'] ?? 0) + self::take_sent($centre, $box->fetch($uid, self::MAXBYTES), $now);
                    $last = max((int) $last, $uid);
                }
                $s['uids'][$sent] = [$validity, $last];
            }
            $box->close();
        } catch (\Throwable $e) {
            // Said without the password or any address.
            $out['error'] = preg_replace('/\S+@\S+/', '…', \core_text::substr($e->getMessage(), 0, 200));
        }
        $s = ['uids' => $s['uids'], 'last' => ['time' => $now] + $out] + self::settings((int) $centre->id);
        self::keep((int) $centre->id, $s);
        return $out;
    }

    /**
     * One mail, taken in if an adult of a family of the centre wrote it: in its file, without what it quotes, waiting for an
     * answer; and, if it answers an invitation, what it seems to say, proposed. Once each.
     *
     * @param \stdClass $centre
     * @param array $mail As inbound::parse() gives it.
     * @param int $now
     * @param int $since Before this, mail was answered from the mailbox itself: it goes to the file without waiting.
     * @param string[] $files The names of the files it carries (attachments()): said in its line, and documents of admission
     *     proposed as asking for a place.
     * @return bool Whether it was taken in.
     */
    public static function take(\stdClass $centre, array $mail, int $now, int $since = 0, array $files = []): bool {
        global $DB;
        $from = \core_text::strtolower(trim((string) ($mail['from'] ?? '')));
        if (
            !validate_email($from) || $mail['automatic'] !== '' || preg_match('/^(mailer-daemon|postmaster)@/', $from) ||
                invites::of_centre_address($from, invites::centre_addresses($centre))
        ) {
            return false;
        }
        $adult = $DB->get_records_select(
            households::ADULTS,
            'centreid = :centre AND ' . $DB->sql_equal('email', ':email', false),
            ['centre' => $centre->id, 'email' => $from],
            'id ASC',
            'id, householdid',
            0,
            1
        );
        $adult = reset($adult);
        $household = $adult ? households::get((int) $adult->householdid, (int) $centre->id) : null;
        if (!$household) {
            return false;
        }
        $key = hash('sha256', $centre->id . '|' . ((string) $mail['messageid'] !== '' ? $mail['messageid'] :
            $from . '|' . $mail['time'] . '|' . $mail['subject']));
        if ($DB->record_exists(self::T, ['centreid' => $centre->id, 'msgkey' => $key])) {
            return false;
        }
        $text = self::own_text((string) $mail['text']);
        if ($text === '') {
            $text = trim((string) $mail['subject']);
        }
        $said = $text;
        if ($files) {
            // The files stay in the mailbox; their names, in the line, so the team knows what came.
            $text = trim($text . "\n\n" . get_string('replies_attached', self::C, implode(', ', $files)));
        }
        $time = (int) $mail['time'] ?: $now;
        households::received($household, 'email', $text, $time >= $since, min($time, $now), 0, \core_text::substr(
            (string) $mail['subject'],
            0,
            255
        ), $from);
        $contactid = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {' . households::CONTACTS . '} WHERE householdid = ? AND direction = ?',
            [$household->id, 'in']
        );
        [$eventid, $proposal] = self::proposal($household, (string) $mail['subject'], $said, $now);
        if ($proposal === '' && self::documents($household, (string) $mail['subject'] . ' ' . $said, $files)) {
            $proposal = 'doc';
        }
        $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'householdid' => $household->id, 'contactid' => $contactid,
            'msgkey' => $key, 'eventid' => $eventid, 'proposal' => $proposal, 'state' => '', 'timecreated' => $now]);
        return true;
    }

    /**
     * A mail the team sent from the mailbox of the centre, without Eguahe: to the file of each family of the centre it went to,
     * as said by the team, without what it quotes; a family that waited since before it, no longer waits. Once each.
     *
     * @param \stdClass $centre
     * @param string $raw The whole message.
     * @param int $now
     * @return int How many families it answered.
     */
    public static function take_sent(\stdClass $centre, string $raw, int $now): int {
        global $DB;
        $mail = inbound::parse($raw);
        $flat = preg_replace("/\r?\n[ \t]+/", ' ', (string) strstr(str_replace("\r\n", "\n", $raw) . "\n\n", "\n\n", true));
        $to = [];
        foreach (['to', 'cc'] as $header) {
            if (
                preg_match('/^' . $header . ':\s*(.+)$/im', $flat, $m) &&
                    preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $m[1], $found)
            ) {
                $to = array_merge($to, array_map('core_text::strtolower', $found[0]));
            }
        }
        $text = self::own_text((string) $mail['text']);
        $time = min((int) $mail['time'] ?: $now, $now);
        $answered = 0;
        foreach (array_unique($to) as $email) {
            $adults = $DB->get_records_select(
                households::ADULTS,
                'centreid = :centre AND ' . $DB->sql_equal('email', ':email', false),
                ['centre' => $centre->id, 'email' => $email],
                'id ASC',
                'id, householdid',
                0,
                1
            );
            $adult = reset($adults);
            $household = $adult ? households::get((int) $adult->householdid, (int) $centre->id) : null;
            $key = hash('sha256', $centre->id . '|sent|' . $email . '|' . ((string) $mail['messageid'] !== '' ? $mail['messageid'] :
                $time . '|' . $mail['subject']));
            if (!$household || $text === '' || $DB->record_exists(self::T, ['centreid' => $centre->id, 'msgkey' => $key])) {
                continue;
            }
            $waiting = (int) $household->waitingsince;
            if ($waiting && $time >= $waiting) {
                households::answered($household, 0, 'email', $text, $time);
                $answered++;
            } else {
                households::told($household, 0, 'email', $text, false, $time);
            }
            $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'householdid' => $household->id, 'contactid' => 0,
                'msgkey' => $key, 'eventid' => 0, 'proposal' => '', 'state' => 'sent', 'timecreated' => $now]);
        }
        return $answered;
    }

    /**
     * What a mail says of itself, without what it quotes: the text before the line that opens the quoted one («El jueves …
     * escribió:», «On … wrote:», «-----Original Message-----»), the lines begun with «>» and the signature.
     *
     * @param string $text
     * @return string
     */
    public static function own_text(string $text): string {
        $text = str_replace("\r\n", "\n", $text);
        // A quoting line some programs fold in two («El jue, 26 nov 2026 a las 18:11, Colegio (<a@b>)» and «escribió:»).
        $text = preg_replace('/\n(escribi[oó]|wrote|escreveu):\s*\n/u', " $1:\n", $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $plain = trim($line);
            if (
                preg_match(inbound::QUOTED, $plain) || preg_match('/^-{2,}\s*(original message|mensaje original|mensagem original)/iu', $plain) ||
                    preg_match(inbound::FORWARDED, $plain) && $plain !== '' || $plain === '--'
            ) {
                break;
            }
            if (strpos($plain, '>') === 0) {
                continue;
            }
            $out[] = rtrim($line);
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }

    /**
     * Whether a mail answers the invitation to an act of the family, and what it seems to say: of the acts still to come whose
     * letter it had, the one its subject names, or the last that went.
     *
     * @param \stdClass $household
     * @param string $subject
     * @param string $text
     * @param int $now
     * @return array [the act (0 for none), yes, no or empty]
     */
    public static function proposal(\stdClass $household, string $subject, string $text, int $now): array {
        global $DB;
        $said = self::says($text);
        if ($said === '') {
            return [0, ''];
        }
        $rows = $DB->get_records_sql(
            'SELECT i.eventid, i.reply, i.mailsent, i.timecreated, e.name
               FROM {' . invites::T . '} i
               JOIN {' . events::T . '} e ON e.id = i.eventid
              WHERE i.householdid = ? AND e.timestart > ? AND e.open = 1 AND COALESCE(e.cancelled, 0) = 0
           ORDER BY i.mailsent DESC, i.timecreated DESC',
            [$household->id, $now]
        );
        $plain = fn(string $s) => \core_text::strtolower(\core_text::specialtoascii($s));
        foreach ($rows as $row) {
            if (str_contains($plain($subject), $plain(format_string($row->name)))) {
                return (string) $row->reply === $said ? [0, ''] : [(int) $row->eventid, $said];
            }
        }
        $row = reset($rows);
        return $row && (string) $row->reply !== $said ? [(int) $row->eventid, $said] : [0, ''];
    }

    /**
     * The names of the files a mail carries: what goes as an attachment, and documents (a PDF, an image of a document), never
     * the little pictures of a signature.
     *
     * @param string $raw The whole message.
     * @return string[]
     */
    public static function attachments(string $raw): array {
        $out = [];
        $flat = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw));
        preg_match_all('/^content-(?:disposition|type):[^\n]*?\b(?:file)?name\*?=\s*"?([^";\n]+)"?/im', $flat, $m);
        foreach ($m[1] as $name) {
            $name = trim((string) iconv_mime_decode(trim($name), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
            $name = preg_replace("/^utf-8''/i", '', rawurldecode($name));
            if ($name === '' || preg_match('/^(image|outlook|logo)[\w-]*\.(png|gif|jpe?g)$/i', $name)) {
                continue;
            }
            $out[$name] = \core_text::substr(clean_param($name, PARAM_FILE), 0, 80);
        }
        return array_values(array_filter($out));
    }

    /**
     * Whether what a family sent by mail are the documents of asking for a place: files, with words of it or a PDF, from a
     * family with a child still asking or visiting.
     *
     * @param \stdClass $household
     * @param string $text Its subject and its text.
     * @param string[] $files
     * @return bool
     */
    protected static function documents(\stdClass $household, string $text, array $files): bool {
        global $DB;
        if (!$files) {
            return false;
        }
        $plain = \core_text::strtolower(\core_text::specialtoascii($text));
        $words = (bool) preg_match('/documentaci|solicitud|matricul|inscripci|admisi|impreso|formulario|certificado|\bdni\b|' .
            'libro de familia|empadronamiento|documents?|application|form|matricula|candidatura/', $plain);
        $pdf = (bool) array_filter($files, fn($f) => preg_match('/\.pdf$/i', $f));
        if (!$words && !$pdf) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal(['inquiry', 'visit']);
        return $DB->record_exists_select(households::CHILDREN, 'householdid = ? AND stage ' . $in, array_merge([$household->id], $params));
    }

    /**
     * Whether a text says they come or not: yes, no, or empty when it says neither, or both.
     *
     * @param string $text
     * @return string
     */
    public static function says(string $text): string {
        $plain = ' ' . preg_replace('/[^a-z0-9\' ]+/', ' ', \core_text::strtolower(\core_text::specialtoascii($text))) . ' ';
        $no = (bool) array_filter(self::NO, fn($re) => preg_match($re, $plain));
        $yes = (bool) array_filter(self::YES, fn($re) => preg_match($re, $plain));
        return $no && !$yes ? 'no' : ($yes && !$no ? 'yes' : '');
    }

    /**
     * What waits to be confirmed in the file of a family: an answer by mail to an invitation, with what it seems to say.
     *
     * @param \stdClass $household
     * @param bool $canact
     * @return array|false
     */
    public static function context(\stdClass $household, bool $canact) {
        global $DB;
        $rows = $DB->get_records_select(
            self::T,
            "householdid = ? AND proposal <> '' AND state = ''",
            [$household->id],
            'id DESC'
        );
        $out = [];
        foreach ($rows as $row) {
            if ($row->proposal === 'doc') {
                // Only while a child is still asking or visiting: once on, there is nothing to confirm.
                [$in, $params] = $DB->get_in_or_equal(['inquiry', 'visit']);
                if ($DB->record_exists_select(households::CHILDREN, 'householdid = ? AND stage ' . $in, array_merge([$household->id], $params))) {
                    $out[] = ['id' => (int) $row->id, 'text' => get_string('replies_seems_doc', self::C),
                        'yes' => get_string('replies_confirm_doc', self::C), 'canact' => $canact];
                }
                continue;
            }
            $event = $DB->get_record(events::T, ['id' => $row->eventid], 'id, name, timestart, open');
            if (!$event || (int) $event->timestart <= time()) {
                continue;
            }
            $name = format_string($event->name);
            $out[] = ['id' => (int) $row->id, 'text' => get_string('replies_seems_' . $row->proposal, self::C, $name),
                'yes' => get_string('replies_confirm_' . $row->proposal, self::C), 'canact' => $canact];
        }
        return $out ? ['items' => $out] : false;
    }

    /**
     * A person confirms what an answer by mail seemed to say, or that it was not that.
     *
     * @param \stdClass $household
     * @param int $id
     * @param bool $right
     * @param int $userid
     * @return bool Whether it was done.
     */
    public static function confirm(\stdClass $household, int $id, bool $right, int $userid): bool {
        global $DB;
        $row = $DB->get_record(self::T, ['id' => $id, 'householdid' => $household->id, 'state' => '']);
        if (!$row) {
            return false;
        }
        if ($right && $row->proposal === 'doc') {
            // What they sent is the asking for a place: each child that was still asking or visiting, now asking for a place.
            foreach ($DB->get_records(households::CHILDREN, ['householdid' => $household->id]) as $child) {
                if (in_array($child->stage, ['inquiry', 'visit'], true)) {
                    households::set_stage($child, 'application');
                }
            }
        } else if ($right) {
            $event = $DB->get_record(events::T, ['id' => $row->eventid]);
            $time = (int) $DB->get_field(households::CONTACTS, 'timecreated', ['id' => $row->contactid]) ?: time();
            if (!$event || !invites::reply($event, $household, $row->proposal, 0, $time)) {
                return false;
            }
            invites::note_way($event, $household, 'email');
        }
        $DB->update_record(self::T, (object) ['id' => $row->id, 'state' => $right ? 'done' : 'dismissed', 'saidby' => $userid,
            'timesaid' => time()]);
        return true;
    }

    /**
     * What the settings say of the mailbox, in a line.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function reading(\stdClass $centre): string {
        $c = self::C;
        $s = self::settings((int) $centre->id);
        if (empty($s['on'])) {
            return get_string('replies_read_off', $c);
        }
        $last = $s['last'];
        return empty($last['time']) ? get_string('replies_read_on', $c, $s['user']) : get_string('replies_read_last', $c, (object) [
            'user' => $s['user'], 'when' => userdate((int) $last['time'], get_string('strftimedatetimeshort', 'langconfig')),
            'kept' => (int) ($last['kept'] ?? 0)]);
    }
}
