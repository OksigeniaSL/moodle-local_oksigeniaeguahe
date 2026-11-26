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
 * A mailbox read by IMAP over TLS, only reading (EXAMINE and BODY.PEEK: nothing is marked, moved or deleted), with what the
 * returned mail needs: the folders where it lands (the inbox, the trash, the spam), the returned mail since the last one read,
 * and each one. PHP has no IMAP of its own since 8.4, so it speaks the few commands it needs.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imapread {
    /** @var resource|null */
    protected $socket;

    /** @var int */
    protected $tag = 0;

    /**
     * Opens the mailbox and logs in.
     *
     * @param string $host
     * @param int $port
     * @param string $user
     * @param string $pass
     * @return self
     * @throws \moodle_exception If it cannot connect or log in.
     */
    public static function open(string $host, int $port, string $user, string $pass): self {
        $box = new self();
        $socket = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $error, 20);
        if (!$socket) {
            throw new \moodle_exception('bounces_noconnect', 'local_oksigeniaeguahe', '', $host);
        }
        stream_set_timeout($socket, 30);
        fgets($socket);
        $box->socket = $socket;
        $answer = $box->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        if (!$box->ok($answer)) {
            $box->close();
            throw new \moodle_exception('bounces_nologin', 'local_oksigeniaeguahe', '', $host);
        }
        return $box;
    }

    /**
     * The folders to look in: the inbox, and the trash and the spam by their marks, whatever they are called.
     *
     * @return string[]
     */
    public function folders(): array {
        $out = ['INBOX'];
        foreach (preg_split('/\r?\n/', $this->command('LIST "" "*"')) as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', trim($line), $m) && preg_match('/\\\\(Trash|Junk)\b/i', $m[1])) {
                $out[] = trim($m[2], '"');
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The folder of the mail sent, by its mark, whatever it is called («[Gmail]/Enviados», «Sent Mail», «Sent»).
     *
     * @return string Empty when the mailbox has none.
     */
    public function sent(): string {
        foreach (preg_split('/\r?\n/', $this->command('LIST "" "*"')) as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', trim($line), $m) && preg_match('/\\\\Sent\b/i', $m[1])) {
                return trim($m[2], '"');
            }
        }
        return '';
    }

    /**
     * Opens a folder only to read.
     *
     * @param string $folder
     * @return string Its UIDVALIDITY: when it changes, its numbers start again.
     */
    public function examine(string $folder): string {
        $answer = $this->command('EXAMINE ' . self::quote($folder));
        return preg_match('/UIDVALIDITY (\d+)/i', $answer, $m) ? $m[1] : '0';
    }

    /**
     * The returned mail of the folder open: after a number, or since a day.
     *
     * @param int $after The last number read; 0 for none.
     * @param int $since A time; 0 when there is a number.
     * @return int[]
     */
    public function returned(int $after, int $since): array {
        $from = 'OR FROM "mailer-daemon" FROM "postmaster"';
        $where = $after ? 'UID ' . ($after + 1) . ':* ' . $from : 'SINCE ' . date('j-M-Y', $since) . ' ' . $from;
        $answer = $this->command('UID SEARCH ' . $where);
        $found = preg_match('/^\* SEARCH ?([\d ]*)/mi', $answer, $m) ? trim($m[1]) : '';
        $uids = array_map('intval', preg_split('/\s+/', $found, -1, PREG_SPLIT_NO_EMPTY));
        // «n:*» gives the last one even when it is older.
        return array_values(array_filter($uids, fn($uid) => $uid > $after));
    }

    /**
     * All the mail of the folder open: after a number, or since a day.
     *
     * @param int $after The last number read; 0 for none.
     * @param int $since A time; 0 when there is a number.
     * @return int[]
     */
    public function since(int $after, int $since): array {
        $where = $after ? 'UID ' . ($after + 1) . ':*' : 'SINCE ' . date('j-M-Y', $since);
        $answer = $this->command('UID SEARCH ' . $where);
        $found = preg_match('/^\* SEARCH ?([\d ]*)/mi', $answer, $m) ? trim($m[1]) : '';
        $uids = array_map('intval', preg_split('/\s+/', $found, -1, PREG_SPLIT_NO_EMPTY));
        // «n:*» gives the last one even when it is older.
        return array_values(array_filter($uids, fn($uid) => $uid > $after));
    }

    /**
     * One message, its first bytes, without marking it as read.
     *
     * @param int $uid
     * @param int $max
     * @return string
     */
    public function fetch(int $uid, int $max): string {
        $answer = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . $max . '>)');
        $start = strpos($answer, "}\r\n");
        return $start === false ? '' : substr($answer, $start + 3);
    }

    /**
     * Logs out.
     */
    public function close(): void {
        if ($this->socket) {
            @$this->command('LOGOUT');
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Sends a command and reads its whole answer, literals included.
     *
     * @param string $command
     * @return string
     */
    protected function command(string $command): string {
        if (!$this->socket) {
            return '';
        }
        $tag = 'e' . (++$this->tag);
        fwrite($this->socket, $tag . ' ' . $command . "\r\n");
        $out = '';
        while (($line = fgets($this->socket)) !== false) {
            $out .= $line;
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $left = (int) $m[1];
                while ($left > 0 && ($chunk = fread($this->socket, min(8192, $left))) !== false && $chunk !== '') {
                    $out .= $chunk;
                    $left -= strlen($chunk);
                }
                continue;
            }
            if (strpos($line, $tag . ' ') === 0) {
                break;
            }
        }
        return $out;
    }

    /**
     * Whether the last line of an answer is OK.
     *
     * @param string $answer
     * @return bool
     */
    protected function ok(string $answer): bool {
        return (bool) preg_match('/^e\d+ OK/m', $answer);
    }

    /**
     * A string for IMAP, between quotes.
     *
     * @param string $value
     * @return string
     */
    protected static function quote(string $value): string {
        return '"' . addcslashes($value, "\"\\") . '"';
    }
}
