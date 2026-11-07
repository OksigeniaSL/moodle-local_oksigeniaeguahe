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
 * The keys that join the same family, or the same student, across the files a centre imports, without keeping who
 * they are. An email, a phone, a student code or a child's name becomes a keyed hash (HMAC-SHA256 with a secret of
 * the centre that never leaves the site), and the hash points to a token (HOG_…, ALU_…). The value itself is never
 * stored. When a row joins two tokens (the email of one and the phone of the other), they become one.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pseudonym {
    /** @var string */
    const T = 'local_oksigeniaeguahe_importkey';

    /** @var array The prefix of the tokens, and the tables and fields that hold them. */
    const KINDS = [
        'household' => ['prefix' => 'HOG_', 'fields' => ['local_oksigeniaeguahe_pastcontact' => 'household',
            'local_oksigeniaeguahe_student' => 'household']],
        'student' => ['prefix' => 'ALU_', 'fields' => ['local_oksigeniaeguahe_pastcontact' => 'student',
            'local_oksigeniaeguahe_student' => 'student']],
    ];

    /** @var int */
    protected $centreid;

    /** @var string */
    protected $secret;

    /** @var array kind:hash => token, for this import. */
    protected $cache = [];

    /**
     * For a centre.
     *
     * @param int $centreid
     */
    public function __construct(int $centreid) {
        $this->centreid = $centreid;
        $name = 'importsecret_' . $centreid;
        $secret = get_config('local_oksigeniaeguahe', $name);
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            set_config($name, $secret, 'local_oksigeniaeguahe');
        }
        $this->secret = (string) $secret;
    }

    /**
     * An email as a key: in lower case, without spaces.
     *
     * @param string $value
     * @return string|null
     */
    public static function email(string $value): ?string {
        $value = \core_text::strtolower(trim(preg_replace('/^mailto:/i', '', trim($value))));
        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value) ? 'email:' . $value : null;
    }

    /**
     * A phone as a key: its last nine digits, so the same number with or without its country prefix is one.
     *
     * @param string $value
     * @return string|null
     */
    public static function phone(string $value): ?string {
        $digits = preg_replace('/\D/', '', $value);
        return strlen($digits) >= 9 ? 'phone:' . substr($digits, -9) : null;
    }

    /**
     * A full name as a key: its words without accents, in order, so «López García, Ana» and «Ana López García» match.
     *
     * @param string $value
     * @return string|null Null for less than two words: a first name alone would join different children.
     */
    public static function name(string $value): ?string {
        $words = array_filter(explode(' ', grades::plain($value)), fn($w) => strlen($w) > 1 || ctype_digit($w));
        if (count($words) < 2) {
            return null;
        }
        sort($words);
        return 'name:' . implode(' ', $words);
    }

    /**
     * A code of the student in the systems of the centre, as a key.
     *
     * @param string $value
     * @return string|null
     */
    public static function code(string $value): ?string {
        $value = \core_text::strtolower(trim($value));
        return $value === '' || in_array($value, ['true', 'false', '#n/a', '-', '0'], true) ? null : 'id:' . $value;
    }

    /**
     * The hash of a key.
     *
     * @param string $kind household or student.
     * @param string $key
     * @return string
     */
    protected function hash(string $kind, string $key): string {
        return hash_hmac('sha256', $kind . '|' . $key, $this->secret);
    }

    /**
     * The token of the family or the student these keys point to: an existing one, a new one, or several made one.
     *
     * @param string $kind household or student.
     * @param array $keys Keys from email(), phone(), name(), code(); nulls are skipped.
     * @return string
     */
    public function token(string $kind, array $keys): string {
        global $DB;
        $hashes = array_values(array_unique(array_map(fn($k) => $this->hash($kind, $k), array_filter($keys))));
        if (!$hashes) {
            return self::KINDS[$kind]['prefix'] . random_string(16);
        }
        $tokens = [];
        $missing = [];
        foreach ($hashes as $hash) {
            if (!isset($this->cache[$kind . ':' . $hash])) {
                $missing[] = $hash;
            }
        }
        if ($missing) {
            [$in, $params] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED);
            $found = $DB->get_records_select_menu(
                self::T,
                "centreid = :centreid AND kind = :kind AND keyhash $in",
                $params + ['centreid' => $this->centreid, 'kind' => $kind],
                '',
                'keyhash, token'
            );
            foreach ($found as $hash => $token) {
                $this->cache[$kind . ':' . $hash] = $token;
            }
        }
        foreach ($hashes as $hash) {
            if (isset($this->cache[$kind . ':' . $hash])) {
                $tokens[$this->cache[$kind . ':' . $hash]] = true;
            }
        }
        $tokens = array_keys($tokens);
        $token = $tokens[0] ?? self::KINDS[$kind]['prefix'] . random_string(16);
        foreach (array_slice($tokens, 1) as $other) {
            $this->merge($kind, $other, $token);
        }
        foreach ($hashes as $hash) {
            if (!isset($this->cache[$kind . ':' . $hash])) {
                $DB->insert_record(self::T, (object) ['centreid' => $this->centreid, 'kind' => $kind, 'keyhash' => $hash,
                    'token' => $token, 'timecreated' => time()]);
                $this->cache[$kind . ':' . $hash] = $token;
            }
        }
        return $token;
    }

    /**
     * Makes two tokens one, everywhere.
     *
     * @param string $kind
     * @param string $from
     * @param string $to
     */
    protected function merge(string $kind, string $from, string $to): void {
        global $DB;
        foreach (self::KINDS[$kind]['fields'] as $table => $field) {
            $DB->set_field($table, $field, $to, ['centreid' => $this->centreid, $field => $from]);
        }
        $DB->set_field(self::T, 'token', $to, ['centreid' => $this->centreid, 'kind' => $kind, 'token' => $from]);
        foreach ($this->cache as $key => $token) {
            if ($token === $from) {
                $this->cache[$key] = $to;
            }
        }
    }

    /**
     * Forgets the keys no imported row uses any more.
     *
     * @param int $centreid
     */
    public static function clean(int $centreid): void {
        global $DB;
        foreach (self::KINDS as $kind => $spec) {
            $used = [];
            foreach ($spec['fields'] as $table => $field) {
                $used[] = "SELECT $field FROM {" . $table . "} WHERE centreid = :c$table AND $field IS NOT NULL";
            }
            $params = ['centreid' => $centreid, 'kind' => $kind];
            foreach (array_keys($spec['fields']) as $table) {
                $params['c' . $table] = $centreid;
            }
            $DB->delete_records_select(self::T, 'centreid = :centreid AND kind = :kind AND token NOT IN (' .
                implode(' UNION ', $used) . ')', $params);
        }
    }
}
