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
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_agenda;
use local_oksigeniaeguahe\local\panel_give;
use local_oksigeniaeguahe\local\teamnotes;

/**
 * Tests for the appointments of the families: those given in Eguahe, the acts and those of a sheet, what is said of each and
 * taking it back, moving them and calling them off.
 *
 * @package    local_oksigeniaeguahe
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\appointments
 */
#[\PHPUnit\Framework\Attributes\CoversClass(appointments::class)]
final class appointments_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass */
    protected $family;

    /**
     * A centre and a family with a child.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        $household = households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'phone']);
        $this->family = households::get($household, $id);
        households::add_adult($this->family, (object) ['firstname' => 'Ana', 'phone' => '+34600333444']);
        households::add_child($this->family, 'Leo', 0, '', false);
    }

    /**
     * A visit a sheet of the team says.
     *
     * @param int $when
     * @param bool $dayonly
     * @return string Its id.
     */
    protected function note(int $when, bool $dayonly = false): string {
        global $DB;
        return 'n' . $DB->insert_record(teamnotes::T, (object) ['centreid' => $this->centre->id, 'householdid' => $this->family->id,
            'mark' => sha1((string) $when), 'entryid' => 3, 'contactid' => 0, 'visit' => $when, 'dayonly' => $dayonly ? 1 : 0, 'seen' => time(),
            'timecreated' => time()]);
    }

    /**
     * Given to any family, it says the one it takes the place of; moved and called off, with nobody told.
     */
    public function test_give(): void {
        global $DB;
        $start = time() + 3 * DAYSECS;
        $given = appointments::give($this->family, $this->centre, $start, 45, 0, 'Secretaría', 2, false);
        $this->assertNull($given['replaced']);
        $this->assertFalse($given['sent']);
        $again = appointments::give($this->family, $this->centre, $start + DAYSECS, 45, 0, 'Secretaría', 2, false);
        $this->assertSame($start, $again['replaced']['start']);
        $all = appointments::of($this->family);
        $this->assertCount(1, $all);
        $this->assertSame(['visit', 'eguahe', 'coming', $start + DAYSECS, 2], [$all[0]['kind'], $all[0]['source'], $all[0]['state'],
            $all[0]['start'], $all[0]['people']]);

        $eventid = (int) $again['event']->id;
        $this->assertFalse(appointments::move($this->family, $this->centre, $eventid, $start + 2 * DAYSECS, 30, 0, 'Biblioteca', false));
        $moved = appointments::of($this->family)[0];
        $this->assertSame([$start + 2 * DAYSECS, 'Biblioteca', $eventid], [$moved['start'], $moved['place'], $moved['eventid']]);
        $this->assertNull(appointments::move($this->family, $this->centre, 999999, $start, 30, 0, '', false));

        $this->assertTrue(appointments::off($this->family, $this->centre, $eventid, '', 2, false));
        $this->assertSame([], appointments::of($this->family));
        $this->assertFalse(appointments::off($this->family, $this->centre, $eventid, '', 2, false));
        $this->assertSame(0, $DB->count_records_select(households::CONTACTS, "householdid = ? AND direction = 'out'", [$this->family->id]));
    }

    /**
     * What is said of several at once, and taking it back: of a sign-up and of a visit of a sheet, with the way of the child.
     */
    public function test_mark(): void {
        global $DB;
        $past = time() - 2 * DAYSECS;
        $note = $this->note($past);
        $later = $this->note(time() + DAYSECS, true);
        $event = events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => $past + HOURSECS,
            'timeend' => $past + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]);
        events::sign_up(events::get($event, (int) $this->centre->id), (int) $this->family->id, 2);
        $state = fn() => array_column(appointments::of($this->family), 'state', 'id');
        $signup = array_values(array_filter(array_keys($state()), fn($id) => $id[0] === 's'))[0];
        $this->assertSame(['unknown', 'unknown', 'coming'], [$state()[$note], $state()[$signup], $state()[$later]]);

        $batch = appointments::mark($this->centre, [$note, $signup, $later], 'came', 2);
        $this->assertSame(['came', 'came', 'coming'], [$state()[$note], $state()[$signup], $state()[$later]]);
        $stage = fn() => $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $this->family->id]);
        $this->assertSame('visit', $stage());
        $this->assertSame(2, appointments::undo($this->centre, $batch));
        $this->assertSame(['unknown', 'unknown'], [$state()[$note], $state()[$signup]]);
        $this->assertSame('inquiry', $stage());
        $this->assertSame(0, appointments::undo($this->centre, $batch), 'Taken back once.');

        $batch = appointments::mark($this->centre, [$signup], 'notcame', 2);
        $this->assertSame('notcame', $state()[$signup]);
        appointments::mark($this->centre, [$signup], 'came', 2);
        $this->assertSame(0, appointments::undo($this->centre, $batch), 'Something else was said since.');
        $this->assertSame('came', $state()[$signup]);
        $this->assertSame('', appointments::mark($this->centre, [$signup], 'came', 2), 'Nothing new.');
        $this->assertSame('', appointments::mark($this->centre, [$later, 'x9', 'n0'], 'came', 2));
    }

    /**
     * Two that may be one, until a person says which; and a week with its days.
     */
    public function test_twins(): void {
        $start = time() + 3 * DAYSECS;
        $note = $this->note($start, true);
        $far = $this->note($start + 20 * DAYSECS);
        $given = appointments::give($this->family, $this->centre, $start + DAYSECS, 45, 0, 'Secretaría', 2, false);
        $pairs = appointments::twins($this->family);
        $this->assertCount(1, $pairs);
        $signup = $pairs[0]['a']['id'] === $note ? $pairs[0]['b']['id'] : $pairs[0]['a']['id'];
        $this->assertFalse(appointments::keep($this->family, $this->centre, $signup, $far, 2), 'They are not a pair.');
        $this->assertTrue(appointments::keep($this->family, $this->centre, $signup, $note, 2));
        $this->assertSame([], appointments::twins($this->family));
        $this->assertNotContains($note, array_column(appointments::of($this->family), 'id'));

        $week = appointments::week($this->centre, (int) $given['event']->timestart);
        $this->assertCount(7, $week);
        $this->assertSame('1', userdate($week[0]['day'], '%u', \core_date::get_server_timezone(), false));
        $this->assertSame([true, false], [$week[0]['open'], $week[6]['open']]);
        $this->assertSame([$signup], array_column(array_merge(...array_column($week, 'items')), 'id'));
    }

    /**
     * The screens of the team: the week with its cards, saying who came and giving one, with the first free hour of each day.
     */
    public function test_screens(): void {
        global $USER;
        centres::add_member((int) $this->centre->id, (int) $USER->id);
        $monday = strtotime('next monday 10:00', time() + 7 * DAYSECS);
        $member = array_key_first(panel_agenda::faces($this->centre));
        appointments::give($this->family, $this->centre, $monday, 45, $member, 'Secretaría', 2, false);
        $note = $this->note(time() - DAYSECS);
        $week = panel_agenda::context($this->centre, true, date('Y-m-d', $monday));
        $cards = array_merge(...array_column($week['days'], 'items'));
        $this->assertSame(['Ana', 'coming', panel::hour($monday)], [$cards[0]['adults'], $cards[0]['state'], $cards[0]['hour']]);
        $this->assertNotSame('', $cards[0]['moveurl']);
        $this->assertNotFalse($cards[0]['guide']);
        $this->assertSame(1, $week['topass']['n']);
        $this->assertCount(0, array_merge(...array_column(panel_agenda::context(
            $this->centre,
            true,
            date('Y-m-d', $monday),
            $member + 1
        )['days'], 'items')));

        // The acts say who came at their door, not here.
        $act = events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() - 2 * DAYSECS,
            'timeend' => time() - 2 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        events::sign_up(events::get($act, (int) $this->centre->id), (int) $this->family->id, 2);
        $pass = panel_agenda::pass($this->centre, true);
        $this->assertSame([1, $note], [$pass['n'], $pass['days'][0]['idlist']]);
        $key = appointments::mark($this->centre, [$note], 'came', (int) $USER->id);
        $this->assertSame($key, panel_agenda::pass($this->centre, true, $key)['undo']);
        $this->assertTrue(panel_agenda::pass($this->centre, true)['none']);

        $give = panel_give::context(households::full($this->family), $this->centre, null, [], false, strtotime('next monday 6:00'));
        $this->assertCount(panel_give::DAYS, $give['days']);
        $this->assertStringStartsWith('9:00', $give['days'][0]['first']);
        $this->assertSame('', $give['days'][5]['first'], 'Saturday.');
        $this->assertFalse($give['tell']['can'], 'There is no way to write to it.');
        $this->assertNotFalse($give['replaces']);
        $family = panel_agenda::of_family(households::full($this->family), $this->centre, true, time());
        $this->assertTrue($family['hasappointments']);
    }

    /**
     * From the week: whose it is, first. Those that asked, those that wait, and any other by its name; the day it comes from
     * stays chosen.
     */
    public function test_who(): void {
        $ana = $this->family;
        households::received($ana, 'webform', '¿Hay plazas?', true);
        $luis = households::get(households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'phone']), 0);
        households::add_adult($luis, (object) ['firstname' => 'Luis', 'phone' => '+34600333555']);
        $week = panel_agenda::context($this->centre, true);
        $this->assertStringContainsString('cita.php', $week['head']['action']['url']);
        $this->assertFalse(panel_agenda::context($this->centre, false)['head']['action']);
        $who = panel_give::who($this->centre, '');
        $this->assertSame(['Ana'], array_map(fn($f) => strtok($f['adults'], ' '), $who['families']));
        $this->assertTrue($who['families'][0]['waiting']);
        $this->assertSame(['Luis'], array_map(fn($f) => strtok($f['adults'], ' '), panel_give::who($this->centre, 'lui')['families']));
        $this->assertNotSame('', panel_give::who($this->centre, 'nadie')['none']);
        // The day it comes from, when it has room.
        $zone = \core_date::get_server_timezone_object();
        $now = (new \DateTimeImmutable('2026-11-25 08:00', $zone))->getTimestamp();
        $give = panel_give::context(households::full($ana), $this->centre, null, ['day' => '2026-11-27'], false, $now);
        $this->assertSame(['2026-11-27'], array_column(array_filter($give['days'], fn($d) => $d['current']), 'key'));
        $this->assertStringContainsString('dia=2026-11-27', panel_give::who($this->centre, '', '2026-11-27')['families'][0]['url']);
    }

    /**
     * Who came to an act that is over: first those that did not, then the rest at once, which never changes what was said of
     * one; and taking it back.
     */
    public function test_act(): void {
        $past = time() - 5 * DAYSECS;
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => $past,
            'timeend' => $past + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
        $ids = [];
        foreach (['Ana', 'Luis', 'Eva'] as $name) {
            $household = households::get(households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'phone']), 0);
            households::add_adult($household, (object) ['firstname' => $name, 'phone' => '']);
            $ids[$name] = 's' . events::sign_up($event, (int) $household->id, 2);
        }
        $now = time();
        $this->assertSame([], panel_agenda::topass($this->centre, $now));
        $page = panel_agenda::pass($this->centre, true, '', 0, $event);
        $this->assertSame(3, $page['n']);
        $this->assertSame(['Ana', 'Eva', 'Luis'], array_column($page['days'][0]['items'], 'adults'));
        $this->assertSame('', $page['thanks'], 'Too late for the thanks: none goes out.');
        $this->assertNotFalse(\local_oksigeniaeguahe\local\panel_event::context($event, $this->centre, true, $now)['topass']);
        appointments::mark($this->centre, [$ids['Eva']], 'notcame', 2);
        $batch = appointments::mark($this->centre, array_values($ids), 'came', 2, 0, true);
        $states = fn() => array_column(appointments::agenda($this->centre, $past - DAYSECS, $now), 'state', 'id');
        $this->assertSame(['came', 'came', 'notcame'], [$states()[$ids['Ana']], $states()[$ids['Luis']], $states()[$ids['Eva']]]);
        $this->assertTrue(panel_agenda::pass($this->centre, true, $batch, 0, $event)['none']);
        appointments::undo($this->centre, $batch);
        $this->assertSame(['unknown', 'unknown', 'notcame'], [$states()[$ids['Ana']], $states()[$ids['Luis']], $states()[$ids['Eva']]]);
    }
}
