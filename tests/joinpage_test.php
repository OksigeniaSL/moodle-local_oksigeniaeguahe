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

use local_oksigeniaeguahe\local\acts;
use local_oksigeniaeguahe\local\brief;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\joinpage;
use local_oksigeniaeguahe\local\knownpage;
use local_oksigeniaeguahe\local\signup;
use local_oksigeniaeguahe\local\writeby;

/**
 * Tests for the one-screen page of a sign-up and for what whoever comes by a letter says.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\joinpage
 */
#[\PHPUnit\Framework\Attributes\CoversClass(joinpage::class)]
final class joinpage_test extends \advanced_testcase {
    /**
     * An open act at a centre.
     *
     * @param int $free
     * @param int $capacity
     * @return array centre, event
     */
    protected function act(int $free = acts::FREE_ASKS, int $capacity = 0): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $start = time() + 8 * DAYSECS;
        $id = events::save($centreid, (object) ['kind' => 'other', 'word' => 'talk', 'name' => 'Respirar y atender', 'timestart' => $start,
            'timeend' => $start + HOURSECS, 'capacity' => $capacity, 'open' => 1, 'told' => ['free' => $free,
                'topic' => 'respiración y atención: si respirar ayuda a atender.']]);
        return [centres::get($centreid), events::get($id, $centreid)];
    }

    /**
     * A family of the admissions, with its adults.
     *
     * @param \stdClass $centre
     * @param int $adults
     * @return \stdClass
     */
    protected function family(\stdClass $centre, int $adults = 2): \stdClass {
        $id = households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $centre->id);
        for ($i = 0; $i < $adults; $i++) {
            $adult = households::add_adult($household, (object) ['firstname' => ['Ana', 'Luis'][$i],
                'email' => "adulto$i.$id@example.com"]);
            households::consent($household, $adult, 'email', true, 'signup', 'es');
        }
        return households::get($id, (int) $centre->id);
    }

    /**
     * The page of an act: what it is, once, and a form that asks only what is needed.
     */
    public function test_context(): void {
        [$centre, $event] = $this->act();
        $this->assertSame('respiración y atención: si respirar ayuda a atender', $event->topic);
        $this->assertSame('respiración y atención', acts::short_topic($event));
        $data = signup::read($centre);
        $url = new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token]);
        $page = joinpage::context($event, $centre, 'es', $data, [], $url, events::mark($event));
        $this->assertSame('Charla', $page['kicker']);
        $this->assertFalse($page['pegs'], 'Nothing hangs of a talk.');
        $this->assertTrue($page['asks']);
        $this->assertStringStartsWith('Entrada libre. Si nos dicen', $page['freetext']);
        $this->assertSame(['a0first', 'a0phone', 'a0email', 'message'], array_column($page['form']['fields'], 'name'));
        $this->assertSame('Avisar de que vamos', $page['form']['send']);
        $this->assertSame('people', $page['form']['people']['name']);
        $this->assertFalse($page['form']['tellme']['checked']);

        // An open day asks for the child and its course too, with its backpack on the rail.
        $day = events::get(events::save((int) $centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() + DAYSECS,
            'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $centre->id);
        $page = joinpage::context($day, $centre, 'es', $data, [], $url, events::mark($day));
        $this->assertContains('c0name', array_column($page['form']['fields'], 'name'));
        $this->assertNotFalse($page['pegs']);
        $this->assertFalse($page['form']['tellme']);
    }

    /**
     * Whoever comes by the letter says whether they come with one touch; how many and «not this time» change it, and take
     * away no permission.
     */
    public function test_reply(): void {
        global $DB;
        [$centre, $event] = $this->act(acts::FREE_ASKS, 3);
        $family = $this->family($centre);
        $signup = fn() => (int) $DB->get_field(events::SIGNUPS, 'people', ['eventid' => $event->id, 'householdid' => $family->id]);
        invites::opened($event, $family);
        $this->assertFalse($DB->record_exists(invites::T, ['householdid' => $family->id]), 'It was not invited.');
        $DB->insert_record(invites::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id, 'householdid' => $family->id,
            'userid' => 0, 'channel' => 'email', 'bulkid' => 0, 'timecreated' => time()]);
        $this->assertCount(1, invites::replies((int) $event->id)['none']);
        invites::opened($event, $family);
        $this->assertCount(1, invites::replies((int) $event->id)['opened']);

        $this->assertTrue(invites::reply($event, $family, 'yes'));
        $this->assertSame(2, $signup(), 'As many as its adults, until it says.');
        $this->assertTrue(invites::reply($event, $family, 'yes', 3));
        $this->assertSame(3, $signup());
        $this->assertFalse(invites::reply($event, $family, 'yes', 4), 'There is no room.');
        $this->assertSame(3, $signup());
        // Said once in its history, and nobody has to answer it.
        $this->assertSame(1, $DB->count_records(households::CONTACTS, ['householdid' => $family->id]));
        $this->assertSame(0, (int) households::get((int) $family->id, (int) $centre->id)->waitingsince);
        $known = joinpage::known($event, $centre, $family, 'es', new \moodle_url('/local/oksigeniaeguahe/j.php'), 'x');
        $this->assertSame(['yes', true, 3], [$known['answer'], $known['coming'], $known['people']['value']]);
        $this->assertSame('Hola, Ana', $known['hello']);

        $this->assertTrue(invites::reply($event, $family, 'no'));
        $this->assertSame(0, $signup());
        $this->assertCount(1, invites::replies((int) $event->id)['no']);
        $this->assertSame('', (string) households::get((int) $family->id, (int) $centre->id)->writeby);
        $this->assertTrue(invites::reply($event, $family, 'yes', 1));
        $this->assertCount(1, invites::replies((int) $event->id)['yes']);
        // Closed, nothing is kept.
        $event->open = 0;
        $this->assertFalse(invites::reply($event, $family, 'no'));
        $this->assertSame(1, $signup());
    }

    /**
     * Signed up on the web, without any letter: how many come is kept when they change it on the page of the act.
     */
    public function test_people_without_a_letter(): void {
        global $DB;
        [$centre, $event] = $this->act();
        $family = $this->family($centre);
        events::sign_up($event, (int) $family->id, 2);
        $_POST = ['people' => '5', 'known' => '1'];
        $this->assertTrue(joinpage::said($event, $centre, $family));
        $_POST = [];
        $this->assertSame(5, (int) $DB->get_field(events::SIGNUPS, 'people', ['eventid' => $event->id, 'householdid' => $family->id]));
    }

    /**
     * What the page keeps of a letter: a signature for its act, that stops working with its link; never the link.
     */
    public function test_letter_of(): void {
        global $DB;
        [$centre, $event] = $this->act();
        $family = $this->family($centre);
        $token = households::new_link($family, 'letter');
        $kept = joinpage::letter_of($event, $token);
        $this->assertStringNotContainsString($token, $kept);
        $this->assertSame((int) $family->id, (int) joinpage::family_of($event, $kept)->id);
        $other = events::get(events::save((int) $centre->id, (object) ['kind' => 'other', 'name' => 'Otra', 'timestart' => time() + DAYSECS,
            'timeend' => time() + DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $centre->id);
        $this->assertNull(joinpage::family_of($other, $kept), 'Only for its act.');
        $this->assertNull(joinpage::family_of($event, substr($kept, 0, -1) . (substr($kept, -1) === 'a' ? 'b' : 'a')));
        $this->assertNull(joinpage::family_of($event, 'x'));
        $this->assertSame('', joinpage::letter_of($event, 'nada'));
        // Its page is answered a week later, and not after.
        $this->assertFalse(events::mark_ok($event, events::mark($event, time() - 2 * DAYSECS)));
        $this->assertTrue(events::mark_ok($event, events::mark($event, time() - 2 * DAYSECS), WEEKSECS));
        $this->assertFalse(events::mark_ok($event, events::mark($event, time() - 8 * DAYSECS), WEEKSECS));
        // An old link, or one that was renewed, opens nothing.
        $DB->set_field(households::LINKS, 'timecreated', time() - 200 * DAYSECS, ['householdid' => $family->id]);
        $DB->set_field(households::LINKS, 'timeused', 0, ['householdid' => $family->id]);
        $this->assertSame('', joinpage::letter_of($event, $token));
        households::renew_links($family);
        $this->assertNull(joinpage::family_of($event, $kept));
    }

    /**
     * «Vamos» answers an invitation: it takes back no «do not write to us», and its confirmation goes all the same.
     */
    public function test_yes_after_silence(): void {
        [$centre, $event] = $this->act();
        $family = $this->family($centre);
        $family->writeby = 'none';
        $family->writebytime = time() - 3 * DAYSECS;
        global $DB;
        $DB->update_record(households::T, $family);
        $this->assertTrue(invites::reply($event, $family, 'yes'));
        $now = households::get((int) $family->id, (int) $centre->id);
        $this->assertTrue(writeby::silent($now));
        $this->assertTrue(writeby::may_send($now, 'confirmation'));
        $this->assertFalse(writeby::may_send($now, 'reminder'));
        // If it is called off, or moved, they are told: they count on it.
        $this->assertTrue(writeby::may_send($now, 'cancelled'));
        $this->assertTrue(writeby::email_now($now, $centre, 'cancelled', true));
        $this->assertFalse(writeby::may_send($now, 'openinvite'));
    }

    /**
     * With one place left and two adults, one comes, instead of no at all.
     */
    public function test_one_place_left(): void {
        global $DB;
        [$centre, $event] = $this->act(acts::FREE_ASKS, 1);
        $family = $this->family($centre);
        $this->assertTrue(invites::reply($event, $family, 'yes'));
        $this->assertSame(1, (int) $DB->get_field(events::SIGNUPS, 'people', ['eventid' => $event->id, 'householdid' => $family->id]));
        $this->assertFalse(invites::reply($event, $this->family($centre), 'yes'));
    }

    /**
     * A family of the centre with no child written down asks for nothing; one that left only a phone is called by its name,
     * and is not offered an email it does not have.
     */
    public function test_who_they_are(): void {
        global $DB;
        [$centre, $event] = $this->act();
        $family = $this->family($centre);
        $this->assertTrue(joinpage::in_admission($family));
        $family->incentre = 1;
        $this->assertFalse(joinpage::in_admission($family));
        $id = households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $phone = households::get($id, (int) $centre->id);
        households::add_adult($phone, (object) ['firstname' => 'Marta', 'phone' => '+34600700800']);
        $known = joinpage::known($event, $centre, $phone, 'es', new \moodle_url('/local/oksigeniaeguahe/j.php'), 'x', 'y');
        $this->assertSame('Hola, Marta', $known['hello']);
        $this->assertNotContains('email', array_column($known['ways'], 'value'));
        $this->assertSame('y', $known['who']);
    }

    /**
     * What follows signing up to an act promises nothing that does not come, and what it offers is the page of the act.
     */
    public function test_words_after(): void {
        [$centre, $event] = $this->act();
        $family = $this->family($centre);
        events::sign_up($event, (int) $family->id, 2);
        $signup = familymail::signup($family, (int) $event->id);
        $page = knownpage::context($centre, 'es', [], $signup, true);
        $this->assertStringContainsString('la confirmación', $page['text']);
        $this->assertStringNotContainsString('página', $page['text']);
        $page = knownpage::context($centre, 'es', [], null, false, 'pub_sent_title');
        $this->assertSame('Ya lo tenemos', $page['title']);
        $sent = brief::sent($centre, 'es', new \moodle_url('/local/oksigeniaeguahe/j.php'), $event);
        $this->assertSame('Cambiar cuántos vienen', $sent['sent']['more']);
    }
}
