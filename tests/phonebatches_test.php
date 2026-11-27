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
use local_oksigeniaeguahe\local\phonebatches;

/**
 * The letter of an act by phone in batches: spread over the list, stopped by itself when several families of a batch ask to
 * stop, going on only when a person says so, never sooner than its gap after the last send nor at night.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\phonebatches
 */
#[\PHPUnit\Framework\Attributes\CoversClass(phonebatches::class)]
final class phonebatches_test extends \advanced_testcase {
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
     * A family with the letter by mail, and by phone in a send if given.
     *
     * @param array $row More of its invite.
     * @return \stdClass
     */
    protected function invited(array $row = []): \stdClass {
        global $DB;
        $centreid = (int) $this->centre->id;
        $household = households::get(households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $centreid);
        $DB->insert_record(invites::T, (object) ($row + ['centreid' => $centreid, 'eventid' => $this->event->id,
            'householdid' => $household->id, 'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => time() - DAYSECS,
            'timecreated' => time() - DAYSECS]));
        return $household;
    }

    /**
     * A moment of daytime or of night, today, by the clock of the server.
     *
     * @param int $hour
     * @return int
     */
    protected function hour_today(int $hour): int {
        return (new \DateTimeImmutable('today', \core_date::get_server_timezone_object()))->setTime($hour, 0)->getTimestamp();
    }

    /**
     * A batch takes families of every part of the list, never more than asked, nor twice the same.
     */
    public function test_spread(): void {
        $this->assertSame([1, 4, 7], phonebatches::spread([7, 1, 2, 3, 4, 5, 6, 8, 9, 10], 3));
        $this->assertSame([2, 5], phonebatches::spread([5, 2, 2], 4));
        $this->assertSame([], phonebatches::spread([1, 2, 3], 0));
        $this->assertCount(20, array_unique(phonebatches::spread(range(1, 182), 20)));
    }

    /**
     * Stopped by the number, by several families asking to stop (three, or a twentieth of a big batch) or by most failing.
     */
    public function test_brake(): void {
        $one = ['n' => 20, 'out' => 0, 'failed' => 0, 'quality' => false];
        $this->assertSame('', phonebatches::brake($one));
        $this->assertSame('quality', phonebatches::brake(['quality' => true] + $one));
        $this->assertSame('', phonebatches::brake(['out' => 2] + $one));
        $this->assertSame('optout', phonebatches::brake(['out' => 3] + $one));
        $this->assertSame('', phonebatches::brake(['n' => 100, 'out' => 4] + $one));
        $this->assertSame('optout', phonebatches::brake(['n' => 100, 'out' => 5] + $one));
        $this->assertSame('failed', phonebatches::brake(['failed' => 10] + $one));
        $this->assertSame('', phonebatches::brake(['n' => 2, 'failed' => 2] + $one));
    }

    /**
     * Asking a phone to stop after the letter went by it is kept on that letter; a no to joining, our own echo from another
     * family, or a letter that did not go by phone, never.
     */
    public function test_phone_out(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/oksigeniaeguahe/lib.php');
        $now = time();
        $byphone = $this->invited(['channel' => 'both', 'bulkid' => 9, 'phonesent' => $now - HOURSECS]);
        $bymail = $this->invited();
        $old = $this->invited(['channel' => 'both', 'bulkid' => 8, 'phonesent' => $now - 3 * WEEKSECS]);
        $asked = $this->invited(['channel' => 'both', 'bulkid' => 9, 'phonesent' => $now - HOURSECS]);
        foreach ([$byphone, $bymail, $old] as $household) {
            local_oksigeniaeguahe_marandu_optout((int) $household->id, 'whatsapp', true, 'whatsapp', $now);
        }
        local_oksigeniaeguahe_marandu_optout((int) $asked->id, 'whatsapp', true, 'asked', $now);
        local_oksigeniaeguahe_marandu_optout((int) $asked->id, 'whatsapp', true, 'eguahe:3', $now);
        $out = fn(\stdClass $h) => (int) $DB->get_field(invites::T, 'phoneout', ['householdid' => $h->id]);
        $this->assertSame($now, $out($byphone));
        $this->assertSame(0, $out($bymail));
        $this->assertSame(0, $out($old));
        $this->assertSame(0, $out($asked));
    }

    /**
     * Several families of the last batch asked to stop: the batches stop by themselves, whatever the hour, and that batch
     * stops them no more once a person goes on; stopped by a person, they wait; no more, and they are gone.
     */
    public function test_stops_and_goes_on(): void {
        $id = (int) $this->event->id;
        $sent = time() - 2 * HOURSECS;
        for ($i = 0; $i < 20; $i++) {
            $this->invited(['channel' => 'both', 'bulkid' => 9, 'phonesent' => $sent, 'phoneout' => $i < 3 ? $sent + 600 : 0]);
        }
        set_config('phoneplan_' . $id, json_encode(['size' => 20, 'every' => 60, 'userid' => 2, 'next' => 0, 'paused' => '',
            'since' => 0, 'checked' => 0, 'started' => $sent - 60]), 'local_oksigeniaeguahe');

        $this->assertSame(0, phonebatches::run($this->event, $this->centre, $this->hour_today(22))['phone']);
        $plan = phonebatches::plan($id);
        $this->assertSame('optout', $plan['paused']);
        $this->assertSame([$id], phonebatches::acts());

        phonebatches::resume($this->event, $this->centre, $this->hour_today(22));
        $plan = phonebatches::plan($id);
        $this->assertSame(9, (int) $plan['checked']);
        $this->assertSame('', $plan['paused'], 'Going on: that batch no longer stops them.');
        $this->assertSame(0, phonebatches::run($this->event, $this->centre, $this->hour_today(22))['phone'], 'Never at night.');
        $this->assertSame('', phonebatches::plan($id)['paused']);

        phonebatches::pause($id, 'user', time());
        $this->assertSame('user', phonebatches::plan($id)['paused']);
        $this->assertSame(0, phonebatches::run($this->event, $this->centre, $this->hour_today(11))['phone']);

        phonebatches::stop($id);
        $this->assertNull(phonebatches::plan($id));
        $this->assertSame([], phonebatches::acts());
    }

    /**
     * The next batch, never sooner than its gap after the last send by phone, in batches or not, and in daytime.
     */
    public function test_next(): void {
        $sent = $this->hour_today(10);
        $this->invited(['channel' => 'both', 'bulkid' => 9, 'phonesent' => $sent]);
        $plan = ['size' => 20, 'every' => 60, 'next' => 0];
        $this->assertSame($sent + HOURSECS, phonebatches::next($this->event, $plan));
        $this->assertSame($this->hour_today(15), phonebatches::next($this->event, ['next' => $this->hour_today(15)] + $plan));
        $this->assertSame(invites::daytime($this->hour_today(21)), phonebatches::next($this->event, ['next' => $this->hour_today(21)] + $plan));
        $this->assertGreaterThan($this->hour_today(21), phonebatches::next($this->event, ['next' => $this->hour_today(21)] + $plan));
    }

    /**
     * An act closed to signing up leaves no batches behind.
     */
    public function test_over(): void {
        global $DB;
        $id = (int) $this->event->id;
        set_config('phoneplan_' . $id, json_encode(['size' => 20, 'every' => 60, 'userid' => 2]), 'local_oksigeniaeguahe');
        $DB->set_field(events::T, 'open', 0, ['id' => $id]);
        $event = events::get($id, (int) $this->centre->id);
        $this->assertSame(0, phonebatches::run($event, $this->centre, time())['phone']);
        $this->assertNull(phonebatches::plan($id));
    }
}
