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
 * What an event shows in the panel, whatever it looks like, and its door: how many came of how many, the families by
 * their turn with «came» at hand, someone who arrives without signing up; for the event of asking, what each family
 * asked for; for a visit of a family, whose it is; and what is shared: the QR, the link and the posters.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_event {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * What the door asks every half minute: the families that came, with the hour.
     *
     * @param \stdClass $event
     * @return array came: sign-up id => hour.
     */
    public static function check(\stdClass $event): array {
        global $DB;
        $came = [];
        foreach (
            $DB->get_records_select(
                'local_oksigeniaeguahe_signup',
                'eventid = ? AND attended > 0',
                [$event->id],
                '',
                'id, attended'
            ) as $signup
        ) {
            $came[(string) $signup->id] = panel::hour((int) $signup->attended);
        }
        return ['came' => (object) $came];
    }

    /**
     * The context of the page.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $event, \stdClass $centre, bool $canact, int $now = 0): array {
        $now = $now ?: time();
        $c = self::C;
        $url = new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]);
        $tailored = !empty($event->tailored);
        $family = $event->householdid ? households::get((int) $event->householdid, (int) $centre->id) : null;

        // The families by their turn: the turn of now open, the others closed; without turns, one list by name.
        $slots = events::slots((int) $event->id);
        $groups = [];
        foreach ($slots as $slot) {
            $groups[$slot->id] = ['title' => get_string('panel_hours', $c, (object) ['from' => panel::hour((int) $slot->timestart),
                'to' => panel::hour((int) $slot->timeend)]), 'now' => $slot->timestart <= $now && $now < $slot->timeend,
                'capacity' => (int) $slot->capacity, 'signups' => [], 'came' => 0];
        }
        $households = 0;
        $came = 0;
        $people = 0;
        $peoplecame = 0;
        foreach (events::signups($event) as $signup) {
            $key = isset($groups[$signup->slotid]) ? (int) $signup->slotid : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = ['title' => $slots ? get_string('event_noslot', $c) : '', 'now' => false, 'capacity' => 0,
                    'signups' => [], 'came' => 0];
            }
            $children = [];
            foreach ($signup->household->children as $child) {
                $children[] = ['tag' => panel::tag($child), 'course' => $child->offer ? format_string($child->offer->name) :
                    get_string('panel_nocourse', $c)];
            }
            $arrived = (int) $signup->attended > 0;
            $groups[$key]['signups'][] = [
                'id' => (int) $signup->id,
                'adults' => panel::adults_line($signup->household->adults),
                'children' => $children,
                'people' => get_string('panel_people', $c, (int) $signup->people),
                'came' => $arrived,
                'cametext' => $arrived ? get_string('panel_came_at', $c, panel::hour((int) $signup->attended)) :
                    get_string('panel_came_button', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $signup->householdid]))->out(false),
                'search' => \core_text::strtolower(panel::adults_line($signup->household->adults) . ' ' .
                    implode(' ', array_column($children, 'tag'))),
            ];
            $groups[$key]['came'] += $arrived ? 1 : 0;
            $households++;
            $came += $arrived ? 1 : 0;
            $people += (int) $signup->people;
            $peoplecame += $arrived ? (int) $signup->people : 0;
        }
        $anynow = (bool) array_filter($groups, fn($g) => $g['now']);
        $list = [];
        foreach ($groups as $group) {
            if (!$slots) {
                usort($group['signups'], fn($a, $b) => strcoll($a['adults'], $b['adults']));
            }
            $list[] = $group + [
                'open' => $group['now'] || !$anynow,
                'count' => get_string('panel_came_count', $c, (object) ['came' => $group['came'],
                    'households' => count($group['signups'])]),
                'hassignups' => (bool) $group['signups'],
            ];
        }

        // The band: at the door on the day; before, how much is left; after, how it went.
        $today = panel::days_between((int) $event->timestart, $now) === 0;
        if ($tailored) {
            $band = get_string('visit_until', $c, userdate((int) $event->timeend, get_string('strftimedate', 'langconfig')));
        } else if ($today) {
            $band = get_string('panel_at_the_door', $c, (object) ['day' => userdate((int) $event->timestart, '%A %d'),
                'now' => panel::hour($now)]);
        } else if ($event->timestart > $now) {
            $days = panel::days_between($now, (int) $event->timestart);
            $band = get_string($days === 1 ? 'panel_days_left_one' : 'panel_days_left', $c, $days);
        } else {
            $band = get_string('panel_it_was', $c, panel::when_words((int) $event->timestart, $now));
        }
        $hosts = $event->hosts ? centres::hosts((int) $centre->id, array_map('intval', explode(',', (string) $event->hosts))) : [];
        $detail = [];
        if (!$tailored) {
            $detail[] = get_string('panel_hours', $c, (object) ['from' => panel::hour((int) $event->timestart),
                'to' => panel::hour((int) $event->timeend)]);
        }
        if (trim((string) $event->place) !== '') {
            $detail[] = format_string($event->place);
        }
        if ($hosts) {
            $firsts = array_map(fn($h) => $h->first, $hosts);
            $last = array_pop($firsts);
            $detail[] = get_string(count($hosts) === 1 ? 'panel_receives' : 'panel_receive', $c, $firsts ?
                get_string('andlist', $c, (object) ['list' => implode(', ', $firsts), 'last' => $last]) : $last);
        }

        // The event of asking: what each family asked for, and the visit it was given.
        $requests = [];
        foreach ($tailored ? visits::requests($event) : [] as $request) {
            $visit = $request->visit;
            $guide = $visit && $visit->hosts ? (centres::hosts((int) $centre->id, [(int) $visit->hosts])[0] ?? null) : null;
            $child = reset($request->household->children);
            $requests[] = [
                'adults' => panel::adults_line($request->household->adults),
                'tag' => $child ? panel::tag($child) : '',
                'suits' => get_string('panel_request_suits', $c, visits::suits($request, current_language())),
                'state' => $request->status === 'pending' || !$visit ? get_string('panel_request_waiting', $c) :
                    get_string($visit->timeend < $now ? 'panel_request_done' : 'panel_request_given', $c, (object) [
                        'when' => panel::when_words((int) $visit->timestart, $now),
                        'guide' => $guide ? $guide->name : get_string('visit_guide_none', $c)]),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $request->householdid]))->out(false),
            ];
        }
        $form = $event->formid ? entries::get((int) $event->formid, (int) $centre->id) : null;
        // The wall of the turn of now, on the day: a knob per family and the backpack of those that came.
        $nowturn = null;
        if ($today && !$tailored && !$family) {
            $group = array_values(array_filter($list, fn($g) => $g['now']))[0] ?? ($slots ? null : ($list[0] ?? null));
            if ($group) {
                $wall = [];
                foreach ($group['signups'] as $i => $signup) {
                    $wall[] = ['came' => $signup['came'], 'name' => $signup['children'][0]['tag'] ?? ''] +
                        ['style' => panel::hanging($i, '')['style']];
                }
                $nowturn = $wall ? ['wall' => $wall] : null;
            }
        }
        return [
            'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]))->out(false),
                'name' => get_string('tab_events', $c)],
            'band' => $band,
            'name' => format_string($event->name),
            'detail' => implode(' · ', $detail),
            'counts' => !$tailored && !$family,
            'came' => $came,
            'households' => $households,
            'camesentence' => get_string('panel_came_of_households', $c, $households),
            'peoplesentence' => get_string('panel_people_of', $c, (object) ['came' => $peoplecame, 'people' => $people]),
            'percent' => $people ? (int) round(100 * $peoplecame / $people) : 0,
            'tailored' => $tailored,
            'requests' => $requests,
            'hasrequests' => (bool) $requests,
            'own' => (bool) $family,
            'family' => $family ? panel::adults_line(households::full($family)->adults) : '',
            'familyurl' => $family ? (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $family->id]))->out(false) :
                '',
            'groups' => $list,
            'hassignups' => (bool) $households,
            'canact' => $canact,
            'addurl' => $canact && !$tailored && !$family ? (new \moodle_url(
                '/local/oksigeniaeguahe/edit.php',
                ['c' => $centre->id, 'eventid' => $event->id]
            ))->out(false) : '',
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'nowturn' => $nowturn,
            'checkurl' => !$tailored && !$family ? (new \moodle_url($url, ['check' => 1]))->out(false) : '',
            'checkevery' => 30,
            'labelsurl' => $households ? (new \moodle_url('/local/oksigeniaeguahe/labels.php', ['c' => $centre->id,
                'event' => $event->id]))->out(false) : '',
            'labelsname' => get_string('panel_labels', $c),
            'doorsound' => (new \moodle_url('/local/oksigeniaeguahe/pub/sonido/puerta'))->out(false),
            'qr' => events::qr_svg($event),
            'link' => events::short_url($event) ?? events::url($event)->out(false),
            'posterurl' => (new \moodle_url($url, ['poster' => 1]))->out(false),
            'posterurla3' => (new \moodle_url($url, ['poster' => 1, 'paper' => 'A3']))->out(false),
            'kind' => get_string($family ? 'visit_own' : ($tailored ? 'visit_asking' : 'event_kind_' . $event->kind), $c),
            'capacity' => $event->capacity ? get_string('panel_people', $c, (int) $event->capacity) : '',
            'slots' => $slots ? get_string('panel_slots', $c, count($slots)) : '',
            'questions' => $form ? format_string($form->name) : '',
            'open' => $event->open && $event->timeend >= $now,
            'editurl' => $canact ? (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id,
                'id' => $event->id]))->out(false) : '',
        ];
    }
}
