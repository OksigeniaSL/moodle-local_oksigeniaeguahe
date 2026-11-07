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
 * One source per region, with the finest step first. The Canary Institute of Statistics (ISTAC) gives both by
 * municipality, without a key, for commercial use with its citation. Anywhere else, the centre can upload the same
 * numbers as a CSV (imports.php, «population»). What is fetched is kept; the pages only read what is kept.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class opendata {
    /** @var string */
    const T = 'local_oksigeniaeguahe_population';

    /** @var string The API of the ISTAC. */
    const ISTAC = 'https://datos.canarias.es/api/estadisticas/statistical-resources/v1.0/datasets/ISTAC/';

    /** @var string Births by municipality of residence of the mother and year (1999 onwards). */
    const ISTAC_BIRTHS = 'E30304A_000002';

    /** @var string People by single age, municipality and singular entity, on 1 January 2025. */
    const ISTAC_AGES = 'E30243A_000023';

    /** @var int The year on whose 1 January ISTAC_AGES counts. */
    const ISTAC_AGES_YEAR = 2025;

    /** @var int Ages fetched: from birth to the end of school. */
    const MAXAGE = 18;

    /** @var array|null Answers of the API in the tests: dataset => decoded JSON. */
    public static $testanswers = null;

    /**
     * Whether a municipality has its numbers at the ISTAC: the codes of Las Palmas (35) and Santa Cruz de Tenerife (38).
     *
     * @param string $code
     * @return bool
     */
    public static function istac_covers(string $code): bool {
        return (bool) preg_match('/^(35|38)\d{3}$/', $code);
    }

    /**
     * The observations of an answer of the ISTAC, each with the codes of its dimensions.
     *
     * @param array $answer The decoded JSON.
     * @return array Rows: [dimension id => code, ..., 'value' => int|null].
     */
    public static function istac_rows(array $answer): array {
        $dimensions = [];
        foreach ($answer['data']['dimensions']['dimension'] ?? [] as $dimension) {
            $codes = [];
            foreach ($dimension['representations']['representation'] ?? [] as $r) {
                $codes[(int) $r['index']] = (string) $r['code'];
            }
            ksort($codes);
            $dimensions[] = [(string) $dimension['dimensionId'], array_values($codes)];
        }
        $values = explode('|', (string) ($answer['data']['observations'] ?? ''));
        $rows = [];
        foreach ($values as $i => $value) {
            $codes = [];
            // The observations go in the order of the dimensions, the last one changing fastest.
            $rest = $i;
            for ($d = count($dimensions) - 1; $d >= 0; $d--) {
                $size = max(1, count($dimensions[$d][1]));
                $codes[$d] = $dimensions[$d][1][$rest % $size] ?? '';
                $rest = intdiv($rest, $size);
            }
            $row = [];
            foreach ($dimensions as $d => [$id]) {
                $row[$id] = $codes[$d];
            }
            $value = trim($value);
            $row['value'] = $value === '' || !is_numeric($value) ? null : (int) round((float) $value);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Asks the ISTAC for some observations of a dataset.
     *
     * @param string $dataset
     * @param array $dims Dimension => codes.
     * @return array The decoded answer.
     */
    protected static function istac_get(string $dataset, array $dims): array {
        global $CFG;
        if (self::$testanswers !== null) {
            return self::$testanswers[$dataset] ?? [];
        }
        $parts = [];
        foreach ($dims as $dim => $codes) {
            $parts[] = $dim . ':' . implode('|', $codes);
        }
        $url = self::ISTAC . $dataset . '/~latest?lang=es&fields=-metadata&dim=' . rawurlencode(implode(':', $parts));
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader(['Accept: application/json']);
        $body = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $info = $curl->get_info();
        if ($curl->get_errno() || (int) ($info['http_code'] ?? 0) !== 200) {
            throw new \moodle_exception('opendata_failed', 'local_oksigeniaeguahe', '', 'ISTAC ' . $dataset);
        }
        return json_decode((string) $body, true) ?: [];
    }

    /**
     * Fetches from the ISTAC the births and the children by age of the municipalities of the area it covers, and keeps
     * them in place of what was kept from it before.
     *
     * @param int $centreid
     * @param string[] $codes Official codes of the area.
     * @return int Numbers kept; 0 when no municipality of the area is covered.
     */
    public static function fetch_istac(int $centreid, array $codes): int {
        global $DB;
        $codes = array_values(array_unique(array_filter($codes, [self::class, 'istac_covers'])));
        if (!$codes) {
            return 0;
        }
        $years = range((int) date('Y') - 12, (int) date('Y'));
        $births = self::istac_rows(self::istac_get(self::ISTAC_BIRTHS, ['TERRITORIO' => $codes, 'SEXO' => ['_T'],
            'TIME_PERIOD' => array_map('strval', $years)]));
        $ages = self::istac_rows(self::istac_get(self::ISTAC_AGES, ['TERRITORIO' => $codes, 'SEXO' => ['_T'],
            'EDAD' => array_map(fn($a) => 'Y' . $a, range(0, self::MAXAGE))]));
        $now = time();
        $records = [];
        foreach ($births as $row) {
            if ($row['value'] !== null && in_array($row['TERRITORIO'] ?? '', $codes, true)) {
                $records[] = (object) ['centreid' => $centreid, 'source' => 'istac', 'kind' => 'births',
                    'municipality' => $row['TERRITORIO'], 'year' => (int) $row['TIME_PERIOD'], 'age' => -1,
                    'people' => $row['value'], 'timefetched' => $now];
            }
        }
        foreach ($ages as $row) {
            if (
                $row['value'] !== null && in_array($row['TERRITORIO'] ?? '', $codes, true) &&
                    preg_match('/^Y(\d+)$/', (string) ($row['EDAD'] ?? ''), $m)
            ) {
                $records[] = (object) ['centreid' => $centreid, 'source' => 'istac', 'kind' => 'age',
                    'municipality' => $row['TERRITORIO'], 'year' => self::ISTAC_AGES_YEAR, 'age' => (int) $m[1],
                    'people' => $row['value'], 'timefetched' => $now];
            }
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::T, ['centreid' => $centreid, 'source' => 'istac']);
        $DB->insert_records(self::T, $records);
        $transaction->allow_commit();
        return count($records);
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
