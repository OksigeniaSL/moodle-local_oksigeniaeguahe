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
use local_oksigeniaeguahe\local\busy;
use local_oksigeniaeguahe\local\calsync;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_agenda;
use local_oksigeniaeguahe\local\panel_give;
use local_oksigeniaeguahe\local\teamcalendar;

/**
 * The calendar of the team in Google, both ways, and what there is in the others behind. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\calsync
 * @covers     \local_oksigeniaeguahe\local\busy
 */
#[\PHPUnit\Framework\Attributes\CoversClass(calsync::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(busy::class)]
final class calsync_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $now;

    /** @var array What there is in the calendar of Google: uid => [start, end]. */
    protected $google = [];

    /**
     * A centre that chose its calendar.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->now = time();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($id);
        calsync::receive($this->centre, ['calendars' => [['id' => 'adm', 'name' => 'Admisiones'], ['id' => 'sec',
            'name' => 'Secretaría']]], $this->now);
        calsync::choose($this->centre, 'adm');
    }

    /**
     * A visit of a family.
     *
     * @param int $start
     * @return array [household, event]
     */
    protected function visit(int $start): array {
        $id = households::create((int) $this->centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
        $household = households::get($id, (int) $this->centre->id);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ruiz', 'email' => "ana$id@example.com"]);
        $given = appointments::give($household, $this->centre, $start, 45, 0, 'Secretaría', 2, false);
        return [$household, $given['event']];
    }

    /**
     * The script runs: it says what there is and does what it is told.
     *
     * @return string[] What it did, as «do uid».
     */
    protected function script(): array {
        $said = ['calendar' => 'adm', 'events' => [], 'behind' => []];
        foreach ($this->google as $uid => $at) {
            $said['events'][] = ['uid' => $uid, 'start' => $at[0], 'end' => $at[1]];
        }
        $told = calsync::receive($this->centre, $said, $this->now);
        $did = [];
        foreach ($told['ops'] as $op) {
            if ($op['do'] === 'delete') {
                unset($this->google[$op['uid']]);
            } else if ($op['do'] !== 'text') {
                $this->google[$op['uid']] = [$op['start'], $op['end']];
            }
            if ($op['do'] !== 'text') {
                $did[] = $op['do'] . ' ' . $op['uid'];
            }
        }
        return $did;
    }

    /**
     * What changes in Eguahe changes there, and what is called off goes.
     */
    public function test_from_eguahe(): void {
        $start = $this->now + 3 * DAYSECS;
        [$household, $event] = $this->visit($start);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $this->assertSame(["create $uid"], $this->script());
        $this->assertSame([], $this->script(), 'Both say the same.');
        appointments::move($household, $this->centre, (int) $event->id, $start + HOURSECS, 45, 0, 'Secretaría', false);
        $this->assertSame(["set $uid"], $this->script());
        $this->assertSame([], $this->script());
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
        appointments::off($household, $this->centre, (int) $event->id, '', 2, false);
        $this->assertSame(["delete $uid"], $this->script());
        $this->assertSame([], $this->google);
    }

    /**
     * What a person moves there is asked, never undone nor applied; and each answer does what it says.
     */
    public function test_moved_there(): void {
        $start = $this->now + 3 * DAYSECS;
        [$household, $event] = $this->visit($start);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $this->script();
        $this->script();
        $moved = [$start + DAYSECS, $start + DAYSECS + 45 * MINSECS];
        $this->google[$uid] = $moved;
        $this->assertSame([], $this->script());
        $this->assertSame([], $this->script(), 'Asked once, and left as the person left it.');
        $this->assertSame($moved, $this->google[$uid]);
        $this->assertSame($start, (int) events::get((int) $event->id, (int) $this->centre->id)->timestart);
        $questions = calsync::questions($this->centre, true, $this->now, (int) $household->id);
        $this->assertCount(1, $questions);
        $this->assertStringContainsString('a=' . $moved[0], html_entity_decode($questions[0]['changeurl']));
        $this->assertStringContainsString('m=45', html_entity_decode($questions[0]['changeurl']));
        $this->assertSame(['', '', false], array_values(array_intersect_key(
            calsync::questions($this->centre, false, $this->now)[0],
            ['changeurl' => 1, 'offurl' => 1, 'keep' => 1]
        )), 'Whoever only looks has no way out.');
        // Left as it was: the calendar goes back.
        $this->assertTrue(calsync::restore($this->centre, (int) $event->id));
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
        $this->assertSame(["set $uid"], $this->script());
        $this->assertSame($start, $this->google[$uid][0]);
        $this->script();
        // Moved again, and changed here too: both say the same and nothing is asked.
        $this->google[$uid] = $moved;
        $this->script();
        $this->assertCount(1, calsync::questions($this->centre, true, $this->now));
        appointments::move($household, $this->centre, (int) $event->id, $moved[0], 45, 0, 'Secretaría', false);
        $this->assertSame([], $this->script());
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
    }

    /**
     * Deleted there: the family is told nothing and the visit stands until a person says; put back, or called off.
     */
    public function test_deleted_there(): void {
        $sink = $this->redirectEmails();
        $start = $this->now + 3 * DAYSECS;
        [, $event] = $this->visit($start);
        [$other, $second] = $this->visit($start + DAYSECS);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $this->script();
        $this->script();
        unset($this->google[$uid]);
        $this->assertSame([], $this->script());
        $this->assertTrue(calsync::questions($this->centre, true, $this->now)[0]['deleted']);
        $this->assertSame(0, (int) events::get((int) $event->id, (int) $this->centre->id)->cancelled);
        $this->assertCount(0, $sink->get_messages());
        calsync::restore($this->centre, (int) $event->id);
        $this->assertSame(["create $uid"], $this->script());
        $this->script();
        unset($this->google['eguahe-' . $this->centre->id . '-' . $second->id]);
        $this->script();
        appointments::off($other, $this->centre, (int) $second->id, '', 2, false);
        $this->assertSame([], $this->script());
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
        $sink->close();
    }

    /**
     * All gone at once is an accident: nothing is asked nor put back until a person says so.
     */
    public function test_emptied(): void {
        for ($i = 1; $i <= 3; $i++) {
            $this->visit($this->now + $i * DAYSECS);
        }
        $this->assertCount(3, $this->script());
        $this->script();
        $this->google = [];
        $this->assertSame([], $this->script());
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
        $this->assertNotSame('', calsync::context($this->centre, $this->now)['emptied']);
        calsync::refill($this->centre);
        $this->assertCount(3, $this->script());
        // Another calendar: everything goes to it, and nothing is asked of the one before.
        $this->script();
        calsync::choose($this->centre, 'sec');
        $this->assertSame('sec', calsync::config($this->centre)['calendar']);
        $this->assertSame([], $this->script(), 'What it says of the one before is of no use.');
        // One that does not exist is not chosen, and one behind is not the one of the appointments.
        calsync::choose($this->centre, 'nada');
        calsync::toggle_behind($this->centre, 'sec');
        calsync::toggle_behind($this->centre, 'adm');
        $this->assertSame(['sec', ['adm']], [calsync::config($this->centre)['calendar'], calsync::config($this->centre)['behind']]);
    }

    /**
     * What there is in the other calendars: behind the appointments, and said when one falls on it.
     */
    public function test_behind(): void {
        $zone = \core_date::get_server_timezone_object();
        $day = (new \DateTimeImmutable('@' . ($this->now + 3 * DAYSECS)))->setTimezone($zone)->setTime(0, 0);
        while ((int) $day->format('N') > 5) {
            $day = $day->modify('+1 day');
        }
        $at = fn(int $hour) => $day->getTimestamp() + $hour * HOURSECS;
        calsync::receive($this->centre, ['behind' => [
            ['calendar' => 'Secretaría', 'title' => 'Claustro', 'start' => $at(9), 'end' => $at(11), 'allday' => false],
            ['calendar' => 'Secretaría', 'title' => '', 'start' => $at(12), 'end' => $at(13), 'allday' => false],
            ['calendar' => 'General', 'title' => 'Semana de la ciencia', 'start' => $day->format('Y-m-d'),
                'end' => $day->modify('+2 days')->format('Y-m-d'), 'allday' => true],
            ['calendar' => 'General', 'title' => 'Hace mucho', 'start' => $at(0) - 60 * DAYSECS, 'end' => $at(1) - 60 * DAYSECS],
        ]], $this->now);
        $cid = (int) $this->centre->id;
        $this->assertStringContainsString('Claustro (Secretaría)', busy::clash($cid, $at(10), $at(10) + 45 * MINSECS));
        $this->assertStringContainsString(get_string('busy_untitled', 'local_oksigeniaeguahe'), busy::clash($cid, $at(12), $at(13)));
        $this->assertSame('', busy::clash($cid, $at(11), $at(12)));
        $there = busy::by_day($cid, [$day->getTimestamp(), $day->modify('+1 day')->getTimestamp()]);
        $this->assertSame(['Semana de la ciencia', 'Claustro', get_string('busy_untitled', 'local_oksigeniaeguahe')], array_column(
            $there[$day->getTimestamp()],
            'title'
        ));
        $this->assertCount(1, $there[$day->modify('+1 day')->getTimestamp()]);
        // In every view, and out of it when the person takes it away.
        foreach (panel_agenda::VIEWS as $view) {
            $context = panel_agenda::context($this->centre, true, $day->format('Y-m-d'), 0, $this->now, $view);
            $this->assertSame($view, $context['view']);
            $titles = array_merge(...array_map(fn($d) => array_column($d['behind'], 'title'), $context['days']));
            $this->assertSame($view !== 'agenda', in_array('Claustro', $titles, true), $view);
        }
        $context = panel_agenda::context($this->centre, true, $day->format('Y-m-d'), 0, $this->now, 'mes', false);
        $this->assertSame([], array_merge(...array_column($context['days'], 'behind')));
        $this->assertTrue($context['hasbehind']);
        $this->assertSame(0, count($context['days']) % $context['columns']);
        // Giving one: the hour proposed is free of it.
        [$household] = $this->visit($at(0) + 20 * DAYSECS);
        $give = panel_give::context(households::full($household), $this->centre, null, [], false, $this->now);
        $days = array_column($give['days'], null, 'key');
        $this->assertStringContainsString('11:00', $days[$day->format('Y-m-d')]['first']);
    }

    /**
     * A calendar file, with what repeats.
     */
    public function test_file(): void {
        $this->setTimezone('Atlantic/Canary');
        $now = make_timestamp(2026, 11, 25, 10);
        $file = "BEGIN:VCALENDAR\r\nX-WR-CALNAME:Eventos\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Madrid:20261126T100000\r\n" .
            "DTEND;TZID=Europe/Madrid:20261126T110000\r\nSUMMARY:Claustro\\, sala 2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\n" .
            "DTSTART:20261123T090000Z\r\nDTEND:20261123T100000Z\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4\r\n" .
            "EXDATE:20261130T090000Z\r\nSUMMARY:Ta\r\n ller\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261208\r\n" .
            "DTEND;VALUE=DATE:20261209\r\nSUMMARY:Festivo\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART:20261127T090000Z\r\n" .
            "STATUS:CANCELLED\r\nSUMMARY:Anulado\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $read = busy::read_ics($file, $now);
        $this->assertSame(['Claustro, sala 2', 'Taller', 'Taller', 'Taller', 'Festivo'], array_column($read, 'title'));
        $this->assertSame('Eventos', $read[0]['calendar']);
        $this->assertSame(make_timestamp(2026, 11, 26, 9), $read[0]['start'], 'In the time of the centre.');
        $this->assertSame([23, 25, 2], array_map(fn($r) => (int) userdate($r['start'], '%d'), array_slice($read, 1, 3)));
        $this->assertTrue($read[4]['allday']);
        $this->assertSame(DAYSECS, $read[4]['end'] - $read[4]['start']);
        $this->assertSame([], busy::read_ics('nada', $now));
    }

    /**
     * The private door: only with its key, only what is JSON; and the script carries its address.
     */
    public function test_script(): void {
        $script = teamcalendar::script($this->centre);
        $this->assertStringContainsString(teamcalendar::url($this->centre, 'json'), $script);
        foreach (['CalendarApp.getAllCalendars()', "op.do === 'delete'", 'everyMinutes(10)'] as $part) {
            $this->assertStringContainsString($part, $script);
        }
    }

    /**
     * Two runs at once do not write it twice; if it is there twice, the one too many goes; and it is written again if it never came.
     */
    public function test_written_once(): void {
        $start = $this->now + 3 * DAYSECS;
        [, $event] = $this->visit($start);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $said = ['calendar' => 'adm', 'events' => [], 'behind' => []];
        $first = calsync::receive($this->centre, $said, $this->now)['ops'];
        $second = calsync::receive($this->centre, $said, $this->now + 5)['ops'];
        $this->assertSame([['create'], []], [array_column($first, 'do'), array_column($second, 'do')]);
        $this->assertSame(['create'], array_column(calsync::receive($this->centre, $said, $this->now + HOURSECS)['ops'], 'do'));
        $said['events'] = [['uid' => $uid, 'n' => 0, 'start' => $start - HOURSECS, 'end' => $start], ['uid' => $uid, 'n' => 1, 'start' => $start,
            'end' => $start + 45 * MINSECS]];
        $ops = calsync::receive($this->centre, $said, $this->now + HOURSECS)['ops'];
        $this->assertSame([['do' => 'delete', 'uid' => $uid, 'n' => 0]], array_values(array_filter($ops, fn($op) => $op['do'] !== 'text')));
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
        $this->assertSame('', calsync::stopped($this->centre, $this->now + HOURSECS));
        $this->assertNotSame('', calsync::stopped($this->centre, $this->now + DAYSECS));
    }

    /**
     * Shorter than half an hour it lasts what it lasts: it says how long, and once accepted it is not asked again.
     */
    public function test_shorter(): void {
        $start = $this->now + 3 * DAYSECS;
        [$household, $event] = $this->visit($start);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $this->script();
        $this->script();
        $this->google[$uid] = [$start, $start + 20 * MINSECS];
        $this->script();
        $text = calsync::questions($this->centre, true, $this->now)[0]['text'];
        $this->assertStringContainsString('20', $text);
        appointments::move($household, $this->centre, (int) $event->id, $start, 20, 0, 'Secretaría', false);
        $this->assertSame([], calsync::questions($this->centre, true, $this->now), 'Already as it says there.');
        $this->assertSame([], $this->script());
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
    }

    /**
     * Moved in both between two times, a person says; deleted there and then changed here, it is written again.
     */
    public function test_both_at_once(): void {
        $start = $this->now + 3 * DAYSECS;
        [$household, $event] = $this->visit($start);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $this->script();
        $this->script();
        $this->google[$uid] = [$start + DAYSECS, $start + DAYSECS + 45 * MINSECS];
        appointments::move($household, $this->centre, (int) $event->id, $start + HOURSECS, 45, 0, 'Secretaría', false);
        $this->assertSame([], $this->script());
        $this->assertCount(1, calsync::questions($this->centre, true, $this->now));
        calsync::restore($this->centre, (int) $event->id);
        $this->assertSame(["set $uid"], $this->script());
        $this->script();
        unset($this->google[$uid]);
        $this->script();
        $this->assertTrue(calsync::questions($this->centre, true, $this->now)[0]['deleted']);
        appointments::move($household, $this->centre, (int) $event->id, $start + 2 * HOURSECS, 45, 0, 'Secretaría', false);
        $this->assertSame(["create $uid"], $this->script());
        $this->assertSame([], calsync::questions($this->centre, true, $this->now));
    }

    /**
     * A family that erased its data is out of the calendar, also its visits that went by, near and long ago.
     */
    public function test_erased(): void {
        global $DB;
        [$household, $event] = $this->visit($this->now + 3 * DAYSECS);
        $uid = 'eguahe-' . $this->centre->id . '-' . $event->id;
        $this->script();
        $this->script();
        $DB->update_record(events::T, (object) ['id' => $event->id, 'timestart' => $this->now - DAYSECS, 'timeend' => $this->now - DAYSECS + HOURSECS]);
        $this->google[$uid] = [$this->now - DAYSECS, $this->now - DAYSECS + HOURSECS];
        $this->script();
        households::erase(households::get((int) $household->id, 0));
        $this->assertSame(["delete $uid"], $this->script());
        $said = ['calendar' => 'adm', 'events' => [], 'behind' => []];
        $this->assertSame([$uid], calsync::receive($this->centre, $said, $this->now)['erase']);
        $this->assertSame([], calsync::receive($this->centre, $said + ['erased' => [$uid]], $this->now)['erase']);
    }

    /**
     * Whoever receives is in the calendar of the team, also when the families do not see them.
     */
    public function test_hosts_of_the_team(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Marta', 'lastname' => 'Soler']);
        $member = (int) $DB->insert_record(centres::MEMBERS, (object) ['centreid' => $this->centre->id, 'userid' => $user->id, 'timecreated' => time()]);
        [$household] = $this->visit($this->now + 3 * DAYSECS);
        appointments::give($household, $this->centre, $this->now + 4 * DAYSECS, 45, $member, '', 2, false);
        $this->assertStringContainsString('Marta Soler', teamcalendar::items($this->centre, $this->now)[0]['description']);
    }

    /**
     * One time changed of something that repeats is not at its old hour too.
     */
    public function test_one_time_changed(): void {
        $now = make_timestamp(2026, 11, 25, 8, 0, 0, 'UTC');
        $ics = implode("\r\n", ['BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'UID:a', 'DTSTART:20261123T090000Z', 'DTEND:20261123T100000Z',
            'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Claustro', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:a', 'RECURRENCE-ID:20261130T090000Z',
            'DTSTART:20261130T150000Z', 'DTEND:20261130T160000Z', 'SUMMARY:Claustro', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:a',
            'RECURRENCE-ID:20261207T090000Z', 'STATUS:CANCELLED', 'DTSTART:20261207T090000Z', 'SUMMARY:Claustro', 'END:VEVENT', 'END:VCALENDAR']);
        $hours = array_map(fn($i) => gmdate('md Hi', $i['start']), busy::read_ics($ics, $now));
        sort($hours);
        $this->assertSame(['1123 0900', '1130 1500'], $hours);
    }
}
