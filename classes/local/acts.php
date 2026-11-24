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
     * @param array $values about, speakers (name, role, key), audience, free and posteralt
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
        return [
            'about' => $about === '' ? null : $about,
            'speakers' => $speakers ? json_encode($speakers, JSON_UNESCAPED_UNICODE) : null,
            'audience' => $audience ? json_encode($audience, JSON_UNESCAPED_UNICODE) : null,
            'posteralt' => $alt === '' ? null : $alt,
            'free' => empty($values['free']) ? 0 : 1,
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
     * @return array|false url, alt, w, h
     */
    public static function poster(\stdClass $event) {
        $file = !empty($event->id) ? self::poster_file((int) $event->id) : null;
        $info = $file ? $file->get_imageinfo() : false;
        if (!$info) {
            return false;
        }
        return ['url' => self::url($file), 'w' => (int) $info['width'], 'h' => (int) $info['height'],
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
        return true;
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
    }

    /**
     * How an act is told to a family, for its pages.
     *
     * @param \stdClass $event
     * @param string $lang
     * @return array word, about, poster, speakers, hasspeakers, audience, free and their words.
     */
    public static function context(\stdClass $event, string $lang): array {
        $speakers = array_map(fn($one) => ['name' => format_string($one['name']), 'role' => format_string($one['role']),
            'photo' => self::photo((int) ($event->id ?? 0), $one['key']),
            'initials' => publicpage::initials($one['name'])], self::speakers($event));
        $audience = array_map('format_string', self::audience($event));
        return [
            'word' => self::word($event, $lang),
            'about' => trim((string) ($event->about ?? '')),
            'poster' => self::poster($event),
            'speakers' => $speakers,
            'hasspeakers' => (bool) $speakers,
            'speakerstitle' => publiclang::str('act_speakers', $lang),
            'audience' => $audience ? publiclang::str('act_for', $lang, implode(', ', $audience)) : '',
            'free' => !empty($event->free),
            'freetext' => !empty($event->free) ? publiclang::str('act_free', $lang) : '',
        ];
    }
}
