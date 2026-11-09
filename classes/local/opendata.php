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
 * Open data of the area of a centre, always aggregate: births by municipality and year, and children by age.
 *
 * No country is written here: the sources are the classes of local\opendata_sources, each saying which official codes
 * it covers (the ISTAC is one of them). Anywhere none covers, the centre uploads the same numbers as a CSV
 * (imports.php, «population»). What is fetched is kept with its source; the pages only read what is kept.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class opendata {
    /** @var string */
    const T = 'local_oksigeniaeguahe_population';

    /** @var int Ages fetched: from birth to the end of school. */
    const MAXAGE = 18;

    /** @var array|null Answers of the APIs in the tests: dataset => decoded JSON. */
    public static $testanswers = null;

    /**
     * Every source Eguahe has, found in its namespace.
     *
     * @return opendata_sources\source[] Key => source.
     */
    public static function sources(): array {
        $out = [];
        $classes = \core_component::get_component_classes_in_namespace('local_oksigeniaeguahe', 'local\\opendata_sources');
        foreach (array_keys($classes) as $class) {
            if (is_subclass_of($class, opendata_sources\source::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $source = new $class();
                $out[$source->key()] = $source;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The sources that cover some municipality of an area, each with the codes it covers.
     *
     * @param string[] $codes Official codes of the area.
     * @return array Key => [source, codes].
     */
    public static function covering(array $codes): array {
        $out = [];
        foreach (self::sources() as $key => $source) {
            $mine = array_values(array_unique(array_filter($codes, fn($c) => $source->covers((string) $c))));
            if ($mine) {
                $out[$key] = [$source, $mine];
            }
        }
        return $out;
    }

    /**
     * Fetches from every source that covers the area its numbers, and keeps them in place of what was kept from it.
     *
     * @param int $centreid
     * @param string[] $codes Official codes of the area.
     * @param string|null $only One source, or all that cover the area.
     * @return int Numbers kept; 0 when no source covers the area.
     */
    public static function fetch(int $centreid, array $codes, ?string $only = null): int {
        global $DB;
        $kept = 0;
        foreach (self::covering($codes) as $key => [$source, $mine]) {
            if ($only !== null && $key !== $only) {
                continue;
            }
            $now = time();
            $records = array_map(fn($row) => (object) ($row + ['centreid' => $centreid, 'source' => $key,
                'timefetched' => $now]), $source->rows($mine, self::MAXAGE));
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records(self::T, ['centreid' => $centreid, 'source' => $key]);
            $DB->insert_records(self::T, $records);
            $transaction->allow_commit();
            $kept += count($records);
        }
        return $kept;
    }

    /**
     * What is kept for a centre, by source: how many numbers and when.
     *
     * @param int $centreid
     * @return array source => [numbers, municipalities, fetched].
     */
    public static function status(int $centreid): array {
        global $DB;
        $status = [];
        $rows = $DB->get_records_sql(
            'SELECT source, COUNT(1) AS n, COUNT(DISTINCT municipality) AS places,
                                             MAX(timefetched) AS fetched
                                        FROM {' . self::T . '} WHERE centreid = :centreid GROUP BY source',
            ['centreid' => $centreid]
        );
        foreach ($rows as $row) {
            $status[$row->source] = ['numbers' => (int) $row->n, 'places' => (int) $row->places,
                'fetched' => (int) $row->fetched];
        }
        return $status;
    }
}
