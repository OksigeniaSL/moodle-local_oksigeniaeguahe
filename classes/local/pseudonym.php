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
 * stored. When a row joins two tokens of a student (the code of one and the name of the other), they become one. Which
 * families are one is worked out from all the rows at once (listhomes): an email or a phone that students of different
 * surnames share never joins them by itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pseudonym {
    /** @var string */
    const T = 'local_oksigeniaeguahe_importkey';

    /** @var array The prefix of the tokens, and the tables and fields that hold them: the imported rows, and what was
     *     already said of a family with them (what the list found of it, who studied at the centre). */
    const KINDS = [
        'household' => ['prefix' => 'HOG_', 'fields' => ['local_oksigeniaeguahe_pastcontact' => 'household',
            'local_oksigeniaeguahe_student' => 'household', 'local_oksigeniaeguahe_alumni' => 'token']],
        'student' => ['prefix' => 'ALU_', 'fields' => ['local_oksigeniaeguahe_pastcontact' => 'student',
            'local_oksigeniaeguahe_student' => 'student', 'local_oksigeniaeguahe_listmatch' => 'student']],
    ];

    /** @var int */
    protected $centreid;

    /** @var string */
    protected $secret;

    /** @var array kind:hash => token, for this import. */
    protected $cache = [];

    /** @var array A token that was joined to another => the other, for this import. */
    protected $joined = [];

    /** @var array Kind => hash => token of the keys still to be written, together. */
    protected $pending = [];

    /** @var array Kind => hash => token of the keys whose token changed, still to be written. */
    protected $changed = [];

    /** @var int The length of a key as the rows keep it: enough to tell them apart, and shorter. */
    const SHORT = 24;

    /** @var int The length of a word of a name as the rows keep it. */
    const WORD = 12;

    /** @var string[] The words of a name that join it, never a surname by themselves. */
    const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'i', 'e', 'da', 'das', 'do', 'dos', 'di', 'della', 'du', 'des',
        'van', 'von', 'der', 'den', 'ten', 'ter', 'bin', 'ben', 'ibn', 'al'];

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
     * A phone as a key: its last nine digits, so the same number with or without its country prefix is one. A number
     * written to fill the box (000000000, 600000000, 123456789) is of nobody.
     *
     * @param string $value
     * @return string|null
     */
    public static function phone(string $value): ?string {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 9) {
            return null;
        }
        $nine = substr($digits, -9);
        if (
            preg_match('/^(\d)\1{8}$/', $nine) || preg_match('/^\d0{8}$/', $nine) || in_array($nine, ['123456789', '012345678',
            '234567890', '987654321', '876543210'], true)
        ) {
            return null;
        }
        return 'phone:' . $nine;
    }

    /**
     * The words of a name that say who someone is: without accents, in lower case, without the ones that only join.
     *
     * @param string $value
     * @return string[]
     */
    public static function words(string $value): array {
        return array_values(array_unique(array_filter(
            explode(' ', grades::plain($value)),
            fn($w) => strlen($w) > 1 && !ctype_digit($w) && !in_array($w, self::PARTICLES, true)
        )));
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
     * A whole name as a key with its words in their order, for brothers and sisters whose names have the same words.
     *
     * @param string $value
     * @return string|null
     */
    public static function ordered(string $value): ?string {
        return self::name($value) === null ? null : 'ordered:' . grades::plain($value);
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
     * The keyed hash of a key of another kind, outside the tokens: the list of former students keeps only these.
     *
     * @param string $kind
     * @param string $key
     * @return string
     */
    public function keyhash(string $kind, string $key): string {
        return $this->hash($kind, $key);
    }

    /**
     * The keys of an email or a phone as the rows of the lists keep them.
     *
     * @param array $keys Keys from email() and phone(); nulls are skipped.
     * @return string[]
     */
    public function shorts(array $keys): array {
        return array_values(array_unique(array_map(
            fn($k) => substr($this->hash('household', $k), 0, self::SHORT),
            array_filter($keys)
        )));
    }

    /**
     * The words of a name as the rows of the lists keep them: never the words.
     *
     * @param string[] $words From words().
     * @return string[]
     */
    public function wordmarks(array $words): array {
        return array_values(array_unique(array_map(fn($w) => substr($this->hash('word', $w), 0, self::WORD), $words)));
    }

    /**
     * Reads at once the tokens of many keys, for a list about to come in.
     *
     * @param string $kind household or student.
     * @param array $keys
     */
    public function warm(string $kind, array $keys): void {
        global $DB;
        $hashes = [];
        foreach (array_unique(array_filter($keys)) as $key) {
            $hash = $this->hash($kind, $key);
            if (!isset($this->cache[$kind . ':' . $hash])) {
                $hashes[$hash] = true;
            }
        }
        foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
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
    }

    /**
     * A token for the family of a row while the list comes in: the one its first key already had, or a new one. It never
     * makes two families one: that is worked out afterwards, with all the rows (listhomes::settle()).
     *
     * @param array $keys Keys from email() and phone(), already read (warm()).
     * @return string|null Null without any key.
     */
    public function home(array $keys): ?string {
        $hashes = array_values(array_unique(array_map(fn($k) => $this->hash('household', $k), array_filter($keys))));
        if (!$hashes) {
            return null;
        }
        $token = null;
        foreach ($hashes as $hash) {
            $token = $token ?? $this->cache['household:' . $hash] ?? null;
        }
        $token = $token ?? self::KINDS['household']['prefix'] . random_string(16);
        foreach ($hashes as $hash) {
            if (!isset($this->cache['household:' . $hash])) {
                $this->cache['household:' . $hash] = $token;
                $this->pending['household'][$hash] = $token;
            }
        }
        return $token;
    }

    /**
     * The token a key already has, read before (warm()).
     *
     * @param string $kind
     * @param string $key
     * @return string|null
     */
    public function had(string $kind, string $key): ?string {
        return $this->cache[$kind . ':' . $this->hash($kind, $key)] ?? null;
    }

    /**
     * Gives a key its token, to be written with the others (flush()).
     *
     * @param string $kind
     * @param string $key
     * @param string|null $token Null, the key is nobody's.
     */
    public function give(string $kind, string $key, ?string $token): void {
        $hash = $this->hash($kind, $key);
        $was = $this->cache[$kind . ':' . $hash] ?? null;
        if ($was === $token) {
            return;
        }
        if (isset($this->pending[$kind][$hash])) {
            if ($token === null) {
                unset($this->pending[$kind][$hash]);
            } else {
                $this->pending[$kind][$hash] = $token;
            }
        } else if ($was !== null) {
            $this->changed[$kind][$hash] = $token;
        } else if (array_key_exists($hash, $this->changed[$kind] ?? [])) {
            $this->changed[$kind][$hash] = $token;
        } else {
            $this->pending[$kind][$hash] = $token;
        }
        if ($token === null) {
            unset($this->cache[$kind . ':' . $hash]);
        } else {
            $this->cache[$kind . ':' . $hash] = $token;
        }
    }

    /**
     * Writes the keys given while a list came in.
     */
    public function flush(): void {
        global $DB;
        $rows = [];
        foreach ($this->pending as $kind => $hashes) {
            foreach ($hashes as $hash => $token) {
                $rows[] = (object) ['centreid' => $this->centreid, 'kind' => $kind, 'keyhash' => $hash, 'token' => $token,
                    'timecreated' => time()];
            }
        }
        if ($rows) {
            $DB->insert_records(self::T, $rows);
        }
        foreach ($this->changed as $kind => $hashes) {
            foreach ($hashes as $hash => $token) {
                $where = ['centreid' => $this->centreid, 'kind' => $kind, 'keyhash' => $hash];
                if ($token === null) {
                    $DB->delete_records(self::T, $where);
                } else {
                    $DB->set_field(self::T, 'token', $token, $where);
                }
            }
        }
        $this->pending = [];
        $this->changed = [];
    }

    /**
     * A token for a new student.
     *
     * @return string
     */
    public static function newstudent(): string {
        return self::KINDS['student']['prefix'] . random_string(16);
    }

    /**
     * Makes two students one, everywhere.
     *
     * @param string $from
     * @param string $to
     */
    public function one_student(string $from, string $to): void {
        $this->merge('student', $from, $to);
    }

    /**
     * A token for a new family.
     *
     * @return string
     */
    public static function newhome(): string {
        return self::KINDS['household']['prefix'] . random_string(16);
    }

    /**
     * The tokens of many at once, each by its own keys: one search for all of them, for a whole campaign.
     *
     * @param string $kind
     * @param array $keysbyid Each id with its keys (null keys are left out).
     * @return array id => tokens, only the ids with some.
     */
    public function find_each(string $kind, array $keysbyid): array {
        global $DB;
        $owners = [];
        foreach ($keysbyid as $id => $keys) {
            foreach (array_filter($keys) as $key) {
                $owners[$this->hash($kind, $key)][$id] = true;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($owners), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $rows = $DB->get_recordset_select(
                self::T,
                "centreid = :centreid AND kind = :kind AND keyhash $in",
                $params + ['centreid' => $this->centreid, 'kind' => $kind],
                '',
                'id, keyhash, token'
            );
            foreach ($rows as $row) {
                foreach (array_keys($owners[$row->keyhash] ?? []) as $id) {
                    $out[$id][$row->token] = true;
                }
            }
            $rows->close();
        }
        return array_map('array_keys', $out);
    }

    /**
     * The tokens these keys already point to, without making any: to find a family of Eguahe in what was imported.
     *
     * @param string $kind household or student.
     * @param array $keys Keys from email(), phone(), name(), code(); nulls are skipped.
     * @return string[]
     */
    public function find(string $kind, array $keys): array {
        global $DB;
        $hashes = array_values(array_unique(array_map(fn($k) => $this->hash($kind, $k), array_filter($keys))));
        if (!$hashes) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
        return array_values(array_unique($DB->get_fieldset_select(
            self::T,
            'token',
            "centreid = :centreid AND kind = :kind AND keyhash $in",
            $params + ['centreid' => $this->centreid, 'kind' => $kind]
        )));
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
     * The token a token given before in this import is now: a later row may have shown that two were the same.
     *
     * @param string $token
     * @return string
     */
    public function current(string $token): string {
        while (isset($this->joined[$token])) {
            $token = $this->joined[$token];
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
        $this->joined[$from] = $to;
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
     * Forgets the keys nothing uses any more. The tokens in use are joined once: asked as a «NOT IN» of them, the
     * database read every imported row again for each key, which is minutes with a few years of lists.
     *
     * @param int $centreid
     */
    public static function clean(int $centreid): void {
        global $DB;
        foreach (self::KINDS as $kind => $spec) {
            $used = [];
            $params = ['centreid' => $centreid, 'kind' => $kind];
            foreach ($spec['fields'] as $table => $field) {
                $used[] = "SELECT $field AS used FROM {" . $table . "} WHERE centreid = :c$table AND $field IS NOT NULL";
                $params['c' . $table] = $centreid;
            }
            $ids = $DB->get_fieldset_sql('SELECT k.id
                                            FROM {' . self::T . '} k
                                       LEFT JOIN (' . implode(' UNION ', $used) . ') u ON u.used = k.token
                                           WHERE k.centreid = :centreid AND k.kind = :kind AND u.used IS NULL', $params);
            foreach (array_chunk($ids, 500) as $chunk) {
                $DB->delete_records_list(self::T, 'id', $chunk);
            }
        }
    }
}
