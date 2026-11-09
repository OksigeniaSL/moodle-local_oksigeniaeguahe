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
 * «Your first day», the welcome story of a child who starts at the centre (Gaudí): a picture book to read at home in the
 * days before, with their name on the pictures and the drawing they made at the visit on the wall of their class. So
 * the child arrives wanting to go, and the family does not feel «you enrolled, now we forget you».
 *
 * A child has it once enrolled in a course of the centre that gives it, from three weeks before the first day the
 * centre wrote (or at once without one) until two months after. The family reads it from its page; to send it to
 * someone (the grandparents), a link of its own opens the story and nothing else.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class story {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string The book, as its reactions name it. */
    const BOOK = 'primer-dia';

    /** @var string[] The languages the story is written in, for now; the rest come with their words. */
    const LANGS = ['es'];

    /** @var string[] The languages with a voice for the story. */
    const VOICES = ['es'];

    /** @var int From how long before the first day the story is there. */
    const BEFORE = 21 * DAYSECS;

    /** @var int Until how long after the first day it stays. */
    const AFTER = 60 * DAYSECS;

    /** @var int The age up to which a course gives the story when nobody chose: the first years at school. */
    const AGE = 5;

    /** @var array[] The pages: the cover, the eight of the story and the dedication; geometry in pub/cuento/paginas.json. */
    const PAGES = [
        ['id' => 'portada', 'n' => 0, 'kind' => 'cover', 'audio' => '00-portada'],
        ['id' => 'mochila', 'n' => 1, 'img' => 'p1-mochila', 'audio' => '01-mochila'],
        ['id' => 'botella', 'n' => 2, 'img' => 'p2-botella', 'audio' => '02-botella'],
        ['id' => 'pizarra', 'n' => 3, 'img' => 'p3-pizarra', 'audio' => '03-pizarra'],
        ['id' => 'percha', 'n' => 4, 'img' => 'p4-percha', 'audio' => '04-percha'],
        ['id' => 'amigo', 'n' => 5, 'img' => 'p5-amigo', 'audio' => '05-amigo'],
        ['id' => 'alfombra', 'n' => 6, 'img' => 'p6-alfombra', 'audio' => '06-alfombra'],
        ['id' => 'casa', 'n' => 7, 'img' => 'p7-casa', 'audio' => '07-casa'],
        ['id' => 'vuelta', 'n' => 8, 'img' => 'p8-vuelta', 'audio' => '08-vuelta'],
        ['id' => 'dedicatoria', 'n' => 9, 'kind' => 'dedication', 'img' => 'dedicatoria-manos'],
    ];

    /** @var string[] The words of the controls and the gestures, as the script of the book reads them. */
    const WORDS = ['cover', 'dedication', 'page', 'pageof', 'liked', 'title', 'titlechild', 'enlarge', 'save', 'again', 'send',
        'percent', 'pause', 'otravez', 'listen', 'copied', 'thelink', 'sharetext'];

    /** @var string[] The words of the page around the book. */
    const FRAME = ['theme', 'titlechild', 'controls', 'previous', 'listen', 'next', 'music', 'volume', 'drawingof', 'drawnby',
        'close'];

    /**
     * Whether a course gives the story: as the centre chose, or, before it chose, the first years at school.
     *
     * @param \stdClass $offer
     * @param int $centreid
     * @return bool
     */
    public static function offer_gives(\stdClass $offer, int $centreid): bool {
        global $DB;
        if (isset($offer->story) && $offer->story !== null && (int) $offer->story >= 0) {
            return (bool) $offer->story;
        }
        $age = $DB->get_field(forecast::AGES, 'age', ['centreid' => $centreid, 'grade' => $offer->name]);
        $age = $age !== false ? (int) $age : grades::age((string) $offer->name);
        return $age !== null && $age <= self::AGE;
    }

    /**
     * The children of a household who have their story now: enrolled, not gone, in a course that gives it, in the time
     * around the first day; and only in a language the story is written in.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param int $now
     * @return \stdClass[] Children.
     */
    public static function children(\stdClass $household, \stdClass $centre, string $lang, int $now): array {
        global $DB;
        if (!in_array($lang, self::LANGS, true)) {
            return [];
        }
        $first = (int) ($centre->firstday ?? 0);
        if ($first && ($now < $first - self::BEFORE || $now > $first + self::AFTER)) {
            return [];
        }
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $out = [];
        foreach (households::full($household)->children as $child) {
            $offer = $offers[$child->offerid] ?? null;
            if ($child->stage === 'enrolled' && empty($child->lefttime) && $offer && self::offer_gives($offer, (int) $centre->id)) {
                $out[$child->id] = $child;
            }
        }
        return $out;
    }

    /**
     * The key that signs the links to send the story: one for the site, made the first time it is needed.
     *
     * @return string
     */
    protected static function key(): string {
        $key = (string) get_config(self::C, 'storykey');
        if ($key === '') {
            $key = random_string(48);
            set_config('storykey', $key, self::C);
        }
        return $key;
    }

    /**
     * The signature of the story of a child.
     *
     * @param int $householdid
     * @param int $childid
     * @return string
     */
    public static function signature(int $householdid, int $childid): string {
        return substr(hash_hmac('sha256', 'story:' . $householdid . ':' . $childid, self::key()), 0, 32);
    }

    /**
     * The link to send the story to someone: it opens the story of this child, and nothing else of the family.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param string $lang
     * @return \moodle_url
     */
    public static function share_url(\stdClass $household, \stdClass $child, string $lang): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/cuento.php', ['h' => $household->id, 'n' => $child->id,
            's' => self::signature((int) $household->id, (int) $child->id), 'lang' => $lang]);
    }

    /**
     * The family and the child of a link to send the story, if the link is good and the child still has it.
     *
     * @param int $householdid
     * @param int $childid
     * @param string $signature
     * @return array|null [household, child, centre]
     */
    public static function from_link(int $householdid, int $childid, string $signature): ?array {
        global $DB;
        if (!hash_equals(self::signature($householdid, $childid), $signature)) {
            return null;
        }
        $household = $DB->get_record(households::T, ['id' => $householdid]);
        $centre = $household ? centres::get((int) $household->centreid) : null;
        $child = $household ? $DB->get_record(households::CHILDREN, ['id' => $childid, 'householdid' => $householdid]) : null;
        return $household && $centre && $child ? [$household, $child, $centre] : null;
    }

    /**
     * The name of a centre inside a sentence when the centre did not write it: in Spanish, with the article of its first
     * word when it is one of the usual ones («el Colegio Los Arces», «la Escuela Infantil El Sol»); else as it is.
     *
     * @param string $name
     * @param string $lang
     * @return string
     */
    public static function phrase(string $name, string $lang): string {
        if ($lang !== 'es') {
            return $name;
        }
        $first = \core_text::strtolower((string) strtok($name, ' '));
        $articles = ['colegio' => 'el', 'instituto' => 'el', 'centro' => 'el', 'liceo' => 'el', 'jardín' => 'el',
            'escuela' => 'la', 'academia' => 'la', 'guardería' => 'la', 'casa' => 'la', 'ikastola' => 'la'];
        return isset($articles[$first]) ? $articles[$first] . ' ' . $name : $name;
    }

    /**
     * The words of the story in a language: each page its lines, with {nombre} and {centro} as the book fills them.
     *
     * @param string $id
     * @param string $lang
     * @return string[]
     */
    protected static function lines(string $id, string $lang): array {
        return array_values(array_filter(explode("\n", publiclang::str('story_' . $id, $lang)), fn($l) => trim($l) !== ''));
    }

    /**
     * The other names on the pegs of page 4: from the language of the book, never the child's own.
     *
     * @param string $child
     * @param string $lang
     * @return string[]
     */
    protected static function pegs(string $child, string $lang): array {
        $names = array_map('trim', explode(',', publiclang::str('story_pegs', $lang)));
        $own = \core_text::strtolower($child);
        $names = array_values(array_filter($names, fn($n) => $n !== '' && \core_text::strtolower($n) !== $own));
        return array_slice($names, 0, 5);
    }

    /**
     * Everything the book needs, for the page around it and for its script.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param \stdClass $centre
     * @param string $lang
     * @param array $links drawing (its address), share (to send it) and measure ([url, sesskey] or null).
     * @return array The context of public_story.
     */
    public static function context(\stdClass $household, \stdClass $child, \stdClass $centre, string $lang, array $links): array {
        global $CFG;
        $name = familypage::label_name($child) ?: (string) $child->firstname;
        $centrename = format_string($centre->name);
        $phrase = trim((string) ($centre->namephrase ?? '')) !== '' ? format_string($centre->namephrase) :
            self::phrase($centrename, $lang);
        $a = (object) ['name' => $name, 'centre' => $centrename];
        $str = fn(string $id) => publiclang::str('story_ui_' . $id, $lang, $a);
        $base = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/cuento/';
        $dir = $CFG->dirroot . '/local/oksigeniaeguahe/pub/cuento/';
        $geometry = json_decode((string) file_get_contents($dir . 'paginas.json'), true);
        $pegs = self::pegs($name, $lang);
        $pages = [];
        foreach (self::PAGES as $page) {
            $extra = $geometry[$page['id']] ?? [];
            if (isset($extra['names'])) {
                foreach ($extra['names'] as $i => $spot) {
                    if (isset($spot['text'])) {
                        // The other pegs: names of the language of the book.
                        $extra['names'][$i]['text'] = $pegs[$i - 1] ?? $spot['text'];
                    }
                    if (isset($spot['pre'])) {
                        $extra['names'][$i]['pre'] = publiclang::str('story_board_pre', $lang);
                        $extra['names'][$i]['post'] = publiclang::str('story_board_post', $lang);
                    }
                }
            }
            $extra['alt'] = publiclang::str('story_alt_' . $page['id'], $lang);
            if (($page['kind'] ?? '') === 'dedication') {
                $extra['lines'] = self::lines('dedicatoria', $lang);
            } else if (empty($page['kind'])) {
                $extra['text'] = self::lines($page['id'], $lang);
            }
            $pages[] = $page + $extra;
        }
        $read = fn(string $file) => json_decode((string) file_get_contents($CFG->dirroot .
            '/local/oksigeniaeguahe/pub/cuento/' . $file), true);
        $voices = [];
        if (in_array($lang, self::VOICES, true)) {
            $voices['sin-nombre'] = ['audio' => $base . 'voz/' . $lang, 'speech' => $read('voz/' . $lang . '/habla.json'),
                'download' => ['href' => $base . 'voz/' . $lang . '/cuento-con-cancion.mp3',
                'name' => clean_filename($str('filename')) . '.mp3']];
        }
        // The song under the reading, at its level against the voice (Gaudí: the bed 11 dB under it, the door 8).
        $levels = $read('musica/nivel.json');
        $loop = $read('musica/musica-bucle.json');
        $lufs = (float) ($levels['voz_lufs'] ?? -18);
        $music = ['bedDb' => round($lufs - 11 + 18, 2), 'duckDb' => -4.0, 'doorDb' => round($lufs - 8 + 18, 2),
            'pre' => (float) $loop['pre'], 'loopSeconds' => (float) $loop['loop'], 'loop' => $base . 'musica/musica-bucle.mp3',
            'door' => $base . 'musica/puerta.mp3'];
        // Their drawing on the wall of the class: it draws itself when its strokes were kept, else it hangs still.
        $drawing = null;
        $file = drawings::file((int) $child->id);
        $strokes = drawings::strokes_file((int) $child->id);
        if ($file && !empty($links['drawing'])) {
            $info = $file->get_imageinfo() ?: ['width' => 600, 'height' => 800];
            $drawing = ['src' => $links['drawing'], 'w' => (int) $info['width'], 'h' => (int) $info['height'],
                'strokes' => $strokes ? json_decode($strokes->get_content(), true) : null];
        }
        $words = [];
        foreach (self::WORDS as $id) {
            $words[$id] = $str($id);
        }
        $data = ['child' => $name, 'centre' => $centrename, 'centrePhrase' => $phrase, 'pages' => $pages, 'voices' => $voices,
            'music' => $music, 'drawing' => $drawing, 'classDrawing' => $drawing ? null : $base . 'clase-garabato.jpg',
            'book' => self::BOOK, 'base' => $base, 'share' => $links['share'], 'str' => $words,
            'measure' => $links['measure'] ?? null];
        $frame = [];
        foreach (self::FRAME as $id) {
            $frame[$id] = $str($id);
        }
        $frame['volumestart'] = publiclang::str('story_ui_percent', $lang, null);
        $frame['volumestart'] = str_replace('{n}', '25', $frame['volumestart']);
        $colour = $centre->colour ?: '#2f5d62';
        $logo = centres::logo((int) $centre->id);
        // The drawing draws itself with its strokes; without them, no script for it.
        $scripts = [$base . 'cuento.js', $base . 'medir.js'];
        if ($drawing && $drawing['strokes']) {
            array_unshift($scripts, $CFG->wwwroot . '/local/oksigeniaeguahe/pub/dibujo-vivo.js');
        }
        $credit = publicpage::credit($lang);
        return [
            'lang' => str_replace('_', '-', $lang === 'pt_br' ? 'pt-BR' : $lang),
            'dir' => lists::dir($lang),
            'title' => $str('titlechild'),
            'css' => $base . 'cuento.css',
            'accent' => publicpage::centre_vars($colour, false)['--c-acento'] ?? $colour,
            'accentdark' => publicpage::centre_vars($colour, true)['--c-acento-vivo'] ?? $colour,
            'logo' => $logo ? $logo->out(false) : '',
            'initials' => publicpage::initials($centrename),
            'centre' => $centrename,
            'credit' => $credit ?: false,
            // Inside a script element: nothing in it may close it.
            'data' => str_replace('</', '<\/', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)),
            'scripts' => $scripts,
            'words' => $frame,
        ];
    }

    /**
     * Prints the book: a page of its own, not Moodle's, and stops.
     *
     * @param array $context
     */
    public static function output(array $context): void {
        global $OUTPUT;
        publicpage::headers();
        @header('Content-Type: text/html; charset=utf-8');
        echo $OUTPUT->render_from_template('local_oksigeniaeguahe/public_story', $context);
        die();
    }
}
