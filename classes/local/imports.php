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
 * What already happened, imported to look at it: the contacts of past campaigns, the new students, the leaves and the
 * lists of each school year, the totals of past years and the population of the area. Files go in as they come (a
 * spreadsheet with several sheets, an export of the adverts, the answers of a form, a CSV): Eguahe reads what each
 * column holds, keeps what makes the numbers and removes the rest on the way in. Emails, phones, codes and children's
 * names only become a key to join the same family or student across files; the file itself is not kept. Nobody is
 * created as a household: it is for the numbers.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imports {
    /** @var string */
    const T = 'local_oksigeniaeguahe_import';

    /** @var array The table of each kind; «students» is how 0.3 and 0.4 named a list of a school year. */
    const TABLES = ['contacts' => 'local_oksigeniaeguahe_pastcontact', 'joins' => 'local_oksigeniaeguahe_student',
        'leaves' => 'local_oksigeniaeguahe_student', 'roll' => 'local_oksigeniaeguahe_student',
        'students' => 'local_oksigeniaeguahe_student', 'totals' => 'local_oksigeniaeguahe_enrolcount',
        'population' => 'local_oksigeniaeguahe_population', 'alumni' => 'local_oksigeniaeguahe_alumnus'];

    /** @var array The channels of the format of Eguahe, as Eguahe names them. */
    const CHANNELS = ['web_form' => 'webform', 'visit_booking' => 'visit', 'open_day' => 'openday',
        'meta_lead_ads' => 'meta', 'google_forms' => 'googleforms', 'whatsapp' => 'whatsapp', 'telegram' => 'telegram',
        'email' => 'email', 'phone' => 'phone', 'walk_in' => 'walkin', 'other' => 'other'];

    /** @var string[] What a contact was. */
    const CONTACT_KINDS = ['inquiry', 'application', 'visit_booked', 'visit_attended', 'open_day_attended'];

    /** @var int A list of a year this long that comes with less than KEEPS of what it had was cut short. */
    const CUTFROM = 20;

    /** @var float The part of the list of the night before a new one has to bring to take its place. */
    const KEEPS = 0.7;

    /** @var int A day of entry this long before its school year is of an earlier one: the student was already there. */
    const BEFORE = 60 * DAYSECS;

    /** @var string A token of the format of Eguahe. */
    const TOKEN = '/^[A-Z]{2,6}_[0-9A-Za-z]{6,32}$/';

    /** @var string Imported rows that are on a list holding only a part of the centre, to leave out of whole-list counts. */
    const NOTPARTIAL = 'importid NOT IN (SELECT id FROM {local_oksigeniaeguahe_import} WHERE partial = 1)';

    /**
     * The imports of a centre, the newest first.
     *
     * @param int $centreid
     * @param string|null $kind
     * @return \stdClass[]
     */
    public static function for_centre(int $centreid, ?string $kind = null): array {
        global $DB;
        $params = ['centreid' => $centreid];
        if ($kind) {
            $params['kind'] = $kind;
        }
        return $DB->get_records(self::T, $params, 'timecreated DESC, id DESC');
    }

    /**
     * What Eguahe makes of each sheet of a file, before importing anything.
     *
     * @param string $path
     * @param string $filename
     * @param array $kinds Sheet index => kind chosen by the centre, to see it again with that kind.
     * @param int $centreid The centre it would come into, to say what it already has and what it shares; 0, none.
     * @param array $labels Sheet index => the label chosen.
     * @return array A list of sheets: index, name, kind, rows, schoolyear, label, the columns kept, made keys and
     *               removed, the contacts several students share, and the import it would repeat.
     */
    public static function preview(
        string $path,
        string $filename,
        array $kinds = [],
        int $centreid = 0,
        array $labels = []
    ): array {
        $out = [];
        $keys = $centreid ? new pseudonym($centreid) : null;
        foreach (sheets::read($path, $filename) as $i => $sheet) {
            $kind = $kinds[$i] ?? null;
            $plan = recognise::sheet($sheet, $filename, $kind === null || $kind === 'none' ? null : $kind);
            if ($kind === 'none') {
                $plan['kind'] = null;
            }
            $fates = ['kept' => [], 'key' => [], 'removed' => []];
            $untitled = ['kept' => 0, 'key' => 0, 'removed' => 0];
            $health = false;
            foreach ($plan['columns'] as $col) {
                if (!$col['values'] && $col['title'] === '') {
                    continue;
                }
                $fate = $plan['kind'] ? $col['fate'] : 'removed';
                $fate = $fate === 'unused' ? 'removed' : $fate;
                $health = $health || $col['role'] === 'health';
                if ($col['title'] === '') {
                    $untitled[$fate]++;
                    continue;
                }
                $title = \core_text::substr(str_replace("\n", ' ', $col['title']), 0, 60);
                $fates[$fate][] = $col['role'] === 'birthdate' && $fate === 'kept' ?
                    get_string('import_yearonly', 'local_oksigeniaeguahe', $title) : $title;
            }
            // The columns without a title, counted: an export can bring dozens.
            foreach ($untitled as $fate => $n) {
                if ($n) {
                    $fates[$fate][] = get_string('import_untitled', 'local_oksigeniaeguahe', $n);
                }
            }
            $out[] = ['index' => $i, 'name' => $plan['name'], 'kind' => $plan['kind'], 'rows' => count($plan['rows']),
                'schoolyear' => $plan['schoolyear'], 'label' => $plan['label'], 'kept' => $fates['kept'],
                'keys' => $fates['key'], 'removed' => $fates['removed'], 'health' => $health,
                'shared' => $keys ? self::shared_by($plan, $centreid) : [],
                'twice' => $keys && $plan['kind'] ? self::already($centreid, $plan, trim((string) ($labels[$i] ?? '')) ?:
                    $plan['label'], self::sum($keys, $plan), $filename) : null];
        }
        return $out;
    }

    /**
     * Imports the sheets of a file the centre chose, each as the kind it chose.
     *
     * @param int $centreid
     * @param string $path
     * @param string $filename
     * @param array $choices Sheet index => [kind, label, schoolyear and, for a list of a year, partial when it holds only
     *     a part of the centre]; a sheet left out, or with kind «none», is not read. The contacts of a campaign that are
     *     already there, row by row and with the same name, take the place of those; with instead (an import) they take the
     *     place of that one, and with both they are kept too.
     * @param int $userid
     * @return array [imports made, rows imported, rows left out]
     */
    public static function import_file(int $centreid, string $path, string $filename, array $choices, int $userid): array {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        [$made, $total, $skipped, $replaced] = self::load($centreid, $path, $filename, $choices, $userid);
        foreach ($replaced as $import) {
            self::remove($import);
        }
        if ($made) {
            self::settle($centreid);
        }
        if ($replaced) {
            pseudonym::clean($centreid);
        }
        $transaction->allow_commit();
        self::cross($centreid, $made ? array_column($choices, 'kind') : []);
        return [count($made), $total, $skipped];
    }

    /**
     * The families of the lists worked out again, and what each import says of the contacts it shares.
     *
     * @param int $centreid
     */
    public static function settle(int $centreid): void {
        global $DB;
        // Never two students under one key in one list, whatever a list before had joined.
        liststudents::one_a_year($centreid);
        $shared = listhomes::settle($centreid);
        foreach ($DB->get_records(self::T, ['centreid' => $centreid], '', 'id, info') as $import) {
            [$never, $doubt] = $shared[$import->id] ?? [0, 0];
            $info = self::info($import);
            if ((int) ($info['never'] ?? 0) !== $never || (int) ($info['doubt'] ?? 0) !== $doubt) {
                $info = ['never' => $never, 'doubt' => $doubt] + $info;
                $DB->set_field(self::T, 'info', json_encode($info), ['id' => $import->id]);
            }
        }
    }

    /**
     * What an import keeps of itself: sum (its rows, to know the same file again), never and doubt (the contacts it has
     * that join nobody, or only with something else in common).
     *
     * @param \stdClass $import
     * @return array
     */
    public static function info(\stdClass $import): array {
        return (array) json_decode((string) ($import->info ?? ''), true);
    }

    /**
     * The rows of a sheet in one number, whatever their order, to know the same file again.
     *
     * @param pseudonym $keys
     * @param array $plan
     * @return string
     */
    protected static function sum(pseudonym $keys, array $plan): string {
        $rows = array_map(fn($row) => implode("\t", array_map(fn($v) => trim((string) $v), $row)), $plan['rows']);
        sort($rows);
        return substr($keys->keyhash('import', implode("\n", $rows)), 0, 32);
    }

    /**
     * The contacts of a campaign already imported that a sheet would bring again: the same rows; or, when the import does
     * not keep them in a number, the same name with as many rows or from the same file.
     *
     * @param int $centreid
     * @param array $plan
     * @param string $label
     * @param string $sum
     * @param string $filename
     * @return array|null [import, whether its rows are the same]
     */
    protected static function already(int $centreid, array $plan, string $label, string $sum, string $filename): ?array {
        if ($plan['kind'] !== 'contacts') {
            return null;
        }
        $like = null;
        foreach (self::for_centre($centreid, 'contacts') as $import) {
            $was = (string) (self::info($import)['sum'] ?? '');
            $named = grades::plain((string) $import->label) === grades::plain($label);
            if ($was !== '' && $was === $sum) {
                return [$import, true];
            }
            $same = (int) $import->rowcount === count($plan['rows']) ||
                ((string) $import->filename === $filename && (string) $import->sheet === $plan['name']);
            if ($named && $same && !$like) {
                $like = [$import, false];
            }
        }
        return $like;
    }

    /**
     * The emails and phones of a sheet that several students share and do not join their families by themselves, to say it
     * before it comes in: they are only shown to who brings the file, never kept.
     *
     * @param array $plan
     * @param int $centreid
     * @return array[] what (email or phone), value, students, families and never (whether it joins nobody at all).
     */
    protected static function shared_by(array $plan, int $centreid): array {
        if (!in_array($plan['kind'], ['joins', 'leaves', 'roll', 'contacts'], true)) {
            return [];
        }
        $own = self::own_keys($centreid) + self::shared_keys($plan['rows'], $plan['columns']);
        $rows = [];
        $shown = [];
        $count = [];
        foreach ($plan['rows'] as $i => $row) {
            $k = [];
            foreach (['email', 'phone'] as $role) {
                foreach (self::values($row, $plan['columns'], $role) as $value) {
                    $key = pseudonym::$role($value);
                    if ($key !== null) {
                        $k[$key] = $key;
                        $shown[$key] = $shown[$key] ?? [$role, $value];
                    }
                }
            }
            foreach ($k as $key) {
                $count[$key] = ($count[$key] ?? 0) + 1;
            }
            $person = $plan['kind'] === 'contacts' ? ['sur' => []] : self::person($row, $plan['columns']);
            $rows[] = ['t' => $plan['kind'] === 'contacts' ? 'c' : 's', 'student' => 'r' . $i,
                'k' => array_values(array_diff_key($k, $own)), 's' => $person['sur']];
        }
        $judged = listhomes::judge($rows);
        $out = [];
        foreach ($count as $key => $n) {
            $what = isset($own[$key]) ? listhomes::NEVER : ($judged['status'][$key] ?? listhomes::PLAIN);
            if ($what !== listhomes::PLAIN && $n > 1) {
                $out[] = ['what' => $shown[$key][0], 'value' => $shown[$key][1], 'students' => $n,
                    'never' => $what === listhomes::NEVER];
            }
        }
        usort($out, fn($a, $b) => [$b['never'], $b['students'], $a['value']] <=> [$a['never'], $a['students'], $b['value']]);
        return $out;
    }

    /**
     * The contacts of the centre itself, which are of no family.
     *
     * @param int $centreid
     * @return array Key => true.
     */
    protected static function own_keys(int $centreid): array {
        $centre = $centreid ? centres::get($centreid) : null;
        if (!$centre) {
            return [];
        }
        return array_fill_keys(array_filter([pseudonym::email((string) ($centre->email ?? '')),
            pseudonym::email((string) ($centre->emailsettled ?? '')), pseudonym::phone((string) ($centre->phone ?? ''))]), true);
    }

    /**
     * Puts the lists of a school year in the place of the ones that were there with the same names, as a bridge with
     * the management program does every night: the new ones come in before the old ones go, so a student keeps its key
     * from one night to the next, and the families are worked out and crossed once, with all of them in. All or nothing:
     * if one cannot be read, everything stays as it was. A list of the year that comes empty, or any of which no row is
     * understood, never takes out the one there was; nor does one that comes cut short (less than KEEPS of what it had),
     * until a person says it is right. Only the list of that school year goes: one of another year with the same name stays.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @param array $lists The name of each list => [kind (roll, joins or leaves), path and, if it is not its own, filename].
     * @param int $userid
     * @return array The name of each list => [rows, rows left out] and, when it was cut short and the one there was stays,
     *     how many rows that one has.
     */
    public static function replace(int $centreid, string $schoolyear, array $lists, int $userid): array {
        global $DB;
        $done = [];
        $kinds = [];
        $held = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($lists as $label => $list) {
            if (!in_array($list['kind'], ['roll', 'joins', 'leaves'], true)) {
                throw new \coding_exception('Not a list of a school year: ' . $list['kind']);
            }
            if (!is_readable($list['path'])) {
                throw new \moodle_exception('import_err_empty', 'local_oksigeniaeguahe', '', null, $list['path']);
            }
            $old = self::of_year($centreid, (string) $label, $list['kind'], $schoolyear);
            $had = array_sum(array_map(fn($i) => (int) $i->rowcount, $old));
            $choice = ['kind' => $list['kind'], 'label' => $label, 'schoolyear' => $schoolyear];
            [$made, $rows, $skipped] = self::load(
                $centreid,
                $list['path'],
                $list['filename'] ?? basename($list['path']),
                [0 => $choice],
                $userid
            );
            $done[$label] = [$rows, $skipped];
            if ($had >= self::CUTFROM && $rows < self::KEEPS * $had && !listnews::accepted($centreid, (string) $label, $rows)) {
                foreach ($made as $id) {
                    self::remove($DB->get_record(self::T, ['id' => $id], '*', MUST_EXIST));
                }
                $done[$label][] = $had;
                $held[$label] = ['rows' => $rows, 'had' => $had];
                continue;
            }
            $held[$label] = null;
            if (!$made && ($skipped || $list['kind'] === 'roll')) {
                continue;
            }
            foreach ($old as $import) {
                self::remove($import);
            }
            if ($made || $old) {
                $kinds[] = $list['kind'];
            }
        }
        if ($kinds) {
            self::settle($centreid);
        }
        if ($kinds || array_filter($held)) {
            pseudonym::clean($centreid);
        }
        $transaction->allow_commit();
        listnews::hold($centreid, $held, time());
        self::cross($centreid, $kinds);
        return $done;
    }

    /**
     * The lists of a centre with a name whose rows are all of a school year.
     *
     * @param int $centreid
     * @param string $label
     * @param string $kind
     * @param string $schoolyear
     * @return \stdClass[]
     */
    protected static function of_year(int $centreid, string $label, string $kind, string $schoolyear): array {
        global $DB;
        $named = $DB->get_records(self::T, ['centreid' => $centreid, 'label' => \core_text::substr($label, 0, 100),
            'kind' => $kind], 'id ASC');
        return array_filter($named, fn($import) => !$DB->record_exists_select(
            self::TABLES[$kind],
            'importid = :id AND schoolyear <> :year',
            ['id' => $import->id, 'year' => $schoolyear]
        ));
    }

    /**
     * Reads the sheets of a file and keeps their rows. The families of the rows are still to be worked out (settle()).
     *
     * @param int $centreid
     * @param string $path
     * @param string $filename
     * @param array $choices As import_file() has them.
     * @param int $userid
     * @return array [the imports made, rows imported, rows left out, the imports they take the place of]
     */
    protected static function load(int $centreid, string $path, string $filename, array $choices, int $userid): array {
        global $DB;
        $keys = new pseudonym($centreid);
        $made = [];
        $replaced = [];
        $total = 0;
        $skipped = 0;
        foreach (sheets::read($path, $filename) as $i => $sheet) {
            $choice = $choices[$i] ?? null;
            if (!$choice || !isset(self::TABLES[$choice['kind'] ?? '']) || $choice['kind'] === 'students') {
                continue;
            }
            $plan = recognise::sheet($sheet, $filename, $choice['kind']);
            $choice += ['label' => $plan['label'], 'schoolyear' => $plan['schoolyear']];
            $label = \core_text::substr(trim((string) $choice['label']) ?: $plan['label'], 0, 100);
            $sum = self::sum($keys, $plan);
            $instead = null;
            if (!empty($choice['instead'])) {
                $instead = $DB->get_record(self::T, ['id' => (int) $choice['instead'], 'centreid' => $centreid,
                    'kind' => $choice['kind']]) ?: null;
            } else if (empty($choice['both'])) {
                [$was, $same] = self::already($centreid, $plan, $label, $sum, $filename) ?? [null, false];
                $instead = $same && grades::plain((string) $was->label) === grades::plain($label) ? $was : null;
            }
            [$records, $left] = self::records($centreid, $plan, $choice, $keys);
            $skipped += $left;
            $keys->flush();
            // A later row may have made one student of two earlier ones of this same sheet.
            foreach ($records as $record) {
                if (!empty($record->student)) {
                    $record->student = $keys->current($record->student);
                }
            }
            if (!$records) {
                continue;
            }
            $importid = (int) $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'kind' => $choice['kind'],
                'label' => $label, 'filename' => \core_text::substr($filename, 0, 255),
                'sheet' => \core_text::substr($plan['name'], 0, 100),
                'rowcount' => $choice['kind'] === 'alumni' ? count(array_filter($records, fn($r) => $r->exact)) : count($records),
                'userid' => $userid, 'timecreated' => time(), 'campaignstart' => $instead ? (int) $instead->campaignstart : 0,
                'partial' => $choice['kind'] === 'roll' && !empty($choice['partial']) ? 1 : 0,
                'info' => json_encode(['sum' => $sum])]);
            foreach ($records as $record) {
                $record->centreid = $centreid;
                $record->importid = $importid;
            }
            $DB->insert_records(self::TABLES[$choice['kind']], $records);
            if ($instead && !isset($replaced[$instead->id])) {
                $replaced[$instead->id] = $instead;
                // The hours the team wrote for it are those of the one that takes its place.
                $DB->set_field('local_oksigeniaeguahe_hours', 'scopeid', $importid, ['centreid' => $centreid, 'scope' => 'import',
                    'scopeid' => $instead->id]);
            }
            $made[] = $importid;
            $total += $choice['kind'] === 'alumni' ? count(array_filter($records, fn($r) => $r->exact)) : count($records);
        }
        return [$made, $total, $skipped, $replaced];
    }

    /**
     * What has to be looked at again after some lists came in.
     *
     * @param int $centreid
     * @param string[] $kinds The kinds of the lists.
     */
    protected static function cross(int $centreid, array $kinds): void {
        // A list of former students: every adult of the centre is looked for in it, in the background (a big centre and a
        // long list take longer than a page).
        if (in_array('alumni', $kinds, true)) {
            \local_oksigeniaeguahe\task\match_alumni::queue($centreid);
        }
        // A list of the year: the families of Eguahe that enrolled a child, or that have one at the centre.
        if (array_intersect($kinds, ['roll', 'joins', 'leaves'])) {
            try {
                listmatch::detect(centres::get($centreid), time());
                continuing::from_lists(centres::get($centreid), true);
            } catch (\Throwable $e) {
                debugging('Eguahe: the list was not crossed with the families: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Imports a CSV with one kind of list, as 0.3 did: the format of Eguahe (docs/05-importacion.md of hq) or any other.
     *
     * @param int $centreid
     * @param string $kind contacts, joins, leaves, roll (or students), totals or population.
     * @param string $label The campaign or the school year.
     * @param string $filename
     * @param string $content
     * @param int $userid
     * @return array [import id or 0, rows, errors (line => message)]
     */
    public static function import(
        int $centreid,
        string $kind,
        string $label,
        string $filename,
        string $content,
        int $userid
    ): array {
        global $DB;
        $kind = $kind === 'students' ? 'roll' : $kind;
        if (!isset(self::TABLES[$kind])) {
            throw new \coding_exception('Unknown kind of import ' . $kind);
        }
        $path = make_request_directory() . '/' . clean_filename($filename ?: 'import.csv');
        file_put_contents($path, $content);
        $schoolyear = recognise::schoolyear($label) ?? (preg_match('/^\d{4}$/', trim($label)) ? trim($label) : null);
        [$made, $rows] = self::import_file(
            $centreid,
            $path,
            basename($path),
            [0 => ['kind' => $kind, 'label' => $label, 'schoolyear' => $schoolyear]],
            $userid
        );
        if (!$made) {
            return [0, 0, [1 => get_string('import_err_empty', 'local_oksigeniaeguahe')]];
        }
        $id = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {' . self::T . '} WHERE centreid = :centreid',
            ['centreid' => $centreid]
        );
        return [$id, $rows, []];
    }

    /**
     * The values of a row in the columns of a role.
     *
     * @param array $row
     * @param array $columns
     * @param string $role
     * @return string[]
     */
    protected static function values(array $row, array $columns, string $role): array {
        $out = [];
        foreach ($columns as $j => $col) {
            $value = trim((string) ($row[$j] ?? ''));
            if ($col['role'] === $role && $value !== '') {
                $out[$j] = $value;
            }
        }
        return $out;
    }

    /**
     * A text without the emails and phone numbers someone wrote in it.
     *
     * @param string $value
     * @return string
     */
    public static function scrub(string $value): string {
        $value = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '', $value);
        $value = preg_replace('/(p:)?\+?\d[\d\s().\/-]{7,}\d/u', '', (string) $value);
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Whether a cell says yes.
     *
     * @param string $value
     * @return bool
     */
    protected static function yes(string $value): bool {
        return in_array(grades::plain($value), ['true', 'verdadero', '1', 'si', 'yes', 'sim', 'x'], true);
    }

    /**
     * The first day of a school year: «2024-25» starts in September, «2025» in March.
     *
     * @param string $schoolyear
     * @return int
     */
    public static function year_start(string $schoolyear): int {
        $year = (int) substr($schoolyear, 0, 4);
        return strlen($schoolyear) > 4 ? make_timestamp($year, 9, 1, 12) : make_timestamp($year, 3, 1, 12);
    }

    /**
     * A municipality of the list of the centre, by its code or its name.
     *
     * @param array $places Code => name.
     * @param string $value
     * @return array [code or null, the text when it is not on the list]
     */
    protected static function place(array $places, string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }
        if (isset($places[$value]) || preg_match('/^\d{3,10}$/', $value)) {
            // On the list, or an official code anyway.
            return [\core_text::substr($value, 0, 20), null];
        }
        foreach ($places as $code => $name) {
            if (grades::plain($name) === grades::plain($value) && strpos((string) $code, 'name:') !== 0) {
                return [(string) $code, null];
            }
        }
        return [null, \core_text::substr($value, 0, 255)];
    }

    /**
     * The emails and phones in more than half of the rows of a list: the centre's own (the account that books the
     * visits, the switchboard), never a family's. They are not keys, or every family of the list would become one.
     *
     * @param array $rows
     * @param array $columns
     * @return array Key => rows it is in.
     */
    public static function shared_keys(array $rows, array $columns): array {
        if (count($rows) < 5) {
            return [];
        }
        $seen = [];
        foreach ($rows as $row) {
            $keys = array_unique(array_filter(array_merge(
                array_map([pseudonym::class, 'email'], self::values($row, $columns, 'email')),
                array_map([pseudonym::class, 'phone'], self::values($row, $columns, 'phone'))
            )));
            foreach ($keys as $key) {
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }
        return array_filter($seen, fn($n) => $n * 2 > count($rows));
    }

    /**
     * The school year of a row: its own or, without one, the one of the list.
     *
     * @param string $value
     * @param array $choice
     * @return string
     */
    protected static function row_year(string $value, array $choice): string {
        return recognise::schoolyear($value) ?? (preg_match('/^\d{4}(-\d{2})?$/', $value) ? $value :
            (string) ($choice['schoolyear'] ?? ''));
    }

    /**
     * Which student each row of a list is (liststudents).
     *
     * @param int $centreid
     * @param array $rows
     * @param array $columns
     * @param array $choice
     * @param array $people Row => person().
     * @param array $contacts Row => its contacts.
     * @param pseudonym $keys
     * @return string[] Row => token.
     */
    protected static function students(
        int $centreid,
        array $rows,
        array $columns,
        array $choice,
        array $people,
        array $contacts,
        pseudonym $keys
    ): array {
        $list = [];
        foreach ($rows as $i => $row) {
            $v = fn(string $role) => (string) (array_values(self::values($row, $columns, $role))[0] ?? '');
            $person = $people[$i];
            $with = [];
            if ($person['name'] !== null) {
                // Its name with its day of birth, or with a contact of its family.
                foreach (
                    array_merge($contacts[$i], recognise::plain($v('birthdate')) !== '' ?
                    ['born:' . recognise::plain($v('birthdate'))] : []) as $one
                ) {
                    $with[] = 'with:' . $person['name'] . '|' . $one;
                }
            }
            [$grade] = grades::split_group($v('grade'));
            $list[$i] = ['codes' => $person['codes'], 'name' => $person['name'], 'ordered' => $person['ordered'], 'with' => $with,
                'grade' => $grade === '' ? '' : grades::label($centreid, $grade), 'year' => self::row_year($v('schoolyear'), $choice)];
        }
        return liststudents::tokens($centreid, $keys, $list);
    }

    /**
     * Who a row is about: its codes and its whole name as keys (the first one it names) and, only to tell families apart and
     * which child it is, the words of its surnames and of its first name (all its words, when they are not told apart).
     *
     * @param array $row
     * @param array $columns
     * @param string[] $roles Where it is: in a contact, the other names are those of the adult who wrote.
     * @return array codes, name, ordered, sur and names
     */
    protected static function person(array $row, array $columns, array $roles = ['fullname', 'childname']): array {
        $whole = null;
        $student = in_array('fullname', $roles, true);
        $first = $student ? implode(' ', self::values($row, $columns, 'firstname')) : '';
        $last = $student ? implode(' ', self::values($row, $columns, 'surname')) : '';
        $childlast = '';
        foreach ($roles as $role) {
            foreach (self::values($row, $columns, $role) as $j => $value) {
                // Only the surnames of the student («Apellidos del alumno»), or its name, whole or not.
                $title = recognise::plain((string) $columns[$j]['title']);
                $rest = preg_replace('/\b(apellidos?|apelidos?|surnames?|last names?|sobrenomes?)\b/', '', $title, -1, $n);
                if ($role === 'childname' && $n && !preg_match('/\b(nombres?|nomes?|names?)\b/', (string) $rest)) {
                    $childlast = $childlast !== '' ? $childlast : $value;
                } else {
                    $whole = $whole ?? [$value, $n && preg_match('/^(apellido|apelido|surname|last name|sobrenome)/', $title)];
                }
            }
        }
        if ($whole !== null && $childlast !== '' && strpos($whole[0], ',') === false) {
            // The name of the student in one column and its surnames in another.
            [$first, $last, $whole] = [$whole[0], $childlast, null];
        }
        if ($whole === null && ($first === '' || $last === '')) {
            $sur = pseudonym::words($last);
            $told = pseudonym::words($first);
            $name = null;
            $ordered = null;
        } else if ($whole === null) {
            $sur = pseudonym::words($last);
            $told = pseudonym::words($first);
            $name = pseudonym::name($first . ' ' . $last);
            $ordered = pseudonym::ordered($first . ' ' . $last);
        } else if (strpos($whole[0], ',') !== false) {
            [$a, $b] = array_map('trim', explode(',', $whole[0], 2));
            $sur = pseudonym::words($a);
            $told = pseudonym::words($b);
            $name = pseudonym::name($whole[0]);
            $ordered = pseudonym::ordered($b . ' ' . $a);
        } else {
            // A whole name: its last two words may be its surnames (the first two, when they go first), never the first name.
            $told = pseudonym::words($whole[0]);
            $n = min(2, count($told) - 1);
            $sur = $n < 1 ? [] : ($whole[1] ? array_slice($told, 0, $n) : array_slice($told, -$n));
            $name = pseudonym::name($whole[0]);
            $ordered = pseudonym::ordered($whole[0]);
        }
        return ['codes' => array_values(array_unique(array_filter(array_map(
            [pseudonym::class, 'code'],
            self::values($row, $columns, 'studentid')
        )))), 'name' => $name, 'ordered' => $ordered, 'sur' => $sur, 'names' => $told ?: $sur];
    }

    /**
     * The rows of a sheet as rows of its table, with keys instead of people.
     *
     * @param int $centreid
     * @param array $plan From recognise::sheet().
     * @param array $choice kind, label, schoolyear.
     * @param pseudonym $keys
     * @return array [records, rows left out]
     */
    protected static function records(int $centreid, array $plan, array $choice, pseudonym $keys): array {
        $columns = $plan['columns'];
        $kind = $choice['kind'];
        $places = centres::municipalities(centres::get($centreid));
        $title = $plan['title'];
        $out = [];
        $skipped = 0;
        $channel = 'other';
        $roles = array_column($columns, 'role');
        if (array_intersect(['platform', 'campaign', 'ad', 'adset', 'organic'], $roles)) {
            $channel = 'meta';
        } else {
            foreach ($columns as $col) {
                if ($col['role'] === 'date' && preg_match('/marca temporal|timestamp|carimbo/', recognise::plain($col['title']))) {
                    $channel = 'googleforms';
                }
            }
        }
        // What is of the centre itself, or in most of the rows, is of no family.
        $shared = self::shared_keys($plan['rows'], $columns) + self::own_keys($centreid);
        $contacts = [];
        $people = [];
        $students = in_array($kind, ['joins', 'leaves', 'roll'], true);
        foreach ($plan['rows'] as $i => $row) {
            $contacts[$i] = array_values(array_filter(array_merge(
                array_map([pseudonym::class, 'email'], self::values($row, $columns, 'email')),
                array_map([pseudonym::class, 'phone'], self::values($row, $columns, 'phone'))
            ), fn($k) => $k !== null && !isset($shared[$k])));
            $people[$i] = $students ? self::person($row, $columns) : null;
        }
        if ($students || $kind === 'contacts') {
            $keys->warm('household', array_merge([], ...$contacts));
        }
        $tokens = $students ? self::students($centreid, $plan['rows'], $columns, $choice, $people, $contacts, $keys) : [];
        // The order of the dates of each column, from all its values at once: a sheet from one country is all one way.
        $centre = centres::get($centreid);
        $monthfirst = [];
        foreach (['birthdate', 'date', 'enrolleddate', 'datein', 'dateout', 'refdate'] as $role) {
            $monthfirst[$role] = recognise::monthfirst(
                array_map(fn($row) => (string) (array_values(self::values($row, $columns, $role))[0] ?? ''), $plan['rows']),
                (string) ($centre->country ?? ''),
                (string) ($centre->lang ?? 'es')
            );
        }
        foreach ($plan['rows'] as $i => $row) {
            $v = fn(string $role) => (string) (array_values(self::values($row, $columns, $role))[0] ?? '');
            // A text kept for the numbers never keeps an email or a phone someone wrote in it.
            $short = fn(string $value, int $len) => ($value = self::scrub($value)) === '' ? null :
                \core_text::substr($value, 0, $len);
            $birth = recognise::date($v('birthdate'), $monthfirst['birthdate']);
            $birthyear = $birth ? (int) date('Y', $birth) : 0;
            $hkeys = $contacts[$i];
            if ($kind === 'alumni') {
                // A former student: the keys of their name and the year they left, never the name.
                $names = array_merge(self::values($row, $columns, 'fullname'), self::values($row, $columns, 'childname'));
                $first = implode(' ', self::values($row, $columns, 'firstname'));
                $last = implode(' ', self::values($row, $columns, 'surname'));
                if ($first !== '' || $last !== '') {
                    $names[] = trim($first . ' ' . $last);
                }
                $year = $v('alumniyear') ?: ($v('schoolyear') ?: $v('year'));
                $rows = [];
                foreach (array_filter(array_map('trim', $names)) as $name) {
                    $rows = array_merge($rows, alumni::list_rows($keys, $name, $year));
                }
                if (!$rows) {
                    $skipped++;
                    continue;
                }
                array_push($out, ...$rows);
                continue;
            }
            if ($kind === 'contacts') {
                $date = recognise::date($v('date'), $monthfirst['date']);
                if (!$date) {
                    $skipped++;
                    continue;
                }
                $given = (bool) preg_match(self::TOKEN, $v('householdtoken'));
                $household = $given ? $v('householdtoken') : ($keys->home($hkeys) ?? pseudonym::newhome());
                // The first child it names: several in one row (a form that asks for each) are never one student.
                $skey = self::person($row, $columns, ['childname'])['name'];
                $platform = \core_text::strtolower($v('platform'));
                $contactkind = in_array($v('kindcol'), self::CONTACT_KINDS, true) ? $v('kindcol') :
                    ($v('visit') !== '' ? 'visit_booked' :
                    (preg_match('/\b(solicitud|plaza|application|inscripci\w*|matricula|admision|vaga)\b/', $title) ?
                    'application' : 'inquiry'));
                $medium = $v('medium') !== '' ? $v('medium') :
                    (in_array('organic', $roles, true) ? (self::yes($v('organic')) ? 'organic' : 'paid') : '');
                $grade = trim(explode('|', $v('grade'))[0]);
                $enrolled = recognise::date($v('enrolleddate'), $monthfirst['enrolleddate']);
                $out[] = (object) [
                    'household' => $household,
                    'marks' => listhomes::marks($keys, $given ? [] : $hkeys, [], [], self::yes($v('alumniflag'))),
                    'student' => $skey !== null ? $keys->token('student', [$skey]) : null,
                    'timecontact' => $date,
                    'channel' => self::CHANNELS[$v('channel')] ?? ($v('channel') !== '' ? teamnotes::channel($v('channel')) : null) ??
                        $channel,
                    'sourcedetail' => $short($v('form') ?: $plan['label'], 255),
                    'kind' => $contactkind,
                    'graderequested' => $grade === '' ? null : $short(grades::label($centreid, $grade), 100),
                    'children' => max(0, (int) $v('children')),
                    'municipality' => self::place($places, $v('municipality'))[0],
                    'declaredsource' => \core_text::strlen($v('declared')) <= 60 ? $short($v('declared'), 100) : null,
                    'utmsource' => $short($platform, 100),
                    'utmmedium' => $short($medium, 30),
                    'utmcampaign' => $short($v('campaign'), 255),
                    'enrolled' => self::yes($v('enrolledflag')) ? 1 : 0,
                    'enrolledgrade' => $short($v('enrolledgrade'), 100),
                    'timeenrolled' => (int) $enrolled,
                    'incentre' => self::yes($v('incentre')) || preg_match('/\b(hermanos?|siblings?|irmaos?)\b/', $title) ? 1 : 0,
                    'birthyear' => $birthyear,
                ];
            } else if (in_array($kind, ['joins', 'leaves', 'roll'], true)) {
                $schoolyear = self::row_year($v('schoolyear'), $choice);
                if (!preg_match('/^\d{4}(-\d{2})?$/', $schoolyear)) {
                    $skipped++;
                    continue;
                }
                $person = $people[$i];
                if (
                    in_array('firstname', $roles, true) && in_array('surname', $roles, true) && $v('firstname') === '' && $v('fullname') === '' &&
                        $v('childname') === '' && !$hkeys && !preg_grep('/[@\d]/', self::values($row, $columns, 'studentid')) &&
                        $v('studenttoken') === ''
                ) {
                    // With a column for the name and nothing in it, nor any way to reach them, it is not a row of a student: something
                    // written under the list.
                    $skipped++;
                    continue;
                }
                $student = preg_match(self::TOKEN, $v('studenttoken')) ? $v('studenttoken') : $tokens[$i];
                $given = (bool) preg_match(self::TOKEN, $v('householdtoken'));
                $household = $given ? $v('householdtoken') : $keys->home($hkeys);
                [$grade, $group] = grades::split_group($v('grade'));
                $start = self::year_start($schoolyear);
                $in = recognise::date($v('datein'), $monthfirst['datein']);
                if ($in && $in < $start - self::BEFORE) {
                    // The day it came to the centre, years ago: this year, it was there from the start.
                    $in = $start - HOURSECS;
                }
                $outdate = recognise::date($v('dateout'), $monthfirst['dateout']);
                $reasons = [];
                foreach (self::values($row, $columns, 'leavebool') as $j => $value) {
                    if (self::yes($value)) {
                        $reasons[] = trim(str_replace("\n", ' ', $columns[$j]['title']));
                    } else if (!in_array(grades::plain($value), ['false', 'falso', '0', 'no'], true)) {
                        $reasons[] = $value;
                    }
                }
                $other = $v('otherschool');
                $other = in_array(grades::plain($other), ['true', 'false', 'verdadero', 'falso', 'centro', 'n a'], true) ?
                    '' : $other;
                $reason = $v('reason') ?: implode(', ', $reasons);
                if ($reason === '' && $kind === 'leaves' && $other !== '') {
                    $reason = get_string('import_reason_otherschool', 'local_oksigeniaeguahe');
                }
                [$code, $locality] = self::place($places, $v('municipality'));
                $out[] = (object) [
                    'student' => $student,
                    'household' => $household,
                    'marks' => listhomes::marks(
                        $keys,
                        $given ? [] : $hkeys,
                        $person['sur'],
                        $person['names'],
                        $household !== null && self::yes($v('alumniflag'))
                    ),
                    'schoolyear' => $schoolyear,
                    'grade' => $grade === '' ? '' : (string) $short(grades::label($centreid, $grade), 100),
                    'groupname' => $short($v('group') ?: (string) $group, 100),
                    'timein' => $in ?: $start,
                    'timeout' => $outdate ?: ($kind === 'leaves' ? -1 : 0),
                    'outreason' => $short($reason, 255),
                    'municipality' => $code,
                    'locality' => $short($v('locality') ?: (string) $locality, 255),
                    'movement' => ['joins' => 'join', 'leaves' => 'leave'][$kind] ?? 'roll',
                    'otherschool' => $short($other, 255),
                    'birthyear' => $birthyear,
                ];
            } else if ($kind === 'totals') {
                $schoolyear = recognise::schoolyear($v('schoolyear')) ??
                    ($v('schoolyear') ?: (string) ($choice['schoolyear'] ?? ''));
                if (!preg_match('/^\d{4}(-\d{2})?$/', $schoolyear) || !is_numeric($v('count'))) {
                    $skipped++;
                    continue;
                }
                $out[] = (object) ['schoolyear' => $schoolyear, 'grade' => grades::label($centreid, $v('grade')),
                    'students' => (int) $v('count'),
                    'referencedate' => (int) recognise::date($v('refdate'), $monthfirst['refdate'])];
            } else if ($kind === 'population') {
                $popkind = in_array($v('kindcol'), ['births', 'age'], true) ? $v('kindcol') :
                    ($v('agecol') !== '' ? 'age' : 'births');
                if (!preg_match('/^\d{4}$/', $v('year')) || !is_numeric($v('people'))) {
                    $skipped++;
                    continue;
                }
                $out[] = (object) ['source' => 'csv', 'kind' => $popkind,
                    'municipality' => \core_text::substr($v('municipality'), 0, 20), 'year' => (int) $v('year'),
                    'age' => $popkind === 'age' ? (int) $v('agecol') : -1, 'people' => (int) $v('people'),
                    'timefetched' => time()];
            }
        }
        return [$out, $skipped];
    }

    /**
     * Removes an import and everything it brought, and the keys nothing uses any more.
     *
     * @param int $centreid
     * @param int $importid
     */
    public static function delete(int $centreid, int $importid): void {
        global $DB;
        $import = $DB->get_record(self::T, ['id' => $importid, 'centreid' => $centreid], '*', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        self::remove($import);
        self::settle($centreid);
        pseudonym::clean($centreid);
        $transaction->allow_commit();
        if ($import->kind === 'alumni') {
            alumni::list_removed($centreid);
        } else {
            // What only that list said of the families of Eguahe goes too.
            self::cross($centreid, [$import->kind]);
        }
    }

    /**
     * Removes an import and its rows, without looking at the keys.
     *
     * @param \stdClass $import
     */
    protected static function remove(\stdClass $import): void {
        global $DB;
        $DB->delete_records(self::TABLES[$import->kind], ['importid' => $import->id]);
        $DB->delete_records(alumni::T, ['importid' => $import->id]);
        $DB->delete_records(self::T, ['id' => $import->id]);
    }
}
