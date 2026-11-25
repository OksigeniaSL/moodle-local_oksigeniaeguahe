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
 * How a family heard of the centre, from what it wrote in a form that is not of Eguahe. With nothing measured it decides its
 * channel in Numbers, so what is not clear is «other» and never a guess.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class heard {
    /**
     * How they heard of the centre, from what they wrote: the answers Eguahe itself offers, in every language of the families;
     * then the words of data/heard-words.json.
     *
     * @param string $value
     * @return string One of lists::HEARD_FROM.
     */
    public static function read(string $value): string {
        $plain = trim(recognise::plain($value));
        if ($plain === '') {
            return 'other';
        }
        foreach (self::labels() as $label => $heard) {
            // An answer may come with its detail after («Redes sociales: Instagram»).
            if ($plain === (string) $label || ($heard !== 'other' && strpos($plain, $label . ' ') === 0)) {
                return $heard;
            }
        }
        foreach (self::words() as [$expression, $heard]) {
            if (preg_match($expression, $plain)) {
                return $heard;
            }
        }
        return 'other';
    }

    /**
     * The answers Eguahe offers, in plain letters, in every language: the longest first.
     *
     * @return array label => one of lists::HEARD_FROM
     */
    protected static function labels(): array {
        static $labels = null;
        if ($labels === null) {
            $labels = [];
            foreach (lists::LANGS as $lang) {
                foreach (lists::HEARD_FROM as $heard) {
                    $said = get_string_manager()->get_string('heard_' . $heard, 'local_oksigeniaeguahe', null, $lang);
                    $label = trim(recognise::plain($said));
                    if ($label !== '') {
                        $labels[$label] = $labels[$label] ?? $heard;
                    }
                }
            }
            uksort($labels, fn($a, $b) => [strlen((string) $b), (string) $a] <=> [strlen((string) $a), (string) $b]);
        }
        return $labels;
    }

    /**
     * The words that say it, as expressions on a text in plain letters.
     *
     * @return array[] expression and one of lists::HEARD_FROM
     */
    protected static function words(): array {
        static $words = null;
        if ($words === null) {
            $words = [];
            $data = json_decode((string) file_get_contents(__DIR__ . '/../../data/heard-words.json'), true);
            foreach (is_array($data) ? ($data['words'] ?? []) : [] as $one) {
                if (in_array($one['heard'] ?? '', lists::HEARD_FROM, true)) {
                    $words[] = ['~' . str_replace('~', '\~', (string) $one['re']) . '~u', (string) $one['heard']];
                }
            }
        }
        return $words;
    }
}
