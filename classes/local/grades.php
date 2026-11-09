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
 * The courses as each file names them («INF 1º A», «1.º de Primaria», «Grade 3», «Year 1», «5º ano») brought to one
 * name: the one of the offer of the centre for the same age, or a usual one. By the age at which a course starts, which
 * is what tells them apart in any school system.
 *
 * No school system is written in the code: they come from data/school-systems.json, each with its countries and its
 * stages. A centre uses its own (chosen in its settings, or else the one of its country); the age the centre wrote for
 * a course always wins. Without a system, all are tried, and where two say different ages, the age is not known: no
 * country goes first.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades {
    /** @var string The school systems, as data. */
    const DATA = '/local/oksigeniaeguahe/data/school-systems.json';

    /** @var array|null The data, read once. */
    protected static $data = null;

    /** @var array Names of the offer of each centre by age, read once. */
    protected static $offers = [];

    /** @var array The ages each centre wrote for its courses, by plain name, read once. */
    protected static $own = [];

    /**
     * The school systems and the words every one uses, as data.
     *
     * @return array generic and systems.
     */
    public static function data(): array {
        global $CFG;
        if (self::$data === null) {
            self::$data = json_decode((string) file_get_contents($CFG->dirroot . self::DATA), true) ?: [];
        }
        return self::$data;
    }

    /**
     * The school system of a centre: the one it chose, else the one of its country; '' when neither is known.
     *
     * @param \stdClass|null $centre
     * @return string
     */
    public static function system(?\stdClass $centre): string {
        $systems = self::data()['systems'] ?? [];
        if (!$centre) {
            return '';
        }
        if (($centre->system ?? '') === 'none') {
            return '';
        }
        if (!empty($centre->system) && isset($systems[$centre->system])) {
            return (string) $centre->system;
        }
        foreach ($systems as $key => $system) {
            if (!empty($centre->country) && in_array($centre->country, $system['countries'] ?? [], true)) {
                return (string) $key;
            }
        }
        return '';
    }

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
     * @param \stdClass|null $centre
     * @return array [course, group or null]
     */
    public static function split_group(string $text, ?\stdClass $centre = null): array {
        $text = trim($text);
        if (preg_match('/^(.*\d\s*[ºo°ª.]?)\s+([A-Z])$/u', $text, $m) && self::age($m[1], $centre) !== null) {
            return [trim($m[1]), $m[2]];
        }
        return [$text, null];
    }

    /**
     * A list of words as a pattern.
     *
     * @param array $words
     * @return string
     */
    protected static function any(array $words): string {
        return implode('|', array_map(fn($w) => preg_quote($w, '/'), $words));
    }

    /**
     * The age turned in the calendar year the course starts, from its name: the one the centre wrote for it, else by
     * the years or months it says, else by the stages of the school system of the centre.
     *
     * @param string $grade
     * @param \stdClass|null $centre Its own ages and its system; without it, every system, and no age where they differ.
     * @return int|null Null for a stage («infantil de 3 a 5 años») or a name it does not know.
     */
    public static function age(string $grade, ?\stdClass $centre = null): ?int {
        global $DB;
        $g = self::plain($grade);
        if ($g === '') {
            return null;
        }
        if ($centre && !empty($centre->id)) {
            $id = (int) $centre->id;
            if (!isset(self::$own[$id])) {
                self::$own[$id] = [];
                foreach ($DB->get_records(forecast::AGES, ['centreid' => $id], '', 'id, grade, age') as $row) {
                    self::$own[$id][self::plain((string) $row->grade)] = (int) $row->age;
                }
            }
            if (isset(self::$own[$id][$g])) {
                return self::$own[$id][$g];
            }
        }
        $generic = self::data()['generic'] ?? [];
        $years = self::any($generic['years'] ?? []);
        // A range of ages («3 a 5 años», «3-5 years») is a stage, not a course; its two numbers go apart, never «18».
        if ($years !== '' && preg_match('/\b\d+(?:\s*(?:a|y|to|e|-)\s*|\s+)\d+\s*(' . $years . ')\b/', $g)) {
            return null;
        }
        $months = self::any($generic['months'] ?? []);
        if ($months !== '' && preg_match('/\b\d+\s*(' . $months . ')\b/', $g)) {
            return 0;
        }
        if ($years !== '' && preg_match('/\b(\d{1,2})\s*(' . $years . ')\b/', $g, $m)) {
            return (int) $m[1];
        }
        $own = self::system($centre);
        $systems = self::data()['systems'] ?? [];
        $ages = [];
        foreach ($own !== '' ? [$own => $systems[$own]] : $systems as $system) {
            $age = self::in_system($g, $system);
            if ($age !== null) {
                $ages[$age] = true;
            }
        }
        return count($ages) === 1 ? (int) array_key_first($ages) : null;
    }

    /**
     * The age of a course in one school system, and the stage that named it.
     *
     * @param string $g The name, plain.
     * @param array $system
     * @param array|null $stage The stage, when one names it.
     * @param int|null $n The number of the course in its stage.
     * @return int|null
     */
    protected static function in_system(string $g, array $system, ?array &$stage = null, ?int &$n = null): ?int {
        $generic = self::data()['generic'] ?? [];
        $ordinal = self::any($generic['ordinals'] ?? []);
        $join = self::any($generic['joins'] ?? []);
        foreach ($system['stages'] ?? [] as $candidate) {
            if (!empty($candidate['with']) && !preg_match('/\b(' . self::any($candidate['with']) . ')\b/', $g)) {
                continue;
            }
            $words = self::any($candidate['words']);
            if (!empty($candidate['fixed'])) {
                if (preg_match('/\b(' . $words . ')\b/', $g)) {
                    [$stage, $n] = [$candidate, 1];
                    return (int) $candidate['age'];
                }
                continue;
            }
            // The number before the stage first: «1º ESO 6 líneas» is the first of ESO.
            if (
                preg_match('/\b(\d{1,2})\s*(?:' . $ordinal . ')?\s*(?:(?:' . $join . ')\s+)?(?:' . $words . ')\b/', $g, $m) ||
                preg_match('/\b(?:' . $words . ')\s*(\d{1,2})\s*(?:' . $ordinal . ')?\b/', $g, $m)
            ) {
                $number = (int) $m[1];
                if ($number >= 1 && $number <= (int) ($candidate['max'] ?? 6)) {
                    [$stage, $n] = [$candidate, $number];
                    return (int) $candidate['age'] + $number - 1;
                }
            }
        }
        return null;
    }

    /**
     * The usual name of a course from its age, when the text says its stage: the stage gives the pattern of the name,
     * the age gives its number.
     *
     * @param string $grade
     * @param int $age
     * @param \stdClass|null $centre
     * @return string|null
     */
    protected static function usual(string $grade, int $age, ?\stdClass $centre): ?string {
        $g = self::plain($grade);
        $own = self::system($centre);
        $systems = self::data()['systems'] ?? [];
        $names = [];
        foreach ($own !== '' ? [$systems[$own]] : $systems as $system) {
            foreach ($system['stages'] ?? [] as $stage) {
                if (!empty($stage['with']) && !preg_match('/\b(' . self::any($stage['with']) . ')\b/', $g)) {
                    continue;
                }
                if (!preg_match('/\b(' . self::any($stage['words']) . ')\b/', $g)) {
                    continue;
                }
                $n = $age - (int) $stage['age'] + 1;
                if (!empty($stage['fixed']) ? $n === 1 : $n >= 1 && $n <= (int) ($stage['max'] ?? 6)) {
                    $names[] = str_replace(['{age}', '{n}'], [$age, $n], (string) $stage['usual']);
                }
                break;
            }
        }
        return count(array_unique($names)) === 1 ? $names[0] : null;
    }

    /**
     * The words that name a course in any system, for telling a column of courses in a file.
     *
     * @return string[]
     */
    public static function words(): array {
        $data = self::data();
        $words = array_merge($data['generic']['years'] ?? [], $data['generic']['months'] ?? [], $data['generic']['courses'] ?? []);
        foreach ($data['systems'] ?? [] as $system) {
            foreach ($system['stages'] ?? [] as $stage) {
                $words = array_merge($words, $stage['words']);
            }
        }
        return array_values(array_unique(array_filter($words, fn($w) => strlen($w) > 2)));
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
        $centre = centres::get($centreid);
        $grade = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $grade)));
        $age = self::age($grade, $centre);
        if ($age === null) {
            return \core_text::substr(\core_text::strtoupper(\core_text::substr($grade, 0, 1)) .
                \core_text::substr($grade, 1), 0, 100);
        }
        if (!isset(self::$offers[$centreid])) {
            self::$offers[$centreid] = [];
            foreach (centres::offers($centreid) as $offer) {
                $offerage = self::age((string) $offer->name, $centre);
                if ($offerage !== null && !isset(self::$offers[$centreid][$offerage])) {
                    self::$offers[$centreid][$offerage] = (string) $offer->name;
                }
            }
        }
        if (isset(self::$offers[$centreid][$age])) {
            return self::$offers[$centreid][$age];
        }
        $usual = self::usual($grade, $age, $centre);
        $years = self::any(self::data()['generic']['years'] ?? []);
        if ($usual === null && $years !== '' && preg_match('/\b(' . $years . ')\b/', self::plain($grade))) {
            $usual = get_string('grade_age', 'local_oksigeniaeguahe', $age);
        }
        return $usual ?? \core_text::substr($grade, 0, 100);
    }

    /**
     * Forgets the names and ages read, when the offer or the ages change.
     */
    public static function reset(): void {
        self::$offers = [];
        self::$own = [];
    }
}
