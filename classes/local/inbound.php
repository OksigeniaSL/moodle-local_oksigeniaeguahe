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
 * An email that reaches an entry of a centre, as it comes: who sent it (or, in a message forwarded by hand, who wrote
 * it first), its subject and its text. The forms of a web («Nombre: …, Teléfono: …») are read field by field. Only the
 * text is read: attachments are not kept.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inbound {
    /** @var string Senders that are machines, not families: the notices of the forms of a web. */
    const MACHINES = '/^(no-?reply|do-?not-?reply|wordpress|mailer|notifications?|forms?|webmaster|bounce|postmaster)\b/i';

    /** @var string[] Domains anyone can have an address in: never trusted as a whole. */
    const OPEN_DOMAINS = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'live.com',
        'yahoo.com', 'yahoo.es', 'icloud.com', 'me.com', 'proton.me', 'protonmail.com', 'gmx.com', 'aol.com', 'msn.com'];

    /** @var string The line that opens a message forwarded by hand. */
    const FORWARDED = '/(forwarded message|mensaje reenviado|mensagem encaminhada|original message|mensaje original|' .
        'mensagem original|^\s*[-_]{3,}\s*$)/imu';

    /** @var string The line of a forwarded or quoted message that says who wrote it. */
    const WROTE = '/^[ \t>*]*(?:de|from|von|da)\s*\*?\s*:\s*\*?\s*(.*@.*)$/imu';

    /** @var string The other lines of its headers. */
    const HEADERS = '/^[ \t>*]*(de|from|von|da|para|to|an|a|enviado(?: el)?|enviada(?: em)?|sent|gesendet|date|fecha|data|asunto|' .
        'subject|assunto|betreff|cc|cco|bcc|responder a|reply-to)\s*\*?\s*:/iu';

    /** @var string The line over what is quoted in a reply: «El lunes, 3 de noviembre, Ana escribió:». */
    const QUOTED = '/^(el|on|em|no dia|le|am)\b.{4,200}(escribi[oó]|wrote|escreveu|a écrit|schrieb)\s*:?\s*$/iu';

    /**
     * Reads an email.
     *
     * @param string $raw The whole message, headers and body.
     * @return array from, name, subject, time, messageid, text, replyto and automatic (reply, an answer nobody wrote; list, a
     *     newsletter or a notice to many; or empty).
     */
    public static function parse(string $raw): array {
        [$headers, $body] = self::split($raw);
        [$from, $name] = self::address((string) ($headers['from'] ?? ''));
        $text = self::text($headers, $body);
        $auto = strtolower(trim((string) ($headers['auto-submitted'] ?? '')));
        $bulk = preg_match('/^(bulk|list|junk)/i', (string) ($headers['precedence'] ?? '')) || isset($headers['list-unsubscribe']) ||
            isset($headers['list-id']);
        $replied = strpos($auto, 'auto-replied') === 0 || isset($headers['x-autoreply']) || isset($headers['x-autorespond']) ||
            preg_match('/^auto_?reply/i', (string) ($headers['precedence'] ?? ''));
        return [
            'from' => $from,
            'name' => $name,
            'subject' => trim(self::decode((string) ($headers['subject'] ?? ''))),
            'time' => (int) (strtotime((string) ($headers['date'] ?? '')) ?: 0),
            'messageid' => trim((string) ($headers['message-id'] ?? ''), " <>\t"),
            'text' => $text,
            'replyto' => self::address((string) ($headers['reply-to'] ?? ''))[0],
            'automatic' => $replied ? 'reply' : ($bulk || ($auto !== '' && $auto !== 'no') ? 'list' : ''),
        ];
    }

    /**
     * Whether nobody wrote an email: an answer of a mailbox, a newsletter. The notice of a form of the web of the centre, or of
     * a program that says whom to answer, is not.
     *
     * @param array $mail As parse() gives it.
     * @param string[] $trusted
     * @return bool
     */
    public static function nobody_wrote(array $mail, array $trusted): bool {
        $kind = (string) ($mail['automatic'] ?? '');
        if ($kind === 'reply') {
            return true;
        }
        return $kind === 'list' && !self::vouched($mail, $trusted) &&
            !(preg_match(self::MACHINES, (string) $mail['from']) && (string) ($mail['replyto'] ?? '') !== '');
    }

    /**
     * The first message forwarded or quoted inside a text: who wrote it, what goes before it and what it says. It is known by
     * the line that opens it or by its headers (who wrote it and, right after, when or about what), as the programs that put
     * no line do.
     *
     * @param string $text
     * @return array|null before, who (address and name), body
     */
    protected static function inner(string $text): ?array {
        $lines = preg_split('/\R/u', $text);
        $opened = null;
        foreach ($lines as $i => $line) {
            if (preg_match(self::FORWARDED, $line)) {
                $opened = $i;
                continue;
            }
            if (!preg_match(self::WROTE, $line, $m)) {
                continue;
            }
            // Its headers, with nothing between them but other headers.
            $end = $i + 1;
            $said = 0;
            while ($end < count($lines) && preg_match(self::HEADERS, $lines[$end])) {
                $said++;
                $end++;
            }
            if (!$said && ($opened === null || $opened < $i - 3)) {
                continue;
            }
            $from = $opened !== null && $opened >= $i - 3 ? $opened : $i;
            return ['before' => trim(implode("\n", array_slice($lines, 0, $from))), 'who' => self::address($m[1]),
                'body' => trim(implode("\n", array_slice($lines, $end)))];
        }
        return null;
    }

    /**
     * What came in an email, as the answers of a form: the fields of a web form, who wrote, and the rest as text.
     *
     * @param array $mail As parse() gives it.
     * @param string[]|null $trusted The addresses whose mail may speak for someone else (the team of the centre and its
     *     own address): only a message they forward, or a web form they send, says who wrote in its text. Null, all (the
     *     tests and what came before).
     * @param \stdClass|null $centre Its own address and phone are never of a family.
     * @return array question => answer; empty when a trusted address sent it and it cannot be told whose it is.
     */
    public static function answers(array $mail, ?array $trusted = null, ?\stdClass $centre = null): array {
        $answers = [];
        $rest = [];
        $text = $mail['text'];
        $vouched = $trusted === null || self::vouched($mail, $trusted);
        $ours = fn(string $email) => $email !== '' && $trusted !== null && self::vouched(['from' => $email], $trusted);
        $machine = (bool) preg_match(self::MACHINES, $mail['from']);
        $replyto = (string) ($mail['replyto'] ?? '');
        $sender = [$mail['from'], $mail['name']];
        $found = !($vouched && $trusted !== null);
        // A message forwarded by hand: the first who wrote and is not of the centre, and what they wrote.
        for ($i = 0, $inner = self::inner($text); $vouched && $inner && $i < 5; $i++, $inner = self::inner($inner['body'])) {
            if ($inner['who'][0] !== '' && !$ours($inner['who'][0])) {
                $sender = $inner['who'];
                $text = $inner['body'];
                $found = true;
                break;
            }
        }
        // What they quote under their answer is of the centre, not theirs.
        $inner = self::inner($text);
        if ($inner && $inner['before'] !== '' && $ours($inner['who'][0])) {
            $text = $inner['before'];
        }
        if (($vouched || $machine) && $replyto !== '' && !$ours($replyto) && !preg_match(self::MACHINES, $replyto) && $sender[0] === $mail['from']) {
            // The notice of a form that says whom to answer; their name, where it says who wrote.
            $name = preg_match_all(self::WROTE, $text, $m) ? array_values(array_filter(
                array_map([self::class, 'address'], $m[1]),
                fn($one) => $one[0] === $replyto
            ))[0][1] ?? '' : '';
            $sender = [$replyto, $name];
            $found = true;
        }
        $fields = $found && $sender[0] !== $mail['from'];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(>|--\s*$)/', $line) || preg_match(self::QUOTED, $line)) {
                continue;
            }
            // Who wrote, at the head of the notice of a form: it is the sender already.
            if ($fields && preg_match(self::WROTE, $line) && stripos($line, $sender[0]) !== false) {
                continue;
            }
            $role = preg_match('/^([^:\n]{2,60}?)\s*:\s*(.+)$/u', $line, $field) ? recognise::header_role($field[1]) : null;
            if ($role !== null && $role !== 'date' && !isset($answers[$field[1]])) {
                $answers[trim($field[1])] = trim($field[2]);
            } else if (!preg_match(self::FORWARDED, $line)) {
                $rest[] = $line;
            }
        }
        $roleof = fn($question) => recognise::header_role((string) $question);
        $ownphones = $centre ? array_filter([phone::normalise((string) ($centre->phone ?? ''), $centre->country ?? null)]) : [];
        $ownmails = $centre ? array_filter(array_map(
            fn($e) => strtolower(trim((string) $e)),
            [$centre->email ?? '', $centre->emailsettled ?? '']
        )) : [];
        foreach ($answers as $question => $value) {
            $role = $roleof($question);
            // Anyone can write anything in the text of an email: the address of the family is the one that sent it. And those
            // of the centre, in a signature or in what is quoted, are never of the family.
            $own = ($role === 'email' && in_array(strtolower(trim($value)), $ownmails, true)) ||
                ($role === 'phone' && in_array(phone::normalise($value, $centre->country ?? null), $ownphones, true));
            if ($own || (!$vouched && $role === 'email')) {
                unset($answers[$question]);
            }
        }
        $roles = array_map($roleof, array_keys($answers));
        if (!$found && !array_intersect(['email', 'phone'], $roles)) {
            // Somebody of the centre sent it on, and nothing in it says whose it is.
            return [];
        }
        if (!in_array('email', $roles, true) && $found && $sender[0] !== '' && !preg_match(self::MACHINES, $sender[0])) {
            $answers['Email'] = $sender[0];
        }
        if (
            !array_intersect(['fullname', 'firstname'], $roles) && $found && $sender[1] !== '' &&
                !preg_match(self::MACHINES, $sender[0])
        ) {
            $answers['Nombre y apellidos'] = $sender[1];
        }
        $message = trim(($mail['subject'] !== '' ? $mail['subject'] . "\n" : '') . implode("\n", array_slice($rest, 0, 60)));
        if ($message !== '') {
            // Beside a field «Mensaje» of a web form, if there was one.
            $answers['Mensaje del correo'] = \core_text::substr($message, 0, 4000);
        }
        return $answers;
    }

    /**
     * Whether the mail comes from someone who may speak for a family: the team, the address of the centre or its domain.
     *
     * @param array $mail As parse() gives it.
     * @param string[] $trusted Addresses, and domains starting with «@».
     * @return bool
     */
    public static function vouched(array $mail, array $trusted): bool {
        $from = strtolower((string) $mail['from']);
        $at = strrpos($from, '@');
        return $from !== '' && (in_array($from, $trusted, true) || ($at !== false && in_array(substr($from, $at), $trusted, true)));
    }

    /**
     * An address as it is written: «Marta Ruiz <marta at example.com>», or only the address.
     *
     * @param string $value
     * @return array [email in lower case or '', name]
     */
    public static function address(string $value): array {
        $value = self::decode($value);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/u', $value, $m)) {
            return [strtolower(trim($m[2])), trim($m[1], " \t'\"")];
        }
        if (preg_match('/[^\s<>"]+@[^\s<>"]+/u', $value, $m)) {
            return [strtolower(trim($m[0], '.,;')), ''];
        }
        return ['', trim($value)];
    }

    /**
     * Headers and body: the headers by their name in lower case, joined when they run over several lines.
     *
     * @param string $raw
     * @return array [headers, body]
     */
    protected static function split(string $raw): array {
        $raw = str_replace("\r\n", "\n", $raw);
        $at = strpos($raw, "\n\n");
        $head = $at === false ? $raw : substr($raw, 0, $at);
        $body = $at === false ? '' : substr($raw, $at + 2);
        $headers = [];
        foreach (explode("\n", preg_replace('/\n[ \t]+/', ' ', $head)) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $headers[strtolower($m[1])] = $headers[strtolower($m[1])] ?? $m[2];
            }
        }
        return [$headers, $body];
    }

    /**
     * A header with words in another encoding («=?UTF-8?Q?…?=»), in UTF-8.
     *
     * @param string $value
     * @return string
     */
    protected static function decode(string $value): string {
        if (strpos($value, '=?') === false && preg_match('/[\x80-\xff]/', $value)) {
            // Written as it is, without encoding it: in UTF-8, or in the old one of the West.
            return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        return function_exists('iconv_mime_decode') ? (string) @iconv_mime_decode(
            $value,
            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
            'UTF-8'
        ) : $value;
    }

    /**
     * The text of a part, or of the best of its parts: the plain text, or else the HTML as text.
     *
     * @param array $headers
     * @param string $body
     * @param int $depth
     * @return string
     */
    protected static function text(array $headers, string $body, int $depth = 0): string {
        $type = strtolower((string) ($headers['content-type'] ?? 'text/plain'));
        if (
            strpos($type, 'multipart/') === 0 && $depth < 5 &&
                preg_match('/boundary="?([^";]+)"?/i', (string) $headers['content-type'], $m)
        ) {
            $plain = '';
            $html = '';
            foreach (explode('--' . $m[1], $body) as $part) {
                if (trim($part) === '' || strpos($part, '--') === 0) {
                    continue;
                }
                [$h, $b] = self::split(ltrim($part, "\r\n"));
                $parttype = strtolower((string) ($h['content-type'] ?? 'text/plain'));
                if (stripos((string) ($h['content-disposition'] ?? ''), 'attachment') !== false) {
                    continue;
                }
                $text = self::text($h, $b, $depth + 1);
                if (strpos($parttype, 'text/html') === 0) {
                    $html = $html ?: $text;
                } else if ($text !== '') {
                    $plain = $plain ?: $text;
                }
            }
            return $plain !== '' ? $plain : $html;
        }
        if (strpos($type, 'text/') !== 0) {
            return '';
        }
        $encoding = strtolower(trim((string) ($headers['content-transfer-encoding'] ?? '')));
        if ($encoding === 'base64') {
            $body = (string) base64_decode(preg_replace('/\s+/', '', $body));
        } else if ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        $charset = preg_match('/charset="?([^";\s]+)"?/i', $type, $c) ? strtoupper($c[1]) : 'UTF-8';
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $body = (string) @mb_convert_encoding($body, 'UTF-8', $charset);
        } else if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        }
        if (strpos($type, 'text/html') === 0) {
            $body = html_to_text($body, 0, false);
        }
        return trim(str_replace("\r\n", "\n", $body));
    }
}
