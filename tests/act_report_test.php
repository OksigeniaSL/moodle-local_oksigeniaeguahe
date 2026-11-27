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

use local_oksigeniaeguahe\local\act_files;
use local_oksigeniaeguahe\local\act_report;
use local_oksigeniaeguahe\local\appointments;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\export;
use local_oksigeniaeguahe\local\facts;
use local_oksigeniaeguahe\local\familylists;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\numbers_questions;
use local_oksigeniaeguahe\local\outs;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\panel_answer;
use local_oksigeniaeguahe\local\panel_answers;
use local_oksigeniaeguahe\local\panel_metrics;
use local_oksigeniaeguahe\local\sharereport;
use local_oksigeniaeguahe\local\teamnotes;
use local_oksigeniaeguahe\local\ties;

/**
 * Numbers by questions: the report of one act, its families, the imported files that are of an act or of a campaign, and what
 * is made up or not known. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\act_report
 */
#[\PHPUnit\Framework\Attributes\CoversClass(act_report::class)]
final class act_report_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int */
    protected $offer;

    /** @var int */
    protected $now;

    /** @var \stdClass An open day of five days ago. */
    protected $act;

    /** @var int */
    protected $n = 0;

    /**
     * A centre whose campaign started forty days ago, with a course and an open day.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->now = time();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $DB->set_field(centres::T, 'campaignstart', $this->now - 40 * DAYSECS, ['id' => $id]);
        $this->centre = centres::get($id);
        $this->offer = centres::save_offer($id, (object) ['schoolyear' => facts::year_name($this->centre, $this->now),
            'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $this->act = $this->act(5);
    }

    /**
     * An act some days ago.
     *
     * @param int $daysago
     * @param string $kind
     * @return \stdClass
     */
    protected function act(int $daysago, string $kind = 'openday'): \stdClass {
        // At noon: what is the eve does not depend on the hour it is run at.
        $start = usergetmidnight($this->now) - $daysago * DAYSECS + 12 * HOURSECS;
        return events::get(events::save((int) $this->centre->id, (object) ['kind' => $kind, 'name' => 'Puertas abiertas',
            'timestart' => $start, 'timeend' => $start + 2 * HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
    }

    /**
     * A family that wrote twenty days ago.
     *
     * @param array $more
     * @param string $phone
     * @return \stdClass
     */
    protected function family(array $more = [], string $phone = ''): \stdClass {
        global $DB;
        $this->n++;
        $cid = (int) $this->centre->id;
        $id = households::create($cid, (object) ($more + ['lang' => 'es', 'firstchannel' => 'webform']));
        $DB->set_field(households::T, 'timecreated', $this->now - 20 * DAYSECS, ['id' => $id]);
        $household = households::get($id, $cid);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'email' => 'f' . $this->n . '@example.com', 'phone' => $phone ?: null]);
        households::add_child($household, 'Leo' . $this->n, $this->offer);
        $DB->set_field(households::CHILDREN, 'timecreated', $this->now - 20 * DAYSECS, ['householdid' => $id]);
        $DB->set_field(households::CHILDREN, 'stagetime', $this->now - 20 * DAYSECS, ['householdid' => $id]);
        households::received(households::get($id, $cid), 'webform', '¿Hay comedor?', false, $this->now - 20 * DAYSECS);
        return households::get($id, $cid);
    }

    /**
     * A family signs up to the open day, so many hours from its start.
     *
     * @param \stdClass $family
     * @param int $hours
     * @param string $state came, notcame or nothing.
     * @param int $people
     */
    protected function sign(\stdClass $family, int $hours, string $state = '', int $people = 2): void {
        global $DB;
        $id = events::sign_up($this->act, (int) $family->id, $people);
        $DB->set_field(events::SIGNUPS, 'timecreated', (int) $this->act->timestart + $hours * HOURSECS, ['id' => $id]);
        if ($state === 'came') {
            events::attend($this->act, $id, true);
        } else if ($state === 'notcame') {
            $DB->set_field(events::SIGNUPS, 'missed', $this->now, ['id' => $id]);
        }
    }

    /**
     * The way of the child of a family, now.
     *
     * @param \stdClass $family
     * @param string $stage
     */
    protected function stage(\stdClass $family, string $stage): void {
        $children = households::full(households::get((int) $family->id, (int) $this->centre->id))->children;
        households::set_stage(reset($children), $stage);
    }

    /**
     * The whole report: who was, who came, who is not known, what they did after, those apart, and the families behind each
     * number in the inbox.
     */
    public function test_report(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $applied = $this->family(['heardfrom' => 'search']);
        $this->sign($applied, -72, 'came', 3);
        $this->stage($applied, 'application');
        $enrolled = $this->family();
        $this->sign($enrolled, -30);
        $this->stage($enrolled, 'enrolled');
        $waits = $this->family();
        $this->sign($waits, -30, 'came');
        households::received(households::get((int) $waits->id, $cid), 'email', '¿Hay comedor en julio?', true, $this->now - DAYSECS);
        $missed = $this->family();
        $this->sign($missed, -3, 'notcame');
        $unknown = $this->family();
        $this->sign($unknown, -3);
        $walkin = $this->family();
        $this->sign($walkin, 0, 'came');
        $inside = $this->family();
        $children = households::full($inside)->children;
        ties::set($inside, (int) reset($children)->id, 'nursery', true, 2);
        $this->sign($inside, -72, 'came');
        $never = $this->family();
        $this->sign($never, -72);
        outs::set(households::get((int) $never->id, $cid), $this->centre, 'job', '', '', 2);
        $sample = $this->family(['guest' => households::SAMPLE]);
        $this->sign($sample, -72, 'came');
        $act = act_report::all($this->centre, $this->now)['e' . $this->act->id];
        $r = act_report::build($this->centre, 'e' . $this->act->id, $this->now);
        $n = array_column($r['figures'], 'n', 'key');
        $this->assertSame(['signed' => 6, 'came' => 3, 'walkin' => 1, 'notcame' => 1, 'unknown' => 2, 'people' => 9], $n);
        $ids = array_column($r['figures'], 'ids', 'key');
        $this->assertEqualsCanonicalizing([(int) $enrolled->id, (int) $unknown->id], $ids['unknown']);
        $c = 'local_oksigeniaeguahe';
        $this->assertStringContainsString(get_string('actr_answer_came', $c, (object) ['came' => 3, 'signed' => 6]), $r['answer']);
        $this->assertStringContainsString(get_string('actr_answer_unknown', $c, 2), $r['answer']);
        $this->assertStringContainsString(get_string('actr_answer_walkin_one', $c, 1), $r['answer']);
        $this->assertStringContainsString(get_string('actr_answer_enrolled', $c, (object) ['applied' => 2, 'enrolled' => 1]), $r['answer']);
        // The way of those of the campaign, with those that applied though nobody noted whether they came.
        $steps = array_column($r['funnel']['steps'], 'n', 'key');
        $this->assertSame(['mine' => 6, 'minecame' => 3, 'after' => 3, 'applied' => 2, 'enrolled' => 1], $steps);
        $this->assertSame(
            get_string('actr_ofcame', $c, (object) ['came' => 1, 'rest' => 1]),
            array_column($r['funnel']['steps'], 'split', 'key')['applied']
        );
        $this->assertSame(['centre' => 1, 'never' => 1], array_column($r['apart'], 'n', 'key'));
        $this->assertSame(['fresh' => 6, 'inside' => 1], array_column($r['who']['kinds'], 'n', 'key'));
        $moments = array_column($r['moment']['rows'], 'n', 'key');
        $this->assertSame([7, 2, 1], [array_sum($moments), $moments['before'], $moments['door']]);
        $this->assertSame([1], array_column($r['heard']['rows'], 'n'));
        $this->assertSame('¿Hay comedor en julio?', $r['said']['asked'][0]['text']);
        $todo = array_column($r['todo'], 'n', 'key');
        $this->assertSame([1, 2], [$todo['waitingsince'], $todo['unknown']]);
        $this->assertFalse($r['spend']['known']);
        $this->assertFalse($r['others'], 'There is no other act.');
        // Each number opens its families, worked out again.
        $list = familylists::of($this->centre, 'act-e' . $this->act->id . '-applied', $this->now);
        $this->assertEqualsCanonicalizing([(int) $applied->id, (int) $enrolled->id], $list['ids']);
        $inbox = panel_inbox::context($this->centre, true, 2, true, ['list' => 'act-e' . $this->act->id . '-applied'], $this->now);
        $this->assertStringContainsString('Puertas abiertas', $inbox['head']['title']);
        $this->assertNull(familylists::of($this->centre, 'act-e' . $this->act->id . '-nada', $this->now));
        $this->assertNull(familylists::of($this->centre, 'act-e999999-came', $this->now));
        $this->assertNull(act_report::build($this->centre, 'e999999', $this->now));
        // The made-up family is nowhere.
        $this->assertArrayNotHasKey((int) $sample->id, facts::prospects($this->centre, $this->now));
        $this->assertNotContains((int) $sample->id, array_column(facts::prospects($this->centre, $this->now), 'id'));
        $this->assertSame(0, (int) households::get((int) $sample->id, $cid)->guest, 'For its pages it is a family.');
        $this->assertCount(8, events::signups($this->act));
        $this->assertNotContains('f9@example.com', array_column(export::families($this->centre), 'emails'));
        $this->assertSame('e' . $this->act->id, $act['key']);
    }

    /**
     * What the sheet of the team says of that day counts: it came, though nobody marked it; «did not come» is not «came»; and
     * a family with a visit that day and no sign-up is told apart.
     */
    public function test_sheet(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $say = function (\stdClass $family, string $key, int $time) use ($DB, $cid): void {
            $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid, 'householdid' => $family->id, 'direction' => 'note',
                'channel' => 'other', 'body' => get_string_manager()->get_string('teamnote_' . $key, 'local_oksigeniaeguahe', null, 'es') .
                ' · Asistencia: x', 'entryid' => 0, 'userid' => 0, 'dayonly' => 1, 'timecreated' => $time]);
        };
        $start = (int) $this->act->timestart;
        $came = $this->family();
        $this->sign($came, -30);
        $say($came, 'attended', $start);
        $not = $this->family();
        $this->sign($not, -30);
        $say($not, 'absent', $start);
        $other = $this->family();
        $DB->insert_record(teamnotes::T, (object) ['centreid' => $cid, 'householdid' => $other->id, 'mark' => 'x', 'visit' => $start,
            'dayonly' => 1, 'timecreated' => $this->now]);
        $r = act_report::build($this->centre, 'e' . $this->act->id, $this->now);
        $n = array_column($r['figures'], 'n', 'key');
        $this->assertSame([2, 1, 1], [$n['signed'], $n['came'], $n['notcame']]);
        $this->assertArrayNotHasKey('unknown', $n);
        $this->assertSame([1, [(int) $other->id]], [$r['sheet']['n'], $r['sheet']['ids']]);
        $read = facts::prospects($this->centre, $this->now);
        $by = array_column($read, 'came', 'id');
        $this->assertSame([true, false], [$by[(int) $came->id], $by[(int) $not->id]]);
        // The visits: of each family, what Eguahe or the sheet says; a day of the sheet that is the day of the act is the act.
        $visit = $this->family();
        appointments::give($visit, $this->centre, $this->now + DAYSECS, 45, 0, 'Secretaría', 2, false);
        foreach (['attended' => 12, 'absent' => 13, '' => 14] as $key => $days) {
            $one = $this->family();
            $time = $this->now - $days * DAYSECS;
            $DB->insert_record(teamnotes::T, (object) ['centreid' => $cid, 'householdid' => $one->id, 'mark' => 'm' . $days, 'visit' => $time,
                'dayonly' => 0, 'timecreated' => $this->now]);
            if ($key !== '') {
                $say($one, $key, $time);
            }
        }
        $o = array_map('count', numbers_questions::visit_outcomes($this->centre, $this->now));
        $this->assertSame(['came' => 1, 'notcame' => 1, 'off' => 0, 'unknown' => 1, 'ahead' => 1], $o);
    }

    /**
     * The imported files: of their act when the label writes the day, asked when it does not, and of the campaign of their
     * year, each family once.
     */
    public function test_files(): void {
        global $DB;
        $this->setTimezone('Europe/Madrid');
        $cid = (int) $this->centre->id;
        $year = (int) date('Y', $this->now) - 1;
        $this->assertSame(make_timestamp($year, 3, 14, 12), act_files::day("Puertas abiertas 14 de marzo de $year (anuncios)"));
        $this->assertSame(make_timestamp($year, 3, 14, 12), act_files::day("Open day, March 14, $year"));
        $this->assertSame(make_timestamp($year, 3, 14, 12), act_files::day("Jornada $year-03-14"));
        $this->assertSame(0, act_files::day('Fiesta de primavera (anuncios)'));
        $this->assertSame('Puertas abiertas', act_files::name("Puertas abiertas 14 de marzo de $year (anuncios)"));
        $this->assertSame('Puertas abiertas', act_files::name("Puertas abiertas 3 y 4 de marzo de $year"));
        $file = function (string $label, array $phones, string $month = '03') use ($cid, $year): int {
            $csv = "date,Teléfono,platform,campaign_name,grade_requested\n";
            foreach ($phones as $i => $phone) {
                $csv .= sprintf("%d-%s-0%d,%s,ig,Primavera,Infantil 3 años\n", $year, $month, $i + 1, $phone);
            }
            $path = make_request_directory() . '/l' . random_string(5) . '.csv';
            file_put_contents($path, $csv);
            imports::import_file($cid, $path, 'lista.csv', [0 => ['kind' => 'contacts', 'label' => $label, 'schoolyear' => '', 'partial' => 0]], 2);
            $all = imports::for_centre($cid, 'contacts');
            return (int) reset($all)->id;
        };
        $file("Puertas abiertas 14 de marzo de $year (anuncios)", ['+34600112201', '+34600112202', '+34600112203']);
        $file("Puertas abiertas 14 de marzo de $year (web)", ['+34600112203', '+34600112204']);
        $ask = $file('Fiesta de primavera (anuncios)', ['+34600112205']);
        $file('Campaña de otoño', ['+34600112201'], '04');
        $known = $this->family([], '+34600112204');
        $acts = act_report::all($this->centre, $this->now);
        $key = 'd' . $year . '0314';
        $this->assertArrayHasKey($key, $acts);
        $this->assertCount(2, $acts[$key]['files']);
        $r = act_report::build($this->centre, $key, $this->now);
        $n = array_column($r['figures'], 'n', 'key');
        $this->assertSame([4, 4], [$n['signed'], $n['unknown']], 'Each family once, and the files do not say who came.');
        $this->assertTrue($r['imported']);
        $this->assertSame([(int) $known->id], $r['figures'][0]['ids'], 'The one that has a file in Eguahe opens it.');
        $this->assertStringContainsString(get_string('actr_answer_unmarked', 'local_oksigeniaeguahe', 4), $r['answer']);
        $rows = array_column($r['others']['rows'] ?: [], null, 'key');
        $this->assertNull($rows[$key]['came']);
        $this->assertNotSame('', $rows[$key]['missing']);
        // Asked, never made up.
        $this->assertSame(['ask'], array_values(array_unique(array_column(array_filter(act_files::of_centre($this->centre), fn($f) => $f['id'] === $ask), 'state'))));
        $questions = numbers_questions::context($this->centre, 'live', true, $this->now);
        $this->assertSame([$ask], array_column($questions['ask'], 'import'));
        $this->assertFalse(act_files::say($this->centre, $ask, true, ''));
        $this->assertTrue(act_files::say($this->centre, $ask, true, $year . '-05-02'));
        $this->assertArrayHasKey('d' . $year . '0502', act_report::all($this->centre, $this->now));
        $this->assertTrue(act_files::say($this->centre, $ask, false));
        $this->assertArrayNotHasKey('d' . $year . '0502', act_report::all($this->centre, $this->now));
        // The campaign of their year: one, with each family once and the files it is in; never a file to choose.
        $campaigns = facts::campaigns($this->centre, $this->now);
        $name = facts::year_name($this->centre, make_timestamp($year, 3, 14));
        $this->assertSame(['live', 'y' . $name], array_keys($campaigns));
        $families = facts::families($this->centre, $campaigns['y' . $name], $this->now);
        $this->assertCount(5, $families);
        $sum = facts::sum($families);
        $this->assertSame([5, 5, 5], [$sum['outside'], $sum['notcame'], $sum['notapplied']]);
        $one = current(array_filter($families, fn($f) => count($f['sources']) === 3)) ?: current(array_filter($families, fn($f) => count($f['sources']) === 2));
        $this->assertNotFalse($one);
        $this->assertSame(['campaign'], array_values(array_unique(array_column($families, 'channel'))), 'They came by an ad.');
        // Both years together, the family of Eguahe and of the file is one.
        $this->assertCount(5, array_filter(facts::everyone($this->centre, $this->now), fn($f) => $f['group'] === 'campaign'));
        $page = panel_metrics::context($this->centre, ['campaign' => 'y' . $name], true, $this->now);
        $this->assertNotContains('visit', array_column($page['reach']['steps'], 'key'), 'What no file says is not a zero.');
        $this->assertStringContainsString($name, $page['head']['title']);
    }

    /**
     * The questions always answer or say what is missing, with the campaign of a whole school year.
     */
    public function test_questions(): void {
        $empty = centres::get(centres::create((object) ['name' => 'Vacío', 'lang' => 'es', 'country' => 'ES']));
        $context = numbers_questions::context($empty, 'live', true, $this->now);
        foreach ($context['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $this->assertTrue(!$question['empty'] || !empty($question['missing']['text']), $question['key']);
            }
        }
        $family = $this->family(['arrival' => ['src' => 'google', 'med' => 'organic']]);
        $this->sign($family, -30, 'came');
        $context = numbers_questions::context($this->centre, 'live', true, $this->now);
        $by = [$context['act']['key'] => $context['act']];
        foreach ($context['groups'] as $group) {
            $by += array_column($group['questions'], null, 'key');
        }
        $this->assertFalse($by['act-e' . $this->act->id]['empty']);
        $this->assertTrue($by['act-e' . $this->act->id]['recent']);
        $this->assertStringContainsString('1', $by['campaign']['answer']);
        $this->assertStringContainsString('1', $by['channels']['answer']);
        $this->assertSame(['channels', 'answers', 'outs', 'places', 'students', 'likes'], array_values(array_intersect(array_keys($by), ['channels',
            'answers', 'outs', 'places', 'students', 'likes'])));
    }

    /**
     * A call nobody took is an attempt, and what a sheet says has no hour: neither measures how long a family waits.
     */
    public function test_attempts_and_hours(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $say = fn(\stdClass $family, string $key, ?string $aim = null) => $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid,
            'householdid' => $family->id, 'direction' => 'out', 'channel' => 'phone', 'body' => get_string_manager()->get_string($key, 'local_oksigeniaeguahe', null, 'es') .
            ' · Respuesta: x', 'entryid' => 0, 'userid' => 2, 'aim' => $aim, 'timecreated' => $this->now]);
        $ids = [];
        foreach (['tried', 'words', 'sheet', 'person'] as $i => $kind) {
            $family = $this->family();
            households::received(households::get((int) $family->id, $cid), 'email', '¿Hay plaza?', true, $this->now - 3 * DAYSECS);
            $ids[$kind] = (int) $family->id;
            if ($kind === 'tried') {
                $say($family, 'teamnote_noanswer', 'tried');
            } else if ($kind === 'words') {
                $say($family, 'teamnote_noanswer');
            } else if ($kind === 'sheet') {
                $say($family, 'teamnote_contacted_phone');
            } else {
                households::answered(households::get((int) $family->id, $cid), 2, 'email', 'Sí, hay.');
            }
        }
        $r = metrics::response($cid, array_values($ids), $this->now);
        $this->assertSame([4, 2, 1, 2, 2], [$r['asked'], $r['answered'], $r['timed'], $r['waiting'], $r['tried']]);
        $this->assertNull($r['median']);
        $said = panel_metrics::response_sentence(panel_metrics::said($r));
        $this->assertStringContainsString(get_string('panel_response_tried', 'local_oksigeniaeguahe', 2), $said);
        // Those that wrote, with no answer, and are not among those that wait: each in its place, none left out.
        foreach (['unreached' => ['teamnote_noanswer', 'tried'], 'met' => null, 'quiet' => null, 'signed' => null] as $kind => $line) {
            $family = $this->family();
            households::received(households::get((int) $family->id, $cid), 'email', '¿Hay plaza?', false, $this->now - 3 * DAYSECS);
            $ids[$kind] = (int) $family->id;
            if ($line) {
                $say($family, ...$line);
            } else if ($kind === 'met') {
                $DB->insert_record(\local_oksigeniaeguahe\local\teamnotes::T, (object) ['centreid' => $cid, 'householdid' => $family->id,
                    'mark' => sha1('m'), 'entryid' => 1, 'visit' => $this->now + DAYSECS, 'timecreated' => $this->now]);
            } else if ($kind === 'signed') {
                events::sign_up($this->act, (int) $family->id, 2);
            }
        }
        $r = metrics::response($cid, array_values($ids), $this->now);
        $this->assertSame([7, 2, 2, 1, 1, 1], [$r['asked'], $r['answered'], $r['waiting'], $r['unreached'], $r['met'], $r['quiet']]);
        $this->assertSame([$ids['unreached'], $ids['met'], $ids['quiet']], array_map(fn($k) => array_key_first($r['who'][$k]), ['unreached', 'met', 'quiet']));
        // Each number opens its families.
        $live = array_values(array_filter(facts::campaigns($this->centre, $this->now), fn($k) => $k['key'] === 'live'))[0];
        $nums = array_column(\local_oksigeniaeguahe\local\familylists::nums(
            $this->centre,
            $live,
            facts::all($this->centre, $live, $this->now),
            ['unreached', 'met', 'quiet']
        ), 'n');
        $this->assertCount(3, $nums);
        $said = panel_metrics::response_sentence(panel_metrics::said($r));
        foreach (['unreached', 'met', 'quiet'] as $key) {
            $this->assertStringContainsString(get_string('panel_response_' . $key . '_one', 'local_oksigeniaeguahe', 1), $said);
        }
        $this->assertStringContainsString(get_string('panel_response_timed_one', 'local_oksigeniaeguahe', (object) ['timed' => 1, 'answered' => 2]), $said);
    }

    /**
     * A family of the sheet without a message of its own carries the day of its visit: when it came is not known, and it is said.
     */
    public function test_undated(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $day = $this->now - 10 * DAYSECS;
        $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'phone']);
        $DB->set_field(households::T, 'timecreated', $day, ['id' => $id]);
        households::add_child(households::get($id, $cid), 'Noa', $this->offer);
        $DB->insert_record(teamnotes::T, (object) ['centreid' => $cid, 'householdid' => $id, 'mark' => 'x', 'visit' => $day, 'dayonly' => 1,
            'timecreated' => $this->now]);
        $this->family();
        $families = facts::families($this->centre, facts::live($this->centre, $this->now), $this->now);
        $this->assertSame([false, true], array_column($families, 'dated'));
        $page = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now);
        $this->assertContains(get_string('panel_unknown_undated_one', 'local_oksigeniaeguahe', 1), array_column($page['unknown']['list'], 'text'));
        $this->assertStringContainsString(get_string('panel_weeks_undated_one', 'local_oksigeniaeguahe', 1), $page['weeks']['def']);
        $this->assertSame([$id], familylists::of($this->centre, familylists::of_year(facts::live($this->centre, $this->now), 'undated'), $this->now)['ids']);
    }

    /**
     * The sheet of an act: a mark for each family, what is not known aside and with its button, and never a zero for what a
     * file does not say.
     */
    public function test_sheet_of_an_act(): void {
        global $OUTPUT;
        $this->sign($this->family(), -30, 'came');
        $this->sign($this->family(), -30, 'came');
        $this->sign($this->family(), -30, 'notcame');
        $this->sign($this->family(), -30);
        $later = $this->family();
        $this->sign($later, -30, 'came');
        $this->stage($later, 'application');
        $report = act_report::build($this->centre, 'e' . $this->act->id, $this->now);
        $sheet = panel_answer::act($report, panel::head(['title' => $report['answer'], 'ante' => $report['question']]));
        $blocks = array_column($sheet['blocks'], null, 'id');
        $steps = $blocks['vinieron']['path']['steps'];
        $this->assertSame(5, $blocks['vinieron']['path']['total']);
        $this->assertTrue($blocks['vinieron']['path']['dots']);
        $this->assertSame([['yes', 3], ['no', 1], ['unknown', 1]], array_map(fn($p) => [$p['kind'], (int) $p['n']], $steps[1]['parts']));
        $this->assertSame(get_string('qs_note_unknown_one', 'local_oksigeniaeguahe', 1), $blocks['vinieron']['note']['text']);
        $this->assertNotEmpty($blocks['vinieron']['note']['url']);
        $this->assertNotEmpty($sheet['todo']['items'][0]['url']);
        foreach ($steps as $step) {
            $this->assertLessThanOrEqual(5, $step['n']);
        }
        $html = $OUTPUT->render_from_template('local_oksigeniaeguahe/panel_answer', ['i' => ['imprimir' => '']] + $sheet);
        $this->assertStringContainsString(get_string('actr_answer_came', 'local_oksigeniaeguahe', (object) ['came' => 3, 'signed' => 5]), $html);
        // More families than marks: a bar.
        $this->assertFalse(panel_answer::path(panel_answer::DOTS + 1, [])['dots']);
        $this->assertSame([1.0, 0.5], array_map('floatval', array_column(panel_answer::bars('x', [['name' => 'a', 'n' => 4], ['name' => 'b', 'n' => 2]])['rows'], 'v')));
    }

    /**
     * The sheets of the other questions: the sentence of the list is their title, and their numbers are the same; without an
     * answer, there is no sheet.
     */
    public function test_sheets_of_questions(): void {
        global $OUTPUT;
        $empty = centres::get(centres::create((object) ['name' => 'Vacío', 'lang' => 'es', 'country' => 'ES']));
        foreach (panel_answers::KEYS as $key) {
            $this->assertNull(panel_answers::build($empty, $key, 'live', true, $this->now), $key);
        }
        $this->assertNull(panel_answers::build($this->centre, 'nada', 'live', true, $this->now));
        $families = [];
        foreach (range(1, 12) as $i) {
            $families[] = $this->family($i % 2 ? ['arrival' => ['src' => 'google', 'med' => 'organic']] : []);
        }
        $tied = $this->family(['incentre' => 1]);
        $this->sign($families[0], -30, 'came');
        $this->sign($families[1], -30);
        $this->sign($tied, -30, 'came');
        $this->stage($families[2], 'enrolled');
        outs::set(households::get((int) $families[3]->id, (int) $this->centre->id), $this->centre, 'cost', '', 'none', 2, $this->now);
        households::received(households::get((int) $families[4]->id, (int) $this->centre->id), 'email', '¿Hay plaza?', true, $this->now - 3 * DAYSECS);
        households::received(households::get((int) $families[5]->id, (int) $this->centre->id), 'email', '¿Hay plaza?', true, $this->now - 3 * DAYSECS);
        households::answered(households::get((int) $families[5]->id, (int) $this->centre->id), 2, 'email', 'Sí, hay.');
        $rows = [];
        foreach (numbers_questions::context($this->centre, 'live', true, $this->now)['groups'] as $group) {
            $rows += array_column($group['questions'], null, 'key');
        }
        $sheets = [];
        foreach (panel_answers::KEYS as $key) {
            $sheets[$key] = panel_answers::build($this->centre, $key, 'live', true, $this->now);
            if ($rows[$key]['empty']) {
                $this->assertNull($sheets[$key], $key);
                continue;
            }
            $this->assertStringContainsString('pregunta=' . $key, $rows[$key]['url']);
            $this->assertSame($rows[$key]['question'], $sheets[$key]['head']['ante']);
            $this->assertSame($key === 'campaign' ? panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now)['head']['title'] :
                $rows[$key]['answer'], $sheets[$key]['head']['title']);
            $html = $OUTPUT->render_from_template('local_oksigeniaeguahe/panel_answer', ['i' => ['imprimir' => '']] + $sheets[$key]);
            $this->assertStringNotContainsString('[[', $html, $key);
            $this->assertStringNotContainsString('{{', $html, $key);
        }
        $of = fn(string $key, string $block) => array_column($sheets[$key]['blocks'], null, 'id')[$block];
        $way = $of('campaign', 'camino');
        $this->assertSame([12, 1, 1, 1, 1], array_map('intval', array_column($way['path']['steps'], 'n')));
        $this->assertSame(1, $way['path']['steps'][1]['parts'][1]['n'], 'Signed up, and nobody noted whether it came.');
        $this->assertSame(get_string('qs_note_unknown_one', 'local_oksigeniaeguahe', 1), $way['note']['text']);
        $this->assertSame(12, array_sum(array_column($of('channels', 'canal')['bars']['groups'][0]['rows'], 'n')));
        $this->assertSame(1, array_sum(array_column($of('channels', 'canal')['bars']['groups'][1]['rows'], 'n')));
        $this->assertNotFalse($of('channels', 'gasto')['note']);
        $this->assertSame([11, 1], [(int) $of('answers', 'contestadas')['path']['total'], (int) $of('answers', 'esperan')['list']['rows'][0]['n']]);
        $this->assertSame([1], array_column($of('outs', 'motivo')['bars']['groups'][0]['rows'], 'n'));
        $this->assertSame([25, 1], array_map('intval', array_slice(array_column($of('places', 'cursos')['table']['rows'][0]['cells'], 'n'), 0, 2)));
    }

    /**
     * The day of the sheet that is the day of the act is not an appointment after it; one of another day is.
     */
    public function test_after(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $note = fn(\stdClass $family, int $when) => $DB->insert_record(\local_oksigeniaeguahe\local\teamnotes::T, (object) ['centreid' => $cid,
            'householdid' => $family->id, 'mark' => sha1((string) $when), 'entryid' => 1, 'visit' => $when, 'dayonly' => 1, 'timecreated' => $this->now]);
        $same = $this->family();
        $later = $this->family();
        $start = usergetmidnight($this->now - 4 * DAYSECS) + 9 * HOURSECS;
        $note($same, $start + 3 * HOURSECS);
        $note($later, $start + DAYSECS + 3 * HOURSECS);
        $after = new \ReflectionMethod(act_report::class, 'after');
        $out = $after->invoke(null, $this->centre, [(int) $same->id, (int) $later->id], $start, $this->now);
        $this->assertSame([(int) $later->id], array_keys($out['appointment']));
    }

    /**
     * The report to share of an act: every family that signed up a mark on its cover (came, did not, not noted), the path
     * always over the same families, the centre's own ways against the ads, what happened since, no names, and «Made with
     * Eguahe»; the same numbers as Numbers.
     */
    public function test_share(): void {
        global $OUTPUT, $PAGE;
        $applied = $this->family(['heardfrom' => 'search']);
        $this->sign($applied, -72, 'came', 3);
        $this->stage($applied, 'application');
        $missed = $this->family();
        $this->sign($missed, -3, 'notcame');
        $unknown = $this->family();
        $this->sign($unknown, -3);
        $earlier = $this->act(40);
        $key = 'e' . $this->act->id;
        $c = sharereport::act($this->centre, $key, $this->now);
        $this->assertSame(['yes', 'no', 'unknown'], array_column($c['field']['dots'], 'k'));
        $s = fn(string $id, $a = null) => get_string('share_' . $id, 'local_oksigeniaeguahe', $a);
        $this->assertSame($s('answer_came', (object) ['came' => 1, 'signed' => 3]) . '. ' . $s('answer_applied_one'), $c['answer']);
        $this->assertSame($s('answer_applied_none'), substr(sharereport::answer(true, 3, 1, 0, 1, 0, 0), -strlen($s('answer_applied_none'))));
        $this->assertSame($s('answer_ahead', 3), sharereport::answer(false, 3, 0, 0, 0, 0, 0));
        $this->assertSame(['3', '1', '3', '1'], array_column($c['figures'], 'n'), 'Signed up, came, people, applied.');
        $this->assertSame(['camino', 'quienes', 'canales', 'desde', 'otras'], array_column($c['sections'], 'id'));
        $path = $c['sections'][0]['path']['rows'];
        $this->assertSame(['3', '1', '1', '1', '0'], array_column($path, 'n'));
        $this->assertSame(['yes', 'no', 'unknown'], array_column($path[1]['dots'], 'k'));
        $this->assertStringContainsString(
            get_string('share_note_unknowncame_one', 'local_oksigeniaeguahe'),
            $c['sections'][0]['notes'][0]['text']
        );
        $others = $c['sections'][4]['table']['rows'];
        $this->assertCount(2, $others);
        $this->assertTrue($others[0]['current']);
        $this->assertNull(sharereport::early($this->centre, null, 5, $this->now));
        $this->assertSame(0, sharereport::early(
            $this->centre,
            act_report::all($this->centre, $this->now)['e' . $earlier->id],
            5,
            $this->now
        ));
        $this->assertNull(sharereport::act($this->centre, 'e999999', $this->now));
        $PAGE->set_context(\context_system::instance());
        $html = $OUTPUT->render_from_template('local_oksigeniaeguahe/report_share', $c);
        $this->assertStringContainsString(get_string('share_made_line', 'local_oksigeniaeguahe'), $html);
        $this->assertStringNotContainsString('Ana', $html, 'No names of families.');
        $this->assertStringContainsString('--franja: linear-gradient', $html);
    }
}
