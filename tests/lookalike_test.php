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

    /**
     * The household as it is now.
     *
     * @param \stdClass|int $household
     * @return \stdClass|null
     */
    protected function now($household): ?\stdClass {
        return households::get(is_int($household) ? $household : (int) $household->id, 0);
    }

    /**
     * Everything of a household that a merge could lose, to compare it.
     *
     * @param int $id
     * @return array
     */
    protected function picture(int $id): array {
        global $DB;
        $out = ['household' => array_diff_key((array) $DB->get_record(households::T, ['id' => $id]), ['timemodified' => 1])];
        foreach (
            [households::ADULTS, households::CHILDREN, households::CONSENTS, households::SIGNUPS, \local_oksigeniaeguahe\local\siblings::T,
                \local_oksigeniaeguahe\local\hobbies::T, \local_oksigeniaeguahe\local\alumni::T, \local_oksigeniaeguahe\local\ties::T,
                \local_oksigeniaeguahe\local\visits::REQUESTS, \local_oksigeniaeguahe\local\attend::PROMISES,
                \local_oksigeniaeguahe\local\listmatch::T, \local_oksigeniaeguahe\local\surveys::T, invites::T,
                \local_oksigeniaeguahe\local\renewal::T] as $table
        ) {
            $out[$table] = array_map(
                fn($row) => array_diff_key((array) $row, ['timemodified' => 1]),
                $DB->get_records($table, ['householdid' => $id], 'id ASC')
            );
        }
        return $out;
    }

    /**
     * Two files of one person, put together by mistake and taken back: each exactly as it was, also with only one adult.
     */
    public function test_undo_exactly(): void {
        global $DB;
        $centreid = (int) $this->centre->id;
        $primaria = centres::save_offer($centreid, (object) ['schoolyear' => '2028-29', 'name' => '1.º de Primaria', 'places' => 25, 'active' => 1]);
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $this->waitForSecond();
        $two = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10', $primaria);
        $adults = [];
        foreach ([$one, $two] as $n => $household) {
            $full = households::full($household);
            $adult = reset($full->adults);
            $adults[] = (int) $adult->id;
            $child = reset($full->children);
            households::consent($household, (int) $adult->id, 'email', $n === 1, 'form', 'es');
            $event = events::get($event->id ?? events::save($centreid, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() - HOURSECS,
                'timeend' => time() + HOURSECS, 'capacity' => 0, 'open' => 1]), $centreid);
            $signup = events::sign_up($event, (int) $household->id, 2 + $n);
            \local_oksigeniaeguahe\local\siblings::add($household, 'Pau', (int) date('Y') - 1, 'team', time() + $n);
            $DB->insert_record(\local_oksigeniaeguahe\local\hobbies::T, (object) ['centreid' => $centreid, 'householdid' => $household->id,
                'adultid' => $adult->id, 'hobby' => 'music', 'timecreated' => time()]);
            \local_oksigeniaeguahe\local\alumni::set($household, (int) $adult->id, $n, 'team', 2);
            \local_oksigeniaeguahe\local\ties::set($household, (int) $child->id, 'student', true, 2);
            \local_oksigeniaeguahe\local\attend::promise($household, 2, 'Llamarles ' . $n, time() + DAYSECS);
            $DB->insert_record(\local_oksigeniaeguahe\local\listmatch::T, (object) ['centreid' => $centreid, 'householdid' => $household->id,
                'schoolyear' => '2027-28', 'student' => 'token', 'kind' => 'enrolled', 'grade' => 'Infantil', 'timein' => 1, 'childid' => $child->id,
                'done' => 1, 'marked' => 0, 'timecreated' => time()]);
            if ($n) {
                events::attend($event, $signup, true);
                $DB->set_field(events::SIGNUPS, 'missed', time(), ['eventid' => $event->id, 'householdid' => $one->id]);
                households::received($household, 'email', '¿Hay comedor?', true);
            }
        }
        $DB->update_record(households::T, (object) ['id' => $one->id, 'municipalityname' => 'Aquí', 'guest' => 1, 'heardfrom' => 'search']);
        $DB->update_record(households::T, (object) ['id' => $two->id, 'municipalityname' => 'Allí', 'noinvites' => 5, 'writeby' => 'email',
            'writebytime' => 7, 'writebyfrom' => 'family', 'heardfrom' => 'family']);
        $was = [$this->picture((int) $one->id), $this->picture((int) $two->id)];

        $kept = lookalike::merge($this->now($one), $this->now($two), 2);
        $full = households::full($kept);
        $this->assertCount(1, $full->adults);
        $this->assertCount(1, $full->children);
        $child = reset($full->children);
        $this->assertSame([$primaria, 'visit'], [(int) $child->offerid, $child->stage], 'The later year, with its own step.');
        $signup = $DB->get_record(households::SIGNUPS, ['householdid' => $kept->id]);
        $this->assertSame([true, 0, 3], [(int) $signup->attended > 0, (int) $signup->missed, (int) $signup->people]);
        $this->assertSame(1, $DB->count_records(\local_oksigeniaeguahe\local\siblings::T, ['householdid' => $kept->id]));
        $this->assertSame(1, $DB->count_records(\local_oksigeniaeguahe\local\listmatch::T, ['householdid' => $kept->id]));
        $this->assertSame((int) $child->id, (int) $DB->get_field(\local_oksigeniaeguahe\local\listmatch::T, 'childid', ['householdid' => $kept->id]));
        $this->assertSame(1, $DB->count_records(\local_oksigeniaeguahe\local\ties::T, ['householdid' => $kept->id, 'childid' => $child->id]));
        $note = implode("\n", $DB->get_fieldset_select(households::CONTACTS, 'body', "householdid = ? AND direction = 'note'", [$kept->id]));
        $this->assertStringContainsString('Allí', $note, 'What could not be there twice stays in the history.');
        $this->assertSame((int) $kept->id, lookalike::now_in((int) $two->id), 'The old file leads to this one.');

        $context = lookalike::split_context($kept, households::full($kept), true);
        $this->assertNotFalse($context, 'With one adult too: she was in both.');
        $picked = fn(array $row) => array_values(array_filter($row['options'], fn($o) => $o['selected']))[0]['value'];
        $this->assertSame('both', $picked($context['adults'][0]));
        $new = lookalike::split($kept, [
            'both' => [$context['adults'][0]['id']],
            'children' => array_column(array_map(fn($r) => [$r['id'], $picked($r)], $context['children']), 1, 0),
            'signups' => array_column(array_map(fn($r) => [$r['eventid'], $picked($r)], $context['signups']), 1, 0),
            'contacts' => array_column(array_filter($context['lines'], fn($l) => $l['checked']), 'id'),
            'things' => array_column(array_map(fn($r) => [$r['key'], $picked($r)], $context['things']), 1, 0),
            'log' => $context['logid']], 2);
        $this->assertSame((int) $two->id, (int) $new->id, 'With the number it had.');
        $this->assertEquals($was[0], $this->picture((int) $one->id));
        $this->assertEquals($was[1], $this->picture((int) $two->id));
        $this->assertSame(0, lookalike::now_in((int) $two->id));
    }

    /**
     * What was said after putting them together is not undone with it, and is of both: not to be written to, nor invited.
     */
    public function test_said_since(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $this->waitForSecond();
        $two = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10');
        $kept = lookalike::merge($one, $two, 2);
        \local_oksigeniaeguahe\local\writeby::set($kept, [], 'family');
        invites::wanted($kept, false, 'family');
        $juan = (int) $DB->get_field(households::ADULTS, 'id', ['email' => 'juan@example.com']);
        $new = lookalike::split($this->now($kept), ['adults' => [$juan], 'log' => (int) $DB->get_field(lookalike::LOG, 'id', ['householdid' => $kept->id])], 2);
        foreach ([$new, $this->now($kept)] as $household) {
            $this->assertSame('none', $household->writeby);
            $this->assertGreaterThan(0, (int) $household->noinvites);
        }
        // And separating by hand a file that never was two.
        $three = $this->family('Rosa Díaz', 'rosa@example.com', 'Pablo');
        $marta = households::add_adult($three, (object) ['firstname' => 'Marta', 'email' => 'marta@example.com']);
        invites::wanted($three, false, 'family');
        \local_oksigeniaeguahe\local\ties::set($three, 0, 'sibling', true, 2);
        $other = lookalike::split($this->now($three), ['adults' => [$marta]], 2);
        $this->assertGreaterThan(0, (int) $other->noinvites);
        $this->assertSame(1, (int) $other->incentre, 'What was marked of the whole family is of both.');
        $this->assertTrue(\local_oksigeniaeguahe\local\ties::any((int) $other->id));
    }

    /**
     * A mark of the child that goes is with it, and the family that stays is no longer of the centre because of it.
     */
    public function test_marks_go_with_the_child(): void {
        global $DB;
        $family = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $luis = households::add_adult($family, (object) ['firstname' => 'Luis', 'email' => 'luis@example.com']);
        $leo = households::add_child($family, 'Leo', $this->infantil);
        \local_oksigeniaeguahe\local\ties::set($family, $leo, 'nursery', true, 2);
        $new = lookalike::split($this->now($family), ['adults' => [$luis], 'children' => [$leo => 'go']], 2);
        $this->assertSame([1, 0], [(int) $new->incentre, (int) $this->now($family)->incentre]);
        $this->assertSame(1, $DB->count_records(\local_oksigeniaeguahe\local\ties::T, ['householdid' => $new->id, 'childid' => $leo, 'made' => 1]));
        // What the family said of itself, with no mark, stays in both.
        $said = $this->family('Rosa Díaz', 'rosa@example.com', 'Pablo');
        $DB->set_field(households::T, 'incentre', 1, ['id' => $said->id]);
        $marta = households::add_adult($said, (object) ['firstname' => 'Marta', 'email' => 'marta@example.com']);
        $other = lookalike::split($this->now($said), ['adults' => [$marta]], 2);
        $this->assertSame([1, 1], [(int) $other->incentre, (int) $this->now($said)->incentre]);
    }

    /**
     * A «yes» to be written to at an address is never of another address; a «no» is of the person.
     */
    public function test_yes_of_an_address(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $two = $this->family('Ana López', 'otra@example.com', 'Lucía', '2024-05-10');
        $DB->set_field(households::ADULTS, 'phone', '+34922000111', ['centreid' => $this->centre->id]);
        $adult = fn($h) => (int) $DB->get_field(households::ADULTS, 'id', ['householdid' => $h->id]);
        households::consent($one, $adult($one), 'email', false, 'form', 'es');
        $this->waitForSecond();
        households::consent($two, $adult($two), 'email', true, 'form', 'es');
        households::consent($two, $adult($two), 'phone', true, 'form', 'es');
        $kept = lookalike::merge($one, $two, 2);
        $now = households::permissions((int) $kept->id)[$adult($kept)];
        $this->assertSame(['email' => false, 'phone' => true], $now);
        $bodies = implode("\n", $DB->get_fieldset_select(households::CONTACTS, 'body', 'householdid = ?', [$kept->id]));
        $this->assertStringContainsString('otra@example.com', $bodies);
        // Two without a name that write from different addresses are two.
        $three = $this->family('Eva Gil', 'eva@example.com', 'Noa', '2024-01-01');
        $four = $this->family('Eva Gil', 'luis@example.com', 'Noa', '2024-01-01');
        $DB->set_field(households::ADULTS, 'phone', '+34922000222', ['householdid' => $three->id]);
        $DB->update_record(households::ADULTS, (object) ['id' => $adult($four), 'phone' => '+34922000222', 'firstname' => '', 'lastname' => null]);
        $this->assertCount(2, households::full(lookalike::merge($three, $four, 2))->adults);
    }

    /**
     * Two that chose different ways are written to by both: never «they want no messages».
     */
    public function test_ways_of_both(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $two = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10');
        $DB->set_field(households::T, 'writeby', 'whatsapp', ['id' => $one->id]);
        $DB->set_field(households::T, 'writeby', 'email', ['id' => $two->id]);
        $kept = lookalike::merge($this->now($one), $this->now($two), 2);
        $this->assertSame('email,whatsapp', $kept->writeby);
        $three = $this->family('Eva Gil', 'eva@example.com', 'Lucía', '2024-05-10');
        $DB->set_field(households::T, 'writeby', 'none', ['id' => $three->id]);
        $this->assertSame('none', lookalike::merge($kept, $this->now($three), 2)->writeby);
    }

    /**
     * One brought from the roll never hides the one that came to the admissions, nor a family that asks for another child.
     */
    public function test_roll(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $two = $this->family('Ana López', 'ana@example.com', 'Pablo', '');
        $DB->update_record(households::T, (object) ['id' => $two->id, 'roster' => time(), 'incentre' => 1]);
        $kept = lookalike::merge($one, $this->now($two), 2);
        $this->assertSame(0, (int) $kept->roster);
        $this->assertContains((int) $kept->id, array_keys(households::inbox((int) $this->centre->id, false)));
        $roll = $this->family('Rosa Díaz', 'rosa@example.com', 'Nora', '', $this->infantil);
        $DB->update_record(households::T, (object) ['id' => $roll->id, 'roster' => time(), 'incentre' => 1]);
        $this->assertContains((int) $roll->id, array_keys(households::inbox((int) $this->centre->id, false)));
        $DB->set_field(households::CHILDREN, 'stage', 'enrolled', ['householdid' => $roll->id]);
        $this->assertNotContains((int) $roll->id, array_keys(households::inbox((int) $this->centre->id, false)));
    }

    /**
     * Of the same child, the course and the step go together; one that left stays so unless it asks again since; and one
     * already enrolled that asks for a later year stays two.
     */
    public function test_one_way(): void {
        global $DB;
        $centreid = (int) $this->centre->id;
        $later = centres::save_offer($centreid, (object) ['schoolyear' => '2028-29', 'name' => '1.º de Primaria', 'places' => 25, 'active' => 1]);
        $way = function (array $a, array $b) use ($DB): array {
            $families = [];
            foreach ([$a, $b] as $n => $fields) {
                $families[$n] = $this->family('Ana López', 'ana@example.com', 'Lucía');
                $DB->set_field_select(households::CHILDREN, 'stagetime', time(), 'householdid = ?', [$families[$n]->id]);
                $child = $DB->get_record(households::CHILDREN, ['householdid' => $families[$n]->id]);
                $DB->update_record(households::CHILDREN, (object) (['id' => $child->id] + $fields));
            }
            $kept = lookalike::merge($families[0], $families[1], 2);
            $out = array_map(
                fn($k) => [(int) $k->offerid, $k->stage, (int) $k->lefttime > 0],
                array_values(households::full($kept)->children)
            );
            households::erase($this->now($kept));
            return $out;
        };
        $this->assertSame([[$later, 'inquiry', false]], $way(
            ['offerid' => $this->infantil, 'stage' => 'closed', 'closedreason' => 'noplace'],
            ['offerid' => $later, 'stage' => 'inquiry']
        ));
        $this->assertSame([[$this->infantil, 'enrolled', true]], $way(
            ['offerid' => $this->infantil, 'stage' => 'inquiry', 'stagetime' => time() - YEARSECS, 'timecreated' => time() - YEARSECS],
            ['offerid' => $this->infantil, 'stage' => 'enrolled', 'lefttime' => time() - DAYSECS, 'leftreason' => 'moved']
        ));
        $this->assertSame([[$later, 'inquiry', false]], $way(
            ['offerid' => $this->infantil, 'stage' => 'enrolled', 'lefttime' => time() - YEARSECS, 'leftreason' => 'moved'],
            ['offerid' => $later, 'stage' => 'inquiry']
        ));
        $this->assertSame([[$this->infantil, 'enrolled', false], [$later, 'application', false]], $way(
            ['offerid' => $this->infantil, 'stage' => 'enrolled'],
            ['offerid' => $later, 'stage' => 'application']
        ));
        // The one without a name, of an ad, and the same one with it.
        $this->assertSame([[$this->infantil, 'application', false]], $way(
            ['firstname' => '', 'offerid' => $this->infantil, 'stage' => 'inquiry'],
            ['offerid' => $this->infantil, 'stage' => 'application']
        ));
    }

    /**
     * A mark that came with the other file never takes out what the family had said of itself.
     */
    public function test_said_of_itself(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $DB->set_field(households::T, 'incentre', 1, ['id' => $one->id]);
        $two = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10');
        $child = (int) $DB->get_field(households::CHILDREN, 'id', ['householdid' => $two->id]);
        \local_oksigeniaeguahe\local\ties::set($two, $child, 'student', true, 2);
        $kept = lookalike::merge($this->now($one), $this->now($two), 2);
        $child = (int) $DB->get_field(households::CHILDREN, 'id', ['householdid' => $kept->id]);
        \local_oksigeniaeguahe\local\ties::set($kept, $child, 'student', false, 2);
        $this->assertSame(1, (int) $this->now($kept)->incentre);
    }

    /**
     * Separating takes the visit with whoever goes, what it asked for and what it was promised; and nobody stops waiting, nor
     * starts to.
     */
    public function test_split_whole(): void {
        global $DB;
        $family = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $luis = households::add_adult($family, (object) ['firstname' => 'Luis', 'email' => 'luis@example.com']);
        $event = \local_oksigeniaeguahe\local\visits::propose($family, time() + DAYSECS, 45, 0, '', 2);
        $DB->insert_record(\local_oksigeniaeguahe\local\visits::REQUESTS, (object) ['centreid' => $this->centre->id, 'householdid' => $family->id,
            'eventid' => 1, 'people' => 2, 'status' => 'scheduled', 'visitid' => $event->id, 'timecreated' => 1, 'timemodified' => 1]);
        $promise = \local_oksigeniaeguahe\local\attend::promise($family, 2, 'Llamar a Luis', time());
        households::received($this->now($family), 'openday', 'Inscripción a «Jornada»', false, time() - 5 * HOURSECS);
        households::received($this->now($family), \local_oksigeniaeguahe\local\lists::PAGE, '¿Hay plaza?', true, time() - 4 * HOURSECS, 0, 'settled');
        $line = (int) $DB->get_field(households::CONTACTS, 'id', ['householdid' => $family->id, 'channel' => 'page']);
        $new = lookalike::split($this->now($family), ['adults' => [$luis], 'signups' => [$event->id => 'go'], 'contacts' => [$line],
            'things' => ['p' . $promise => 'go']], 2);
        $this->assertSame((int) $new->id, (int) $DB->get_field(events::T, 'householdid', ['id' => $event->id]));
        $this->assertSame((int) $new->id, (int) $DB->get_field(\local_oksigeniaeguahe\local\visits::REQUESTS, 'householdid', ['visitid' => $event->id]));
        $this->assertTrue(\local_oksigeniaeguahe\local\appointments::off($new, $this->centre, (int) $event->id, '', 2, false));
        $this->assertSame((int) $new->id, (int) $DB->get_field(\local_oksigeniaeguahe\local\attend::PROMISES, 'householdid', ['id' => $promise]));
        $this->assertGreaterThan(0, (int) $new->waitingsince, 'The question of its page still waits.');
        $this->assertSame(0, (int) $this->now($family)->waitingsince, 'Signing up to an open day asks nothing.');
        // A visit of its own is of one family, never in both.
        $context = lookalike::split_context($this->now($family), households::full($this->now($family)), true);
        $this->assertFalse($context, 'One adult, never put together: there is nothing to separate.');
    }

    /**
     * What is kept of a family put together goes with it when it erases its data, whatever was put together after.
     */
    public function test_log_goes_with_the_family(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $this->waitForSecond();
        $two = $this->family('Ana López', 'ana@example.com', 'Lucía', '2024-05-10');
        $this->waitForSecond();
        $three = $this->family('Juan Pérez', 'juan@example.com', 'Lucía', '2024-05-10');
        lookalike::merge($two, $three, 2);
        $kept = lookalike::merge($one, $this->now($two), 2);
        $this->assertSame(2, $DB->count_records(lookalike::LOG, ['householdid' => $kept->id]));
        $this->assertSame((int) $kept->id, lookalike::now_in((int) $three->id));
        // Taken back, the one before goes with the one it was of.
        $log = (int) $DB->get_field(lookalike::LOG, 'id', ['goneid' => $two->id]);
        $ana = (int) $DB->get_field(households::ADULTS, 'id', ['email' => 'ana@example.com']);
        $juan = (int) $DB->get_field(households::ADULTS, 'id', ['email' => 'juan@example.com']);
        $new = lookalike::split($kept, ['adults' => [$juan], 'both' => [$ana], 'log' => $log], 2);
        $this->assertSame((int) $new->id, (int) $DB->get_field(lookalike::LOG, 'householdid', ['goneid' => $three->id]));
        households::erase($this->now((int) $new->id));
        $this->assertSame(0, $DB->count_records(lookalike::LOG));
        // And of a file that is no longer there, nothing.
        $DB->insert_record(lookalike::LOG, (object) ['centreid' => $this->centre->id, 'householdid' => 999999, 'data' => '{}', 'timecreated' => 1]);
        households::tidy(time());
        $this->assertSame(0, $DB->count_records(lookalike::LOG));
    }

    /**
     * Two with the same email are proposed; the letters on their way follow the one that stays; a person sees before what it does.
     */
    public function test_same_email_and_letters(): void {
        global $DB;
        $one = $this->family('Ana López', 'ana@example.com', 'Lucía', '', $this->infantil);
        $this->waitForSecond();
        $two = $this->family('Ana Gil', 'ana2@example.com', 'Pablo');
        $DB->set_field(households::ADULTS, 'email', 'ana@example.com', ['householdid' => $two->id]);
        $this->assertSame('email', lookalike::of($two)[$one->id]['why']);
        $this->assertSame([[(int) $two->id, (int) $one->id]], lookalike::refresh((int) $this->centre->id, 0));
        $task = new \local_oksigeniaeguahe\task\send_invites();
        $task->set_custom_data(['eventid' => 1, 'centreid' => 1, 'userid' => 2, 'ids' => [(int) $two->id, 77], 'words' => '', 'centre' => [(int) $two->id]]);
        \core\task\manager::queue_adhoc_task($task);
        \local_oksigeniaeguahe\local\visits::propose($one, time() + DAYSECS, 45, 0, '', 2);
        \local_oksigeniaeguahe\local\visits::propose($two, time() + 2 * DAYSECS, 45, 0, '', 2);
        $DB->set_field(households::T, 'noinvites', 5, ['id' => $two->id]);
        $this->assertCount(3, lookalike::preview($this->now($one), $this->now($two)), 'The adult, two visits, and that one is not invited.');
        $kept = lookalike::merge($one, $this->now($two), 2);
        $tasks = \core\task\manager::get_adhoc_tasks(\local_oksigeniaeguahe\task\send_invites::class);
        $data = reset($tasks)->get_custom_data();
        $this->assertEquals([(int) $kept->id, 77], $data->ids);
        $this->assertEquals([(int) $kept->id], $data->centre);
        $this->assertCount(1, \local_oksigeniaeguahe\local\appointments::twins($kept), 'Two visits to come: a person says which.');
        // Two that asked for a day are one, the last.
        $asks = fn(int $id, int $time) => $DB->insert_record(\local_oksigeniaeguahe\local\visits::REQUESTS, (object) ['centreid' => $this->centre->id,
            'householdid' => $id, 'eventid' => 1, 'people' => 1, 'status' => 'pending', 'visitid' => 0, 'timecreated' => $time, 'timemodified' => $time]);
        $three = $this->family('Eva Gil', 'eva@example.com', 'Noa', '2024-01-01');
        $four = $this->family('Luis Gil', 'luis@example.com', 'Noa', '2024-01-01');
        $asks((int) $three->id, 10);
        $last = $asks((int) $four->id, 20);
        $kept = lookalike::merge($three, $four, 2);
        $this->assertSame((int) $last, (int) \local_oksigeniaeguahe\local\visits::pending((int) $kept->id)->id);
        $this->assertSame(1, $DB->count_records(\local_oksigeniaeguahe\local\visits::REQUESTS, ['householdid' => $kept->id, 'status' => 'pending']));
    }

    /**
     * The same child without its second surname is recognised by its birthday; and any other file is found by hand.
     */
    public function test_written_shorter(): void {
        $one = $this->family('Rosa Hernández', 'rosa@example.com', 'José Manuel Hernández Gil', '2021-05-05');
        $two = $this->family('Pepe Gil', 'pepe@example.com', 'Jose Manuel Hernandez', '2021-05-05');
        $other = $this->family('Eva Soto', 'eva@example.com', 'Jose Manuel', '2021-06-06');
        $this->assertSame('birthdate', lookalike::of($two)[$one->id]['why']);
        $this->assertArrayNotHasKey((int) $other->id, lookalike::of($two));
        $this->assertCount(1, lookalike::refresh((int) $this->centre->id, 0));
        $found = lookalike::by_hand($two, 'soto');
        $this->assertSame([(int) $other->id], array_column($found['found'], 'id'));
        $this->assertSame([], lookalike::by_hand($two, 'pepe')['found'], 'Never itself.');
        $this->assertNotSame('', lookalike::by_hand($two, 'nadie')['none']);
    }
}
