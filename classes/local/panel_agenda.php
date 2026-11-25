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
 * The agenda of the team: the week with its appointments, those that asked for one and have no day, and saying who came to
 * the ones that passed, several at once.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_agenda {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] How the appointments are looked at. */
    const VIEWS = ['semana', 'quincena', 'mes', 'agenda'];

    /** @var int How many days the list shows. */
    const LIST = 30;

    /** @var string The view each person left it in. */
    const PREF = 'local_oksigeniaeguahe_agendaview';

    /** @var int How many of those that passed are in sight on a file. */
    const LASTONES = 3;

    /** @var int How far back who came is asked, in days. */
    const BACK = 30;

    /** @var string[] The places the centre used last, offered. */
    const PLACES = 4;

    /**
     * The team's appointments: a week, two, a month or a list of what is ahead, with what there is in the other calendars of
     * the centre behind.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param string $week A day of it (YYYY-MM-DD); empty, today.
     * @param int $guide Only of whom receives; 0, of everyone.
     * @param int $now
     * @param string $view One of VIEWS.
     * @param bool $behind Whether what there is in the other calendars is shown.
     * @param string $only Only the appointments (citas) or the acts of the centre (actos); empty, everything.
     * @return array
     */
    public static function context(
        \stdClass $centre,
        bool $canact,
        string $week = '',
        int $guide = 0,
        int $now = 0,
        string $view = 'semana',
        bool $behind = true,
        string $only = ''
    ): array {
        $now = $now ?: time();
        $only = in_array($only, ['citas', 'actos'], true) ? $only : '';
        $seen = ['act' => false, 'visit' => false];
        $view = in_array($view, self::VIEWS, true) ? $view : 'semana';
        $zone = \core_date::get_server_timezone_object();
        $at = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $week, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ?
            new \DateTimeImmutable($week . ' 00:00', $zone) : (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0);
        [$start, $n, $before, $after] = self::span($view, $at);
        $days = appointments::days($centre, $start, $n, $now);
        $first = $view === 'mes' ? $at->modify('first day of this month')->getTimestamp() : $days[0]['day'];
        $last = $view === 'mes' ? $at->modify('last day of this month')->getTimestamp() : $days[$n - 1]['day'];
        $all = array_merge(...array_column($days, 'items'));
        $people = households::some((int) $centre->id, array_column($all, 'householdid'));
        $faces = self::faces($centre);
        $today = userdate($now, '%Y-%m-%d', 99, false);
        $keep = ($view === 'semana' ? [] : ['vista' => $view]) + array_filter(['que' => $only]);
        $url = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id] + $params + $keep))->out(false);
        $key = fn(int $time) => userdate($time, '%Y-%m-%d', $zone->getName(), false);
        $give = fn(array $more) => (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['c' => $centre->id] + $more))->out(false);
        $has = busy::has((int) $centre->id);
        $others = $has && $behind ? busy::by_day((int) $centre->id, array_column($days, 'day')) : [];
        // An act nobody has signed up to yet is there too.
        $empty = self::empty_acts($centre, $days[0]['day'], $days[$n - 1]['day'] + DAYSECS + HOURSECS, $faces);
        $out = [];
        foreach ($days as $i => $day) {
            $items = $guide ? array_filter($day['items'], fn($o) => $o['guideid'] === $guide) : $day['items'];
            $cards = self::cards($items, $people, $faces, $centre, $canact, $now);
            $ids = array_column(array_filter($cards, fn($card) => !empty($card['isact'])), 'id');
            foreach ($empty as $act) {
                if ($key($act['start']) === $key($day['day']) && !in_array($act['id'], $ids, true) && (!$guide || $act['guideid'] === $guide)) {
                    $cards[] = $act;
                }
            }
            usort($cards, fn($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));
            foreach ($cards as $card) {
                $seen[!empty($card['isact']) ? 'act' : 'visit'] = true;
            }
            if ($only !== '') {
                $cards = array_values(array_filter($cards, fn($card) => !empty($card['isact']) === ($only === 'actos')));
            }
            $other = $others[$day['day']] ?? [];
            if ($view === 'agenda' ? !$cards : ($i % 7 > 4 && $view === 'semana' && !$day['open'] && !$cards)) {
                continue;
            }
            $out[] = ['key' => $key($day['day']), 'weekday' => userdate($day['day'], '%a'), 'day' => userdate($day['day'], '%d', 99, true),
                'month' => userdate($day['day'], '%b'), 'today' => $key($day['day']) === $today, 'past' => $key($day['day']) < $today,
                'closed' => !$day['open'], 'items' => $cards, 'hasitems' => (bool) $cards, 'n' => $i,
                'out' => $view === 'mes' && ($day['day'] < $first || $day['day'] > $last),
                'behind' => $other, 'hasbehind' => (bool) $other, 'pieces' => self::weave($cards, $other, (int) $day['day']),
                'dayurl' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id, 'vista' => 'semana',
                    'semana' => $key($day['day'])] + array_filter(['guia' => $guide, 'que' => $only]), 'dia-' . $key($day['day'])))->out(false),
                // A weekend with an act in it may have an appointment too.
                'addurl' => $canact && ($day['open'] || $cards) && $key($day['day']) >= $today ?
                    $give(['dia' => $key($day['day'])]) : ''];
        }
        $calendars = [];
        foreach ($has && $behind ? busy::colours((int) $centre->id) : [] as $name => $colour) {
            if ($name !== '') {
                $calendars[] = ['name' => $name, 'color' => $colour];
            }
        }
        // The weekend, only when there is something in it: the month keeps its columns.
        $columns = 7;
        if (in_array($view, ['mes', 'quincena'], true) && !array_filter($out, fn($d) => $d['n'] % 7 > 4 && ($d['hasitems'] || !$d['closed']))) {
            $out = array_values(array_filter($out, fn($d) => $d['n'] % 7 < 5));
            $columns = 5;
        }
        $views = [];
        foreach (self::VIEWS as $one) {
            $views[] = ['key' => $one, 'name' => get_string('agenda_view_' . $one, self::C), 'current' => $one === $view,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id, 'vista' => $one] +
                    ($at->format('Y-m-d') === $today ? [] : ['semana' => $at->format('Y-m-d')]) + array_filter(['guia' => $guide, 'que' => $only])))->out(false)];
        }
        $kinds = [];
        foreach (['' => 'all', 'citas' => 'visits', 'actos' => 'acts'] as $one => $name) {
            $kinds[] = ['name' => get_string('agenda_kind_' . $name, self::C), 'current' => $one === $only,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id, 'semana' => $at->format('Y-m-d')] +
                    ($view === 'semana' ? [] : ['vista' => $view]) + array_filter(['guia' => $guide, 'que' => $one])))->out(false)];
        }
        $asked = [];
        $waiting = appointments::asked($centre);
        $askers = households::some((int) $centre->id, array_column($waiting, 'householdid'));
        foreach ($waiting as $one) {
            if (isset($askers[$one['householdid']])) {
                $asked[] = self::card($one, $askers[$one['householdid']], $faces, $canact, $now) + ['since' => get_string(
                    'agenda_since',
                    self::C,
                    panel::when_words($one['asked'], $now, true)
                ), 'suits' => $one['suits'] ?? ''];
            }
        }
        $topass = count(self::topass($centre, $now));
        $guides = [];
        foreach ($faces as $id => $face) {
            $guides[] = $face + ['id' => $id, 'current' => $id === $guide, 'url' => $url(array_filter(['semana' => $key($first),
                'guia' => $id === $guide ? 0 : $id]))];
        }
        return [
            'head' => ['action' => $canact ? ['url' => $give([]), 'name' => get_string('give_title', self::C), 'icon' => panel::icons()['mas1'] ?? ''] : false] + self::ahead(
                self::own($guide ? array_filter($all, fn($o) => $o['guideid'] === $guide) : $all, $centre),
                $people,
                $key($first) <= $today && $today <= $key($last),
                $now,
                $faces[$guide]['first'] ?? '',
                $view === 'semana'
            ),
            'title' => $view === 'mes' ? userdate($first, get_string('strftimemonthyear', 'langconfig')) : get_string(
                'agenda_title',
                self::C,
                (object) ['from' => userdate($first, userdate($first, '%m') === userdate($last, '%m') ? '%e' :
                    get_string('strftimedateshort', 'langconfig')), 'to' => userdate($last, get_string('strftimedateshort', 'langconfig'))]
            ),
            'prevurl' => $url(['semana' => $before->format('Y-m-d')] + array_filter(['guia' => $guide])),
            'nexturl' => $url(['semana' => $after->format('Y-m-d')] + array_filter(['guia' => $guide])),
            'todayurl' => $url([]),
            'views' => $views,
            // Everything, the appointments or the acts; offered where there are both.
            'kinds' => $kinds,
            'haskinds' => $only !== '' || ($seen['act'] && $seen['visit']),
            'view' => $view,
            'compact' => $view === 'mes',
            'columns' => $columns,
            'hasbehind' => $has,
            // Whose colour is whose.
            'calendars' => $calendars,
            'behindon' => $has && $behind,
            'behindurl' => $url(['fondo' => $behind ? 0 : 1, 'semana' => $at->format('Y-m-d')] + array_filter(['guia' => $guide])),
            // What was moved or deleted in the calendar of the team, for a person to say.
            'moved' => $moved = calsync::questions($centre, $canact, $now),
            'hasmoved' => (bool) $moved,
            // That the script that brings the other calendars stopped calling.
            'stopped' => calsync::stopped($centre, $now),
            'isnow' => $key($first) <= $today && $today <= $key($last),
            'days' => $out,
            'asked' => $asked,
            'hasasked' => (bool) $asked,
            'topass' => $topass ? ['n' => $topass, 'text' => get_string($topass === 1 ? 'agenda_topass_one' : 'agenda_topass', self::C, $topass),
                'url' => $url(['pasada' => 1])] : false,
            'guides' => $guides,
            'hasguides' => count($guides) > 1,
            'weekurl' => $url([]),
            'isweek' => true,
            'everyone' => !$guide,
            'everyoneurl' => $url(['semana' => $key($first)]),
            // What is said here of one that passed.
            'canact' => $canact,
            'markurl' => $url([]),
            'sesskey' => sesskey(),
            'undo' => '',
            'newurl' => $canact ? (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id, 'new' => 1]))->out(false) : '',
            'actsurl' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id, 'actos' => 1]))->out(false),
        ];
    }

    /**
     * A day by its hours: what there is in the other calendars, and over it the appointments that fall on it.
     *
     * @param array $cards Of the day, with their start and end.
     * @param array $others What there is behind: busy::by_day().
     * @param int $day Its start.
     * @return array[] Each with behind, items, over (an appointment on something), allday and color.
     */
    protected static function weave(array $cards, array $others, int $day): array {
        $pieces = [];
        $minute = fn(int $time) => intdiv($time - $day, MINSECS);
        foreach ($others as $other) {
            $pieces[] = ['at' => $other['allday'] ? -1 : $other['from'], 'behind' => [$other], 'items' => [], 'to' => $other['to'],
                'allday' => $other['allday'], 'color' => $other['color']];
        }
        foreach ($cards as $card) {
            $from = !empty($card['dayonly']) || empty($card['start']) ? -1 : $minute((int) $card['start']);
            $to = max($from + 1, $minute((int) ($card['end'] ?? 0)));
            $on = $from < 0 ? [] : array_keys(array_filter(
                $pieces,
                fn($p) => $p['behind'] && !$p['allday'] && $p['at'] < $to && $p['to'] > $from
            ));
            if (!$on) {
                $pieces[] = ['at' => $from, 'behind' => [], 'items' => [$card], 'to' => $to, 'allday' => false, 'color' => 0];
                continue;
            }
            // On several at once: they are one stretch.
            $first = array_shift($on);
            foreach ($on as $n) {
                $pieces[$first]['behind'] = array_merge($pieces[$first]['behind'], $pieces[$n]['behind']);
                $pieces[$first]['items'] = array_merge($pieces[$first]['items'], $pieces[$n]['items']);
                $pieces[$first]['at'] = min($pieces[$first]['at'], $pieces[$n]['at']);
                $pieces[$first]['to'] = max($pieces[$first]['to'], $pieces[$n]['to']);
                unset($pieces[$n]);
            }
            $pieces[$first]['items'][] = $card;
        }
        usort($pieces, fn($a, $b) => [$a['at'], $a['behind'] ? 0 : 1] <=> [$b['at'], $b['behind'] ? 0 : 1]);
        return array_map(fn($p) => $p + ['over' => $p['behind'] && $p['items']], $pieces);
    }

    /**
     * The acts of some days without their families, as cards.
     *
     * @param \stdClass $centre
     * @param int $from
     * @param int $to
     * @param array $faces
     * @return array[]
     */
    protected static function empty_acts(\stdClass $centre, int $from, int $to, array $faces): array {
        global $DB;
        $out = [];
        foreach (
            $DB->get_records_select(
                events::T,
                'centreid = ? AND cancelled = 0 AND tailored = 0 AND COALESCE(householdid, 0) = 0 AND timestart >= ? AND timestart < ?',
                [$centre->id, $from, $to],
                'timestart ASC'
            ) as $event
        ) {
            $guide = (int) (array_values(array_filter(array_map('intval', explode(',', (string) $event->hosts))))[0] ?? 0);
            $one = ['start' => (int) $event->timestart, 'end' => (int) $event->timeend, 'dayonly' => false, 'kind' => 'act',
                'title' => format_string($event->name), 'place' => format_string((string) $event->place), 'guideid' => $guide];
            $out[] = ['id' => 'a' . $event->id, 'isact' => true, 'families' => 0, 'people' => 0, 'came' => 0, 'topass' => 0, 'state' => '',
                'statename' => '', 'adults' => '', 'children' => [], 'moveurl' => '', 'offurl' => '', 'passtext' => '', 'passurl' => '',
                'familiestext' => get_string('cal_families', self::C, 0), 'start' => $one['start'], 'end' => $one['end'], 'guideid' => $guide,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false)] + self::of_act($event) +
                self::when($one) + self::where($one, $faces);
        }
        return $out;
    }

    /**
     * What tells an act apart from the appointment of one family: what it is (a talk, an open day) and the room it has.
     *
     * @param \stdClass|null $event
     * @return array word, room (a text, or empty), capacity, hasfill, fill (of it, in hundredths) and peopletext
     */
    protected static function of_act(?\stdClass $event): array {
        $room = $event ? (int) $event->capacity : 0;
        return ['word' => $event ? acts::team_word($event) : '', 'room' => $room ? get_string('cal_room', self::C, $room) : '',
            'capacity' => $room, 'hasfill' => $room > 0, 'fill' => 0, 'peopletext' => '', 'sheettext' => ''];
    }

    /**
     * The days of a view, and where the one before and the one after start.
     *
     * @param string $view
     * @param \DateTimeImmutable $at
     * @return array [first day, how many, a day of the one before, a day of the one after]
     */
    protected static function span(string $view, \DateTimeImmutable $at): array {
        $monday = $at->modify('-' . ((int) $at->format('N') - 1) . ' days');
        if ($view === 'mes') {
            $first = $at->modify('first day of this month');
            $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
            $weeks = (int) ceil(((int) $first->format('N') - 1 + (int) $first->format('t')) / 7);
            return [$start, $weeks * 7, $first->modify('-1 month'), $first->modify('+1 month')];
        }
        $n = ['semana' => 7, 'quincena' => 14, 'agenda' => self::LIST][$view];
        $start = $view === 'agenda' ? $at : $monday;
        return [$start, $n, $start->modify("-$n days"), $start->modify("+$n days")];
    }

    /**
     * The head of the week: this one says how many are ahead and which is the next; another, how many it has.
     *
     * @param array $all
     * @param \stdClass[] $people
     * @param bool $isnow
     * @param int $now
     * @param string $whose The name of whom they are only of; empty, everyone's.
     * @param bool $week Whether it is a week.
     * @return array
     */
    protected static function ahead(array $all, array $people, bool $isnow, int $now, string $whose = '', bool $week = true): array {
        // An act is one, however many families come to it.
        $seen = [];
        $all = array_filter($all, function ($o) use (&$seen) {
            $key = $o['kind'] === 'act' ? $o['eventid'] . '-' . $o['start'] : $o['id'];
            return !isset($seen[$key]) && ($seen[$key] = true);
        });
        // What is counted, by its name: the appointments, and the acts apart.
        $say = function (string $key, array $list): string {
            $acts = count(array_filter($list, fn($o) => $o['kind'] === 'act'));
            $n = count($list) - $acts;
            if (!$acts || !$n) {
                $key .= $acts ? '_acts' : '';
                $n = $n ?: $acts;
                return get_string($key . ($n === 0 ? '_none' : ($n === 1 ? '_one' : '')), self::C, $n);
            }
            return get_string($key . '_both', self::C, (object) ['n' => get_string($n === 1 ? 'agenda_n_one' : 'agenda_n', self::C, $n),
                'acts' => get_string($acts === 1 ? 'agenda_acts_one' : 'agenda_acts', self::C, $acts)]);
        };
        if (!$isnow) {
            return panel::head(['ante' => $whose, 'title' => $say('agenda_sub', $all)]);
        }
        $ahead = array_values(array_filter($all, fn($o) => $o['state'] === 'coming' && isset($people[$o['householdid']])));
        $title = $say($week ? 'agenda_ahead' : 'agenda_still', $ahead);
        if (!$ahead) {
            return panel::head(['ante' => $whose, 'title' => $title]);
        }
        $one = $ahead[0];
        $today = userdate($one['start'], '%Y-%m-%d', 99, false) === userdate($now, '%Y-%m-%d', 99, false);
        $weekday = preg_replace('/\s+/u', ' ', userdate($one['start'], '%A %e'));
        $when = ($today ? get_string('today', 'calendar') : $weekday) . ($one['dayonly'] ? '' : ', ' . panel::hour($one['start']));
        return panel::head(['ante' => $whose, 'title' => $title, 'sub' => get_string('agenda_ahead_next', self::C, (object) ['when' => \core_text::strtolower($when),
            'who' => $one['kind'] === 'act' ? $one['title'] : panel::adults_line($people[$one['householdid']]->adults)])]);
    }

    /**
     * Those that passed and nobody said whether they came. Not the acts, which say it at their door, dozens at once.
     *
     * @param \stdClass $centre
     * @param int $now
     * @param \stdClass|null $act Those of this act; null, every appointment but the acts.
     * @return array[]
     */
    public static function topass(\stdClass $centre, int $now, ?\stdClass $act = null): array {
        $from = $act ? (int) $act->timestart : $now - self::BACK * DAYSECS;
        $to = $act ? min($now, (int) $act->timeend + 1) : $now;
        return array_values(array_filter(
            appointments::agenda($centre, $from, $to, $now),
            fn($o) => $o['state'] === 'unknown' && ($act ? $o['kind'] === 'act' && $o['eventid'] === (int) $act->id : $o['kind'] !== 'act')
        ));
    }

    /**
     * Where it is said who came to an act.
     *
     * @param int $centreid
     * @param int $eventid
     * @return string
     */
    public static function passurl(int $centreid, int $eventid): string {
        return (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centreid, 'pasada' => 1, 'acto' => $eventid]))->out(false);
    }

    /**
     * Saying who came: each day with its appointments, the last one first.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param string $key Of what was just said, to take it back; empty, nothing.
     * @param int $now
     * @param \stdClass|null $act Those of an act, with those that came to it for sure out.
     * @return array
     */
    public static function pass(
        \stdClass $centre,
        bool $canact,
        string $key = '',
        int $now = 0,
        ?\stdClass $act = null
    ): array {
        $now = $now ?: time();
        $all = self::topass($centre, $now, $act);
        $people = households::some((int) $centre->id, array_column($all, 'householdid'));
        $faces = self::faces($centre);
        $days = [];
        foreach ($all as $one) {
            if (!isset($people[$one['householdid']])) {
                continue;
            }
            $day = userdate($one['start'], '%Y-%m-%d', \core_date::get_server_timezone(), false);
            $days[$day]['name'] = self::day($one['start']);
            $days[$day]['ids'][] = $one['id'];
            $days[$day]['items'][] = self::card($one, $people[$one['householdid']], $faces, $canact, $now);
        }
        krsort($days);
        foreach ($act ? array_keys($days) : [] as $day) {
            usort($days[$day]['items'], fn($x, $y) => strcoll($x['adults'], $y['adults']));
        }
        $n = count($all);
        $thanks = $act && surveys::THANKSUNTIL * DAYSECS > $now - (int) $act->timeend;
        return [
            'head' => panel::head(['title' => $act ? get_string('agenda_act_title', self::C, panel::quote(format_string($act->name))) :
                get_string('agenda_pass_title', self::C),
                'sub' => get_string(($act ? 'agenda_acttopass' : 'agenda_topass') . ($n === 1 ? '_one' : ''), self::C, $n),
                'back' => $act ? ['url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $act->id]))->out(false),
                    'name' => format_string($act->name)] : ['url' => (new \moodle_url(
                        '/local/oksigeniaeguahe/agenda.php',
                        ['c' => $centre->id]
                    ))->out(false), 'name' => get_string('tab_events', self::C)]]),
            'act' => (bool) $act,
            'thanks' => $thanks && $n ? get_string('agenda_pass_thanks', self::C) : '',
            'days' => array_map(
                fn($d) => $d + ['idlist' => implode(',', $d['ids']), 'n' => count($d['ids']), 'many' => count($d['ids']) > 1],
                array_values($days)
            ),
            'n' => $n,
            'none' => !$all,
            'canact' => $canact,
            'undo' => $key,
            'markurl' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id, 'pasada' => 1] +
                ($act ? ['acto' => $act->id] : [])))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * What was said of some, in a sentence.
     *
     * @param int $n
     * @param string $state
     * @return string
     */
    public static function said(int $n, string $state): string {
        return get_string('agenda_said_' . $state . ($n === 1 ? '_one' : ''), self::C, $n);
    }

    /**
     * The people of the team, any of whom may receive a family: those the families see, with the face they know.
     *
     * @param \stdClass $centre
     * @return array Member id => first, name, picture and initials.
     */
    public static function faces(\stdClass $centre): array {
        $out = [];
        foreach (centres::members((int) $centre->id) as $user) {
            $out[(int) $user->memberid] = panel::person($user);
        }
        foreach (centres::hosts((int) $centre->id) as $host) {
            if ($host->photo && isset($out[(int) $host->memberid])) {
                $out[(int) $host->memberid]['picture'] = (string) $host->photo;
            }
        }
        return $out;
    }

    /**
     * The cards of a day: an act with many families is one.
     *
     * @param array $items
     * @param \stdClass[] $people
     * @param array $faces
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array[]
     */
    protected static function cards(array $items, array $people, array $faces, \stdClass $centre, bool $canact, int $now): array {
        $out = [];
        $acts = [];
        foreach ($items as $one) {
            if (!isset($people[$one['householdid']])) {
                continue;
            }
            if ($one['kind'] !== 'act') {
                $out[] = self::card($one, $people[$one['householdid']], $faces, $canact, $now);
                continue;
            }
            // One card for the act, at its hour, with how many come.
            $key = $one['eventid'] . '-' . $one['start'];
            if (!isset($acts[$key])) {
                $acts[$key] = count($out);
                $out[] = ['id' => 'a' . $one['eventid'], 'isact' => true, 'families' => 0, 'people' => 0, 'came' => 0, 'topass' => 0, 'state' => '',
                    'start' => $one['start'], 'end' => $one['end'], 'statename' => '', 'adults' => '', 'children' => [], 'moveurl' => '', 'offurl' => '', 'url' => (new \moodle_url(
                        '/local/oksigeniaeguahe/event.php',
                        ['id' => $one['eventid']]
                    ))->out(false)] + self::of_act(events::get((int) $one['eventid'], (int) $centre->id) ?: null) + self::when($one) +
                    self::where($one, $faces);
            }
            $act = &$out[$acts[$key]];
            $act['families']++;
            $act['people'] += $one['people'];
            $act['came'] += $one['state'] === 'came' ? 1 : 0;
            $act['topass'] += $one['state'] === 'unknown' ? 1 : 0;
            $act['passtext'] = $canact && $act['topass'] ? get_string('agenda_acttopass' . ($act['topass'] === 1 ? '_one' : ''), self::C, $act['topass']) : '';
            $act['passurl'] = $act['passtext'] !== '' ? self::passurl((int) $centre->id, (int) $one['eventid']) : '';
            $act['familiestext'] = get_string($act['families'] === 1 ? 'cal_families_one' : 'cal_families', self::C, $act['families']);
            $act['peopletext'] = get_string($act['people'] === 1 ? 'cal_people_one' : 'cal_people', self::C, $act['people']);
            $act['hasfill'] = $act['capacity'] > 0;
            $act['fill'] = $act['capacity'] ? (int) min(100, round(100 * $act['people'] / $act['capacity'])) : 0;
            unset($act);
        }
        return self::fold($out, $items, $centre);
    }

    /**
     * The appointments and the acts, without those the sheet of the team has on the day of an open day: they come to it.
     *
     * @param array $items As appointments::agenda() gives them.
     * @param \stdClass $centre
     * @return array
     */
    protected static function own(array $items, \stdClass $centre): array {
        $day = fn(int $time) => userdate($time, '%Y-%m-%d', 99, false);
        $open = [];
        foreach ($items as $one) {
            if ($one['kind'] === 'act' && !isset($open[$one['eventid']])) {
                $event = events::get((int) $one['eventid'], (int) $centre->id);
                $open[$one['eventid']] = $event && $event->kind === 'openday' && !(int) $event->cancelled ? $day($one['start']) : '';
            }
        }
        $open = array_filter($open);
        return $open ? array_filter($items, fn($o) => $o['kind'] !== 'note' || $o['source'] !== 'sheet' || !$o['dayonly'] ||
            !in_array($day($o['start']), $open, true)) : $items;
    }

    /**
     * Those the sheet of the team has on the day of an open day, without an hour: they come to it, and are in its card.
     *
     * @param array[] $cards
     * @param array $items As appointments::agenda() gives them.
     * @param \stdClass $centre
     * @return array[]
     */
    protected static function fold(array $cards, array $items, \stdClass $centre): array {
        $open = null;
        foreach ($cards as $i => $card) {
            $event = !empty($card['isact']) ? events::get((int) substr($card['id'], 1), (int) $centre->id) : null;
            if ($event && $event->kind === 'openday' && !(int) $event->cancelled) {
                $open = $open ?? $i;
            }
        }
        if ($open === null) {
            return $cards;
        }
        $signed = [];
        $sheet = [];
        foreach ($items as $one) {
            if ($one['kind'] === 'act') {
                $signed[$one['householdid']] = true;
            } else if ($one['kind'] === 'note' && $one['source'] === 'sheet' && $one['dayonly']) {
                $sheet[$one['id']] = $one['householdid'];
            }
        }
        $more = count(array_unique(array_filter($sheet, fn($id) => !isset($signed[$id]))));
        $cards[$open]['sheet'] = $more;
        $cards[$open]['sheettext'] = $more ? get_string($more === 1 ? 'cal_act_sheet_one' : 'cal_act_sheet', self::C, $more) : '';
        return array_values(array_filter($cards, fn($card) => !isset($sheet[$card['id']])));
    }

    /**
     * An appointment of a family, as a card.
     *
     * @param array $one As appointments::agenda() gives it.
     * @param \stdClass $household With its people.
     * @param array $faces
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function card(array $one, \stdClass $household, array $faces, bool $canact, int $now): array {
        $file = new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
        $give = fn(array $more) => (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $household->id] + $more))->out(false);
        $own = $canact && $one['kind'] === 'visit' && $one['source'] === 'eguahe' && $one['state'] === 'coming';
        $children = [];
        foreach ($household->children as $child) {
            if ($child->stage !== 'closed' && panel::tag($child) !== '') {
                $children[] = ['tag' => panel::tag($child), 'course' => !empty($child->offer) ? format_string($child->offer->name) : ''];
            }
        }
        return [
            'id' => $one['id'],
            'isact' => false,
            'adults' => panel::adults_line($household->adults) ?: get_string('chat_family_noname', self::C),
            'children' => $children,
            'kind' => $one['kind'],
            'state' => $one['state'],
            'is' . $one['state'] => true,
            'statename' => get_string('agenda_state_' . $one['state'], self::C),
            'source' => $one['source'],
            'sourcename' => $one['source'] === 'eguahe' ? '' : get_string('agenda_source_' . $one['source'], self::C),
            'people' => $one['people'],
            'peopletext' => $one['people'] > 0 && $one['kind'] !== 'note' ?
                get_string($one['people'] === 1 ? 'panel_people_one' : 'panel_people', self::C, $one['people']) : '',
            'url' => $file->out(false),
            'moveurl' => $own ? $give(['id' => $one['eventid']]) : ($canact && $one['state'] === 'asked' ? $give([]) : ''),
            'offurl' => $own ? $give(['id' => $one['eventid'], 'anular' => 1]) : '',
            'twin' => false,
            'acttitle' => $one['kind'] === 'act' ? $one['title'] : '',
            'start' => (int) $one['start'],
            'end' => $one['kind'] === 'note' ? (int) $one['start'] : (int) ($one['end'] ?? 0),
        ] + self::when($one) + self::where($one, $faces);
    }

    /**
     * A day as it is said, without its year: «jueves 26 de noviembre».
     *
     * @param int $time
     * @return string
     */
    public static function day(int $time): string {
        $lang = current_language();
        return publicpage::date_parts($time, in_array($lang, lists::LANGS, true) ? $lang : 'en')['long'];
    }

    /**
     * When one is, for its card.
     *
     * @param array $one
     * @return array
     */
    protected static function when(array $one): array {
        return ['hour' => $one['start'] && !$one['dayonly'] ? panel::hour($one['start']) : '',
            'hourend' => $one['start'] && !$one['dayonly'] && $one['kind'] !== 'note' ? panel::hour($one['end']) : '',
            'dayonly' => $one['dayonly'], 'day' => $one['start'] ? self::day($one['start']) : '',
            // As the leaf of a calendar.
            'wday' => $one['start'] ? userdate($one['start'], '%a') : '', 'dnum' => $one['start'] ? userdate($one['start'], '%e') : '',
            'mon' => $one['start'] ? userdate($one['start'], '%b') : ''];
    }

    /**
     * Where and with whom one is, for its card.
     *
     * @param array $one
     * @param array $faces
     * @return array
     */
    protected static function where(array $one, array $faces): array {
        return ['title' => $one['title'], 'place' => $one['place'], 'guide' => $faces[$one['guideid']] ?? false];
    }

    /**
     * The appointments of a family, for its file, with the pairs that may be one.
     *
     * @param \stdClass $full households::full()
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array appointments, hasappointments, twins, hastwins and giveurl
     */
    public static function of_family(\stdClass $full, \stdClass $centre, bool $canact, int $now): array {
        $faces = self::faces($centre);
        $groups = ['coming' => [], 'asked' => [], 'unknown' => [], 'past' => []];
        foreach (appointments::of($full, $now) as $one) {
            // The year, only of those of another one.
            $year = $one['start'] ? userdate($one['start'], '%Y') : '';
            $groups[isset($groups[$one['state']]) ? $one['state'] : 'past'][] = self::card($one, $full, $faces, $canact, $now) +
                ['year' => $year === userdate($now, '%Y') ? '' : $year];
        }
        $past = array_reverse($groups['past']);
        // What is still ahead or to be said, whole; of what passed, the last ones.
        $ahead = array_merge($groups['coming'], $groups['asked'], array_reverse($groups['unknown']));
        $cards = array_merge($ahead, array_slice($past, 0, self::LASTONES));
        $older = array_slice($past, self::LASTONES);
        $twins = [];
        foreach ($canact ? appointments::twins($full, $now) : [] as $pair) {
            $twins[] = ['a' => self::card($pair['a'], $full, $faces, false, $now), 'b' => self::card($pair['b'], $full, $faces, false, $now),
                'days' => $pair['days'], 'daystext' => get_string('agenda_twindays' . ($pair['days'] < 2 ? '_' . $pair['days'] : ''), self::C, $pair['days']),
                'keepa' => $pair['a']['id'] . ',' . $pair['b']['id'],
                'keepb' => $pair['b']['id'] . ',' . $pair['a']['id'], 'apart' => $pair['a']['id'] . ',' . $pair['b']['id'],
                'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $full->id]))->out(false), 'sesskey' => sesskey()];
        }
        $moved = calsync::questions($centre, $canact, $now, (int) $full->id);
        return ['canact' => $canact, 'sesskey' => sesskey(), 'markurl' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id]))->out(false),
            'moved' => $moved, 'hasmoved' => (bool) $moved,
            'appointments' => $cards, 'hasappointments' => (bool) $cards,
            'older' => $older, 'hasolder' => (bool) $older, 'twins' => $twins, 'hastwins' => (bool) $twins,
            'giveurl' => $canact ? (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $full->id]))->out(false) : ''];
    }
}
