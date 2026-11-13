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
 * Phone numbers as one form (E.164, «+34600111222»), so the same family is found whichever way it typed its number.
 *
 * The numbering of every country comes from data, not from the code: the calling code, the national (trunk) prefix and
 * the international prefix of each of the 245 regions of libphonenumber (thirdparty/libphonenumber-metadata). A number
 * with its country code is taken as it is; one dialled with the international prefix of the country of the centre loses
 * it; one without either takes the calling code of that country, without its national prefix. When it cannot be told,
 * nothing is guessed.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phone {
    /** @var string The numbering of every region, as data. */
    const DATA = '/local/oksigeniaeguahe/thirdparty/libphonenumber-metadata/callingcodes.json';

    /** @var array|null The regions, read once. */
    protected static $regions = null;

    /**
     * The numbering of a region: its calling code, its national prefix and its international prefix (a pattern).
     *
     * @param string $country ISO 3166-1 alpha-2.
     * @return array|null code, national, international; null for a region it does not know.
     */
    public static function region(string $country): ?array {
        global $CFG;
        if (self::$regions === null) {
            $data = json_decode((string) file_get_contents($CFG->dirroot . self::DATA), true);
            self::$regions = $data['regions'] ?? [];
        }
        return self::$regions[strtoupper($country)] ?? null;
    }

    /**
     * The number in E.164, or null when it cannot be told.
     *
     * @param string $raw As typed.
     * @param string|null $country The country of the centre (ISO 3166-1 alpha-2), for numbers without their code.
     * @return string|null
     */
    public static function normalise(string $raw, ?string $country): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $plus = $raw[0] === '+';
        $digits = preg_replace('/\D+/', '', $raw);
        $region = $country ? self::region($country) : null;
        $abroad = $region && $region['international'] !== '' ? '/^(?:' . $region['international'] . ')/' : null;
        if (!$plus && $abroad && preg_match($abroad, $digits, $m)) {
            // Dialled from the country of the centre to another: what follows has its own country code.
            $plus = true;
            $digits = substr($digits, strlen($m[0]));
        } else if (!$plus && !$region && strpos($digits, '00') === 0) {
            // Without a country, the most usual international prefix.
            $plus = true;
            $digits = substr($digits, 2);
        }
        if (!$plus) {
            if (!$region) {
                return null;
            }
            if ($region['national'] !== '' && strpos($digits, $region['national']) === 0) {
                $digits = substr($digits, strlen($region['national']));
            }
            $digits = $region['code'] . $digits;
        }
        // E.164: up to 15 digits with the country code; nothing real is shorter than 8.
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }
        return '+' . $digits;
    }

    /**
     * A number in E.164 written to be read and dictated: its country code, then the rest in groups of three (the last
     * of four, or the first of two, so that no group is left with one digit): «+34 600 333 444».
     *
     * @param string $e164
     * @return string
     */
    public static function display(string $e164): string {
        if (!preg_match('/^\+(\d{8,15})$/', $e164, $m)) {
            return $e164;
        }
        $digits = $m[1];
        self::region('ES');
        $codes = array_unique(array_map(fn($r) => (string) ($r['code'] ?? ''), self::$regions ?? []));
        $code = '';
        foreach ([3, 2, 1] as $length) {
            if (in_array(substr($digits, 0, $length), $codes, true)) {
                $code = substr($digits, 0, $length);
                break;
            }
        }
        $rest = substr($digits, strlen($code));
        $n = strlen($rest);
        $groups = [];
        if ($n % 3 === 2) {
            $groups[] = substr($rest, 0, 2);
            $rest = substr($rest, 2);
        }
        $tail = $n % 3 === 1 && strlen($rest) >= 4 ? substr($rest, -4) : '';
        $rest = $tail !== '' ? substr($rest, 0, -4) : $rest;
        $groups = array_merge($groups, $rest !== '' ? str_split($rest, 3) : [], $tail !== '' ? [$tail] : []);
        return '+' . ($code !== '' ? $code . ' ' : '') . implode(' ', $groups);
    }
}
