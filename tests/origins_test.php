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
use local_oksigeniaeguahe\local\metrics;

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
            ['inner' => 1, 'sibling' => 1, 'newfamily' => 2, 'unknown' => 1],
            array_map(fn($g) => $g['n'], $o['groups'])
        );
        $this->assertSame(['ad' => 1, 'campaign' => 0, 'none' => 1], $o['newby']);

        $p = metrics::publicity($cid, $o);
        $this->assertCount(1, $p);
        $this->assertSame(['label' => 'Captación', 'families' => 3, 'centre' => 1, 'students' => 1, 'spend' => 300.0,
            'each' => 300.0], $p[0]);
    }
}
