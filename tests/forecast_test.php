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
use local_oksigeniaeguahe\local\forecast;
use local_oksigeniaeguahe\local\imports;
use local_oksigeniaeguahe\local\opendata;
use local_oksigeniaeguahe\local\opendata_sources\istac;

/**
 * The forecast: reading the open data, the age of each course, the share of the area, the ranges ahead and the reach
 * by municipality. Without the network: the answers of the ISTAC are made up here, with the shape of the real ones.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\forecast
 * @covers     \local_oksigeniaeguahe\local\opendata
 */
#[\PHPUnit\Framework\Attributes\CoversClass(forecast::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(opendata::class)]
final class forecast_test extends \advanced_testcase {
    /**
     * An answer of the ISTAC with the shape of the real ones and made-up numbers.
     *
     * @param array $dims Dimension => codes, in the order of the answer.
     * @param int[] $values
     * @return array
     */
    protected function answer(array $dims, array $values): array {
        $dimension = [];
        foreach ($dims as $id => $codes) {
            $dimension[] = ['dimensionId' => $id, 'representations' => ['representation' => array_map(
                fn($code, $index) => ['code' => (string) $code, 'index' => $index],
                $codes,
                array_keys($codes)
            )]];
        }
        return ['data' => ['dimensions' => ['dimension' => $dimension], 'observations' => implode(' | ', $values)]];
    }

    /**
     * The observations come in the order of the dimensions, the last one changing fastest.
     */
    public function test_istac_rows(): void {
        $rows = istac::parse($this->answer(['TIME_PERIOD' => ['2023', '2024'], 'TERRITORIO' => ['35001', '35002'],
            'SEXO' => ['_T']], [10, 20, 30, '']));
        $this->assertSame(
            ['TIME_PERIOD' => '2023', 'TERRITORIO' => '35002', 'SEXO' => '_T', 'value' => 20],
            $rows[1]
        );
        $this->assertSame('2024', $rows[2]['TIME_PERIOD']);
        $this->assertNull($rows[3]['value']);
        $this->assertTrue((new istac())->covers('38001'));
        $this->assertFalse((new istac())->covers('28065'));
        // The sources are found by themselves; none is written in the code that uses them.
        $this->assertArrayHasKey('istac', opendata::sources());
        $this->assertSame(['istac'], array_keys(opendata::covering(['38001', '28065'])));
        $this->assertSame([], opendata::covering(['28065', '3550308']), 'Madrid or São Paulo: the CSV of the centre.');
    }

    /**
     * Ages from the usual names of the courses, to start from.
     */
    public function test_suggest_age(): void {
        $this->assertSame(3, forecast::suggest_age('Infantil 3 años'));
        $this->assertSame(6, forecast::suggest_age('1º de Primaria'));
        $this->assertSame(12, forecast::suggest_age('1.º de ESO'));
        $this->assertSame(16, forecast::suggest_age('1º de Bachiller'));
        $this->assertNull(forecast::suggest_age('Ciclo de cocina'));
    }

