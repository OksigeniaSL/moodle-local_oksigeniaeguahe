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

    /** @var int How many of those that passed are in sight on a file. */
    const LASTONES = 3;

    /** @var int How far back who came is asked, in days. */
    const BACK = 30;

    /** @var string[] The places the centre used last, offered. */
    const PLACES = 4;

    /**
     * The week.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param string $week A day of it, as YYYY-MM-DD; empty, this one.
     * @param int $guide Only those of this member of the team; 0, everyone's.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, string $week = '', int $guide = 0, int $now = 0): array {
        $now = $now ?: time();
        $zone = \core_date::get_server_timezone_object();
        $at = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? (new \DateTimeImmutable($week . ' 12:00', $zone))->getTimestamp() : $now;
        $days = appointments::week($centre, $at, $now);
        $first = $days[0]['day'];
        $last = $days[6]['day'];
        $all = array_merge(...array_column($days, 'items'));
        $people = households::some((int) $centre->id, array_column($all, 'householdid'));
        $faces = self::faces($centre);
        $today = userdate($now, '%Y-%m-%d', 99, false);
        $url = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id] + $params))->out(false);
        $key = fn(int $time) => userdate($time, '%Y-%m-%d', $zone->getName(), false);
        $give = fn(array $more) => (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['c' => $centre->id] + $more))->out(false);
        $until = $key($now + (panel_give::DAYS - 1) * DAYSECS);
        $out = [];
        foreach ($days as $i => $day) {
            $items = $guide ? array_filter($day['items'], fn($o) => $o['guideid'] === $guide) : $day['items'];
            $cards = self::cards($items, $people, $faces, $centre, $canact, $now);
            if ($i > 4 && !$day['open'] && !$cards) {
                continue;
            }
            $out[] = ['key' => $key($day['day']), 'weekday' => userdate($day['day'], '%a'), 'day' => userdate($day['day'], '%d', 99, true),
                'month' => userdate($day['day'], '%b'), 'today' => $key($day['day']) === $today, 'past' => $key($day['day']) < $today,
                'closed' => !$day['open'], 'items' => $cards, 'hasitems' => (bool) $cards,
                'addurl' => $canact && $day['open'] && $key($day['day']) >= $today && $key($day['day']) <= $until ?
                    $give(['dia' => $key($day['day'])]) : ''];
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
                $guide ? array_filter($all, fn($o) => $o['guideid'] === $guide) : $all,
                $people,
                $key($first) <= $today && $today <= $key($last),
                $now,
                $faces[$guide]['first'] ?? ''
            ),
            'title' => get_string('agenda_title', self::C, (object) ['from' => userdate($first, '%e'),
                'to' => userdate($last, get_string('strftimedateshort', 'langconfig'))]),
            'prevurl' => $url(['semana' => $key($first - 2 * DAYSECS)]),
            'nexturl' => $url(['semana' => $key($last + 2 * DAYSECS)]),
            'todayurl' => $url([]),
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
     * The head of the week: this one says how many are ahead and which is the next; another, how many it has.
     *
     * @param array $all
     * @param \stdClass[] $people
     * @param bool $isnow
     * @param int $now
     * @param string $whose The name of whom they are only of; empty, everyone's.
     * @return array
     */
    protected static function ahead(array $all, array $people, bool $isnow, int $now, string $whose = ''): array {
        // An act is one, however many families come to it.
        $seen = [];
        $all = array_filter($all, function ($o) use (&$seen) {
            $key = $o['kind'] === 'act' ? $o['eventid'] . '-' . $o['start'] : $o['id'];
            return !isset($seen[$key]) && ($seen[$key] = true);
        });
        if (!$isnow) {
            $n = count($all);
            return panel::head(['ante' => $whose, 'title' => get_string('agenda_sub' . ($n === 0 ? '_none' : ($n === 1 ? '_one' : '')), self::C, $n)]);
        }
        $ahead = array_values(array_filter($all, fn($o) => $o['state'] === 'coming' && isset($people[$o['householdid']])));
        $title = get_string(!$ahead ? 'agenda_ahead_none' : (count($ahead) === 1 ? 'agenda_ahead_one' : 'agenda_ahead'), self::C, count($ahead));
        if (!$ahead) {
            return panel::head(['ante' => $whose, 'title' => $title]);
        }
        $one = $ahead[0];
        $today = userdate($one['start'], '%Y-%m-%d', 99, false) === userdate($now, '%Y-%m-%d', 99, false);
        $when = ($today ? get_string('today', 'calendar') : userdate($one['start'], '%A %e')) . ($one['dayonly'] ? '' : ', ' . panel::hour($one['start']));
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
                    'statename' => '', 'adults' => '', 'children' => [], 'moveurl' => '', 'offurl' => '', 'url' => (new \moodle_url(
                        '/local/oksigeniaeguahe/event.php',
                        ['id' => $one['eventid']]
                    ))->out(false)] + self::when($one) + self::where($one, $faces);
            }
            $act = &$out[$acts[$key]];
            $act['families']++;
            $act['people'] += $one['people'];
            $act['came'] += $one['state'] === 'came' ? 1 : 0;
            $act['topass'] += $one['state'] === 'unknown' ? 1 : 0;
            $act['passtext'] = $canact && $act['topass'] ? get_string('agenda_acttopass' . ($act['topass'] === 1 ? '_one' : ''), self::C, $act['topass']) : '';
            $act['passurl'] = $act['passtext'] !== '' ? self::passurl((int) $centre->id, (int) $one['eventid']) : '';
            $act['familiestext'] = get_string($act['families'] === 1 ? 'cal_families_one' : 'cal_families', self::C, $act['families']);
            unset($act);
        }
        return $out;
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
        return ['canact' => $canact, 'sesskey' => sesskey(), 'markurl' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id]))->out(false),
            'appointments' => $cards, 'hasappointments' => (bool) $cards,
            'older' => $older, 'hasolder' => (bool) $older, 'twins' => $twins, 'hastwins' => (bool) $twins,
            'giveurl' => $canact ? (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => $full->id]))->out(false) : ''];
    }
}
