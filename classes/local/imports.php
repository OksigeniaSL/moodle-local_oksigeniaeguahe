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
        'population' => 'local_oksigeniaeguahe_population'];

    /** @var array The channels of the format of Eguahe, as Eguahe names them. */
    const CHANNELS = ['web_form' => 'webform', 'visit_booking' => 'visit', 'open_day' => 'openday',
        'meta_lead_ads' => 'meta', 'google_forms' => 'googleforms', 'whatsapp' => 'whatsapp', 'telegram' => 'telegram',
        'email' => 'email', 'phone' => 'phone', 'walk_in' => 'walkin', 'other' => 'other'];

    /** @var string[] What a contact was. */
    const CONTACT_KINDS = ['inquiry', 'application', 'visit_booked', 'visit_attended', 'open_day_attended'];

    /** @var string A token of the format of Eguahe. */
    const TOKEN = '/^[A-Z]{2,6}_[0-9A-Za-z]{6,32}$/';

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
     * @return array A list of sheets: index, name, kind, rows, schoolyear, label, and the columns kept, made keys and
     *               removed.
     */
    public static function preview(string $path, string $filename, array $kinds = []): array {
        $out = [];
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
                'keys' => $fates['key'], 'removed' => $fates['removed'], 'health' => $health];
        }
        return $out;
    }

    /**
     * Imports the sheets of a file the centre chose, each as the kind it chose.
     *
     * @param int $centreid
     * @param string $path
     * @param string $filename
     * @param array $choices Sheet index => [kind, label, schoolyear]; a sheet left out, or with kind «none», is not read.
     * @param int $userid
     * @return array [imports made, rows imported, rows left out]
     */
    public static function import_file(int $centreid, string $path, string $filename, array $choices, int $userid): array {
        global $DB;
        $keys = new pseudonym($centreid);
        $made = 0;
        $total = 0;
        $skipped = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach (sheets::read($path, $filename) as $i => $sheet) {
            $choice = $choices[$i] ?? null;
            if (!$choice || !isset(self::TABLES[$choice['kind'] ?? '']) || $choice['kind'] === 'students') {
                continue;
            }
            $plan = recognise::sheet($sheet, $filename, $choice['kind']);
            $choice += ['label' => $plan['label'], 'schoolyear' => $plan['schoolyear']];
            [$records, $left] = self::records($centreid, $plan, $choice, $keys);
            $skipped += $left;
            if (!$records) {
                continue;
            }
            $importid = (int) $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'kind' => $choice['kind'],
                'label' => \core_text::substr(trim((string) $choice['label']) ?: $plan['label'], 0, 100),
                'filename' => \core_text::substr($filename, 0, 255), 'sheet' => \core_text::substr($plan['name'], 0, 100),
                'rowcount' => count($records), 'userid' => $userid, 'timecreated' => time()]);
            foreach ($records as $record) {
                $record->centreid = $centreid;
                $record->importid = $importid;
            }
            $DB->insert_records(self::TABLES[$choice['kind']], $records);
            $made++;
            $total += count($records);
        }
        $transaction->allow_commit();
        return [$made, $total, $skipped];
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
        foreach ($plan['rows'] as $row) {
            $v = fn(string $role) => (string) (array_values(self::values($row, $columns, $role))[0] ?? '');
            // A text kept for the numbers never keeps an email or a phone someone wrote in it.
            $short = fn(string $value, int $len) => ($value = self::scrub($value)) === '' ? null :
                \core_text::substr($value, 0, $len);
            $birth = recognise::date($v('birthdate'), $monthfirst['birthdate']);
            $birthyear = $birth ? (int) date('Y', $birth) : 0;
            $hkeys = array_merge(
                array_map([pseudonym::class, 'email'], self::values($row, $columns, 'email')),
                array_map([pseudonym::class, 'phone'], self::values($row, $columns, 'phone'))
            );
            if ($kind === 'contacts') {
                $date = recognise::date($v('date'), $monthfirst['date']);
                if (!$date) {
                    $skipped++;
                    continue;
                }
                $household = preg_match(self::TOKEN, $v('householdtoken')) ? $v('householdtoken') :
                    $keys->token('household', $hkeys);
                $skeys = array_map([pseudonym::class, 'name'], self::values($row, $columns, 'childname'));
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
                    'student' => array_filter($skeys) ? $keys->token('student', $skeys) : null,
                    'timecontact' => $date,
                    'channel' => self::CHANNELS[$v('channel')] ?? $channel,
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
                $schoolyear = recognise::schoolyear($v('schoolyear')) ?? (preg_match('/^\d{4}(-\d{2})?$/', $v('schoolyear')) ?
                    $v('schoolyear') : (string) ($choice['schoolyear'] ?? ''));
                if (!preg_match('/^\d{4}(-\d{2})?$/', $schoolyear)) {
                    $skipped++;
                    continue;
                }
                $names = array_merge(self::values($row, $columns, 'fullname'), self::values($row, $columns, 'childname'));
                $first = implode(' ', self::values($row, $columns, 'firstname'));
                $last = implode(' ', self::values($row, $columns, 'surname'));
                if ($first !== '' && $last !== '') {
                    $names[] = $first . ' ' . $last;
                }
                $skeys = array_merge(
                    array_map([pseudonym::class, 'code'], self::values($row, $columns, 'studentid')),
                    array_map([pseudonym::class, 'name'], $names)
                );
                $student = preg_match(self::TOKEN, $v('studenttoken')) ? $v('studenttoken') : $keys->token('student', $skeys);
                $household = preg_match(self::TOKEN, $v('householdtoken')) ? $v('householdtoken') :
                    (array_filter($hkeys) ? $keys->token('household', $hkeys) : null);
                [$grade, $group] = grades::split_group($v('grade'));
                $start = self::year_start($schoolyear);
                $in = recognise::date($v('datein'), $monthfirst['datein']);
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
        $DB->delete_records(self::TABLES[$import->kind], ['importid' => $import->id]);
        $DB->delete_records(self::T, ['id' => $import->id]);
        pseudonym::clean($centreid);
        $transaction->allow_commit();
    }
}
