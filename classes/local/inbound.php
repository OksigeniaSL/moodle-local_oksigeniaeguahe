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
        'mensagem original|^\s*-{3,}\s*$)/imu';

    /**
     * Reads an email.
     *
     * @param string $raw The whole message, headers and body.
     * @return array from, name, subject, time, messageid, text.
     */
    public static function parse(string $raw): array {
        [$headers, $body] = self::split($raw);
        [$from, $name] = self::address((string) ($headers['from'] ?? ''));
        $text = self::text($headers, $body);
        return [
            'from' => $from,
            'name' => $name,
            'subject' => trim(self::decode((string) ($headers['subject'] ?? ''))),
            'time' => (int) (strtotime((string) ($headers['date'] ?? '')) ?: 0),
            'messageid' => trim((string) ($headers['message-id'] ?? ''), " <>\t"),
            'text' => $text,
        ];
    }

    /**
     * What came in an email, as the answers of a form: the fields of a web form, who wrote, and the rest as text.
     *
     * @param array $mail As parse() gives it.
     * @param string[]|null $trusted The addresses whose mail may speak for someone else (the team of the centre and its
     *     own address): only a message they forward, or a web form they send, says who wrote in its text. Null, all (the
     *     tests and what came before).
     * @return array question => answer
     */
    public static function answers(array $mail, ?array $trusted = null): array {
        $answers = [];
        $rest = [];
        $text = $mail['text'];
        $vouched = $trusted === null || self::vouched($mail, $trusted);
        // A message forwarded by hand: the first who wrote, and what they wrote.
        $sender = [$mail['from'], $mail['name']];
        if ($vouched && preg_match(self::FORWARDED, $text, $m, PREG_OFFSET_CAPTURE)) {
            $after = substr($text, $m[0][1]);
            if (preg_match('/^\s*(?:de|from|von|da)\s*:\s*(.+)$/imu', $after, $who)) {
                $sender = self::address($who[1]);
                $text = $after;
            }
        }
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(>|--\s*$)/', $line)) {
                continue;
            }
            // The headers of the forwarded message are not part of what they wrote.
            if (preg_match('/^(de|from|para|to|enviado|sent|date|fecha|asunto|subject|cc)\s*:/iu', $line)) {
                continue;
            }
            if (
                preg_match('/^([^:\n]{2,60}?)\s*:\s*(.+)$/u', $line, $field) && recognise::header_role($field[1]) !== null &&
                !isset($answers[$field[1]])
            ) {
                $answers[trim($field[1])] = trim($field[2]);
            } else if (!preg_match(self::FORWARDED, $line)) {
                $rest[] = $line;
            }
        }
        if (!$vouched) {
            // Anyone can write anything in the text of an email: the address of the family is the one that sent it.
            foreach (array_keys($answers) as $question) {
                if (recognise::header_role((string) $question) === 'email') {
                    unset($answers[$question]);
                }
            }
        }
        $roles = array_map([recognise::class, 'header_role'], array_keys($answers));
        if (!in_array('email', $roles, true) && $sender[0] !== '' && !preg_match(self::MACHINES, $sender[0])) {
            $answers['Email'] = $sender[0];
        }
        if (!array_intersect(['fullname', 'firstname'], $roles) && $sender[1] !== '' && !preg_match(self::MACHINES, $sender[0])) {
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
