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

    /** @var string[] The languages the story is written in: those of the family pages. */
    const LANGS = ['es', 'en', 'pt', 'pt_br', 'ar'];

    /** @var string[] Each language as the book names it. */
    const CODES = ['es' => 'es', 'en' => 'en', 'pt' => 'pt-PT', 'pt_br' => 'pt-BR', 'ar' => 'ar'];

    /** @var string[] The pages that change when whoever receives the group is a man, and their pictures (Gaudí). */
    const MAESTRO = ['pizarra' => 'p3-pizarra-maestro', 'alfombra' => 'p6-alfombra-maestro', 'casa' => 'p7-casa-maestro'];

    /** @var string[] How the teacher of a course is called in the story. */
    const TEACHERS = ['maestra', 'maestro'];

    /** @var string[] All the words of the book (Gaudí's cadenas.json), as strings «firstday_» plus the key. */
    const STRINGS = ['title', 'doc_title', 'book_label', 'page1', 'page2', 'page3', 'page3_maestro', 'page3_tutor',
        'page3_maestro_tutor', 'page4', 'page5', 'page6', 'page6_maestro', 'page6_tutor', 'page6_maestro_tutor', 'page7',
        'page7_maestro', 'page7_tutor', 'page7_maestro_tutor', 'page8', 'chalk', 'dedication', 'video', 'spoken1', 'spoken2',
        'spoken3', 'spoken3_maestro', 'spoken4', 'spoken7', 'spoken7_maestro', 'alt1', 'alt2', 'alt3', 'alt3_maestro',
        'alt4', 'alt5', 'alt6', 'alt6_maestro', 'alt7', 'alt7_maestro', 'alt8', 'alt_dedication', 'sample', 'theme',
        'book_role', 'nav', 'prev', 'next', 'listen', 'pause', 'again', 'read_with_music', 'music', 'volume', 'volume_value',
        'by', 'drawing_open', 'drawing_label', 'drawing_caption', 'close', 'like', 'cover', 'dedication_label', 'page_n',
        'page_n_of', 'hint', 'listen_again', 'read_again', 'save', 'send', 'link_copied', 'link_here', 'share_title',
        'share_text', 'download_name'];

    /** @var string[] The words of the page around the book. */
    const FRAME = ['theme', 'book_label', 'nav', 'prev', 'listen', 'next', 'music', 'volume', 'volume_value', 'drawing_label',
        'drawing_caption', 'close'];

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

    /** @var string[] What a person of the team writes around the first day: the story, the eve and the day after. */
    const STEPS = ['story', 'eve', 'after'];


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
        $age = $age !== false ? (int) $age : grades::age((string) $offer->name, centres::get($centreid));
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
     * What a person of the team has to write to a family around the first day, today: their story in the days before
     * (when a child has it), the eve, and «How was their first day?» in the three days after. Each one until someone
     * writes to them in its time; never sent by itself.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array|null step (story, eve or after) and children (those who start).
     */
    public static function step(\stdClass $household, \stdClass $centre, int $now): ?array {
        global $DB;
        $kids = array_values(array_filter(
            households::full($household)->children,
            fn($c) => $c->stage === 'enrolled' && empty($c->lefttime)
        ));
        if (!$kids) {
            return null;
        }
        $written = fn(int $since) => $DB->record_exists_select(
            households::CONTACTS,
            'householdid = ? AND direction = ? AND timecreated >= ?',
            [$household->id, 'out', $since]
        );
        $enrolled = max(array_map(fn($c) => (int) $c->stagetime, $kids));
        $first = (int) ($centre->firstday ?? 0);
        $stories = self::children($household, $centre, (string) $household->lang, $now);
        $step = null;
        if ($first && $now >= $first + DAYSECS && $now < $first + 4 * DAYSECS) {
            $step = $written($first + DAYSECS) ? null : 'after';
        } else if ($first && $now >= $first - DAYSECS && $now < $first) {
            $step = $written($first - DAYSECS) ? null : 'eve';
        } else if ($stories && (!$first || $now < $first - DAYSECS)) {
            $step = $written(max($enrolled, $first ? $first - self::BEFORE : 0)) ? null : 'story';
        }
        return $step ? ['step' => $step, 'children' => $kids, 'stories' => array_values($stories), 'first' => $first] : null;
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
        if (!hash_equals(self::signature($householdid, $childid), $signature)) {
            return null;
        }
        return self::from_ids($householdid, $childid);
    }

    /**
     * The family, the child and the centre of a link already checked.
     *
     * @param int $householdid
     * @param int $childid
     * @return array|null [household, child, centre]
     */
    public static function from_ids(int $householdid, int $childid): ?array {
        global $DB;
        $household = $DB->get_record(households::T, ['id' => $householdid]);
        $centre = $household ? centres::get((int) $household->centreid) : null;
        $child = $household ? $DB->get_record(households::CHILDREN, ['id' => $childid, 'householdid' => $householdid]) : null;
        return $household && $centre && $child ? [$household, $child, $centre] : null;
    }

    /**
     * The name of a centre inside a sentence when the centre did not write it: in Spanish and Portuguese, with the article
     * of its first word when it is one of the usual ones («el Colegio Los Arces», «a Escola do Sol»); in English and
     * Arabic, and else, as it is.
     *
     * @param string $name
     * @param string $lang
     * @return string
     */
    public static function phrase(string $name, string $lang): string {
        $first = \core_text::strtolower((string) strtok($name, ' '));
        $articles = [
            'es' => ['colegio' => 'el', 'instituto' => 'el', 'centro' => 'el', 'liceo' => 'el', 'jardín' => 'el',
                'escuela' => 'la', 'academia' => 'la', 'guardería' => 'la', 'casa' => 'la', 'ikastola' => 'la'],
            'pt' => ['colegio' => 'o', 'colégio' => 'o', 'instituto' => 'o', 'centro' => 'o', 'jardim' => 'o', 'liceu' => 'o',
                'externato' => 'o', 'escola' => 'a', 'escuela' => 'a', 'academia' => 'a', 'creche' => 'a', 'casa' => 'a'],
        ];
        $articles['pt_br'] = $articles['pt'];
        return isset($articles[$lang][$first]) ? $articles[$lang][$first] . ' ' . $name : $name;
    }

    /**
     * The other names on the pegs of page 4: from the language of the book, never the child's own.
     *
     * @param string $child
     * @param string $lang
     * @return string[]
     */
    protected static function pegs(string $child, string $lang): array {
        // Arabic lists them with its own comma («،»).
        $names = array_map('trim', preg_split('/[,،]/u', publiclang::str('story_pegs', $lang)));
        $own = \core_text::strtolower($child);
        $names = array_values(array_filter($names, fn($n) => $n !== '' && \core_text::strtolower($n) !== $own));
        return array_slice($names, 0, 5);
    }

    /**
     * How the book calls whoever receives the child's group and their name: from the course of the child, its teacher
     * («maestra» or «maestro», as the centre set it) and the person who carries it, if families see them.
     *
     * @param \stdClass $centre
     * @param \stdClass $child
     * @return array [teacher, tutor name or '']
     */
    public static function teacher(\stdClass $centre, \stdClass $child): array {
        global $DB;
        $offer = $DB->get_record(centres::OFFERS, ['id' => $child->offerid, 'centreid' => $centre->id]);
        $teacher = $offer && in_array($offer->teacher ?? '', self::TEACHERS, true) ? $offer->teacher : 'maestra';
        $host = $offer && (int) $offer->handover ? (centres::hosts((int) $centre->id, [(int) $offer->handover])[0] ?? null) : null;
        return [$teacher, $host ? $host->first : ''];
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
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'es';
        $code = self::CODES[$lang];
        $name = familypage::label_name($child) ?: (string) $child->firstname;
        $centrename = format_string($centre->name);
        $own = trim((string) ($centre->namephrase ?? ''));
        // The phrase the centre wrote is in its own language; in another, Eguahe says the name as that language does.
        $phrase = $own !== '' && $lang === ($centre->lang ?? '') ? format_string($own) : self::phrase($centrename, $lang);
        $words = [];
        foreach (self::STRINGS as $key) {
            $words[$key] = publiclang::str('firstday_' . $key, $lang);
        }
        $fill = fn(string $text) => str_replace(['{nombre}', '{n}'], [$name, '25'], $text);
        $base = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/cuento/';
        $dir = $CFG->dirroot . '/local/oksigeniaeguahe/pub/cuento/';
        $read = fn(string $file) => json_decode((string) file_get_contents($dir . $file), true);
        $geometry = $read('paginas.json');
        $pegs = self::pegs($name, $lang);
        $pages = [];
        foreach (self::PAGES as $page) {
            $extra = array_diff_key($geometry[$page['id']] ?? [], ['alt' => 1]);
            foreach ($extra['names'] ?? [] as $i => $spot) {
                if (isset($spot['text'])) {
                    // The other pegs: names of the language of the book, never the child's own.
                    $extra['names'][$i]['text'] = $pegs[$i - 1] ?? $spot['text'];
                }
            }
            if (isset(self::MAESTRO[$page['id']])) {
                $extra['maestro'] = ['img' => self::MAESTRO[$page['id']]];
            }
            $pages[] = $page + $extra;
        }
        // The readings of the language that has a voice: each with its pages, its timings and the whole story to keep.
        $voices = [];
        foreach (glob($dir . 'voz/' . $code . '/*/habla.json') ?: [] as $speech) {
            $reading = basename(dirname($speech));
            $level = $read('voz/' . $code . '/nivel.json');
            $whole = 'voz/' . $code . '/' . $reading . '/cuento-con-cancion.mp3';
            $voices[$reading] = ['audio' => $base . 'voz/' . $code . '/' . $reading, 'speech' => $read('voz/' . $code . '/' .
                $reading . '/habla.json'), 'download' => is_file($dir . $whole) ? ['href' => $base . $whole] : null,
                'lufs' => (float) ($level['voz_lufs'] ?? -20.91)];
        }
        // The child's name in the voice, when the bank has its readings (namevoices, from the voice service): the book reads
        // the one of its teacher and, in Arabic, the one for the child the family says; without them, the one without.
        $level = $read('voz/' . $code . '/nivel.json');
        foreach (namevoice_service::READINGS[$code] ?? [] as $reading) {
            $named = namevoices::find($code, $reading, $name);
            if ($named) {
                $voices[$reading] = $named + ['lufs' => (float) ($level['voz_lufs'] ?? -20.91)];
            }
        }
        // The song under the reading, at its level against the voice (Gaudí: the bed 11 dB under it, the door 8).
        $lufs = (float) ($read('voz/es/nivel.json')['voz_lufs'] ?? -20.91);
        $loop = $read('musica/musica-bucle.json');
        $music = ['bedDb' => round($lufs - 11 + 18, 2), 'duckDb' => -4.0, 'doorDb' => round($lufs - 8 + 18, 2),
            'voiceLufs' => $lufs, 'pre' => (float) $loop['pre'], 'loopSeconds' => (float) $loop['loop'],
            'loop' => $base . 'musica/musica-bucle.mp3', 'door' => $base . 'musica/puerta.mp3'];
        // Their drawing on the wall of the class: it draws itself when its strokes were kept, else it hangs still.
        $drawing = null;
        $file = drawings::file((int) $child->id);
        $strokes = drawings::strokes_file((int) $child->id);
        if ($file && !empty($links['drawing'])) {
            $info = $file->get_imageinfo() ?: ['width' => 600, 'height' => 800];
            $drawing = ['src' => $links['drawing'], 'w' => (int) $info['width'], 'h' => (int) $info['height'],
                'strokes' => $strokes ? json_decode($strokes->get_content(), true) : null];
        }
        [$teacher, $tutor] = self::teacher($centre, $child);
        $data = ['child' => $name, 'centre' => $centrename, 'centrePhrases' => [$code => $phrase], 'pages' => $pages,
            'strings' => [$code => $words], 'lang' => $code, 'teacher' => $teacher, 'childSex' => 'm',
            'tutors' => ['maestra' => $tutor, 'maestro' => $tutor], 'voices' => [$code => $voices], 'music' => $music,
            'drawing' => $drawing, 'classDrawing' => $drawing ? null : $base . 'clase-garabato.jpg', 'book' => self::BOOK,
            'base' => $base, 'share' => $links['share'], 'measure' => $links['measure'] ?? null, 'childKey' => (int) $child->id,
            'video' => self::video_data($centre, $words, $name, $lang, (string) $links['share'])];
        $frame = [];
        foreach (self::FRAME as $key) {
            $frame[$key] = $fill($words[$key]);
        }
        $colour = $centre->colour ?: '#2f5d62';
        $logo = centres::logo((int) $centre->id);
        // The drawing draws itself with its strokes; without them, no script for it. In Arabic, the voice is asked first.
        // The story as a video to keep, made in the page when asked (video.js, with the sheet of ../dibujo-video.js).
        $scripts = [$base . 'voz.js', $base . 'cuento.js', $base . 'medir.js', $base . '../dibujo-video.js', $base . 'video.js'];
        if ($drawing && $drawing['strokes']) {
            array_unshift($scripts, $CFG->wwwroot . '/local/oksigeniaeguahe/pub/dibujo-vivo.js');
        }
        $sexes = array_filter(array_keys($voices), fn($r) => preg_match('/-[mf]$/', $r));
        return [
            'lang' => str_replace('_', '-', $lang === 'pt_br' ? 'pt-BR' : $lang),
            'dir' => lists::dir($lang),
            'title' => $fill($words['doc_title']),
            'css' => $base . 'cuento.css',
            'accent' => publicpage::centre_vars($colour, false)['--c-acento'] ?? $colour,
            'accentdark' => publicpage::centre_vars($colour, true)['--c-acento-vivo'] ?? $colour,
            'logo' => $logo ? $logo->out(false) : '',
            'initials' => publicpage::initials($centrename),
            'centre' => $centrename,
            'credit' => publicpage::credit($lang) ?: false,
            // Inside a script element: nothing in it may close it.
            'data' => str_replace('</', '<\/', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)),
            'scripts' => $scripts,
            'words' => $frame,
            // Where the voice says «you» as a boy or as a girl (Arabic), the family chooses once how it is read to them.
            'choosevoice' => $sexes ? ['title' => publiclang::str('story_voice_title', $lang),
                'text' => publiclang::str('story_voice_text', $lang), 'boy' => publiclang::str('story_voice_boy', $lang),
                'girl' => publiclang::str('story_voice_girl', $lang)] : false,
        ];
    }

    /**
     * What the video of the story needs that the book has not: the gift and the colours of the centre, the seal, the
     * name of the file and the words of the sheet.
     *
     * @param \stdClass $centre
     * @param array $words The words of the book in its language.
     * @param string $name The name of the child.
     * @param string $lang
     * @param string $share The link of the story to send.
     * @return array
     */
    protected static function video_data(\stdClass $centre, array $words, string $name, string $lang, string $share): array {
        global $CFG;
        $colour = $centre->colour ?: '#2f5d62';
        $vars = publicpage::centre_vars($colour, false);
        $s = fn(string $id) => publiclang::str('share_video_' . $id, $lang);
        return [
            'title' => str_replace('{nombre}', $name, $words['doc_title']),
            'text' => publiclang::str('share_story_text', $lang, $name),
            'url' => $share,
            'file' => clean_filename(str_replace('{nombre}', $name, preg_replace(
                '/\.mp3$/',
                '.mp4',
                publiclang::str('firstday_download_name', $lang)
            ))),
            'gift' => share::gift($centre, $lang),
            'credit' => publiclang::str('credit_name', $lang),
            'mark' => $CFG->wwwroot . '/local/oksigeniaeguahe/pix/icon.svg',
            'accent' => $vars['--c-acento'],
            'accentText' => $vars['--c-acento-texto'],
            'words' => ['preparing' => $s('preparing'), 'send' => $s('send'), 'save' => $s('save'), 'close' => $s('close'),
                'failed' => $s('failed'), 'instead' => $s('audio'), 'saveinstead' => $s('saveaudio')],
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
