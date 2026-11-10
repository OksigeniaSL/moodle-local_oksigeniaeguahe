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
 * The voice of «Tu primer día» saying a child's name: a bank of readings, one for each name, language and reading, made
 * once and kept for every child with that name, of any centre of the site. The book reads the one of its child when the
 * bank has it, and the one without the name when not. Each reading is a folder: the pages (`00-portada.mp3` to
 * `08-vuelta.mp3`), their stretches of speech (`habla.json`) and, if there is one, the whole story with its song
 * (`cuento-con-cancion.mp3`), as the readings of `pub/cuento/voz/` are.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class namevoices {
    /** @var string The file area, in the system context: a first name said aloud, for anyone. */
    const AREA = 'namevoice';

    /** @var string The files of a reading. */
    const FILES = '/^(\d\d-[a-z]+\.mp3|habla\.json|cuento-con-cancion\.mp3|cumple\.mp3|cumple\.json)$/';

    /** @var string The reading with the name, as the book calls it. */
    const READING = 'nombre';

    /**
     * The key of a name in the bank: in small letters, without accents, its words joined by hyphens («María de los
     * Ángeles» is maria-de-los-angeles). Empty when nothing is left.
     *
     * @param string $name
     * @return string
     */
    public static function key(string $name): string {
        $plain = \core_text::strtolower(trim($name));
        if (class_exists('\Transliterator')) {
            $latin = \Transliterator::create('Any-Latin; Latin-ASCII');
            $plain = $latin ? (string) $latin->transliterate($plain) : $plain;
        } else {
            $plain = \core_text::specialtoascii($plain);
        }
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($plain)), '-');
    }

    /**
     * The folder of a reading in the file area.
     *
     * @param string $code The language of the book (es, en, pt-PT, pt-BR, ar).
     * @param string $reading
     * @param string $key
     * @return string
     */
    private static function path(string $code, string $reading, string $key): string {
        return '/' . $code . '/' . $reading . '/' . $key . '/';
    }

    /**
     * The reading of a name, as the book takes a voice: where its pages are, its speech and the story to keep. Null when
     * the bank does not have it whole (every page and its speech).
     *
     * @param string $code
     * @param string $reading
     * @param string $name
     * @return array|null audio, speech, download
     */
    public static function find(string $code, string $reading, string $name): ?array {
        $key = self::key($name);
        if ($key === '') {
            return null;
        }
        $context = \context_system::instance();
        $path = self::path($code, $reading, $key);
        $files = [];
        foreach (get_file_storage()->get_directory_files($context->id, 'local_oksigeniaeguahe', self::AREA, 0, $path) as $f) {
            if (!$f->is_directory()) {
                $files[$f->get_filename()] = $f;
            }
        }
        $speech = isset($files['habla.json']) ? json_decode($files['habla.json']->get_content(), true) : null;
        if (!is_array($speech) || !$speech) {
            return null;
        }
        foreach (array_keys($speech) as $page) {
            if (!isset($files[$page . '.mp3'])) {
                return null;
            }
        }
        $url = function (string $file) use ($context, $path): string {
            return \moodle_url::make_pluginfile_url($context->id, 'local_oksigeniaeguahe', self::AREA, 0, $path, $file)->out(false);
        };
        $song = isset($files['cuento-con-cancion.mp3']) ? ['href' => $url('cuento-con-cancion.mp3')] : null;
        return ['audio' => rtrim($url(''), '/'), 'speech' => $speech, 'download' => $song];
    }

    /**
     * The birthday wish said with a name («Happy birthday, Sebas!»), when the bank has it: its sound and its JSON,
     * which says where the name is said.
     *
     * @param string $code
     * @param string $reading cumple, or in Arabic cumple-f or cumple-m.
     * @param string $name
     * @return array|null url, json
     */
    public static function find_birthday(string $code, string $reading, string $name): ?array {
        $key = self::key($name);
        if ($key === '') {
            return null;
        }
        $context = \context_system::instance();
        $path = self::path($code, $reading, $key);
        $fs = get_file_storage();
        foreach (['cumple.mp3', 'cumple.json'] as $file) {
            if (!$fs->file_exists($context->id, 'local_oksigeniaeguahe', self::AREA, 0, $path, $file)) {
                return null;
            }
        }
        $url = fn(string $file) => \moodle_url::make_pluginfile_url(
            $context->id,
            'local_oksigeniaeguahe',
            self::AREA,
            0,
            $path,
            $file
        )->out(false);
        return ['url' => $url('cumple.mp3'), 'json' => $url('cumple.json')];
    }

    /**
     * Puts the reading of a name in the bank from a folder, in place of the one it had.
     *
     * @param string $code
     * @param string $reading
     * @param string $name
     * @param string $dir A folder with the pages, habla.json and, if there is one, cuento-con-cancion.mp3; or, for the
     *     birthday, cumple.mp3 and cumple.json.
     * @return int The files kept.
     */
    public static function store(string $code, string $reading, string $name, string $dir): int {
        $key = self::key($name);
        if ($key === '' || (!is_file($dir . '/habla.json') && !is_file($dir . '/cumple.json'))) {
            throw new \coding_exception('A reading of a name needs a name and its habla.json, or its cumple.json');
        }
        self::delete($code, $reading, $name);
        $context = \context_system::instance();
        $fs = get_file_storage();
        $kept = 0;
        foreach (scandir($dir) ?: [] as $file) {
            if (!preg_match(self::FILES, $file) || !is_file($dir . '/' . $file)) {
                continue;
            }
            $record = ['contextid' => $context->id, 'component' => 'local_oksigeniaeguahe', 'filearea' => self::AREA,
                'itemid' => 0, 'filepath' => self::path($code, $reading, $key), 'filename' => $file];
            $fs->create_file_from_pathname($record, $dir . '/' . $file);
            $kept++;
        }
        return $kept;
    }

    /**
     * Takes the reading of a name out of the bank.
     *
     * @param string $code
     * @param string $reading
     * @param string $name
     */
    public static function delete(string $code, string $reading, string $name): void {
        $key = self::key($name);
        if ($key === '') {
            return;
        }
        $context = \context_system::instance();
        $fs = get_file_storage();
        $path = self::path($code, $reading, $key);
        foreach ($fs->get_directory_files($context->id, 'local_oksigeniaeguahe', self::AREA, 0, $path, false, true) as $f) {
            $f->delete();
        }
    }
}
