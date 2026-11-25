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
 * The first name of somebody as it is said in a greeting. What people write in a form of an ad is not always that: letters
 * of fancy («ᴄʀɪsᴛɪɴᴀ», «𝑰𝒓𝒆𝒏𝒆»), a user name («DaviRambreu»), a word of the form. The letters become plain ones; what does
 * not look like the name of a person is left out, and the greeting goes without it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class greeting {
    /** @var array Small capitals and other letters of fancy that the normal form of Unicode does not touch. */
    const FANCY = ['ᴀ' => 'a', 'ʙ' => 'b', 'ᴄ' => 'c', 'ᴅ' => 'd', 'ᴇ' => 'e', 'ꜰ' => 'f', 'ɢ' => 'g', 'ʜ' => 'h', 'ɪ' => 'i',
        'ᴊ' => 'j', 'ᴋ' => 'k', 'ʟ' => 'l', 'ᴍ' => 'm', 'ɴ' => 'n', 'ᴏ' => 'o', 'ᴘ' => 'p', 'ꞯ' => 'q', 'ǫ' => 'q', 'ʀ' => 'r',
        'ꜱ' => 's', 'ᴛ' => 't', 'ᴜ' => 'u', 'ᴠ' => 'v', 'ᴡ' => 'w', 'ʏ' => 'y', 'ᴢ' => 'z', 'ɑ' => 'a', 'ɡ' => 'g'];

    /** @var string[] Short forms that are said, though they are not whole words: with their name. */
    const SHORT = ['mª' => 'María', 'ma.' => 'María', 'm.ª' => 'María', 'mª.' => 'María'];

    /**
     * The name to say, or nothing.
     *
     * @param string $raw The first name, as it was written.
     * @return string
     */
    public static function name(string $raw): string {
        $name = self::plain($raw);
        if ($name === '' || preg_match('/[@\d]/u', $name)) {
            return '';
        }
        $words = explode(' ', $name);
        $lower = \core_text::strtolower($words[0]);
        if (isset(self::SHORT[$lower])) {
            $words[0] = self::SHORT[$lower];
        }
        $name = implode(' ', $words);
        if ($name === \core_text::strtoupper($name) || $name === \core_text::strtolower($name)) {
            // All in one case (with the letters it has), as it is written: «Ana», «María José».
            $name = preg_replace_callback('/(?<=\s)(De|Del|La|Las|Los|Y|E|Da|Do|Dos|Das)(?=\s)/u', fn($m) => \core_text::strtolower(
                $m[1]
            ), \core_text::strtotitle($name));
        }
        foreach (explode(' ', $name) as $i => $word) {
            if (!self::looks_like_one($word, $i > 0)) {
                return '';
            }
        }
        return $name;
    }

    /**
     * With plain letters: those of fancy as the ones they stand for, without drawings, and in one space.
     *
     * @param string $raw
     * @return string
     */
    public static function plain(string $raw): string {
        $name = strtr($raw, self::FANCY);
        // «Mª» stays: its small letter is not one of fancy.
        $name = str_replace('ª', "\u{E000}", $name);
        if (class_exists('\Normalizer')) {
            $name = (string) (\Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name);
        }
        $name = str_replace("\u{E000}", 'ª', $name);
        $name = preg_replace('/[^\p{L}\p{M}\p{N}ª\'’.@\-\s]/u', '', $name);
        return trim(preg_replace('/\s+/u', ' ', (string) $name));
    }

    /**
     * Whether a word looks like a name of a person: letters, one capital at its beginning at most, and a vowel (of scripts that
     * write them).
     *
     * @param string $word
     * @param bool $small Whether it may be one that joins two («de», «del», «la»).
     * @return bool
     */
    protected static function looks_like_one(string $word, bool $small): bool {
        $letters = preg_replace('/[^\p{L}\p{M}]/u', '', $word);
        $length = \core_text::strlen($letters);
        // «I’m» is not a name; «D’Angelo» and «O’Brien» are.
        if ($length < 2 || $length > 20 || preg_match('/[.@]|^(?![DOLdol])\p{L}[\'’]/u', $word)) {
            return false;
        }
        if (!preg_match('/\p{Latin}/u', $letters)) {
            // Another script: its letters are enough.
            return true;
        }
        if ($small && preg_match('/^(de|del|da|do|dos|das|la|las|los|el|y|e|i|van|von|bin|al)$/iu', $letters)) {
            return true;
        }
        // A capital in the middle is a user name; «McKenna» and «D’Angelo», what names have them.
        $inside = preg_replace('/^(Mc|Mac|De|Di|Da|La|Le|O|D)(?=\p{Lu})|[\-\'’]\p{Lu}/u', '', $word);
        if (preg_match('/\p{Ll}\p{Lu}/u', (string) $inside)) {
            return false;
        }
        // Without a vowel, or the same letter twice at its end («Fanii», «Saraa», «Roo»): nobody is called so.
        $base = \core_text::strtolower(\core_text::specialtoascii($letters));
        return (bool) preg_match('/[aeiouy]/', $base) && !preg_match('/([aiou])\1$/', $base);
    }
}
