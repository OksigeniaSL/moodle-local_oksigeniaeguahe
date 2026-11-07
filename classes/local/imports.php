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
 * What already happened, imported to look at it: the contacts of a past campaign, the students of a school year and the
 * totals of past years (docs/05-importacion.md of hq). Pseudonymised only: tokens instead of households and students,
 * and a file that carries an email, a phone or anything like a name is refused whole. Nobody is created as a household:
 * it is for the numbers.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imports {
    /** @var string */
    const T = 'local_oksigeniaeguahe_import';

    /** @var array Kinds of file, with the table they go to and their columns: required first, then optional. */
    const KINDS = [
        'contacts' => ['table' => 'local_oksigeniaeguahe_pastcontact',
            'required' => ['household', 'date', 'channel', 'kind'],
            'optional' => ['source_detail', 'grade_requested', 'children', 'municipality', 'declared_source', 'utm_source',
                'utm_medium', 'utm_campaign', 'enrolled', 'enrolled_grade', 'enrolled_date']],
        'students' => ['table' => 'local_oksigeniaeguahe_student',
            'required' => ['student', 'school_year', 'grade', 'date_in'],
            'optional' => ['household', 'group', 'date_out', 'out_reason', 'municipality', 'locality']],
        'totals' => ['table' => 'local_oksigeniaeguahe_enrolcount',
            'required' => ['school_year', 'grade', 'count'],
            'optional' => ['reference_date']],
        'population' => ['table' => 'local_oksigeniaeguahe_population',
            'required' => ['municipality', 'year', 'kind', 'people'],
            'optional' => ['age']],
    ];

    /** @var array The channels of the import format, as Eguahe names them. */
    const CHANNELS = ['web_form' => 'webform', 'visit_booking' => 'visit', 'open_day' => 'openday',
        'meta_lead_ads' => 'meta', 'google_forms' => 'googleforms', 'whatsapp' => 'whatsapp', 'telegram' => 'telegram',
        'email' => 'email', 'phone' => 'phone', 'walk_in' => 'walkin', 'other' => 'other'];

    /** @var string[] What a contact was. */
    const CONTACT_KINDS = ['inquiry', 'application', 'visit_booked', 'visit_attended', 'open_day_attended'];

    /** @var string[] Columns of free text, where a phone number would be a person's. */
    const TEXTS = ['source_detail', 'grade_requested', 'declared_source', 'enrolled_grade', 'grade', 'group', 'out_reason',
        'locality'];

    /** @var int Errors shown at most. */
    const MAXERRORS = 20;

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
     * Reads a CSV file: UTF-8, commas or semicolons, with a header.
     *
     * @param string $content
     * @return array [header, rows], rows as arrays keyed by the header.
     */
    public static function parse(string $content): array {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $firstline = strtok($content, "\n");
        $delimiter = substr_count((string) $firstline, ';') > substr_count((string) $firstline, ',') ? ';' : ',';
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $header = null;
        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($line === [null] || $line === ['']) {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn($h) => strtolower(trim((string) $h)), $line);
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }
        fclose($handle);
        return [$header ?? [], $rows];
    }

    /**
     * Whether a value carries what must not be there: an email, a phone number or a name instead of a token.
     *
     * @param string $value
     * @return bool
     */
    public static function personal(string $value): bool {
        return strpos($value, '@') !== false || preg_match('/\d[\d\s().-]{7,}\d/', $value) === 1;
    }

    /**
     * A date of the format, at noon of the server, or 0 when empty; null when it is not a date.
     *
     * @param string $value
     * @return int|null
     */
    protected static function date(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return make_timestamp((int) $m[1], (int) $m[2], (int) $m[3], 12);
    }

    /**
     * Checks a file and turns it into rows of its table, or the errors that stop it.
     *
     * @param string $kind
     * @param array $header
     * @param array $rows
     * @return array [rows for the table, errors (line => message)]
     */
    public static function check(string $kind, array $header, array $rows): array {
        $c = 'local_oksigeniaeguahe';
        $spec = self::KINDS[$kind];
        $errors = [];
        $missing = array_diff($spec['required'], $header);
        if ($missing) {
            return [[], [1 => get_string('import_err_columns', $c, implode(', ', $missing))]];
        }
        $out = [];
        $token = '/^[A-Z]{2,6}_[0-9A-Za-z]{6,32}$/';
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $error = null;
            foreach ($row as $column => $value) {
                // Tokens, dates and codes are fine; an email anywhere, or a phone in a text, is not.
                $text = in_array($column, self::TEXTS, true);
                if (
                    !in_array($column, ['household', 'student'], true) && (strpos((string) $value, '@') !== false ||
                        ($text && self::personal((string) $value)))
                ) {
                    $error = get_string('import_err_personal', $c, $column);
                    break;
                }
            }
            $get = fn(string $col) => trim((string) ($row[$col] ?? ''));
            $short = fn(string $col, int $len) => $get($col) === '' ? null : \core_text::substr($get($col), 0, $len);
            if (!$error && $kind === 'contacts') {
                $date = self::date($get('date'));
                $enrolleddate = self::date($get('enrolled_date'));
                if (!preg_match($token, $get('household'))) {
                    $error = get_string('import_err_token', $c, 'household');
                } else if (!$date) {
                    $error = get_string('import_err_date', $c, 'date');
                } else if (!isset(self::CHANNELS[$get('channel')])) {
                    $error = get_string('import_err_value', $c, 'channel');
                } else if (!in_array($get('kind'), self::CONTACT_KINDS, true)) {
                    $error = get_string('import_err_value', $c, 'kind');
                } else if ($enrolleddate === null) {
                    $error = get_string('import_err_date', $c, 'enrolled_date');
                } else {
                    $out[] = (object) ['household' => $get('household'), 'timecontact' => $date,
                        'channel' => self::CHANNELS[$get('channel')], 'sourcedetail' => $short('source_detail', 255),
                        'kind' => $get('kind'), 'graderequested' => $short('grade_requested', 100),
                        'children' => max(0, (int) $get('children')), 'municipality' => $short('municipality', 20),
                        'declaredsource' => $short('declared_source', 100), 'utmsource' => $short('utm_source', 100),
                        'utmmedium' => $short('utm_medium', 30), 'utmcampaign' => $short('utm_campaign', 255),
                        'enrolled' => $get('enrolled') === '1' ? 1 : 0, 'enrolledgrade' => $short('enrolled_grade', 100),
                        'timeenrolled' => (int) $enrolleddate];
                }
            } else if (!$error && $kind === 'students') {
                $in = self::date($get('date_in'));
                $outdate = self::date($get('date_out'));
                if (!preg_match($token, $get('student'))) {
                    $error = get_string('import_err_token', $c, 'student');
                } else if ($get('household') !== '' && !preg_match($token, $get('household'))) {
                    $error = get_string('import_err_token', $c, 'household');
                } else if (!preg_match('/^\d{4}(-\d{2})?$/', $get('school_year'))) {
                    $error = get_string('import_err_value', $c, 'school_year');
                } else if ($get('grade') === '') {
                    $error = get_string('import_err_value', $c, 'grade');
                } else if (!$in) {
                    $error = get_string('import_err_date', $c, 'date_in');
                } else if ($outdate === null) {
                    $error = get_string('import_err_date', $c, 'date_out');
                } else {
                    $out[] = (object) ['student' => $get('student'), 'household' => $short('household', 40),
                        'schoolyear' => $get('school_year'), 'grade' => \core_text::substr($get('grade'), 0, 100),
                        'groupname' => $short('group', 100), 'timein' => $in, 'timeout' => (int) $outdate,
                        'outreason' => $short('out_reason', 255), 'municipality' => $short('municipality', 20),
                        'locality' => $short('locality', 255)];
                }
            } else if (!$error && $kind === 'population') {
                if ($get('municipality') === '' || !preg_match('/^\d{4}$/', $get('year'))) {
                    $error = get_string('import_err_value', $c, $get('municipality') === '' ? 'municipality' : 'year');
                } else if (!in_array($get('kind'), ['births', 'age'], true)) {
                    $error = get_string('import_err_value', $c, 'kind');
                } else if (
                    !preg_match('/^\d+$/', $get('people')) || ($get('kind') === 'age' &&
                        !preg_match('/^\d{1,2}$/', $get('age')))
                ) {
                    $error = get_string('import_err_value', $c, $get('kind') === 'age' ? 'age' : 'people');
                } else {
                    $out[] = (object) ['source' => 'csv', 'kind' => $get('kind'),
                        'municipality' => \core_text::substr($get('municipality'), 0, 20), 'year' => (int) $get('year'),
                        'age' => $get('kind') === 'age' ? (int) $get('age') : -1, 'people' => (int) $get('people'),
                        'timefetched' => time()];
                }
            } else if (!$error) {
                $reference = self::date($get('reference_date'));
                if (!preg_match('/^\d{4}(-\d{2})?$/', $get('school_year'))) {
                    $error = get_string('import_err_value', $c, 'school_year');
                } else if ($get('grade') === '' || !preg_match('/^\d+$/', $get('count'))) {
                    $error = get_string('import_err_value', $c, $get('grade') === '' ? 'grade' : 'count');
                } else if ($reference === null) {
                    $error = get_string('import_err_date', $c, 'reference_date');
                } else {
                    $out[] = (object) ['schoolyear' => $get('school_year'), 'grade' => \core_text::substr($get('grade'), 0, 100),
                        'students' => (int) $get('count'), 'referencedate' => (int) $reference];
                }
            }
            if ($error) {
                $errors[$line] = $error;
                if (count($errors) >= self::MAXERRORS) {
                    break;
                }
            }
        }
        if (!$rows) {
            $errors[1] = get_string('import_err_empty', $c);
        }
        return [$errors ? [] : $out, $errors];
    }

    /**
     * Imports a file whole, or nothing of it.
     *
     * @param int $centreid
     * @param string $kind contacts, students or totals.
     * @param string $label The campaign or the school year.
     * @param string $filename
     * @param string $content
     * @param int $userid
     * @return array [import id or 0, rows, errors]
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
        if (!isset(self::KINDS[$kind])) {
            throw new \coding_exception('Unknown kind of import ' . $kind);
        }
        [$header, $rows] = self::parse($content);
        [$records, $errors] = self::check($kind, $header, $rows);
        if ($errors) {
            return [0, 0, $errors];
        }
        $transaction = $DB->start_delegated_transaction();
        $importid = (int) $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'kind' => $kind,
            'label' => \core_text::substr(trim($label), 0, 100), 'filename' => \core_text::substr($filename, 0, 255),
            'rowcount' => count($records), 'userid' => $userid, 'timecreated' => time()]);
        foreach ($records as $record) {
            $record->centreid = $centreid;
            $record->importid = $importid;
        }
        $DB->insert_records(self::KINDS[$kind]['table'], $records);
        $transaction->allow_commit();
        return [$importid, count($records), []];
    }

    /**
     * Removes an import and everything it brought.
     *
     * @param int $centreid
     * @param int $importid
     */
    public static function delete(int $centreid, int $importid): void {
        global $DB;
        $import = $DB->get_record(self::T, ['id' => $importid, 'centreid' => $centreid], '*', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::KINDS[$import->kind]['table'], ['importid' => $import->id]);
        $DB->delete_records(self::T, ['id' => $import->id]);
        $transaction->allow_commit();
    }
}
