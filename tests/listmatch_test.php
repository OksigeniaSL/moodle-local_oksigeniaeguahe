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
use local_oksigeniaeguahe\local\listnews;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\moments;
use local_oksigeniaeguahe\local\pseudonym;
use local_oksigeniaeguahe\local\ties;
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
        $counts = who::count([['who' => 'new', 'enrolled' => true], ['who' => 'new', 'enrolled' => false],
            ['who' => 'nursery', 'enrolled' => true], ['who' => 'centre', 'enrolled' => false], ['who' => 'alumni', 'enrolled' => false]]);
        $this->assertSame(['n' => 2, 'enrolled' => 1, 'all' => 2], $counts['new']);
        $this->assertSame(1, $counts['nursery']['n']);
        $c = 'local_oksigeniaeguahe';
        $this->assertSame(get_string('who_part_new', $c, (object) ['n' => 2, 'enrolled' => 1]) . '; ' .
            get_string('who_part_centre_parts', $c, (object) ['n' => 2, 'parts' => get_string('who_bit_nursery', $c, 1) . ', ' .
                get_string('who_bit_centre', $c, 1)]) . '; ' .
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
        $family = function (string $email, string $child) use ($cid, $offer): \stdClass {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => $email,
                'phone' => null]);
            households::add_child($household, $child, $offer);
            return households::get($id, $cid);
        };
        $one = $family('una@gmail.com', 'Leo');
        $two = $family('dos@hotmail.com', 'Teo');
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

    /**
     * A centre with the courses asked for, its keys and a function that writes a row of its lists.
     *
     * @return array [centre id, offers by name, row()]
     */
    protected function world(): array {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offers = [];
        foreach ([['2026-27', 'Infantil 3 años'], ['2026-27', '1.º de Primaria'], ['2027-28', '1.º de Primaria']] as [$y, $n]) {
            $offers["$n $y"] = centres::save_offer($cid, (object) ['schoolyear' => $y, 'name' => $n, 'places' => 25, 'active' => 1]);
        }
        $keys = new pseudonym($cid);
        $imports = [];
        $row = function (
            string $email,
            string $student,
            string $movement,
            string $grade,
            string $year = '2026-27',
            string $in = ''
        ) use (
            $DB,
            $cid,
            $keys,
            &$imports
        ): void {
            $imports[$movement] = $imports[$movement] ?? (int) $DB->insert_record(imports::T, (object) ['centreid' => $cid,
                'kind' => $movement === 'join' ? 'joins' : 'roll', 'label' => $movement, 'rowcount' => 1, 'userid' => 2,
                'timecreated' => time()]);
            $DB->insert_record(metrics::STUDENTS, (object) ['centreid' => $cid, 'importid' => $imports[$movement],
                'student' => $student, 'household' => $keys->token('household', [pseudonym::email($email)]), 'schoolyear' => $year,
                'grade' => $grade, 'timein' => strtotime($in !== '' ? $in : substr($year, 0, 4) . '-09-07'), 'timeout' => 0,
                'movement' => $movement, 'birthyear' => 0]);
        };
        return [$cid, $offers, $row];
    }

    /**
     * A family of Eguahe.
     *
     * @param int $cid
     * @param string $email
     * @param array $children Name => offer.
     * @param string $came When it came.
     * @param string $lastname
     * @return \stdClass
     */
    protected function home(int $cid, string $email, array $children, string $came = '2026-02-01', string $lastname = 'Ruiz'): \stdClass {
        global $DB;
        $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, $cid);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => $lastname, 'email' => $email,
            'phone' => null]);
        foreach ($children as $name => $offer) {
            households::add_child($household, (string) $name, (int) $offer);
        }
        $DB->set_field(households::T, 'timecreated', strtotime($came), ['id' => $id]);
        return households::get($id, $cid);
    }

    /**
     * The steps of the children of a family.
     *
     * @param \stdClass $household
     * @return array name => step
     */
    protected function steps(\stdClass $household): array {
        global $DB;
        return $DB->get_records_menu(households::CHILDREN, ['householdid' => $household->id], 'id ASC', 'firstname, stage');
    }

    /**
     * Enrols a child by hand.
     *
     * @param \stdClass $household
     * @param string $name
     * @param string $stage
     */
    protected function set(\stdClass $household, string $name, string $stage): void {
        global $DB;
        households::set_stage($DB->get_record(households::CHILDREN, ['householdid' => $household->id, 'firstname' => $name]), $stage);
    }

    /**
     * A child a person had already enrolled is the one of the list; nobody is enrolled for being the only one left.
     */
    public function test_enrolled_by_a_person(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $one = $this->home($cid, 'a@example.com', ['Lucía' => $o['1.º de Primaria 2026-27']]);
        $this->set($one, 'Lucía', 'enrolled');
        $row('a@example.com', 'ST_a', 'join', '1.º de Primaria');
        $twins = $this->home($cid, 'b@example.com', ['Leo' => $o['1.º de Primaria 2026-27'], 'Mía' => $o['1.º de Primaria 2026-27']]);
        $this->set($twins, 'Leo', 'enrolled');
        $this->set($twins, 'Mía', 'waitlist');
        $row('b@example.com', 'ST_b', 'join', '1.º de Primaria');
        $two = $this->home($cid, 'd@example.com', ['Teo' => $o['1.º de Primaria 2026-27'], 'Noa' => $o['Infantil 3 años 2026-27']]);
        $this->set($two, 'Teo', 'enrolled');
        $row('d@example.com', 'ST_d', 'join', '1.º de Primaria');
        // Two twins that both join: both are enrolled, with nothing to ask.
        $both = $this->home($cid, 'e@example.com', ['Ana' => $o['1.º de Primaria 2026-27'], 'Eva' => $o['1.º de Primaria 2026-27']]);
        $row('e@example.com', 'ST_e1', 'join', '1.º de Primaria');
        $row('e@example.com', 'ST_e2', 'join', '1.º de Primaria');
        $this->assertSame([4, 0, 1], listmatch::detect(centres::get($cid), time()));
        $this->assertSame(['Lucía' => 'enrolled'], $this->steps($one));
        $this->assertFalse(listmatch::context($one, households::full($one), true)['hasasks']);
        $this->assertSame(['Leo' => 'enrolled', 'Mía' => 'waitlist'], $this->steps($twins));
        $asks = listmatch::context($twins, households::full($twins), true)['asks'];
        $this->assertCount(2, $asks[0]['children'], 'Either of them: a person says.');
        $this->assertSame(['Teo' => 'enrolled', 'Noa' => 'inquiry'], $this->steps($two));
        $this->assertFalse(listmatch::context($two, households::full($two), true)['hasasks']);
        $this->assertSame(['Ana' => 'enrolled', 'Eva' => 'enrolled'], $this->steps($both));
        // With only the list of the new ones, each counts once, also while it is asked which of the two it is.
        $this->assertSame(5, metrics::origins($cid, '2026-27')['total']);
        $kid = array_key_first(array_filter(households::full($twins)->children, fn($k) => $k->firstname === 'Leo'));
        listmatch::choose($twins, $asks[0]['id'], $kid);
        $this->assertSame(['Leo' => 'enrolled', 'Mía' => 'waitlist'], $this->steps($twins));
        $this->assertSame(5, metrics::origins($cid, '2026-27')['total']);
    }

    /**
     * The name of the student says which child it is; a name that is nobody's is asked.
     */
    public function test_by_its_name(): void {
        [$cid, $o] = $this->world();
        $p = $o['1.º de Primaria 2026-27'];
        $twins = $this->home($cid, 'una@gmail.com', ['Leo' => $p, 'Mía' => $p]);
        $this->set($twins, 'Leo', 'enrolled');
        $other = $this->home($cid, 'dos@gmail.com', ['Pablo' => $p]);
        $csv = "Nombre,Apellidos,Nivel,Correo electrónico,Fecha de alta\nMía,Ruiz Sosa,1.º de Primaria,una@gmail.com,07/09/2026\n" .
            "Hugo,Ruiz Paz,1.º de Primaria,dos@gmail.com,07/09/2026\n";
        imports::import($cid, 'joins', '2026-27', 'a.csv', $csv, 2);
        $this->assertSame(['Leo' => 'enrolled', 'Mía' => 'enrolled'], $this->steps($twins));
        $this->assertSame(['Pablo' => 'inquiry'], $this->steps($other));
        $this->assertTrue(listmatch::context($other, households::full($other), true)['hasasks']);
        // A question still open is looked at again: the brother of Pablo, written down later, is who the list says.
        $this->assertSame([0, 0, 0], listmatch::detect(centres::get($cid), time()));
        households::add_child($other, 'Hugo', $p);
        $this->assertSame([1, 0, 0], listmatch::detect(centres::get($cid), time()));
        $this->assertSame(['Pablo' => 'inquiry', 'Hugo' => 'enrolled'], $this->steps($other));
        $this->assertFalse(listmatch::context($other, households::full($other), true)['hasasks']);
        // Enrolled the day it joined, not the night it was said.
        $mia = array_values(array_filter(households::full($twins)->children, fn($k) => $k->firstname === 'Mía'))[0];
        $this->assertSame('2026-09-07', userdate((int) $mia->stagetime, '%Y-%m-%d', 99, false));
    }

    /**
     * A phone written in the file of another student, or shared by families of different surnames, neither enrols nor makes a
     * family of the centre by itself: a person is asked; and once corrected, what it had said goes.
     */
    public function test_wrong_contact(): void {
        global $DB;
        [$cid, $o] = $this->world();
        $p = $o['1.º de Primaria 2026-27'];
        $csv = fn(array $rows) => "Nombre,Apellidos,Nivel,Correo electrónico,Correo electrónico 2\n" . implode("\n", array_map(
            fn($r) => implode(',', $r),
            $rows
        )) . "\n";
        $night = function (array $rows, array $joins = []) use ($cid, $csv): void {
            $dir = make_request_directory();
            file_put_contents("$dir/l.csv", $csv($rows));
            file_put_contents("$dir/a.csv", $csv($joins));
            imports::replace($cid, '2026-27', ['L' => ['kind' => 'roll', 'path' => "$dir/l.csv"],
                'A' => ['kind' => 'joins', 'path' => "$dir/a.csv"]], 2);
        };
        $of = fn($h) => (int) $DB->get_field(households::T, 'incentre', ['id' => $h->id]);
        $error = $this->home($cid, 'error@gmail.com', ['Pablo' => $p], '2026-11-20', 'Mena');
        $new = $this->home($cid, 'nueva@gmail.com', ['Lucía' => $p], '2026-02-01', 'Ruiz');
        $rows = [['Eva', 'Sosa Díaz', '4.º de Primaria', 'fam1@gmail.com', 'abuela@gmail.com'],
            ['Pol', 'Gil Vera', '4.º de Primaria', 'fam2@gmail.com', 'error@gmail.com'],
            ['Teo', 'Vega Sol', '4.º de Primaria', 'fam3@hotmail.com', '']];
        $join = ['Lucía', 'Ruiz Paz', '1.º de Primaria', 'nueva@gmail.com', 'abuela@gmail.com'];
        $night(array_merge($rows, [$join]), [$join]);
        $this->assertSame(['Lucía' => 'enrolled'], $this->steps($new));
        $this->assertSame(0, $of($new), 'The grandmother does not make it of the centre.');
        $this->assertSame(1, $of($error));
        // The email of the family is corrected in the program: the next night it is nobody's.
        $rows[1][4] = '';
        $night(array_merge($rows, [$join]), [$join]);
        $this->assertSame(0, $of($error));
        $this->assertSame(0, $DB->count_records(listmatch::T, ['householdid' => $error->id]));
        // Now two students of different surnames have it: nobody is joined, a person is asked, and says it is not.
        $rows[1][4] = $rows[2][4] = 'error@gmail.com';
        $night(array_merge($rows, [$join]), [$join]);
        $this->assertSame(0, $of($error));
        $asks = listmatch::context($error, households::full($error), true)['asks'];
        $this->assertSame([false, false], array_column($asks, 'which'));
        $this->assertTrue(listmatch::say($error, $asks[0]['id'], false, 2));
        $this->assertTrue(listmatch::say($error, $asks[1]['id'], true, 2), 'And that the other is.');
        $this->assertSame(1, $of($error));
        $night(array_merge($rows, [$join]), [$join]);
        $this->assertSame(1, $of($error), 'What a person said stays.');
        $this->assertFalse(listmatch::context($error, households::full($error), true)['hasasks']);
        // A surname in common says it without asking.
        $sol = $this->home($cid, 'error@gmail.com', ['Sara' => $p], '2026-11-20', 'Vega');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(1, $of($sol));
    }

    /**
     * A child the list enrolled by a contact that was not its family's: once corrected it is asked, and put back where it was.
     */
    public function test_taken_back(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $home = $this->home($cid, 'a@example.com', ['Pablo' => $o['1.º de Primaria 2026-27']]);
        $this->set($home, 'Pablo', 'application');
        $row('a@example.com', 'ST_a', 'join', '1.º de Primaria');
        $row('z@example.com', 'ST_z', 'roll', '1.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(['Pablo' => 'enrolled'], $this->steps($home));
        // The list says now it is of another family.
        $token = (new pseudonym($cid))->token('household', [pseudonym::email('otra@example.com')]);
        $DB->set_field(metrics::STUDENTS, 'household', $token, ['student' => 'ST_a']);
        $this->assertSame([0, 0, 0], listmatch::detect(centres::get($cid), time()));
        $this->assertSame(['Pablo' => 'enrolled'], $this->steps($home), 'Never taken back alone.');
        $ask = listmatch::context($home, households::full($home), true)['asks'][0];
        $this->assertFalse($ask['which']);
        $this->assertTrue(listmatch::say($home, $ask['id'], false, 2));
        $this->assertSame(['Pablo' => 'application'], $this->steps($home));
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(['Pablo' => 'application'], $this->steps($home));
    }

    /**
     * The list of the next year does not make of the centre the families its own campaign brought.
     */
    public function test_next_year(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $new = $this->home($cid, 'a@example.com', ['Leo' => $o['1.º de Primaria 2026-27']]);
        // Enrolled by a person, and never on a list of the new ones.
        $hand = $this->home($cid, 'b@example.com', ['Eva' => $o['1.º de Primaria 2026-27']]);
        $this->set($hand, 'Eva', 'enrolled');
        $old = $this->home($cid, 'c@example.com', ['Pol' => $o['1.º de Primaria 2027-28']], '2027-01-10');
        $row('a@example.com', 'ST_a', 'join', '1.º de Primaria');
        $row('a@example.com', 'ST_a', 'roll', '1.º de Primaria');
        $row('c@example.com', 'ST_c', 'roll', '3.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        $of = fn($h) => (int) $DB->get_field(households::T, 'incentre', ['id' => $h->id]);
        $this->assertSame([0, 0, 1], [$of($new), $of($hand), $of($old)]);
        $row('a@example.com', 'ST_a', 'roll', '2.º de Primaria', '2027-28');
        $row('b@example.com', 'ST_b', 'roll', '2.º de Primaria', '2027-28');
        $row('c@example.com', 'ST_c', 'roll', '4.º de Primaria', '2027-28');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame([0, 0, 1], [$of($new), $of($hand), $of($old)]);
        who::reset();
        // New for the campaign that brought it; for the one of the year after, its child is there.
        $this->assertSame(['new', 'centre'], [who::kind($cid, (int) $new->id, '2026-27'), who::kind($cid, (int) $new->id, '2027-28')]);
        // A list of only a part of the centre, or only of the new ones, of a later year does not empty the one there is.
        $row('z@example.com', 'ST_z', 'join', '1.º de Primaria', '2028-29');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(1, $of($old));
        $this->assertSame(0, $DB->count_records(listmatch::T, ['householdid' => $old->id, 'kind' => 'former']));
    }

    /**
     * A family whose child left the centre stops being of it when it asks for a year after that child had gone; what it was
     * when it came stays.
     */
    public function test_not_for_ever(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $gone = $this->home($cid, 'a@example.com', ['Leo' => $o['1.º de Primaria 2027-28']], '2026-03-01');
        $came = $this->home($cid, 'b@example.com', ['Eva' => $o['1.º de Primaria 2026-27']], '2026-03-01');
        $row('a@example.com', 'ST_a', 'roll', '4.º de ESO', '2025-26');
        $row('b@example.com', 'ST_b', 'roll', '4.º de ESO', '2025-26');
        listmatch::detect(centres::get($cid), time());
        $of = fn($h) => (int) $DB->get_field(households::T, 'incentre', ['id' => $h->id]);
        $this->assertSame([1, 1], [$of($gone), $of($came)]);
        $row('z@example.com', 'ST_z', 'roll', '1.º de ESO', '2026-27');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(0, $of($gone), 'It asks for 2027-28, and its child had gone a year before.');
        $this->assertSame(1, $of($came), 'It asked while its child was there.');
        who::reset();
        $this->assertSame('former', who::kind($cid, (int) $gone->id));
    }

    /**
     * «It is Noa», who asks for another school year: she is already there and what she asks for goes on; nobody takes a place.
     */
    public function test_question_of_another_year(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $next = $o['1.º de Primaria 2027-28'];
        $noa = $this->home($cid, 'n@example.com', ['Noa' => $next], '2026-10-20');
        $row('n@example.com', 'ST_n', 'join', 'Infantil 5 años', '2026-27', '2026-11-20');
        listmatch::detect(centres::get($cid), time());
        $ask = listmatch::context($noa, households::full($noa), true)['asks'][0];
        $this->assertStringContainsString('2026-27', $ask['text']);
        $this->assertStringContainsString('2027-28', $ask['children'][0]['name']);
        listmatch::choose($noa, $ask['id'], $ask['children'][0]['id']);
        $this->assertSame(['Noa' => 'inquiry'], $this->steps($noa));
        $this->assertSame(0, (int) (households::offer_counts($cid)[$next]['enrolled'] ?? 0));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'incentre', ['id' => $noa->id]), 'It came before she joined.');
        $context = listmatch::context($noa, households::full($noa), true);
        $this->assertFalse($context['hasasks']);
        $this->assertStringContainsString('Noa', $context['lines'][0]['text']);
        listmatch::detect(centres::get($cid), time());
        $this->assertFalse(listmatch::context($noa, households::full($noa), true)['hasasks']);
        // A family that came long after its other child joined was already of the centre.
        $late = $this->home($cid, 'l@example.com', ['Sol' => $next], '2027-02-01');
        $row('l@example.com', 'ST_l', 'join', '3.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $late->id]));
        $lines = $DB->count_records(households::CONTACTS, ['householdid' => $late->id]);
        $this->assertSame([0, 0, 0], listmatch::detect(centres::get($cid), time()), 'Said once.');
        $this->assertSame($lines, $DB->count_records(households::CONTACTS, ['householdid' => $late->id]));
    }

    /**
     * A child that had gone and joins again, as a person says: it is back, in the course of the list.
     */
    public function test_back_again(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $teo = $this->home($cid, 't@example.com', ['Teo' => $o['Infantil 3 años 2026-27']]);
        $this->set($teo, 'Teo', 'enrolled');
        $DB->set_field(households::CHILDREN, 'lefttime', time(), ['householdid' => $teo->id]);
        $row('t@example.com', 'ST_t', 'join', '1.º de Primaria', '2027-28');
        listmatch::detect(centres::get($cid), time());
        $ask = listmatch::context($teo, households::full($teo), true)['asks'][0];
        listmatch::choose($teo, $ask['id'], $ask['children'][0]['id']);
        $kid = $DB->get_record(households::CHILDREN, ['householdid' => $teo->id]);
        $this->assertSame(['enrolled', '0', (string) $o['1.º de Primaria 2027-28'], '2027-28'], [$kid->stage, $kid->lefttime,
            $kid->offerid, $kid->startyear]);
    }

    /**
     * What a person marked and what the list says, in any order: the family is of the centre while one of them says so.
     */
    public function test_marks_and_list(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $of = fn($h) => (int) $DB->get_field(households::T, 'incentre', ['id' => $h->id]);
        $home = $this->home($cid, 'v@example.com', ['Leo' => $o['1.º de Primaria 2026-27']]);
        ties::set($home, 0, 'sibling', true, 2);
        $row('v@example.com', 'ST_1', 'roll', '1.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        ties::set(households::get((int) $home->id, $cid), 0, 'sibling', false, 2);
        $this->assertSame(1, $of($home), 'The list says so.');
        $row('v@example.com', 'ST_1', 'join', '1.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(0, $of($home), 'Nobody says so any more: it joined.');
        $this->assertSame(['Leo' => 'enrolled'], $this->steps($home));
        // The other way round: the list said it first, a person marks it, and the list stops saying it.
        $two = $this->home($cid, 'w@example.com', ['Eva' => $o['1.º de Primaria 2026-27']]);
        $row('w@example.com', 'ST_2', 'roll', '3.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        ties::set(households::get((int) $two->id, $cid), 0, 'staff', true, 2);
        $token = (new pseudonym($cid))->token('household', [pseudonym::email('otra@example.com')]);
        $DB->set_field(metrics::STUDENTS, 'household', $token, ['student' => 'ST_2']);
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(1, $of($two));
        ties::set(households::get((int) $two->id, $cid), 0, 'staff', false, 2);
        $this->assertSame(0, $of($two));
        // One that said it itself stays, whatever the list says.
        $said = $this->home($cid, 'x@example.com', ['Pol' => $o['1.º de Primaria 2026-27']]);
        $DB->set_field(households::T, 'incentre', 1, ['id' => $said->id]);
        $row('x@example.com', 'ST_3', 'roll', '3.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        $DB->set_field(metrics::STUDENTS, 'household', $token, ['student' => 'ST_3']);
        listmatch::detect(centres::get($cid), time());
        $this->assertSame(1, $of($said));
    }

    /**
     * What the last list did by itself is told in the inbox, can be put right, and is not told again once known; a family made
     * up to be looked at is in no list.
     */
    public function test_news(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $this->setAdminUser();
        $p = $o['1.º de Primaria 2026-27'];
        $one = $this->home($cid, 'a@example.com', ['Leo' => $p]);
        $this->set($one, 'Leo', 'application');
        $two = $this->home($cid, 'b@example.com', ['Eva' => $p]);
        $ask = $this->home($cid, 'c@example.com', ['Pol' => $p, 'Sol' => $p]);
        $sample = $this->home($cid, 'a@example.com', ['Muestra' => $p], '2000-01-01 00:00:00 UTC');
        $marked = $this->home($cid, 'a@example.com', ['Otra' => $p]);
        $DB->set_field(households::T, 'guest', 2, ['id' => $marked->id]);
        $row('a@example.com', 'ST_a', 'join', '1.º de Primaria');
        $row('b@example.com', 'ST_b', 'roll', '3.º de Primaria');
        $row('c@example.com', 'ST_c', 'join', '1.º de Primaria');
        $centre = centres::get($cid);
        $this->assertSame([], listnews::notes($centre, true));
        $this->assertSame([1, 1, 1], listmatch::detect($centre, time()));
        $this->assertSame(['Muestra' => 'inquiry'], $this->steps($sample));
        $this->assertSame(['Otra' => 'inquiry'], $this->steps($marked));
        $note = listnews::notes($centre, true)[0];
        foreach (['listnews_enrolled_one', 'listnews_incentre_one', 'listnews_ask_one'] as $key) {
            $this->assertStringContainsString(get_string($key, 'local_oksigeniaeguahe', 1), $note['text']);
        }
        $page = listnews::context($centre, true);
        $this->assertSame(['ask', 'enrolled', 'incentre'], array_column($page['groups'], 'key'));
        // It is not so.
        $this->assertTrue(listmatch::say($one, $page['groups'][1]['families'][0]['id'], false, 2));
        $this->assertSame(['Leo' => 'application'], $this->steps($one));
        $this->assertTrue(listmatch::say($two, $page['groups'][2]['families'][0]['id'], false, 2));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'incentre', ['id' => $two->id]));
        listmatch::detect($centre, time());
        $this->assertSame(['Leo' => 'application'], $this->steps($one), 'It is not said again.');
        $this->assertSame(0, (int) $DB->get_field(households::T, 'incentre', ['id' => $two->id]));
        // Known, until there is something new.
        moments::know($cid, listnews::notes($centre, true)[0]['known']['key']);
        $this->assertSame([], listnews::notes($centre, true));
        $row('b@example.com', 'ST_d', 'join', '1.º de Primaria');
        listmatch::detect($centre, time());
        $this->assertCount(1, listnews::notes($centre, true));
        $this->assertStringContainsString('lista.php', listnews::notes($centre, true)[0]['url']);
    }

    /**
     * Two files put together keep what the list said once, of the child that stays.
     */
    public function test_merged(): void {
        global $DB;
        [$cid, $o, $row] = $this->world();
        $p = $o['1.º de Primaria 2026-27'];
        $keep = $this->home($cid, 'a@example.com', ['Teo' => $p], '2026-01-01');
        $gone = $this->home($cid, 'b@example.com', ['Teo' => $p], '2026-02-01');
        $row('b@example.com', 'ST_t', 'join', '1.º de Primaria');
        listmatch::detect(centres::get($cid), time());
        $kept = \local_oksigeniaeguahe\local\lookalike::merge($keep, $gone, 2);
        $kid = (int) $DB->get_field(households::CHILDREN, 'id', ['householdid' => $kept->id]);
        $this->assertSame($kid, (int) $DB->get_field(listmatch::T, 'childid', ['householdid' => $kept->id]));
        $this->assertStringContainsString('Teo', listmatch::context($kept, households::full($kept), true)['lines'][0]['text']);
        $this->assertSame(1, metrics::origins($cid, '2026-27')['total']);
    }
}
