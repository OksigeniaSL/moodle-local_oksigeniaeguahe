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

namespace local_oksigeniaeguahe;

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\listhomes;
use local_oksigeniaeguahe\local\listnews;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\pseudonym;

/**
 * Which rows of the lists are one family and which are one student: what is shared by mistake joins nobody, what one night
 * joined the next one takes apart, and a list cut short never takes the place of the one there was. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\listhomes
 */
#[\PHPUnit\Framework\Attributes\CoversClass(listhomes::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_oksigeniaeguahe\local\liststudents::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(imports::class)]
final class lists_test extends \advanced_testcase {
    /**
     * A centre.
     *
     * @return int
     */
    protected function centre(): int {
        $this->resetAfterTest();
        return centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'email' => 'secretaria@escuela-ejemplo.com', 'phone' => '+34 922 111 333']);
    }

    /**
     * A list written as a program would.
     *
     * @param array $rows Each: code, «surnames, name», course, phone, email, other phone.
     * @param string $name
     * @return string Its path.
     */
    protected function csv(array $rows, string $name = 'lista'): string {
        $path = make_request_directory() . "/$name.csv";
        $f = fopen($path, 'w');
        fputcsv($f, ['Expediente n.º', 'Apellidos y nombre completo', 'Curso', 'Teléfono móvil', 'Correo electrónico', 'Teléfono 2'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($f, $row + [3 => '', 4 => '', 5 => ''], ',', '"', '');
        }
        fclose($f);
        return $path;
    }

    /**
     * Some rows of families with nothing in common.
     *
     * @param int $n
     * @param int $from
     * @return array
     */
    protected function rows(int $n, int $from = 1): array {
        $out = [];
        for ($i = $from; $i < $from + $n; $i++) {
            $out[] = [sprintf('A%03d', $i), "Apellido$i Segundo$i, Alumno$i", '4º Primaria', '6300000' . sprintf('%02d', $i),
                "fam$i@gmail.com", ''];
        }
        return $out;
    }

    /**
     * Brings the list of 2026-27, in the place of the one before.
     *
     * @param int $cid
     * @param array $rows
     * @return array
     */
    protected function night(int $cid, array $rows): array {
        return imports::replace($cid, '2026-27', ['Lista' => ['kind' => 'roll', 'path' => $this->csv($rows)]], 2)['Lista'];
    }

    /**
     * The family of each student, by its code.
     *
     * @param int $cid
     * @return array
     */
    protected function homes(int $cid): array {
        global $DB;
        return $DB->get_fieldset_select(metrics::STUDENTS, 'household', 'centreid = ? AND schoolyear = ? ORDER BY id', [$cid, '2026-27']);
    }

    /**
     * A contact that students of different surnames share joins nobody by itself; with a surname in common, or another
     * contact, it does. The contacts of the centre, a number to fill the box and what is in many files join nobody.
     */
    public function test_shared_contacts(): void {
        $cid = $this->centre();
        $rows = $this->rows(16);
        // Two families with the phone of a grandmother.
        $rows[0][5] = $rows[1][5] = '699000001';
        // Two brothers with the same mother's phone and one surname in common.
        $rows[2][1] = 'Ruiz Sosa, Ana';
        $rows[3][1] = 'Vega Sosa, Leo';
        $rows[2][5] = $rows[3][5] = '699000002';
        // Two half brothers, no surname in common, with the mother's phone and email.
        $rows[4][1] = 'Ortiz Gil, Eva';
        $rows[5][1] = 'Mora Paz, Pol';
        $rows[4][4] = $rows[5][4] = 'madre@gmail.com';
        $rows[4][5] = $rows[5][5] = '699000003';
        // The office of the centre, and a number to fill the box.
        $rows[6][4] = $rows[7][4] = 'secretaria@escuela-ejemplo.com';
        $rows[8][5] = $rows[9][5] = '600000000';
        // What is in five files of different families.
        foreach (range(10, 14) as $i) {
            $rows[$i][5] = '699000004';
        }
        $this->night($cid, $rows);
        $homes = $this->homes($cid);
        $this->assertNotSame($homes[0], $homes[1], 'A grandmother.');
        $this->assertSame($homes[2], $homes[3], 'Brothers.');
        $this->assertSame($homes[4], $homes[5], 'Two contacts in common.');
        $this->assertCount(14, array_unique($homes));
        $this->assertNotContains(null, $homes);
        // The plain phone of the grandmother finds nobody.
        $this->assertSame([], (new pseudonym($cid))->find('household', [pseudonym::phone('699000001')]));
        // Said of the import, with numbers, never the contact.
        $imports = imports::for_centre($cid);
        $this->assertSame([1, 3], [imports::info(reset($imports))['never'], imports::info(reset($imports))['doubt']]);
        $dump = json_encode($imports);
        $this->assertStringNotContainsString('699000001', $dump);
    }

    /**
     * What one night joined, the next one takes apart: corrected in the program, the families are two again, and each keeps
     * its key from then on.
     */
    public function test_taken_apart(): void {
        $cid = $this->centre();
        $rows = $this->rows(6);
        $bad = $rows;
        $bad[0][1] = 'Ruiz Sosa, Ana';
        $bad[1][1] = 'Ruiz Mar, Leo';
        $bad[0][5] = $bad[1][5] = '699000001';
        $this->night($cid, $bad);
        $this->assertCount(5, array_unique($this->homes($cid)), 'A surname and a phone in common: one family.');
        $good = $bad;
        $good[0][5] = $good[1][5] = '';
        $this->night($cid, $good);
        $homes = $this->homes($cid);
        $this->assertCount(6, array_unique($homes));
        $this->night($cid, $good);
        $this->assertSame($homes, $this->homes($cid), 'Nothing changes, nothing moves.');
    }

    /**
     * Rows that came in before they kept their marks, made one by a shared phone: when the list of the year comes, its families
     * are told apart, and the rows of the years before of its students go with each.
     */
    public function test_old_rows(): void {
        global $DB;
        $cid = $this->centre();
        $rows = $this->rows(3);
        $keys = new pseudonym($cid);
        $import = (int) $DB->insert_record(imports::T, (object) ['centreid' => $cid, 'kind' => 'roll', 'label' => 'Antes',
            'rowcount' => 2, 'userid' => 2, 'timecreated' => time()]);
        foreach ([0, 1] as $i) {
            $household = $keys->token('household', [pseudonym::email($rows[$i][4]), pseudonym::phone('699000009')]);
            $DB->insert_record(metrics::STUDENTS, (object) ['centreid' => $cid, 'importid' => $import, 'household' => $household,
                'student' => $keys->token('student', [pseudonym::code($rows[$i][0])]), 'schoolyear' => '2025-26',
                'grade' => '3.º de Primaria', 'timein' => 0, 'timeout' => 0, 'movement' => 'roll', 'birthyear' => 0]);
        }
        $before = $DB->get_fieldset_select(metrics::STUDENTS, 'household', 'importid = ?', [$import]);
        $this->assertCount(1, array_unique($before));
        $rows[0][5] = $rows[1][5] = '699000009';
        $this->night($cid, $rows);
        $homes = $this->homes($cid);
        $this->assertCount(3, array_unique($homes));
        $now = $DB->get_fieldset_select(metrics::STUDENTS, 'household', 'importid = ? ORDER BY id', [$import]);
        $this->assertSame([$homes[0], $homes[1]], $now);
    }

    /**
     * Two students of different courses of the same year are two: with the same name, with a code two brothers share, or with a
     * key that a form naming several children had made one.
     */
    public function test_students_apart(): void {
        global $DB;
        $cid = $this->centre();
        // A campaign with a column for each child: before, they were made one student.
        $keys = new pseudonym($cid);
        $keys->token('student', [pseudonym::name('Ana Ruiz Sosa'), pseudonym::name('Leo Ruiz Sosa')]);
        $rows = $this->rows(22);
        $rows[0][1] = 'Ruiz Sosa, Ana';
        $rows[1][1] = 'Ruiz Sosa, Leo';
        $rows[1][2] = '2º ESO';
        // Namesakes in two courses, without a code.
        $rows[2] = ['', 'García Pérez, Lucía', '1º Primaria', '630000090', 'una@gmail.com', ''];
        $rows[3] = ['', 'García Pérez, Lucía', '5º Primaria', '630000091', 'otra@gmail.com', ''];
        // Brothers whose names have the same words.
        $rows[4] = ['', 'Díaz Mora, José Antonio', '1º Primaria', '630000092', 'dos@gmail.com', ''];
        $rows[5] = ['', 'Díaz Mora, Antonio José', '3º Primaria', '630000092', 'dos@gmail.com', ''];
        // The code of the family in both.
        $rows[6] = ['F9', 'Gil Paz, Eva', '1º Primaria', '630000093', 'tres@gmail.com', ''];
        $rows[7] = ['F9', 'Gil Paz, Pol', '3º Primaria', '630000093', 'tres@gmail.com', ''];
        $this->night($cid, $rows);
        $students = fn() => $DB->get_fieldset_select(metrics::STUDENTS, 'student', 'centreid = ? ORDER BY id', [$cid]);
        $one = $students();
        $this->assertCount(22, array_unique($one));
        $this->night($cid, $rows);
        $this->assertSame($one, $students(), 'Each keeps its key from one night to the next.');
        // And a list of who joins finds each of them.
        imports::replace($cid, '2026-27', ['Altas' => ['kind' => 'joins', 'path' => $this->csv([$rows[3], $rows[5]], 'altas')]], 2);
        $joins = $DB->get_fieldset_select(metrics::STUDENTS, 'student', "centreid = ? AND movement = 'join' ORDER BY id", [$cid]);
        $this->assertSame([$one[3], $one[5]], $joins);
    }

    /**
     * A form that asks for each child in its own column names the first one as the student of the contact, never two as one.
     */
    public function test_several_children_in_a_row(): void {
        global $DB;
        $cid = $this->centre();
        $csv = "Marca temporal,Correo,Nombre y apellidos del alumno 1,Nombre y apellidos del alumno 2\n" .
            "2026-01-10 10:00:00,ana@gmail.com,Ana Ruiz Sosa,Leo Ruiz Sosa\n";
        imports::import($cid, 'contacts', 'Formulario', 'f.csv', $csv, 2);
        $keys = new pseudonym($cid);
        $ana = $keys->find('student', [pseudonym::name('Ana Ruiz Sosa')]);
        $this->assertSame($ana, $DB->get_fieldset_select('local_oksigeniaeguahe_pastcontact', 'student', 'centreid = ?', [$cid]));
        $this->assertSame([], $keys->find('student', [pseudonym::name('Leo Ruiz Sosa')]));
    }

    /**
     * Only the list of its school year goes, though the one of another year has the same name; and a list cut short does not
     * take the place of the one there was, until a person says it is right.
     */
    public function test_replace_keeps(): void {
        global $DB;
        $cid = $this->centre();
        $rows = $this->rows(40);
        $n = fn(string $year) => $DB->count_records(metrics::STUDENTS, ['centreid' => $cid, 'schoolyear' => $year]);
        $this->assertSame([40, 0], $this->night($cid, $rows));
        $this->assertSame([12, 0, 40], $this->night($cid, array_slice($rows, 0, 12)), 'Cut short.');
        $this->assertSame(40, $n('2026-27'));
        $this->assertSame(['Lista'], array_keys(listnews::held($cid)));
        $notes = listnews::notes(centres::get($cid), true);
        $this->assertStringContainsString('12', $notes[0]['text']);
        // Told to the team once.
        $sink = $this->redirectMessages();
        $user = $this->getDataGenerator()->create_user();
        centres::add_member($cid, (int) $user->id);
        $DB->set_field('local_oksigeniaeguahe_member', 'admin', 1, ['centreid' => $cid, 'userid' => $user->id]);
        $this->assertSame(1, listnews::tell(centres::get($cid), time()));
        $this->assertSame(0, listnews::tell(centres::get($cid), time()));
        $this->assertSame('listheld', $sink->get_messages()[0]->eventtype);
        // The next night comes whole.
        $this->assertSame([39, 0], $this->night($cid, array_slice($rows, 0, 39)));
        $this->assertSame([], listnews::held($cid));
        // Shorter for good: a person says so, and it goes in.
        $this->assertSame([20, 0, 39], $this->night($cid, array_slice($rows, 0, 20)));
        $this->assertTrue(listnews::accept($cid, 'Lista'));
        $this->assertSame([20, 0], $this->night($cid, array_slice($rows, 0, 20)));
        $this->assertSame([], listnews::held($cid));
        // The list of the next year, with the same name.
        $next = array_map(fn($row) => array_replace($row, [2 => '5º Primaria']), $rows);
        imports::replace($cid, '2027-28', ['Lista' => ['kind' => 'roll', 'path' => $this->csv($next)]], 2);
        $this->assertSame([20, 40], [$n('2026-27'), $n('2027-28')]);
    }

    /**
     * The day a student came to the centre, years ago, is not a day of this school year; the one it joined this year is.
     */
    public function test_day_of_entry(): void {
        global $DB;
        $cid = $this->centre();
        $csv = "Nombre,Apellidos,Nivel,Correo electrónico,Fecha de alta\nAna,Ruiz Sosa,3.º de Primaria,a@gmail.com,07/09/2023\n" .
            "Leo,Vega Paz,3.º de Primaria,b@gmail.com,15/10/2026\nEva,Mora Gil,3.º de Primaria,c@gmail.com,15/07/2026\n";
        imports::import($cid, 'roll', '2026-27', 'l.csv', $csv, 2);
        $days = array_map(fn($t) => userdate((int) $t, '%Y-%m-%d', 99, false), $DB->get_fieldset_select(
            metrics::STUDENTS,
            'timein',
            'centreid = ? ORDER BY id',
            [$cid]
        ));
        $this->assertSame(['2026-09-01', '2026-10-15', '2026-07-15'], $days);
    }

    /**
     * The same file of contacts brought again takes the place of the first time; the centre is told, and can keep both. The
     * contacts several students share are said before the list comes in.
     */
    public function test_twice_and_said(): void {
        global $DB;
        $cid = $this->centre();
        $csv = "Marca temporal,Correo,Teléfono\n2026-01-10 10:00:00,ana@gmail.com,\n2026-01-11 10:00:00,eva@gmail.com,\n" .
            "2026-01-12 10:00:00,leo@gmail.com,\n";
        $path = make_request_directory() . '/anuncios.csv';
        file_put_contents($path, $csv);
        $this->assertNull(imports::preview($path, 'anuncios.csv', [0 => 'contacts'], $cid)[0]['twice']);
        $choice = [0 => ['kind' => 'contacts', 'label' => 'Anuncios']];
        imports::import_file($cid, $path, 'anuncios.csv', $choice, 2);
        [$import, $same] = imports::preview($path, 'anuncios.csv', [0 => 'contacts'], $cid)[0]['twice'];
        $this->assertTrue($same);
        $DB->set_field(imports::T, 'campaignstart', 1767225600, ['id' => $import->id]);
        imports::import_file($cid, $path, 'anuncios.csv', $choice, 2);
        $this->assertSame(3, $DB->count_records('local_oksigeniaeguahe_pastcontact', ['centreid' => $cid]));
        $imports = imports::for_centre($cid);
        $this->assertCount(1, $imports);
        $this->assertSame(1767225600, (int) reset($imports)->campaignstart, 'What the centre wrote of it stays.');
        imports::import_file($cid, $path, 'anuncios.csv', [0 => $choice[0] + ['both' => true]], 2);
        $this->assertSame(6, $DB->count_records('local_oksigeniaeguahe_pastcontact', ['centreid' => $cid]));

        $rows = $this->rows(12);
        $rows[0][5] = $rows[1][5] = '699000001';
        foreach (range(2, 7) as $i) {
            $rows[$i][5] = '699000002';
        }
        $said = imports::preview($this->csv($rows), 'lista.csv', [0 => 'roll'], $cid)[0]['shared'];
        $this->assertSame([['699000002', 6, true], ['699000001', 2, false]], array_map(fn($s) => [$s['value'], $s['students'],
            $s['never']], $said));
    }
}
