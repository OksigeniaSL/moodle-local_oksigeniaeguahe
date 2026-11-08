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

use local_oksigeniaeguahe\local\antispam;
use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familypage;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\siblings;
use local_oksigeniaeguahe\local\signup;

/**
 * The brothers and sisters who would come later, where children enter by age: told when signing up or noted by the
 * team, offered once enrolled, proposed to the team when the campaign of their year starts, and a place kept by the
 * family with one touch. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(siblings::class)]
final class siblings_test extends \advanced_testcase {
    /** @var int A day of the campaign under way, for the school year 2027-28. */
    protected int $now = 0;

    /**
     * A centre where children enter at three, its campaign begun on the 1st of October 2026, and a family with Leo
     * enrolled, Mateo (born in 2024, his turn now) and Sofía (born in 2025, next year).
     *
     * @return array [centre, household, Mateo, Sofía]
     */
    protected function family(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->now = make_timestamp(2026, 11, 8, 12);
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1, 'sortorder' => 0]);
        $DB->update_record(centres::T, (object) ['id' => $id, 'siblingage' => 3,
            'campaignstart' => make_timestamp(2026, 10, 1)]);
        $household = households::get(households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $id);
        households::add_adult($household, (object) ['firstname' => 'Marta', 'lastname' => 'Ruiz', 'email' => 'marta@example.com']);
        $leo = households::add_child($household, 'Leonardo', $offer, 'Leo');
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $leo]), 'enrolled');
        $mateo = siblings::add($household, 'Mateo', 2024, 'signup', $this->now);
        $sofia = siblings::add($household, 'Sofía', 2025, 'team', $this->now);
        return [centres::get($id), households::get((int) $household->id, $id), $mateo, $sofia];
    }

    /**
     * When it is the turn of each, in the words of the centre; who can be noted; and which households have one now.
     */
    public function test_turns(): void {
        [$centre, $household, $mateo, $sofia] = $this->family();
        $this->assertTrue(siblings::on($centre));
        $this->assertNull(siblings::add($household, '  ', 2024, 'team', $this->now));
        $this->assertNull(siblings::add($household, 'Antonio', 1990, 'team', $this->now), 'Not a child.');
        $this->assertSame(2027, siblings::campaign_year($centre, $this->now));
        [$m, $s] = siblings::of((int) $household->id);
        $this->assertSame([$mateo, $sofia], [(int) $m->id, (int) $s->id]);
        $this->assertSame('2027-28', siblings::year_label($centre, siblings::turn_year($centre, $m)));
        $this->assertTrue(siblings::in_turn($centre, $m, $this->now));
        $this->assertFalse(siblings::in_turn($centre, $s, $this->now));
        $this->assertSame(make_timestamp(2027, 10, 1), siblings::campaign_start($centre, 2028, $this->now));
        $due = siblings::due($centre, $this->now);
        $this->assertSame([(int) $household->id], array_keys($due));
        $this->assertSame('Mateo', $due[$household->id][0]->firstname);

        // Where children do not enter by age, nothing of this.
        $centre->siblingage = 0;
        $this->assertSame([], siblings::due($centre, $this->now));
        $this->assertFalse(siblings::in_turn($centre, $m, $this->now));
    }

    /**
     * The page of the family: Sofía offered with a yes (a promise for the day her campaign starts), Mateo with a place
     * to keep; kept, he is one more who would come, asking for a place, and waits for the team.
     */
    public function test_family_page(): void {
        global $DB;
        [$centre, $household, $mateo, $sofia] = $this->family();
        $page = familypage::context($household, $centre, 'es', 'clave', false);
        $this->assertSame(['Mateo', 'Sofía'], array_column($page['siblings'], 'name'));
        $this->assertSame('Le tocaría en 2028-29', $page['siblings'][1]['line']);
        $this->assertSame('Le toca a Mateo', $page['turns'][0]['title']);
        $this->assertSame($sofia, $page['siblingoffers'][0]['id']);
        $this->assertSame('Cuando le toque a Sofía, ¿les avisamos?', $page['siblingoffers'][0]['question']);

        $this->assertTrue(siblings::notify($household, $centre, $sofia, $this->now));
        $this->assertFalse(siblings::notify($household, $centre, $sofia, $this->now));
        $promise = attend::open_promise((int) $household->id);
        $this->assertSame(make_timestamp(2027, 10, 1), (int) $promise->dueday);
        $this->assertStringContainsString('Sofía', $promise->body);

        $this->assertFalse(siblings::confirm($household, $centre, $sofia, $this->now), 'Not her turn yet.');
        $this->assertTrue(siblings::confirm($household, $centre, $mateo, $this->now));
        $this->assertFalse(siblings::confirm($household, $centre, $mateo, $this->now));
        $child = $DB->get_record(households::CHILDREN, ['householdid' => $household->id, 'firstname' => 'Mateo']);
        $this->assertSame('application', $child->stage);
        $this->assertGreaterThan(0, (int) $DB->get_field(households::T, 'waitingsince', ['id' => $household->id]));

        $page = familypage::context(households::get((int) $household->id, (int) $centre->id), $centre, 'es', 'clave', false);
        $this->assertSame([], $page['turns']);
        $this->assertSame(['Sofía'], array_column($page['siblings'], 'name'));
    }

    /**
     * The team: the proposal of Mateo with its why and the message written; gone once written to; and the inbox with
     * the households that have one, all or only them.
     */
    public function test_team(): void {
        [$centre, $household, $mateo] = $this->family();
        $full = households::full($household);
        $proposal = panel_household::sibling_proposal($household, $full, $centre, $this->now);
        $this->assertStringContainsString('Mateo', $proposal['sentence']);
        $this->assertStringContainsString('Mateo', $proposal['text']);
        $this->assertStringContainsString('hermano=' . $mateo, $proposal['url']);
        siblings::proposed($household, $mateo, $this->now);
        $this->assertFalse(panel_household::sibling_proposal($household, $full, $centre, $this->now));

        $other = households::get(
            households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']),
            (int) $centre->id
        );
        households::add_child($other, 'Vera', 0);
        $context = panel_inbox::context($centre, true, (int) get_admin()->id, true, [], $this->now);
        $this->assertNotFalse($context['siblingsdue']);
        $this->assertStringContainsString('2027-28', $context['siblingsdue']['text']);
        $only = panel_inbox::context($centre, false, (int) get_admin()->id, true, ['siblings' => 1], $this->now);
        $ids = array_merge(...array_map(fn($d) => array_column($d['rows'], 'id'), $only['days']));
        $this->assertSame([(string) $household->id], $ids);
    }

    /**
     * Told when signing up: who would come later, with a name and a year; one already known is not noted twice.
     */
    public function test_signup(): void {
        global $DB;
        [$centre] = $this->family();
        antispam::$testpass = true;
        $eventid = events::save((int) $centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada',
            'timestart' => time() + DAYSECS, 'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($eventid, (int) $centre->id);
        $data = [
            'adults' => [['firstname' => 'Inés', 'lastname' => 'Pardo', 'email' => 'ines@example.com', 'phone' => '']],
            'children' => [['firstname' => 'Hugo', 'tagname' => '', 'offerid' => 0]],
            'siblings' => [['firstname' => 'Lola', 'birthyear' => 2025], ['firstname' => '', 'birthyear' => 0],
                ['firstname' => 'Lola', 'birthyear' => 2025]],
            'people' => 2, 'slot' => 0, 'incentre' => '', 'municipality' => '', 'municipalityother' => '', 'heardfrom' => '',
            'message' => '', 'consents' => ['email' => 1, 'whatsapp' => 0, 'phone' => 0], 'altcha' => 'x',
        ];
        $this->assertSame([], signup::check($data, $event, $centre, 'es'));
        [$household] = signup::save($data, $event, $centre, 'es');
        $kept = siblings::of((int) $household->id);
        $this->assertCount(1, $kept);
        $this->assertSame('Lola', $kept[0]->firstname);
        $this->assertSame('signup', $kept[0]->source);
        $this->assertSame(0, $DB->count_records(households::CHILDREN, ['householdid' => $household->id, 'firstname' => 'Lola']));
        antispam::$testpass = null;
    }
}
