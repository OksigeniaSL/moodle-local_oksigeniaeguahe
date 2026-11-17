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
 * The guided helps, «How it works: …»: the real screen of Eguahe in a frame, on the practice centre, while a pointer
 * shows how something is done, each step with its caption and, when the art package brings it, its voice and a music under
 * it; and at one moment, «Try it», when the person does it. The steps of each piece are in pub/ayuda-piezas.js; their
 * captions, the strings help_<piece>_NN.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class helps {
    /** @var string Component. */
    const C = 'local_oksigeniaeguahe';

    /** @var array[] Each piece: how many captions it has and the tasks of «How is it done?» it shows. */
    const PIECES = [
        'inbox' => [6, ['waiting']],
        'answer' => [7, ['answer', 'promise', 'saved']],
        'newevent' => [7, ['newevent']],
        'door' => [6, ['door', 'eventlabels']],
    ];

    /**
     * The piece that shows a task of «How is it done?», if any.
     *
     * @param string $task
     * @return string|null
     */
    public static function for_task(string $task): ?string {
        foreach (self::PIECES as $piece => [, $tasks]) {
            if (in_array($task, $tasks, true)) {
                return $piece;
            }
        }
        return null;
    }

    /**
     * The address of a guided help, opened from a centre.
     *
     * @param string $piece
     * @param int $centreid
     * @return string
     */
    public static function url(string $piece, int $centreid): string {
        return (new \moodle_url('/local/oksigeniaeguahe/help.php', ['c' => $centreid, 'piece' => $piece]))->out(false);
    }

    /**
     * The address of the guided help of a page, for its head; none inside the practice centre, where it plays.
     *
     * @param string $piece
     * @param \stdClass $centre
     * @return string|false
     */
    public static function link(string $piece, \stdClass $centre) {
        return empty($centre->practice) ? self::url($piece, (int) $centre->id) : false;
    }

    /**
     * Where a piece starts, on the practice centre: the page and what it needs (the family that waits, the open day of
     * today).
     *
     * @param string $piece
     * @param \stdClass $practice
     * @return string
     */
    public static function start(string $piece, \stdClass $practice): string {
        global $DB;
        $id = (int) $practice->id;
        // The family whose question the help's text answers; once answered, the practice centre starts again.
        $answer = practice::answer($id);
        if (
            $piece === 'answer' && !$DB->record_exists_select(
                households::T,
                'id = ? AND centreid = ? AND waitingsince > 0',
                [$answer, $id]
            )
        ) {
            practice::make($id, time(), (int) $practice->practice);
            $answer = practice::answer($id);
        }
        $url = match ($piece) {
            'answer' => new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $answer]),
            'newevent' => new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $id, 'new' => 1]),
            'door' => new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => (int) $DB->get_field_sql(
                'SELECT id FROM {local_oksigeniaeguahe_event} WHERE centreid = ? AND kind = ? AND timestart <= ? AND timeend > ?
                 ORDER BY timestart ASC',
                [$id, 'openday', time(), time()],
                IGNORE_MULTIPLE
            )]),
            default => new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $id]),
        };
        return $url->out(false);
    }

    /**
     * The captions of a piece, in the language of the page.
     *
     * @param string $piece
     * @return string[]
     */
    public static function steps(string $piece): array {
        $out = [];
        for ($i = 1; $i <= self::PIECES[$piece][0]; $i++) {
            $out[] = get_string('help_' . $piece . '_' . sprintf('%02d', $i), self::C);
        }
        return $out;
    }

    /**
     * The voice of each step and the music under it, when the art package brings them for this language: their
     * addresses, or none (the captions are read, without sound).
     *
     * @param string $piece
     * @param string $lang
     * @return array voices (one per step, or empty) and music (or '').
     */
    public static function sound(string $piece, string $lang): array {
        global $CFG;
        $dir = $CFG->dirroot . '/local/oksigeniaeguahearte/ayuda';
        $base = $CFG->wwwroot . '/local/oksigeniaeguahearte/ayuda';
        $lang = str_replace('_', '-', $lang === 'pt_br' ? 'pt_BR' : $lang);
        $voices = [];
        for ($i = 1; $i <= self::PIECES[$piece][0]; $i++) {
            $file = sprintf('%s/%s/%s/%02d.mp3', $dir, $piece, $lang, $i);
            $voices[] = file_exists($file) ? sprintf('%s/%s/%s/%02d.mp3', $base, $piece, $lang, $i) : '';
        }
        return [
            'voices' => array_filter($voices) ? $voices : [],
            'music' => file_exists($dir . '/fondo.mp3') ? $base . '/fondo.mp3' : '',
        ];
    }
}
