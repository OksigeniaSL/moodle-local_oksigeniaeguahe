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
use local_oksigeniaeguahe\local\export;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\lookalike;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\ties;
use local_oksigeniaeguahe\local\who;

/**
 * What ties a child to the centre, as who knows the family marks it, and what the numbers make of it. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\ties
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ties::class)]
final class ties_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $infantil;

    /**
     * A centre with a nursery and Infantil.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        who::reset();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Guardería 2 años', 'places' => 15, 'active' => 1]);
        $this->infantil = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
    }

    /**
     * A family with one child.
     *
     * @param string $email
     * @param string $child
     * @return array [household, child id]
     */
    protected function family(string $email, string $child = 'Leo'): array {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'email' => $email]);
        $childid = households::add_child($household, $child, $this->infantil);
        return [households::get($id, (int) $this->centre->id), $childid];
    }

    /**
     * Two things at once: in the nursery and of the staff. The family is already of the centre, once, and both are said.
     */
    public function test_two_things(): void {
        global $DB;
        [$household, $child] = $this->family('ana@example.com');
        [$other] = $this->family('eva@example.com', 'Nora');
        $cid = (int) $this->centre->id;
        $id = (int) $household->id;
        $this->assertSame('new', who::kind($cid, $id));
        $this->assertTrue(ties::set($household, $child, 'nursery', true, 2));
        $this->assertFalse(ties::set($household, $child, 'nursery', true, 2), 'Marked already.');
        $this->assertTrue(ties::set($household, $child, 'staff', true, 2));
        $this->assertTrue(ties::set($household, $child, 'alumni', true, 2));
        $this->assertFalse(ties::set($household, 0, 'student', true, 2), 'Of which child is it.');
        $this->assertFalse(ties::set($household, $child, 'other', true, 2));
        $this->assertSame(['nursery', 'staff', 'alumni'], who::kinds($cid, $id));
        $this->assertSame('nursery', who::kind($cid, $id));
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $id]));
        $this->assertTrue(alumni::in_family($id));
        $counts = who::count([['who' => 'nursery', 'also' => who::kinds($cid, $id), 'step' => 1],
            ['who' => who::kind($cid, (int) $other->id), 'also' => who::kinds($cid, (int) $other->id), 'step' => 1]]);
        $this->assertSame([1, 1, 1, 0], [who::centre($counts), $counts['nursery']['n'], $counts['new']['n'], $counts['staff']['n']]);
        $this->assertSame(1, $counts['staff']['all']);
        $c = 'local_oksigeniaeguahe';
        $this->assertSame(get_string('who_bit_nursery', $c, 1) . ', ' . get_string('who_bit_staff', $c, 1), who::centre_parts($counts));
        // Its history says who marked it and what, and so does what they can download.
        $bodies = $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ? AND userid = 2', [$id]);
        $this->assertNotEmpty(preg_grep('/' . preg_quote(get_string('tie_nursery', $c), '/') . '.*Leo/u', $bodies));
        $this->assertSame([get_string('tie_nursery', $c), get_string('tie_staff', $c)], array_column(ties::export($id), 'what'));
        // The file: each thing, marked or not.
        $file = panel_household::context(households::get($id, $cid), $this->centre, true);
        $rows = array_column($file['children'][0]['ties']['rows'], 'on', 'kind');
        $this->assertSame(['nursery' => true, 'student' => false, 'sibling' => false, 'staff' => true, 'alumni' => true], $rows);
        // The list of families to download.
        $down = array_column(export::families($this->centre), 'ties', 'child');
        $this->assertStringContainsString(get_string('who_staff', $c), implode('|', $down));
    }

    /**
     * Taken out, the family is as it was; one a list says is of the centre stays so.
     */
    public function test_taken_out(): void {
        global $DB;
        [$household, $child] = $this->family('ana@example.com');
        $id = (int) $household->id;
        $incentre = fn() => (int) $DB->get_field(households::T, 'incentre', ['id' => $id]);
        ties::set($household, $child, 'sibling', true, 2);
        ties::set($household, $child, 'staff', true, 2);
        ties::set($household, $child, 'sibling', false, 2);
        $this->assertSame(1, $incentre(), 'Of the staff still.');
        ties::set($household, $child, 'staff', false, 2);
        $this->assertSame(0, $incentre());
        $this->assertSame('new', who::kind((int) $this->centre->id, $id));
        ties::set($household, $child, 'nursery', true, 2);
        $DB->insert_record(listmatch::T, (object) ['centreid' => $this->centre->id, 'householdid' => $id,
            'schoolyear' => '2026-27', 'student' => 'ST_1', 'kind' => 'incentre', 'grade' => 'Guardería 2 años', 'timein' => 0,
            'childid' => 0, 'done' => 1, 'marked' => listmatch::WAS, 'timecreated' => time()]);
        ties::set($household, $child, 'nursery', false, 2);
        $this->assertSame(1, $incentre(), 'The list says it.');
        $this->assertSame('nursery', who::kind((int) $this->centre->id, $id));
        $this->assertStringContainsString(
            get_string('tie_list_nursery', 'local_oksigeniaeguahe'),
            ties::from_list($id, $this->centre)
        );
        // One that said it was of the centre: it stays so whatever is marked and taken out.
        [$said, $kid] = $this->family('eva@example.com');
        $DB->set_field(households::T, 'incentre', 1, ['id' => $said->id]);
        ties::set($said, $kid, 'staff', true, 2);
        $this->assertSame('staff', who::kind((int) $this->centre->id, (int) $said->id), 'What was marked is what is known.');
        ties::set($said, $kid, 'staff', false, 2);
        $this->assertSame('centre', who::kind((int) $this->centre->id, (int) $said->id));
        $this->assertSame(1, (int) $DB->get_field(households::T, 'incentre', ['id' => $said->id]));
    }

    /**
     * Enrolled: whoever was already there goes on, and one of the staff is of the staff; neither was attracted.
     */
    public function test_new_students(): void {
        global $DB;
        $kinds = [];
        foreach (['nursery', 'sibling', 'staff', 'alumni', ''] as $i => $kind) {
            [$household, $child] = $this->family("f$i@example.com", "Niño $i");
            if ($kind !== '') {
                ties::set($household, $child, $kind, true, 2);
            }
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'enrolled');
            $kinds['eg' . $child] = ['nursery' => 'inner', 'sibling' => 'sibling', 'staff' => 'staff'][$kind] ?? 'newfamily';
        }
        $this->assertEquals($kinds, array_column(array_map(
            fn($id, $row) => ['id' => $id, 'kind' => $row['kind']],
            array_keys($new = metrics::live_new((int) $this->centre->id, '2027-28')),
            $new
        ), 'kind', 'id'));
        $origins = metrics::origins((int) $this->centre->id, '2027-28');
        $this->assertSame([1, 1, 1, 2, 0], array_map(fn($g) => $g['n'], array_values($origins['groups'])));
    }

    /**
     * The same family put together: what was marked of both, once, of the child that stays.
     */
    public function test_merge(): void {
        global $DB;
        [$one, $a] = $this->family('ana@example.com', 'Nora Vidal Cano');
        [$two, $b] = $this->family('pau@example.com', 'Vidal Cano Nora');
        ties::set($one, $a, 'nursery', true, 2);
        ties::set($two, $b, 'nursery', true, 2);
        ties::set($two, $b, 'staff', true, 2);
        $kept = lookalike::merge($one, $two, 2);
        $this->assertSame([$a => ['nursery' => true], 0 => ['staff' => true]], ties::of((int) $kept->id));
        $this->assertSame(1, (int) $kept->incentre);
        households::erase($kept);
        $this->assertSame(0, $DB->count_records(ties::T));
    }
}
