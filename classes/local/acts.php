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
 * How an act is told: the word for what it is (a talk, a workshop, a party, or the one the centre writes), what it is about,
 * who gives it, for which courses, its own picture and whether anybody may come without signing up.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acts {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The words with a translation, for an act that is neither an open day nor a visit. */
    const WORDS = ['talk', 'workshop', 'party'];

    /** @var string[] What the form asks, in its order: the kinds of events, and those words. */
    const CHOICES = ['openday', 'visit', 'talk', 'workshop', 'party', 'other'];

    /** @var string Its picture, by event. */
    const POSTER = 'actposter';

    /** @var string The photo of each one who gives it, by event, each in the folder of its key. */
    const SPEAKER = 'actspeaker';

    /** @var int How many people it names, at most. */
    const SPEAKERS = 4;

    /** @var int How long what it is about can be. */
    const ABOUT = 600;

    /** @var string[] What a centre is called in a sentence, in Spanish, when its name starts with it. */
    const NOUNS = ['colegio', 'escuela', 'instituto', 'academia', 'conservatorio', 'universidad', 'centro', 'liceo', 'jardín',
        'guardería'];

    /** @var string[] Places of a centre that are feminine in Spanish, to say them in a sentence. */
    const FEMININE = ['biblioteca', 'sala', 'entrada', 'cafeteria', 'secretaria', 'capilla', 'cancha', 'pista', 'piscina',
        'recepcion', 'ludoteca', 'casa', 'plaza', 'terraza', 'clase', 'huerta', 'guarderia', 'planta', 'escuela'];

    /** @var string[] And those that are masculine. */
    const MASCULINE = ['salon', 'patio', 'gimnasio', 'comedor', 'teatro', 'auditorio', 'pabellon', 'edificio', 'aula',
        'laboratorio', 'vestibulo', 'jardin', 'polideportivo', 'huerto', 'taller', 'colegio', 'instituto', 'parque'];

    /** @var int How long what it is about, in a line, can be. */
    const TOPIC = 200;

    /** @var int Anybody may come, and there is nothing to sign up to. */
    const FREE = 1;

    /** @var int Anybody may come, and whoever wants says so. */
    const FREE_ASKS = 2;

    /** @var int The longest side of a picture, as it is kept. */
    const SIDE = 1600;

    /** @var int The heaviest picture that is taken. */
    const MOST = 12 * 1024 * 1024;

    /**
     * What the form has chosen for an act.
     *
     * @param string $kind
     * @param string $word
     * @return string One of CHOICES.
     */
    public static function choice(string $kind, string $word): string {
        return $kind === 'other' && in_array($word, self::WORDS, true) ? $word : $kind;
    }

    /**
     * What a choice of the form is kept as.
     *
     * @param string $choice One of CHOICES.
     * @param string $own The word of the centre, for «other».
     * @return string[] kind, word
     */
    public static function kept(string $choice, string $own): array {
        if (in_array($choice, self::WORDS, true)) {
            return ['other', $choice];
        }
        $kind = in_array($choice, events::KINDS, true) ? $choice : 'openday';
        // A word of the centre that is one of ours is ours: it goes translated.
        $own = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $own)), 0, 40);
        return [$kind, $kind === 'other' ? $own : ''];
    }

    /**
     * What an act is, as a family reads it: never «another event».
     *
     * @param \stdClass $event
     * @param string $lang
     * @return string Empty when there is no word for it.
     */
    public static function word(\stdClass $event, string $lang): string {
        $word = trim((string) ($event->word ?? ''));
        if ($event->kind !== 'other') {
            return publiclang::str('act_word_' . ($event->kind === 'visit' ? 'visit' : 'openday'), $lang);
        }
        return in_array($word, self::WORDS, true) ? publiclang::str('act_word_' . $word, $lang) : format_string($word);
    }

    /**
     * What an act is, as the team reads it.
     *
     * @param \stdClass $event
     * @return string
     */
    public static function team_word(\stdClass $event): string {
        $word = trim((string) ($event->word ?? ''));
        if ($event->kind !== 'other' || $word === '') {
            return get_string('event_kind_' . $event->kind, self::C);
        }
        return in_array($word, self::WORDS, true) ? get_string('act_word_' . $word, self::C) : format_string($word);
    }

    /**
     * Who gives an act.
     *
     * @param \stdClass $event
     * @return array[] name, role, key
     */
    public static function speakers(\stdClass $event): array {
        $out = [];
        foreach (json_decode((string) ($event->speakers ?? ''), true) ?: [] as $one) {
            if (is_array($one) && trim((string) ($one['name'] ?? '')) !== '') {
                $out[] = ['name' => (string) $one['name'], 'role' => (string) ($one['role'] ?? ''),
                    'key' => preg_replace('/[^a-z0-9]/', '', (string) ($one['key'] ?? ''))];
            }
        }
        return array_slice($out, 0, self::SPEAKERS);
    }

    /**
     * For which courses an act is, by their names; none, for everyone.
     *
     * @param \stdClass $event
     * @return string[]
     */
    public static function audience(\stdClass $event): array {
        return array_values(array_filter(array_map('strval', json_decode((string) ($event->audience ?? ''), true) ?: [])));
    }

    /**
     * The names of the courses a centre gives, each once, in its order.
     *
     * @param int $centreid
     * @return string[]
     */
    public static function courses(int $centreid): array {
        $names = [];
        foreach (centres::offers($centreid, true) as $offer) {
            $names[(string) $offer->name] = true;
        }
        return array_map('strval', array_keys($names));
    }

    /**
     * What the form sends of all this, as events::save() keeps it.
     *
     * @param int $centreid
     * @param array $values topic, about, speakers (name, role, key), audience, free and posteralt
     * @return array Columns of the event.
     */
    public static function columns(int $centreid, array $values): array {
        $cut = fn($text, int $n) => \core_text::substr(trim((string) $text), 0, $n);
        $speakers = [];
        foreach ((array) ($values['speakers'] ?? []) as $one) {
            $name = $cut($one['name'] ?? '', 80);
            if ($name !== '' && count($speakers) < self::SPEAKERS) {
                $key = preg_replace('/[^a-z0-9]/', '', (string) ($one['key'] ?? ''));
                $speakers[] = ['name' => $name, 'role' => $cut($one['role'] ?? '', 120),
                    'key' => $key !== '' ? $key : random_string(8)];
            }
        }
        foreach ($speakers as $i => $one) {
            $speakers[$i]['key'] = \core_text::strtolower($one['key']);
        }
        $audience = array_values(array_intersect(self::courses($centreid), (array) ($values['audience'] ?? [])));
        $about = $cut($values['about'] ?? '', self::ABOUT);
        $alt = $cut($values['posteralt'] ?? '', 255);
        $topic = rtrim(trim(preg_replace('/\s+/u', ' ', $cut($values['topic'] ?? '', self::TOPIC))), '.');
        return [
            'about' => $about === '' ? null : $about,
            'speakers' => $speakers ? json_encode($speakers, JSON_UNESCAPED_UNICODE) : null,
            'audience' => $audience ? json_encode($audience, JSON_UNESCAPED_UNICODE) : null,
            'posteralt' => $alt === '' ? null : $alt,
            'topic' => $topic === '' ? null : $topic,
            'free' => in_array((int) ($values['free'] ?? 0), [self::FREE, self::FREE_ASKS], true) ? (int) $values['free'] : 0,
        ];
    }

    /**
     * Its own picture.
     *
     * @param int $eventid
     * @return \stored_file|null
     */
    public static function poster_file(int $eventid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            self::C,
            self::POSTER,
            $eventid,
            'filename',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * Its own picture, with its size and what it says.
     *
     * @param \stdClass $event
     * @return array|false url, full (to enlarge it), alt, w, h, wide, portrait, tall and ratio (its height by its width)
     */
    public static function poster(\stdClass $event) {
        $file = !empty($event->id) ? self::poster_file((int) $event->id) : null;
        $info = $file ? $file->get_imageinfo() : false;
        if (!$info) {
            return false;
        }
        // How tall it stands beside the rest, in its 330 by 400 px, for the page to leave its place.
        $tall = (int) round(min(400, 330 * $info['height'] / max(1, $info['width'])));
        return ['url' => self::url($file), 'w' => (int) $info['width'], 'h' => (int) $info['height'],
            'wide' => $info['width'] > 1.15 * $info['height'], 'portrait' => $info['height'] > 1.15 * $info['width'],
            'full' => self::url($file), 'tall' => $tall,
            'ratio' => round($info['height'] / max(1, $info['width']), 4),
            'alt' => trim((string) ($event->posteralt ?? '')) ?: format_string($event->name)];
    }

    /**
     * The photo of who gives it.
     *
     * @param int $eventid
     * @param string $key
     * @return string Its address; empty without one.
     */
    public static function photo(int $eventid, string $key): string {
        if ($key === '') {
            return '';
        }
        foreach (
            get_file_storage()->get_area_files(
                \context_system::instance()->id,
                self::C,
                self::SPEAKER,
                $eventid,
                'filename',
                false
            ) as $file
        ) {
            if ($file->get_filepath() === '/' . $key . '/') {
                return self::url($file);
            }
        }
        return '';
    }

    /**
     * The address of a picture, for anyone: the families have no account.
     *
     * @param \stored_file $file
     * @return string
     */
    protected static function url(\stored_file $file): string {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            self::C,
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * Keeps a picture sent with the form: without what the camera wrote in it, turned as it was taken and no longer than
     * SIDE, instead of the one there was.
     *
     * @param array|null $sent As PHP gives it: error, tmp_name.
     * @param string $area POSTER or SPEAKER.
     * @param int $eventid
     * @param string $path The folder, for a photo of who gives it.
     * @return bool False when something came and it is no picture that can be kept.
     */
    public static function keep(?array $sent, string $area, int $eventid, string $path = '/'): bool {
        $error = (int) ($sent['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return true;
        }
        $file = (string) ($sent['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file) || filesize($file) > self::MOST) {
            return false;
        }
        return self::store((string) file_get_contents($file), $area, $eventid, $path);
    }

    /**
     * Keeps the bytes of a picture.
     *
     * @param string $bytes
     * @param string $area
     * @param int $eventid
     * @param string $path
     * @return bool
     */
    public static function store(string $bytes, string $area, int $eventid, string $path = '/'): bool {
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($types[$info['mime']]) || $info[0] * $info[1] > drawings::PIXELS) {
            return false;
        }
        [$bytes, $info] = drawings::clean($bytes, $info, self::SIDE);
        $context = \context_system::instance()->id;
        $fs = get_file_storage();
        foreach ($fs->get_area_files($context, self::C, $area, $eventid, 'filename', false) as $old) {
            if ($old->get_filepath() === $path) {
                $old->delete();
            }
        }
        $fs->create_file_from_string(['contextid' => $context, 'component' => self::C, 'filearea' => $area,
            'itemid' => $eventid, 'filepath' => $path, 'filename' => substr(sha1($bytes), 0, 12) . '.' .
            $types[$info['mime']]], $bytes);
        self::posted($eventid, $area);
        return true;
    }

    /**
     * What waits for the channel takes the poster of its act as it is now, whoever kept it.
     *
     * @param int $eventid
     * @param string $area
     */
    protected static function posted(int $eventid, string $area): void {
        global $DB;
        $event = $area === self::POSTER ? $DB->get_record(events::T, ['id' => $eventid]) : null;
        if ($event) {
            channel::sync($event);
        }
    }

    /**
     * Takes away its picture, or the photos of those who no longer give it.
     *
     * @param int $eventid
     * @param string $area
     * @param string[]|null $keep The keys whose photo stays; null for none.
     */
    public static function drop(int $eventid, string $area, ?array $keep = null): void {
        foreach (
            get_file_storage()->get_area_files(
                \context_system::instance()->id,
                self::C,
                $area,
                $eventid,
                'filename',
                false
            ) as $file
        ) {
            if ($keep === null || !in_array(trim($file->get_filepath(), '/'), $keep, true)) {
                $file->delete();
            }
        }
        self::posted($eventid, $area);
    }

    /**
     * How an act is told to a family, for its pages.
     *
     * @param \stdClass $event
     * @param string $lang
     * @return array word, any (whether there is something to tell), about, poster, speakers, hasspeakers, audience, free and their words.
     */
    public static function context(\stdClass $event, string $lang): array {
        $speakers = array_map(fn($one) => ['name' => format_string($one['name']), 'role' => format_string($one['role']),
            'photo' => self::photo((int) ($event->id ?? 0), $one['key']),
            'initials' => publicpage::initials($one['name'])], self::speakers($event));
        $audience = array_map('format_string', self::audience($event));
        $about = trim((string) ($event->about ?? ''));
        $poster = self::poster($event);
        return [
            'word' => self::word($event, $lang),
            'any' => $poster || $speakers || $audience || $about !== '' || !empty($event->free),
            'about' => $about,
            'poster' => $poster,
            'speakers' => $speakers,
            'hasspeakers' => (bool) $speakers,
            'speakerstitle' => publiclang::str('act_speakers', $lang),
            'audience' => $audience ? publiclang::str('act_for', $lang, implode(', ', $audience)) : '',
            'free' => !empty($event->free),
            // Whether there is something to fill all the same: whoever wants says they come.
            'asks' => (int) ($event->free ?? 0) === self::FREE_ASKS,
            'freetext' => !empty($event->free) ? publiclang::str((int) $event->free === self::FREE_ASKS ? 'act_free_asks' :
                'act_free', $lang) : '',
        ];
    }

    /**
     * What an act is about, said in a word or two: what goes before the colon of its topic, or its name.
     *
     * @param \stdClass $act
     * @return string
     */
    public static function short_topic(\stdClass $act): string {
        $topic = trim((string) ($act->topic ?? ''));
        $short = trim((string) strtok($topic, ':'));
        return $short !== '' ? format_string($short, true, ['escape' => false]) : format_string(
            (string) ($act->eventname ?? $act->name ?? ''),
            true,
            ['escape' => false]
        );
    }

    /**
     * The centre as people call it in a sentence: in Spanish «el colegio», when it is one; else its name.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function short_centre(\stdClass $centre, string $lang): string {
        $phrase = html_entity_decode(share::phrase($centre, $lang), ENT_QUOTES, 'UTF-8');
        if ($lang === 'es' && preg_match('/^(el|la) (' . implode('|', self::NOUNS) . ')\b/iu', $phrase, $m)) {
            return \core_text::strtolower($m[1] . ' ' . $m[2]);
        }
        return $phrase;
    }

    /**
     * The centre after asking for it, as it is called in a sentence: «por el colegio».
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function by_centre(\stdClass $centre, string $lang): string {
        return $lang === 'es' ? 'por ' . self::short_centre($centre, $lang) : share::by_centre($centre, $lang);
    }

    /**
     * The subject of the letter of an act: what it is, the day and what it is about, never only its name.
     *
     * @param \stdClass $act As invites::of_letter() gives it.
     * @param string $lang
     * @param array $parts publicpage::date_parts() of its day.
     * @return string
     */
    public static function letter_subject(\stdClass $act, string $lang, array $parts): string {
        $name = html_entity_decode(format_string($act->eventname), ENT_QUOTES, 'UTF-8');
        $word = ($act->kind ?? '') === 'other' ? self::word($act, $lang) : '';
        if ($word === '') {
            return publiclang::str('mail_letter_subject', $lang, (object) ['event' => $name, 'date' => $parts['long']]);
        }
        return publiclang::str('mail_letter_subject_act', $lang, (object) ['word' => html_entity_decode(
            $word,
            ENT_QUOTES,
            'UTF-8'
        ), 'day' => $parts['daymonth'], 'what' => self::short_topic($act)]);
    }

    /**
     * What an act is about, in the middle of a sentence: «sobre el sueño de los niños», though it was written with a capital;
     * a name keeps its own.
     *
     * @param \stdClass $act
     * @return string
     */
    public static function topic(\stdClass $act): string {
        $topic = format_string(trim((string) ($act->topic ?? '')), true, ['escape' => false]);
        $articles = '/^(El|La|Los|Las|Un|Una|Lo|Cómo|Qué|Por qué|Cuándo|The|A|An|How|What|Why|O|Os|As|Um|Uma|Como|Que|Porque)\s/u';
        return preg_match($articles, $topic) ? \core_text::strtolower(\core_text::substr($topic, 0, 1)) . \core_text::substr($topic, 1) : $topic;
    }

    /**
     * The first sentence of the letter: when, who and what about.
     *
     * @param \stdClass $act
     * @param \stdClass $centre
     * @param string $lang
     * @param string $date
     * @param string $when
     * @return string
     */
    public static function letter_lead(\stdClass $act, \stdClass $centre, string $lang, string $date, string $when): string {
        $kind = (string) ($act->kind ?? 'other');
        $word = trim((string) ($act->word ?? ''));
        $name = html_entity_decode(format_string($act->eventname), ENT_QUOTES, 'UTF-8');
        $speakers = self::speakers($act);
        // Who gives it, with what they are: «Carolina Pérez Soto, maestra de Educación Primaria, da…»; of several, each with
        // theirs in brackets.
        $several = count($speakers) > 1;
        $who = array_map(function ($one) use ($several) {
            $role = self::lower(html_entity_decode(format_string(trim($one['role'])), ENT_QUOTES, 'UTF-8'));
            return html_entity_decode(format_string($one['name']), ENT_QUOTES, 'UTF-8') . ($role === '' ? '' : ($several ?
                ' (' . $role . ')' : ', ' . $role));
        }, $speakers);
        $named = familypage::join($who, $lang);
        // The comma that closes what they are, before what they do.
        $comma = !$several && $speakers && trim($speakers[0]['role']) !== '' ? (lists::dir($lang) === 'rtl' ? '' : ',') : '';
        $a = (object) ['date' => $date, 'when' => $when, 'who' => $named . $comma,
            'centre' => self::short_centre($centre, $lang), 'word' => self::lower(html_entity_decode(
                format_string($word),
                ENT_QUOTES,
                'UTF-8'
            )), 'event' => $name];
        $many = count($speakers) > 1 ? '_many' : '';
        if ($kind !== 'other') {
            $key = 'mail_letter_act_' . ($kind === 'visit' ? 'visit' : 'openday');
            $named = recognise::plain($name) !== recognise::plain(self::word($act, $lang)) ? $name : '';
        } else if (in_array($word, self::WORDS, true)) {
            $key = 'mail_letter_act_' . $word . ($speakers ? $many : '_none');
            $named = $name;
        } else {
            $key = $word !== '' ? 'mail_letter_act_own' : 'mail_letter_act_other';
            $named = $word !== '' ? $name : '';
        }
        $topic = $kind === 'other' ? trim((string) ($act->topic ?? '')) : '';
        $lead = publiclang::str($key, $lang, $a);
        // A party, or an act of a word of the centre, is with somebody; a talk is given by them.
        if ($speakers && !in_array($word, ['talk', 'workshop'], true)) {
            $lead .= publiclang::str('mail_letter_with', $lang, implode(', ', $who));
        }
        // It begins a paragraph, whatever it begins with in each language.
        $lead = \core_text::strtoupper(\core_text::substr($lead, 0, 1)) . \core_text::substr($lead, 1);
        if ($topic !== '') {
            return $lead . publiclang::str('mail_letter_about', $lang, self::topic($act)) . '.';
        }
        return $named !== '' ? $lead . publiclang::str('mail_letter_named', $lang, $named) : $lead . '.';
    }

    /**
     * For whom an act is: any family, or those of some courses. Nothing for an open day or a visit.
     *
     * @param \stdClass $act
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function letter_whom(\stdClass $act, \stdClass $centre, string $lang): string {
        if (($act->kind ?? '') !== 'other') {
            return '';
        }
        $audience = array_map(fn($n) => html_entity_decode(format_string($n), ENT_QUOTES, 'UTF-8'), self::audience($act));
        if ($audience) {
            return publiclang::str('mail_letter_for', $lang, familypage::join($audience, $lang));
        }
        $word = trim((string) ($act->word ?? ''));
        return publiclang::str('mail_letter_open_' . (in_array($word, self::WORDS, true) ? $word : 'own'), $lang, self::short_centre(
            $centre,
            $lang
        ));
    }

    /**
     * Where an act is, as it is said: «Es en la Biblioteca…», with its article when it has a known one, and the street.
     *
     * @param \stdClass $act
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function letter_place(\stdClass $act, \stdClass $centre, string $lang): string {
        // The street is enough in a letter to somebody of the place; the page it opens has the whole address.
        $address = trim(preg_replace('/[\s,·-]*\b\d{4,5}(-\d{3,4})?\b.*$/us', '', trim((string) ($centre->address ?? ''))));
        $place = html_entity_decode(format_string(trim((string) ($act->place ?? ''))), ENT_QUOTES, 'UTF-8');
        if ($place === '' && $address === '') {
            return '';
        }
        if ($place !== '' && $lang === 'es') {
            $article = preg_match('/^(el|la|los|las) /iu', $place) ? '' : null;
            $first = recognise::plain((string) preg_replace('/[^\p{L}].*$/us', '', $place));
            $article = $article ?? (in_array($first, self::FEMININE, true) ? 'la ' : (in_array($first, self::MASCULINE, true) ?
                'el ' : null));
            if ($article !== null) {
                return 'Es en ' . $article . $place . ($address !== '' ? ', ' . $address : '') . '.';
            }
        }
        return publiclang::str('mail_letter_place', $lang, implode(', ', array_filter([$place, $address])));
    }

    /**
     * A word or a role as it goes inside a sentence, unless it is an acronym.
     *
     * @param string $text
     * @return string
     */
    protected static function lower(string $text): string {
        return preg_match('/^\p{Lu}\p{Ll}/u', $text) ? \core_text::strtolower(\core_text::substr($text, 0, 1)) .
            \core_text::substr($text, 1) : $text;
    }
}
