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
use local_oksigeniaeguahe\local\forecast;
use local_oksigeniaeguahe\local\grades;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\pseudonym;
use local_oksigeniaeguahe\local\recognise;

/**
 * Files as they come: an export of adverts with its header out of place, the answers of a form, the lists of a
 * secretary's office with titles above the header and boxes to tick. All made-up example data, with the shape of the
 * real ones. Nothing is refused, nothing personal is kept, and the same family is found across files.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\imports
 * @covers     \local_oksigeniaeguahe\local\recognise
 * @covers     \local_oksigeniaeguahe\local\pseudonym
 * @covers     \local_oksigeniaeguahe\local\grades
 */
#[\PHPUnit\Framework\Attributes\CoversClass(imports::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recognise::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pseudonym::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(grades::class)]
final class import_test extends \advanced_testcase {
    /** @var string[] What must never be stored: the names, emails, phones and notes of the example files. */
    const PERSONAL = ['Ana Ruiz', 'ana.ruiz', 'Pedro Gil', 'pedro.gil', '600000001', '600000002', '600000003', 'Lucía',
        'Martín Pérez', 'Hugo', 'Sosa Díaz', 'Alergia', 'secreto', 'colegio-pinos', 'Calle Mayor', 'B12X'];

    /**
     * A spreadsheet with these sheets.
     *
     * @param array $sheets Name => rows.
     * @return string The path.
     */
    protected function xlsx(array $sheets): string {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($sheets as $name => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($rows, null, 'A1', true);
        }
        $path = make_request_directory() . '/file.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        return $path;
    }

    /**
     * The adverts: the export of a lead form, with a lead above its header and the header copied to the right.
     *
     * @return array
     */
    protected function adverts(): array {
        $header = ['created_time', 'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name', 'form_id',
            'form_name', 'is_organic', 'platform', 'selecciona_una_opción', 'Nombre y apellidos', 'E-mail', 'Teléfono',
            'Llamada'];
        $lead = fn($id, $date, $platform, $grade, $name, $email, $phone) => ['l:' . $id, $date, 'ag:1201', 'Vídeo',
            'as:1202', 'Captación', 'c:1203', 'Captación 26/27', 'f:1204', 'Formulario captación', 'false', $platform, $grade,
            $name, $email, $phone, true];
        return [
            array_merge($lead(
                9001,
                '2025-12-10T10:00:00+01:00',
                'ig',
                'infantil_3_años',
                'Ana Ruiz',
                'ana.ruiz@example.com',
                'p:+34600000001'
            ), ['', '', 'id'], $header),
            array_merge([''], $header),
            $lead(
                9002,
                '2026-01-15T18:30:00+01:00',
                'fb',
                'primaria',
                'Pedro Gil',
                'pedro.gil@example.com',
                'p:+34600000002'
            ),
            $lead(9003, '2026-02-01T09:00:00+01:00', 'ig', 'guardería_0-3_años', 'Ana Ruiz', 'ana.ruiz@example.com', ''),
        ];
    }

