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
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\metrics;

/**
 * What already happened: importing it pseudonymised, and the numbers that come out of it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\imports
 * @covers     \local_oksigeniaeguahe\local\metrics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(imports::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(metrics::class)]
final class metrics_test extends \advanced_testcase {
    /** @var string A small campaign, example data: four households, one writes twice, two are enrolled. */
    const CONTACTS = "household,date,channel,source_detail,kind,grade_requested,utm_source,utm_medium,enrolled,enrolled_date
HOG_aaaaaa1111,2025-10-02,web_form,Formulario de contacto,inquiry,Infantil 3 años,,,0,
HOG_bbbbbb2222,2025-10-05,meta_lead_ads,Captación,inquiry,Infantil 3 años,ig,paid,1,2026-03-09
HOG_bbbbbb2222,2026-03-01,open_day,Jornada,open_day_attended,Infantil 3 años,,,1,2026-03-09
HOG_cccccc3333,2025-11-20,meta_lead_ads,Captación,inquiry,,fb,paid,0,
HOG_dddddd4444,2026-01-10,web_form,Formulario de contacto,application,1º de ESO,,,1,2026-04-01
";

    /** @var string Students of a school year, example data: most start on 1 September; one joins later, two leave. */
    const STUDENTS = "student,household,school_year,grade,group,date_in,date_out,out_reason,municipality,locality
ALU_aaaaaa1111,HOG_aaaaaa0000,2026-27,1º de ESO,A,2026-09-01,,,28065,Getafe
ALU_bbbbbb2222,HOG_bbbbbb0000,2026-27,1º de ESO,A,2026-09-01,2026-11-15,Traslado de domicilio,28065,Getafe
ALU_cccccc3333,HOG_cccccc0000,2026-27,1º de ESO,B,2026-09-01,,,28074,Leganés
ALU_dddddd4444,HOG_dddddd0000,2026-27,Infantil 3 años,A,2026-10-20,,,28065,Getafe
ALU_eeeeee5555,HOG_eeeeee0000,2026-27,Infantil 3 años,A,2026-09-01,2027-01-10,,28074,Leganés
";

    /**
     * A centre with example data.
     *
     * @return int
     */
    protected function centre(): int {
        $this->resetAfterTest();
        return centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es']);
    }

    /**
     * Nothing is refused: an email, a phone or a name where a token or a text was expected is removed on the way in.
     */
    public function test_nothing_refused(): void {
        global $DB;
        $centreid = $this->centre();
        $variants = [
            str_replace('Formulario de contacto,inquiry', 'ana@example.com,inquiry', self::CONTACTS),
            str_replace('HOG_aaaaaa1111', 'Ana Gil', self::CONTACTS),
            str_replace('Captación,inquiry,,fb', 'Llamar al 600 111 222,inquiry,,fb', self::CONTACTS),
            str_replace('household,date', 'familia,date', self::CONTACTS),
        ];
        foreach ($variants as $content) {
            [$id, $rows, $errors] = imports::import($centreid, 'contacts', '2025-26', 'c.csv', $content, 2);
            $this->assertNotSame(0, $id);
            $this->assertSame([5, []], [$rows, $errors]);
        }
        $dump = json_encode(array_values($DB->get_records('local_oksigeniaeguahe_pastcontact')), JSON_UNESCAPED_UNICODE);
        foreach (['ana@example.com', 'Ana Gil', '600 111 222'] as $value) {
            $this->assertStringNotContainsString($value, $dump);
        }
    }

    /**
     * The way of a campaign by households, its channels from both ends, and what each cost.
     */
    public function test_campaign(): void {
        global $DB;
        $centreid = $this->centre();
        [$id, $rows] = imports::import($centreid, 'contacts', '2025-26', 'c.csv', self::CONTACTS, 2);
        $this->assertSame(5, $rows);
        $this->assertSame('webform', $DB->get_field(
            'local_oksigeniaeguahe_pastcontact',
            'channel',
            ['household' => 'HOG_aaaaaa1111']
        ));
        metrics::set_spend($centreid, '2025-26', 'meta', 'Captación', 300, 2);
        metrics::set_spend($centreid, '2025-26', 'webform', '', 50, 2);
        $data = metrics::campaign($DB->get_record(imports::T, ['id' => $id]));
        $t = $data['totals'];
        $this->assertSame([4, 5, 3, 1, 1, 2], [$t['households'], $t['contacts'], $t['inquiries'], $t['visited'],
            $t['applied'], $t['enrolled']]);
        // Of the three that asked for a course, two were enrolled; the one that came was enrolled.
        $this->assertEquals(66.7, $t['inquirytoenrolled']);
        $this->assertEquals(100.0, $t['visitedtoenrolled']);
        $channels = array_column($data['channel'], null, 'key');
        // The household that came from Meta and was enrolled after the open day counts for Meta first, the open day last.
        $this->assertSame(2, $channels['meta']['households']);
        $this->assertSame(1, $channels['meta']['enrolled']);
        $this->assertSame(0, $channels['meta']['lastenrolled']);
        $this->assertSame(1, $channels['openday']['lastenrolled']);
        $this->assertEquals(300.0, $channels['meta']['spend']);
        $this->assertEquals(300.0, $channels['meta']['costperenrolled']);
        $this->assertEquals(50.0, $channels['webform']['costperenrolled']);
        $this->assertEquals(175.0, $t['costperenrolled']);
        $platforms = array_column($data['platform'], 'households', 'key');
        $this->assertSame(['fb' => 1, 'ig' => 1], array_intersect_key($platforms, ['fb' => 1, 'ig' => 1]));

        imports::delete($centreid, $id);
        $this->assertSame(0, $DB->count_records('local_oksigeniaeguahe_pastcontact'));
    }

    /**
     * Who came and went in a school year, and the years before.
     */
    public function test_movements_and_history(): void {
        $centreid = $this->centre();
        imports::import($centreid, 'students', '2026-27', 's.csv', self::STUDENTS, 2);
        $m = metrics::movements($centreid, '2026-27');
        $this->assertSame(['start' => 4, 'new' => 0, 'joins' => 1, 'leaves' => 2, 'now' => 3, 'net' => -1], $m['totals']);
        $reasons = array_column($m['reasons'], 'count', 'reason');
        $this->assertSame(1, $reasons['Traslado de domicilio']);
        $this->assertSame(2, array_column($m['places'], 'students', 'code')['28065']);

        imports::import(
            $centreid,
            'totals',
            'Años anteriores',
            't.csv',
            "school_year,grade,count\n2025-26,1º de ESO,30\n2025-26,Infantil 3 años,25\n",
            2
        );
        [$years, , $matrix, $totals] = metrics::history($centreid);
        $this->assertSame(['2025-26', '2026-27'], $years);
        $this->assertSame(30, $matrix['1.º de ESO']['2025-26']);
        $this->assertSame(3, $matrix['1.º de ESO']['2026-27']);
        $this->assertSame(55, $totals['2025-26']);
    }
}
