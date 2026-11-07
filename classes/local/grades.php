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
 * The courses as each file names them («INF 1º A», «1.º de Primaria», «infantil_3_años», «Primaria 2º») brought to
 * one name: the one of the offer of the centre for the same age, or a usual one. By the age at which a course starts,
 * which is what tells them apart in any school system the centre uses.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades {
    /** @var array Stage words (without accents) => [age of its first course, the usual name, short codes]. */
    const STAGES = [
        'infantil' => [3, 'Infantil {age} años', ['inf', 'infantil', 'educacion infantil', 'ei']],
        'primaria' => [6, '{n}.º de Primaria', ['pri', 'prim', 'primaria', 'primary', 'educacion primaria', 'ep']],
        'eso' => [12, '{n}.º de ESO', ['eso', 'secundaria', 'educacion secundaria', 'secondary']],
        'bachillerato' => [16, '{n}.º de Bachillerato', ['bac', 'bach', 'bachiller', 'bachillerato']],
    ];

    /** @var array Names of the offer of each centre by age, read once. */
    protected static $offers = [];

    /**
     * A text without accents, in lower case, with single spaces.
     *
     * @param string $text
     * @return string
     */
    public static function plain(string $text): string {
        $text = \core_text::strtolower(trim($text));
        $text = strtr($text, ['º' => 'o ', 'ª' => 'a ', '°' => 'o ']);
        $text = (string) \core_text::specialtoascii($text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Splits «INF 1º A» into the course and its group.
     *
     * @param string $text
     * @return array [course, group or null]
     */
    public static function split_group(string $text): array {
        $text = trim($text);
        if (preg_match('/^(.*\d\s*[ºo°ª.]?)\s+([A-Z])$/u', $text, $m) && self::age($m[1]) !== null) {
            return [trim($m[1]), $m[2]];
        }
        return [$text, null];
    }

    /**
     * The age turned in the calendar year the course starts, from its name.
     *
     * @param string $grade
     * @return int|null Null for a stage («infantil de 3 a 5 años») or a name it does not know.
     */
    public static function age(string $grade): ?int {
        $g = self::plain($grade);
        if ($g === '' || preg_match('/\d+\s*(a|y|to|-)?\s*\d+\s*(anos?|years?)/', $g)) {
            return null;
        }
        if (preg_match('/\b(\d+)\s*(meses|months?)\b/', $g)) {
            return 0;
        }
        if (preg_match('/\b(\d{1,2})\s*(anos?|years?|year old)\b/', $g, $m)) {
            return (int) $m[1];
        }
        foreach (self::STAGES as [$base, , $codes]) {
            $words = implode('|', array_map(fn($c) => preg_quote($c, '/'), $codes));
            // The number before the stage first: «1º ESO 6 líneas» is the first of ESO.
            if (
                preg_match('/\b(\d)\s*o?\s*(de\s+|do\s+)?(' . $words . ')\b/', $g, $m) ||
                preg_match('/\b(' . $words . ')\s*(\d)\s*o?\b/', $g, $m)
            ) {
                $n = (int) (ctype_digit($m[2] ?? '') ? $m[2] : $m[1]);
                return $n >= 1 && $n <= 6 ? $base + $n - 1 : null;
            }
        }
        return null;
    }

    /**
     * The usual name of a course from its age, when the text says its stage.
     *
     * @param string $grade
     * @param int $age
     * @return string|null
     */
    protected static function usual(string $grade, int $age): ?string {
        $g = self::plain($grade);
        foreach (self::STAGES as [$base, $name, $codes]) {
            foreach ($codes as $code) {
                if (preg_match('/\b' . preg_quote($code, '/') . '\b/', $g)) {
                    return str_replace(['{age}', '{n}'], [$age, $age - $base + 1], $name);
                }
            }
        }
        return preg_match('/\b(anos?)\b/', $g) ? "$age años" : null;
    }

    /**
     * The name a course takes in Eguahe: the one of the offer of the centre for that age, else its usual name, else
     * the text as it came, tidied.
     *
     * @param int $centreid
     * @param string $grade
     * @return string
     */
    public static function label(int $centreid, string $grade): string {
        $grade = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $grade)));
        $age = self::age($grade);
        if ($age === null) {
            return \core_text::substr(\core_text::strtoupper(\core_text::substr($grade, 0, 1)) .
                \core_text::substr($grade, 1), 0, 100);
        }
        if (!isset(self::$offers[$centreid])) {
            self::$offers[$centreid] = [];
            foreach (centres::offers($centreid) as $offer) {
                $offerage = self::age((string) $offer->name);
                if ($offerage !== null && !isset(self::$offers[$centreid][$offerage])) {
                    self::$offers[$centreid][$offerage] = (string) $offer->name;
                }
            }
        }
        return self::$offers[$centreid][$age] ?? self::usual($grade, $age) ?? \core_text::substr($grade, 0, 100);
    }

    /**
     * Forgets the names read, when the offer changes.
     */
    public static function reset(): void {
        self::$offers = [];
    }
}
