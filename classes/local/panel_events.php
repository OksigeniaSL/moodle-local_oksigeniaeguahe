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
 * What the open days and visits of the panel show, whatever they look like: what comes next, the events ahead with
 * who receives and how full they are, the visits families asked for and how each one is going, and the events past
 * with how many came.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_events {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How many events past are shown. */
    const PAST = 12;

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, int $now = 0): array {
        $now = $now ?: time();
        $c = self::C;
        $all = events::for_centre((int) $centre->id);
        $hosts = [];
        foreach (centres::hosts((int) $centre->id) as $host) {
            $hosts[$host->memberid] = $host;
        }
        $ahead = [];
        $past = [];
        $asking = [];
        foreach ($all as $event) {
            if (!empty($event->tailored)) {
                $asking[] = $event;
            } else if (empty($event->householdid)) {
                if ($event->timeend >= $now) {
                    $ahead[] = $event;
                } else {
                    $past[] = $event;
                }
            }
        }
        usort($ahead, fn($a, $b) => $a->timestart <=> $b->timestart);
        usort($past, fn($a, $b) => $b->timestart <=> $a->timestart);
        $requests = [];
        $waiting = 0;
        foreach ($asking as $event) {
            foreach (visits::requests($event) as $request) {
                $requests[] = self::request($request, $centre, $now);
                $waiting += $request->status === 'pending' ? 1 : 0;
            }
        }
        $next = $ahead[0] ?? null;
        $sub = [];
        if ($next) {
            $sub[] = get_string($next->capacity ? 'panel_events_next_load' : 'panel_events_next_people', $c, (object) [
                'households' => (int) $next->signedup, 'people' => (int) $next->people, 'capacity' => (int) $next->capacity]);
        }
        if ($waiting) {
            $sub[] = get_string($waiting === 1 ? 'panel_events_waiting_one' : 'panel_events_waiting', $c, $waiting);
        }
        return [
            'week' => self::week($centre, $all, $hosts, $now),
            'head' => panel::head([
                'title' => $next ? get_string('panel_events_next', $c, (object) ['name' => format_string($next->name),
                    'day' => userdate((int) $next->timestart, '%A %d')]) : get_string('panel_events_none', $c),
                'sub' => implode(' ', $sub),
                'action' => $canact ? ['url' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id,
                    'new' => 1]))->out(false), 'name' => get_string('event_new', $c), 'icon' => panel::icon('mas1')] : null,
            ]),
            'ahead' => array_map(fn($e) => self::row($e, $hosts, $now), $ahead),
            'hasahead' => (bool) $ahead,
            'asking' => array_map(fn($e) => [
                'name' => format_string($e->name),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $e->id]))->out(false),
                'link' => events::short_url($e) ?? events::url($e)->out(false),
            ], $asking),
            'requests' => $requests,
            'hasrequests' => (bool) $requests,
            'hasasking' => (bool) $asking,
            'past' => array_map(fn($e) => self::row($e, $hosts, $now), array_slice($past, 0, self::PAST)),
            'haspast' => (bool) $past,
        ];
    }

    /**
     * This week, from Monday to Sunday: every visit and event of each day, with its hour, the family and the child or
     * the event, the place and who guides or receives.
     *
     * @param \stdClass $centre
     * @param array $all The events of the centre.
     * @param array $hosts Who receives, by member id.
     * @param int $now
     * @return array title, sub, days
     */
    public static function week(\stdClass $centre, array $all, array $hosts, int $now): array {
        $c = self::C;
        $zone = \core_date::get_user_timezone_object();
        $today = (new \DateTime('@' . $now))->setTimezone($zone)->setTime(0, 0);
        $monday = (clone $today)->modify('-' . ((int) $today->format('N') - 1) . ' days');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = (clone $monday)->modify('+' . $i . ' days');
            $start = $day->getTimestamp();
            $items = [];
            foreach ($all as $event) {
                if (!empty($event->tailored) || $event->timestart < $start || $event->timestart >= $start + DAYSECS) {
                    continue;
                }
                $guide = null;
                foreach (array_filter(array_map('intval', explode(',', (string) $event->hosts))) as $id) {
                    if (isset($hosts[$id])) {
                        $guide = ['first' => $hosts[$id]->first, 'picture' => $hosts[$id]->photo ?
                            $hosts[$id]->photo->out(false) : '', 'initials' => $hosts[$id]->initials];
                        break;
                    }
                }
                $family = $event->householdid ? households::get((int) $event->householdid, (int) $centre->id) : null;
                $full = $family ? households::full($family) : null;
                $child = $full ? reset($full->children) : null;
                $minutes = (int) round(($event->timeend - $event->timestart) / MINSECS);
                $items[] = [
                    'hour' => panel::hour((int) $event->timestart),
                    'title' => $full ? panel::adults_line($full->adults) : format_string($event->name),
                    'tag' => $child ? panel::tag($child) : '',
                    'what' => implode(' · ', array_filter([trim((string) $event->place) !== '' ? format_string($event->place) : '',
                        $full ? get_string('panel_minutes', $c, $minutes) : ''])),
                    'who' => $guide,
                    'url' => (new \moodle_url(
                        $full ? '/local/oksigeniaeguahe/household.php' : '/local/oksigeniaeguahe/event.php',
                        ['id' => $full ? $family->id : $event->id]
                    ))->out(false),
                    'proposed' => false,
                    'start' => (int) $event->timestart,
                ];
            }
            usort($items, fn($a, $b) => $a['start'] <=> $b['start']);
            $days[] = panel::leaf($start) + [
                'today' => $start === $today->getTimestamp(),
                'past' => $start < $today->getTimestamp(),
                'empty' => !$items,
                'items' => $items,
            ];
        }
        $sunday = (clone $monday)->modify('+6 days');
        return ['title' => get_string('panel_this_week', $c), 'sub' => get_string('panel_week_span', $c, (object) [
            'from' => userdate($monday->getTimestamp(), '%A %d'), 'to' => userdate($sunday->getTimestamp(), '%A %d')]),
            'days' => $days];
    }

    /**
     * An event in a list: its leaf, name, kind, hours, place, turns, who receives and how full it is.
     *
     * @param \stdClass $event
     * @param array $hosts Who receives, by member id.
     * @param int $now
     * @return array
     */
    public static function row(\stdClass $event, array $hosts, int $now): array {
        $c = self::C;
        $receive = [];
        foreach (array_filter(array_map('intval', explode(',', (string) $event->hosts))) as $id) {
            if (isset($hosts[$id])) {
                $receive[] = ['name' => $hosts[$id]->name, 'photo' => $hosts[$id]->photo ? $hosts[$id]->photo->out(false) : '',
                    'initials' => $hosts[$id]->initials];
            }
        }
        $slots = count(events::slots((int) $event->id));
        $past = $event->timeend < $now;
        return panel::leaf((int) $event->timestart) + [
            'url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
            'name' => format_string($event->name),
            'kind' => get_string('event_kind_' . $event->kind, $c),
            'hours' => get_string('panel_hours', $c, (object) ['from' => panel::hour((int) $event->timestart),
                'to' => panel::hour((int) $event->timeend)]),
            'place' => trim((string) $event->place) !== '' ? format_string($event->place) : '',
            'slots' => $slots ? get_string('panel_slots', $c, $slots) : '',
            'receive' => $receive,
            'load' => $past ? get_string('panel_came_of', $c, (object) ['came' => (int) $event->came,
                'households' => (int) $event->signedup]) : get_string(
                    $event->capacity ? 'panel_load' : 'panel_load_open',
                    $c,
                    (object) ['households' => (int) $event->signedup, 'people' => (int) $event->people,
                    'capacity' => (int) $event->capacity]
                ),
            'percent' => $event->capacity ? min(100, (int) round(100 * $event->people / $event->capacity)) : 0,
            'hasbar' => !$past && $event->capacity > 0,
            'closed' => !$event->open,
        ];
    }

    /**
     * A visit a family asked for: who, what suits them, and how it is going.
     *
     * @param \stdClass $request With household and visit, as visits::requests() gives it.
     * @param \stdClass $centre
     * @param int $now
     * @return array
     */
    protected static function request(\stdClass $request, \stdClass $centre, int $now): array {
        $c = self::C;
        $visit = $request->visit;
        $child = reset($request->household->children);
        if ($request->status === 'pending' || !$visit) {
            $state = get_string('panel_request_waiting', $c);
        } else {
            $guide = $visit->hosts ? (centres::hosts((int) $centre->id, [(int) $visit->hosts])[0] ?? null) : null;
            $state = get_string($visit->timeend < $now ? 'panel_request_done' : 'panel_request_given', $c, (object) [
                'when' => panel::when_words((int) $visit->timestart, $now),
                'guide' => $guide ? $guide->name : get_string('visit_guide_none', $c),
            ]);
        }
        return [
            'adults' => panel::adults_line($request->household->adults),
            'tag' => $child ? panel::tag($child) : '',
            'suits' => get_string('panel_request_suits', $c, visits::suits($request, current_language())),
            'state' => $state,
            'pending' => $request->status === 'pending',
            'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $request->householdid]))->out(false),
        ];
    }
}
