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
use local_oksigeniaeguahe\local\letters;
use local_oksigeniaeguahe\local\writeby;

/**
 * The letter of an act by more than one way: by mail and by phone, who it may go to by phone too, and the way each family
 * came by.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\invites
 */
#[\PHPUnit\Framework\Attributes\CoversClass(invites::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(letters::class)]
final class ways_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $event;

    /**
     * A centre with an act still to come.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $eventid = events::save($id, (object) ['kind' => 'other', 'name' => 'Respira',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]);
        $this->event = events::get($eventid, $id);
    }

    /**
     * A family with one adult, and its letter by mail already gone.
     *
     * @param string $name
     * @param string|null $phone
     * @param int $sent
     * @param array $row More of its invite.
     * @return \stdClass
     */
    protected function invited(string $name, ?string $phone, int $sent, array $row = []): \stdClass {
        global $DB;
        $centreid = (int) $this->centre->id;
        $household = households::get(households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $centreid);
        $adult = households::add_adult($household, (object) ['firstname' => $name, 'lastname' => 'Ejemplo',
            'email' => strtolower($name) . '@example.com', 'phone' => $phone]);
        households::consent($household, $adult, 'email', true, 'family', 'es');
        $DB->insert_record(invites::T, (object) ($row + ['centreid' => $centreid, 'eventid' => $this->event->id,
            'householdid' => $household->id, 'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => $sent,
            'timecreated' => $sent]));
        return households::get((int) $household->id, $centreid);
    }

    /**
     * By phone too, to those with the letter by mail that have said nothing: never to whoever answered, signed up or said
     * not by phone; with only a number, only once the messaging writes to one.
     */
    public function test_phone_too(): void {
        if (!invites::by_phone($this->centre)) {
            $this->markTestSkipped('The messaging of phones is not on this site.');
        }
        $sent = time() - HOURSECS;
        $this->invited('Ana', '+34600111001', $sent);
        $this->invited('Berta', null, $sent);
        $mailonly = $this->invited('Carlos', '+34600111003', $sent);
        writeby::set($mailonly, ['email'], 'family');
        $this->invited('Diego', '+34600111004', $sent, ['reply' => 'no', 'replied' => $sent + 60, 'opened' => $sent + 30]);
        $signed = $this->invited('Elena', '+34600111005', $sent);
        global $DB;
        $DB->insert_record(events::SIGNUPS, (object) ['centreid' => $this->centre->id, 'eventid' => $this->event->id,
            'householdid' => $signed->id, 'people' => 1, 'timecreated' => $sent + 60]);
        $too = invites::phone_too($this->event, $this->centre);
        $this->assertSame(1, $too['nophone']);
        $this->assertSame(1, $too['notphone']);
        if (invites::numbers_ready()) {
            $this->assertCount(1, $too['households']);
            $this->assertSame('+34600111001', reset($too['numbers']));
        } else {
            $this->assertSame([], $too['households']);
            $this->assertSame(1, $too['waiting']);
        }
    }

    /**
     * The way each family came by: from the link its message by phone carries, the phone; from any other, the mail; kept
     * once. Numbers and the tally count each way apart.
     */
    public function test_ways(): void {
        global $DB;
        $sent = time() - 3 * DAYSECS;
        $byphone = $this->invited('Ana', '+34600111001', $sent, ['channel' => 'both', 'phonesent' => $sent + HOURSECS,
            'phonelink' => 77, 'opened' => $sent + HOURSECS + 600]);
        $bymail = $this->invited('Berta', '+34600111002', $sent, ['channel' => 'both', 'phonesent' => $sent + HOURSECS,
            'phonelink' => 78, 'opened' => $sent + 300]);
        $this->invited('Carlos', null, $sent);

        $this->assertSame('phone', invites::way_of($this->event, $byphone, '77.' . str_repeat('a', 32)));
        $this->assertSame('email', invites::way_of($this->event, $bymail, '5.' . str_repeat('a', 32)));
        invites::note_way($this->event, $byphone, 'phone');
        invites::note_way($this->event, $byphone, 'email');
        invites::note_way($this->event, $bymail, 'email');
        $this->assertSame('phone', $DB->get_field(invites::T, 'via', ['householdid' => $byphone->id]));

        $mail = letters::figures($this->centre, 0, 0, time());
        $phone = letters::figures($this->centre, 0, 0, time(), 'phone');
        $this->assertSame(3, $mail['sent']);
        $this->assertSame(1, $mail['opened'], 'Opened from the phone, it does not count for the mail.');
        $this->assertSame(2, $phone['sent']);
        $this->assertSame(1, $phone['opened']);
        $ways = letters::ways($this->centre, 0, 0, time());
        $this->assertSame(['email', 'phone'], array_column($ways, 'key'));
        $this->assertStringContainsString(get_string('letters_ways', 'local_oksigeniaeguahe', (object) ['phone' => 1, 'phoneof' => 2,
            'mail' => 1, 'mailof' => 3]), letters::sentence($mail, $ways));

        $tally = invites::tally((int) $this->event->id);
        $this->assertSame(2, $tally['phone']);
        $this->assertSame(1, $tally['viaphone']);
        $this->assertSame(1, $tally['viaemail']);
    }
    /**
     * A family of example gets it by phone only when it is named, never with the others.
     */
    public function test_sample_only(): void {
        global $DB;
        if (!invites::by_phone($this->centre)) {
            $this->markTestSkipped('The messaging of phones is not on this site.');
        }
        $sample = $this->invited('Enrique', '+34600111009', time() - HOURSECS);
        $DB->set_field(households::T, 'guest', households::SAMPLE, ['id' => $sample->id]);
        $all = invites::phone_too($this->event, $this->centre);
        $this->assertSame(0, $all['waiting'] + count($all['households']));
        $only = invites::phone_too($this->event, $this->centre, [(int) $sample->id]);
        $this->assertSame(1, $only['waiting'] + count($only['households']));
        $this->assertSame([], invites::phone_too($this->event, $this->centre, [])['households']);
    }

    /**
     * Asked by the messaging while the send is being made, before its rows carry its number: it goes, as for the act it is
     * for; asked for a send it is not in, it does not.
     */
    public function test_still_while_asked(): void {
        $family = $this->invited('Ana', '+34600111001', time() - HOURSECS);
        $this->assertFalse(invites::still((int) $family->id, 55));
        $asking = new class extends invites {
            /**
             * Says which families a send is being asked for.
             *
             * @param int[] $families household id => act.
             */
            public static function asking(array $families): void {
                self::$asking = $families;
            }
        };
        $asking::asking([(int) $family->id => (int) $this->event->id]);
        try {
            $this->assertNotFalse(invites::still((int) $family->id, 55));
            $this->assertFalse(invites::still((int) $family->id + 1, 55), 'Not in the send.');
        } finally {
            $asking::asking([]);
        }
        $this->assertFalse(invites::still((int) $family->id, 55));
    }

    /**
     * «No», written to the invitation by phone: they do not come to that act, as said on the page of the letter, and nothing
     * else; the ways stay as they were.
     */
    public function test_answered_by_phone(): void {
        global $DB;
        $sent = time() - HOURSECS;
        $family = $this->invited('Ana', '+34600111001', $sent, ['channel' => 'both', 'bulkid' => 41, 'phonesent' => $sent]);
        $before = writeby::chosen($family);
        $this->assertNull(invites::answered_by_phone(99, (int) $family->id, 'no', time()));
        $this->assertNull(invites::answered_by_phone(41, (int) $family->id, 'yes', time()));
        $said = invites::answered_by_phone(41, (int) $family->id, 'no', time());
        $this->assertStringContainsString('Respira', (string) $said);
        $row = $DB->get_record(invites::T, ['householdid' => $family->id]);
        $this->assertSame('no', $row->reply);
        $this->assertSame('phone', $row->via);
        $this->assertSame($before, writeby::chosen(households::get((int) $family->id, (int) $this->centre->id)));
        $this->assertSame('no', invites::answer((int) $this->event->id, (int) $family->id));
    }
}
