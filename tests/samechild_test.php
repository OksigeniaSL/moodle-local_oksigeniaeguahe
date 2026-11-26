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
use local_oksigeniaeguahe\local\lookalike;
use local_oksigeniaeguahe\local\ties;

/**
 * The same child written down twice in one file: «Dafne» and «Dafne Cano», put together as one line, and back.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\lookalike
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lookalike::class)]
final class samechild_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $infantil;

    /**
     * A centre with a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $this->infantil = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
    }

    /**
     * A household with the lines of children given, written straight, as an older version could leave them.
     *
     * @param array[] $children Each [name, offer id, step, birthdate].
     * @return array [household, child ids]
     */
    protected function file(array $children): array {
        global $DB;
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Cano', 'email' => 'marta@example.com',
            'phone' => null]);
        $ids = [];
        foreach ($children as [$name, $offerid, $stage, $birth]) {
            $ids[] = (int) $DB->insert_record(households::CHILDREN, (object) ['centreid' => $this->centre->id,
                'householdid' => $id, 'firstname' => $name, 'offerid' => $offerid, 'stage' => $stage, 'stagetime' => time(),
                'birthdate' => $birth, 'timecreated' => time(), 'timemodified' => time()]);
        }
        return [households::get($id, (int) $this->centre->id), $ids];
    }

    /**
     * «Dafne» and «Dafne Cano» are proposed; two brothers, two birthdays or a name that could be either of two, never.
     */
    public function test_what_is_proposed(): void {
        [$h, [$a, $b]] = $this->file([['Dafne', 0, 'visit', ''], ['Dafne Cano', $this->infantil, 'inquiry', '']]);
        $pairs = lookalike::twice($h);
        $this->assertCount(1, $pairs);
        $this->assertSame([$a, $b], [(int) $pairs[0][0]->id, (int) $pairs[0][1]->id]);

        [$h] = $this->file([['Ana', 0, 'inquiry', ''], ['Leo', 0, 'inquiry', '']]);
        $this->assertSame([], lookalike::twice($h));

        [$h] = $this->file([['Ana', 0, 'inquiry', '2023-01-02'], ['Ana María', 0, 'inquiry', '2025-03-04']]);
        $this->assertSame([], lookalike::twice($h), 'Two birthdays: two children.');

        [$h] = $this->file([['Dafne', 0, 'inquiry', ''], ['Dafne Cano', 0, 'inquiry', ''], ['Dafne López', 0, 'inquiry', '']]);
        $this->assertSame([], lookalike::twice($h), 'The shorter one could be either.');

        [$h, [$a, $b]] = $this->file([['DAFNE CANO', 0, 'inquiry', ''], ['Dafne  Cano', 0, 'inquiry', '']]);
        $this->assertCount(1, lookalike::twice($h), 'The same words, written otherwise.');
        lookalike::not_twice($h, $a, $b, 0);
        $this->assertSame([], lookalike::twice($h), 'Said to be two: not asked again.');
    }

    /**
     * Put together: one line, with the fuller name, the shorter one for home, the way that went furthest and the course, and
     * what named the other; the history says it. Undone: two lines again, as they were, and not proposed again.
     */
    public function test_join_and_undo(): void {
        global $DB;
        [$h, [$a, $b]] = $this->file([['Dafne', 0, 'visit', ''], ['Dafne Cano', $this->infantil, 'inquiry', '2024-05-10']]);
        ties::set($h, $b, 'nursery', true, 0);
        $DB->insert_record(households::SIGNUPS, (object) ['centreid' => $this->centre->id, 'eventid' => 0,
            'householdid' => $h->id, 'people' => 2, 'wasstages' => $b . ':inquiry', 'timecreated' => time()]);
        $this->assertTrue(lookalike::join_twice($h, $b, $a, 0));

        $children = array_values($DB->get_records(households::CHILDREN, ['householdid' => $h->id]));
        $this->assertCount(1, $children);
        $child = $children[0];
        $this->assertSame($a, (int) $child->id, 'The older line stays.');
        $this->assertSame('Dafne Cano', $child->firstname);
        $this->assertSame('Dafne', $child->tagname);
        $this->assertSame('visit', $child->stage);
        $this->assertSame($this->infantil, (int) $child->offerid);
        $this->assertSame('2024-05-10', $child->birthdate);
        $this->assertSame($a, (int) $DB->get_field(ties::T, 'childid', ['householdid' => $h->id]));
        $this->assertSame($a . ':inquiry', $DB->get_field(households::SIGNUPS, 'wasstages', ['householdid' => $h->id]));
        $this->assertTrue($DB->record_exists_select(households::CONTACTS, 'householdid = ? AND ' .
            $DB->sql_like('body', '?'), [$h->id, '%Dafne Cano%']));
        $this->assertSame([], lookalike::twice($h));

        $row = $DB->get_record(lookalike::TWICE, ['householdid' => $h->id, 'same' => 1]);
        $this->assertTrue(lookalike::undo_twice($h, (int) $row->id, 0));
        $back = $DB->get_records(households::CHILDREN, ['householdid' => $h->id], 'id ASC');
        $this->assertSame([$a, $b], array_map('intval', array_keys($back)));
        $this->assertSame('Dafne', $back[$a]->firstname);
        $this->assertEmpty($back[$a]->tagname);
        $this->assertSame(0, (int) $back[$a]->offerid);
        $this->assertSame('Dafne Cano', $back[$b]->firstname);
        $this->assertSame('inquiry', $back[$b]->stage);
        $this->assertSame($b, (int) $DB->get_field(ties::T, 'childid', ['householdid' => $h->id]));
        $this->assertSame($b . ':inquiry', $DB->get_field(households::SIGNUPS, 'wasstages', ['householdid' => $h->id]));
        $this->assertSame([], lookalike::twice($h), 'Undone by a person: they are two.');
    }

    /**
     * The same child three times becomes one, a pair at a time; erasing the family takes what was kept of it.
     */
    public function test_three_times_and_erase(): void {
        global $DB;
        [$h] = $this->file([['Sara Gil Ruiz', 0, 'visit', ''], ['Sara Gil Ruiz', 0, 'inquiry', ''], ['Sara', 0, 'inquiry', '']]);
        for ($i = 0; $i < 5 && ($pairs = lookalike::twice($h)); $i++) {
            lookalike::join_twice($h, (int) $pairs[0][0]->id, (int) $pairs[0][1]->id, 0);
        }
        $this->assertSame(1, $DB->count_records(households::CHILDREN, ['householdid' => $h->id]));
        $this->assertSame(2, $DB->count_records(lookalike::TWICE, ['householdid' => $h->id]));
        // All of them at once, as the update does.
        [$other] = $this->file([['Dafne', 0, 'visit', ''], ['Dafne Cano', 0, 'inquiry', ''], ['Leo', 0, 'inquiry', '']]);
        $this->assertSame(1, lookalike::join_all_twice());
        $this->assertSame(2, $DB->count_records(households::CHILDREN, ['householdid' => $other->id]));
        households::erase($h);
        $this->assertSame(0, $DB->count_records(lookalike::TWICE, ['householdid' => $h->id]));
    }
}
