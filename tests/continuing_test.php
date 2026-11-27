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
use local_oksigeniaeguahe\local\continuing;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\ties;
use local_oksigeniaeguahe\local\who;

/**
 * A continuing student: a child already at the centre that asks for the next course keeps its place, and the only question
 * is whether it goes on. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\continuing
 */
#[\PHPUnit\Framework\Attributes\CoversClass(continuing::class)]
final class continuing_test extends \advanced_testcase {
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
        centres::save_offer($id, (object) ['schoolyear' => '2026-27', 'name' => 'Guardería 2 años', 'places' => 15, 'active' => 1]);
        $this->infantil = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años',
            'places' => 25, 'active' => 1]);
    }

    /**
     * A family with its children, all asking for Infantil.
     *
     * @param string $email
     * @param string[] $names
     * @return array [household, child ids]
     */
    protected function family(string $email, array $names = ['Sara']): array {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'email' => $email]);
        $children = [];
        foreach ($names as $name) {
            $children[] = households::add_child($household, $name, $this->infantil);
        }
        return [households::get($id, (int) $this->centre->id), $children];
    }

    /**
     * Marked as in the nursery, its place is kept; yes enrols it, no closes its way with why, and taking the mark away
     * puts it back where it was. The places count it, and the numbers never as a new student.
     */
    public function test_place_kept(): void {
        global $DB;
        [$household, [$child]] = $this->family('ana@example.com');
        $cid = (int) $this->centre->id;
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'visit');
        ties::set($household, $child, 'nursery', true, 2);
        $row = $DB->get_record(households::CHILDREN, ['id' => $child]);
        $this->assertSame(['admitted', 'visit'], [$row->stage, $row->contstage]);
        $this->assertSame([$this->infantil => 1], continuing::reserved($cid));
        $this->assertSame([(int) $household->id => [$child]], continuing::families($cid));
        // The file: the question, not the way of asking for a place.
        $file = panel_household::context(households::get((int) $household->id, $cid), $this->centre, true);
        $cont = $file['children'][0]['cont'];
        $this->assertIsArray($cont);
        $this->assertFalse($cont['stay']);
        $this->assertSame(get_string('cont_from_nursery', 'local_oksigeniaeguahe'), $cont['from']);
        // The places: one kept, of the 25.
        $places = panel_inbox::places($this->centre, '2027-28');
        $this->assertSame(24, array_column($places['list'], 'free', 'name')['Infantil 3 años']);
        // Yes: enrolled, as a continuing student, never a new one.
        $this->assertTrue(continuing::answer($household, $child, 'stay', '', '', 2));
        $this->assertFalse(continuing::answer($household, $child, 'stay', '', '', 2), 'Said already.');
        $this->assertSame('enrolled', $DB->get_field(households::CHILDREN, 'stage', ['id' => $child]));
        $this->assertSame([], continuing::reserved($cid));
        $this->assertSame(['inner'], array_values(array_unique(array_column(metrics::live_new($cid, '2027-28'), 'kind'))));
        $lines = $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ? AND userid = 2', [$household->id]);
        $this->assertContains(get_string('cont_noted_stay', 'local_oksigeniaeguahe', (object) ['name' => 'Sara',
            'course' => 'Infantil 3 años', 'why' => '']), $lines);
        // No, with why: its way closed, its place free.
        $this->assertTrue(continuing::answer($household, $child, 'leave', 'moved', 'Se van a Madrid', 2));
        $row = $DB->get_record(households::CHILDREN, ['id' => $child]);
        $this->assertSame(['closed', 'moved', 'moved', 'Se van a Madrid'], [$row->stage, $row->closedreason, $row->contreason,
            $row->contnote]);
        $this->assertSame([], continuing::families($cid));
        // Not yet: its place kept again.
        $this->assertTrue(continuing::answer($household, $child, 'unsure', '', '', 2));
        $this->assertSame('admitted', $DB->get_field(households::CHILDREN, 'stage', ['id' => $child]));
        // The mark taken away: back to its visit, and the list does not mark it again.
        ties::set($household, $child, 'nursery', false, 2);
        $row = $DB->get_record(households::CHILDREN, ['id' => $child]);
        $this->assertSame(['visit', null, null], [$row->stage, $row->contstage, $row->contanswer]);
        $this->assertGreaterThan(0, (int) $row->contlist);
        $this->assertFalse(continuing::answer($household, $child, 'stay', '', '', 2), 'No longer continuing.');
        $this->list_says($household, 'a1');
        $this->assertSame([], continuing::from_lists($this->centre, true)['marked']);
    }

    /**
     * The list says a child of the family is in the nursery this year: the one asking for the next course is marked by
     * itself, once; with more children asking than students in the list, a person says.
     */
    public function test_from_lists(): void {
        global $DB;
        [$household, [$child]] = $this->family('ana@example.com');
        [$twins, $both] = $this->family('eva@example.com', ['Nora', 'Iria']);
        $this->list_says($household, 'a1');
        $this->list_says($twins, 'b1');
        $dry = continuing::from_lists($this->centre, false);
        $this->assertSame([[$child], [(int) $twins->id]], [$dry['marked'], $dry['doubt']]);
        $this->assertSame('', continuing::kind($child), 'Dry, nothing marked.');
        continuing::from_lists($this->centre, true);
        $this->assertSame('nursery', continuing::kind($child));
        $this->assertSame('admitted', $DB->get_field(households::CHILDREN, 'stage', ['id' => $child]));
        $this->assertTrue($DB->record_exists(ties::T, ['childid' => $child, 'kind' => 'nursery', 'userid' => 0]));
        $this->assertSame('', continuing::kind($both[0]));
        $this->assertSame([], continuing::from_lists($this->centre, true)['marked'], 'Once.');
        // The list of the new school year has it in Infantil: it went on, though nobody had said it.
        $DB->set_field(listmatch::T, 'schoolyear', '2027-28', ['householdid' => $household->id]);
        $DB->set_field(listmatch::T, 'grade', 'Infantil 3 años', ['householdid' => $household->id]);
        $this->assertSame([$child], continuing::from_lists($this->centre, true)['confirmed']);
        $this->assertSame(['enrolled', 'stay'], array_values((array) $DB->get_record(
            households::CHILDREN,
            ['id' => $child],
            'stage, contanswer'
        )));
        $this->assertSame([], continuing::from_lists($this->centre, true)['confirmed'], 'Once.');
        // What the file says: that the list said it.
        $file = panel_household::context(households::get((int) $household->id, (int) $this->centre->id), $this->centre, true);
        $this->assertStringContainsString(
            substr(get_string('cont_said_list', 'local_oksigeniaeguahe', ''), 0, 10),
            $file['children'][0]['cont']['said']
        );
    }

    /**
     * Each child marked before this, still asking for a place, has it kept; one not marked is not touched.
     */
    public function test_sync(): void {
        global $DB;
        [$household, [$child]] = $this->family('ana@example.com');
        [, [$other]] = $this->family('eva@example.com', ['Nora']);
        $DB->insert_record(ties::T, (object) ['centreid' => $this->centre->id, 'householdid' => $household->id,
            'childid' => $child, 'kind' => 'student', 'made' => 1, 'userid' => 2, 'timecreated' => time()]);
        $this->assertSame(1, continuing::sync_all((int) $this->centre->id));
        $this->assertSame(['admitted', 'inquiry'], [$DB->get_field(households::CHILDREN, 'stage', ['id' => $child]),
            $DB->get_field(households::CHILDREN, 'stage', ['id' => $other])]);
        $this->assertSame(0, continuing::sync_all((int) $this->centre->id));
    }

    /**
     * What the list of the centre said of a family: a student in its nursery this school year.
     *
     * @param \stdClass $household
     * @param string $student
     */
    protected function list_says(\stdClass $household, string $student): void {
        global $DB;
        $DB->insert_record(listmatch::T, (object) ['centreid' => $this->centre->id, 'householdid' => $household->id,
            'schoolyear' => '2026-27', 'student' => $student, 'kind' => 'incentre', 'grade' => 'Guardería 2 años',
            'timein' => 0, 'childid' => 0, 'done' => time(), 'marked' => listmatch::MADE, 'said' => 0, 'timecreated' => time()]);
        $DB->set_field(households::T, 'incentre', 1, ['id' => $household->id]);
    }
}
