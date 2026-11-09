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
 * The data of the page of the kit of an act for the networks, apart from its template: when to post, the link of each
 * network, the pictures, the texts (by the AI of the centre or by rules) and the script.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_kit {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The page.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $event, \stdClass $centre, bool $canact, int $now): array {
        $c = self::C;
        $url = new \moodle_url('/local/oksigeniaeguahe/kit.php', ['id' => $event->id]);
        $texts = kit::texts($event, $centre);
        $pictures = [];
        foreach (array_keys(kit::SHAPES) as $shape) {
            [$w, $h] = kit::SHAPES[$shape];
            $pictures[] = ['name' => get_string('kit_shape_' . $shape, $c), 'size' => $w . ' × ' . $h,
                'url' => (new \moodle_url($url, ['img' => $shape]))->out(false),
                'download' => (new \moodle_url($url, ['img' => $shape, 'bajar' => 1]))->out(false), 'w' => $w, 'h' => $h];
        }
        $shots = [];
        $total = 0;
        foreach ($texts['script'] as $i => $shot) {
            $total += (int) $shot['seconds'];
            $shots[] = ['n' => $i + 1, 'shot' => $shot['shot'], 'seconds' => get_string('kit_seconds', $c, (int) $shot['seconds']),
                'onscreen' => $shot['onscreen']];
        }
        $ai = ai::ready($centre);
        return [
            'head' => panel::head([
                'title' => get_string('kit_title', $c),
                'ante' => format_string($event->name),
                'sub' => get_string('kit_sub', $c),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
                    'name' => format_string($event->name)],
            ]),
            'when' => kit::when($event, (string) $centre->lang, $now),
            'links' => kit::links($event, $centre),
            'pictures' => $pictures,
            'posterurl' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id, 'poster' => 1]))->out(false),
            'post' => $texts['post'],
            'forward' => $texts['forward'],
            'shots' => $shots,
            'total' => get_string('kit_seconds', $c, $total),
            'byai' => ($texts['by'] ?? '') === 'ai' ? get_string('kit_by_ai', $c, ai::name($centre) ?: get_string('kit_ai', $c)) :
                '',
            'canwrite' => $canact && $ai,
            'writelabel' => get_string(($texts['by'] ?? '') === 'ai' ? 'kit_write_again' : 'kit_write', $c),
            'canforget' => $canact && ($texts['by'] ?? '') === 'ai',
            'noai' => !$ai ? get_string('kit_noai', $c) : '',
            'action' => $url->out(false),
            'sesskey' => sesskey(),
        ];
    }
}
