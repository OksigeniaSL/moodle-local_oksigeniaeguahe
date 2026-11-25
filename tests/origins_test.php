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

use local_oksigeniaeguahe\local\alumni;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\listmatch;
use local_oksigeniaeguahe\local\metrics;
use local_oksigeniaeguahe\local\panel_newstudents;
use local_oksigeniaeguahe\local\who;

/**
 * Where the new students of a school year come from, and what the ads brought.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\metrics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(metrics::class)]
final class origins_test extends \advanced_testcase {
    /**
     * An inner step, a brother, new families by an ad and by nothing, and one with no family to tell; the ad with its
     * families, the ones of the centre among them, the new students of new families and what each cost.
     */
    public function test_origins_and_publicity(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $import = fn(string $kind, string $label) => (int) $DB->insert_record('local_oksigeniaeguahe_import', (object) [
            'centreid' => $cid, 'kind' => $kind, 'label' => $label, 'rowcount' => 0, 'userid' => 2, 'campaignstart' => 0,
            'timecreated' => time()]);
        $students = $import('joins', 'Alumnos');
        $ads = $import('contacts', 'Captación');
        $student = function (string $who, ?string $home, string $year, string $movement, string $grade) use ($DB, $cid, $students) {
            $DB->insert_record('local_oksigeniaeguahe_student', (object) ['centreid' => $cid, 'importid' => $students,
                'student' => $who, 'household' => $home, 'schoolyear' => $year, 'grade' => $grade,
                'timein' => strtotime('2026-09-01'), 'timeout' => 0, 'movement' => $movement, 'birthyear' => 0]);
        };
        // On the whole list of 2026-27: an older brother who was there, and the new ones.
        $student('ST_older0001', 'HO_family001', '2026-27', 'roll', 'Infantil 5 años');
        $student('ST_nursery01', 'HO_family002', '2025-26', 'join', 'Infantil 2 años');
        foreach (
            ['ST_nursery01' => 'HO_family002', 'ST_brother01' => 'HO_family001', 'ST_fromad001' => 'HO_family003',
                'ST_nothing01' => 'HO_family004', 'ST_nofamily1' => null] as $who => $home
        ) {
            $student($who, $home, '2026-27', 'join', 'Infantil 3 años');
            $student($who, $home, '2026-27', 'roll', 'Infantil 3 años');
        }
        foreach (['HO_family003' => 0, 'HO_family001' => 0, 'HO_family009' => 0] as $home => $unused) {
            $DB->insert_record('local_oksigeniaeguahe_pastcontact', (object) ['centreid' => $cid, 'importid' => $ads,
                'household' => $home, 'timecontact' => strtotime('2026-02-01'), 'channel' => 'meta', 'kind' => 'inquiry',
                'children' => 1, 'enrolled' => 0, 'timeenrolled' => 0, 'incentre' => 0, 'birthyear' => 0]);
        }
        metrics::set_spend($cid, 'Captación', 'meta', '', 300.0, 2);

        // The list of only the nursery of the year before: who was there, not the whole list of that year.
        $nursery = (int) $DB->insert_record('local_oksigeniaeguahe_import', (object) ['centreid' => $cid, 'kind' => 'roll',
            'label' => 'Guardería', 'rowcount' => 1, 'userid' => 2, 'campaignstart' => 0, 'timecreated' => time(),
            'partial' => 1]);
        $DB->insert_record('local_oksigeniaeguahe_student', (object) ['centreid' => $cid, 'importid' => $nursery,
            'student' => 'ST_fromad001', 'household' => 'HO_family003', 'schoolyear' => '2025-26', 'grade' => 'Infantil 2 años',
            'timein' => strtotime('2025-09-01'), 'timeout' => 0, 'movement' => 'roll', 'birthyear' => 0]);
        $this->assertNull(metrics::movements($cid, '2025-26')['totals']['start'], 'Not the whole list of 2025-26.');
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame(2, $o['groups']['inner']['n'], 'From the nursery of the centre: already there.');
        $DB->delete_records('local_oksigeniaeguahe_student', ['importid' => $nursery]);
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame(5, $o['total']);
        $this->assertTrue($o['hasroll']);
        $this->assertSame(
            ['inner' => 1, 'back' => 0, 'sibling' => 1, 'staff' => 0, 'newfamily' => 2, 'unknown' => 1],
            array_map(fn($g) => $g['n'], $o['groups'])
        );
        $this->assertSame(['own' => 0, 'ad' => 1, 'other' => 0, 'campaign' => 0, 'none' => 1], $o['newby']);

        $p = metrics::publicity($cid, $o);
        $this->assertCount(1, $p);
        $this->assertSame(['label' => 'Captación', 'families' => 3, 'centre' => 1, 'centrein' => 1, 'newin' => 1, 'students' => 1,
            'spend' => 300.0, 'each' => 300.0], array_intersect_key($p[0], array_flip(['label', 'families', 'centre', 'centrein',
            'newin', 'students', 'spend', 'each'])));
        // Out of its time, a campaign is left out.
        $this->assertSame([], metrics::publicity($cid, $o, strtotime('2026-06-01'), strtotime('2027-01-01')));

        // The sheet: a hundred dots, the hours written, and the whole context with its definitions.
        $this->assertSame(5, panel_newstudents::dots(1, 4, 100)['per']);
        $this->assertNull(panel_newstudents::dots(1, 0, 400)['per'], 'Under one in a hundred.');
        panel_newstudents::set_hours($cid, 'import', $ads, 'agency', 3.5, 2);
        panel_newstudents::set_hours($cid, 'import', $ads, 'agency', 4, 2);
        panel_newstudents::set_hours($cid, 'import', 999999, 'agency', 4, 2);
        $this->assertSame(['agency' => 4.0], panel_newstudents::written($cid)['import'][$ads]);
        $centre = centres::get($cid);
        $url = fn(array $v) => new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $cid]);
        $ctx = panel_newstudents::context($centre, '2026-27', true, $url, true, strtotime('2026-11-20'));
        $this->assertSame('2026-27', $ctx['year']);
        $ns = $ctx['newstudents'];
        $this->assertSame(['inner', 'sibling', 'newfamily', 'unknown'], array_column($ns['origins']['rows'], 'key'));
        $card = $ns['ads']['campaigns'][0];
        $this->assertSame(['agency'], array_column($card['written'], 'task'));
        $ids = array_column($ns['explain'], 'id');
        foreach (
            ['ex-total', 'ex-centre', 'ex-inner', 'ex-sibling', 'ex-newfamily', 'ex-ad', 'ex-none', 'ex-unknown',
                $card['ex']['contacts'], $card['ex']['per'], $card['ex']['hours']] as $id
        ) {
            $this->assertContains($id, $ids);
        }
        $this->assertNotEmpty($ns['truth']['is']);

        // Ads that took families to the website: their contacts came by its form, and money was spent on them.
        $web = $import('contacts', 'Puertas abiertas (web)');
        $DB->insert_record('local_oksigeniaeguahe_pastcontact', (object) ['centreid' => $cid, 'importid' => $web,
            'household' => 'HO_family004', 'timecontact' => strtotime('2026-02-05'), 'channel' => 'webform',
            'kind' => 'visit_booked', 'children' => 1, 'enrolled' => 0, 'timeenrolled' => 0, 'incentre' => 0, 'birthyear' => 0]);
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame(['own' => 0, 'ad' => 1, 'other' => 0, 'campaign' => 1, 'none' => 0], $o['newby'], 'Without money, another campaign.');
        $this->assertCount(1, metrics::publicity($cid, $o));
        metrics::set_spend($cid, 'Puertas abiertas (web)', 'meta', '', 99.48, 2);
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame(['own' => 0, 'ad' => 2, 'other' => 0, 'campaign' => 0, 'none' => 0], $o['newby']);
        $this->assertSame(['Captación', 'Puertas abiertas (web)'], array_column(metrics::publicity($cid, $o), 'label'));
    }

    /**
     * The families of Eguahe enrolled for the year, by what brought them first: the centre's own channels, an ad, or
     * nothing measured. Coming to an open day is a door, never what brought them.
     */
    public function test_live_new_by_first_contact(): void {
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        $event = events::get(events::save($cid, (object) ['kind' => 'openday', 'name' => 'Jornada de puertas abiertas',
            'timestart' => time() - DAYSECS, 'timeend' => time() - DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]), $cid);
        $family = function (array $arrival, string $first) use ($cid, $offer, $event): int {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => $first, 'arrival' => $arrival]);
            $household = households::get($id, $cid);
            households::add_child($household, 'Leo', (int) $offer);
            events::sign_up($event, $id, 2);
            $child = array_values(households::full($household)->children)[0];
            households::set_stage($child, 'enrolled');
            return $id;
        };
        $google = $family(['src' => 'google', 'med' => 'organic', 'cmp' => '', 'ref' => 'google.com'], 'webform');
        $ad = $family(['src' => 'instagram', 'med' => 'paid', 'cmp' => 'Jornada', 'ref' => ''], 'webform');
        $door = $family(['src' => 'puerta', 'med' => 'formulario', 'cmp' => '', 'ref' => ''], 'openday');
        $by = [];
        foreach (metrics::live_new($cid, '2027-28') as $live) {
            $by[$live['home']] = $live['by'];
        }
        $this->assertSame('own', $by['eg:h' . $google]);
        $this->assertSame('ad', $by['eg:h' . $ad]);
        $this->assertSame('none', $by['eg:h' . $door], 'The open day they came to did not bring them.');
    }

    /**
     * With the list, the students of families of Eguahe keep what brought them; those back after years are not new families;
     * and with only the list of the new ones, whoever was enrolled by hand counts once.
     */
    public function test_channels_with_the_list(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = centres::save_offer($cid, (object) ['schoolyear' => '2026-27', 'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $list = function (string $kind, string $label, string $csv) use ($cid): void {
            $path = make_request_directory() . '/l' . random_string(5) . '.csv';
            file_put_contents($path, $csv);
            imports::import_file($cid, $path, 'lista.csv', [0 => ['kind' => $kind, 'label' => $label, 'schoolyear' => $label, 'partial' => 0]], 2);
        };
        $ids = [];
        foreach ([['google', 'organic'], ['instagram', 'paid'], ['folleto', 'qr'], ['', '']] as $i => [$src, $med]) {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform', 'arrival' => ['src' => $src, 'med' => $med]]);
            $household = households::get($id, $cid);
            households::add_adult($household, (object) ['firstname' => 'Ana', 'phone' => '+3460011000' . $i]);
            $child = households::add_child($household, 'Leo' . $i, $offer);
            households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'enrolled');
            $ids[] = $id;
        }
        $this->assertSame(4, metrics::origins($cid, '2026-27')['total']);
        $head = "ID alumno,Teléfono del tutor,Curso,Fecha de alta\n";
        $joins = $head;
        for ($i = 0; $i < 4; $i++) {
            $joins .= "N$i,+3460011000$i,Infantil 3 años,2026-09-07\n";
        }
        $list('joins', '2026-27', $joins . "V1,+34600119999,5.º de Primaria,2026-09-07\n");
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame(5, $o['total'], 'Each child once, though it was enrolled by hand before.');
        $this->assertSame(['own' => 1, 'ad' => 1, 'other' => 1, 'campaign' => 0, 'none' => 2], $o['newby']);
        // It was there years ago, and not the last one.
        $list('roll', '2023-24', $head . "V1,+34600119999,2.º de Primaria,2023-09-07\n");
        $list('roll', '2025-26', $head . "X1,+34600118888,2.º de Primaria,2025-09-07\n");
        $o = metrics::origins($cid, '2026-27');
        $this->assertSame([1, 4], [$o['groups']['back']['n'], $o['groups']['newfamily']['n']]);
        // Without a list of the year before, who was there earlier is back, never an inner step.
        $DB->delete_records(metrics::STUDENTS, ['centreid' => $cid, 'schoolyear' => '2025-26']);
        $this->assertSame([1, 0], [metrics::origins($cid, '2026-27')['groups']['back']['n'], metrics::origins($cid, '2026-27')['groups']['inner']['n']]);
        // And the ads of Eguahe have their own card, with what they brought.
        $cards = array_column(metrics::publicity($cid, $o), null, 'label');
        $this->assertSame([1, 1], [$cards[get_string('panel_new_ads_noname', 'local_oksigeniaeguahe')]['newin'],
            $cards[get_string('panel_new_ads_noname', 'local_oksigeniaeguahe')]['students']]);
        $page = panel_newstudents::context(centres::get($cid), '2026-27', true, fn($v) => new \moodle_url('/'), true);
        $this->assertContains('back', array_column($page['newstudents']['origins']['rows'], 'key'));
        // The school year Eguahe is filling can be opened too.
        $next = centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $child = households::add_child(households::get($ids[0], $cid), 'Noa', $next);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'enrolled');
        $this->assertSame(['2027-28', '2026-27'], metrics::years_with_new($cid));
        $page = panel_newstudents::context(centres::get($cid), '2027-28', true, fn($v) => new \moodle_url('/'), true);
        $this->assertSame('2027-28', $page['year']);
        $this->assertFalse($page['newstudents']['year'], 'Without its list, no ledger, and never that of another year.');
        // For the next campaign, that family is of the centre: its second child is a brother or sister.
        $this->assertSame(1, metrics::origins($cid, '2027-28')['groups']['sibling']['n']);
    }

    /**
     * A child the centre's own list has in its nursery, marked by nobody, that enrols: an inner step, not a brother or sister;
     * and a family of a former student counts as such before there is any list of the year.
     */
    public function test_nursery_and_alumni_of_eguahe(): void {
        global $DB;
        $this->resetAfterTest();
        $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $offer = (int) centres::save_offer($cid, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25, 'active' => 1]);
        $path = make_request_directory() . '/l.csv';
        file_put_contents($path, "ID alumno,Teléfono del tutor,Curso,Fecha de alta\nG1,+34600112501,Infantil 2 años,2026-09-07\n");
        imports::import_file($cid, $path, 'g.csv', [0 => ['kind' => 'roll', 'label' => '2026-27', 'schoolyear' => '2026-27', 'partial' => 1]], 2);
        $family = function (string $phone, string $name) use ($cid, $offer): array {
            $id = households::create($cid, (object) ['lang' => 'es', 'firstchannel' => 'webform']);
            $adult = households::add_adult(households::get($id, $cid), (object) ['firstname' => 'Ana', 'phone' => $phone]);
            return [$id, $adult, households::add_child(households::get($id, $cid), $name, $offer)];
        };
        [$nursery, , $child] = $family('+34600112501', 'Leo');
        listmatch::detect(centres::get($cid), time());
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $child]), 'enrolled');
        [$studied, $adult, $other] = $family('+34600112502', 'Noa');
        alumni::set(households::get($studied, $cid), (int) $adult, alumni::YES, 'team', 2);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $other]), 'enrolled');
        who::reset();
        $this->assertSame('nursery', who::kind($cid, $nursery));
        $new = array_column(metrics::live_new($cid, '2027-28'), null, 'home');
        $this->assertSame('inner', $new['eg:h' . $nursery]['kind']);
        $o = metrics::origins($cid, '2027-28');
        $this->assertSame([1, 0, 1], [$o['groups']['inner']['n'], $o['groups']['sibling']['n'], $o['alumni']['n']]);
    }

    /**
     * Who left, up to today, is set against who had left by the same day a year ago; those of July and those without a day are
     * in the total, which is the one of the ledger; and a school year of one calendar year has its own before.
     */
    public function test_leaves_to_the_same_day(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $world = function (array $years, array $leaves): int {
            $cid = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
            $list = function (string $kind, string $label, string $csv) use ($cid): void {
                $path = make_request_directory() . '/l' . random_string(5) . '.csv';
                file_put_contents($path, $csv);
                imports::import_file($cid, $path, 'l.csv', [0 => ['kind' => $kind, 'label' => $label, 'schoolyear' => $label, 'partial' => 0]], 2);
            };
            foreach ($years as $year => $day) {
                $csv = "ID alumno,Curso,Fecha de alta\n";
                for ($i = 1; $i <= 20; $i++) {
                    $csv .= "S$i,2.º de Primaria,$day\n";
                }
                $list('roll', (string) $year, $csv);
                $list('joins', (string) $year, "ID alumno,Curso,Fecha de alta\nN$year,Infantil 3 años,$day\n");
            }
            foreach ($leaves as $year => $days) {
                $csv = "ID alumno,Curso,Fecha de baja\n";
                foreach ($days as $i => $day) {
                    $csv .= 'S' . ($i + 1) . ",2.º de Primaria,$day\n";
                }
                $list('leaves', (string) $year, $csv);
            }
            return $cid;
        };
        $sheet = fn(int $cid, string $year, string $now) => panel_newstudents::context(
            centres::get($cid),
            $year,
            true,
            fn($view) => new \moodle_url('/'),
            false,
            strtotime($now)
        );
        $cid = $world(['2025-26' => '2025-09-08', '2026-27' => '2026-09-07'], [
            '2025-26' => ['2025-11-10', '2025-11-10', '2025-11-10', '2025-11-27', '2025-11-27', '2026-07-10', ''],
            '2026-27' => array_fill(0, 6, '2026-11-10')]);
        $moves = $sheet($cid, '2026-27', '2026-11-25 12:00')['newstudents']['moves'];
        $this->assertSame([6, 6, 3], [$moves['leaves'], $moves['dated'], $moves['bleaves']]);
        $more = get_string('panel_new_trend_more', 'local_oksigeniaeguahe', (object) ['leaves' => 6, 'diff' => 3, 'before' => 3]);
        $this->assertStringStartsWith($more, $moves['trend']);
        $past = $sheet($cid, '2025-26', '2026-11-25 12:00');
        $this->assertSame([7, 6], [$past['newstudents']['moves']['leaves'], $past['newstudents']['moves']['dated']]);
        $this->assertNotFalse($past['newstudents']['moves']['undated']);
        $this->assertStringContainsString('7', $past['title']);
        $cid = $world(['2025' => '2025-03-03', '2026' => '2026-03-02'], ['2025' => ['2025-06-10', '2025-06-10'], '2026' => ['2026-06-10']]);
        $moves = $sheet($cid, '2026', '2026-11-25 12:00')['newstudents']['moves'];
        $this->assertSame([true, 2], [$moves['hasbefore'], $moves['bleaves']]);
    }
}
