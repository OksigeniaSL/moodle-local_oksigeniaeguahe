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
use local_oksigeniaeguahe\local\grades;
use local_oksigeniaeguahe\local\knownpage;
use local_oksigeniaeguahe\local\publicpage;

/**
 * What Enrique saw on dev (0.56.0): the whole offer of a centre, from the nursery to the last year, added a stage at a
 * time and grouped by stage in the list of the families; the date of birth in three fields, in the order of the country
 * of the centre; the names of who receives, as they are said; and the wall of a family already known. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grades::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(publicpage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(knownpage::class)]
final class wholeoffer_test extends \advanced_testcase {
    /**
     * A centre of Spain.
     *
     * @return \stdClass
     */
    protected function centre(): \stdClass {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        return centres::get($id);
    }

    /**
     * The stages of the system of the centre, youngest first, from the nursery: one at a time, its courses are added
     * to a year with their usual names, never twice; and the list of the families goes by stage, each course with the
     * year of birth it is for.
     */
    public function test_stages_and_groups(): void {
        $this->resetAfterTest();
        $centre = $this->centre();
        $stages = grades::stages($centre);
        $this->assertSame(
            ['Infantil (0 a 2 años)', 'Infantil (3 a 5 años)', 'Primaria', 'ESO', 'Bachillerato'],
            array_column($stages, 'name')
        );
        $this->assertSame(['Infantil 0 años', 'Infantil 1 año', 'Infantil 2 años'], array_column($stages[0]['courses'], 'name'));
        $this->assertSame('6.º de Primaria', end($stages[2]['courses'])['name']);
        foreach ($stages as $stage) {
            foreach ($stage['courses'] as $course) {
                $this->assertSame($course['age'], grades::age($course['name'], $centre), $course['name'] . ' reads back.');
            }
        }
        $this->assertSame('Primaria', grades::stage_of(8, $centre)['name']);
        $this->assertNull(grades::stage_of(19, $centre));

        $this->assertSame(6, centres::add_stage((int) $centre->id, $stages[2]['key'], '2027-28'));
        $this->assertSame(0, centres::add_stage((int) $centre->id, $stages[2]['key'], '2027-28'), 'Never twice.');
        $this->assertSame(3, centres::add_stage((int) $centre->id, $stages[1]['key'], '2027-28'));
        centres::save_offer((int) $centre->id, (object) ['schoolyear' => '2027-28', 'name' => 'Aula de música',
            'places' => 0, 'active' => 1]);
        [$groups, $loose] = grades::grouped(array_values(centres::offers((int) $centre->id, true)), $centre);
        $this->assertSame(['Infantil (3 a 5 años) · 2027-28', 'Primaria · 2027-28'], array_column($groups, 'label'));
        $this->assertSame(['1.º de Primaria', '2.º de Primaria'], array_slice(array_column($groups[1]['offers'], 'name'), 0, 2));
        $this->assertSame(2027 - 6, $groups[1]['offers'][0]->born, 'First of Primary: born six years before it starts.');
        $this->assertSame(['Aula de música'], array_column($loose, 'name'));

        // Without a system, no stages: each course by its age, as before.
        $this->assertSame([], grades::stages((object) ['id' => 0, 'country' => '', 'system' => 'none']));
    }

    /**
     * The date in three fields, in the order of the country of the centre and never of the browser, read back as one;
     * half a date, or letters, are said to be wrong.
     */
    public function test_date_fields(): void {
        $this->resetAfterTest();
        $this->assertSame(['d', 'm', 'y'], publicpage::date_order('es', 'ES'));
        $this->assertSame(['m', 'd', 'y'], publicpage::date_order('en', 'US'));
        $this->assertSame(['d', 'm', 'y'], publicpage::date_order('en', 'GB'));
        $this->assertSame(['d', 'm', 'y'], publicpage::date_order('pt_br', 'PT'), 'Brazilian Portuguese, by its language.');
        $centre = $this->centre();
        $input = publicpage::date_input('c0birth', '2024-03-14', 'es', $centre);
        $this->assertSame(['c0birthd', 'c0birthm', 'c0birthy'], array_column($input['parts'], 'id'));
        $this->assertSame(['14', '03', '2024'], array_column($input['parts'], 'value'));
        $this->assertSame('c0birthd', $input['first']);
        $this->assertFalse($input['form'], 'Off unless the page sets it.');
        $this->assertSame('Día', $input['parts'][0]['label']);

        $_POST = ['c0birthd' => '9', 'c0birthm' => '3', 'c0birthy' => '2024'];
        $this->assertSame('2024-03-09', publicpage::date_param('c0birth'));
        $_POST = ['c0birthd' => '9', 'c0birthm' => '', 'c0birthy' => '2024'];
        $this->assertSame('invalid', publicpage::date_param('c0birth'));
        $_POST = ['c0birthd' => 'ab', 'c0birthm' => '3', 'c0birthy' => '24'];
        $this->assertSame('invalid', publicpage::date_param('c0birth'));
        $_POST = ['c0birth' => '2024-03-09'];
        $this->assertSame('2024-03-09', publicpage::date_param('c0birth'), 'The old single field still reads.');
        $_POST = [];
        $this->assertSame('', publicpage::date_param('c0birth'));
    }

    /**
     * Who receives is named as said, «Lucía Medina», even where the lists of the site put the surname first; and the
     * wall of a family already known shows what they just wrote and nothing of their page.
     */
    public function test_names_and_known_wall(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->fullnamedisplay = 'lastname firstname';
        $centre = $this->centre();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Lucía', 'lastname' => 'Medina']);
        centres::add_member((int) $centre->id, (int) $user->id);
        $member = $DB->get_record(centres::MEMBERS, ['centreid' => $centre->id, 'userid' => $user->id]);
        $DB->set_field(centres::MEMBERS, 'public', 1, ['id' => $member->id]);
        $hosts = centres::hosts((int) $centre->id, [(int) $member->id]);
        $this->assertSame('Lucía Medina', $hosts[0]->name);

        $context = knownpage::context($centre, 'es', [['firstname' => 'Leonardo', 'tagname' => 'Leo', 'offerid' => 0],
            ['firstname' => 'Ana', 'tagname' => '', 'offerid' => 0], ['firstname' => '', 'tagname' => '']], null, true);
        $this->assertSame('Leo y Ana', $context['names']);
        $this->assertSame(['Leo', 'Ana'], array_column($context['rail']['pegs'], 'name'));
        $this->assertFalse($context['event'], 'From «Ask for information», no act.');
        $this->assertFalse($context['hall'], 'Never the hall of their own page.');
        $this->assertStringContainsString('al correo que nos dieron', $context['text']);
    }
}
