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
 * The drawing of a child in the panel, whatever it looks like: the one there is, and taking it with the phone, cut to
 * the four corners of the sheet and straightened in the browser; without JavaScript, the photo as it is.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_drawing {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * Where the page goes back to: the door of an event, or the file of the household.
     *
     * @param \stdClass $child
     * @param int $eventid
     * @return \moodle_url
     */
    public static function back(\stdClass $child, int $eventid): \moodle_url {
        return $eventid ? new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $eventid]) :
            new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $child->householdid]);
    }

    /**
     * The context of the page.
     *
     * @param \stdClass $child
     * @param int $eventid
     * @param bool $canact
     * @return array
     */
    public static function context(\stdClass $child, int $eventid, bool $canact): array {
        $c = self::C;
        $tag = panel::tag($child);
        $file = drawings::file((int) $child->id);
        $url = drawings::url((int) $child->id);
        $back = self::back($child, $eventid);
        return [
            'head' => panel::head([
                'title' => get_string('panel_drawing_title', $c, $tag),
                'sub' => $file ? get_string('panel_drawing_when', $c, userdate(
                    $file->get_timemodified(),
                    get_string('strftimedatetimeshort', 'langconfig')
                )) : get_string('panel_drawing_sub', $c, $tag),
                'back' => ['url' => $back->out(false), 'name' => get_string($eventid ? 'panel_drawing_back_door' :
                    'panel_drawing_back_file', $c)],
            ]),
            'current' => $url ? $url->out(false) : '',
            'alt' => get_string('panel_drawing_alt', $c, $tag),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/drawing.php', ['child' => $child->id] +
                ($eventid ? ['event' => $eventid] : [])))->out(false),
            'backurl' => $back->out(false),
            'sesskey' => sesskey(),
            'canact' => $canact,
        ];
    }
}
