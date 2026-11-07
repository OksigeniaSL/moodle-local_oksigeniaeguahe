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
 * Without a library of numbering plans: a number with its country code is taken as it is; one without it takes the
 * code of the country of the centre, dropping the trunk zero where that country dials one. When it cannot be told,
 * nothing is guessed.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phone {
    /** @var array Country => [calling code, whether national numbers start with a trunk 0 that is dropped]. */
    const COUNTRIES = [
        'AD' => ['376', false], 'AR' => ['54', true], 'AT' => ['43', true], 'BE' => ['32', true], 'BO' => ['591', true],
        'BR' => ['55', true], 'CA' => ['1', false], 'CH' => ['41', true], 'CL' => ['56', false], 'CO' => ['57', false],
        'CR' => ['506', false], 'CU' => ['53', false], 'DE' => ['49', true], 'DO' => ['1', false], 'EC' => ['593', true],
        'ES' => ['34', false], 'FR' => ['33', true], 'GB' => ['44', true], 'GQ' => ['240', false], 'GT' => ['502', false],
        'HN' => ['504', false], 'IE' => ['353', true], 'IT' => ['39', false], 'MA' => ['212', true], 'MX' => ['52', false],
        'NI' => ['505', false], 'NL' => ['31', true], 'PA' => ['507', false], 'PE' => ['51', true], 'PR' => ['1', false],
        'PT' => ['351', false], 'PY' => ['595', true], 'SV' => ['503', false], 'US' => ['1', false], 'UY' => ['598', true],
        'VE' => ['58', true],
    ];

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
        if (!$plus && strpos($digits, '00') === 0) {
            $plus = true;
            $digits = substr($digits, 2);
        }
        if (!$plus) {
            $country = strtoupper((string) $country);
            if (!isset(self::COUNTRIES[$country])) {
                return null;
            }
            [$code, $trunk] = self::COUNTRIES[$country];
            if ($trunk && strpos($digits, '0') === 0) {
                $digits = substr($digits, 1);
            }
            $digits = $code . $digits;
        }
        // E.164: up to 15 digits with the country code; nothing real is shorter than 8.
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }
        return '+' . $digits;
    }
}
