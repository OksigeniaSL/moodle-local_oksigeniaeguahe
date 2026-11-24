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

use local_oksigeniaeguahe\local\attend;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\chatcontext;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\export;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\pulse;
use local_oksigeniaeguahe\local\recognise;
use local_oksigeniaeguahe\local\sheetrows;
use local_oksigeniaeguahe\local\teamnotes;

/**
 * The dates of the sheets: a day without its hour never has one, what the team writes in its sheet is not something the
 * family said, the visit a row gives follows the row, and a sheet that loses its row of titles says so at once.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\entries
 * @covers     \local_oksigeniaeguahe\local\teamnotes
 * @covers     \local_oksigeniaeguahe\local\pulse
 * @covers     \local_oksigeniaeguahe\local\sheetrows
 */
#[\PHPUnit\Framework\Attributes\CoversClass(entries::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(teamnotes::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pulse::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sheetrows::class)]
final class sheetdays_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var \stdClass A sheet the team keeps, with a column for the hour. */
    protected $sheet;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $this->centre = centres::get($cid);
        $this->sheet = entries::get(entries::create($cid, 'google', 'Hoja de visitas'), $cid);
        $DB->set_field(entries::T, 'teamsheet', 1, ['id' => $this->sheet->id]);
        $DB->set_field(entries::T, 'questions', json_encode(['id', 'created_time', 'ad_id', 'ad_name', 'campaign_name', 'form_id',
            'platform', 'Nombre y apellidos', 'E-mail', 'Teléfono', 'Priority', 'Hora', 'FECHA', 'Tutor 1', 'Nivel',
            'OBSERVACIONES']), ['id' => $this->sheet->id]);
        $this->sheet = entries::get((int) $this->sheet->id, $cid);
    }

    /**
     * A contact of an ad, as the sheet brings it.
     *
     * @param array $more
     * @return array
     */
    protected function ad(array $more = []): array {
        return $more + ['id' => 'l:111222333', 'created_time' => date('c', time() - 2 * DAYSECS), 'ad_id' => 'ag:120000001',
            'ad_name' => 'Anuncio', 'campaign_name' => 'Captación', 'form_id' => 'f:220000001', 'platform' => 'ig',
            'Nombre y apellidos' => 'Noa Ramos', 'E-mail' => 'noa@example.com', 'Teléfono' => 'p:+34600111222'];
    }

    /**
     * A day some days from today, as the sheet writes it.
     *
     * @param int $days
     * @return string
     */
    protected function day(int $days): string {
        return userdate(time() + $days * DAYSECS, '%d/%m/%Y', 99, false);
    }

    /**
     * The lines of the history of a family.
     *
     * @param int $id
     * @return \stdClass[]
     */
    protected function lines(int $id): array {
        global $DB;
        return array_values($DB->get_records(households::CONTACTS, ['householdid' => $id], 'id ASC'));
    }

    /**
     * Sends the line of time back, as if the last sending of the sheet had been some hours ago.
     *
     * @param int $hours
     */
    protected function hours_ago(int $hours): void {
        global $DB;
        $DB->execute('UPDATE {' . teamnotes::T . '} SET seen = seen - ?, timecreated = timecreated - ?', [$hours * HOURSECS,
            $hours * HOURSECS]);
    }

    /**
     * A date is read with its hour when it is written, and only as a day when it is not.
     */
    public function test_moment(): void {
        [$time, $dayonly] = recognise::moment('22/11/2026 8:30:52');
        $this->assertFalse($dayonly);
        $this->assertSame('2026-11-22 08:30', userdate($time, '%Y-%m-%d %H:%M', 99, false));
        [$time, $dayonly] = recognise::moment('7/11/26, 3:05 p. m.');
        $this->assertFalse($dayonly);
        $this->assertSame('2026-11-07 15:05', userdate($time, '%Y-%m-%d %H:%M', 99, false));
        [$time, $dayonly] = recognise::moment('19/11/2026');
        $this->assertTrue($dayonly);
        $this->assertSame('2026-11-19', userdate($time, '%Y-%m-%d', 99, false));
        [$time, $dayonly] = recognise::moment('11/6/26', true);
        $this->assertTrue($dayonly);
        $this->assertSame('2026-11-06', userdate($time, '%Y-%m-%d', 99, false));
        [$time, $dayonly] = recognise::moment('2026-11-06');
        $this->assertTrue($dayonly);
        $this->assertSame('2026-11-06', userdate($time, '%Y-%m-%d', 99, false));
        $this->assertSame([gmmktime(17, 13, 48, 11, 23, 2026), false], recognise::moment('2026-11-23T12:13:48-05:00'));
        $this->assertNull(recognise::moment('mañana por la tarde'));
        $this->assertTrue(teamnotes::has_hour('11:00'));
        $this->assertTrue(teamnotes::has_hour('17h'));
        $this->assertFalse(teamnotes::has_hour('por la tarde'));
    }

    /**
     * A form that only writes the day: nowhere in Eguahe is there an hour of it, and once it writes the hour, it has it.
     */
    public function test_a_day_has_no_hour(): void {
        global $DB, $USER;
        $form = entries::get(entries::create((int) $this->centre->id, 'google', 'Formularios'), (int) $this->centre->id);
        $row = ['Marca temporal' => $this->day(-3), 'Nombre y apellidos' => 'Eva Sanz', 'Correo' => 'eva@example.com',
            'Teléfono' => '600777888', 'Mensaje' => '¿Hay comedor?'];
        centres::add_member((int) $this->centre->id, (int) $USER->id);
        [$household, $new] = entries::receive($form, $row, '', null, true);
        $this->assertTrue($new);
        $this->assertGreaterThan(0, (int) $household->waitingsince);
        $this->assertSame(1, (int) $household->waitingdayonly);
        $this->assertSame(1, (int) $this->lines((int) $household->id)[0]->dayonly);
        $now = time();
        $hour = panel::hour((int) $household->waitingsince);
        $household->children = [];
        $household->adults = [];
        $rowof = panel_inbox::rows([(int) $household->id => $household], $now)[(int) $household->id];
        $this->assertSame('', $rowof['hour']);
        $this->assertSame('', $rowof['stamp']['hour']);
        $said = json_encode([panel_inbox::check($this->centre)['sub'],
            chatcontext::lines('+34600777888', (int) $USER->id, $now),
            panel::wait_words((int) $household->waitingsince, $now, true),
            panel::when_words((int) $household->waitingsince, $now, true)], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($hour, $said);
        // The file, its history and the download of the family.
        $file = panel_household::context($household, $this->centre, true, '', $now);
        $this->assertStringNotContainsString($hour, json_encode([$file['head'] ?? '', $file['history'] ?? '']));
        $download = json_encode(households::export($household));
        $this->assertStringContainsString(userdate((int) $household->waitingsince, '%Y-%m-%d', 99, false), $download);
        $this->assertStringNotContainsString('T12:00:00', $download);
        $entry = entries::get((int) $form->id, (int) $this->centre->id);
        $this->assertSame(1, (int) $entry->lastdayonly);
        $this->assertStringNotContainsString($hour, pulse::line(pulse::of($entry, $now) + ['entry' => $entry], $now));
        // It comes again with its hour: the same row, the hour it did not have, and nothing twice.
        $row['Marca temporal'] .= ' 8:41:07';
        [$same, $new] = entries::receive($form, $row, '', null, true);
        $this->assertFalse($new);
        $this->assertSame((int) $household->id, (int) $same->id);
        $lines = $this->lines((int) $household->id);
        $this->assertCount(1, $lines);
        $this->assertSame(0, (int) $lines[0]->dayonly);
        $this->assertSame('08:41', userdate((int) $lines[0]->timecreated, '%H:%M', 99, false));
        $fresh = households::get((int) $household->id, (int) $this->centre->id);
        $this->assertSame((int) $lines[0]->timecreated, (int) $fresh->waitingsince);
        $this->assertSame(0, (int) $fresh->waitingdayonly);
        $this->assertSame(0, $DB->count_records(households::CONTACTS, ['dayonly' => 1]));
    }

    /**
     * What only has its day measures no hours: how long a first answer takes is never counted from it.
     */
    public function test_a_day_measures_no_hours(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $make = function (int $in, int $out, int $dayonly) use ($DB, $cid): void {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            foreach ([['in', $in], ['out', $out]] as [$direction, $time]) {
                $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid, 'householdid' => $id,
                    'direction' => $direction, 'channel' => 'email', 'body' => 'Hola', 'dayonly' => $dayonly,
                    'timecreated' => $time]);
            }
        };
        $start = time() - 3 * DAYSECS;
        $make($start, $start + 2 * HOURSECS, 0);
        $make($start, $start, 1);
        $response = metrics::response($cid);
        $this->assertSame(2.0, $response['median']);
        $this->assertSame(2, $response['answered']);
        $this->assertSame(1, $response['bychannel']['email']['count']);
    }

    /**
     * A row of the sheet that is not of an ad is the team's: a note, the visit of its day, and nobody waiting; the contact
     * of the ad is still the family's.
     */
    public function test_rows_of_the_team(): void {
        [$household] = entries::receive($this->sheet, $this->ad(['Priority' => 'Wasap']), '', null, true);
        $this->assertSame('in', $this->lines((int) $household->id)[0]->direction);
        $this->assertSame('meta', $household->firstchannel);
        // The sheet writes its visit, with the hour still empty (the script never sends an empty cell).
        $day = $this->day(-1);
        [$same] = entries::receive($this->sheet, ['FECHA' => $day, 'Tutor 1' => 'Noa', 'Nivel' => 'Guardería',
            'TELÉFONO' => '600111222'], '', null, true);
        $this->assertSame((int) $household->id, (int) $same->id);
        $lines = $this->lines((int) $household->id);
        $in = array_filter($lines, fn($l) => $l->direction === 'in');
        $this->assertCount(1, $in, 'Only what the family left in the ad is of the family.');
        $row = array_values(array_filter($lines, fn($l) => $l->direction === 'note' && (int) $l->entryid))[0];
        $this->assertStringContainsString('Hoja de visitas', $row->body);
        $this->assertStringContainsString('Tutor 1: Noa', $row->body);
        $this->assertSame(1, (int) $row->dayonly);
        $booked = array_values(array_filter($lines, fn($l) => strpos($l->body, 'Visit booked for') === 0))[0];
        $this->assertStringNotContainsString('12:00', $booked->body);
        $this->assertSame(1, (int) $booked->dayonly);
        $this->assertSame(0, (int) $same->waitingsince, 'The team gave them a visit: they no longer wait.');
        // A box left empty («FALSE») does not say they did not come, when their hour is not even written.
        entries::receive($this->sheet, ['FECHA' => $day, 'Tutor 1' => 'Noa', 'Nivel' => 'Guardería', 'TELÉFONO' => '600111222',
            'ASISTENCIA' => 'FALSE'], '', null, true);
        $this->assertNotContains('absent', array_keys(entries::$noted));
        // A family only of the team: nobody waits, and its first door is the sheet.
        [$other, $new] = entries::receive($this->sheet, ['FECHA' => $this->day(-2), 'Hora' => '10:30', 'Tutor 1' => 'Iris Gil',
            'TELÉFONO' => '600111333'], '', null, true);
        $this->assertTrue($new);
        $this->assertSame(0, (int) $other->waitingsince);
        $other->adults = $other->children = [];
        $this->assertSame('', panel_inbox::rows([(int) $other->id => $other], time())[(int) $other->id]['hour']);
        $this->assertNotContains('in', array_column($this->lines((int) $other->id), 'direction'));
        // It comes through the sheet all the same, for its tape.
        $tapes = pulse::tapes($this->centre, pulse::of_centre($this->centre, time()), time());
        $this->assertSame(1, array_sum(array_map(fn($d) => $d['n'] > 0 && $d['n'] < 9 ? (int) ($d['n'] >= 1) : 0, array_slice(
            $tapes[(int) $this->sheet->id],
            -3,
            1
        ))));
        // A word in the column of the hour is no hour.
        [$third] = entries::receive($this->sheet, ['FECHA' => $this->day(-2), 'Hora' => 'por la tarde', 'Tutor 1' => 'Lía Gil',
            'TELÉFONO' => '600111444'], '', null, true);
        $this->assertStringNotContainsString('12:00', json_encode(array_column($this->lines((int) $third->id), 'body')));
        // Without a column for the hour, the day of a row is only when it was written.
        $plain = entries::get(entries::create((int) $this->centre->id, 'google', 'Otra hoja'), (int) $this->centre->id);
        $plain->teamsheet = 1;
        $read = entries::read($plain, $this->centre, ['FECHA' => $day, 'Tutor 1' => 'Ana Gil', 'TELÉFONO' => '600111555']);
        $this->assertSame(0, $read->appointment);
        // A form the team writes beside: what it stamps is of the family.
        [$formed] = entries::receive($this->sheet, ['Marca temporal' => $day . ' 9:15:00', 'Nombre y apellidos' => 'Sol Díaz',
            'Correo' => 'sol@example.com', 'OBSERVACIONES' => 'Llamar'], '', null, true);
        $this->assertSame('in', $this->lines((int) $formed->id)[0]->direction);
        $this->assertGreaterThan(0, (int) $formed->waitingsince);
    }

    /**
     * The same row, with its hour added later or its day changed: one visit for the team, that says what it was before; a
     * family with two rows keeps its two visits.
     */
    public function test_visit_follows_its_row(): void {
        global $DB;
        $row = fn(int $days, string $hour = '') => array_filter(['FECHA' => $this->day($days), 'Hora' => $hour,
            'Tutor 1' => 'Iris Gil', 'Nivel' => 'Guardería', 'TELÉFONO' => '600111333']);
        [$household] = entries::receive($this->sheet, $row(5), '', null, true);
        $id = (int) $household->id;
        $bodies = fn() => array_values(array_filter(
            array_column($this->lines($id), 'body'),
            fn($b) => strpos($b, 'Visit booked') !== false
        ));
        $this->assertCount(1, $bodies());
        $this->assertSame('Their visit', attend::open_promise($id)->body);
        // Its hour: at once, and the promise takes it.
        entries::receive($this->sheet, $row(5, '10:00'), '', null, true);
        $said = $bodies();
        $this->assertCount(2, $said);
        $this->assertStringStartsWith('Before: ', $said[0]);
        $this->assertStringContainsString('10:00 (before: ', $said[1]);
        $this->assertSame('Their visit, at 10:00', attend::open_promise($id)->body);
        $this->assertCount(1, array_filter($this->lines($id), fn($l) => (int) $l->entryid), 'The row is written down once.');
        // Another day, some days later: the old one no longer comes with the sheet.
        $this->hours_ago(24);
        entries::receive($this->sheet, $row(7, '9:30'), '', null, true);
        $said = $bodies();
        $this->assertCount(3, $said);
        $this->assertStringStartsWith('Before: ', $said[1]);
        $this->assertStringContainsString('9:30 (before: ', str_replace('09:30', '9:30', $said[2]));
        $this->assertSame('Their visit, at 09:30', attend::open_promise($id)->body);
        $this->assertSame(
            userdate(time() + 7 * DAYSECS, '%Y-%m-%d', 99, false),
            userdate((int) attend::open_promise($id)->dueday, '%Y-%m-%d', 99, false)
        );
        $this->assertCount(1, array_filter($this->lines($id), fn($l) => (int) $l->entryid));
        // The sheet brings both rows: both stand, night after night.
        foreach ([24, 24] as $hours) {
            $this->hours_ago($hours);
            entries::receive($this->sheet, $row(7, '9:30'), '', null, true);
            entries::receive($this->sheet, $row(5, '10:00'), '', null, true);
            $said = $bodies();
            $this->assertCount(3, $said);
            $this->assertStringStartsWith('Before: ', $said[0]);
            $this->assertStringStartsWith('Visit booked', $said[1]);
            $this->assertStringStartsWith('Visit booked', $said[2]);
            $this->assertStringNotContainsString('before: ', $said[2]);
        }
        $this->assertSame(2, $DB->count_records_select(teamnotes::T, 'householdid = ? AND visit > 0 AND replacedby = 0', [$id]));
    }

    /**
     * What a person of the team writes down by hand is never said as if the family had written; it waits only if they say so.
     */
    public function test_noted_by_hand(): void {
        global $USER;
        $cid = (int) $this->centre->id;
        $household = households::get(households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'phone']), $cid);
        households::received(
            $household,
            'phone',
            get_string('noted_by_team', 'local_oksigeniaeguahe'),
            true,
            null,
            0,
            '',
            '',
            true,
            false,
            (int) $USER->id
        );
        $this->assertGreaterThan(0, (int) households::get((int) $household->id, $cid)->waitingsince);
        $history = panel_household::context($household, $this->centre, true)['history'];
        $this->assertTrue($history[0]['team']);
        $this->assertFalse($history[0]['family']);
        $this->assertSame(fullname($USER), $history[0]['who']);
        $this->assertSame('', $history[0]['body']);
        // What the family said to a person: the family's, with who wrote it down.
        households::received(
            $household,
            'phone',
            '¿Hay comedor?',
            true,
            null,
            0,
            '',
            '',
            true,
            false,
            (int) $USER->id
        );
        $history = panel_household::context($household, $this->centre, true)['history'];
        $last = end($history);
        $this->assertTrue($last['family']);
        $this->assertStringContainsString(fullname($USER), $last['how']);
    }

    /**
     * A sheet that lost its row of titles: nothing of a family is kept as a question, and it is said at once, until the
     * whole sheet comes with them.
     */
    public function test_lost_titles(): void {
        global $DB;
        $before = entries::questions($this->sheet);
        $bad = ['38611' => '38600', 'l:111222333' => 'l:444555666', '2026-11-23T12:13:48-05:00' => '2026-11-24T08:00:00-05:00',
            'ig' => 'fb', 'Zoe' => 'Max', 'zoe@example.com' => 'max@example.com', 'p:+34600999888' => 'p:+34600999777'];
        [$nobody] = entries::receive($this->sheet, $bad, '', null, false);
        $this->assertNull($nobody);
        $this->assertTrue(entries::$headless);
        $kept = entries::get((int) $this->sheet->id, (int) $this->centre->id);
        $this->assertSame($before, entries::questions($kept));
        $this->assertStringNotContainsString('max@example.com', (string) $kept->examples);
        $now = time();
        pulse::called($kept, 0, 1, false, [], $now, true);
        $pulse = pulse::of($kept, $now) + ['entry' => $kept];
        $this->assertSame('headless', $pulse['state']);
        $this->assertSame($now, $pulse['since']);
        $this->assertStringContainsString('row of titles', pulse::words($pulse, $now));
        $this->assertSame('Titles lost', pulse::tag($pulse, $now)['word']);
        $this->assertCount(1, pulse::silent($this->centre, $now));
        // Every night it is still so: the same silence, with the same key.
        $night = $now + 18 * HOURSECS;
        pulse::called($kept, 5, 0, true, [], $night);
        pulse::called($kept, 0, 1, true, [], $night + 5, true);
        pulse::called($kept, 3, 0, true, [], $night + 10);
        $again = pulse::of($kept, $night + 20);
        $this->assertSame('headless', $again['state']);
        $this->assertSame($pulse['key'], $again['key']);
        // A row of another tab, by day, does not say it is solved; the whole sheet with its titles does.
        pulse::called($kept, 1, 0, false, [], $night + 3 * HOURSECS);
        $this->assertSame('headless', pulse::of($kept, $night + 3 * HOURSECS)['state']);
        pulse::called($kept, 9, 0, true, [], $night + 4 * HOURSECS);
        $this->assertNotSame('headless', pulse::of($kept, $night + 4 * HOURSECS)['state']);
        // One that never sends everything again is solved by the next thing it brings.
        $hook = entries::get(entries::create((int) $this->centre->id, 'hook', 'Otra'), (int) $this->centre->id);
        pulse::called($hook, 0, 1, false, [], $now, true);
        $this->assertSame('headless', pulse::of($hook, $now)['state']);
        pulse::called($hook, 1, 0, false, [], $now + 60);
        $this->assertNotSame('headless', pulse::of($hook, $now + 60)['state']);
        $this->assertSame(0, $DB->count_records(households::T, ['centreid' => $this->centre->id]));
    }

    /**
     * The «id» of the ads missing, or their titles twice: said at once, and the family comes in, as the ad's.
     */
    public function test_titles_without_id_or_twice(): void {
        $ad = $this->ad();
        unset($ad['id']);
        [$household, $new] = entries::receive($this->sheet, $ad, '', null, true);
        $this->assertTrue($new);
        $this->assertTrue(entries::$headless);
        $this->assertSame('in', $this->lines((int) $household->id)[0]->direction);
        // With everything in its place, nothing is said.
        entries::receive($this->sheet, $this->ad(['id' => 'l:777', 'E-mail' => 'ivo@example.com', 'Teléfono' => 'p:+34600000001']));
        $this->assertFalse(entries::$headless);
        // A row of the team has none of those titles.
        entries::receive($this->sheet, ['FECHA' => $this->day(1), 'Tutor 1' => 'Ana Gil', 'TELÉFONO' => '600222333']);
        $this->assertFalse(entries::$headless);
        // The second set of titles, filled: read as the first one.
        $twice = [];
        foreach ($this->ad(['id' => 'l:888', 'E-mail' => 'sol@example.com', 'Teléfono' => 'p:+34600000002']) as $title => $value) {
            $twice[$title . ' (2)'] = $value;
        }
        [$second, $new] = entries::receive($this->sheet, $twice, '', null, true);
        $this->assertTrue($new);
        $this->assertTrue(entries::$headless);
        $this->assertSame('meta', $second->firstchannel);
        $this->assertSame('in', $this->lines((int) $second->id)[0]->direction);
        // Two people in one row of a form are not that.
        [$answers, $lost] = entries::headings($this->sheet, ['Teléfono de contacto' => '600111222',
            'Teléfono de contacto (2)' => '600111333']);
        $this->assertFalse($lost);
        $this->assertCount(2, $answers);
    }

    /**
     * A contact of an ad is the same one with its identifier and without it, whichever came first.
     */
    public function test_same_contact_without_its_id(): void {
        global $DB;
        $ad = $this->ad();
        $bare = array_diff_key($ad, ['id' => 1]);
        [$household, $new] = entries::receive($this->sheet, $ad, '', null, true);
        $this->assertTrue($new);
        [$same, $new] = entries::receive($this->sheet, $bare, '', null, true);
        $this->assertFalse($new);
        $this->assertSame((int) $household->id, (int) $same->id);
        $this->assertCount(1, $this->lines((int) $household->id));
        // As it was kept before this was: only by its identifier.
        $DB->delete_records_select(entries::ARRIVALS, 'externalid <> ?', [hash('sha256', 'id:l:111222333')]);
        [$same, $new] = entries::receive($this->sheet, $bare, '', null, true);
        $this->assertFalse($new);
        $this->assertCount(1, $this->lines((int) $household->id));
        // And the other way round.
        $other = $this->ad(['id' => 'l:999', 'E-mail' => 'ivo@example.com', 'Teléfono' => 'p:+34600000003']);
        [$ivo, $new] = entries::receive($this->sheet, array_diff_key($other, ['id' => 1]), '', null, true);
        $this->assertTrue($new);
        [$same, $new] = entries::receive($this->sheet, $other, '', null, true);
        $this->assertFalse($new);
        $this->assertCount(1, $this->lines((int) $ivo->id));
        // The same person, another day, is another contact.
        [, $new] = entries::receive($this->sheet, $this->ad(['id' => 'l:1000', 'E-mail' => 'ivo@example.com',
            'created_time' => date('c', time() - HOURSECS)]), '', null, true);
        $this->assertTrue($new);
        $this->assertSame(2, $DB->count_records(households::T, ['centreid' => $this->centre->id]));
    }

    /**
     * What the titles lost before left in an entry is taken out, and nothing else.
     */
    public function test_clean_titles(): void {
        global $DB;
        $questions = ['id', 'created_time', 'Nombre y apellidos', 'Respuesta 1', '38611', 'l:111222333',
            '2026-11-23T12:13:48-05:00', 'ig', 'Zoe', 'zoe@example.com', 'p:+34600999888', 'CREATED', 'Observaciones'];
        $DB->update_record(entries::T, (object) ['id' => $this->sheet->id, 'questions' => json_encode($questions),
            'examples' => json_encode(['Zoe' => 'Max', 'created_time' => '2026-11-20T10:00:00Z', 'Respuesta 1' => '600111222'])]);
        $this->assertSame(1, sheetrows::clean_titles());
        $entry = entries::get((int) $this->sheet->id, (int) $this->centre->id);
        $this->assertSame(
            ['id', 'created_time', 'Nombre y apellidos', 'Respuesta 1', 'Observaciones'],
            entries::questions($entry)
        );
        $this->assertSame(['created_time' => '2026-11-20T10:00:00Z'], entries::examples($entry));
        $this->assertSame(0, sheetrows::clean_titles());
    }

    /**
     * What was already there: lines at noon sharp are only a day, and the rows of the team kept as messages become notes;
     * a family waits for what it truly wrote.
     */
    public function test_what_was_there(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $sheet = (int) $this->sheet->id;
        $noon = (new \DateTime('now', \core_date::get_server_timezone_object()))->modify('-4 days')->setTime(12, 0)->getTimestamp();
        $line = function (int $id, string $way, int $time, int $entryid, string $body, string $from = '') use ($DB, $cid): int {
            return (int) $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid, 'householdid' => $id,
                'direction' => $way, 'channel' => 'meta', 'body' => $body, 'entryid' => $entryid,
                'detail' => $from ?: null, 'timecreated' => $time]);
        };
        $make = function (int $since) use ($DB, $cid): int {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'meta']);
            $DB->set_field(households::T, 'waitingsince', $since, ['id' => $id]);
            return $id;
        };
        $arrived = get_string('entry_arrived', 'local_oksigeniaeguahe', 'Hoja de visitas');
        // Only a row of the team: it waited for nothing.
        $one = $make($noon);
        $row = $line($one, 'in', $noon, $sheet, "$arrived\n\nTutor 1: Noa");
        // An ad too, that the team has not answered.
        $two = $make($noon);
        $adtime = $noon + 2 * HOURSECS + 7;
        $line($two, 'in', $noon, $sheet, "$arrived\n\nTutor 1: Ivo");
        $ad = $line($two, 'in', $adtime, $sheet, $arrived, 'Instagram · Captación');
        $DB->insert_record(entries::ARRIVALS, (object) ['centreid' => $cid, 'entryid' => $sheet, 'externalid' => 'x',
            'householdid' => $two, 'timecreated' => $adtime + DAYSECS]);
        // An ad the team answered after.
        $three = $make($noon);
        $line($three, 'in', $noon, $sheet, "$arrived\n\nTutor 1: Sol");
        $line($three, 'in', $adtime, $sheet, $arrived, 'Instagram · Captación');
        $line($three, 'out', $adtime + HOURSECS, 0, 'Hola');
        // One that waits by something else is left as it was.
        $four = $make($adtime);
        $line($four, 'in', $noon, $sheet, "$arrived\n\nTutor 1: Eva");
        $line($four, 'in', $adtime, 0, '¿Hay comedor?');
        $this->assertGreaterThanOrEqual(4, sheetrows::mark_days());
        $this->assertSame(1, (int) $DB->get_field(households::CONTACTS, 'dayonly', ['id' => $row]));
        $this->assertSame(0, (int) $DB->get_field(households::CONTACTS, 'dayonly', ['id' => $ad]));
        $this->assertSame(1, (int) $DB->get_field(households::T, 'waitingdayonly', ['id' => $one]));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'waitingdayonly', ['id' => $four]));
        // A dry run says the same and writes nothing.
        $dry = sheetrows::fix($this->centre, false);
        $this->assertSame('in', $DB->get_field(households::CONTACTS, 'direction', ['id' => $row]));
        $done = sheetrows::fix($this->centre, true);
        $this->assertSame($dry, $done);
        $this->assertSame(['sheets' => 1, 'unclear' => 0, 'lines' => 4, 'families' => 4, 'ads' => 2, 'waiting' => 4, 'free' => 2,
            'moved' => 1, 'after' => 2], $done);
        $note = $DB->get_record(households::CONTACTS, ['id' => $row]);
        $this->assertSame('note', $note->direction);
        $this->assertStringStartsWith(get_string('entry_noted', 'local_oksigeniaeguahe', 'Hoja de visitas'), $note->body);
        $this->assertStringContainsString('Tutor 1: Noa', $note->body);
        $since = fn(int $id) => (int) $DB->get_field(households::T, 'waitingsince', ['id' => $id]);
        $this->assertSame([0, $adtime, 0, $adtime], array_map($since, [$one, $two, $three, $four]));
        $this->assertSame(0, (int) $DB->get_field(households::T, 'waitingdayonly', ['id' => $two]));
        $this->assertSame('in', $DB->get_field(households::CONTACTS, 'direction', ['id' => $ad]));
        // Again, nothing.
        $this->assertSame(0, sheetrows::fix($this->centre, true)['lines']);
    }

    /**
     * The download of the families has days, never hours.
     */
    public function test_export_has_days(): void {
        $row = ['FECHA' => $this->day(-2), 'Tutor 1' => 'Iris Gil', 'TELÉFONO' => '600111333'];
        entries::receive($this->sheet, $row, '', null, true);
        $this->assertStringNotContainsString('12:00', json_encode(export::families($this->centre)));
    }
}
