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
 * Giving an appointment to a family, changing it or calling it off: the fifteen days to come with what there is in each, the
 * hours, how long, who receives and where, and whether the family is told.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_give {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How many days are offered. */
    const DAYS = 15;

    /** @var int[] The hours of a day, in minutes, while the centre has none of its own. */
    const HOURS = [480, 1080];

    /** @var int The first hour proposed, in minutes: earlier is for whoever asks for it. */
    const FIRST = 540;

    /** @var int[] How long one can be. */
    const MINUTES = [20, 30, 45, 60, 90];

    /**
     * What was sent, checked.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array start, minutes, guide, place, people, tell and error (a string id, or empty)
     */
    public static function posted(\stdClass $centre, int $now): array {
        $day = optional_param('day', '', PARAM_RAW_TRIMMED);
        $min = optional_param('min', -1, PARAM_INT);
        $minutes = optional_param('minutes', 45, PARAM_INT);
        $start = 0;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $d) && $min >= 0 && $min < 1440) {
            $start = make_timestamp((int) $d[1], (int) $d[2], (int) $d[3], intdiv($min, 60), $min % 60, 0, \core_date::get_server_timezone());
        }
        $guide = optional_param('guide', 0, PARAM_INT);
        return [
            'start' => $start,
            'minutes' => max(15, min(240, $minutes)),
            'guide' => $guide && isset(panel_agenda::faces($centre)[$guide]) ? $guide : 0,
            'place' => \core_text::substr(trim(optional_param('place', '', PARAM_TEXT)), 0, 255),
            'people' => max(1, min(20, optional_param('people', 2, PARAM_INT))),
            'tell' => optional_param('tell', 1, PARAM_BOOL),
            'error' => $start <= $now ? 'visit_err_when' : '',
        ];
    }

    /**
     * The context.
     *
     * @param \stdClass $full households::full()
     * @param \stdClass $centre
     * @param \stdClass|null $event The one that is changed or called off.
     * @param array $values What was sent, when it was wrong.
     * @param bool $off Whether it is being called off.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $full, \stdClass $centre, ?\stdClass $event, array $values, bool $off, int $now = 0): array {
        $now = $now ?: time();
        $zone = \core_date::get_server_timezone_object();
        $faces = panel_agenda::faces($centre);
        $first = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0);
        $to = $first->modify('+' . self::DAYS . ' days');
        $agenda = appointments::agenda($centre, $first->getTimestamp(), $to->getTimestamp(), $now);
        $people = households::some((int) $centre->id, array_column($agenda, 'householdid'));
        $closed = workdays::closed($centre);
        $guide = $values['guide'] ?? ($event ? (int) (array_values(array_filter(explode(',', (string) $event->hosts)))[0] ?? 0) : 0);
        $minutes = $values['minutes'] ?? ($event ? (int) round(($event->timeend - $event->timestart) / MINSECS) : 45);
        $data = [];
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $first->modify("+$i days");
            $key = $day->format('Y-m-d');
            $open = workdays::is_open($centre, $day, $closed);
            $next = $day->modify('+1 day')->getTimestamp();
            $items = [];
            foreach ($agenda as $one) {
                $here = $one['start'] >= $day->getTimestamp() && $one['start'] < $next && !$one['dayonly'];
                if (!$here || $one['state'] === 'off' || ($event && $one['eventid'] === (int) $event->id)) {
                    continue;
                }
                $start = (new \DateTimeImmutable('@' . $one['start']))->setTimezone($zone);
                $from = (int) $start->format('G') * 60 + (int) $start->format('i');
                $what = $one['kind'] === 'act' ? $one['title'] : (isset($people[$one['householdid']]) ? panel::adults_line(
                    $people[$one['householdid']]->adults
                ) : '');
                $items[$one['kind'] === 'act' ? 'a' . $one['eventid'] . '-' . $from : $one['id']] = ['from' => $from,
                    'to' => min(1440, $from + (int) round(($one['end'] - $one['start']) / MINSECS)), 'guide' => $one['guideid'], 'what' => $what];
            }
            $data[$key] = ['open' => $open ? [self::HOURS] : [], 'items' => array_values($items)];
            $days[] = ['key' => $key, 'weekday' => userdate($day->getTimestamp(), '%a'), 'day' => $day->format('j'),
                'month' => userdate($day->getTimestamp(), '%b'), 'closed' => !$open, 'today' => $i === 0,
                'when' => get_string('agenda_when', self::C, userdate($day->getTimestamp(), '%A') . ' ' . $day->format('j')),
                'n' => count($items), 'has' => (bool) $items,
                'first' => $open ? self::first($data[$key], $minutes, $guide, $i === 0 ? intdiv($now - $first->getTimestamp(), MINSECS) + 60 : 0) : ''];
        }
        $replaces = false;
        foreach (appointments::of($full, $now) as $one) {
            if ($one['kind'] === 'visit' && $one['state'] === 'coming' && (!$event || $one['eventid'] !== (int) $event->id)) {
                $replaces = panel_agenda::card($one, $full, $faces, false, $now);
            }
        }
        $current = false;
        foreach ($event ? appointments::of($full, $now) : [] as $one) {
            $current = $one['eventid'] === (int) $event->id && $one['kind'] === 'visit' ? panel_agenda::card($one, $full, $faces, false, $now) : $current;
        }
        $ways = array_values(array_filter(writeby::now($full, $centre), fn($w) => $w !== 'email' || households::recipient($full) !== ''));
        $silent = writeby::silent($full);
        $start = $values['start'] ?? ($event ? (int) $event->timestart : 0);
        // The day it starts on: the one it has, or the first with a free hour.
        $chosen = $start ? userdate($start, '%Y-%m-%d', $zone->getName(), false) : '';
        foreach ($days as $day) {
            $chosen = $chosen === '' && $day['first'] !== '' ? $day['key'] : $chosen;
        }
        $min = $start ? (int) userdate($start, '%H', $zone->getName(), false) * 60 + (int) userdate($start, '%M', $zone->getName(), false) : 0;
        foreach ($days as $i => $day) {
            $days[$i]['current'] = $day['key'] === $chosen;
            if (!$min && $day['key'] === $chosen && preg_match('/(\d+)\D(\d+)/', $day['first'], $m)) {
                $min = (int) $m[1] * 60 + (int) $m[2];
            }
        }
        $children = [];
        foreach ($full->children as $child) {
            $children[] = ['tag' => panel::tag($child), 'course' => !empty($child->offer) ? format_string($child->offer->name) : ''];
        }
        return [
            'head' => panel::head(['title' => get_string($off ? 'give_off_title' : ($event ? 'give_move_title' : 'give_title'), self::C),
                'sub' => panel::adults_line($full->adults), 'back' => ['url' => (new \moodle_url(
                    '/local/oksigeniaeguahe/household.php',
                    ['id' => $full->id]
                ))->out(false), 'name' => panel::adults_line($full->adults) ?: get_string('household', self::C)]]),
            'family' => panel::adults_line($full->adults),
            'children' => $children,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $full->id] + ($event ? ['id' => $event->id] : []) +
                ($off ? ['anular' => 1] : [])))->out(false),
            'sesskey' => sesskey(),
            'current' => $current,
            'off' => $off,
            'offurl' => $event && !$off ? (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $full->id, 'id' => $event->id,
                'anular' => 1]))->out(false) : '',
            'days' => $days,
            'min' => $min,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'chosenday' => $start ? userdate($start, '%Y-%m-%d', $zone->getName(), false) : '',
            'chosenmin' => $start ? (int) userdate($start, '%H', $zone->getName(), false) * 60 + (int) userdate($start, '%M', $zone->getName(), false) : '',
            'minutes' => array_map(
                fn($m) => ['value' => $m, 'label' => get_string('give_minutes', self::C, $m), 'current' => $m === $minutes],
                array_values(array_unique(array_merge(self::MINUTES, [$minutes])))
            ),
            'guides' => array_map(fn($id, $f) => $f + ['id' => $id, 'current' => $id === $guide], array_keys($faces), $faces),
            'hasguides' => (bool) $faces,
            'places' => self::places($centre, $values['place'] ?? ($event ? (string) $event->place : '')),
            'place' => $values['place'] ?? ($event ? (string) $event->place : ''),
            'people' => $values['people'] ?? ($current ? $current['people'] : 2),
            'tell' => ['can' => $ways && !$silent, 'how' => $ways && !$silent ? get_string(
                'give_tell_by',
                self::C,
                writeby::words($ways, current_language())
            ) : '', 'why' => $ways && !$silent ? '' : get_string($silent ? 'give_tell_silent' : 'give_tell_none', self::C),
                'checked' => $values['tell'] ?? (bool) ($ways && !$silent)],
            'replaces' => $replaces,
            'error' => !empty($values['error']) ? get_string($values['error'], self::C) : '',
        ];
    }

    /**
     * The first free hour of a day, in words: where one of this length fits, with nobody of that guide taken.
     *
     * @param array $day open and items, in minutes.
     * @param int $minutes
     * @param int $guide 0, whoever.
     * @param int $from Not before.
     * @return string
     */
    protected static function first(array $day, int $minutes, int $guide, int $from): string {
        foreach ($day['open'] as [$open, $close]) {
            for ($at = max($open, self::FIRST, (int) (ceil($from / 30) * 30)); $at + $minutes <= $close; $at += 30) {
                $taken = array_filter($day['items'], fn($o) => $o['from'] < $at + $minutes && $o['to'] > $at &&
                    (!$guide || !$o['guide'] || $o['guide'] === $guide));
                if (!$taken) {
                    return panel::hour(make_timestamp(2026, 1, 1, intdiv($at, 60), $at % 60));
                }
            }
        }
        return '';
    }

    /**
     * The places of the last appointments of the centre, to choose with a touch.
     *
     * @param \stdClass $centre
     * @param string $current
     * @return array[] value and current
     */
    protected static function places(\stdClass $centre, string $current): array {
        global $DB;
        $places = [];
        foreach (
            $DB->get_records_select(
                events::T,
                "centreid = ? AND place IS NOT NULL AND place <> ''",
                [$centre->id],
                'timemodified DESC, id DESC',
                'id, place',
                0,
                60
            ) as $row
        ) {
            $places[trim($row->place)] = true;
        }
        $places = array_slice(array_map('strval', array_keys($places)), 0, panel_agenda::PLACES);
        return array_map(
            fn($p, $n) => ['n' => $n, 'value' => $p, 'name' => format_string($p), 'current' => $p === $current],
            $places,
            array_keys($places)
        );
    }
}
