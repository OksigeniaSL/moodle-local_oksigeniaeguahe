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

namespace local_oksigeniaeguahe\local;

/**
 * The forecast of a course, always aggregate and in ranges: the share of the children of its area the centre took in
 * the years it has, applied to the children who will come, with its places as the ceiling. And how far the centre
 * reaches in each municipality: its students against the children of school age who live there.
 *
 * A cohort is the children born in one year in the area: the births by municipality when published, else the children
 * of that age on the 1 January of the open data, else (not born yet) the trend of the years before. Each number says
 * which.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forecast {
    /** @var string */
    const AGES = 'local_oksigeniaeguahe_gradeage';

    /** @var int School years forecast after the last one known. */
    const AHEAD = 3;

    /** @var int Years of births whose yearly changes carry a cohort not yet known. */
    const TREND = 5;

    /**
     * An age for a course from its usual names (es, pt, en), to start from; the centre corrects it.
     *
     * @param string $grade
     * @param \stdClass|null $centre Its school system.
     * @return int|null The age turned in the calendar year the school year starts.
     */
    public static function suggest_age(string $grade, ?\stdClass $centre = null): ?int {
        return grades::age($grade, $centre);
    }

    /**
     * The courses of a centre, from its offer and what it imported, with their age and whether they are forecast.
     *
     * @param int $centreid
     * @return array Grade => [age (or the suggestion, or null), forecast, saved].
     */
    public static function grades(int $centreid): array {
        global $DB;
        $names = [];
        foreach (centres::offers($centreid) as $offer) {
            $names[$offer->name] = true;
        }
        [, $grades] = metrics::history($centreid);
        foreach ($grades as $grade) {
            $names[$grade] = true;
        }
        $saved = $DB->get_records(self::AGES, ['centreid' => $centreid], '', 'grade, age, forecast');
        $list = [];
        foreach (array_keys($names) as $grade) {
            $row = $saved[$grade] ?? null;
            $list[$grade] = ['age' => $row ? (int) $row->age : self::suggest_age((string) $grade, centres::get($centreid)),
                'forecast' => $row ? (bool) $row->forecast : false, 'saved' => (bool) $row];
        }
        uasort($list, fn($a, $b) => [$a['age'] ?? 99] <=> [$b['age'] ?? 99]);
        return $list;
    }

    /**
     * Saves the age of the courses of a centre and which are forecast.
     *
     * @param int $centreid
     * @param array $rows Grade => [age, forecast].
     */
    public static function save_ages(int $centreid, array $rows): void {
        global $DB;
        foreach ($rows as $grade => $row) {
            $grade = \core_text::substr(trim((string) $grade), 0, 100);
            if ($grade === '') {
                continue;
            }
            $record = (object) ['centreid' => $centreid, 'grade' => $grade, 'age' => max(0, min(30, (int) $row['age'])),
                'forecast' => empty($row['forecast']) ? 0 : 1];
            $id = $DB->get_field(self::AGES, 'id', ['centreid' => $centreid, 'grade' => $grade]);
            if ($id) {
                $record->id = $id;
                $DB->update_record(self::AGES, $record);
            } else {
                $DB->insert_record(self::AGES, $record);
            }
        }
    }

    /**
     * The area of a centre: the municipalities of its list and those its students come from.
     *
     * @param \stdClass $centre
     * @return string[] Official codes.
     */
    public static function zone(\stdClass $centre): array {
        global $DB;
        $codes = array_filter(array_keys(centres::municipalities($centre)), fn($c) => strpos((string) $c, 'name:') !== 0);
        $students = $DB->get_fieldset_select(
            metrics::STUDENTS,
            'DISTINCT municipality',
            'centreid = :centreid AND municipality IS NOT NULL',
            ['centreid' => $centre->id]
        );
        return array_values(array_unique(array_map('strval', array_merge($codes, $students))));
    }

    /**
     * The children born in a year in the area.
     *
     * @param int $centreid
     * @param string[] $zone
     * @param int $birthyear
     * @return array|null [people, kind (births or age), source]; null when nothing tells.
     */
    public static function cohort(int $centreid, array $zone, int $birthyear): ?array {
        global $DB;
        if (!$zone) {
            return null;
        }
        [$in, $params] = $DB->get_in_or_equal($zone, SQL_PARAMS_NAMED);
        $params += ['centreid' => $centreid, 'birthyear' => $birthyear];
        $births = $DB->get_record_sql("SELECT SUM(people) AS people, MAX(source) AS source
                                         FROM {" . opendata::T . "}
                                        WHERE centreid = :centreid AND kind = 'births' AND year = :birthyear
                                              AND municipality $in", $params);
        if ($births && $births->people) {
            return [(int) $births->people, 'births', (string) $births->source];
        }
        // Not yet in the births: the children of that age on the 1 January of the open data.
        $ages = $DB->get_records_sql("SELECT year, SUM(people) AS people, MAX(source) AS source
                                        FROM {" . opendata::T . "}
                                       WHERE centreid = :centreid AND kind = 'age' AND municipality $in
                                             AND age = year - :birthyear - 1
                                    GROUP BY year ORDER BY year DESC", $params);
        foreach ($ages as $row) {
            if ($row->people) {
                return [(int) $row->people, 'age', (string) $row->source];
            }
        }
        return null;
    }

    /**
     * The children of a year not born yet, or whose births are not published: the last cohort known, carried forward
     * by the yearly changes of the years before it. A range (with the lowest and the highest change) and its middle
     * (with the median change).
     *
     * @param callable $known A birth year => its cohort as cohort() gives it, or null.
     * @param int $birthyear
     * @return array|null [middle, 'estimate', source, low, high]; null when no year before is known.
     */
    public static function estimate(callable $known, int $birthyear): ?array {
        $last = null;
        for ($year = $birthyear - 1; $year >= $birthyear - 2 * self::TREND; $year--) {
            if (($known($year)[0] ?? 0) > 0) {
                $last = $year;
                break;
            }
        }
        if ($last === null) {
            return null;
        }
        $changes = [];
        for ($year = $last; $year > $last - self::TREND; $year--) {
            $now = $known($year)[0] ?? 0;
            $before = $known($year - 1)[0] ?? 0;
            if (!$now || !$before) {
                break;
            }
            $changes[] = $now / $before;
        }
        $changes = $changes ?: [1.0];
        sort($changes);
        $gap = $birthyear - $last;
        $base = (int) $known($last)[0];
        $median = $changes[(int) floor((count($changes) - 1) / 2)];
        return [(int) round($base * $median ** $gap), 'estimate', (string) $known($last)[2],
            (int) round($base * reset($changes) ** $gap), (int) round($base * end($changes) ** $gap)];
    }

    /**
     * The forecast of each course the centre forecasts: the years it has, with their share of the area, and the years
     * ahead in ranges, with the places as the ceiling.
     *
     * @param \stdClass $centre
     * @return array A list of courses: grade, age, past (year, students, cohort, share), ahead (year, cohort, low, mid,
     *               high, places).
     */
    public static function by_grade(\stdClass $centre): array {
        $zone = self::zone($centre);
        [$years, , $matrix] = metrics::history((int) $centre->id);
        $lastyear = $years ? (int) substr((string) end($years), 0, 4) : (int) date('Y');
        $places = [];
        foreach (centres::offers((int) $centre->id) as $offer) {
            $places[$offer->name][(int) substr((string) $offer->schoolyear, 0, 4)] = (int) $offer->places;
        }
        $known = [];
        $cohortof = function (int $birthyear) use (&$known, $centre, $zone): ?array {
            if (!array_key_exists($birthyear, $known)) {
                $known[$birthyear] = self::cohort((int) $centre->id, $zone, $birthyear);
            }
            return $known[$birthyear];
        };
        $out = [];
        foreach (self::grades((int) $centre->id) as $grade => $info) {
            if (!$info['forecast'] || $info['age'] === null) {
                continue;
            }
            $past = [];
            $shares = [];
            foreach ($matrix[$grade] ?? [] as $year => $students) {
                $start = (int) substr((string) $year, 0, 4);
                $cohort = $cohortof($start - $info['age']);
                $share = $cohort && $cohort[0] ? $students / $cohort[0] : null;
                if ($share !== null) {
                    $shares[] = $share;
                }
                $past[] = ['year' => $year, 'students' => $students, 'cohort' => $cohort[0] ?? null, 'share' => $share];
            }
            sort($shares);
            $ahead = [];
            for ($start = $lastyear + 1; $start <= $lastyear + self::AHEAD; $start++) {
                $cohort = $cohortof($start - $info['age']) ?? self::estimate($cohortof, $start - $info['age']);
                $row = ['year' => $start . '-' . substr((string) ($start + 1), 2), 'cohort' => $cohort[0] ?? null,
                    'kind' => $cohort[1] ?? null, 'low' => null, 'mid' => null, 'high' => null,
                    'places' => $places[$grade][$start] ?? 0];
                if ($cohort && $shares) {
                    // An estimated cohort widens the range with its own.
                    $row['low'] = (int) round(reset($shares) * ($cohort[3] ?? $cohort[0]));
                    $row['high'] = (int) round(end($shares) * ($cohort[4] ?? $cohort[0]));
                    $row['mid'] = (int) round($shares[(int) floor((count($shares) - 1) / 2)] * $cohort[0]);
                }
                $ahead[] = $row;
            }
            $out[] = ['grade' => $grade, 'age' => $info['age'], 'past' => $past, 'ahead' => $ahead];
        }
        return $out;
    }

    /**
     * How far the centre reaches in each municipality: its students against the children of the ages of its courses
     * who live there. Where there are many children and few students is where it can grow.
     *
     * @param \stdClass $centre
     * @return array A list: code, name, students, children, share (%), sorted by children.
     */
    public static function reach(\stdClass $centre): array {
        global $DB;
        // The last school year the centre has the whole list of.
        $year = $DB->get_field_sql("SELECT MAX(schoolyear) FROM {" . metrics::STUDENTS . "}
                                     WHERE centreid = :centreid AND movement = 'roll'", ['centreid' => $centre->id]);
        if (!$year) {
            return [];
        }
        $ages = array_filter(array_column(self::grades((int) $centre->id), 'age'), fn($a) => $a !== null);
        [$minage, $maxage] = $ages ? [min($ages), max($ages)] : [0, opendata::MAXAGE - 1];
        $students = $DB->get_records_sql("SELECT municipality, COUNT(DISTINCT student) AS n
                                            FROM {" . metrics::STUDENTS . "}
                                           WHERE centreid = :centreid AND schoolyear = :year AND timeout = 0
                                                 AND movement <> 'leave' AND municipality IS NOT NULL
                                        GROUP BY municipality", ['centreid' => $centre->id, 'year' => $year]);
        $children = $DB->get_records_sql("SELECT municipality, SUM(people) AS n FROM {" . opendata::T . "}
                                           WHERE centreid = :centreid AND kind = 'age' AND age BETWEEN :minage AND :maxage
                                                 AND year = (SELECT MAX(year) FROM {" . opendata::T . "}
                                                              WHERE centreid = :centreid2 AND kind = 'age')
                                        GROUP BY municipality", ['centreid' => $centre->id, 'centreid2' => $centre->id,
                                            'minage' => $minage, 'maxage' => $maxage]);
        $names = centres::municipalities($centre);
        $list = [];
        foreach (array_unique(array_merge(array_keys($students), array_keys($children))) as $code) {
            $n = (int) ($students[$code]->n ?? 0);
            $c = (int) ($children[$code]->n ?? 0);
            // The name from the list of the centre; a locality of the students would name a part, not the municipality.
            $list[] = ['code' => (string) $code, 'name' => $names[$code] ?? (string) $code,
                'students' => $n, 'children' => $c, 'share' => $c ? round(100 * $n / $c, 1) : null];
        }
        usort($list, fn($a, $b) => [$b['children'], $b['students']] <=> [$a['children'], $a['students']]);
        return $list;
    }
}
