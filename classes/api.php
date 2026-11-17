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

namespace local_oksigeniaeguahe;

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\guide;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\share;

/**
 * What the other plugins of Oksigenia read from Eguahe, never about a family. For now, the open acts of each centre for
 * the screens of Hechauka: an open day, a talk or a festival with open sign-up.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var int How far ahead an open act is told. */
    const AHEAD = 30 * DAYSECS;

    /**
     * The open acts of the centres, under way and coming, for a screen: what, when and where, its turns, the address to
     * sign up (the screen draws its QR) and the guide of the centre with its greeting. For the act of today, how many
     * families have come: a number, never who. Never the practice centre, a cancelled act or a visit for one family.
     *
     * @param int|null $centreid One centre, or all of them.
     * @param int|null $now
     * @return array[] By start: centreid, centre, colour, lang, id, kind, name, place, timestart, timeend, today, turns
     *     (timestart, timeend, full), url, capacity, signed, came (null but today) and guide (img, path of its file on
     *     disk and hello, or null).
     */
    public static function open_acts(?int $centreid = null, ?int $now = null): array {
        global $CFG, $DB;
        $now = $now ?? time();
        $centres = centres::all();
        if ($centreid !== null) {
            $centres = isset($centres[$centreid]) ? [$centreid => $centres[$centreid]] : [];
        }
        if (!$centres) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($centres), SQL_PARAMS_NAMED);
        $events = $DB->get_records_select(
            events::T,
            "centreid $in AND open = 1 AND cancelled = 0 AND timeend > :now AND timestart <= :until",
            $params + ['now' => $now, 'until' => $now + self::AHEAD],
            'timestart ASC, id ASC'
        );
        $midnight = usergetmidnight($now);
        $out = [];
        foreach ($events as $event) {
            if (!share::act_open($event, $now)) {
                continue;
            }
            $centre = $centres[(int) $event->centreid];
            $lang = (string) ($centre->lang ?? 'es');
            $today = (int) $event->timestart < $midnight + DAYSECS;
            $turns = [];
            foreach (events::slots((int) $event->id) as $slot) {
                $turns[] = ['timestart' => (int) $slot->timestart, 'timeend' => (int) $slot->timeend,
                    'full' => (int) $slot->capacity > 0 && (int) $slot->people >= (int) $slot->capacity];
            }
            $kind = guide::of($centre);
            $said = trim((string) ($centre->guidehello ?? ''));
            // Seen from afar: the larger picture; and its file, for screens that show only what they keep themselves.
            $guide = $kind ? ['img' => str_replace('-256.webp', '-512.webp', guide::picture($kind, 'saluda')['src']),
                'path' => $CFG->dirroot . '/local/oksigeniaeguahe/pub/guia/' . $kind . '-saluda-512.webp',
                'hello' => $said !== '' ? format_string($said, true, ['escape' => false]) :
                    publiclang::str('pub_guide_hello', $lang)] : null;
            $out[] = [
                'centreid' => (int) $centre->id,
                'centre' => format_string($centre->name, true, ['escape' => false]),
                'colour' => (string) ($centre->colour ?? ''),
                'lang' => $lang,
                'id' => (int) $event->id,
                'kind' => (string) $event->kind,
                'name' => format_string($event->name, true, ['escape' => false]),
                'place' => format_string((string) $event->place, true, ['escape' => false]),
                'timestart' => (int) $event->timestart,
                'timeend' => (int) $event->timeend,
                'today' => $today,
                'turns' => $turns,
                'url' => events::short_url($event) ?? events::url($event)->out(false),
                'capacity' => (int) $event->capacity,
                'signed' => events::people((int) $event->id),
                'came' => $today ? (int) $DB->count_records_select(
                    events::SIGNUPS,
                    'eventid = ? AND attended > 0',
                    [$event->id]
                ) : null,
                'guide' => $guide,
            ];
        }
        return $out;
    }
}
