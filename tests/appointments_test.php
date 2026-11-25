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
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_agenda;
use local_oksigeniaeguahe\local\panel_give;
use local_oksigeniaeguahe\local\teamnotes;
use local_oksigeniaeguahe\local\visits;

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
     * A day the centre closes may be chosen, said so; and the days go on past the first fifteen, where the one it has is.
     */
    public function test_days(): void {
        $now = (new \DateTimeImmutable('2026-11-25 09:00', \core_date::get_server_timezone_object()))->getTimestamp();
        $full = households::full($this->family);
        $one = panel_give::context($full, $this->centre, null, [], false, $now);
        $days = array_column($one['days'], null, 'key');
        $this->assertSame(['2026-11-25', '2026-12-09'], [array_key_first($days), array_key_last($days)]);
        $this->assertTrue($days['2026-11-28']['closed'], 'A Saturday.');
        $this->assertSame('', $one['earlierurl']);
        $this->assertStringContainsString('desde=2026-12-09', $one['laterurl']);
        // The Saturday that was asked for is the one chosen, at an hour.
        $saturday = panel_give::context($full, $this->centre, null, ['day' => '2026-11-28'], false, $now);
        $this->assertSame(['2026-11-28'], array_column(array_filter($saturday['days'], fn($d) => $d['current']), 'key'));
        $this->assertSame(panel_give::FIRST, $saturday['min']);
        // Later: from that day, and back to today at most.
        $later = panel_give::context($full, $this->centre, null, ['from' => '2026-12-09'], false, $now);
        $this->assertSame('2026-12-09', $later['days'][0]['key']);
        $this->assertFalse($later['days'][0]['today']);
        $this->assertStringContainsString('desde=2026-11-25', $later['earlierurl']);
        $this->assertSame('2026-11-25', panel_give::context($full, $this->centre, null, ['from' => '2026-10-01'], false, $now)['days'][0]['key']);
        // One that is three weeks away, or a day of the week that far: from its Monday.
        $far = panel_give::context($full, $this->centre, null, ['day' => '2026-12-16'], false, $now);
        $this->assertSame('2026-12-14', $far['days'][0]['key']);
        $this->assertSame(['2026-12-16'], array_column(array_filter($far['days'], fn($d) => $d['current']), 'key'));
    }

    /**
     * The families the sheet of the team has on the day of an open day, without an hour, are in its card.
     */
    public function test_open_day_of_the_sheet(): void {
        $zone = \core_date::get_server_timezone_object();
        $at = (new \DateTimeImmutable('2026-11-21 10:00', $zone))->getTimestamp();
        $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Puertas abiertas', 'timestart' => $at,
            'timeend' => $at + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
        events::sign_up($event, (int) $this->family->id, 2);
        $other = households::get(households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'phone']), (int) $this->centre->id);
        $day = (new \DateTimeImmutable('2026-11-21 12:00', $zone))->getTimestamp();
        // Both in the sheet: the one that signed up is not counted twice. One with its hour is an appointment.
        $this->note($day, true);
        $own = $this->family;
        $this->family = $other;
        $this->note($day, true);
        $this->note($at + 6 * HOURSECS);
        $this->family = $own;
        $week = panel_agenda::context($this->centre, true, '2026-11-18', 0, $at + 4 * DAYSECS);
        $saturday = array_values(array_filter($week['days'], fn($d) => $d['key'] === '2026-11-21'))[0];
        $this->assertSame([true, false], array_map(fn($c) => (bool) $c['isact'], array_values($saturday['items'])));
        $this->assertSame(1, $saturday['items'][0]['sheet']);
        $this->assertNotSame('', $saturday['items'][0]['sheettext']);
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
     * The next one is given while they are here: the visit under way stays, with what was noted of it; and its name is of
     * whoever receives them now.
     */
    public function test_next_during(): void {
        global $DB;
        $now = appointments::give($this->family, $this->centre, time() + HOURSECS, 60, 0, '', 2, false)['event'];
        $DB->set_field(events::T, 'timestart', time() - 600, ['id' => $now->id]);
        $signup = $DB->get_field(events::SIGNUPS, 'id', ['eventid' => $now->id]);
        events::attend(events::get((int) $now->id, (int) $this->centre->id), (int) $signup, true);
        $next = appointments::give($this->family, $this->centre, time() + 7 * DAYSECS, 60, 0, '', 2, false);
        $this->assertNull($next['replaced']);
        $this->assertSame(0, (int) $DB->get_field(events::T, 'cancelled', ['id' => $now->id]));
        $this->assertGreaterThan(0, (int) $DB->get_field(events::SIGNUPS, 'attended', ['id' => $signup]));
        $this->assertCount(2, $DB->get_records(events::SIGNUPS, ['householdid' => $this->family->id]));
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Marta', 'lastname' => 'Ruiz']);
        $guide = (int) $DB->insert_record(centres::MEMBERS, (object) ['centreid' => $this->centre->id, 'userid' => $user->id,
            'public' => 1, 'sortorder' => 1, 'timecreated' => time()]);
        $id = (int) $next['event']->id;
        appointments::move($this->family, $this->centre, $id, time() + 8 * DAYSECS, 60, $guide, '', false);
        $this->assertStringContainsString('Marta', $DB->get_field(events::T, 'name', ['id' => $id]));
        appointments::move($this->family, $this->centre, $id, time() + 8 * DAYSECS, 60, 0, '', false);
        $this->assertStringNotContainsString('Marta', $DB->get_field(events::T, 'name', ['id' => $id]));
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

    /**
     * The same one sent twice is one; two to come of a family are asked about, and who already receives someone then is said.
     */
    public function test_twice(): void {
        global $DB, $USER;
        $member = (int) $DB->insert_record(centres::MEMBERS, (object) ['centreid' => $this->centre->id, 'userid' => $USER->id, 'timecreated' => time()]);
        $start = time() + 3 * DAYSECS;
        $one = appointments::give($this->family, $this->centre, $start, 45, $member, 'Secretaría', 2, false);
        $two = appointments::give($this->family, $this->centre, $start, 45, $member, 'Secretaría', 2, true);
        $this->assertTrue($two['again']);
        $this->assertFalse($two['sent']);
        $this->assertSame((int) $one['event']->id, (int) $two['event']->id);
        $this->assertSame(1, $DB->count_records(events::T, ['householdid' => $this->family->id]));
        $this->assertStringContainsString('Ana', appointments::taken($this->centre, $member, $start + 20 * MINSECS, $start + HOURSECS));
        $this->assertSame('', appointments::taken($this->centre, $member, $start, $start + HOURSECS, (int) $one['event']->id));
        $this->assertSame('', appointments::taken($this->centre, $member, $start + HOURSECS, $start + 2 * HOURSECS));
        // Another one, of when there were two files.
        $other = events::save((int) $this->centre->id, (object) ['kind' => 'visit', 'name' => 'Visita', 'timestart' => $start + 20 * DAYSECS,
            'timeend' => $start + 20 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 0]);
        $DB->set_field(events::T, 'householdid', $this->family->id, ['id' => $other]);
        events::sign_up(events::get($other, (int) $this->centre->id), (int) $this->family->id, 1);
        $this->assertCount(1, appointments::twins($this->family));
    }

    /**
     * Called off, or said on their page that they do not come: what they asked for waits for a day again, and the visit is out of
     * the week and of the calendar; one that already happened stays.
     */
    public function test_asks_again(): void {
        global $DB;
        $ask = fn() => $DB->insert_record(visits::REQUESTS, (object) ['centreid' => $this->centre->id, 'householdid' => $this->family->id, 'eventid' => 1,
            'people' => 2, 'status' => 'pending', 'visitid' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $request = $ask();
        $status = fn() => $DB->get_field(visits::REQUESTS, 'status', ['id' => $request]);
        $given = appointments::give($this->family, $this->centre, time() + DAYSECS, 45, 0, '', 2, false);
        $this->assertTrue($given['asked']);
        $this->assertSame('scheduled', $status());
        appointments::off($this->family, $this->centre, (int) $given['event']->id, '', 2, false);
        $this->assertSame('pending', $status());
        $given = appointments::give($this->family, $this->centre, time() + DAYSECS, 45, 0, '', 2, false);
        $this->assertCount(1, \local_oksigeniaeguahe\local\teamcalendar::items($this->centre));
        events::unsign(events::get((int) $given['event']->id, (int) $this->centre->id), $this->family, 'No podemos ir', true);
        $this->assertSame('pending', $status());
        $this->assertGreaterThan(0, (int) $DB->get_field(events::T, 'cancelled', ['id' => $given['event']->id]));
        $this->assertSame([], \local_oksigeniaeguahe\local\teamcalendar::items($this->centre));
        // One that passed: from a page of before, it is not called off, nor who came lost.
        $given = appointments::give($this->family, $this->centre, time() + DAYSECS, 45, 0, '', 2, false);
        $event = $given['event'];
        $signup = (int) $DB->get_field(events::SIGNUPS, 'id', ['eventid' => $event->id]);
        $DB->update_record(events::T, (object) ['id' => $event->id, 'timestart' => time() - 2 * HOURSECS, 'timeend' => time() - HOURSECS]);
        $event = events::get((int) $event->id, (int) $this->centre->id);
        events::attend($event, $signup, true);
        $this->assertFalse(appointments::off($this->family, $this->centre, (int) $event->id, '', 2, false));
        events::cancel($event, $this->centre, 2, '', time());
        $this->assertSame(1, $DB->count_records(events::SIGNUPS, ['eventid' => $event->id]));
        $this->assertSame(0, (int) $DB->get_field(events::T, 'cancelled', ['id' => $event->id]));
    }

    /**
     * Taken back that they came to one, the way stays when they came to another; and a visit of a sheet said to be missed takes
     * it back too, with what was promised for it.
     */
    public function test_came_to_two(): void {
        global $DB;
        $child = fn() => $DB->get_field(households::CHILDREN, 'stage', ['householdid' => $this->family->id]);
        $signups = [];
        foreach ([2, 1] as $days) {
            $event = events::get(events::save((int) $this->centre->id, (object) ['kind' => 'openday', 'name' => 'Jornada', 'timestart' => time() - $days * DAYSECS,
                'timeend' => time() - $days * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
            $signups[] = [$event, events::sign_up($event, (int) $this->family->id, 1)];
        }
        foreach ($signups as [$event, $signup]) {
            events::attend($event, $signup, true);
        }
        events::attend($signups[0][0], $signups[0][1], false);
        $this->assertSame('visit', $child());
        events::attend($signups[1][0], $signups[1][1], false);
        $this->assertSame('inquiry', $child());
        // The sheet.
        $when = time() - DAYSECS;
        $note = $this->note($when);
        $body = teamnotes::visit_promise($when, true, 'es');
        $promise = attend::promise($this->family, 2, $body, $when);
        $done = fn() => (int) $DB->get_field(attend::PROMISES, 'timedone', ['id' => $promise]) > 0;
        $key = appointments::mark($this->centre, [$note], 'came', 2);
        $this->assertSame(['visit', true], [$child(), $done()]);
        appointments::undo($this->centre, $key);
        $this->assertSame(['inquiry', false], [$child(), $done()]);
        appointments::mark($this->centre, [$note], 'came', 2);
        appointments::mark($this->centre, [$note], 'notcame', 2);
        $this->assertSame(['inquiry', true], [$child(), $done()]);
    }

    /**
     * A day that is not one is today.
     */
    public function test_wrong_day(): void {
        $context = panel_agenda::context($this->centre, true, '2026-13-45');
        $this->assertTrue($context['isnow']);
    }
}
