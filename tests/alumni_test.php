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

use local_oksigeniaeguahe\local\alumni;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\teamnotes;

/**
 * Who in a family studied at the centre: what the family and the team say, the notes of the sheets, the list of former
 * students kept as keys, and the new students of families of former students in Cifras.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\alumni
 */
#[\PHPUnit\Framework\Attributes\CoversClass(alumni::class)]
final class alumni_test extends \advanced_testcase {
    /**
     * A family with one adult, by name.
     *
     * @param int $centreid
     * @param string $first
     * @param string $last
     * @param string $email
     * @return array [household, adult id]
     */
    protected function family(int $centreid, string $first, string $last, string $email = ''): array {
        $id = households::create($centreid, (object) ['lang' => 'es']);
        $household = households::get($id, $centreid);
        $adult = households::add_adult($household, (object) ['firstname' => $first, 'lastname' => $last,
            'email' => $email !== '' ? $email : null]);
        return [$household, $adult];
    }

    /**
     * The keys of a name: the whole one, and each without one word from three words on; nothing for one word.
     */
    public function test_name_keys(): void {
        $keys = alumni::name_keys('García López, Ana');
        $this->assertSame(['name:ana garcia lopez' => true, 'name:garcia lopez' => false, 'name:ana lopez' => false,
            'name:ana garcia' => false], $keys);
        $this->assertSame(['name:ana garcia' => true], alumni::name_keys('Ana García'));
        $this->assertSame([], alumni::name_keys('Ana'));
        $this->assertSame('2003', alumni::year('Promoción 2003'));
        $this->assertSame('2002-03', alumni::year('2002/2003'));
        $this->assertSame('', alumni::year('la del pabellón'));
    }

    /**
     * What a family and the team say, with its line in the history; a person over the list, never the list over a person.
     */
    public function test_said_by_people(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        [$household, $adult] = $this->family($cid, 'Marta', 'Ruiz Gil');
        $this->assertFalse(alumni::in_family((int) $household->id));
        $this->assertTrue(alumni::set($household, $adult, alumni::YES, 'family'));
        $this->assertFalse(alumni::set($household, $adult, alumni::YES, 'family'), 'Nothing changes twice.');
        $this->assertTrue(alumni::in_family((int) $household->id));
        $this->assertSame([(int) $household->id => true], alumni::families($cid));
        $this->assertTrue($DB->record_exists_select(
            households::CONTACTS,
            "householdid = ? AND direction = 'note' AND " . $DB->sql_like('body', '?'),
            [$household->id, '%Marta Ruiz Gil%']
        ));
        // A list never says over what a person said.
        $this->assertFalse(alumni::set($household, $adult, alumni::MAYBE, 'list'));
        $this->assertTrue(alumni::set($household, $adult, alumni::NO, 'team', 2));
        $this->assertSame([], alumni::families($cid));
        $this->assertSame(
            get_string('alumni_no', 'local_oksigeniaeguahe'),
            alumni::words(alumni::of((int) $household->id)[$adult])
        );
        // It goes with the family.
        households::erase($household);
        $this->assertFalse($DB->record_exists(alumni::T, ['householdid' => $household->id]));
    }

    /**
     * The list of former students, imported: only keys and years are kept; the whole name says it, a name with one word
     * less is a question, and two names that only share a part say nothing. Taken away, what only it said goes.
     */
    public function test_list(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        [$whole, $wholeadult] = $this->family($cid, 'Ana', 'García López');
        [$part, $partadult] = $this->family($cid, 'Luis', 'Pérez');
        [$other, $otheradult] = $this->family($cid, 'Ana', 'García Pérez');
        $csv = "Nombre,Apellidos,Promoción\nAna,García López,2003\nLuis,Pérez Ruiz,1999\n";
        [$importid, $rows] = imports::import($cid, 'alumni', 'Antiguos alumnos', 'antiguos-alumnos.csv', $csv, 2);
        // The look-up runs in the background (a big centre and a long list do not fit in a page), and says what it found.
        $this->expectOutputRegex('/former students found 1, to ask 1/');
        $this->runAdhocTasks(\local_oksigeniaeguahe\task\match_alumni::class);
        $this->assertNotEmpty($importid);
        $this->assertSame(2, $rows, 'Two former students, whatever the keys of each.');
        $this->assertSame(2, alumni::list_size($cid));
        $this->assertSame(0, $DB->count_records_select(alumni::LIST, $DB->sql_like('keyhash', '?'), ['%garcia%']));
        $said = fn($h, $a) => (int) (alumni::of((int) $h->id)[$a]->said ?? -1);
        $this->assertSame(alumni::YES, $said($whole, $wholeadult));
        $this->assertSame('2003', (string) alumni::of((int) $whole->id)[$wholeadult]->year);
        $this->assertSame(alumni::MAYBE, $said($part, $partadult));
        $this->assertSame(-1, $said($other, $otheradult));
        $this->assertSame([(int) $part->id => true], alumni::maybes($cid));
        // A family that comes later is looked for as it comes.
        [$later, $lateradult] = $this->family($cid, 'Luis', 'Pérez Ruiz');
        $this->assertSame(alumni::YES, $said($later, $lateradult));
        // A person answers the question; then the list is taken away and only what it said goes.
        alumni::set($part, $partadult, alumni::YES, 'team', 2);
        imports::delete($cid, (int) $importid);
        $this->assertSame(0, alumni::list_size($cid));
        $this->assertSame(-1, $said($whole, $wholeadult));
        $this->assertSame(alumni::YES, $said($part, $partadult));
    }

    /**
     * The notes of a sheet: «madre antigua alumna» says it, «no es antiguo alumno» does not; the line of the note is
     * not written again for it.
     */
    public function test_notes(): void {
        $kinds = fn(string $text) => array_column(teamnotes::read('Observaciones', $text, time(), true) ?? [], 'kind');
        $this->assertContains('alumni', $kinds('Madre antigua alumna del cole, llamar el lunes'));
        $this->assertContains('alumni', $kinds('El padre es exalumno'));
        $this->assertContains('alumni', $kinds('Estudió aquí de pequeña'));
        $this->assertNotContains('alumni', $kinds('No es antiguo alumno'));
        $this->assertNotContains('alumni', $kinds('Alumna nueva de 3 años'));
        $this->assertSame(['alumni'], array_column(teamnotes::read('Antiguo alumno', 'Sí', time()) ?? [], 'kind'));
    }

    /**
     * A column of an imported list of the new students says which families have a former student; Cifras counts the new
     * students of those families, and of the families of Eguahe that said so, found by their emails.
     */
    public function test_counted(): void {
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $csv = "Nombre del alumno,Curso,Correo,Antiguo alumno\n" .
            "Daniel Sosa Díaz,Infantil 3 años,sosa.diaz@gmail.com,Sí\n" .
            "Lucía Mena Rey,Infantil 3 años,lucia.mena@hotmail.com,No\n" .
            "Hugo León Gil,Infantil 3 años,rosa.leon@gmail.com,\n";
        [$importid] = imports::import($cid, 'joins', '2026-27', 'altas.csv', $csv, 2);
        $this->assertNotEmpty($importid);
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame(3, $o['total']);
        $this->assertSame(1, $o['alumni']['n']);
        $this->assertTrue($o['alumni']['known']);
        // A family of Eguahe with the email of another of the list says it on its page.
        [$household, $adult] = $this->family($cid, 'Rosa', 'León Gil', 'rosa.leon@gmail.com');
        alumni::set($household, $adult, alumni::YES, 'family');
        $this->assertSame(2, metrics::origins($cid, '2026-27')['alumni']['n']);
    }
}
