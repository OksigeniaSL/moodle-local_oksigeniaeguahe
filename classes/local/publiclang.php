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
 * The language of a family, in the pages and messages it gets.
 *
 * A family reads in its language even when the site has not installed that language pack: Moodle only reads the
 * strings a plugin brings for the packs it has, so the plugin reads its own strings for the others.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publiclang {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var array Strings read from the plugin, by language. */
    protected static $own = [];

    /**
     * The language of the page: the one chosen, the one of the browser if it is offered, or the one of the centre.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function choose(\stdClass $centre): string {
        global $SESSION;
        $asked = optional_param('lang', '', PARAM_ALPHANUMEXT);
        if (in_array($asked, lists::LANGS, true)) {
            $SESSION->local_oksigeniaeguahe_lang = $asked;
            return $asked;
        }
        if (in_array($SESSION->local_oksigeniaeguahe_lang ?? '', lists::LANGS, true)) {
            return $SESSION->local_oksigeniaeguahe_lang;
        }
        return self::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? $centre->lang;
    }

    /**
     * The first offered language in an Accept-Language header.
     *
     * @param string $header
     * @return string|null
     */
    public static function from_header(string $header): ?string {
        $ranked = [];
        foreach (explode(',', strtolower($header)) as $i => $part) {
            $bits = explode(';q=', trim($part));
            $tag = trim($bits[0]);
            if ($tag !== '') {
                $ranked[] = [(float) ($bits[1] ?? 1), -$i, $tag];
            }
        }
        rsort($ranked);
        foreach ($ranked as [, , $tag]) {
            $primary = substr($tag, 0, 2);
            if ($tag === 'pt-br') {
                return 'pt_br';
            }
            if ($primary === 'pt') {
                return 'pt';
            }
            if ($primary === 'es' || $primary === 'en') {
                return $primary;
            }
        }
        return null;
    }

    /**
     * A string of the plugin in a language.
     *
     * @param string $id
     * @param string $lang
     * @param mixed $a
     * @return string
     */
    public static function str(string $id, string $lang, $a = null): string {
        $manager = get_string_manager();
        if ($a !== null && lists::dir($lang) === 'rtl') {
            // Written from right to left: each thing put into the sentence (a name, a school year, a phone) keeps its
            // own direction, so «2027-28» or «Colegio Los Almendros (Getafe)» do not turn round inside it.
            $a = self::isolate($a);
        }
        if ($lang === 'en' || $manager->translation_exists($lang, false)) {
            return $manager->get_string($id, self::C, $a, $lang);
        }
        $strings = self::own($lang);
        if (!array_key_exists($id, $strings)) {
            return $manager->get_string($id, self::C, $a, 'en');
        }
        return self::fill($strings[$id], $a);
    }

    /**
     * The strings the plugin brings for a language, over the English ones (and over the parent's: pt_br over pt).
     *
     * @param string $lang
     * @return array
     */
    protected static function own(string $lang): array {
        if (!isset(self::$own[$lang])) {
            $merged = [];
            foreach ($lang === 'pt_br' ? ['pt', 'pt_br'] : [$lang] as $each) {
                $file = __DIR__ . '/../../lang/' . $each . '/local_oksigeniaeguahe.php';
                if (in_array($each, lists::LANGS, true) && is_readable($file)) {
                    $string = [];
                    include($file);
                    $merged = $string + $merged;
                }
            }
            self::$own[$lang] = $merged;
        }
        return self::$own[$lang];
    }

    /**
     * A string with a number: one, or many; in Arabic also two (the dual) and eleven or more, each with its own form.
     *
     * @param string $one The string for one.
     * @param string $many The string for many, with the number in {$a}; Arabic adds {$many}_two and {$many}_eleven.
     * @param int $n
     * @param string $lang
     * @return string
     */
    public static function count(string $one, string $many, int $n, string $lang): string {
        if ($n === 1) {
            return self::str($one, $lang);
        }
        if ($lang === 'ar' && ($n === 2 || $n >= 11)) {
            return self::str($many . ($n === 2 ? '_two' : '_eleven'), $lang, $n);
        }
        return self::str($many, $lang, $n);
    }

    /**
     * Each thing put into a sentence, between the marks that isolate its direction (FSI and PDI).
     *
     * @param mixed $a
     * @return mixed
     */
    protected static function isolate($a) {
        $wrap = fn($value) => is_scalar($value) && (string) $value !== '' ? "\u{2068}" . $value . "\u{2069}" : $value;
        if (is_scalar($a)) {
            return $wrap($a);
        }
        $out = new \stdClass();
        foreach ((array) $a as $key => $value) {
            $out->$key = $wrap($value);
        }
        return $out;
    }

    /**
     * Puts the values in a string, as Moodle does: {$a} and {$a->name}.
     *
     * @param string $text
     * @param mixed $a
     * @return string
     */
    protected static function fill(string $text, $a): string {
        if ($a === null) {
            return $text;
        }
        if (is_scalar($a)) {
            return str_replace('{$a}', (string) $a, $text);
        }
        foreach ((array) $a as $key => $value) {
            if (is_scalar($value)) {
                $text = str_replace('{$a->' . $key . '}', (string) $value, $text);
            }
        }
        return $text;
    }

    /**
     * The links to the other languages, for the corner of the page.
     *
     * @param string $lang The one of the page.
     * @param \moodle_url $url The page.
     * @return array For the template: name, url, current.
     */
    public static function switcher(string $lang, \moodle_url $url): array {
        $names = ['es' => 'Español', 'en' => 'English', 'pt_br' => 'Português', 'pt' => 'Português'];
        // One Portuguese in the corner: the one of the browser, Brazil's when it says neither.
        $portuguese = in_array($lang, ['pt', 'pt_br'], true) ? $lang :
            (self::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) === 'pt' ? 'pt' : 'pt_br');
        $list = [];
        foreach (['es', 'en', $portuguese] as $code) {
            $link = new \moodle_url($url, ['lang' => $code]);
            $list[] = ['name' => $names[$code], 'url' => $link->out(false), 'current' => $code === $lang,
                'code' => str_replace('_', '-', $code)];
        }
        return $list;
    }
}
