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
 * The first day (start.php): the enrolled children of the school year by course, each with «Started» at one touch, as
 * the door of an open day. Whoever did not start is the first loss after the campaign, caught when it matters; the
 * count is the first honest number of what comes after: «68 of the 71 enrolled started».
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_start {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The context of the template panel_start.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact): array {
        $c = self::C;
        $centreid = (int) $centre->id;
        $year = settled::year($centreid);
        $courses = $year !== '' ? settled::first_day($centreid, $year) : [];
        $action = (new \moodle_url('/local/oksigeniaeguahe/start.php', ['c' => $centreid]))->out(false);
        $enrolled = 0;
        $started = 0;
        $groups = [];
        $now = time();
        $asking = [];
        foreach (firstdays::asking($centre, $now) as $ask) {
            $asking[(int) $ask['offer']->id] = $ask['kind'];
        }
        $format = get_string('strftimedaydate', 'langconfig');
        foreach ($courses as $course) {
            $rows = [];
            $done = 0;
            foreach ($course['children'] as $child) {
                $on = (int) $child->started > 0;
                $done += $on ? 1 : 0;
                $adults = panel::adults_line($child->adults);
                $rows[] = [
                    'id' => (int) $child->id,
                    'tag' => panel::tag($child),
                    'name' => (string) $child->firstname,
                    'adults' => $adults,
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $child->householdid]))->out(false),
                    'started' => $on,
                    'text' => $on ? get_string('panel_firstday_done', $c, panel::hour((int) $child->started)) :
                        get_string('panel_firstday_mark', $c),
                    'search' => \core_text::strtolower($child->firstname . ' ' . $child->tagname . ' ' . $adults),
                ];
            }
            $enrolled += count($rows);
            $started += $done;
            $offer = $course['offer'];
            $first = firstdays::of($offer, $centre);
            $own = (int) $offer->firstday > 0;
            $kind = $asking[(int) $offer->id] ?? '';
            $groups[] = [
                'title' => format_string($offer->name),
                'count' => get_string('panel_firstday_of', $c, (object) ['started' => $done, 'enrolled' => count($rows)]),
                'rows' => $rows,
                // Its first day: written, the general one of the centre, or none yet; and whether to say it holds.
                'offerid' => (int) $offer->id,
                'daytext' => $first ? get_string('panel_firstday_course_on', $c, userdate($first, $format)) :
                    get_string('panel_firstday_course_none', $c),
                'general' => $first && !$own,
                'confirmed' => $own && (int) $offer->firstdayok && $kind !== 'check' ?
                    get_string('panel_firstday_course_confirmed', $c, userdate((int) $offer->firstdayok, $format)) : '',
                'missing' => !$first,
                'check' => $kind === 'check',
                'asked' => $kind !== '',
                'value' => $first ? userdate($first, '%Y-%m-%d', 99, false) : '',
                'date' => ['labelled' => false, 'arialabel' => get_string(
                    'panel_firstday_course_label',
                    $c,
                    format_string($offer->name)
                )] + panel::date_input(
                    'day',
                    $first ? userdate($first, '%Y-%m-%d', 99, false) : '',
                    $centre,
                    'pd-' . (int) $offer->id
                ),
                // The leaf of a calendar for its day, as the next open days of the inbox; none while it has no day.
                'leaf' => $first ? panel::leaf($first) : false,
                'label' => get_string('panel_firstday_course_label', $c, format_string($offer->name)),
            ];
        }
        return [
            'head' => panel::head([
                // The «What is this?» of a name of its own, said in one paragraph.
                'what' => get_string('panel_isthis_start', self::C),
                'title' => get_string('panel_firstday', $c),
                'ante' => $year !== '' ? get_string('panel_firstday_year', $c, s($year)) : '',
                'sub' => !$enrolled ? get_string('panel_firstday_none', $c) : ($started ?
                    get_string('panel_firstday_count', $c, (object) ['started' => $started, 'enrolled' => $enrolled]) :
                    get_string('panel_firstday_sub', $c)),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]))->out(false),
                    'name' => get_string('tab_inbox', $c)],
            ]),
            'groups' => $groups,
            'hasgroups' => (bool) $groups,
            'canact' => $canact,
            'action' => $action,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The link of the inbox to the first day, once anyone is enrolled.
     *
     * @param int $centreid
     * @return array|false url, text.
     */
    public static function link(int $centreid) {
        $year = settled::year($centreid);
        if ($year === '') {
            return false;
        }
        $counts = settled::started($centreid, $year);
        $url = (new \moodle_url('/local/oksigeniaeguahe/start.php', ['c' => $centreid]))->out(false);
        // What a person has to say about the first days now: the date of a course, or whether it holds.
        $asks = [];
        $format = get_string('strftimedaydate', 'langconfig');
        $centre = centres::get($centreid);
        foreach ($centre ? firstdays::asking($centre, time()) : [] as $ask) {
            $name = format_string($ask['offer']->name);
            $missing = $ask['kind'] === 'missing';
            $asks[] = ['url' => $url . '#curso' . (int) $ask['offer']->id, 'text' => $missing ?
                get_string('panel_firstday_ask_missing', self::C, $name) :
                get_string('panel_firstday_ask_check', self::C, (object) ['course' => $name,
                    'day' => userdate($ask['day'], $format)]),
                // In the inbox each question is a row as the next open days: the leaf of its day (blank without one),
                // what is asked and, under it, a quiet line.
                'leaf' => $missing ? false : panel::leaf((int) $ask['day']),
                'title' => $missing ? get_string('panel_firstday_ask_missing', self::C, $name) : $name,
                'sub' => $missing ? get_string('panel_firstday_course_none', self::C) :
                    get_string('panel_firstday_course_check', self::C)];
        }
        return [
            'url' => $url,
            'text' => $counts ? get_string('panel_firstday_link_count', self::C, (object) $counts) :
                get_string('panel_firstday_link', self::C),
            'asks' => $asks,
            'hasasks' => (bool) $asks,
        ];
    }
}
