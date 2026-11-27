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
use local_oksigeniaeguahe\local\chatin;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\prepared;
use local_oksigeniaeguahe\local\replies;

/**
 * Prepared messages, and what a family says of an invitation by any way: written once with gaps each family fills, never
 * sent with a gap empty, the thanks after a visit proposed to whoever came; an answer in the chat or noted by a person,
 * proposed to confirm; and what a person notes, with who noted it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\prepared
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prepared::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chatin::class)]
final class prepared_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $offer;

    /**
     * A centre in Spanish, with how to ask for a place.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $DB->set_field(centres::T, 'applyurl', 'https://example.com/solicitud', ['id' => $id]);
        $this->centre = centres::get($id);
        $this->offer = centres::save_offer($id, (object) ['schoolyear' => \local_oksigeniaeguahe\local\facts::year_name(
            $this->centre,
            time()
        ), 'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
    }

    /**
     * A family with one adult with a phone and a child.
     *
     * @param string $child
     * @param string $phone
     * @return \stdClass
     */
    protected function family(string $child, string $phone = '+34600111222'): \stdClass {
        $cid = (int) $this->centre->id;
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $cid);
        $adult = households::add_adult($household, (object) ['firstname' => 'Gabriela', 'lastname' => 'Ejemplo',
            'email' => strtolower($child) . '@example.com', 'phone' => $phone]);
        households::consent($household, $adult, 'email', true, 'family', 'es');
        households::add_child($household, $child, $this->offer);
        return households::get((int) $household->id, $cid);
    }

    /**
     * An act of the centre, so many days from now.
     *
     * @param int $days
     * @param string $kind
     * @return \stdClass
     */
    protected function act(int $days, string $kind = 'openday'): \stdClass {
        $start = time() + $days * DAYSECS;
        return events::get(events::save((int) $this->centre->id, (object) ['kind' => $kind, 'name' => 'Respira',
            'timestart' => $start, 'timeend' => $start + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
    }

    /**
     * Written with the words of the gaps, kept with their keys and given back as written; only one is the one after a
     * visit, and going by itself makes it that one.
     */
    public function test_keep(): void {
        $w = fn(string $key) => '[' . prepared::word($key, 'es') . ']';
        $text = 'Gracias por venir ' . $w('visitday') . ' con ' . $w('children') . '.';
        $id = prepared::save($this->centre, 0, 'Gracias por la visita', $text);
        $one = prepared::get((int) $this->centre->id, $id);
        $this->assertSame('Gracias por venir {visitday} con {children}.', $one->body);
        $this->assertSame($text, prepared::to_words($one->body, 'es'));
        $other = prepared::save($this->centre, 0, 'Lista de espera', 'Hemos anotado a ' . $w('children') . ' en la lista.', 1);
        $this->assertSame($other, (int) prepared::after_visit((int) $this->centre->id)->id);
        prepared::save($this->centre, $id, '', $text, null, 1);
        $after = prepared::after_visit((int) $this->centre->id);
        $this->assertSame($id, (int) $after->id);
        $this->assertSame(1, (int) $after->auto);
        $this->assertSame(0, (int) prepared::get((int) $this->centre->id, $other)->aftervisit, 'Only one.');
        $this->assertSame(0, prepared::save($this->centre, 0, 'Vacío', ''), 'Nothing written, nothing kept.');
        prepared::remove($this->centre, $other);
        $this->assertCount(1, prepared::all((int) $this->centre->id));
    }

    /**
     * Written for a family: its children, the link of the centre; what it has no value for stays in brackets, said, and a
     * text with it is not sent.
     */
    public function test_fill(): void {
        $w = fn(string $key) => '[' . prepared::word($key, 'es') . ']';
        $family = $this->family('Greta');
        $id = prepared::save($this->centre, 0, 'Gracias', 'Gracias por venir ' . $w('visitday') . ' con ' . $w('children') . ".\n\n" .
            'La solicitud: ' . $w('applylink'));
        $full = households::full($family);
        $filled = prepared::fill(prepared::get((int) $this->centre->id, $id), prepared::values($this->centre, $full, time()), 'es');
        $this->assertStringContainsString('con Greta.', $filled['text']);
        $this->assertStringContainsString('https://example.com/solicitud', $filled['text']);
        $this->assertSame(['visitday'], $filled['missing'], 'They have not come yet.');
        $this->assertStringContainsString($w('visitday'), $filled['text']);
        $this->assertTrue(prepared::has_gap($filled['text'], 'es'));
        $this->assertFalse(prepared::has_gap('Gracias por venir con Greta.', 'es'));
        $pick = prepared::pick_context($this->centre, $full, $id, true, 'https://example.com/household.php?id=1', time());
        $this->assertSame($id, $pick['chosen']['id']);
        $this->assertStringStartsWith(\local_oksigeniaeguahe\local\panel_household::greeting($full), $pick['list'][0]['text']);
        $this->assertNotSame('', $pick['chosen']['missing']);
    }

    /**
     * The thanks after a visit: proposed to a family that came and has not had it; going by themselves, they go from 9 to
     * 20, once, by mail, in the place of the thanks of always; a written answer is kept as one more.
     */
    public function test_thanks(): void {
        global $DB;
        $family = $this->family('Greta');
        $act = $this->act(-1);
        $signup = events::sign_up($act, (int) $family->id, 2);
        events::attend($act, $signup, true);
        $now = time();
        $this->assertSame([], prepared::due_households($this->centre, $now), 'Without a message after a visit, nothing.');
        $children = '[' . prepared::word('children', 'es') . ']';
        $id = prepared::save($this->centre, 0, 'Gracias por la visita', 'Gracias por venir con ' . $children . '.', 1);
        $this->assertSame([(int) $family->id], prepared::due_households($this->centre, $now));
        $full = households::full($family);
        $envelope = prepared::thanks_context($this->centre, $full, 'https://example.com/household.php?id=1', $now);
        $this->assertStringContainsString('gracias=1', $envelope['url']);
        $this->assertSame('', $envelope['sent']);
        // By itself, in daytime.
        prepared::save($this->centre, $id, '', 'Gracias por venir con ' . $children . '.', null, 1);
        $sink = $this->redirectEmails();
        $day = (new \DateTimeImmutable('today', \core_date::get_server_timezone_object()))->setTime(11, 0)->getTimestamp();
        $this->assertSame(0, prepared::send_due($day - 4 * HOURSECS), 'Never at night.');
        $this->assertSame(1, prepared::send_due($day));
        $this->assertSame(0, prepared::send_due($day), 'Once.');
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(1, $DB->count_records(households::CONTACTS, ['householdid' => $family->id, 'prepared' => $id]));
        $this->assertSame(1, (int) prepared::get((int) $this->centre->id, $id)->uses);
        $this->assertSame([], prepared::due_households($this->centre, $now));
        // What a person wrote, one more message, without its greeting and with the gap of the children.
        $kept = prepared::keep_written($this->centre, $full, \local_oksigeniaeguahe\local\panel_household::greeting($full) .
            "\n\nGreta tiene su plaza.");
        $this->assertSame('{children} tiene su plaza.', prepared::get((int) $this->centre->id, $kept)->body);
    }

    /**
     * What they said of an act, noted by a person: in their history with who noted it, and never as the family opening its
     * letter.
     */
    public function test_noted(): void {
        global $DB, $USER;
        $family = $this->family('Greta');
        $act = $this->act(5, 'other');
        $DB->insert_record(invites::T, (object) ['centreid' => $this->centre->id, 'eventid' => $act->id,
            'householdid' => $family->id, 'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => time() - DAYSECS,
            'timecreated' => time() - DAYSECS]);
        $this->assertTrue(invites::reply($act, $family, 'no', 0, time(), (int) $USER->id));
        $row = $DB->get_record(invites::T, ['eventid' => $act->id, 'householdid' => $family->id]);
        $this->assertSame('no', $row->reply);
        $this->assertSame(0, (int) $row->opened);
        $this->assertTrue($DB->record_exists(households::CONTACTS, ['householdid' => $family->id, 'userid' => $USER->id,
            'direction' => 'note']));
        // A call noted that says they will not come: proposed, by the team.
        $other = $this->family('Leo', '+34600111333');
        $DB->insert_record(invites::T, (object) ['centreid' => $this->centre->id, 'eventid' => $act->id,
            'householdid' => $other->id, 'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => time() - DAYSECS,
            'timecreated' => time() - DAYSECS]);
        households::received($other, 'phone', 'Llamó: que esta vez no podrán ir', true, null, 0, '', '', true, false, (int) $USER->id);
        $this->assertTrue(replies::propose_noted($other, 'Llamó: que esta vez no podrán ir', time()));
        $items = replies::context($other, true)['items'];
        $this->assertSame(get_string('replies_seems_no_team', 'local_oksigeniaeguahe', 'Respira'), $items[0]['text']);
    }

    /**
     * Written in the chat, by a number joined or not: in the history of its family without waiting, once; what it seems to
     * say of an invitation, proposed with the words of the chat, and confirmed as the family's answer.
     */
    public function test_chat(): void {
        global $DB, $USER;
        $family = $this->family('Greta');
        $act = $this->act(5, 'other');
        $DB->insert_record(invites::T, (object) ['centreid' => $this->centre->id, 'eventid' => $act->id,
            'householdid' => $family->id, 'userid' => 2, 'channel' => 'email', 'bulkid' => 0, 'mailsent' => time() - DAYSECS,
            'timecreated' => time() - DAYSECS]);
        $text = 'Gracias por escribir, pero esta vez no podremos ir';
        $this->assertSame(1, chatin::take('34600111222', $text, time(), 'https://example.com/chat.php?c=1', 571));
        $this->assertSame(0, chatin::take('34600111222', $text, time(), 'https://example.com/chat.php?c=1', 571), 'Once.');
        $this->assertSame(0, chatin::take('34699999999', $text, time(), 'https://example.com/chat.php?c=1', 572), 'Nobody.');
        $this->assertTrue($DB->record_exists(households::CONTACTS, ['householdid' => $family->id, 'direction' => 'in',
            'channel' => 'whatsapp']));
        $this->assertSame(0, (int) households::get((int) $family->id, (int) $this->centre->id)->waitingsince);
        $items = replies::context($family, true)['items'];
        $this->assertSame(get_string('replies_seems_no_chat', 'local_oksigeniaeguahe', 'Respira'), $items[0]['text']);
        $this->assertTrue(replies::confirm($family, $items[0]['id'], true, (int) $USER->id));
        $this->assertSame('no', $DB->get_field(invites::T, 'reply', ['eventid' => $act->id, 'householdid' => $family->id]));
    }
}
