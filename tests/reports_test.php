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

use local_oksigeniaeguahe\local\ai;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_report;
use local_oksigeniaeguahe\local\report_ai;
use local_oksigeniaeguahe\local\reports;
use local_oksigeniaeguahe\task\make_reports;

/**
 * The reports (0.39.0): the week and the school year of a centre, their figures against the same point of the period
 * before, what asks for attention, the advice of the rules, what the AI of the centre adds (with an answer of the test),
 * and the task that makes them each Monday. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(reports::class)]
final class reports_test extends \advanced_testcase {
    /**
     * Back to the provider and the clock of always.
     */
    protected function tearDown(): void {
        ai::$answer = null;
        make_reports::$testnow = null;
        parent::tearDown();
    }

    /**
     * A centre and a family that came in at a time, with a child in a course and a message of theirs.
     *
     * @param int $centreid
     * @param int $offerid
     * @param int $time
     * @param string $said
     * @return \stdClass The household.
     */
    protected function family(int $centreid, int $offerid, int $time, string $said = 'Hola, ¿tienen comedor?'): \stdClass {
        global $DB;
        $h = households::get(households::create($centreid, (object) ['lang' => 'es', 'firstchannel' => 'webform']), $centreid);
        $DB->set_field(households::T, 'timecreated', $time, ['id' => $h->id]);
        households::add_adult($h, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo', 'email' => 'ana' . $h->id .
            '@example.com']);
        households::add_child($h, 'Leo', $offerid);
        $DB->insert_record(households::CONTACTS, (object) ['centreid' => $centreid, 'householdid' => $h->id,
            'direction' => 'in', 'channel' => 'webform', 'body' => $said, 'userid' => 0, 'timecreated' => $time]);
        return households::get((int) $h->id, $centreid);
    }

    /**
     * The periods: the week from Monday to Monday, the school year from the month of the first day.
     */
    public function test_periods(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        $wednesday = make_timestamp(2026, 11, 11, 10, 30);
        [$from, $to] = reports::week($wednesday);
        $this->assertSame([make_timestamp(2026, 11, 2), make_timestamp(2026, 11, 9)], [$from, $to]);
        $this->assertSame([make_timestamp(2026, 11, 9), make_timestamp(2026, 11, 16)], reports::current(
            (object) ['firstday' => 0],
            'hot',
            $wednesday
        ));
        $centre = (object) ['firstday' => make_timestamp(2027, 9, 8)];
        $this->assertSame([make_timestamp(2025, 9, 1), make_timestamp(2026, 9, 1)], reports::school_year($centre, $wednesday));
        $this->assertSame([make_timestamp(2026, 9, 1), make_timestamp(2027, 9, 1)], reports::current($centre, 'cold', $wednesday));
        // Where the school year starts in February, the year runs from February.
        $south = (object) ['firstday' => make_timestamp(2027, 2, 22)];
        $this->assertSame(make_timestamp(2026, 2, 1), reports::current($south, 'cold', $wednesday)[0]);
    }

    /**
     * The week under way: what came in, came to see the centre, enrolled and left, against the week before at the same
     * point; what asks for attention, and the report kept and shown in the panel.
     */
    public function test_week(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $centre = centres::get($id);
        $now = time();
        [$from, $to] = reports::current($centre, 'hot', $now);
        $elapsed = $now - $from;
        // Three this week, one the week before at the same point, one the week before later than that.
        $mine = [];
        foreach ([0, 0, 0] as $i => $unused) {
            $mine[] = $this->family($id, $offer, $from + (int) ($elapsed * ($i + 1) / 4));
        }
        $this->family($id, $offer, $from - WEEKSECS + (int) ($elapsed / 2));
        $this->family($id, $offer, $from - 1);
        $start = $from + (int) ($elapsed / 3);
        $eventid = events::save($id, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas', 'place' => '',
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1, 'hosts' => []]);
        $signup = events::sign_up(events::get($eventid, $id), (int) $mine[0]->id, 2);
        $DB->set_field(events::SIGNUPS, 'attended', $start + 60, ['id' => $signup]);
        $child = $DB->get_record(households::CHILDREN, ['householdid' => $mine[1]->id]);
        households::set_stage($child, 'enrolled');
        $DB->set_field(households::CHILDREN, 'stagetime', $now - 60, ['id' => $child->id]);
        $DB->set_field(households::T, 'waitingsince', $now - 3 * DAYSECS, ['id' => $mine[2]->id]);

        $reportid = reports::make($centre, 'hot', $from, $to, $now, 2);
        $report = reports::get($reportid, $id);
        $this->assertSame('none', $report->aistatus, 'Without AI, the report is whole with the rules.');
        $figures = array_column($report->data['figures'], null, 'key');
        $this->assertSame(['3', '1', '1', '+1'], [$figures['arrived']['value'], $figures['came']['value'],
            $figures['enrolled']['value'], $figures['net']['value']]);
        $this->assertSame('1', $figures['arrived']['before'], 'The week before at the same point, not whole.');
        $this->assertSame('sube', $figures['arrived']['trend']);
        $this->assertStringContainsString('3', $report->data['sentence']);
        $this->assertNotFalse($report->data['sofar']);
        $this->assertSame('reply', $report->data['attention'][0]['kind']);
        $this->assertTrue($report->data['attention'][0]['late']);
        $this->assertCount(8, $report->data['pace']['rows']);
        $this->assertSame(1, $report->data['acts']['rows'][0]['came']);

        // The same period again: the same report, made again.
        $this->assertSame($reportid, reports::make($centre, 'hot', $from, $to, $now + 60));
        $context = panel_report::context($centre, reports::get($reportid, $id), true, $now);
        $this->assertTrue($context['report']);
        $this->assertNotFalse($context['aistatus']);
        foreach ($context['advice'] as $tip) {
            $this->assertSame('rule', $tip['from']);
        }
        // The design: the trend in words, and every bar between 0 and 1 on the scale of its chart.
        $figures = array_column($context['data']['figures'], null, 'key');
        $this->assertSame(get_string('panel_ct_sube', 'local_oksigeniaeguahe'), $figures['arrived']['trendtext']);
        foreach ($context['data']['funnel']['steps'] as $step) {
            $this->assertGreaterThanOrEqual(0, $step['v']);
            $this->assertLessThanOrEqual(1, max($step['v'], $step['bv']));
        }
        $this->assertSame(1.0, (float) max(array_column($context['data']['funnel']['steps'], 'v')));
        $this->assertSame(1, count(array_filter(array_column($context['data']['pace']['rows'], 'top'))));
        $course = $context['data']['places'][0];
        $this->assertLessThan(1, max($course['vt'], $course['vp'], $course['vh']), 'A little air above.');
        $this->assertCount(1, $context['others']);
    }

    /**
     * What the AI of the centre adds: asked once in a task with the texts without mails or phones, kept only as it
     * should be (themes the most said first, never counting more texts than it got), and shown with where it comes from.
     */
    public function test_ai(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $centre = centres::get($id);
        $now = time();
        [$from, $to] = reports::current($centre, 'hot', $now);
        $this->family($id, $offer, $from + 60, 'Escríbanme a ana@example.com o al 612 345 678; vi www.ejemplo.com');
        $this->family($id, $offer, $from + 120, '¿Tienen comedor?');
        $asked = [];
        // As the providers answer: a line, then the JSON inside a fence.
        $fence = str_repeat(chr(96), 3);
        ai::$answer = function (string $prompt) use (&$asked, $fence) {
            $asked[] = $prompt;
            return "Aquí va:\n" . $fence . "json\n" . json_encode(['reading' => 'Semana con preguntas por el comedor.',
                'themes' => [['theme' => 'Plazas', 'texts' => 1, 'sentence' => 'Preguntan por plazas.'],
                ['theme' => 'Comedor', 'texts' => 40, 'sentence' => 'Preguntan si hay comedor.']],
                'advice' => [['text' => 'Poner el comedor en la web', 'why' => 'Lo preguntan.']]]) . "\n" . $fence;
        };
        // Off for the centre: no AI, whatever the site has.
        $this->assertSame('none', reports::get(reports::make($centre, 'hot', $from, $to, $now), $id)->aistatus);
        global $DB;
        $DB->set_field(centres::T, 'aion', 1, ['id' => $id]);
        $centre = centres::get($id);
        $reportid = reports::make($centre, 'hot', $from, $to, $now);
        $this->assertSame('waiting', reports::get($reportid, $id)->aistatus);
        $this->runAdhocTasks(\local_oksigeniaeguahe\task\report_ai::class);
        $this->assertCount(1, $asked);
        $this->assertStringNotContainsString('ana@example.com', $asked[0]);
        $this->assertStringNotContainsString('612 345 678', $asked[0]);
        $this->assertStringNotContainsString('www.ejemplo.com', $asked[0]);
        $this->assertStringContainsString('¿Tienen comedor?', $asked[0]);
        $this->assertStringContainsString('ustedes', $asked[0], 'In Spanish, never «vosotros».');
        $report = reports::get($reportid, $id);
        $this->assertSame('done', $report->aistatus);
        $this->assertSame(['Comedor', 'Plazas'], array_column($report->ai['themes'], 'theme'), 'The most said first.');
        $this->assertSame(2, $report->ai['themes'][0]['texts'], 'Never more texts than it got.');
        $context = panel_report::context($centre, $report, true, $now);
        $this->assertSame('Semana con preguntas por el comedor.', $context['reading']);
        $this->assertStringContainsString('Test', $context['ainame'], 'The note is signed by the AI of the centre.');
        $last = end($context['advice']);
        $this->assertSame('ai', $last['from']);
        $this->assertFalse($context['aistatus']);
        // An answer that is not JSON is not kept; the rest of the report is.
        ai::$answer = fn() => 'No sé.';
        $reportid = reports::make($centre, 'hot', $from, $to, $now);
        $this->runAdhocTasks(\local_oksigeniaeguahe\task\report_ai::class);
        $this->assertSame('failed', reports::get($reportid, $id)->aistatus);
        $this->assertSame('[…] o al […]; vi […]', report_ai::clean('ana@example.com o al +34 612 345 678; vi https://x.es/a'));
    }

    /**
     * The task: each Monday the week that ended, once; the school year when it ended; and a report someone asked for half
     * way is made again when its period ends.
     */
    public function test_task(): void {
        global $DB;
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $centre = centres::get($id);
        $now = time();
        [$from, $to] = reports::week($now);
        $this->family($id, $offer, $from - 3 * DAYSECS);
        $this->family($id, $offer, reports::school_year($centre, $now)[0] + DAYSECS);
        // Asked half way through the week that has ended since.
        reports::make($centre, 'hot', $from, $to, $from + DAYSECS);
        make_reports::$testnow = $now;
        $this->expectOutputRegex('/hot report/');
        (new make_reports())->execute();
        $hot = $DB->get_record(reports::T, ['centreid' => $id, 'kind' => 'hot', 'periodfrom' => $from]);
        $this->assertSame($now, (int) $hot->timemodified, 'Made again, whole.');
        $this->assertTrue($DB->record_exists(reports::T, ['centreid' => $id, 'kind' => 'cold']), 'The school year that ended.');
        $count = $DB->count_records(reports::T, ['centreid' => $id]);
        (new make_reports())->execute();
        $this->assertSame($count, $DB->count_records(reports::T, ['centreid' => $id]), 'Once.');
        // The school year: twelve months, those to come marked, with the year before at sight.
        [$yfrom, $yto] = reports::current($centre, 'cold', $now);
        $cold = reports::get(reports::make($centre, 'cold', $yfrom, $yto, $now), $id);
        $this->assertCount(12, $cold->data['pace']['rows']);
        $future = array_filter($cold->data['pace']['rows'], fn($r) => $r['future']);
        $this->assertNotEmpty($future);
        $this->assertFalse(reset($future)['n']);
    }
}