    /**
     * The open data of the area, fetched and kept; the share of the years before and the ranges ahead; the reach.
     */
    public function test_forecast(): void {
        global $DB;
        $this->resetAfterTest();
        $year = (int) date('Y');
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo', 'municipalities' => "35001;Uno\n35002;Dos"]);
        $centre = centres::get($centreid);
        // Births of the area: 200 a year in the first municipality and 100 in the second, made up.
        $years = array_map('strval', range($year - 12, $year));
        $births = [];
        foreach ($years as $y) {
            $births[] = 200;
            $births[] = 100;
        }
        // Children by age on 1 January of the open data: 150 and 50 of each age.
        $ages = [];
        foreach (range(0, opendata::MAXAGE) as $age) {
            $ages[] = 150;
            $ages[] = 50;
        }
        opendata::$testanswers = [
            istac::BIRTHS => $this->answer(['TIME_PERIOD' => $years, 'TERRITORIO' => ['35001', '35002']], $births),
            istac::AGES => $this->answer(
                ['TERRITORIO' => ['35001', '35002'],
                'EDAD' => array_map(fn($a) => 'Y' . $a, range(0, opendata::MAXAGE))],
                array_merge(...array_map(
                    null,
                    array_slice($ages, 0, opendata::MAXAGE + 1),
                    array_slice($ages, opendata::MAXAGE + 1)
                ))
            ),
        ];
        try {
            $this->assertGreaterThan(0, opendata::fetch($centreid, forecast::zone($centre)));
        } finally {
            opendata::$testanswers = null;
        }
        $this->assertSame(['istac'], array_keys(opendata::status($centreid)));

        // The course of three-year-olds took 30 of 300 two years ago and 45 last year: a share of 10 % to 15 %.
        $last = $year - 1;
        imports::import($centreid, 'totals', 'Años anteriores', 't.csv', "school_year,grade,count\n" .
            ($last - 1) . '-' . substr((string) $last, 2) . ",Infantil 3 años,30\n" .
            $last . '-' . substr((string) ($last + 1), 2) . ",Infantil 3 años,45\n" .
            $last . '-' . substr((string) ($last + 1), 2) . ",Bebés,6\n", 2);
        forecast::save_ages($centreid, ['Infantil 3 años' => ['age' => 3, 'forecast' => true],
            'Bebés' => ['age' => 0, 'forecast' => true]]);
        $courses = array_column(forecast::by_grade($centre), null, 'grade');
        $this->assertCount(2, $courses);
        // The babies of the years after this one are not born yet: the trend of the births before, flat here.
        $babies = $courses['Bebés']['ahead'];
        $this->assertSame([300, 'births'], [$babies[0]['cohort'], $babies[0]['kind']]);
        $this->assertSame([300, 'estimate', 6, 6], [$babies[1]['cohort'], $babies[1]['kind'], $babies[1]['low'],
            $babies[1]['high']]);
        $courses = array_values(array_filter($courses, fn($c) => $c['grade'] === 'Infantil 3 años'));
        $DB->delete_records(forecast::AGES, ['centreid' => $centreid, 'grade' => 'Bebés']);
        $this->assertEqualsWithDelta(0.1, $courses[0]['past'][0]['share'], 0.0001);
        $this->assertEqualsWithDelta(0.15, $courses[0]['past'][1]['share'], 0.0001);
        $ahead = $courses[0]['ahead'][0];
        $this->assertSame(300, $ahead['cohort']);
        $this->assertSame([30, 45], [$ahead['low'], $ahead['high']]);

        // Reach: the students of the centre against the children of the ages of its courses.
        imports::import(
            $centreid,
            'students',
            (string) $last,
            's.csv',
            "student,school_year,grade,date_in,municipality\nALU_aaaaaa1111,{$last}-" . substr((string) ($last + 1), 2) .
            ",Infantil 3 años,{$last}-09-01,35002\n",
            2
        );
        $reach = array_column(forecast::reach($centre), null, 'code');
        $this->assertSame(1, $reach['35002']['students']);
        $this->assertSame(50, $reach['35002']['children']);
        $this->assertEquals(2.0, $reach['35002']['share']);
        $this->assertSame(0, $reach['35001']['students']);
    }

    /**
     * A cohort not known yet: the last one known, carried by the yearly changes before it, in a range.
     */
    public function test_estimate(): void {
        $known = [2020 => 1000, 2021 => 950, 2022 => 900, 2023 => 880, 2024 => 860];
        $cohort = fn(int $year) => isset($known[$year]) ? [$known[$year], 'births', 'istac'] : null;
        // Changes of 0.947, 0.95, 0.977 and 0.978: two years on, 860 × 0.95² in the middle, from 860 × 0.947² to
        // 860 × 0.978².
        $this->assertSame([776, 'estimate', 'istac', 772, 822], forecast::estimate($cohort, 2026));
        // One year known: it stays.
        $this->assertSame([500, 'estimate', 'istac', 500, 500], forecast::estimate(
            fn(int $year) => $year === 2024 ? [500, 'births', 'istac'] : null,
            2025
        ));
        $this->assertNull(forecast::estimate(fn(int $year) => null, 2025));
    }

    /**
     * Anywhere else, the centre uploads the numbers of its area.
     */
    public function test_population_csv(): void {
        global $DB;
        $this->resetAfterTest();
        $centreid = centres::create((object) ['name' => 'Colegio de ejemplo']);
        [$id, $rows] = imports::import(
            $centreid,
            'population',
            'Zona',
            'p.csv',
            "municipality,year,kind,age,people\n28065,2024,births,,1500\n28065,2025,age,3,1600\n",
            2
        );
        $this->assertSame(2, $rows);
        $this->assertSame(['csv'], array_keys(opendata::status($centreid)));
        imports::delete($centreid, $id);
        $this->assertSame(0, $DB->count_records(opendata::T));
    }
}
