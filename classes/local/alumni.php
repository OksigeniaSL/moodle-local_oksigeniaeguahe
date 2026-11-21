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
 * Who in a family studied at the centre: a mother or a father who was a student here and now brings their children.
 * It is a fact, never a grade: what the family or a person of the team said, what a note of a sheet of the team says, a
 * column of an imported list, or the list of former students of the centre, which Eguahe keeps only as keys of the
 * names, never the names. A name that is on the list with one word more or less is only a question for a person.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class alumni {
    /** @var string Who studied at the centre, one row per adult (0, someone of the family). */
    const T = 'local_oksigeniaeguahe_alumni';

    /** @var string The list of former students, as keys of their names. */
    const LIST = 'local_oksigeniaeguahe_alumnus';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int They did not study at the centre. */
    const NO = 0;

    /** @var int They studied at the centre. */
    const YES = 1;

    /** @var int The list has a name with one word more or less: a person says if it is them. */
    const MAYBE = 2;

    /** @var string[] What a person said, which no list nor sheet says over. */
    const PEOPLE = ['family', 'team'];

    /** @var string The kind of the keyed hashes of the list. */
    const KIND = 'alumnus';

    /**
     * What is known of a family: adult id => its row; 0 for someone of the family, as a note of a sheet says it.
     *
     * @param int $householdid
     * @return \stdClass[]
     */
    public static function of(int $householdid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::T, ['householdid' => $householdid], 'id ASC') as $row) {
            $out[(int) $row->adultid] = $row;
        }
        return $out;
    }

    /**
     * Whether someone of a family studied at the centre.
     *
     * @param int $householdid
     * @return bool
     */
    public static function in_family(int $householdid): bool {
        global $DB;
        return $DB->record_exists(self::T, ['householdid' => $householdid, 'said' => self::YES]);
    }

    /**
     * The families of a centre where someone studied at it.
     *
     * @param int $centreid
     * @return array household id => true
     */
    public static function families(int $centreid): array {
        global $DB;
        $ids = $DB->get_fieldset_select(
            self::T,
            'DISTINCT householdid',
            'centreid = :centreid AND householdid > 0 AND said = :yes',
            ['centreid' => $centreid, 'yes' => self::YES]
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * The families where the list has a name like one of theirs, for a person to say.
     *
     * @param int $centreid
     * @return array household id => true
     */
    public static function maybes(int $centreid): array {
        global $DB;
        $ids = $DB->get_fieldset_select(
            self::T,
            'DISTINCT householdid',
            'centreid = :centreid AND householdid > 0 AND said = :maybe',
            ['centreid' => $centreid, 'maybe' => self::MAYBE]
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Whether a centre knows anything of its former students: its list, or someone said it.
     *
     * @param int $centreid
     * @return bool
     */
    public static function known(int $centreid): bool {
        global $DB;
        return $DB->record_exists(self::LIST, ['centreid' => $centreid]) ||
            $DB->record_exists(self::T, ['centreid' => $centreid, 'said' => self::YES]);
    }

    /**
     * How many names the list of former students of a centre has.
     *
     * @param int $centreid
     * @return int
     */
    public static function list_size(int $centreid): int {
        global $DB;
        return $DB->count_records(self::LIST, ['centreid' => $centreid, 'exact' => 1]);
    }

    /**
     * Says whether an adult studied at the centre (or someone of the family, with adult 0). What a person said is never
     * changed by a list or a sheet; null forgets it.
     *
     * @param \stdClass $household
     * @param int $adultid 0 for someone of the family.
     * @param int|null $said YES, NO, MAYBE or null.
     * @param string $source family, team, sheet, list or import.
     * @param int $userid Who of the team said it.
     * @param string $year The year they left, when it is known.
     * @param bool $note Whether a line goes to its history.
     * @return bool Whether something changed.
     */
    public static function set(
        \stdClass $household,
        int $adultid,
        ?int $said,
        string $source,
        int $userid = 0,
        string $year = '',
        bool $note = true
    ): bool {
        global $DB;
        $where = ['householdid' => (int) $household->id, 'adultid' => $adultid];
        $row = $DB->get_record(self::T, $where, '*', IGNORE_MULTIPLE);
        if ($row && !in_array($source, self::PEOPLE, true) && in_array((string) $row->source, self::PEOPLE, true)) {
            return false;
        }
        if ($said === null) {
            if (!$row) {
                return false;
            }
            $DB->delete_records(self::T, $where);
            return true;
        }
        $year = \core_text::substr(trim($year), 0, 20);
        if ($row && (int) $row->said === $said && ($year === '' || (string) $row->year === $year)) {
            return false;
        }
        $record = ['centreid' => (int) $household->centreid, 'householdid' => (int) $household->id, 'adultid' => $adultid,
            'said' => $said, 'source' => $source, 'year' => $year !== '' ? $year : ($row->year ?? null),
            'userid' => $userid, 'timecreated' => time()];
        if ($row) {
            $DB->update_record(self::T, (object) (['id' => $row->id] + $record));
        } else {
            $DB->insert_record(self::T, (object) $record);
        }
        if ($note && $said !== self::MAYBE) {
            $centre = centres::get((int) $household->centreid);
            $lang = (string) ($centre->lang ?? '') ?: current_language();
            $name = self::adult_name((int) $household->id, $adultid, $lang);
            $key = 'alumni_noted_' . ($source === 'list' ? 'list' : ($source === 'family' ? 'family' : 'team')) . '_' .
                ($said === self::YES ? 'yes' : 'no') . ($said === self::YES && (string) $record['year'] !== '' ? '_year' : '');
            households::note($household, $userid, get_string_manager()->get_string(
                $key,
                self::C,
                (object) ['name' => $name, 'year' => (string) $record['year']],
                $lang
            ));
        }
        return true;
    }

    /**
     * The name of an adult of a family, or «someone of the family».
     *
     * @param int $householdid
     * @param int $adultid
     * @param string $lang
     * @return string
     */
    protected static function adult_name(int $householdid, int $adultid, string $lang): string {
        global $DB;
        $adult = $adultid ? $DB->get_record(households::ADULTS, ['id' => $adultid, 'householdid' => $householdid]) : null;
        return $adult ? trim($adult->firstname . ' ' . $adult->lastname) :
            get_string_manager()->get_string('alumni_someone', self::C, null, $lang);
    }

    /**
     * The keys of a full name: the whole name, and each one without one of its words when it has three or more, so
     * «Ana García López» is found from «Ana García» (with one surname) as a question for a person.
     *
     * @param string $name
     * @return array key => whether it is the whole name
     */
    public static function name_keys(string $name): array {
        $full = pseudonym::name($name);
        if ($full === null) {
            return [];
        }
        $out = [$full => true];
        $words = explode(' ', substr($full, strlen('name:')));
        if (count($words) >= 3) {
            foreach (array_keys($words) as $i) {
                $less = $words;
                unset($less[$i]);
                $key = 'name:' . implode(' ', $less);
                $out[$key] = $out[$key] ?? false;
            }
        }
        return $out;
    }

    /**
     * The rows of the list for one former student: the keyed hashes of their name, never the name.
     *
     * @param pseudonym $keys
     * @param string $name
     * @param string $year The year they left, as the list says it.
     * @return \stdClass[]
     */
    public static function list_rows(pseudonym $keys, string $name, string $year): array {
        $out = [];
        $year = self::year($year);
        foreach (self::name_keys($name) as $key => $exact) {
            $out[] = (object) ['keyhash' => $keys->keyhash(self::KIND, $key), 'exact' => $exact ? 1 : 0,
                'year' => $year !== '' ? $year : null, 'timecreated' => time()];
        }
        return $out;
    }

    /**
     * The year a former student left, from what the list says: «2003», «Promoción 2003», «2002-03» or «2002/2003».
     *
     * @param string $value
     * @return string
     */
    public static function year(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $schoolyear = recognise::schoolyear($value);
        if ($schoolyear !== null) {
            return $schoolyear;
        }
        // A year that has already been, never one to come; «04» or «'98» as people say them.
        $now = (int) date('Y');
        if (preg_match('/\b(19|20)\d{2}\b/', $value, $m)) {
            return (int) $m[0] >= 1930 && (int) $m[0] <= $now ? $m[0] : '';
        }
        if (preg_match('/^\D{0,2}(\d{2})\D{0,2}$/', $value, $m)) {
            $year = (int) $m[1] <= $now % 100 ? 2000 + (int) $m[1] : 1900 + (int) $m[1];
            return $year >= 1930 ? (string) $year : '';
        }
        return '';
    }

    /**
     * Looks for an adult in the list of former students of the centre. The whole name on the list says they studied at
     * the centre; a name with one word more or less is a question for a person. Nothing changes what was already said.
     *
     * @param \stdClass $household
     * @param \stdClass $adult With id, firstname and lastname.
     * @param pseudonym|null $keys
     * @return int|null YES or MAYBE when it was found and written; null otherwise.
     */
    public static function match(\stdClass $household, \stdClass $adult, ?pseudonym $keys = null): ?int {
        global $DB;
        $centreid = (int) $household->centreid;
        if (!$DB->record_exists(self::LIST, ['centreid' => $centreid]) || centres::is_practice($centreid)) {
            return null;
        }
        if ($DB->record_exists(self::T, ['householdid' => $household->id, 'adultid' => $adult->id])) {
            return null;
        }
        $mine = self::name_keys(trim($adult->firstname . ' ' . ($adult->lastname ?? '')));
        if (!$mine) {
            return null;
        }
        $keys = $keys ?? new pseudonym($centreid);
        $hashes = [];
        foreach ($mine as $key => $whole) {
            $hashes[$keys->keyhash(self::KIND, $key)] = $whole;
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($hashes), SQL_PARAMS_NAMED);
        $found = $DB->get_records_select(
            self::LIST,
            "centreid = :centreid AND keyhash $in",
            $params + ['centreid' => $centreid],
            'exact DESC, id ASC',
            'id, keyhash, exact, year'
        );
        $said = null;
        $year = '';
        foreach ($found as $row) {
            $whole = $hashes[$row->keyhash];
            if ($whole && $row->exact) {
                $said = self::YES;
                $year = (string) $row->year;
                break;
            }
            // One name is the other without one word: «Ana García» and «Ana García López». Two names that only share
            // part of themselves are nothing.
            if ($whole xor $row->exact) {
                $said = self::MAYBE;
                $year = $year !== '' ? $year : (string) $row->year;
            }
        }
        if ($said === null) {
            return null;
        }
        self::set($household, (int) $adult->id, $said, 'list', 0, $year);
        return $said;
    }

    /**
     * Looks for every adult of a centre in its list: the families that ask now and those already at the centre.
     *
     * @param int $centreid
     * @return int[] [found, to ask a person]
     */
    public static function match_centre(int $centreid): array {
        global $DB;
        $out = [0, 0];
        if (!$DB->record_exists(self::LIST, ['centreid' => $centreid])) {
            return $out;
        }
        $keys = new pseudonym($centreid);
        $homes = $DB->get_records(households::T, ['centreid' => $centreid], '', 'id, centreid');
        $adults = $DB->get_recordset(households::ADULTS, ['centreid' => $centreid], '', 'id, householdid, firstname, lastname');
        foreach ($adults as $adult) {
            if (!isset($homes[$adult->householdid])) {
                continue;
            }
            $said = self::match($homes[$adult->householdid], $adult, $keys);
            if ($said === self::YES) {
                $out[0]++;
            } else if ($said === self::MAYBE) {
                $out[1]++;
            }
        }
        $adults->close();
        return $out;
    }

    /**
     * After a list of former students is taken away: what only it said goes, and the other lists are read again.
     *
     * @param int $centreid
     */
    public static function list_removed(int $centreid): void {
        global $DB;
        $DB->delete_records(self::T, ['centreid' => $centreid, 'source' => 'list']);
        self::match_centre($centreid);
    }

    /**
     * The families of an imported list whose column said someone studied at the centre, by their token.
     *
     * @param int $centreid
     * @param int $importid
     * @param string[] $tokens
     */
    public static function from_import(int $centreid, int $importid, array $tokens): void {
        global $DB;
        $rows = [];
        foreach (array_unique(array_filter($tokens)) as $token) {
            $rows[] = (object) ['centreid' => $centreid, 'householdid' => 0, 'adultid' => 0, 'token' => $token,
                'importid' => $importid, 'said' => self::YES, 'source' => 'import', 'year' => null, 'userid' => 0,
                'timecreated' => time()];
        }
        if ($rows) {
            $DB->insert_records(self::T, $rows);
        }
    }

    /**
     * The families of the imported lists where someone studied at the centre, by the token of the family: from a column
     * of the list, and the families of Eguahe that said it, found in the lists by their emails and phones.
     *
     * @param int $centreid
     * @return array token => true
     */
    public static function tokens(int $centreid): array {
        global $DB;
        $out = array_fill_keys($DB->get_fieldset_select(
            self::T,
            'DISTINCT token',
            'centreid = :centreid AND token IS NOT NULL AND said = :yes',
            ['centreid' => $centreid, 'yes' => self::YES]
        ), true);
        $families = array_keys(self::families($centreid));
        if (!$families) {
            return $out;
        }
        $keys = new pseudonym($centreid);
        [$in, $params] = $DB->get_in_or_equal($families, SQL_PARAMS_NAMED);
        $byhome = [];
        $adults = $DB->get_records_select(households::ADULTS, "householdid $in", $params, '', 'id, householdid, email, phone');
        foreach ($adults as $a) {
            $byhome[$a->householdid][] = $a->email ? pseudonym::email((string) $a->email) : null;
            $byhome[$a->householdid][] = $a->phone ? pseudonym::phone((string) $a->phone) : null;
        }
        foreach ($byhome as $list) {
            foreach ($keys->find('household', $list) as $token) {
                $out[$token] = true;
            }
        }
        return $out;
    }

    /**
     * What a family can download of it: who studied at the centre, as they or the team said it.
     *
     * @param int $householdid
     * @return array
     */
    public static function export(int $householdid): array {
        global $DB;
        $names = $DB->get_records_menu(households::ADULTS, ['householdid' => $householdid], '', 'id, firstname');
        $out = [];
        foreach (self::of($householdid) as $adultid => $row) {
            if ((int) $row->said === self::MAYBE) {
                continue;
            }
            $out[] = ['adult' => (string) ($names[$adultid] ?? ''), 'studied_at_the_centre' => (int) $row->said === self::YES,
                'year' => (string) ($row->year ?? ''), 'told_by' => (string) $row->source];
        }
        return $out;
    }

    /**
     * Forgets what is known of a family.
     *
     * @param int $householdid
     */
    public static function delete(int $householdid): void {
        global $DB;
        if ($householdid > 0) {
            $DB->delete_records(self::T, ['householdid' => $householdid]);
        }
    }

    /**
     * The words of the team for an adult: «Estudió en el centro (2003)», or the question when the list only may say it.
     *
     * @param \stdClass|null $row
     * @return string
     */
    public static function words(?\stdClass $row): string {
        if (!$row) {
            return '';
        }
        $year = (string) ($row->year ?? '');
        if ((int) $row->said === self::YES) {
            return get_string($year !== '' ? 'alumni_yes_year' : 'alumni_yes', self::C, $year);
        }
        if ((int) $row->said === self::MAYBE) {
            return get_string($year !== '' ? 'alumni_maybe_year' : 'alumni_maybe', self::C, $year);
        }
        return get_string('alumni_no', self::C);
    }
}
