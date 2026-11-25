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

use local_oksigeniaeguahe\local\arrival;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\export;
use local_oksigeniaeguahe\local\facts;
use local_oksigeniaeguahe\local\heard;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\lookalike;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\outs;
use local_oksigeniaeguahe\local\panel_inbox;
use local_oksigeniaeguahe\local\panel_metrics;
use local_oksigeniaeguahe\local\panel_newstudents;
use local_oksigeniaeguahe\local\renewal;
use local_oksigeniaeguahe\local\report_data;
use local_oksigeniaeguahe\local\settled;
use local_oksigeniaeguahe\local\ties;
use local_oksigeniaeguahe\local\who;

/**
 * What each family did, worked out in one place: every screen says the same totals, in a world with the odd cases. With
 * example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\facts
 */
#[\PHPUnit\Framework\Attributes\CoversClass(facts::class)]
final class facts_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $centre;

    /** @var int[] The offers by school year: the campaign under way, the year before it and the year after. */
    protected $offers;

    /** @var int A moment, an hour before now at most: the campaign started thirty days before. */
    protected $now;

    /** @var string The school year the campaign fills. */
    protected $year;

    /** @var int */
    protected $n = 0;

    /**
     * A centre whose campaign started thirty days ago, with a course of three school years.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->now = time();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $start = $this->now - 30 * DAYSECS;
        $from = (int) userdate($start, '%Y') + ((int) userdate($start, '%m') >= 7 ? 1 : 0);
        $name = fn(int $y) => sprintf('%d-%02d', $y, ($y + 1) % 100);
        foreach ([-1, 0, 1] as $i) {
            $this->offers[$i] = centres::save_offer($id, (object) ['schoolyear' => $name($from + $i), 'name' => 'Infantil 3 años',
                'places' => 25, 'active' => 1]);
        }
        $this->year = $name($from);
        global $DB;
        $DB->set_field(centres::T, 'campaignstart', $start, ['id' => $id]);
        $this->centre = centres::get($id);
        require_once($CFG->dirroot . '/local/oksigeniaeguahe/lib.php');
    }

    /**
     * A family that wrote some days ago.
     *
     * @param int $daysago
     * @param array $kids Each the year of its course (-1, 0 or 1) and its stage.
     * @param array $more first, arrival, guest, heardfrom.
     * @return \stdClass
     */
    protected function family(int $daysago, array $kids = [[0, 'inquiry']], array $more = []): \stdClass {
        global $DB;
        $this->n++;
        $cid = (int) $this->centre->id;
        $time = $this->now - $daysago * DAYSECS;
        $id = households::create($cid, (object) ($more + ['lang' => 'es', 'firstchannel' => 'webform']));
        $DB->set_field(households::T, 'timecreated', $time, ['id' => $id]);
        $household = households::get($id, $cid);
        households::add_adult($household, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo ' . $this->n,
            'email' => 'f' . $this->n . '@example.com']);
        households::received($household, 'webform', '¿Hay comedor?', true, $time);
        foreach ($kids as $i => [$year, $stage]) {
            $child = households::add_child(households::get($id, $cid), ['Leo', 'Noa'][$i] . $this->n, $this->offers[$year]);
            if ($stage !== 'inquiry') {
                households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), $stage, 'cost');
            }
            $DB->update_record(households::CHILDREN, (object) ['id' => $child, 'timecreated' => $time]);
        }
        return households::get($id, $cid);
    }

    /**
     * An open day some days ago, or ahead.
     *
     * @param int $daysago
     * @param string $kind
     * @return \stdClass
     */
    protected function act(int $daysago, string $kind = 'openday'): \stdClass {
        $start = $this->now - $daysago * DAYSECS;
        return events::get(events::save((int) $this->centre->id, (object) ['kind' => $kind, 'name' => 'Acto de ejemplo',
            'timestart' => $start, 'timeend' => $start + HOURSECS, 'capacity' => 0, 'open' => 1]), (int) $this->centre->id);
    }

    /**
     * A family comes to an act, marked now.
     *
     * @param \stdClass $act
     * @param \stdClass $family
     */
    protected function came(\stdClass $act, \stdClass $family): void {
        events::attend($act, events::sign_up($act, (int) $family->id, 2), true);
    }

    /**
     * The children of a family.
     *
     * @param \stdClass $family
     * @return \stdClass[]
     */
    protected function kids(\stdClass $family): array {
        return array_values(households::full(households::get((int) $family->id, (int) $this->centre->id))->children);
    }

    /**
     * A family of the campaign, as its facts say.
     *
     * @param \stdClass $family
     * @return array
     */
    protected function fact(\stdClass $family): array {
        who::reset();
        return facts::read($this->centre, facts::live($this->centre, $this->now), $this->now)[(int) $family->id];
    }

    /**
     * The odd cases, each once, and every screen with the same totals.
     */
    public function test_one_truth(): void {
        global $DB;
        $act = $this->act(5);
        $nokid = $this->family(3, []);
        $closed = $this->family(4, [[0, 'closed']]);
        $waits = $this->family(6, [[0, 'waitlist']]);
        $gone = $this->family(20);
        $this->came($act, $gone);
        households::set_stage($this->kids($gone)[0], 'closed', 'othercentre');
        $enrolled = $this->family(12, [[0, 'enrolled']]);
        $place = $this->family(13, [[0, 'admitted']]);
        $left = $this->family(14, [[0, 'enrolled']]);
        settled::leave(households::get((int) $left->id, (int) $this->centre->id), 2, 'moved', '', $this->now);
        $two = $this->family(15, [[0, 'closed'], [0, 'application']]);
        $out = $this->family(16);
        $this->assertTrue(outs::set($out, $this->centre, 'othercentre', '', '', 2));
        $merged = $this->family(17);
        $other = $this->family(18);
        $merged = lookalike::merge($merged, $other, 2);
        // Apart: already of the centre, in four ways, and one that asks for the course under way.
        $tied = $this->family(8);
        ties::set($tied, (int) $this->kids($tied)[0]->id, 'nursery', true, 2);
        $said = $this->family(9, [[0, 'enrolled']], ['incentre' => 1]);
        $sibling = $this->family(380, [[-1, 'enrolled'], [0, 'inquiry']]);
        $DB->set_field(households::CHILDREN, 'stagetime', $this->now - 200 * DAYSECS, ['id' => $this->kids($sibling)[0]->id]);
        $now = $this->family(10, [[-1, 'enrolled']]);
        // Nowhere: who only came to a talk, who was not a family, another campaign, the roll.
        $guest = $this->family(5, [], ['guest' => 1]);
        $never = $this->family(5);
        $this->assertTrue(outs::set($never, $this->centre, 'job', '', '', 2));
        $past = $this->family(380, [[-1, 'inquiry']]);
        $roll = $this->family(3, [[0, 'enrolled']]);
        $DB->update_record(households::T, (object) ['id' => $roll->id, 'roster' => $this->now, 'incentre' => 1]);
        $DB->set_field(households::CHILDREN, 'roster', $this->now, ['householdid' => $roll->id]);

        $live = facts::live($this->centre, $this->now);
        $this->assertSame($this->year, $live['year']);
        $read = facts::read($this->centre, $live, $this->now);
        $ids = fn(string $group) => array_column(facts::group($read, $group), 'id');
        $expected = array_map(fn($h) => (int) $h->id, [$nokid, $closed, $waits, $gone, $enrolled, $place, $left, $two, $out, $merged]);
        $this->assertEqualsCanonicalizing($expected, $ids('campaign'));
        $this->assertEqualsCanonicalizing(array_map(fn($h) => (int) $h->id, [$tied, $said, $sibling]), $ids('centre'));
        $this->assertSame([(int) $now->id], $ids('other'));
        foreach ([$guest, $never, $roll] as $absent) {
            $this->assertArrayNotHasKey((int) $absent->id, $read);
        }
        $this->assertSame('', $read[(int) $past->id]['group']);
        $sums = facts::sum(facts::group($read, 'campaign'));
        $this->assertSame(['n' => 10, 'open' => 6, 'came' => 1, 'applied' => 5, 'waitlist' => 1, 'place' => 2, 'enrolled' => 1,
            'students' => 1, 'went' => 1, 'closed' => 3, 'nokids' => 1, 'undated' => 0, 'outside' => 0, 'notcame' => 0,
            'notapplied' => 0], $sums);

        // Numbers, the way of the campaign of the inbox, the report, the download and «Your work».
        $numbers = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now);
        $steps = array_column($numbers['reach']['steps'], 'n', 'key');
        $this->assertSame(['inquiry' => 10, 'visit' => 1, 'application' => 5, 'waitlist' => 1, 'admitted' => 2, 'enrolled' => 1], $steps);
        $this->assertSame(get_string('panel_title_fams', 'local_oksigeniaeguahe', (object) ['n' => 10,
            'campaign' => \core_text::strtolower(get_string('panel_this_campaign', 'local_oksigeniaeguahe')),
            'parts' => get_string('panel_title_enrolled_one', 'local_oksigeniaeguahe', 1) . ', ' .
            get_string('panel_title_went_one', 'local_oksigeniaeguahe', 1) . ', ' .
            get_string('panel_title_closed', 'local_oksigeniaeguahe', 3)]), $numbers['head']['title']);
        foreach (panel_metrics::VOICES as $voice) {
            $channels = panel_metrics::context($this->centre, ['campaign' => 'live', 'voice' => $voice], true, $this->now)['channels'];
            if (in_array($voice, ['channel', 'first', 'last'], true)) {
                $this->assertSame(10, array_sum(array_column($channels['rows'], 'n')), $voice);
                $this->assertSame(1, array_sum(array_column($channels['rows'], 'en')), $voice);
            }
        }
        $this->assertSame([10, 3], [$numbers['outs'] ? array_sum([$sums['n']]) : 0, array_sum(array_column(outs::figures(
            $this->centre,
            facts::group($read, 'campaign'),
            $live['since']
        )['left']['rows'], 'n'))]);
        $weeks = array_column($numbers['weeks']['table'], 'now');
        $this->assertSame(10, max(array_filter($weeks, 'is_int')));
        $path = panel_inbox::path((int) $this->centre->id, $this->now);
        $this->assertSame([10, 4], [$path['total'], $path['gone']]);
        $this->assertSame(10, array_sum(array_column($path['steps'], 'n')) + $path['gone']);
        $this->assertSame(1, array_column($path['steps'], 'n', 'key')['enrolled']);
        $from = $this->now - 60 * DAYSECS;
        $report = report_data::build($this->centre, 'cold', $from, $this->now + DAYSECS, time() + 5);
        $this->assertSame($steps, array_combine(array_keys($steps), array_column($report['funnel']['steps'], 'n')));
        // Every family that came in the time, whichever school year it asks for: the ten and the one of the course under way.
        $figures = array_column($report['figures'], 'n', 'key');
        $this->assertSame([11, 1, 3, 2], [$figures['arrived'], $figures['came'], $figures['enrolled'], $figures['net']]);
        $download = export::figures($this->centre);
        $mine = array_filter($download, fn($r) => $r['counts'] === get_string('export_counts_campaign', 'local_oksigeniaeguahe') &&
            strpos($r['campaign'], $this->year) !== false);
        $this->assertCount(10, $mine);
        $yes = get_string('yes');
        $this->assertSame([1, 5, 1], [count(array_filter($mine, fn($r) => $r['came'] === $yes)), count(array_filter(
            $mine,
            fn($r) => $r['applied'] === $yes
        )), array_sum(array_column($mine, 'students'))]);
        $this->assertEquals(['wrote' => 2, 'closed' => 3, 'waitlist' => 1, 'enrolled' => 1, 'place' => 1, 'went' => 1, 'applied' => 1], array_count_values(array_map(
            fn($f) => export::reached($f),
            facts::group($read, 'campaign')
        )));
        // «Your work»: of the last week, the new ones that came in and the one that came to the act.
        $week = facts::week($this->centre, $this->now - 7 * DAYSECS, $this->now);
        $this->assertSame([3, 1], $week);
        $this->assertStringContainsString(
            get_string('work_week_new', 'local_oksigeniaeguahe', 3),
            local_oksigeniaeguahe_work_week((int) $this->centre->id, $this->now)
        );
        // The three kinds of families that ask for a place.
        $this->assertSame(['all' => 13, 'fresh' => 10, 'knew' => 0, 'former' => 0, 'alumni' => 0, 'centre' => 3], facts::kinds($read));
        $this->assertStringContainsString('13', $numbers['head']['sub']);
    }

    /**
     * A family without a child noted down is not closed, and one closed keeps how far it got.
     */
    public function test_closed(): void {
        $nokid = $this->family(3, []);
        $this->assertFalse($this->fact($nokid)['closed']);
        $family = $this->family(9, [[0, 'application']]);
        $this->came($this->act(4), $family);
        households::set_stage($this->kids($family)[0], 'closed', 'cost');
        $fact = $this->fact($family);
        $this->assertSame([true, true, true, 'cost', 3], [$fact['closed'], $fact['came'], $fact['applied'], $fact['why'], $fact['step']]);
        households::set_stage($this->kids($family)[0], 'inquiry');
        $this->assertNull($this->kids($family)[0]->closedstage);
        $fact = $this->fact($family);
        $this->assertSame([false, true, false], [$fact['closed'], $fact['came'], $fact['applied']]);
    }

    /**
     * «Came» is coming: not having applied or enrolled without it, nor having come to a talk; and on the day it was.
     */
    public function test_came(): void {
        $never = $this->family(9, [[0, 'enrolled']]);
        $this->assertFalse($this->fact($never)['came']);
        $talk = $this->family(9);
        $this->came($this->act(3, 'other'), $talk);
        $this->assertFalse($this->fact($talk)['came']);
        $late = $this->family(20);
        $this->came($this->act(15), $late);
        $this->assertTrue($this->fact($late)['came']);
        $this->assertSame(
            [1, 0],
            [count($this->fact($late)['visits']), facts::week($this->centre, $this->now - 7 * DAYSECS, $this->now)[1]],
            'Marked today, it came two weeks ago.'
        );
    }

    /**
     * Enrolled for an earlier school year, a family is of the centre for the next one and not for its own; whoever left, not.
     */
    public function test_who_by_campaign(): void {
        global $DB;
        $family = $this->family(380, [[-1, 'enrolled'], [0, 'inquiry']]);
        $cid = (int) $this->centre->id;
        $before = (string) $DB->get_field(centres::OFFERS, 'schoolyear', ['id' => $this->offers[-1]]);
        $this->assertSame('new', who::kind($cid, (int) $family->id, $before));
        $this->assertSame('centre', who::kind($cid, (int) $family->id, $this->year));
        settled::leave(households::get((int) $family->id, $cid), 2, 'moved', '', $this->now);
        $this->assertSame('former', who::kind($cid, (int) $family->id, $this->year));
        // The list of the next year names the child the campaign brought: it is still of that campaign.
        $new = $this->family(40, [[0, 'enrolled']]);
        $after = (string) $DB->get_field(centres::OFFERS, 'schoolyear', ['id' => $this->offers[1]]);
        foreach ([['enrolled', $this->year, 0], ['incentre', $after, listmatch::MADE]] as [$kind, $year, $marked]) {
            $DB->insert_record(listmatch::T, (object) ['centreid' => $cid, 'householdid' => $new->id, 'schoolyear' => $year,
                'student' => 'ALU_x', 'kind' => $kind, 'grade' => 'Infantil 4 años', 'timein' => 0, 'childid' => 0, 'done' => 1,
                'marked' => $marked, 'timecreated' => 1]);
        }
        $DB->set_field(households::T, 'incentre', 1, ['id' => $new->id]);
        $this->assertSame('new', who::kind($cid, (int) $new->id, $this->year));
        $this->assertSame('centre', who::kind($cid, (int) $new->id, $after));
        $this->assertSame('campaign', $this->fact($new)['group']);
    }

    /**
     * The campaign that ends is still there, with the start it had, and the new one is set against it.
     */
    public function test_past_campaigns(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $DB->set_field(centres::T, 'campaignstart', $this->now - 395 * DAYSECS, ['id' => $cid]);
        $this->centre = centres::get($cid);
        for ($i = 0; $i < 4; $i++) {
            $this->family(380, [[-1, $i ? 'inquiry' : 'enrolled']]);
        }
        panel_metrics::save_starts($this->centre, ['live' => date('Y-m-d', $this->now - 30 * DAYSECS)]);
        $this->centre = centres::get($cid);
        $this->family(3);
        $campaigns = panel_metrics::campaigns($this->centre, $this->now);
        $before = (string) $DB->get_field(centres::OFFERS, 'schoolyear', ['id' => $this->offers[-1]]);
        $this->assertSame(['live', 'y' . $before], array_keys($campaigns));
        $this->assertEqualsWithDelta($this->now - 395 * DAYSECS, $campaigns['y' . $before]['start'], DAYSECS);
        $this->assertCount(4, panel_metrics::families($this->centre, $campaigns['y' . $before], $this->now));
        $this->assertCount(1, panel_metrics::families($this->centre, $campaigns['live'], $this->now));
        $this->assertSame('y' . $before, panel_metrics::before($campaigns, $campaigns['live'])['key']);
        $numbers = panel_metrics::context($this->centre, ['campaign' => 'y' . $before], true, $this->now);
        $this->assertStringContainsString($before, $numbers['head']['title']);
        $live = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now);
        $this->assertStringContainsString($campaigns['y' . $before]['name'], $live['reach']['steps'][0]['line']);
    }

    /**
     * A share is only said when it is one: never a fraction that is not near, and nothing with fewer than ten behind.
     */
    public function test_shares(): void {
        $c = 'local_oksigeniaeguahe';
        $this->assertSame('', panel_metrics::fraction(2, 211));
        $this->assertSame(': ' . get_string('panel_frac_1_4', $c), panel_metrics::share(10, 41));
        $this->assertSame('', panel_metrics::fraction(95, 100));
        $this->assertSame('', panel_metrics::fraction(38, 100), 'Not one in three.');
        $this->assertSame('', panel_metrics::share(2, 7));
        $this->assertStringContainsString('0', panel_metrics::share(2, 211));
        $this->assertStringContainsString('%', panel_metrics::share(2, 211));
        $this->assertSame(': ' . get_string('panel_frac_all', $c), panel_metrics::share(12, 12));
        $this->assertSame(' (' . get_string('panel_frac_none', $c) . ')', panel_metrics::share(0, 12, true));
        $this->assertStringStartsWith('99', panel_metrics::percent(1999, 2000));
    }

    /**
     * The first answer counts those that still wait: with half of them waiting there is no median, an invitation to many and a
     * call nobody took are not answers, and with fewer than ten no time is said.
     */
    public function test_response(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $families = [];
        for ($i = 0; $i < 22; $i++) {
            $families[] = $this->family(16);
        }
        foreach ([0, 1] as $i) {
            households::answered($families[$i], 2, 'email', 'Sí.');
            $DB->set_field_select(households::CONTACTS, 'timecreated', $this->now - 16 * DAYSECS + HOURSECS, "householdid = ? AND direction = 'out'", [$families[$i]->id]);
        }
        households::told($families[2], 2, 'email', 'Invitación', true);
        $DB->insert_record(households::CONTACTS, (object) ['centreid' => $cid, 'householdid' => $families[3]->id, 'direction' => 'out',
            'channel' => 'phone', 'body' => get_string_manager()->get_string('teamnote_noanswer', 'local_oksigeniaeguahe', null, 'es') .
            ' · No contesta', 'entryid' => 0, 'userid' => 2,
            'timecreated' => $this->now - DAYSECS]);
        $r = metrics::response($cid, array_map(fn($h) => (int) $h->id, $families), $this->now);
        $this->assertSame([22, 2, 20], [$r['asked'], $r['answered'], $r['waiting']]);
        $this->assertNull($r['median']);
        $this->assertSame(20, $r['open']['more']);
        $this->assertEqualsWithDelta(16 * 24, $r['longest'], 1);
        $said = panel_metrics::response_sentence(panel_metrics::said($r));
        $this->assertStringContainsString('22', $said);
        $this->assertStringContainsString('20', $said);
        $this->assertStringNotContainsString(get_string('panel_response_half', 'local_oksigeniaeguahe', (object) ['median' =>
            panel_metrics::hours_words(1)]), $said);
        $this->assertSame(2.0, metrics::median([1.0, 2.0, 3.0, null]));
        $this->assertNull(metrics::median([1.0, null]));
        $this->assertSame(4.0, metrics::median([4.0, false]));
        $few = metrics::response($cid, [(int) $families[0]->id, (int) $families[1]->id], $this->now);
        $this->assertSame(1.0, $few['median']);
        $this->assertNull(panel_metrics::said($few)['median'], 'Two are not said as a time.');
    }

    /**
     * From the open day to the enrolment: only what was done after it, without those of the centre or whoever only came to it;
     * and nobody marked is said, never zero.
     */
    public function test_acts(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $act = $this->act(6);
        $before = $this->family(20, [[0, 'enrolled']]);
        $DB->set_field(households::CHILDREN, 'stagetime', $this->now - 10 * DAYSECS, ['householdid' => $before->id]);
        $after = $this->family(20);
        $tied = $this->family(20, [[0, 'enrolled']], ['incentre' => 1]);
        foreach ([$before, $after, $tied] as $family) {
            $this->came($act, $family);
        }
        households::set_stage($this->kids($after)[0], 'enrolled');
        $acts = panel_newstudents::acts($cid, 0, $this->now, $this->year);
        $this->assertCount(1, $acts);
        $this->assertSame([3, 1, 1, 1, 1, 1], [$acts[0]['came'], $acts[0]['centre'], $acts[0]['newin'], $acts[0]['students'],
            $acts[0]['before'], $acts[0]['talk'] ? 0 : 1]);
        // A talk: who only came to it is told apart, and it is not measured by enrolments.
        $talk = $this->act(4, 'other');
        $guest = $this->family(6, [], ['guest' => 1]);
        $this->came($talk, $guest);
        $this->came($talk, $after);
        $talks = array_values(array_filter(panel_newstudents::acts($cid, 0, $this->now, $this->year), fn($a) => $a['talk']));
        $this->assertSame([1, 1], [$talks[0]['came'], $talks[0]['guests']]);
        $past = (string) $DB->get_field(centres::OFFERS, 'schoolyear', ['id' => $this->offers[-1]]);
        $this->assertSame([], panel_newstudents::acts($cid, 0, $this->now, $past), 'It fills the next year, not this one.');
        // One with families signed up and nobody marked.
        $unmarked = $this->act(2);
        events::sign_up($unmarked, (int) $this->family(5)->id, 2);
        $rows = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now)['acts']['rows'];
        $this->assertSame([true, false], array_column($rows, 'missing'));
        $this->assertStringContainsString(get_string('panel_act_enrolled_one', 'local_oksigeniaeguahe', 1), $rows[1]['text']);
        $unknown = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now)['unknown'];
        $this->assertContains(get_string('panel_unknown_acts_one', 'local_oksigeniaeguahe', 1), array_column($unknown['list'], 'text'));
    }

    /**
     * What they spent is the same in every language, and what each new student cost is by student.
     */
    public function test_spend(): void {
        $family = $this->family(5, [[0, 'enrolled'], [0, 'enrolled']], ['arrival' => ['src' => 'chatgpt.com']]);
        $live = panel_metrics::campaigns($this->centre, $this->now)['live'];
        $families = panel_metrics::families($this->centre, $live, $this->now);
        $this->assertSame(2, $families[0]['students']);
        $keys = array_flip(panel_metrics::spend_keys($this->centre, $live, $families, 'measured'));
        $name = get_string('arrival_ai_group', 'local_oksigeniaeguahe');
        // As it was kept before, by its name in another language.
        $old = get_string_manager()->get_string('arrival_ai_group', 'local_oksigeniaeguahe', null, 'es');
        metrics::set_spend((int) $this->centre->id, panel_metrics::LIVE, 'row', $old, 500, 2);
        $cost = fn() => panel_metrics::context($this->centre, ['campaign' => 'live', 'voice' => 'measured'], true, $this->now)['channels']['rows'][0]['cost'];
        $this->assertStringContainsString('250', $cost());
        panel_metrics::save_spend($this->centre, $live, [$keys[$name] => '300'], 2);
        $this->assertSame(['row|@arrival_ai_group'], array_values(array_filter(
            array_keys(metrics::spend((int) $this->centre->id, panel_metrics::LIVE)),
            fn($k) => $k !== 'row|'
        )));
        $this->assertStringContainsString('150', $cost());
    }

    /**
     * What they wrote of how they heard of the centre: the answers of Eguahe in every language, and the website of the centre
     * is not a search engine.
     */
    public function test_heard(): void {
        foreach (lists::LANGS as $lang) {
            foreach (lists::HEARD_FROM as $heard) {
                $said = get_string_manager()->get_string('heard_' . $heard, 'local_oksigeniaeguahe', null, $lang);
                $this->assertSame($heard, heard::read($said), $lang . ': ' . $said);
            }
        }
        foreach (
            ['La web del colegio' => 'other', 'Página web del centro' => 'other', 'ChatGPT' => 'ai', 'Gemini' => 'ai',
                'Buscando en Google' => 'search', 'Internet' => 'search', 'Por una amiga' => 'family', 'Instagram' => 'social',
                'Un cartel en la calle' => 'ad', 'La guardería de mi hija' => 'referral', 'Vivimos cerca' => 'passingby',
                'Familias del colegio' => 'centrefamilies', '' => 'other'] as $said => $heard
        ) {
            $this->assertSame($heard, heard::read($said), $said);
        }
        $this->assertSame('unmeasured', arrival::channel((object) ['firstchannel' => 'webform', 'heardfrom' => heard::read('La web del colegio')]));
    }

    /**
     * The same student of two families of the roll takes one place, and is asked about once.
     */
    public function test_same_student_twice(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        centres::update($cid, (object) ['system' => 'es']);
        $older = centres::save_offer($cid, (object) ['schoolyear' => $this->year, 'name' => 'Infantil 4 años', 'places' => 25, 'active' => 1]);
        $ids = [];
        foreach (['uno', 'dos'] as $i => $name) {
            $family = $this->family(5, [[-1, 'enrolled']]);
            $child = $this->kids($family)[0];
            $DB->update_record(households::CHILDREN, (object) ['id' => $child->id, 'roster' => 1, 'familychild' => 90 + $i,
                'studentkey' => 'cifra:55']);
            $ids[] = (int) $child->id;
        }
        $this->assertSame(1, households::offer_counts($cid)[$this->offers[-1]]['enrolled']);
        $centre = (object) (['renewalyear' => $this->year] + (array) centres::get($cid));
        $this->assertCount(1, renewal::children($centre));
        $DB->insert_record(renewal::T, (object) ['centreid' => $cid, 'householdid' => 1, 'childid' => $ids[1], 'schoolyear' => $this->year,
            'answer' => 'stay', 'userid' => 0, 'timemodified' => 1]);
        $this->assertSame([$ids[1]], array_keys(renewal::children($centre)), 'The one that answered.');
    }

    /**
     * A child that left is said, by why, though it was enrolled for another school year; the weeks keep their days when the
     * clocks change; and whoever was noted as of the website of the centre has its words in every language.
     */
    public function test_gone_weeks_and_words(): void {
        global $CFG;
        $family = $this->family(40, [[-1, 'enrolled']]);
        settled::leave($family, 2, 'moved', '', $this->now - 5 * DAYSECS);
        $said = panel_metrics::gone($this->centre, $this->now - 60 * DAYSECS, $this->now + 1, '2026-27');
        $this->assertSame(get_string('panel_gone_sentence_one', 'local_oksigeniaeguahe', (object) ['n' => 1, 'year' => '2026-27']), $said['text']);
        $this->assertSame([get_string('left_moved', 'local_oksigeniaeguahe')], array_map(fn($r) => lcfirst($r['name']) === get_string(
            'left_moved',
            'local_oksigeniaeguahe'
        ) ? lcfirst($r['name']) : $r['name'], $said['reasons']));
        $this->setTimezone('Europe/Madrid');
        $monday = make_timestamp(2026, 10, 22, 0, 0, 0);
        $this->assertSame('2026-10-29 00:00', userdate(panel_metrics::days_after($monday, 7), '%Y-%m-%d %H:%M', 99, false));
        foreach (['es', 'en', 'pt', 'pt_br'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/local/oksigeniaeguahe/lang/' . $lang . '/local_oksigeniaeguahe.php');
            foreach (lists::CHANNELS as $channel) {
                $this->assertArrayHasKey('via_' . $channel, $string, $lang);
            }
        }
    }

    /**
     * Filtered by a course, those of the centre are those of that course too.
     */
    public function test_filtered_by_course(): void {
        $primary = centres::save_offer((int) $this->centre->id, (object) ['schoolyear' => $this->year, 'name' => '1.º de Primaria',
            'places' => 25, 'active' => 1]);
        $this->offers[7] = $primary;
        $this->family(5);
        $this->family(5, [[0, 'inquiry']], ['incentre' => 1]);
        $this->family(5, [[7, 'inquiry']], ['incentre' => 1]);
        $this->family(5, [[7, 'inquiry']], ['incentre' => 1]);
        $of = fn(string $course) => panel_metrics::context($this->centre, ['campaign' => 'live', 'course' => $course], true, $this->now);
        $whole = $of('');
        $said = fn(int $n) => get_string('panel_apart_centre' . ($n === 1 ? '_one' : ''), 'local_oksigeniaeguahe', (object) ['n' => $n,
            'year' => $this->year]);
        $this->assertSame($said(3), $whole['apart']['list'][0]['text']);
        $keys = array_column($whole['filters']['courses'], 'key', 'name');
        $this->assertSame($said(1), $of($keys['Infantil 3 años'])['apart']['list'][0]['text']);
        $this->assertFalse($of($keys['Infantil 3 años'])['reach']['steps'][0]['url'], 'Of one course, the numbers are not opened.');
        $this->assertNotFalse($whole['reach']['steps'][0]['url']);
    }

    /**
     * The advice to invite never counts whoever asked for no invitations, nor whoever is not to be written to.
     */
    public function test_invite_advice(): void {
        global $DB;
        $cid = (int) $this->centre->id;
        $this->act(-10);
        $families = [];
        for ($i = 0; $i < 4; $i++) {
            $families[] = $family = $this->family(5);
            $adult = (int) $DB->get_field(households::ADULTS, 'id', ['householdid' => $family->id]);
            households::consent($family, $adult, 'email', true, 'team', 'es');
            households::answered($family, 2, 'email', 'Sí.');
        }
        $kinds = fn() => array_column(report_data::build($this->centre, 'hot', $this->now - 7 * DAYSECS, $this->now, $this->now)['advice'] ?? [], 'key');
        $this->assertContains('invite', $kinds());
        foreach ([0, 1] as $i) {
            $DB->set_field(households::T, 'noinvites', $this->now, ['id' => $families[$i]->id]);
        }
        $this->assertNotContains('invite', $kinds());
    }

    /**
     * What is said with one family, or none, is said in the singular, in every language of the team.
     */
    public function test_singular(): void {
        $act = $this->act(3);
        $family = $this->family(5, [[0, 'inquiry']], ['arrival' => ['src' => 'google', 'med' => 'organic']]);
        $this->came($act, $family);
        $texts = function (): string {
            $live = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now);
            $report = report_data::build($this->centre, 'hot', $this->now - 7 * DAYSECS, $this->now + 1, $this->now);
            $questions = \local_oksigeniaeguahe\local\numbers_questions::context($this->centre, 'live', true, $this->now);
            $out = '';
            $walk = [$live, $report, $questions];
            array_walk_recursive($walk, function ($value) use (&$out) {
                $out .= is_string($value) ? strip_tags($value) . "\n" : '';
            });
            return $out;
        };
        $wrong = ['es' => '/\b(1 (familias|niños|alumnos|minutos|horas|días|contactos|inscritas|plazas)|(las|los|Las|Los) 1\b|0 de (las|los) 0)/u',
            'en' => '/\b(1 (families|children|students|minutes|hours|days|contacts|places)|of the 1\b|0 of the 0)/u',
            'pt' => '/\b(1 (famílias|crianças|alunos|minutos|horas|dias|contactos|vagas)|[Dd]?as 1\b|0 de 0)/u',
            'pt_br' => '/\b(1 (famílias|crianças|alunos|minutos|horas|dias|contatos|vagas)|[Dd]?as 1\b|0 de 0)/u'];
        $found = [];
        foreach ([1, 2] as $round) {
            foreach ($wrong as $lang => $pattern) {
                force_current_language($lang);
                foreach (explode("\n", $texts()) as $line) {
                    if (preg_match($pattern, $line)) {
                        $found[] = $lang . ': ' . $line;
                    }
                }
            }
            force_current_language('');
            households::set_stage($this->kids($family)[0], 'enrolled');
        }
        $this->assertSame([], array_values(array_unique($found)));
    }

    /**
     * With only families already of the centre asking for a place, none is new, and it does not say there is nothing to count.
     */
    public function test_only_of_the_centre(): void {
        $this->family(5, [[0, 'inquiry']], ['incentre' => 1]);
        $page = panel_metrics::context($this->centre, ['campaign' => 'live'], true, $this->now);
        $this->assertSame(get_string('panel_title_nonew', 'local_oksigeniaeguahe'), $page['head']['title']);
        $this->assertStringContainsString(get_string('panel_kinds_centre_one', 'local_oksigeniaeguahe', (object) ['centre' => 1]), $page['head']['sub']);
        $this->assertNotFalse($page['apart']);
        $empty = centres::get(centres::create((object) ['name' => 'Vacío', 'lang' => 'es', 'country' => 'ES']));
        $page = panel_metrics::context($empty, ['campaign' => 'live'], true, $this->now);
        $this->assertSame(get_string('panel_metrics_empty', 'local_oksigeniaeguahe'), $page['head']['title']);
    }
}
