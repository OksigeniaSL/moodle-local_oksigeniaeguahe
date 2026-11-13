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
 * The families already at the centre, from its roll (the common family record, local_oksigeniafamily): so that the
 * re-enrolment and the whole year reach every student, not only those who came through Eguahe.
 *
 * Family keeps who each student and each adult is, never their course: that lives where the centre keeps it, in the
 * cohorts of the site, in the groups of a course or in a field of the profile. The centre says which, and what each of
 * its values means (the cohort «3.º B» is 3.º de Primaria); each family with a student placed in a course of the school
 * year now is brought as a household already at the centre (out of the numbers of the campaign), with its adults and
 * those students, enrolled. A family that erased its data is never brought back. Students without an account have no
 * name in Family: they come through the import of Eguahe.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster {
    /** @var string Each family brought, and the ones that erased their data. */
    const T = 'local_oksigeniaeguahe_roster';

    /** @var string What each value of the roll means. */
    const MAPS = 'local_oksigeniaeguahe_rostermap';

    /** @var string[] Where the course of each student comes from; family, only the group the record keeps. */
    const KINDS = ['cohort', 'group', 'profile', 'family'];

    /** @var string The source of the permission to write, for the families brought from the roll of the centre. */
    const CONSENT = 'roster';

    /** @var int The students whose values are read in one query. */
    const CHUNK = 500;

    /** @var array|null Only for the tests: the roll, whatever the site has. */
    public static $testfamilies = null;

    /**
     * Whether the site has the common family record with its whole roll.
     *
     * @return bool
     */
    public static function available(): bool {
        if (self::$testfamilies !== null) {
            return true;
        }
        $api = family::API;
        return family::available() && method_exists($api, 'households');
    }

    /**
     * The roll of the site: every active family with its children and adults.
     *
     * @return \stdClass[] family id => household of Family.
     */
    public static function families(): array {
        if (self::$testfamilies !== null) {
            return self::$testfamilies;
        }
        if (!self::available()) {
            return [];
        }
        $api = family::API;
        return $api::households(true);
    }

    /**
     * The roll of the site to go through, for counting and asking about the children: without its adults and a batch at
     * a time when Family can (each_household(), Family 0.3.13), so a big centre never loads it all; else the whole roll.
     *
     * @return iterable family id => household of Family.
     */
    public static function children_of_families(): iterable {
        if (self::$testfamilies !== null || !self::available()) {
            return self::families();
        }
        $api = family::API;
        return method_exists($api, 'each_household') ? $api::each_household(true, false) : self::families();
    }

    /**
     * Where a centre takes the course of each student from.
     *
     * @param \stdClass $centre
     * @return array|null [kind, the course id or the short name of the field], or null when it does not bring them.
     */
    public static function source(\stdClass $centre): ?array {
        $source = trim((string) ($centre->rostersource ?? ''));
        if ($source === 'cohort' || $source === 'family') {
            return [$source, ''];
        }
        if (preg_match('/^(group|profile):(.+)$/', $source, $m)) {
            return [$m[1], $m[2]];
        }
        return null;
    }

    /**
     * Sets where the course of each student comes from, and the school year they are in now.
     *
     * @param \stdClass $centre
     * @param string $kind cohort, group or profile; empty, it stops bringing them.
     * @param string $arg The course of the groups, or the short name of the field.
     * @param string $year
     */
    public static function set_source(\stdClass $centre, string $kind, string $arg, string $year): void {
        global $DB;
        $source = null;
        if ($kind === 'cohort' || $kind === 'family') {
            $source = $kind;
        } else if (in_array($kind, self::KINDS, true) && trim($arg) !== '') {
            $source = \core_text::substr($kind . ':' . trim($arg), 0, 100);
        }
        $year = \core_text::substr(trim($year), 0, 9);
        $DB->update_record(centres::T, (object) ['id' => $centre->id, 'rostersource' => $source,
            'rosteryear' => $year !== '' ? $year : null, 'timemodified' => time()]);
        $centre->rostersource = $source;
        $centre->rosteryear = $year !== '' ? $year : null;
    }

    /**
     * The values of each student, from where the centre said: the names of their cohorts or groups, or the field.
     *
     * @param \stdClass $centre
     * @param int[] $userids
     * @return array user id => string[]
     */
    public static function values(\stdClass $centre, array $userids): array {
        global $DB;
        $source = self::source($centre);
        $out = [];
        if (!$source || !$userids || $source[0] === 'family') {
            return $out;
        }
        [$kind, $arg] = $source;
        foreach (array_chunk(array_values(array_unique($userids)), self::CHUNK) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            if ($kind === 'cohort') {
                $sql = "SELECT cm.id, cm.userid, c.name AS value
                          FROM {cohort_members} cm
                          JOIN {cohort} c ON c.id = cm.cohortid
                         WHERE cm.userid $in";
            } else if ($kind === 'group') {
                $sql = "SELECT gm.id, gm.userid, g.name AS value
                          FROM {groups_members} gm
                          JOIN {groups} g ON g.id = gm.groupid
                         WHERE g.courseid = :courseid AND gm.userid $in";
                $params['courseid'] = (int) $arg;
            } else {
                $sql = "SELECT d.id, d.userid, d.data AS value
                          FROM {user_info_data} d
                          JOIN {user_info_field} f ON f.id = d.fieldid
                         WHERE f.shortname = :shortname AND d.userid $in";
                $params['shortname'] = $arg;
            }
            $rows = $DB->get_recordset_sql($sql, $params);
            foreach ($rows as $row) {
                $value = trim(strip_tags((string) $row->value));
                if ($value !== '') {
                    $out[(int) $row->userid][] = \core_text::substr($value, 0, 255);
                }
            }
            $rows->close();
        }
        return $out;
    }

    /**
     * What each value means for a centre.
     *
     * @param \stdClass $centre
     * @return string[] value => the name of its course; empty, not a course of this centre.
     */
    public static function maps(\stdClass $centre): array {
        global $DB;
        return $DB->get_records_menu(self::MAPS, ['centreid' => $centre->id], '', 'value, grade');
    }

    /**
     * Says what a value means: the course of its students, or that it is not one of this centre.
     *
     * @param \stdClass $centre
     * @param string $value
     * @param string $grade The name of the course, as the offer names it; empty, not a course.
     */
    public static function set_map(\stdClass $centre, string $value, string $grade): void {
        global $DB;
        $value = \core_text::substr(trim($value), 0, 255);
        // Only a course of the school year now, or nothing (not a course).
        if ($value === '' || (trim($grade) !== '' && !isset(self::offers($centre)[grades::plain($grade)]))) {
            return;
        }
        $row = (object) ['centreid' => $centre->id, 'value' => $value, 'grade' => \core_text::substr(trim($grade), 0, 255),
            'timemodified' => time()];
        $id = $DB->get_field(self::MAPS, 'id', ['centreid' => $centre->id, 'value' => $value]);
        if ($id) {
            $row->id = $id;
            $DB->update_record(self::MAPS, $row);
        } else {
            $DB->insert_record(self::MAPS, $row);
        }
    }

    /**
     * The courses of the school year now, by their plain name.
     *
     * @param \stdClass $centre
     * @return \stdClass[] plain name => offer.
     */
    public static function offers(\stdClass $centre): array {
        $year = trim((string) ($centre->rosteryear ?? ''));
        $out = [];
        foreach (centres::offers((int) $centre->id) as $offer) {
            if ($year !== '' && (string) $offer->schoolyear === $year) {
                $out[grades::plain((string) $offer->name)] = $offer;
            }
        }
        return $out;
    }

    /**
     * The course a value most likely is, for a person to confirm: the course all of whose words it holds, an abbreviation
     * counting as its word and a number as itself («INF 3ºA» is Infantil 3 años, «3.º Primaria B» is 3.º de Primaria),
     * the one with most words; else the only course of the same age; else, for a value that is only a number and a
     * letter («3.º B»), the only course with that number.
     *
     * @param string $value
     * @param \stdClass[] $offers self::offers()
     * @param \stdClass $centre
     * @return string The name of the course; empty, none.
     */
    public static function guess(string $value, array $offers, \stdClass $centre): string {
        $skip = ['', 'de', 'del', 'la', 'el', 'of', 'the', 'do', 'da', 'y', 'e', 'ano', 'anos', 'year', 'years', 'curso'];
        $words = fn(string $text) => array_values(array_diff(explode(' ', grades::plain($text)), $skip));
        $number = fn(string $word) => preg_match('/^(\d+)[a-z]{0,2}$/', $word, $m) ? (int) $m[1] : null;
        $same = function (string $theirs, string $mine) use ($number): bool {
            if ($number($theirs) !== null || $number($mine) !== null) {
                return $number($theirs) !== null && $number($theirs) === $number($mine);
            }
            $short = min(strlen($theirs), strlen($mine));
            return $theirs === $mine || ($short >= 3 && (str_starts_with($theirs, $mine) || str_starts_with($mine, $theirs)));
        };
        $mine = $words($value);
        $best = null;
        $most = 0;
        foreach ($offers as $offer) {
            $theirs = $words((string) $offer->name);
            $all = $theirs && !array_filter($theirs, fn($t) => !array_filter($mine, fn($m) => $same($t, $m)));
            if ($all && count($theirs) > $most) {
                $best = $offer;
                $most = count($theirs);
            }
        }
        $age = grades::age($value, $centre);
        if ($best) {
            // Read by its words and by its age, two different courses («INF 3º»: three years old, or the third year of
            // Infantil?): nothing is proposed, a person hangs it.
            $bestage = grades::age((string) $best->name, $centre);
            return $age !== null && $bestage !== null && $bestage !== $age ? '' : (string) $best->name;
        }
        if ($age !== null) {
            $same = array_filter($offers, fn($o) => grades::age((string) $o->name, $centre) === $age);
            if (count($same) === 1) {
                return (string) reset($same)->name;
            }
        }
        // Only a number and letters of a group: the number alone says it, when only one course has it.
        $numbers = array_filter(array_map($number, $mine), fn($n) => $n !== null);
        $letters = array_filter($mine, fn($w) => $number($w) === null && strlen($w) >= 3);
        if (count($numbers) !== 1 || $letters) {
            return '';
        }
        $n = reset($numbers);
        $first = fn(string $text) => preg_match('/\d+/', $text, $m) ? (int) $m[0] : null;
        $same = array_filter($offers, fn($o) => $first((string) $o->name) === $n);
        return count($same) === 1 ? (string) reset($same)->name : '';
    }

    /**
     * The course of a student from its values: the one its mapped values say, when they say only one.
     *
     * @param string[] $values
     * @param string[] $maps self::maps()
     * @param \stdClass[] $offers self::offers()
     * @return \stdClass|null
     */
    public static function place(array $values, array $maps, array $offers): ?\stdClass {
        $grades = [];
        foreach ($values as $value) {
            $grade = grades::plain((string) ($maps[$value] ?? ''));
            if ($grade !== '' && isset($offers[$grade])) {
                $grades[$grade] = true;
            }
        }
        return count($grades) === 1 ? $offers[array_key_first($grades)] : null;
    }

    /**
     * The students of the roll the centre can place: those with an account, and those without one whose name the record
     * has from the data of the centre; and how many have neither, so cannot be named.
     *
     * @return array userids, groups (whether any has the group of the record), count, nameless.
     */
    public static function students(): array {
        $userids = [];
        $count = 0;
        $nameless = 0;
        $groups = false;
        foreach (self::children_of_families() as $family) {
            foreach ($family->children as $child) {
                $groups = $groups || trim((string) ($child->group ?? '')) !== '';
                if ($child->userid) {
                    $userids[] = (int) $child->userid;
                    $count++;
                } else if (trim((string) ($child->firstname ?? '')) !== '') {
                    $count++;
                } else {
                    $nameless++;
                }
            }
        }
        return ['userids' => array_values(array_unique($userids)), 'groups' => $groups, 'count' => $count,
            'nameless' => $nameless];
    }

    /**
     * The values of one student: those of its account, from where the centre said, and the group the record keeps for it
     * («INF 3ºA», from the data of the centre), if any.
     *
     * @param \stdClass $child A child of the roll.
     * @param array $values self::values()
     * @return string[]
     */
    public static function of(\stdClass $child, array $values): array {
        $out = $child->userid ? ($values[(int) $child->userid] ?? []) : [];
        $group = trim((string) ($child->group ?? ''));
        if ($group !== '') {
            $out[] = \core_text::substr($group, 0, 255);
        }
        return array_values(array_unique($out));
    }

    /**
     * Whether a child of the roll has a name to be brought with.
     *
     * @param \stdClass $child
     * @return bool
     */
    protected static function named(\stdClass $child): bool {
        return (bool) $child->userid || trim((string) ($child->firstname ?? '')) !== '';
    }

    /**
     * Where the course of the students can come from on this site: the courses whose groups have students of the roll,
     * and the fields of the profile they have filled in.
     *
     * @param int[] $userids
     * @return array courses (id => name), fields (short name => name).
     */
    public static function sources(array $userids): array {
        global $DB;
        $courses = [];
        $fields = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $courses += $DB->get_records_sql_menu(
                "SELECT DISTINCT c.id, c.fullname
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                   JOIN {course} c ON c.id = g.courseid
                  WHERE gm.userid $in",
                $params
            );
            $fields += $DB->get_records_sql_menu(
                "SELECT DISTINCT f.shortname, f.name
                   FROM {user_info_data} d
                   JOIN {user_info_field} f ON f.id = d.fieldid
                  WHERE d.userid $in AND d.data <> ''",
                $params
            );
        }
        return ['courses' => array_map('format_string', $courses), 'fields' => array_map('format_string', $fields)];
    }

    /**
     * What a centre sees before and after bringing them: the values found among the students of the roll, how many
     * students each one has, what it means or what it most likely is; and the students with a course, without one, and
     * without an account.
     *
     * @param \stdClass $centre
     * @return array values (value, count, grade or null, guess), students, placed, unplaced, nameless.
     */
    public static function overview(\stdClass $centre): array {
        ['userids' => $userids, 'count' => $students, 'nameless' => $nameless] = self::students();
        $values = self::values($centre, $userids);
        $maps = self::maps($centre);
        $offers = self::offers($centre);
        $counts = [];
        $placed = 0;
        foreach (self::children_of_families() as $family) {
            foreach ($family->children as $child) {
                if (!self::named($child)) {
                    continue;
                }
                $mine = self::of($child, $values);
                foreach ($mine as $value) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
                $placed += self::place($mine, $maps, $offers) ? 1 : 0;
            }
        }
        \core_collator::ksort($counts, \core_collator::SORT_NATURAL);
        $out = [];
        foreach ($counts as $value => $count) {
            $out[] = ['value' => (string) $value, 'count' => $count, 'grade' => array_key_exists($value, $maps) ?
                (string) $maps[$value] : null, 'guess' => self::guess((string) $value, $offers, $centre)];
        }
        return ['values' => $out, 'students' => $students, 'placed' => $placed, 'unplaced' => $students - $placed,
            'nameless' => $nameless];
    }

    /**
     * Brings the families of the roll with a student in a course of the centre, and keeps them up to date: a new
     * household already at the centre for each one, or the one it already has in Eguahe; its students placed in their
     * course, enrolled. The common record already has them: nothing goes back to it.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array families (brought now), children (placed now), moved (to another course).
     */
    public static function sync(\stdClass $centre, int $now): array {
        global $DB;
        $done = ['families' => 0, 'children' => 0, 'moved' => 0];
        $offers = self::offers($centre);
        if (!self::source($centre) || !$offers) {
            return $done;
        }
        $families = self::families();
        $userids = [];
        foreach ($families as $family) {
            foreach ($family->children as $child) {
                if ($child->userid) {
                    $userids[] = (int) $child->userid;
                }
            }
        }
        $values = self::values($centre, $userids);
        $maps = self::maps($centre);
        $brought = $DB->get_records_menu(self::T, ['centreid' => $centre->id], '', 'familyid, householdid');
        // The families that came into the common record from Eguahe, by the centre of their household: one of another
        // centre of the site is never brought here, even if a course of both is called the same.
        $from = [];
        foreach ($families as $familyid => $family) {
            if (
                ($family->source ?? '') === family::SOURCE && preg_match(
                    '/^' . family::SOURCE . ':(\d+)$/',
                    (string) ($family->sourcekey ?? ''),
                    $m
                )
            ) {
                $from[$familyid] = (int) $m[1];
            }
        }
        $centreof = [];
        if ($from) {
            [$in, $params] = $DB->get_in_or_equal(array_values(array_unique($from)));
            $centreof = $DB->get_records_select_menu(households::T, "id $in", $params, '', 'id, centreid');
        }
        foreach ($families as $familyid => $family) {
            if (
                isset($from[$familyid]) && isset($centreof[$from[$familyid]]) &&
                    (int) $centreof[$from[$familyid]] !== (int) $centre->id
            ) {
                continue;
            }
            $placed = [];
            foreach ($family->children as $child) {
                $offer = self::named($child) ? self::place(self::of($child, $values), $maps, $offers) : null;
                if ($offer) {
                    $placed[] = [$child, $offer];
                }
            }
            if (!$placed || (array_key_exists($familyid, $brought) && !(int) $brought[$familyid])) {
                // Not of this centre, or a family that erased its data here.
                continue;
            }
            $household = self::household_of($centre, (int) $familyid, $family, $brought);
            if (!$household) {
                $household = self::bring($centre, (int) $familyid, $family, $now);
                $done['families']++;
            }
            foreach ($placed as [$child, $offer]) {
                $mine = $DB->get_record(households::CHILDREN, ['centreid' => $centre->id,
                    'familychild' => (int) $child->childid]);
                if (!$mine) {
                    $id = households::add_child($household, trim((string) $child->firstname), (int) $offer->id);
                    $DB->update_record(households::CHILDREN, (object) ['id' => $id, 'offerid' => $offer->id,
                        'stage' => 'enrolled', 'stagetime' => $now, 'familychild' => (int) $child->childid,
                        'roster' => $now, 'timemodified' => $now]);
                    $done['children']++;
                } else if ((int) $mine->offerid !== (int) $offer->id && empty($mine->lefttime) && self::later($mine, $offer)) {
                    $DB->update_record(households::CHILDREN, (object) ['id' => $mine->id, 'offerid' => $offer->id,
                        'timemodified' => $now]);
                    $done['moved']++;
                }
            }
        }
        set_config('rostersync' . $centre->id, $now, 'local_oksigeniaeguahe');
        return $done;
    }

    /**
     * Whether a course is of the same school year as the one a child is in, or a later one: a roll left on an old year
     * never takes a child back.
     *
     * @param \stdClass $child
     * @param \stdClass $offer
     * @return bool
     */
    protected static function later(\stdClass $child, \stdClass $offer): bool {
        global $DB;
        $now = (string) $DB->get_field(centres::OFFERS, 'schoolyear', ['id' => $child->offerid]);
        return $now === '' || strcmp((string) $offer->schoolyear, $now) >= 0;
    }

    /**
     * The household a family of the roll already has in Eguahe: the one brought before, the one Eguahe gave it when it
     * enrolled (eguahe:<id>), or the one a person said it is.
     *
     * @param \stdClass $centre
     * @param int $familyid
     * @param \stdClass $family
     * @param array $brought family id => household id.
     * @return \stdClass|null
     */
    protected static function household_of(\stdClass $centre, int $familyid, \stdClass $family, array $brought): ?\stdClass {
        global $DB;
        $id = (int) ($brought[$familyid] ?? 0);
        $key = '/^' . family::SOURCE . ':(\d+)$/';
        if (!$id && $family->source === family::SOURCE && preg_match($key, (string) $family->sourcekey, $m)) {
            $id = (int) $m[1];
        }
        if (!$id) {
            $id = (int) $DB->get_field(
                households::T,
                'id',
                ['centreid' => $centre->id, 'familylink' => $familyid],
                IGNORE_MULTIPLE
            );
        }
        return $id ? households::get($id, (int) $centre->id) : null;
    }

    /**
     * A family of the roll, brought: a household already at the centre, with its adults and what they let the centre
     * do (the centre already writes to its families), and the line that keeps it.
     *
     * @param \stdClass $centre
     * @param int $familyid
     * @param \stdClass $family
     * @param int $now
     * @return \stdClass
     */
    protected static function bring(\stdClass $centre, int $familyid, \stdClass $family, int $now): \stdClass {
        global $DB;
        $lang = in_array($centre->lang ?? '', lists::LANGS, true) ? $centre->lang : 'en';
        $id = households::create((int) $centre->id, (object) ['lang' => $lang, 'firstchannel' => 'other', 'incentre' => 1]);
        $DB->update_record(households::T, (object) ['id' => $id, 'roster' => $now, 'familylink' => $familyid,
            'timecreated' => $now, 'lastcontact' => $now, 'timemodified' => $now]);
        $household = households::get($id, (int) $centre->id);
        foreach ($family->adults as $adult) {
            $adultid = households::add_adult($household, (object) ['firstname' => (string) $adult->firstname,
                'lastname' => (string) $adult->lastname, 'email' => (string) $adult->email, 'phone' => (string) $adult->phone]);
            if (trim((string) $adult->email) !== '') {
                households::consent($household, $adultid, 'email', true, self::CONSENT, $lang);
            }
        }
        $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'familyid' => $familyid, 'householdid' => $id,
            'timecreated' => $now, 'timemodified' => $now]);
        return $household;
    }

    /**
     * How many families and students a centre brought, and when it last did.
     *
     * @param \stdClass $centre
     * @return array families, children, time.
     */
    public static function brought(\stdClass $centre): array {
        global $DB;
        $families = $DB->count_records_select(self::T, 'centreid = ? AND householdid > 0', [$centre->id]);
        $children = $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {' . households::CHILDREN . '} c
               JOIN {' . households::T . '} h ON h.id = c.householdid
              WHERE h.centreid = ? AND h.roster > 0 AND c.stage = ?',
            [$centre->id, 'enrolled']
        );
        return ['families' => $families, 'children' => (int) $children,
            'time' => (int) get_config('local_oksigeniaeguahe', 'rostersync' . $centre->id)];
    }

    /**
     * A family brought from the roll erased its data: it is never brought back.
     *
     * @param \stdClass $household
     */
    public static function erased(\stdClass $household): void {
        global $DB;
        $DB->set_field(self::T, 'householdid', 0, ['householdid' => $household->id]);
        // A family of the campaign that enrolled is in the common record too (its own key, or the family a person said it
        // is): it is kept as erased, so that the roll never brings it back.
        $familyid = (int) ($household->familylink ?? 0);
        if ($familyid <= 0 && family::available()) {
            $api = family::API;
            try {
                $familyid = (int) $api::family_by_key(family::SOURCE, family::key('h', (int) $household->id));
            } catch (\Throwable $e) {
                $familyid = 0;
            }
        }
        if ($familyid > 0 && !$DB->record_exists(self::T, ['centreid' => $household->centreid, 'familyid' => $familyid])) {
            $DB->insert_record(self::T, (object) ['centreid' => $household->centreid, 'familyid' => $familyid,
                'householdid' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        }
    }
}
