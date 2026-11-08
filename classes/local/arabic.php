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
 * Names in Arabic script for the labels drawn with GD. GD draws one glyph after another, from left to right, without
 * OpenType: it neither joins Arabic letters nor writes from right to left. So the name is given here the joined form
 * of each letter (the Unicode presentation forms, which the label font maps) and is put in visual order. The pages do
 * not need this: the browser joins the letters itself.
 *
 * The tables come from the font (assets/sticker/font/PlaypenSansArabic-Eguahe.ttf): only forms it has are asked for.
 * A letter without forms there is written on its own, readable, unjoined. Vowel marks are left out: GD cannot place
 * them, and names are written without them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class arabic {
    /** @var array Letter => its final (f), initial (i) and medial (m) forms. */
    const FORMS = [
        "\u{0622}" => ['f' => "\u{FE82}"],
        "\u{0623}" => ['f' => "\u{FE84}"],
        "\u{0624}" => ['f' => "\u{FE86}"],
        "\u{0625}" => ['f' => "\u{FE88}"],
        "\u{0626}" => ['f' => "\u{FE8A}", 'i' => "\u{FE8B}", 'm' => "\u{FE8C}"],
        "\u{0627}" => ['f' => "\u{FE8E}"],
        "\u{0628}" => ['f' => "\u{FE90}", 'i' => "\u{FE91}", 'm' => "\u{FE92}"],
        "\u{0629}" => ['f' => "\u{FE94}"],
        "\u{062A}" => ['f' => "\u{FE96}", 'i' => "\u{FE97}", 'm' => "\u{FE98}"],
        "\u{062B}" => ['f' => "\u{FE9A}", 'i' => "\u{FE9B}", 'm' => "\u{FE9C}"],
        "\u{062C}" => ['f' => "\u{FE9E}", 'i' => "\u{FE9F}", 'm' => "\u{FEA0}"],
        "\u{062D}" => ['f' => "\u{FEA2}", 'i' => "\u{FEA3}", 'm' => "\u{FEA4}"],
        "\u{062E}" => ['f' => "\u{FEA6}", 'i' => "\u{FEA7}", 'm' => "\u{FEA8}"],
        "\u{062F}" => ['f' => "\u{FEAA}"],
        "\u{0630}" => ['f' => "\u{FEAC}"],
        "\u{0631}" => ['f' => "\u{FEAE}"],
        "\u{0632}" => ['f' => "\u{FEB0}"],
        "\u{0633}" => ['f' => "\u{FEB2}", 'i' => "\u{FEB3}", 'm' => "\u{FEB4}"],
        "\u{0634}" => ['f' => "\u{FEB6}", 'i' => "\u{FEB7}", 'm' => "\u{FEB8}"],
        "\u{0635}" => ['f' => "\u{FEBA}", 'i' => "\u{FEBB}", 'm' => "\u{FEBC}"],
        "\u{0636}" => ['f' => "\u{FEBE}", 'i' => "\u{FEBF}", 'm' => "\u{FEC0}"],
        "\u{0637}" => ['f' => "\u{FEC2}", 'i' => "\u{FEC3}", 'm' => "\u{FEC4}"],
        "\u{0638}" => ['f' => "\u{FEC6}", 'i' => "\u{FEC7}", 'm' => "\u{FEC8}"],
        "\u{0639}" => ['f' => "\u{FECA}", 'i' => "\u{FECB}", 'm' => "\u{FECC}"],
        "\u{063A}" => ['f' => "\u{FECE}", 'i' => "\u{FECF}", 'm' => "\u{FED0}"],
        "\u{0641}" => ['f' => "\u{FED2}", 'i' => "\u{FED3}", 'm' => "\u{FED4}"],
        "\u{0642}" => ['f' => "\u{FED6}", 'i' => "\u{FED7}", 'm' => "\u{FED8}"],
        "\u{0643}" => ['f' => "\u{FEDA}", 'i' => "\u{FEDB}", 'm' => "\u{FEDC}"],
        "\u{0644}" => ['f' => "\u{FEDE}", 'i' => "\u{FEDF}", 'm' => "\u{FEE0}"],
        "\u{0645}" => ['f' => "\u{FEE2}", 'i' => "\u{FEE3}", 'm' => "\u{FEE4}"],
        "\u{0646}" => ['f' => "\u{FEE6}", 'i' => "\u{FEE7}", 'm' => "\u{FEE8}"],
        "\u{0647}" => ['f' => "\u{FEEA}", 'i' => "\u{FEEB}", 'm' => "\u{FEEC}"],
        "\u{0648}" => ['f' => "\u{FEEE}"],
        "\u{0649}" => ['f' => "\u{FEF0}"],
        "\u{064A}" => ['f' => "\u{FEF2}", 'i' => "\u{FEF3}", 'm' => "\u{FEF4}"],
        "\u{0671}" => ['f' => "\u{FB51}"],
        "\u{0679}" => ['f' => "\u{FB67}", 'i' => "\u{FB68}", 'm' => "\u{FB69}"],
        "\u{067E}" => ['f' => "\u{FB57}", 'i' => "\u{FB58}", 'm' => "\u{FB59}"],
        "\u{0686}" => ['f' => "\u{FB7B}", 'i' => "\u{FB7C}", 'm' => "\u{FB7D}"],
        "\u{0688}" => ['f' => "\u{FB89}"],
        "\u{0691}" => ['f' => "\u{FB8D}"],
        "\u{0698}" => ['f' => "\u{FB8B}"],
        "\u{06A4}" => ['f' => "\u{FB6B}", 'i' => "\u{FB6C}", 'm' => "\u{FB6D}"],
        "\u{06A9}" => ['f' => "\u{FB8F}", 'i' => "\u{FB90}", 'm' => "\u{FB91}"],
        "\u{06AF}" => ['f' => "\u{FB93}", 'i' => "\u{FB94}", 'm' => "\u{FB95}"],
        "\u{06BA}" => ['f' => "\u{FB9F}"],
        "\u{06BE}" => ['f' => "\u{FBAB}", 'i' => "\u{FBAC}", 'm' => "\u{FBAD}"],
        "\u{06C1}" => ['f' => "\u{FBA7}", 'i' => "\u{FBA8}", 'm' => "\u{FBA9}"],
        "\u{06C6}" => ['f' => "\u{FBDA}"],
        "\u{06CC}" => ['f' => "\u{FBFD}", 'i' => "\u{FBFE}", 'm' => "\u{FBFF}"],
        "\u{06D2}" => ['f' => "\u{FBAF}"],
        "\u{06D3}" => ['f' => "\u{FBB1}"],
    ];

    /** @var array The letter after lam => the lam-alef ligature, isolated (i) and final (f). */
    const LAM_ALEF = [
        "\u{0622}" => ['f' => "\u{FEF6}", 'i' => "\u{FEF5}"],
        "\u{0623}" => ['f' => "\u{FEF8}", 'i' => "\u{FEF7}"],
        "\u{0625}" => ['f' => "\u{FEFA}", 'i' => "\u{FEF9}"],
        "\u{0627}" => ['f' => "\u{FEFC}", 'i' => "\u{FEFB}"],
    ];

    /** @var string Letters joining on both sides. */
    const DUAL = "\u{0626}\u{0628}\u{062A}\u{062B}\u{062C}\u{062D}\u{062E}\u{0633}\u{0634}\u{0635}" .
        "\u{0636}\u{0637}\u{0638}\u{0639}\u{063A}\u{0640}\u{0641}\u{0642}\u{0643}\u{0644}" .
        "\u{0645}\u{0646}\u{0647}\u{0649}\u{064A}\u{0679}\u{067A}\u{067B}\u{067E}\u{067F}" .
        "\u{0680}\u{0683}\u{0684}\u{0686}\u{0687}\u{06A4}\u{06A6}\u{06A9}\u{06AD}\u{06AF}" .
        "\u{06B1}\u{06B3}\u{06BB}\u{06BE}\u{06C1}\u{06CC}\u{06D0}";

    /** @var string Letters joining only to the letter before them. */
    const RIGHT = "\u{0622}\u{0623}\u{0624}\u{0625}\u{0627}\u{0629}\u{062F}\u{0630}\u{0631}\u{0632}" .
        "\u{0648}\u{0671}\u{0688}\u{068C}\u{068D}\u{068E}\u{0691}\u{0698}\u{06BA}\u{06C0}" .
        "\u{06C5}\u{06C6}\u{06C7}\u{06C8}\u{06C9}\u{06CB}\u{06D2}\u{06D3}";

    /**
     * Whether a text has Arabic letters, and so needs the Arabic font and this joining.
     *
     * @param string $text
     * @return bool
     */
    public static function has(string $text): bool {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text);
    }

    /**
     * The text ready for imagettftext() and imagettfbbox(): joined letters, in visual order.
     *
     * @param string $text
     * @return string
     */
    public static function for_gd(string $text): string {
        $chars = mb_str_split(preg_replace('/\p{Mn}/u', '', $text));
        $dual = mb_str_split(self::DUAL);
        $right = mb_str_split(self::RIGHT);
        $joinsnext = fn(?string $c): bool => $c !== null && in_array($c, $dual, true);
        $joinsprev = fn(?string $c): bool => $c !== null && (in_array($c, $dual, true) || in_array($c, $right, true));
        $out = [];
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            $prev = $chars[$i - 1] ?? null;
            $next = $chars[$i + 1] ?? null;
            $prevj = $joinsnext($prev) && $joinsprev($c);
            if ($c === "\u{0644}" && $next !== null && isset(self::LAM_ALEF[$next])) {
                $lig = self::LAM_ALEF[$next];
                $out[] = $lig[$prevj ? 'f' : 'i'] ?? $c . $next;
                $i++;
                continue;
            }
            $nextj = $joinsnext($c) && $joinsprev($next);
            $form = $prevj && $nextj ? 'm' : ($prevj ? 'f' : ($nextj ? 'i' : null));
            $out[] = $form === null ? $c : (self::FORMS[$c][$form] ?? $c);
        }
        return self::visual($out);
    }

    /**
     * Visual order, word by word: the letters of an Arabic word reversed; the words in reverse order when the name
     * starts with an Arabic letter (the first strong letter decides, as in the Unicode bidi algorithm). Latin words
     * and digits keep their own order. A text without Arabic is returned as it was.
     *
     * @param string[] $glyphs
     * @return string
     */
    protected static function visual(array $glyphs): string {
        $text = implode('', $glyphs);
        $isrtl = fn(string $w): bool => (bool) preg_match('/[\x{0600}-\x{08FF}\x{FB50}-\x{FEFF}]/u', $w);
        if (!$isrtl($text)) {
            return $text;
        }
        $words = array_map(
            fn(string $w): string => $isrtl($w) ? implode('', array_reverse(mb_str_split($w))) : $w,
            explode(' ', $text)
        );
        preg_match('/\p{L}/u', $text, $first);
        return implode(' ', $isrtl($first[0] ?? '') ? array_reverse($words) : $words);
    }
}
