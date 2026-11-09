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

namespace local_oksigeniaeguahe\local\opendata_sources;

use local_oksigeniaeguahe\local\opendata;

/**
 * The Canary Institute of Statistics (ISTAC): births by municipality and year, and people by single age and
 * municipality, without a key, for commercial use with its citation. One source among others: what it knows of its API
 * stays here.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class istac extends source {
    /** @var string The API of the ISTAC. */
    const API = 'https://datos.canarias.es/api/estadisticas/statistical-resources/v1.0/datasets/ISTAC/';

    /** @var string Births by municipality of residence of the mother and year (1999 onwards). */
    const BIRTHS = 'E30304A_000002';

    /** @var string People by single age, municipality and singular entity, on 1 January of AGES_YEAR. */
    const AGES = 'E30243A_000023';

    /** @var int The year on whose 1 January AGES counts. */
    const AGES_YEAR = 2025;

    #[\Override]
    public function key(): string {
        return 'istac';
    }

    #[\Override]
    public function covers(string $code): bool {
        // The official codes of Las Palmas (35) and Santa Cruz de Tenerife (38).
        return (bool) preg_match('/^(35|38)\d{3}$/', $code);
    }

    #[\Override]
    public function rows(array $codes, int $maxage): array {
        $years = range((int) date('Y') - 12, (int) date('Y'));
        $births = self::parse($this->get(self::BIRTHS, ['TERRITORIO' => $codes, 'SEXO' => ['_T'],
            'TIME_PERIOD' => array_map('strval', $years)]));
        $ages = self::parse($this->get(self::AGES, ['TERRITORIO' => $codes, 'SEXO' => ['_T'],
            'EDAD' => array_map(fn($a) => 'Y' . $a, range(0, $maxage))]));
        $out = [];
        foreach ($births as $row) {
            if ($row['value'] !== null && in_array($row['TERRITORIO'] ?? '', $codes, true)) {
                $out[] = ['kind' => 'births', 'municipality' => $row['TERRITORIO'], 'year' => (int) $row['TIME_PERIOD'],
                    'age' => -1, 'people' => $row['value']];
            }
        }
        foreach ($ages as $row) {
            if (
                $row['value'] !== null && in_array($row['TERRITORIO'] ?? '', $codes, true) &&
                    preg_match('/^Y(\d+)$/', (string) ($row['EDAD'] ?? ''), $m)
            ) {
                $out[] = ['kind' => 'age', 'municipality' => $row['TERRITORIO'], 'year' => self::AGES_YEAR,
                    'age' => (int) $m[1], 'people' => $row['value']];
            }
        }
        return $out;
    }

    /**
     * The observations of an answer of the ISTAC, each with the codes of its dimensions.
     *
     * @param array $answer The decoded JSON.
     * @return array Rows: [dimension id => code, ..., 'value' => int|null].
     */
    public static function parse(array $answer): array {
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
    protected function get(string $dataset, array $dims): array {
        global $CFG;
        if (opendata::$testanswers !== null) {
            return opendata::$testanswers[$dataset] ?? [];
        }
        $parts = [];
        foreach ($dims as $dim => $codes) {
            $parts[] = $dim . ':' . implode('|', $codes);
        }
        $url = self::API . $dataset . '/~latest?lang=es&fields=-metadata&dim=' . rawurlencode(implode(':', $parts));
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
}
