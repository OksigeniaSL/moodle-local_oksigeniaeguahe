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

use local_oksigeniaeguahe\local\appointments;
use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\export;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\lookalike;
use local_oksigeniaeguahe\local\outs;
use local_oksigeniaeguahe\local\panel_metrics;
use local_oksigeniaeguahe\local\visits;
use local_oksigeniaeguahe\local\writeby;

/**
 * The families that go and those that never were: what it closes, what it stops, taking it back, and the numbers. With
 * example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\outs
 */
#[\PHPUnit\Framework\Attributes\CoversClass(outs::class)]
final class outs_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $offer;

    /**
     * A centre with a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $this->offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
    }

    /**
     * A family that asked and waits.
     *
     * @param string $email
     * @param string $stage
     * @return \stdClass
     */
    protected function family(string $email, string $stage = 'inquiry'): \stdClass {
        global $DB;
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        $adult = households::add_adult($household, (object) ['firstname' => 'Ana', 'email' => $email]);
        households::consent($household, $adult, 'email', true, 'form', 'es');
        $child = households::add_child($household, 'Leo', $this->offer);
        households::received($household, 'webform', '¿Hay comedor?', true);
        if ($stage !== 'inquiry') {
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), $stage);
        }
        return households::get($id, (int) $this->centre->id);
    }

    /**
     * The family as it is now.
     *
     * @param \stdClass $household
     * @return \stdClass
     */
    protected function now(\stdClass $household): \stdClass {
        return households::get((int) $household->id, (int) $this->centre->id);
    }

    /**
     * Not interested: everything stops, nothing waits, and it can be taken back as it was.
     */
    public function test_not_interested_and_back(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $family = $this->family('ana@example.com', 'application');
        attend::promise($family, 2, 'Llamarla', time() + DAYSECS);
        appointments::give($family, $this->centre, time() + 5 * DAYSECS, 45, 0, 'Secretaría', 2, false);
        $talk = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'other', 'name' => 'Charla',
            'timestart' => time() + 6 * DAYSECS, 'timeend' => time() + 6 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1,
            'free' => 2]), (int) $this->centre->id);
        invites::reply($talk, $family, 'yes', 1);
        $family = $this->now($family);
        $this->assertTrue(outs::set($family, $this->centre, 'notinterested', 'Que no le escribamos', '', 2));
        $this->assertFalse(outs::set($family, $this->centre, 'cost', '', '', 2), 'Once.');
        $now = $this->now($family);
        $this->assertSame(['notinterested', 'all', 3, 0], [$now->outreason, $now->outstops, (int) $now->outstep,
            (int) $now->waitingsince]);
        $this->assertTrue(writeby::silent($now));
        $this->assertSame(['closed'], $DB->get_fieldset_select(households::CHILDREN, 'stage', 'householdid = ?', [$now->id]));
        $this->assertSame(0, $DB->count_records(attend::PROMISES, ['householdid' => $now->id, 'timedone' => 0]));
        $this->assertSame([], array_filter(appointments::of($now), fn($o) => $o['state'] === 'coming'));
        $this->assertSame('no', invites::answer((int) $talk->id, (int) $now->id));
        $this->assertCount(0, $sink->get_messages(), 'Nobody is told anything.');
        $this->assertFalse(writeby::may_send($now, 'openinvite'));
        $down = array_column(export::families($this->centre), 'out', 'emails');
        $this->assertSame(get_string('out_notinterested', 'local_oksigeniaeguahe'), $down['ana@example.com']);
        // Taken back.
        $this->assertTrue(outs::undo($now, $this->centre, 2));
        $now = $this->now($family);
        $this->assertFalse(outs::is_out($now));
        $this->assertFalse(writeby::silent($now));
        $this->assertGreaterThan(0, (int) $now->waitingsince);
        $this->assertSame(['application'], $DB->get_fieldset_select(households::CHILDREN, 'stage', 'householdid = ?', [$now->id]));
        $this->assertFalse(outs::undo($now, $this->centre, 2));
        $sink->close();
    }

    /**
     * What each reason stops, and what a person says instead.
     */
    public function test_stops(): void {
        $other = $this->family('eva@example.com');
        outs::set($other, $this->centre, 'othercentre', '', '', 2);
        $now = $this->now($other);
        $this->assertSame('invites', $now->outstops);
        $this->assertGreaterThan(0, (int) $now->noinvites);
        $this->assertFalse(writeby::silent($now));
        outs::undo($now, $this->centre, 2);
        $this->assertSame(0, (int) $this->now($other)->noinvites);
        $silent = $this->family('luz@example.com');
        outs::set($silent, $this->centre, 'noanswer', '', 'all', 2);
        $this->assertTrue(writeby::silent($this->now($silent)));
        foreach (array_merge(outs::NEVER, outs::LEFT) as $reason) {
            $this->assertContains(outs::stop($reason), outs::STOPS);
        }
        // With a child at the centre it is not that.
        $inside = $this->family('mar@example.com', 'enrolled');
        $this->assertFalse(outs::set($inside, $this->centre, 'cost', '', '', 2));
        $this->assertFalse(outs::set($this->family('sol@example.com'), $this->centre, 'nada', '', '', 2));
    }

    /**
     * In the numbers: who went stays, with its reason; who was not a family is in no other number, and is counted apart.
     */
    public function test_numbers(): void {
        $families = [];
        foreach (['job', 'mistake', 'cost', 'othercentre', ''] as $i => $reason) {
            $families[$i] = $this->family("f$i@example.com", $reason === 'othercentre' ? 'visit' : 'inquiry');
            if ($reason !== '') {
                outs::set($families[$i], $this->centre, $reason, '', '', 2);
            }
        }
        // One closed child by child, with its reason.
        $closed = $this->family('cerrada@example.com');
        $children = households::full($closed)->children;
        households::set_stage(reset($children), 'closed', 'moved');
        $all = panel_metrics::everyone($this->centre);
        $this->assertCount(4, $all, 'The two that were not families are nowhere else.');
        $figures = outs::figures($this->centre, $all, time() - YEARSECS);
        $this->assertSame([3, 4], [$figures['left']['total'], $figures['left']['of']]);
        $this->assertEqualsCanonicalizing(['cost' => 1, 'othercentre' => 1, 'far' => 1], array_column($figures['left']['rows'], 'n', 'reason'));
        $this->assertSame([2, 2], [$figures['never']['total'], array_sum(array_column($figures['never']['channels'], 'n'))]);
        $rows = array_column($figures['left']['rows'], null, 'reason');
        $this->assertSame([['name' => get_string('out_step_2', 'local_oksigeniaeguahe'), 'n' => 1]], array_values($rows['othercentre']['steps']));
        $said = outs::summary($this->centre, $all, time() - YEARSECS);
        $this->assertStringContainsString('3', $said['left']);
        $this->assertStringContainsString('2', $said['never']);
        $this->assertNull(outs::summary(centres::get(centres::create((object) ['name' => 'Otro', 'lang' => 'es'])), [], 0));
        outs::undo($this->now($families[0]), $this->centre, 2);
        $this->assertCount(5, panel_metrics::everyone($this->centre));
    }

    /**
     * Writing by a form or signing up again brings it back by itself; a message alone does not, and waits for a person.
     */
    public function test_it_writes_again(): void {
        $family = $this->family('ana@example.com');
        outs::set($family, $this->centre, 'mistake', '', '', 2);
        households::received($this->now($family), 'email', 'Perdonen, sí queríamos', true);
        $now = $this->now($family);
        $this->assertTrue(outs::is_out($now));
        $this->assertGreaterThan(0, (int) $now->waitingsince);
        outs::back($now, $this->centre);
        $this->assertFalse(outs::is_out($this->now($family)));
        $this->assertSame(0, (int) $this->now($family)->guest);
    }

    /**
     * What the notes suggest, only a proposal.
     */
    public function test_proposals(): void {
        $cases = ['Dice que se equivocó de formulario' => 'mistake', 'Creía que ofrecíamos empleo. Buscaba trabajo' => 'job',
            'La hija le cogió el teléfono, jugando' => 'someoneelse', 'No le interesa' => 'notinterested',
            'Ya tienen colegio' => 'othercentre', 'Viven en la península' => 'far', 'Le parece muy caro' => 'cost',
            'Le interesa mucho, vienen el martes' => '', 'Llamarla el jueves' => '', 'Su hija no tiene colegio' => ''];
        foreach ($cases as $note => $reason) {
            $contact = (object) ['direction' => 'note', 'body' => $note, 'timecreated' => time() - DAYSECS];
            $this->assertSame($reason, outs::proposal([$contact], time())['reason'] ?? '', $note);
        }
        $family = $this->family('ana@example.com');
        households::note($family, 2, 'No están interesados');
        $found = outs::proposals((int) $this->centre->id, time());
        $this->assertSame([(int) $family->id], array_keys($found));
        outs::not_so($family);
        $this->assertSame([], outs::proposals((int) $this->centre->id, time()));
    }

    /**
     * Put together with one that goes on, it goes on.
     */
    public function test_merge(): void {
        $gone = $this->family('ana@example.com');
        outs::set($gone, $this->centre, 'cost', '', '', 2);
        $other = $this->family('pau@example.com');
        $kept = lookalike::merge($this->now($gone), $other, 2);
        $this->assertFalse(outs::is_out($kept));
        $this->assertSame(['inquiry'], array_values(array_map(fn($k) => $k->stage, households::full($kept)->children)));
    }

    /**
     * A child that had already left is not closed with the others, nor is it back when that is taken back; and what they asked for
     * does not wait for a day.
     */
    public function test_with_one_that_left(): void {
        global $DB;
        $family = $this->family('ana@example.com');
        $left = households::add_child($family, 'Noa', $this->offer);
        $DB->update_record(households::CHILDREN, (object) ['id' => $left, 'stage' => 'enrolled', 'lefttime' => 5, 'leftreason' => 'moved']);
        $event = \local_oksigeniaeguahe\local\visits::propose($family, time() + DAYSECS, 45, 0, '', 2);
        $request = $DB->insert_record(\local_oksigeniaeguahe\local\visits::REQUESTS, (object) ['centreid' => $this->centre->id, 'householdid' => $family->id,
            'eventid' => 1, 'people' => 1, 'status' => 'scheduled', 'visitid' => $event->id, 'timecreated' => 1, 'timemodified' => 1]);
        $family = $this->now($family);
        $this->assertTrue(outs::set($family, $this->centre, 'cost', '', '', 2));
        $this->assertSame('withdrawn', $DB->get_field(\local_oksigeniaeguahe\local\visits::REQUESTS, 'status', ['id' => $request]));
        $this->assertSame(['enrolled', '5'], array_values((array) $DB->get_record(households::CHILDREN, ['id' => $left], 'stage, lefttime')));
        outs::undo($family, $this->centre, 2);
        $this->assertSame('5', $DB->get_field(households::CHILDREN, 'lefttime', ['id' => $left]));
    }
}
