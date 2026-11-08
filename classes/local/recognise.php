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
 * Makes sense of a sheet as it comes: where its header is (not always on the first row), what each column holds, by
 * its name in Spanish, English or Portuguese and by what is in it, and what kind of list it is. Nothing is refused:
 * what is not understood stays out, and what is personal never goes in (names, notes, health, passwords, addresses,
 * documents). Emails, phones, student codes and children's names only become a key to join the same family or the
 * same student across files (see pseudonym).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recognise {
    /** @var array Headers of the format of Eguahe, exactly (without accents, spaces for underscores). */
    const EXACT = ['household' => 'householdtoken', 'student' => 'studenttoken', 'channel' => 'channel',
        'kind' => 'kindcol', 'source detail' => 'form', 'grade requested' => 'grade', 'children' => 'children',
        'declared source' => 'declared', 'utm source' => 'platform', 'utm medium' => 'medium', 'utm campaign' => 'campaign',
        'enrolled' => 'enrolledflag', 'enrolled grade' => 'enrolledgrade', 'enrolled date' => 'enrolleddate',
        'school year' => 'schoolyear', 'grade' => 'grade', 'group' => 'group', 'date in' => 'datein', 'date out' => 'dateout',
        'out reason' => 'reason', 'locality' => 'locality', 'count' => 'count', 'reference date' => 'refdate',
        'year' => 'year', 'age' => 'agecol', 'people' => 'people', 'date' => 'date', 'municipality' => 'municipality'];

    /** @var array Roles by the name of the column, in order: the first whose words (regular expressions) match. */
    const HEADERS = [
        'consent' => ['de acuerdo con', '^acepto', 'consent', 'politica de privacidad', 'proteccion de datos',
            'rgpd', 'gdpr', 'lgpd'],
        'health' => ['alerg', 'intoleran', 'medic', 'pediatr', 'esfinter', 'patolog', 'salud', 'enfermedad',
            'health', 'diagnos', 'discapac', 'necesidades', 'vacun', 'tratamiento', 'terapia'],
        'secret' => ['password', 'contrasena', 'senha', 'clave de acceso', '\bpin\b'],
        'birthplace' => ['lugar de nacimiento', 'place of birth', 'naturalidade', 'nacionalidad', 'nationality',
            'pais de (origen|nacimiento)'],
        'otherschool' => ['centro (del que|de) proce', 'centro de (origen|procedencia|destino)', 'procedencia',
            'colegio anterior', 'centro anterior', 'previous school', 'escola anterior', 'nombre del centro',
            'centro al que va', '^destino$', 'nuevo centro', '^centro$', '^colegio$', '^escuela$', '^school$',
            '^escola$'],
        'notes' => ['observ', 'obsev', 'comentario', '\bnotas?\b', 'anotacion', 'mensaje', 'message', 'comment',
            'duda', 'pregunta', 'cuentanos', '^cuales$', 'otros datos'],
        'email' => ['e ?mail', 'correo', 'endereco eletronico'],
        'address' => ['direccion', 'domicilio', 'address', 'endereco', '\bcalle\b', 'codigo postal', '\bcp\b',
            '\bzip\b'],
        'document' => ['\bdni\b', '\bnie\b', '\bnif\b', 'pasaporte', 'passport', '\bdocumento\b', '\bcpf\b'],
        'incentre' => ['son del cole', 'ya (es|son) (familia|del)', 'familia del centro', 'alumnos? del centro',
            'ya en el centro', 'hermanos? en el centro', 'already', 'existing famil'],
        'childname' => [
            '(nombre|apellido|name|nome).*(alumn|nin[oa]|hij[oa]|futuro|student|child|kid|aluno|crianca|filh)'],
        'guardianname' => ['\btutor', 'padre', 'madre', 'responsable', 'guardian', 'parent', '\bpai\b', '\bmae\b',
            'progenitor', 'relacion con', 'parentesco', 'relationship', 'custodia'],
        'studentid' => ['^cial$', '^nia$', 'idnumber', '^id number$', 'id alumno', 'student id',
            'codigo (de )?alumno', 'expediente n', 'numero de expediente', '^usuario$', '^username$', '^login$',
            '^user$'],
        'phone' => ['telefono', '^tel\b', 'movil', 'movl', 'celular', 'phone', 'whatsapp', 'telemovel'],
        'fullname' => ['nombre (y|completo)', 'full ?name', 'nome completo'],
        'surname' => ['apellido', 'last ?name', 'surname', 'sobrenome'],
        'firstname' => ['^(nombre|first ?name|nome|name|primer nombre)$'],
        'birthdate' => ['nacimiento', 'birth', 'nascimento', '\bf nac', 'fecha nac', '\bdob\b'],
        'date' => ['created time', 'marca temporal', 'timestamp', 'carimbo',
            'fecha (de )?(contacto|solicitud|registro|inscripcion|envio)', 'submitted', '^fecha$', '^data$',
            'fecha y hora'],
        'platform' => ['^platform$', 'plataforma', 'red social', '^network$'],
        'organic' => ['is organic', 'organico'],
        'campaign' => ['campaign name', '^campaign$', 'campana'],
        'adset' => ['adset name', 'ad set name', 'conjunto de anuncios'],
        'ad' => ['^ad name$', '^anuncio$'],
        'form' => ['form name', '^formulario$', '^form$'],
        'ids' => ['^id$', '^ad id$', 'adset id', 'campaign id', 'form id', 'lead id', '^#$', '^codigo$', '^code$'],
        'status' => ['lead status', '^estado$', '^status$'],
        'visit' => ['(momento|dia|fecha|hora) elegid', 'visita', '\bvisit'],
        'children' => ['cuantos hijos', 'numero de hijos', 'how many children', 'quantos filhos'],
        'declared' => ['como nos (conocio|conociste|conocisteis|has conocido)', 'how did you hear',
            'como (nos )?conheceu', 'donde nos conoci'],
        'schoolyear' => ['curso escolar', 'ano (academico|escolar|letivo)', 'school year', 'academic year',
            'curso academico'],
        'municipality' => ['municipio', 'localidad', 'poblacion', 'ciudad', 'city', 'town', 'concelho', 'cidade',
            'municipality', 'residencia'],
        'reason' => ['motivo', 'reason', 'causa', 'razon'],
        'leavebool' => ['peninsula', 'extranjer', 'otro pais', 'abroad', 'otra isla', 'otra comunidad',
            'otra provincia', 'mudanza', 'traslad', 'no renovacion', 'anulad'],
        'datein' => ['fecha (de )?alta', 'fecha de (entrada|incorporacion|matricula)', 'data de matricula',
            'start date'],
        'dateout' => ['fecha (de )?baja', 'fecha de salida', 'data de saida', 'end date', 'leave date'],
        'grade' => ['curso', 'nivel', 'etapa', 'grade', 'course', 'clase', 'grupo', 'turma', 'serie', '\bano\b',
            'edad', 'selecciona', 'opcion'],
        'count' => ['^total$', '^alumnos$', '^matriculados$', 'numero de alumnos', '^students$', '^n$'],
        'people' => ['personas', 'people', 'habitantes', 'nacimientos', 'births'],
    ];

    /** @var string[] Roles that become a key and are then removed. */
    const KEYS = ['email', 'phone', 'studentid', 'childname', 'fullname', 'firstname', 'surname', 'householdtoken',
        'studenttoken'];

    /** @var string[] Roles removed without being read. */
    const REMOVED = ['health', 'secret', 'birthplace', 'notes', 'address', 'document', 'consent', 'guardianname',
        'institutional'];

    /** @var array Roles kept for the numbers, by kind of list; any other is left out. */
    const USED = [
        'contacts' => ['date', 'platform', 'organic', 'medium', 'campaign', 'form', 'visit', 'children', 'declared',
            'municipality', 'grade', 'incentre', 'birthdate', 'channel', 'kindcol', 'enrolledflag', 'enrolledgrade',
            'enrolleddate'],
        'joins' => ['grade', 'group', 'schoolyear', 'datein', 'municipality', 'locality', 'otherschool', 'birthdate'],
        'leaves' => ['grade', 'group', 'schoolyear', 'dateout', 'reason', 'leavebool', 'municipality', 'locality',
            'otherschool', 'birthdate'],
        'roll' => ['grade', 'group', 'schoolyear', 'datein', 'dateout', 'reason', 'municipality', 'locality', 'otherschool',
            'birthdate'],
        'totals' => ['schoolyear', 'grade', 'count', 'refdate'],
        'population' => ['municipality', 'year', 'people', 'agecol', 'kindcol'],
    ];

    /** @var string[] Kinds of list. */
    const KINDS = ['contacts', 'joins', 'leaves', 'roll', 'totals', 'population'];

    /** @var string[] Email services of people, not of institutions. */
    const WEBMAIL = ['gmail', 'googlemail', 'hotmail', 'outlook', 'live', 'msn', 'yahoo', 'ymail', 'icloud', 'me', 'mac',
        'aol', 'gmx', 'proton', 'protonmail', 'pm', 'zoho', 'yandex', 'mail', 'telefonica', 'movistar', 'orange', 'terra',
        'uol', 'bol', 'ig', 'sapo', 'libero', 'wanadoo', 'free', 'laposte', 'web', 't-online', 'qq', '163'];

    /**
     * A header without accents, in lower case, with spaces.
     *
     * @param string $text
     * @return string
     */
    public static function plain(string $text): string {
        $text = strtr($text, ['📧' => ' email ', '✉' => ' email ', '☎' => ' telefono ', '📞' => ' telefono ',
            '📱' => ' telefono ']);
        return grades::plain($text);
    }

    /**
     * What a value looks like.
     *
     * @param string $v
     * @return string email, phone, date, bool, id, number, grade, text or empty.
     */
    public static function type(string $v): string {
        static $seen = [];
        $v = trim($v);
        if ($v === '') {
            return 'empty';
        }
        if (isset($seen[$v])) {
            return $seen[$v];
        }
        if (count($seen) > 50000) {
            $seen = [];
        }
        return $seen[$v] = self::guess_type($v);
    }

    /**
     * What a value looks like, the first time it is seen.
     *
     * @param string $v
     * @return string
     */
    protected static function guess_type(string $v): string {
        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v)) {
            return 'email';
        }
        if (in_array(strtoupper($v), ['TRUE', 'FALSE', 'VERDADERO', 'FALSO'], true)) {
            return 'bool';
        }
        if (preg_match('/^[a-z]{1,2}:\d{6,}$/', $v)) {
            return 'id';
        }
        if (self::date($v) !== null) {
            return 'date';
        }
        $digits = preg_replace('/\D/', '', $v);
        if (preg_match('/^(p:)?[+\d\s().\/-]+$/', $v) && strlen($digits) >= 9 && strlen($digits) <= 15) {
            return 'phone';
        }
        if (is_numeric($v)) {
            return 'number';
        }
        if (
            grades::age($v) !== null || preg_match('/\b(infantil|primaria|eso|bachiller|bachillerato|guarderia|' .
            'nursery|preschool|secundaria|anos|meses)\b/', grades::plain($v))
        ) {
            return 'grade';
        }
        return 'text';
    }

    /**
     * A date or a date and time, in the forms files use; a timestamp, or null.
     *
     * @param string $v
     * @return int|null
     */
    public static function date(string $v): ?int {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})(?:\s+(\d{1,2}):(\d{2}))?/', $v, $m)) {
            // Day first, as in Spanish and Portuguese, unless the day cannot be the month.
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($mo > 12 && $d <= 12) {
                [$d, $mo] = [$mo, $d];
            }
            $y += $y < 100 ? 2000 : 0;
        } else {
            return null;
        }
        if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) {
            return null;
        }
        return make_timestamp($y, $mo, $d, 12);
    }

    /**
     * The role of a header, by its name only.
     *
     * @param string $header
     * @return string|null
     */
    public static function header_role(string $header): ?string {
        $h = self::plain($header);
        if ($h === '') {
            return null;
        }
        if (isset(self::EXACT[$h])) {
            return self::EXACT[$h];
        }
        foreach (self::HEADERS as $role => $words) {
            if (preg_match('/' . implode('|', $words) . '/', $h)) {
                return $role;
            }
        }
        return null;
    }

    /**
     * The row of the header: the one with most names of columns that fit what is below them, among the first ones.
     *
     * @param array $rows
     * @return int|null
     */
    protected static function header_row(array $rows): ?int {
        $best = null;
        $bestscore = 0;
        foreach (array_slice($rows, 0, 20, true) as $i => $row) {
            $matches = 0;
            $data = 0;
            $score = 0;
            foreach ($row as $j => $cell) {
                $type = self::type($cell);
                if ($type === 'empty') {
                    continue;
                }
                $role = in_array($type, ['text', 'grade'], true) ? self::header_role($cell) : null;
                if ($role === null) {
                    $data += in_array($type, ['email', 'phone', 'date', 'id', 'number', 'bool'], true) ? 1 : 0;
                    continue;
                }
                $matches++;
                // Whether the column below holds what the name says.
                $want = ['date' => 'date', 'email' => 'email', 'phone' => 'phone', 'birthdate' => 'date'][$role] ?? null;
                if ($want) {
                    $below = array_map(fn($r) => self::type($r[$j] ?? ''), array_slice($rows, $i + 1, 30));
                    $below = array_filter($below, fn($t) => $t !== 'empty');
                    $score += !$below ? -1 : (count(array_keys($below, $want)) * 2 > count($below) ? 2 : 0);
                }
            }
            $score += 2 * $matches - $data;
            if ($matches >= 2 && $matches > $data && $score > $bestscore) {
                [$best, $bestscore] = [$i, $score];
            }
        }
        return $best;
    }

    /**
     * Makes sense of a sheet.
     *
     * @param array $sheet name, rows.
     * @param string $filename
     * @param string|null $kind A kind chosen by the centre, or null to guess it.
     * @return array name, kind, columns (title, role, fate), rows (the data), schoolyear, label, and what it is about.
     */
    public static function sheet(array $sheet, string $filename, ?string $kind = null): array {
        $rows = $sheet['rows'];
        $h = self::header_row($rows);
        $header = $h === null ? [] : $rows[$h];
        $body = [];
        foreach ($rows as $i => $row) {
            if ($i !== $h) {
                $body[] = $row;
            }
        }
        $width = max(array_merge([count($header)], array_map('count', $body ?: [[]])));
        $columns = [];
        for ($j = 0; $j < $width; $j++) {
            $values = array_values(array_filter(
                array_map(fn($r) => trim((string) ($r[$j] ?? '')), $body),
                fn($v) => $v !== ''
            ));
            $title = trim((string) ($header[$j] ?? ''));
            $columns[$j] = ['title' => $title, 'role' => self::column_role($title, $values), 'values' => count($values)];
        }
        // A column of first names whose header says something else («elia» for «Nombre»): next to the surnames, short.
        $roles = array_column($columns, 'role');
        if (in_array('surname', $roles, true) && !array_intersect(['firstname', 'fullname', 'childname'], $roles)) {
            foreach ($columns as $j => $col) {
                if ($col['role'] === 'surname') {
                    foreach ([$j - 1, $j + 1] as $k) {
                        if (isset($columns[$k]) && $columns[$k]['role'] === null && $columns[$k]['values']) {
                            $values = array_filter(array_map(fn($r) => trim((string) ($r[$k] ?? '')), $body));
                            $text = array_filter($values, fn($v) => self::type($v) === 'text');
                            $words = array_map(fn($v) => count(preg_split('/\s+/', $v)), $text);
                            if (count($text) >= 0.8 * count($values) && array_sum($words) / count($words) <= 2) {
                                $columns[$k]['role'] = 'firstname';
                                break 2;
                            }
                        }
                    }
                }
            }
            $roles = array_column($columns, 'role');
        }
        // A name alone that holds whole names is a whole name.
        if (in_array('firstname', $roles, true) && !in_array('surname', $roles, true)) {
            foreach ($columns as $j => $col) {
                if ($col['role'] === 'firstname') {
                    $words = array_map(fn($r) => count(preg_split('/\s+/', trim((string) ($r[$j] ?? '')))), $body);
                    $columns[$j]['role'] = $words && array_sum($words) / count($words) >= 2.5 ? 'fullname' : 'firstname';
                }
            }
        }
        $kind = $kind ?? self::kind($columns, $sheet['name'], $filename);
        $columns = self::emails($columns, $body, $kind);
        $data = [];
        foreach ($body as $row) {
            if (self::is_data($row, $columns, $kind)) {
                $data[] = $row;
            }
        }
        foreach ($columns as $j => $col) {
            $columns[$j]['fate'] = self::fate($col['role'], $kind);
        }
        return ['name' => $sheet['name'], 'kind' => $kind, 'columns' => $columns, 'rows' => $data,
            'schoolyear' => self::schoolyear($sheet['name']) ?? self::schoolyear($filename),
            'label' => self::label($columns, $data, $filename, $sheet['name']),
            'title' => self::plain($filename . ' ' . $sheet['name'])];
    }

    /**
     * The emails that are not of a family. In a list of students, an email is of the family when most are of the
     * services people use; next to the school a student comes from, it is that school's; one each and of one domain,
     * the account of the student at the centre, which identifies the student; anything else is left out. In a list of
     * contacts, an email repeated by many is not of a family either.
     *
     * @param array $columns
     * @param array $body
     * @param string|null $kind
     * @return array
     */
    protected static function emails(array $columns, array $body, ?string $kind): array {
        foreach ($columns as $j => $col) {
            if ($col['role'] !== 'email') {
                continue;
            }
            $values = array_values(array_filter(array_map(
                fn($r) => \core_text::strtolower(trim((string) ($r[$j] ?? ''))),
                $body
            ), fn($v) => strpos($v, '@') !== false));
            if (!$values) {
                continue;
            }
            $domains = array_count_values(array_map(fn($v) => substr(strrchr($v, '@'), 1), $values));
            arsort($domains);
            $webmail = 0;
            foreach ($domains as $domain => $n) {
                $webmail += in_array(strtok((string) $domain, '.'), self::WEBMAIL, true) ? $n : 0;
            }
            $distinct = count(array_unique($values)) / count($values);
            if (in_array($kind, ['joins', 'leaves', 'roll'], true) && $webmail < 0.5 * count($values)) {
                $nearschool = false;
                foreach ($columns as $k => $other) {
                    $nearschool = $nearschool || (abs($k - $j) <= 2 && in_array($other['role'], ['otherschool', 'ids'], true));
                }
                $account = !$nearschool && $distinct >= 0.95 && reset($domains) >= 0.8 * count($values);
                $columns[$j]['role'] = $account ? 'studentid' : 'institutional';
            } else if ($distinct < 0.5 && count($values) > 4) {
                $columns[$j]['role'] = 'institutional';
            }
        }
        return $columns;
    }

    /**
     * The role of a column, by its name and checked against what it holds; by what it holds when the name says nothing.
     *
     * @param string $title
     * @param string[] $values
     * @return string|null
     */
    protected static function column_role(string $title, array $values): ?string {
        $types = array_count_values(array_map([self::class, 'type'], array_slice($values, 0, 400)));
        $n = max(1, array_sum($types));
        $share = fn(string ...$t) => array_sum(array_intersect_key($types, array_flip($t))) / $n;
        $role = self::header_role($title);
        $checks = [
            'email' => fn() => $share('email') >= 0.5,
            'phone' => fn() => $share('phone', 'number') >= 0.5 && $share('bool') < 0.5,
            'date' => fn() => $share('date') >= 0.5,
            'datein' => fn() => $share('date') >= 0.5,
            'dateout' => fn() => $share('date') >= 0.5,
            'refdate' => fn() => $share('date') >= 0.5,
            'enrolleddate' => fn() => $share('date', 'empty') >= 0.5,
            'birthdate' => fn() => $share('date') >= 0.5,
            'grade' => fn() => $share('grade') >= 0.5,
            'studentid' => fn() => $share('bool') < 0.5 && count(array_unique($values)) >= 0.9 * count($values),
            'incentre' => fn() => $share('bool') >= 0.5,
            'leavebool' => fn() => $share('bool') >= 0.5 || $share('text') >= 0.5,
            'organic' => fn() => $share('bool') >= 0.5 || $share('text') >= 0.5,
            'count' => fn() => $share('number') >= 0.5,
            'people' => fn() => $share('number') >= 0.5,
            'children' => fn() => $share('number') >= 0.5,
            'firstname' => fn() => $share('text') >= 0.5,
            'surname' => fn() => $share('text') >= 0.5,
            'fullname' => fn() => $share('text') >= 0.5,
            'childname' => fn() => $share('text') >= 0.5,
        ];
        if ($role !== null && (!isset($checks[$role]) || $checks[$role]())) {
            return $role;
        }
        if ($role !== null && in_array($role, self::REMOVED, true)) {
            return $role;
        }
        // By what it holds: whatever its name, an email or a phone is never kept.
        if ($share('email') >= 0.5) {
            return 'email';
        }
        if ($share('phone') >= 0.5) {
            return 'phone';
        }
        if ($share('id') >= 0.5) {
            return 'ids';
        }
        if ($role === null && $share('date') >= 0.5 && $title === '') {
            return 'date';
        }
        if ($role === null && $share('grade') >= 0.7) {
            return 'grade';
        }
        return null;
    }

    /**
     * What kind of list a sheet is.
     *
     * @param array $columns
     * @param string $sheetname
     * @param string $filename
     * @return string|null One of KINDS, or null.
     */
    protected static function kind(array $columns, string $sheetname, string $filename): ?string {
        $has = array_flip(array_filter(array_column($columns, 'role')));
        $person = isset($has['firstname']) || isset($has['surname']) || isset($has['fullname']) ||
            isset($has['childname']) || isset($has['studentid']) || isset($has['studenttoken']);
        $identity = isset($has['email']) || isset($has['phone']) || isset($has['householdtoken']);
        if (isset($has['municipality'], $has['year'], $has['people'])) {
            return 'population';
        }
        if (isset($has['householdtoken'], $has['channel'])) {
            return 'contacts';
        }
        if (isset($has['grade'], $has['count']) && !$person && !$identity) {
            return 'totals';
        }
        if (isset($has['date']) && $identity) {
            return 'contacts';
        }
        if (!$person) {
            return null;
        }
        if (isset($has['studenttoken']) || isset($has['datein'])) {
            return 'roll';
        }
        if (isset($has['leavebool'])) {
            return 'leaves';
        }
        // What the name of the sheet says, else the name of the file when it says only one thing.
        foreach ([$sheetname, $filename] as $name) {
            $t = self::plain($name);
            $leaves = preg_match('/\b(bajas?|leav\w*|withdraw\w*|saidas?|salidas?)\b/', $t);
            $joins = preg_match('/\b(nuev\w*|altas?|new|novos?|ingres\w*|admitid\w*|incorpora\w*)\b/', $t);
            if ($leaves xor $joins) {
                return $leaves ? 'leaves' : 'joins';
            }
        }
        return 'roll';
    }

    /**
     * Whether a row is one of the list, and not a title, a header repeated or a row of empty boxes.
     *
     * @param array $row
     * @param array $columns
     * @param string|null $kind
     * @return bool
     */
    protected static function is_data(array $row, array $columns, ?string $kind): bool {
        $value = function (string ...$roles) use ($row, $columns): bool {
            foreach ($columns as $j => $col) {
                if (in_array($col['role'], $roles, true) && trim((string) ($row[$j] ?? '')) !== '') {
                    $type = self::type((string) $row[$j]);
                    $want = ['date' => 'date', 'email' => 'email', 'phone' => 'phone'][$col['role']] ?? null;
                    if (!$want || $type === $want || ($want === 'phone' && $type === 'number')) {
                        return true;
                    }
                }
            }
            return false;
        };
        switch ($kind) {
            case 'contacts':
                return $value('date') || $value('email', 'phone', 'householdtoken');
            case 'joins':
            case 'leaves':
            case 'roll':
                return $value('firstname', 'surname', 'fullname', 'childname', 'studentid', 'studenttoken');
            case 'totals':
                return $value('grade') && $value('count');
            case 'population':
                return $value('municipality') && $value('people');
        }
        return false;
    }

    /**
     * What happens to a column.
     *
     * @param string|null $role
     * @param string|null $kind
     * @return string kept, key, removed or unused.
     */
    public static function fate(?string $role, ?string $kind): string {
        if ($role === null) {
            return 'unused';
        }
        if (in_array($role, ['firstname', 'surname', 'fullname'], true)) {
            // In a list of students it is the student; in a contact, the adult who wrote.
            return $kind === 'contacts' ? 'removed' : 'key';
        }
        if (in_array($role, self::KEYS, true)) {
            return 'key';
        }
        if (in_array($role, self::REMOVED, true)) {
            return 'removed';
        }
        return $kind && in_array($role, self::USED[$kind], true) ? 'kept' : 'unused';
    }

    /**
     * A school year in a text: «2024 2025», «24-25», «25／26» or «2026-27».
     *
     * @param string $text
     * @return string|null «2024-25»
     */
    public static function schoolyear(string $text): ?string {
        if (preg_match_all('/(?<!\d)(?:20)?(\d{2})\s*[-\/／_ ]\s*(?:20)?(\d{2})(?!\d)/u', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if ((int) $m[2] === ((int) $m[1] + 1) % 100) {
                    return '20' . $m[1] . '-' . $m[2];
                }
            }
        }
        return null;
    }

    /**
     * A name for what is imported: the campaign of most rows, else the form, else the file and the sheet.
     *
     * @param array $columns
     * @param array $rows
     * @param string $filename
     * @param string $sheetname
     * @return string
     */
    protected static function label(array $columns, array $rows, string $filename, string $sheetname): string {
        foreach (['campaign', 'form'] as $role) {
            foreach ($columns as $j => $col) {
                if ($col['role'] === $role && $rows) {
                    $counts = array_count_values(array_filter(array_map(fn($r) => trim((string) ($r[$j] ?? '')), $rows)));
                    arsort($counts);
                    // A name, not the number a tool gives a campaign.
                    if ($counts && reset($counts) >= 0.6 * count($rows) && !preg_match('/^[\d\s._-]+$/', (string) key($counts))) {
                        return \core_text::substr((string) key($counts), 0, 100);
                    }
                }
            }
        }
        $name = pathinfo($filename, PATHINFO_FILENAME);
        return \core_text::substr(preg_match('/^(hoja|sheet|folha)\s*\d+$|^respuestas de formulario/i', $sheetname) ?
            $name : $name . ' · ' . $sheetname, 0, 100);
    }
}
