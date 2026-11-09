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

use local_oksigeniaeguahe\local\ages;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\story;

/**
 * Each child by its own age (0.43.0): a family with one of three and one of fifteen. The step of the ladder of each,
 * the drawing asked for only of the young, the visit that shows the classrooms when one is older, and the eve and the
 * day after written to each in its tone. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ages::class)]
final class ages_test extends \advanced_testcase {
    /**
     * A centre of Spain with three courses, and a family with the children asked for.
     *
     * @param array $kids Name => course, '' without one.
     * @return array [centre, household]
     */
    protected function family(array $kids): array {
        global $DB;
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offers = [];
        foreach (['Infantil 3 años', '2.º de Primaria', '3.º de ESO', '1.º de Bachillerato', 'Inglés B2'] as $name) {
            $offers[$name] = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => $name, 'places' => 25,
                'sortorder' => count($offers), 'active' => 1]);
        }
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana@example.com']);
        foreach ($kids as $name => $course) {
            $childid = households::add_child($household, $name, $course === '' ? 0 : $offers[$course]);
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'enrolled');
        }
        return [centres::get($id), households::get((int) $household->id, $id)];
    }

    /**
     * The step of each age on the ladder, and of a course of no known age.
     */
    public function test_steps(): void {
        $steps = [3 => 'r1', 5 => 'r1', 6 => 'r2', 9 => 'r3', 10 => 'r4', 11 => 'r4', 12 => 'r5', 15 => 'r5', 16 => 'r6',
            17 => 'r6', 18 => 'r7', 40 => 'r7'];
        foreach ($steps as $age => $step) {
            $this->assertSame($step, ages::step_of($age), (string) $age);
        }
        $this->assertSame(ages::UNKNOWN, ages::step_of(null));
    }

    /**
     * What hangs for each child on the pages of its family, by the age of its course, whatever the skin of the panel.
     */
    public function test_hanging(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $household] = $this->family(['Leo' => 'Infantil 3 años', 'Noa' => '2.º de Primaria',
            'Juan' => '3.º de ESO', 'Ana' => '1.º de Bachillerato', 'Sara' => '', 'Max' => 'Inglés B2']);
        $offers = centres::offers((int) $centre->id);
        $children = households::full($household)->children;
        $objects = [];
        foreach ($children as $child) {
            $objects[$child->firstname] = ages::hanging($child, $offers, $centre);
        }
        $this->assertSame(['Leo' => 'mochila', 'Noa' => 'escolar', 'Juan' => 'equipaje', 'Ana' => 'credencial',
            'Sara' => 'mochila', 'Max' => 'equipaje'], $objects);
        // The cinematic skin hangs a luggage tag in the panel; the page of the family keeps each by its age.
        $centre->skin = 'cinematico';
        $leo = array_values(array_filter($children, fn($c) => $c->firstname === 'Leo'))[0];
        $this->assertSame('mochila', ages::hanging($leo, $offers, $centre));
    }

    /**
     * Young and older: the drawing only of the young (and of one without a course yet); one already sent always seen.
     * The visit shows where the backpack would go, or the classrooms when one of them is older.
     */
    public function test_young_and_older(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $household] = $this->family(['Leo' => 'Infantil 3 años', 'Juan' => '3.º de ESO', 'Sara' => '',
            'Marta' => 'Inglés B2']);
        $offers = centres::offers((int) $centre->id);
        $kids = array_column(array_values(households::full($household)->children), null, 'firstname');
        $this->assertTrue(ages::young($kids['Leo'], $offers, $centre));
        $this->assertFalse(ages::young($kids['Juan'], $offers, $centre));
        $this->assertTrue(ages::young($kids['Sara'], $offers, $centre), 'Without a course yet, the youngest.');
        $this->assertFalse(ages::young($kids['Marta'], $offers, $centre), 'Of no known age, never a little one.');
        $this->assertSame(ages::NOCOURSE, ages::step($kids['Sara'], $offers, $centre));
        $this->assertSame('r5', ages::step($kids['Juan'], $offers, $centre));
        $this->assertFalse(ages::older([$kids['Leo'], $kids['Sara']], $centre));
        $this->assertTrue(ages::older([$kids['Leo'], $kids['Juan']], $centre));

        // On their page, only Leo and Sara are asked for their drawing.
        $page = familypage::context($household, $centre, 'es', 'k', false);
        $send = array_column($page['children'], 'drawingsend', 'name');
        $this->assertNotSame('', $send['Leo']);
        $this->assertNotSame('', $send['Sara']);
        $this->assertSame('', $send['Juan']);
        $this->assertSame('', $send['Marta']);
    }

    /**
     * The eve and the day after, to a family with one of three and one of fifteen: each in its tone, the eldest never
     * a little one; and to a family with only older children, the course starts. In the language of the team (English
     * here); the Spanish ones read the same way.
     */
    public function test_first_day_texts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $household] = $this->family(['Leo' => 'Infantil 3 años', 'Juan' => '3.º de ESO']);
        $soon = time() + 10 * DAYSECS;
        $first = make_timestamp((int) date('Y', $soon), (int) date('n', $soon), (int) date('j', $soon));
        $DB->set_field(centres::T, 'firstday', $first, ['id' => $centre->id]);
        $centre = centres::get((int) $centre->id);
        $full = households::full($household);

        $eve = panel_household::firstday_proposal($household, $full, $centre, $first - HOURSECS)['text'];
        $this->assertStringContainsString('tomorrow is the big day.', $eve);
        $this->assertStringContainsString('We are waiting for Leo with their name on the door and their peg ready.', $eve);
        $this->assertStringContainsString('At the entrance, someone will show Juan their classroom and timetable.', $eve);
        $this->assertStringNotContainsString('Juan with their name', $eve);
        $this->assertStringContainsString('the story of Leo', $eve, 'The story, only of the one who has it.');
        $this->assertStringEndsWith('See you tomorrow.', $eve);

        $after = panel_household::firstday_proposal($household, $full, $centre, $first + DAYSECS + HOURSECS)['text'];
        $this->assertStringContainsString('how was the first day?', $after);
        $this->assertStringContainsString('how Leo came home', $after);
        $this->assertStringContainsString('What did Juan make of the school?', $after);
        $this->assertStringEndsWith('You can answer us right here or tell us on your page.', $after);

        // Only older children: the course starts, not «the big day».
        [$centre2, $older] = $this->family(['Juan' => '3.º de ESO', 'Lucía' => '1.º de Bachillerato']);
        $DB->set_field(centres::T, 'firstday', $first, ['id' => $centre2->id]);
        $centre2 = centres::get((int) $centre2->id);
        $this->assertSame('eve', story::step($older, $centre2, $first - HOURSECS)['step']);
        $eve = panel_household::firstday_proposal($older, households::full($older), $centre2, $first - HOURSECS)['text'];
        $this->assertStringContainsString('the school year starts tomorrow.', $eve);
        $this->assertStringContainsString('someone will show Juan and Lucía their classrooms', $eve);
        $after = panel_household::firstday_proposal($older, households::full($older), $centre2, $first + DAYSECS)['text'];
        $this->assertStringContainsString('If something does not fit for Lucía', $after);
    }
}
