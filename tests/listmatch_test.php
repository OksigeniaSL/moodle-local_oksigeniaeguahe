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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\pseudonym;
use local_oksigeniaeguahe\local\who;

/**
 * What the list of the centre says of the families of Eguahe: a child enrolled, a family of the centre, or a question.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\listmatch
 */
#[\PHPUnit\Framework\Attributes\CoversClass(listmatch::class)]
final class listmatch_test extends \advanced_testcase {
    /**
     * A new student of the year enrols the child that asked for its course; an older one makes a family of the centre;
     * two children asking for the same course make a question; nothing is done twice.
     */
    public function test_detect(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $infantil = centres::save_offer($cid, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 3 años', 'places' => 0,
            'active' => 1]);
        $family = function (string $email, array $children) use ($cid, $infantil): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => $email,
                'phone' => null]);
            foreach ($children as $name) {
                households::add_child($household, $name, $infantil);
            }
            return households::get($id, $cid);
        };
        $one = $family('ana@example.com', ['Lucía']);
        $two = $family('rosa@example.com', ['Pablo']);
        $three = $family('eva@example.com', ['Leo', 'Mía']);
        $keys = new pseudonym($cid);
        $import = (int) $DB->insert_record('local_oksigeniaeguahe_import', (object) ['centreid' => $cid, 'kind' => 'joins',
            'label' => 'Cifra 2026-27', 'rowcount' => 3, 'userid' => 2, 'campaignstart' => 0, 'timecreated' => time()]);
        $row = function (string $email, string $student, string $movement, string $grade) use ($DB, $cid, $import, $keys) {
            $DB->insert_record('local_oksigeniaeguahe_student', (object) ['centreid' => $cid, 'importid' => $import,
                'student' => $student, 'household' => $keys->token('household', [pseudonym::email($email)]),
                'schoolyear' => '2026-27', 'grade' => $grade, 'timein' => strtotime('2026-09-07'), 'timeout' => 0,
                'movement' => $movement, 'birthyear' => 0]);
        };
        $row('ana@example.com', 'ST_one000001', 'join', 'Infantil 3 años');
        $row('rosa@example.com', 'ST_two000001', 'roll', '3.º de ESO');
        $row('eva@example.com', 'ST_three0001', 'join', 'Infantil 3 años');

        $this->assertSame([1, 1, 1], listmatch::detect(centres::get($cid), time()));
        $lucia = $DB->get_record(households::CHILDREN, ['householdid' => $one->id]);
        $this->assertSame('enrolled', $lucia->stage);
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $two->id]));
        $this->assertNotSame(
            'enrolled',
            $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $two->id]),
            'A brother at the centre does not enrol the one asking.'
        );
        // Nothing twice.
        $this->assertSame([0, 0, 0], listmatch::detect(centres::get($cid), time()));

        // The question: a person says which.
        $full = households::full(households::get((int) $three->id, $cid));
        $ctx = listmatch::context($three, $full, true);
        $this->assertCount(1, $ctx['asks']);
        $this->assertCount(2, $ctx['asks'][0]['children']);
        listmatch::choose($three, $ctx['asks'][0]['id'], $ctx['asks'][0]['children'][1]['id']);
        $this->assertSame(['open', 'enrolled'], array_values(array_map(
            fn($k) => $k->stage === 'enrolled' ? 'enrolled' : 'open',
            $DB->get_records(households::CHILDREN, ['householdid' => $three->id], 'id ASC')
        )));
        $this->assertFalse(listmatch::context($three, households::full(households::get((int) $three->id, $cid)), true)['hasasks']);
    }

    /**
     * Who each family that comes is, by the lists of the centre: with a child in its nursery, with children inside, with
     * children there in years before, of former students, or really new. A family with a child in the nursery that comes
     * to an open day knew the centre: it is never counted as new.
     */
    public function test_who(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 0,
            'active' => 1]);
        $family = function (string $email) use ($cid, $offer): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'openday']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => $email,
                'phone' => null]);
            households::add_child($household, 'Leo', $offer);
            return households::get($id, $cid);
        };
        $nursery = $family('guarderia@example.com');
        $inside = $family('dentro@example.com');
        $before = $family('antes@example.com');
        $new = $family('nueva@example.com');
        $keys = new pseudonym($cid);
        $import = (int) $DB->insert_record('local_oksigeniaeguahe_import', (object) ['centreid' => $cid, 'kind' => 'roll',
            'label' => 'Lista', 'rowcount' => 4, 'userid' => 2, 'campaignstart' => 0, 'timecreated' => time()]);
        $row = function (string $email, string $student, string $year, string $grade) use ($DB, $cid, $import, $keys) {
            $DB->insert_record('local_oksigeniaeguahe_student', (object) ['centreid' => $cid, 'importid' => $import,
                'student' => $student, 'household' => $keys->token('household', [pseudonym::email($email)]),
                'schoolyear' => $year, 'grade' => $grade, 'timein' => 0, 'timeout' => 0, 'movement' => 'roll',
                'birthyear' => 0]);
        };
        $row('guarderia@example.com', 'ST_nursery01', '2026-27', 'Guardería 2 años');
        $row('dentro@example.com', 'ST_inside001', '2026-27', '3.º de Primaria');
        $row('dentro@example.com', 'ST_inside002', '2026-27', 'Guardería 1 año');
        $row('antes@example.com', 'ST_before001', '2022-23', '2.º de Bachillerato');
        listmatch::detect(centres::get($cid), time());
        who::reset();
        $this->assertSame('nursery', who::kind($cid, (int) $nursery->id));
        $this->assertSame('centre', who::kind($cid, (int) $inside->id), 'A child in Primary too: children inside.');
        $this->assertSame('former', who::kind($cid, (int) $before->id));
        $this->assertSame(
            0,
            (int) $DB->get_field(households::T, 'incentre', ['id' => $before->id]),
            'Having had children there does not make it a family of the centre now.'
        );
        $this->assertSame('new', who::kind($cid, (int) $new->id));
        // Nothing twice: the years before are written once.
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(1, $DB->count_records(listmatch::T, ['householdid' => $before->id, 'kind' => 'former']));
        $ctx = listmatch::context($before, households::full($before), true);
        $this->assertStringContainsString('2022-23', $ctx['lines'][0]['text']);

        // Counted and said, the really new first.
        $counts = who::count([['who' => 'new', 'step' => 5], ['who' => 'new', 'step' => 1], ['who' => 'nursery', 'step' => 5],
            ['who' => 'centre', 'step' => 1], ['who' => 'alumni', 'step' => 2]]);
        $this->assertSame(['n' => 2, 'enrolled' => 1], $counts['new']);
        $this->assertSame(1, $counts['nursery']['n']);
        $c = 'local_oksigeniaeguahe';
        $this->assertSame(get_string('who_part_new', $c, (object) ['n' => 2, 'enrolled' => 1]) . '; ' .
            get_string('who_part_centre_nursery', $c, (object) ['n' => 2, 'nursery' => 1]) . '; ' .
            get_string('who_part_alumni', $c, 1), who::sentence($counts, false));
        $this->assertSame('', who::sentence(who::count([])));
    }

    /**
     * The lists a bridge puts in every night: each in the place of the one of the night before, all at once. A student
     * keeps its key from one night to the next, so nothing is said twice and what a person corrected stays; a new one of
     * the night enrols its child; an empty list never takes out the one there was.
     */
    public function test_every_night(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $primaria = centres::save_offer($cid, (object) ['schoolyear' => '2026-27', 'name' => '1.º de Primaria', 'places' => 0,
            'active' => 1]);
        $family = function (string $email) use ($cid, $primaria): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => $email,
                'phone' => null]);
            households::add_child($household, 'Leo', $primaria);
            return households::get($id, $cid);
        };
        $inside = $family('dentro@gmail.com');
        $comes = $family('llega@empresa-ejemplo.com');
        $dir = make_request_directory();
        $write = function (string $name, array $rows) use ($dir): string {
            $out = "Nombre,Apellidos,Nivel,Correo electrónico,Fecha de alta\n";
            foreach ($rows as $row) {
                $out .= implode(',', $row) . "\n";
            }
            file_put_contents("$dir/$name.csv", $out);
            return "$dir/$name.csv";
        };
        $night = fn(array $roll, array $joins) => imports::replace($cid, '2026-27', [
            'Programa 2026-27 · lista' => ['kind' => 'roll', 'path' => $write('lista', $roll)],
            'Programa 2026-27 · altas' => ['kind' => 'joins', 'path' => $write('altas', $joins)],
        ], 2);
        $roll = [['Mía', 'Sosa Díaz', '3.º de ESO', 'dentro@gmail.com', ''],
            ['Pol', 'Gil Vera', '2.º de ESO', 'otra@hotmail.com', '']];
        $this->assertSame([2, 0], $night($roll, [])['Programa 2026-27 · lista']);
        $tokens = $DB->get_fieldset_select(metrics::STUDENTS, 'student', 'centreid = ?', [$cid]);
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $inside->id]));
        // A person says it is not of the centre: the next night does not say it again, and the keys are the same.
        $DB->set_field(households::T, 'incentre', 0, ['id' => $inside->id]);
        $lines = $DB->count_records(households::CONTACTS, ['householdid' => $inside->id]);
        $night($roll, []);
        $this->assertEqualsCanonicalizing(
            $tokens,
            $DB->get_fieldset_select(metrics::STUDENTS, 'student', 'centreid = ?', [$cid])
        );
        $this->assertSame(1, $DB->count_records(listmatch::T, ['householdid' => $inside->id]));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'incentre', ['id' => $inside->id]));
        $this->assertSame($lines, $DB->count_records(households::CONTACTS, ['householdid' => $inside->id]));
        $this->assertSame(1, $DB->count_records(imports::T, ['centreid' => $cid]), 'One import of each list, the last.');
        // Who joins one night is on both lists: the family enrolled its child, it is not one that was there before. An email
        // of a company, alone on the list of the new ones, is not taken for the one of a family; the list of the year has it.
        $join = ['Leo', 'Ruiz Mar', '1.º de Primaria', 'llega@empresa-ejemplo.com', '24/11/2026'];
        $night(array_merge($roll, [array_replace($join, [4 => ''])]), [$join]);
        $this->assertSame('enrolled', $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $comes->id]));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'incentre', ['id' => $comes->id]));
        // An empty list of the year does not take out the one there was; an empty list of who joined does.
        $this->assertSame([0, 0], $night([], [])['Programa 2026-27 · lista']);
        $this->assertSame(3, $DB->count_records(metrics::STUDENTS, ['centreid' => $cid, 'movement' => 'roll']));
        $this->assertSame(0, $DB->count_records(metrics::STUDENTS, ['centreid' => $cid, 'movement' => 'join']));
    }

    /**
     * The list of the new ones that comes days after the list of the year: a family taken for one of the centre was one
     * that had just enrolled its child. A family made of the centre by hand, or with another child there, stays so.
     */
    public function test_joins_after_the_list(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2026-27', 'name' => '1.º de Primaria', 'places' => 0,
            'active' => 1]);
        $family = function (string $email) use ($cid, $offer): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => $email,
                'phone' => null]);
            households::add_child($household, 'Leo', $offer);
            return households::get($id, $cid);
        };
        $one = $family('una@gmail.com');
        $two = $family('dos@hotmail.com');
        $csv = fn(array $rows) => "Nombre,Apellidos,Nivel,Correo electrónico\n" . implode("\n", $rows) . "\n";
        $leo = 'Leo,Ruiz Mar,1.º de Primaria,una@gmail.com';
        $teo = 'Teo,Vega Sol,1.º de Primaria,dos@hotmail.com';
        imports::import($cid, 'roll', '2026-27', 'l.csv', $csv([$leo, $teo, 'Eva,Vega Sol,3.º de ESO,dos@hotmail.com']), 2);
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $one->id]));
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $two->id]));
        imports::import($cid, 'joins', '2026-27', 'a.csv', $csv([$leo, $teo]), 2);
        $this->assertSame('enrolled', $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $one->id]));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'incentre', ['id' => $one->id]));
        $this->assertSame('enrolled', $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $two->id]));
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $two->id]), 'Its other child was there.');
    }

    /**
     * Two rows of a sheet that a later row shows to be one family: the three carry the same key, which can be found.
     */
    public function test_one_family_in_three_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        imports::import($cid, 'roll', '2026-27', 'l.csv', "Nombre,Apellidos,Nivel,Correo electrónico,Correo electrónico 2\n" .
            "Leo,Ruiz Mar,1.º de Primaria,madre@gmail.com,\nEva,Ruiz Mar,2.º de Primaria,padre@hotmail.com,\n" .
            "Pau,Ruiz Mar,3.º de Primaria,madre@gmail.com,padre@hotmail.com\n" .
            "Mía,Sosa Díaz,3.º de Primaria,otra@hotmail.com,\nPol,Gil Vera,3.º de Primaria,mas@gmail.com,\n" .
            "Teo,Vega Sol,3.º de Primaria,aun@gmail.com,\n", 2);
        $homes = $DB->get_fieldset_select(metrics::STUDENTS, 'DISTINCT household', 'centreid = ?', [$cid]);
        $this->assertCount(4, $homes);
        $found = (new pseudonym($cid))->find('household', [pseudonym::email('padre@hotmail.com')]);
        $this->assertCount(1, $found);
        $this->assertSame(3, $DB->count_records(metrics::STUDENTS, ['centreid' => $cid, 'household' => $found[0]]));
    }
}