    /**
     * Everything a file brings, as it comes, is found, and nothing personal is kept.
     */
    public function test_files_as_they_come(): void {
        global $DB;
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'municipalities' => "28065;Getafe"]);

        // The open day of a past year: a form with the child's name and birth date, and a real date cell.
        $form = [['Marca temporal', 'Dirección de correo electrónico', 'Nombre completo', 'Relación con el alumno',
            'Nombre y apellidos del futuro alumno', 'Fecha de nacimiento', 'Teléfono de contacto',
            'Momento elegido para la visita', 'Observaciones', '¿Tiene alguna alergia?']];
        $form[] = ['2024-02-20 10:00:00', 'pedro.gil@example.com', 'Pedro Gil', 'Padre', 'Lucía Martín Pérez',
            '2021-03-04', 600000002, 'Viernes 1 de marzo', 'Llamar por la tarde', 'Alergia al huevo'];
        $form[] = ['2024-02-21 11:00:00', 'otra.familia@example.com', 'Otra Familia', 'Madre', 'Hugo Sosa Díaz',
            '2020-06-07', '', 'Sábado 2 de marzo', '', ''];
        $path = $this->xlsx(['Respuestas de formulario 1' => $form]);
        $sheets = imports::preview($path, 'Puertas abiertas 2024.xlsx');
        $this->assertSame('contacts', $sheets[0]['kind']);
        $this->assertTrue($sheets[0]['health']);
        $this->assertContains('Nombre completo', $sheets[0]['removed']);
        $this->assertContains('Teléfono de contacto', $sheets[0]['keys']);
        imports::import_file($centreid, $path, 'Puertas abiertas 2024.xlsx', [0 => ['kind' => 'contacts']], 2);

        // The secretary's office: a title above the header, the school they came from with its email, and boxes.
        $joins = [['', '', '', '', 'Centro del que procede', '', '', ''],
            ['Nombre', 'Apellidos', 'Cial', 'Nivel', 'Nombre del Centro (Provincia si es de fuera)', 'Código', 'email',
                'Exp. Recibido'],
            ['Lucía', 'Martín Pérez', 'B12X0001', 'Infantil 1º', 'CEIP Los Pinos', '28000001', 'info@colegio-pinos.es', true],
            ['Hugo', 'Sosa Díaz', 'B12X0002', 'Primaria 2º', 'CEIP Los Pinos', '28000001', 'info@colegio-pinos.es', false],
            ['Marta', 'León Vidal', '', 'Primaria 2º', 'Colegio del Sur', '28000002', 'secretaria@colegiosur.es', true],
            ['', '', '', '', '', '', '', false]];
        $leaves = [['#', 'Nombre', 'Apellidos', 'Nivel', 'Centro', 'Península', 'Extranjero', 'Baja en el sistema'],
            [1, 'Iván', 'Ortega Ramos', 'ESO 3º', '', true, false, true],
            [2, 'Sara', 'Cano Gil', 'Primaria 4º', 'Colegio del Norte', false, false, true]];
        $roll = [['Grupo', 'Nombre', 'Apellidos', 'Móvil', 'Tutor A', 'Móvil A', 'Contraseña'],
            ['INF 1º A', 'Lucía', 'Martín Pérez', '', 'Pedro Gil', 600000002, 'secreto'],
            ['INF 1º A', 'Leo', 'Vera Ruiz', '', 'Ana Ruiz', 600000001, 'secreto']];
        $path = $this->xlsx(['Altas 24 25' => $joins, 'Bajas 24 25' => $leaves, 'A' => $roll,
            'Listado de Centros' => [['Denominacion', 'Codigo', 'Municipio', 'Correo Electronico'],
                ['CEIP Los Pinos', '28000001', 'Getafe', 'info@colegio-pinos.es']]]);
        $sheets = imports::preview($path, 'Altas y Bajas 2024 2025.xlsx');
        $this->assertSame(['joins', 'leaves', 'roll', null], array_column($sheets, 'kind'));
        $this->assertSame([3, 2, 2, 0], array_column($sheets, 'rows'));
        $this->assertSame('2024-25', $sheets[0]['schoolyear']);
        $this->assertContains('email', $sheets[0]['removed']);
        $this->assertContains('Contraseña', $sheets[2]['removed']);
        $choices = [0 => ['kind' => 'joins'], 1 => ['kind' => 'leaves'], 2 => ['kind' => 'roll', 'schoolyear' => '2024-25']];
        [$made, $rows] = imports::import_file($centreid, $path, 'Altas y Bajas 2024 2025.xlsx', $choices, 2);
        $this->assertSame([3, 7], [$made, $rows]);

        // The adverts of the next campaign.
        $path = $this->xlsx(['Hoja 1' => $this->adverts()]);
        $sheets = imports::preview($path, 'Campaña redes admisiones.xlsx');
        $this->assertSame('contacts', $sheets[0]['kind']);
        $this->assertSame(3, $sheets[0]['rows']);
        $this->assertSame('Captación 26/27', $sheets[0]['label']);
        imports::import_file($centreid, $path, 'Campaña redes admisiones.xlsx', [0 => ['kind' => 'contacts']], 2);

        // Nothing personal anywhere: not in the rows, not in the keys.
        foreach (
            ['local_oksigeniaeguahe_pastcontact', 'local_oksigeniaeguahe_student', 'local_oksigeniaeguahe_import',
                'local_oksigeniaeguahe_importkey'] as $table
        ) {
            $dump = json_encode(array_values($DB->get_records($table)), JSON_UNESCAPED_UNICODE);
            foreach (self::PERSONAL as $value) {
                $this->assertStringNotContainsString($value, $dump, "$value in $table");
            }
        }

        // The courses, as the centre names them; the reason and the schools.
        $students = $DB->get_records('local_oksigeniaeguahe_student', null, 'id ASC');
        $this->assertSame(['Infantil 3 años', '2.º de Primaria', '2.º de Primaria', '3.º de ESO', '4.º de Primaria',
            'Infantil 3 años', 'Infantil 3 años'], array_values(array_column($students, 'grade')));
        $m = metrics::movements($centreid, '2024-25');
        $this->assertTrue($m['hasroll']);
        $this->assertSame(
            ['Península' => 1, get_string('import_reason_otherschool', 'local_oksigeniaeguahe') => 1],
            array_column($m['reasons'], 'count', 'reason')
        );
        $this->assertSame(['CEIP Los Pinos' => 2, 'Colegio del Sur' => 1], array_column($m['from'], 'count', 'school'));
        // Lucía is on two lists and counts once; the leaves are in no list of the year and count as there at the start.
        $this->assertSame(['start' => 6, 'new' => 3, 'joins' => 0, 'leaves' => 2, 'now' => 4, 'net' => 1], $m['totals']);

        // The open day: the family of Lucía enrolled her (her name); the other child too (his name).
        $openday = $DB->get_record(imports::T, ['label' => 'Puertas abiertas 2024']);
        $t = metrics::campaign($openday)['totals'];
        $this->assertSame([2, 0, 2], [$t['households'], $t['visited'], $t['enrolled']]);

        // The adverts: Ana's two leads are one family, found again by her phone on the list of a year before them, so
        // she is not new; Pedro's family was already enrolled before too.
        $adverts = $DB->get_record(imports::T, ['label' => 'Captación 26/27']);
        $t = metrics::campaign($adverts)['totals'];
        $this->assertSame([2, 3, 0], [$t['households'], $t['contacts'], $t['enrolled']]);
        $platforms = array_column(metrics::campaign($adverts)['platform'], 'households', 'key');
        ksort($platforms);
        $this->assertSame(['fb' => 1, 'ig' => 1], $platforms);

        // Removing what was imported removes its keys too.
        foreach (imports::for_centre($centreid) as $import) {
            imports::delete($centreid, (int) $import->id);
        }
        $this->assertSame(0, $DB->count_records('local_oksigeniaeguahe_importkey'));
    }

    /**
     * The same family by its email in one file and its phone in another, made one by a row that has both.
     */
    public function test_keys_merge(): void {
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo']);
        $keys = new pseudonym($centreid);
        $a = $keys->token('household', [pseudonym::email('Familia@Example.com ')]);
        $b = $keys->token('household', [pseudonym::phone('+34 600 000 009')]);
        $this->assertNotSame($a, $b);
        $c = $keys->token('household', [pseudonym::email('familia@example.com'), pseudonym::phone('600000009')]);
        $this->assertSame($a, $c);
        $this->assertSame($a, (new pseudonym($centreid))->token('household', [pseudonym::phone('0034600000009')]));
        $this->assertNull(pseudonym::name('Lucía'));
        $this->assertSame(pseudonym::name('Martín Pérez, Lucía'), pseudonym::name('lucia martin perez'));
    }

    /**
     * Courses named in many ways are one, by the age they start at.
     */
    public function test_grades(): void {
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo']);
        $this->assertSame(['INF 1º', 'A'], grades::split_group('INF 1º A'));
        foreach (
            ['INF 1º' => 3, 'Infantil 3 años' => 3, 'infantil_3_años' => 3, '1º de Primaria' => 6, 'PRI 6º' => 11,
                'Primaria 2º' => 7, '1.º de ESO' => 12, "1º ESO\n6 líneas" => 12, 'BAC 2º' => 17, '4 meses' => 0] as $name => $age
        ) {
            $this->assertSame($age, grades::age($name), $name);
        }
        $this->assertNull(grades::age('infantil_3-5_años'));
        $this->assertSame('2.º de Primaria', grades::label($centreid, 'PRI 2º'));
    }

    /**
     * The school systems come from data, not from the code: each centre reads its courses with the one of its country
     * or the one it chose; the age it wrote wins; without a system, where two say different ages, the age is not known.
     */
    public function test_school_systems(): void {
        global $DB;
        $this->resetAfterTest();
        $us = centres::get(centres::create((object) ['name' => 'Example School', 'lang' => 'en', 'country' => 'US']));
        $this->assertSame('us', grades::system($us));
        $this->assertSame(6, grades::age('Grade 1', $us));
        $this->assertSame(16, grades::age('11th grade', $us));
        $this->assertSame(5, grades::age('Kindergarten', $us));
        $this->assertSame(4, grades::age('Pre-K', $us));
        $br = centres::get(centres::create((object) ['name' => 'Escola de exemplo', 'lang' => 'pt_br', 'country' => 'BR']));
        $this->assertSame(10, grades::age('5º ano', $br));
        $this->assertSame(15, grades::age('1º ano do Ensino Médio', $br));
        $this->assertSame(16, grades::age('2ª série', $br));
        $gb = centres::get(centres::create((object) ['name' => 'Example School', 'lang' => 'en', 'country' => 'GB']));
        $au = centres::get(centres::create((object) ['name' => 'Example School', 'lang' => 'en', 'country' => 'AU']));
        $this->assertSame(5, grades::age('Year 1', $gb));
        $this->assertSame(6, grades::age('Year 1', $au));
        $this->assertNull(grades::age('Year 1'), 'Without a system, two ages: none is guessed.');
        $this->assertSame(1, grades::age('1 año'), 'A year of age, never a course of Portugal.');
        // The centre chooses another system, or none; and the age it writes wins.
        $DB->set_field(centres::T, 'system', 'gb', ['id' => $au->id]);
        $this->assertSame(5, grades::age('Year 1', centres::get((int) $au->id)));
        $DB->insert_record(forecast::AGES, (object) ['centreid' => $us->id, 'grade' => 'Grade 1', 'age' => 7, 'forecast' => 0]);
        grades::reset();
        $this->assertSame(7, grades::age('Grade 1', $us));
        $this->assertContains('kindergarten', grades::words());
        $this->assertSame('2024-25', recognise::schoolyear('Correos alumnos nuevos 2024 2025'));
        $this->assertSame('2025-26', recognise::schoolyear('Correos de alumnos nuevos 25／26'));
        $this->assertNull(recognise::schoolyear('Puertas abiertas 2024'));
    }
}
