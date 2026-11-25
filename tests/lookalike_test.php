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
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\lookalike;

/**
 * The same family written down twice: the mother on one side and the father on the other.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\lookalike
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lookalike::class)]
final class lookalike_test extends \advanced_testcase {
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
     * A household with one adult and one child.
     *
     * @param string $adult
     * @param string $email
     * @param string $child
     * @param string $birthdate
     * @param int $offerid
     * @return \stdClass
     */
    protected function family(string $adult, string $email, string $child, string $birthdate = '', int $offerid = 0): \stdClass {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        [$first, $last] = explode(' ', $adult, 2);
        households::add_adult($household, (object) ['firstname' => $first, 'lastname' => $last, 'email' => $email,
            'phone' => null]);
        $childid = households::add_child($household, $child, $offerid);
        if ($birthdate !== '') {
            households::set_birthdate($household, $childid, $birthdate);
        }
        return households::get($id, (int) $this->centre->id);
    }

    /**
     * The task compares ahead and keeps the pairs, so the inbox reads them ready (find_lookalikes).
     */
    public function test_task_keeps_pairs(): void {
        $mother = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $father = $this->family('Juan Pérez', 'juan@example.com', 'Lucia', '2024-05-10');
        lookalike::forget((int) $this->centre->id);
        (new \local_oksigeniaeguahe\task\find_lookalikes())->execute();
        $kept = \cache::make('local_oksigeniaeguahe', 'lookalikes')->get((int) $this->centre->id);
        $this->assertSame([[(int) $father->id, (int) $mother->id]], $kept['pairs']);
    }

    /**
     * Joined, two people who share the phone of the home stay two, with what each one said; the same person is one.
     */
    public function test_phone_of_the_home(): void {
        global $DB;
        $mother = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $father = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10');
        $DB->set_field(households::ADULTS, 'phone', '+34922000111', ['centreid' => $this->centre->id]);
        $kept = lookalike::merge($mother, $father, 2);
        $names = array_map(fn($a) => $a->firstname, array_values(households::full($kept)->adults));
        $this->assertSame(['Ana', 'Juan'], $names);
        $again = $this->family('Ana López', 'otra@example.com', 'Lucía', '2024-05-10');
        $DB->set_field(households::ADULTS, 'phone', '+34922000111', ['householdid' => $again->id]);
        $kept = lookalike::merge($kept, $again, 2);
        $this->assertCount(2, households::full($kept)->adults);
    }

    /**
     * The same child with the same birthday, or asking for the same course, looks alike; two birthdays are two children;
     * classmates already enrolled never do; and what a person said is kept.
     */
    public function test_found(): void {
        $mother = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $father = $this->family('Juan Pérez', 'juan@example.com', 'Lucia', '2024-05-10');
        $other = $this->family('Rosa Díaz', 'rosa@example.com', 'Lucía', '2024-09-01');
        $found = lookalike::of($father);
        $this->assertSame([(int) $mother->id], array_keys($found));
        $this->assertSame('birthdate', $found[$mother->id]['why']);
        $this->assertSame([], lookalike::of($other), 'Another birthday is another child.');

        // The same course, near in time and without birthdays: with a surname in common; two Mateos of other families
        // for the same course are not one.
        $one = $this->family('Eva Ruiz', 'eva@example.com', 'Mateo', '', $this->infantil);
        $two = $this->family('Luis Ruiz Gil', 'luis@example.com', 'Mateo', '', $this->infantil);
        $three = $this->family('Ana Soto', 'soto@example.com', 'Mateo', '', $this->infantil);
        $this->assertSame('course', lookalike::of($two)[$one->id]['why']);
        $this->assertArrayNotHasKey((int) $three->id, lookalike::of($two), 'The same course alone is not enough.');
        // Two classmates of the same name already enrolled are not one.
        $children = households::full($two)->children;
        households::set_stage(reset($children), 'enrolled');
        $this->assertSame([], lookalike::of(households::get((int) $two->id, (int) $this->centre->id)));

        // In the inbox, from the families of the last half year.
        $this->assertSame([[(int) $father->id, (int) $mother->id]], lookalike::pairs((int) $this->centre->id, 0));

        // Its name with its surnames, in another order and from another adult: the same child; a compound name is not that.
        $phone = $this->family('Marta Soler', 'marta@example.com', 'Nora Vidal Cano', '2024-01-02', $this->infantil);
        $ad = $this->family('Pau Vidal', 'pau@example.com', 'Vidal Cano Nora', '', $this->infantil);
        $this->assertSame('course', lookalike::of($ad)[$phone->id]['why']);
        $aunt = $this->family('Sara Gil', 'sara@example.com', 'María del Carmen', '', $this->infantil);
        $this->family('Leo Prat', 'leo@example.com', 'María del Carmen', '', $this->infantil);
        $this->assertSame([], lookalike::of($aunt));
        $children = households::full($ad)->children;
        households::set_stage(reset($children), 'admitted');
        $kept = lookalike::merge($phone, households::get((int) $ad->id, (int) $this->centre->id), 2);
        $children = array_values(households::full($kept)->children);
        $this->assertCount(1, $children);
        $this->assertSame(['Nora Vidal Cano', 'admitted', '2024-01-02'], [$children[0]->firstname, $children[0]->stage,
            $children[0]->birthdate]);

        // A person says they are different: never asked again.
        lookalike::distinct((int) $this->centre->id, (int) $father->id, (int) $mother->id, 2);
        $this->assertSame([], lookalike::of($father));
        $this->assertSame([], lookalike::pairs((int) $this->centre->id, 0));
    }

    /**
     * Put together in the older one: both adults, the child once with what only the other knew, one sign-up for the
     * act with the most people and coming if either came, the conversation, and the links of both.
     */
    public function test_merge(): void {
        global $DB;
        $mother = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $this->waitForSecond();
        $father = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10', $this->infantil);
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada',
            'place' => 'Entrada', 'timestart' => time() + DAYSECS, 'timeend' => time() + DAYSECS + HOURSECS,
            'capacity' => 50, 'open' => 1]), (int) $this->centre->id);
        events::sign_up($event, (int) $mother->id, 2);
        events::sign_up($event, (int) $father->id, 3);
        $fatherlink = households::new_link($father, 'signup');
        households::received($father, 'webform', '¿Hay comedor?', true);

        // A letter to each for the same talk: the mother said no and, later, the father yes.
        $talk = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'other', 'name' => 'Charla',
            'timestart' => time() + 2 * DAYSECS, 'timeend' => time() + 2 * DAYSECS + HOURSECS, 'capacity' => 0,
            'open' => 1, 'free' => 2]), (int) $this->centre->id);
        invites::reply($talk, $mother, 'no', 0, time() - HOURSECS);
        invites::reply($talk, $father, 'yes', 2);

        $kept = lookalike::merge($father, $mother, 7);
        $this->assertSame((int) $mother->id, (int) $kept->id, 'The older one stays.');
        $this->assertSame(1, $DB->count_records(invites::T, ['eventid' => $talk->id]));
        $this->assertSame('yes', invites::answer((int) $talk->id, (int) $kept->id));
        $this->assertFalse($DB->record_exists(households::T, ['id' => $father->id]));
        $full = households::full($kept);
        $this->assertCount(2, $full->adults);
        $this->assertCount(1, $full->children, 'The same child, once.');
        $this->assertSame('2024-05-10', reset($full->children)->birthdate);
        $signups = $DB->get_records(households::SIGNUPS, ['eventid' => $event->id]);
        $this->assertCount(1, $signups);
        $this->assertSame(3, (int) reset($signups)->people);
        $this->assertSame((int) $kept->id, (int) households::by_link($fatherlink)->id, 'The old link opens the same page.');
        $bodies = $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$kept->id]);
        $this->assertContains('¿Hay comedor?', $bodies);
        $this->assertNotEmpty(array_filter($bodies, fn($b) => str_contains($b, 'Juan Pérez')));
        $this->assertGreaterThan(0, (int) $kept->waitingsince, 'It waits, as the other one did.');
        $this->assertSame([], lookalike::of($kept));
    }

    /**
     * Put together by mistake: separated again as they were, with what came from the other one marked, its links and
     * its sign-up; and two families joined by a shared phone, separated by hand.
     */
    public function test_split(): void {
        global $DB;
        $mother = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $this->waitForSecond();
        $father = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10', $this->infantil);
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada',
            'place' => 'Entrada', 'timestart' => time() + DAYSECS, 'timeend' => time() + DAYSECS + HOURSECS,
            'capacity' => 50, 'open' => 1]), (int) $this->centre->id);
        events::sign_up($event, (int) $mother->id, 2);
        events::sign_up($event, (int) $father->id, 3);
        $fatherlink = households::new_link($father, 'signup');
        households::received($father, 'webform', '¿Hay comedor?', true);
        $kept = lookalike::merge($mother, $father, 7);

        // The form comes marked with what came from the other one.
        $context = lookalike::split_context($kept, households::full($kept), true);
        $this->assertNotEmpty($context['fromlog']);
        $going = array_values(array_filter($context['adults'], fn($a) => $a['checked']));
        $this->assertSame(['Juan Pérez'], array_column($going, 'name'));
        $both = array_filter($context['children'][0]['options'], fn($o) => $o['selected']);
        $this->assertSame('both', reset($both)['value']);
        $this->assertSame('both', array_column(
            array_filter($context['signups'][0]['options'], fn($o) => $o['selected']),
            'value'
        )[0]);
        $lines = array_column(array_filter($context['lines'], fn($l) => $l['checked']), 'id');
        $this->assertCount(1, $lines);

        $new = lookalike::split($kept, ['adults' => array_column($going, 'id'),
            'children' => [$context['children'][0]['id'] => 'both'], 'signups' => [$event->id => 'both'],
            'contacts' => $lines, 'log' => $context['logid']], 7);
        $this->assertSame((int) $father->timecreated, (int) $new->timecreated, 'When it came, as it was.');
        $newfull = households::full($new);
        $this->assertSame(['juan@example.com'], array_values(array_column($newfull->adults, 'email')));
        $this->assertSame('2024-05-10', reset($newfull->children)->birthdate);
        $this->assertCount(1, households::full(households::get((int) $kept->id, (int) $this->centre->id))->children);
        $this->assertSame(3, (int) $DB->get_field(households::SIGNUPS, 'people', ['eventid' => $event->id,
            'householdid' => $new->id]));
        $this->assertSame((int) $new->id, (int) households::by_link($fatherlink)->id, 'Its link opens its own page again.');
        $this->assertGreaterThan(0, (int) $new->waitingsince);
        $this->assertSame([], lookalike::of($new), 'Two families now: not asked again.');
        $this->assertFalse($DB->record_exists(lookalike::LOG, ['householdid' => $kept->id]));

        // Joined by a shared phone, with no log: separated by hand, and the links sent since stop working.
        $one = $this->family('Rosa Díaz', 'rosa@example.com', 'Pablo');
        $this->waitForSecond();
        households::add_adult($one, (object) ['firstname' => 'Marta', 'lastname' => 'Gil', 'email' => 'marta@example.com',
            'phone' => null]);
        $sent = households::new_link($one, 'reply');
        $marta = $DB->get_field(households::ADULTS, 'id', ['email' => 'marta@example.com']);
        $other = lookalike::split($one, ['adults' => [$marta], 'children' => [], 'signups' => [], 'contacts' => []], 7);
        $this->assertSame(1, $DB->count_records(households::ADULTS, ['householdid' => $other->id]));
        $this->assertNull(households::by_link($sent));
        try {
            lookalike::split($other, ['adults' => [$marta]], 7);
            $this->fail('A family cannot be left without adults.');
        } catch (\moodle_exception $e) {
            $this->assertSame('lookalike_split_adults_error', $e->errorcode);
        }
    }
}
