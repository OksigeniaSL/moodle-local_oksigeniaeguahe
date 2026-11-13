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
 * What a family asked in what it wrote since the centre last answered, sentence by sentence, so that whoever answers sees
 * whether the answer says it all («Did we answer everything?»). Plain rules over the text, in any language: a sentence
 * with a question mark, or one that asks without it («we would like to know if…»). Never the AI, never a score.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class questions {
    /** @var int At most, the questions shown. */
    const MAX = 6;

    /** @var int At most, the words of a question that tell it is answered. */
    const WORDS = 5;

    /** @var string[] How a request without a question mark opens, without accents and in lower case. */
    const ASKS = ['quisiera saber', 'queria saber', 'quisieramos saber', 'queriamos saber', 'me gustaria saber',
        'nos gustaria saber', 'necesito saber', 'necesitamos saber', 'informacion sobre', 'informacion de', 'saber si',
        'would like to know', 'wanted to know', 'want to know', 'wondering if', 'wondering whether', 'information about',
        'let me know', 'let us know', 'gostaria de saber', 'gostariamos de saber', 'informacao sobre',
        'informacoes sobre', 'saber se', 'أود أن أعرف', 'نود أن نعرف', 'نريد أن نعرف', 'هل يمكن'];

    /** @var string[] Words that say nothing of what is asked, without accents and in lower case. */
    const STOP = ['para', 'como', 'cuando', 'donde', 'tienen', 'tiene', 'puede', 'pueden', 'podria', 'podrian', 'esta',
        'este', 'estos', 'estas', 'esto', 'sobre', 'saber', 'quisiera', 'queria', 'gustaria', 'nuestro', 'nuestra',
        'nuestros', 'nuestras', 'gracias', 'hola', 'buenos', 'buenas', 'dias', 'tardes', 'noches', 'favor', 'porque',
        'pero', 'tambien', 'alguna', 'alguno', 'algun', 'cual', 'cuales', 'cuanto', 'cuanta', 'cuantos', 'cuantas', 'mucho',
        'mucha', 'muchas', 'muchos', 'quiero', 'queremos', 'tenemos', 'seria', 'serian', 'existe', 'hacer', 'todo', 'todos',
        'toda', 'todas', 'desde', 'hasta', 'entre', 'aqui', 'ellos', 'ellas', 'ella', 'ustedes', 'nosotros', 'mismo',
        'misma', 'otra', 'otro', 'otros', 'otras', 'cosa', 'cosas', 'mejor', 'bien', 'solo', 'sola', 'ahora', 'luego',
        'despues', 'antes', 'entonces', 'pues', 'hijo', 'hija', 'hijos', 'hijas', 'nino', 'nina', 'ninos', 'ninas',
        'colegio', 'centro', 'escuela', 'academia', 'saludo', 'saludos', 'quedo', 'quedamos', 'atentamente', 'necesito',
        'necesitamos', 'informacion', 'that', 'this', 'what', 'when', 'where', 'which', 'would', 'could', 'should',
        'there', 'their', 'about', 'know', 'with', 'have', 'does', 'will', 'your', 'from', 'please', 'thanks', 'thank',
        'hello', 'like', 'want', 'information', 'school', 'child', 'children', 'daughter', 'also', 'some', 'many', 'much',
        'more', 'they', 'them', 'were', 'been', 'into', 'just', 'quando', 'onde', 'qual', 'quais', 'quanto', 'quanta',
        'voces', 'vossa', 'vosso', 'tambem', 'gostaria', 'gostariamos', 'obrigado', 'obrigada', 'filho', 'filha',
        'filhos', 'filhas', 'escola', 'pode', 'podem', 'temos', 'muito', 'muita', 'isso', 'isto',
        'essa', 'esse', 'aquela', 'aquele', 'informacao', 'informacoes'];

    /**
     * The questions of what a family wrote since the centre last answered: the messages of the household that came in
     * after the last answer, in order.
     *
     * @param \stdClass[] $contacts The contacts of a household, in order of time.
     * @return array A list of [text, words], at most MAX.
     */
    public static function asked(array $contacts): array {
        $since = [];
        foreach ($contacts as $contact) {
            if ($contact->direction === 'out') {
                $since = [];
            } else if ($contact->direction === 'in' && trim((string) $contact->body) !== '') {
                $since[] = (string) $contact->body;
            }
        }
        return self::of(implode("\n", $since));
    }

    /**
     * The questions of a text, each with the words that tell an answer speaks of it.
     *
     * @param string $text
     * @return array A list of [text, words], at most MAX.
     */
    public static function of(string $text): array {
        $out = [];
        $seen = [];
        // Sentences, each with the mark that closes it: a question mark of any language, a stop or a line; the Spanish
        // opening mark starts one.
        preg_match_all(
            '/¿?[^.!?¿¡؟\n]*[^.!?¿¡؟\n\s][^.!?¿¡؟\n]*([.!?؟]+|\n|$)/u',
            str_replace('¿', "\n¿", $text),
            $parts
        );
        foreach ($parts[0] as $part) {
            $sentence = trim(preg_replace('/\s+/u', ' ', $part));
            if (\core_text::strlen($sentence) < 6) {
                continue;
            }
            $plain = self::normal($sentence);
            $asks = (bool) preg_match('/[?؟][.!?؟]*$/u', $sentence);
            foreach (self::ASKS as $opening) {
                $asks = $asks || strpos($plain, self::normal($opening)) !== false;
            }
            if (!$asks || isset($seen[$plain])) {
                continue;
            }
            $seen[$plain] = true;
            $words = self::words($sentence);
            if (!$words) {
                continue;
            }
            $shown = rtrim($sentence, '.');
            $out[] = ['text' => \core_text::strlen($shown) > 140 ? \core_text::substr($shown, 0, 139) . '…' : $shown,
                'words' => $words];
            if (count($out) === self::MAX) {
                break;
            }
        }
        return $out;
    }

    /**
     * The words of a question that an answer to it would say: without the empty ones, the first five letters of each,
     * as the panel compares them with the draft.
     *
     * @param string $sentence
     * @return string[]
     */
    public static function words(string $sentence): array {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::normal($sentence), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $latin = (bool) preg_match('/^[a-z0-9]+$/', $word);
            if (\core_text::strlen($word) < ($latin ? 4 : 3) || in_array($word, self::STOP, true)) {
                continue;
            }
            $stem = \core_text::substr($word, 0, 5);
            $words[$stem] = $stem;
        }
        return array_slice(array_values($words), 0, self::WORDS);
    }

    /**
     * A text as it is compared: lower case and without accents or vowel marks, as the panel does it in the browser.
     *
     * @param string $text
     * @return string
     */
    public static function normal(string $text): string {
        $text = \core_text::strtolower($text);
        if (class_exists('\Normalizer')) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_D);
        }
        return (string) preg_replace('/\p{Mn}/u', '', $text);
    }
}
