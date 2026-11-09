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
 * What the inbox of the panel shows, whatever it looks like: how many families wait and since when, in words; each
 * family with its adults, what they said last, how and when, and its children by the name of their label; the visits
 * of the person; and the places of each course.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_inbox {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How many visits of the person are shown. */
    const VISITS = 6;

    /** @var int From how many families on, the list is a ledger, one line each, unless the person chose. */
    const LEDGER = 20;

    /** @var string The preference of the person: the list as cards or as a ledger. */
    const DENSITY = 'local_oksigeniaeguahe_density';

    /** @var int Every how many seconds the page asks whether a family arrived. */
    const CHECK = 60;

    /**
     * The context of the inbox.
     *
     * @param \stdClass $centre
     * @param bool $all Every family, not only those that wait.
     * @param int $userid Who looks.
     * @param bool $canact Whether they may write down a family.
     * @param array $view What the person chose: course (an offer id), stage, density («fichas» or «lista»).
     * @param int $now
     * @return array
     */
    public static function context(
        \stdClass $centre,
        bool $all,
        int $userid,
        bool $canact,
        array $view = [],
        int $now = 0
    ): array {
        global $DB;
        $now = $now ?: time();
        $c = self::C;
        $centreid = (int) $centre->id;
        $course = (int) ($view['course'] ?? 0);
        $stage = in_array($view['stage'] ?? '', lists::STAGES, true) ? $view['stage'] : '';
        $waiting = households::waiting_count($centreid);
        $total = $DB->count_records(households::T, ['centreid' => $centreid]);
        // The households with a brother or sister whose turn it is, and only them when asked.
        $due = siblings::due($centre, $now);
        $onlysiblings = !empty($view['siblings']) && $due;
        $onlyvolunteers = !empty($view['volunteers']);
        // The families with something to write around the first day today, and only them when asked.
        $starting = self::starting($centre, $now);
        $onlystarting = !empty($view['starting']) && $starting;
        $list = households::inbox($centreid, !$all && !$onlysiblings && !$onlyvolunteers && !$onlystarting);
        if ($onlystarting) {
            $list = array_intersect_key($list, $starting);
        }
        if ($onlysiblings) {
            $list = array_intersect_key($list, $due);
        }
        if ($onlyvolunteers) {
            // The families that offered to tell another family how it went.
            $list = array_filter($list, fn($household) => (int) ($household->volunteer ?? 0) > 0);
        }
        $list = array_filter($list, function ($household) use ($course, $stage) {
            foreach ($course || $stage ? $household->children : [null] as $child) {
                if (!$child || ((!$course || (int) $child->offerid === $course) && (!$stage || $child->stage === $stage))) {
                    return true;
                }
            }
            return false;
        });
        $days = self::days($list, $centreid, $now, $userid);
        $fresh = $total === 0;

        // The sentence of the page, and the line under it.
        if ($fresh) {
            $title = get_string('panel_inbox_fresh', $c);
            $sub = get_string('panel_inbox_fresh_sub', $c);
        } else if (!$waiting) {
            $title = get_string('inbox_empty_waiting', $c);
            $sub = self::last_answer($centreid, $now);
        } else {
            $first = $DB->get_field_select(households::T, 'MIN(waitingsince)', 'centreid = ? AND waitingsince > 0', [$centreid]);
            $title = get_string($waiting === 1 ? 'inbox_waiting_one' : 'inbox_waiting', $c, $waiting);
            $sub = get_string('panel_inbox_longest', $c, panel::wait_words((int) $first, $now)['since']);
        }

        // The list as cards or as a ledger: what the person chose, else by how many families there are.
        $density = in_array($view['density'] ?? '', ['fichas', 'lista'], true) ? $view['density'] :
            (string) get_user_preferences(self::DENSITY, '', $userid);
        $density = in_array($density, ['fichas', 'lista'], true) ? $density : (count($list) >= self::LEDGER ? 'lista' : 'fichas');
        $base = ['c' => $centreid] + ($all ? ['all' => 1] : []) + ($course ? ['curso' => $course] : []) +
            ($stage ? ['etapa' => $stage] : []);
        $url = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/index.php', $params))->out(false);
        $places = self::places($centre);
        $next = self::next_events($centre, $now);
        // The living hall: the families that wait hanging from the rail, the card of the queue on its wall.
        $hall = false;
        if (vestibulo::on($centre)) {
            $queue = households::inbox($centreid, true, 60);
            $card = vestibulo::card($queue, $now);
            $hall = ['json' => json_encode(vestibulo::hall($centre, $queue, $now), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'base' => (new \moodle_url('/local/oksigeniaeguahe/'))->out(false), 'card' => $card, 'hascard' => (bool) $card];
        }
        // The cinematic skin: the river of the week, the families waiting as lanterns.
        $cine = cinematico::on($centre) ? cinematico::river($centre, households::inbox($centreid, true, 60), $userid, $now) :
            false;
        return [
            'hall' => $hall,
            'cine' => $cine,
            'head' => panel::head([
                'title' => $title,
                'sub' => $sub,
                'action' => $canact ? ['url' => (new \moodle_url(
                    '/local/oksigeniaeguahe/edit.php',
                    ['c' => $centreid]
                ))->out(false),
                    'name' => get_string('household_new', $c), 'icon' => panel::icon('mas1')] : null,
            ]),
            'fresh' => $fresh,
            'showtools' => !$fresh,
            'all' => $all,
            'waiting' => $waiting,
            'total' => $total,
            'waitingurl' => $url(array_diff_key($base, ['all' => 1])),
            'allurl' => $url($base + ['all' => 1]),
            'density' => $density,
            'fichas' => $density === 'fichas',
            'lista' => $density === 'lista',
            'densityurls' => ['fichas' => $url($base + ['vista' => 'fichas']), 'lista' => $url($base + ['vista' => 'lista'])],
            'checkurl' => $url(['c' => $centreid, 'check' => 1]),
            'checkevery' => self::CHECK,
            'filters' => self::filters($centre, $base, $course, $stage),
            'days' => $days,
            'hasrows' => (bool) $days,
            'emptybag' => panel::hanging(0, '', '', $centre),
            'emptytitle' => get_string($fresh ? 'panel_inbox_fresh_empty' : 'panel_inbox_upto', $c),
            'emptytext' => get_string($fresh ? 'panel_inbox_fresh_text' : 'panel_inbox_upto_text', $c),
            'newevent' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centreid, 'new' => 1]))->out(false),
            'newform' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centreid]))->out(false),
            'year' => $places['year'],
            'places' => $places['list'],
            'hasplaces' => (bool) $places['list'],
            'path' => self::path($centreid),
            'firstday' => panel_start::link($centreid),
            'siblingsdue' => $due ? ['text' => get_string(
                count($due) === 1 ? 'panel_siblings_due_one' : 'panel_siblings_due',
                $c,
                (object) ['n' => count($due),
                'year' => siblings::year_label(
                    $centre,
                    siblings::campaign_year($centre, $now)
                )]
            ),
                'url' => $url(['c' => $centreid, 'hermanos' => 1]), 'on' => $onlysiblings,
                'allurl' => $url(['c' => $centreid])] : false,
            'startingdue' => $starting ? ['text' => get_string(
                count($starting) === 1 ? 'panel_story_inbox_one' : 'panel_story_inbox_text',
                $c,
                count($starting)
            ), 'url' => $url(['c' => $centreid, 'primerdia' => 1]), 'on' => $onlystarting,
                'allurl' => $url(['c' => $centreid])] : false,
            'nextevents' => $next,
            'hasnextevents' => (bool) $next,
            'keyhint' => get_string('panel_keyhint', $c),
        ];
    }

    /**
     * The households of a centre with something for a person to write around the first day today.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array household id => the step.
     */
    protected static function starting(\stdClass $centre, int $now): array {
        global $DB;
        $ids = $DB->get_fieldset_select(
            households::CHILDREN,
            'DISTINCT householdid',
            "centreid = ? AND stage = 'enrolled' AND lefttime = 0",
            [$centre->id]
        );
        $out = [];
        foreach ($ids as $id) {
            $household = households::get((int) $id, (int) $centre->id);
            $step = $household ? story::step($household, $centre, $now) : null;
            if ($step) {
                $out[(int) $id] = $step['step'];
            }
        }
        return $out;
    }

    /**
     * What the page asks every minute: how many wait, and the family that arrived last.
     *
     * @param \stdClass $centre
     * @return array waiting, latest (id, adults, listurl) or null.
     */
    public static function check(\stdClass $centre): array {
        global $DB;
        $centreid = (int) $centre->id;
        $latest = $DB->get_records_select(
            households::T,
            'centreid = ? AND waitingsince > 0',
            [$centreid],
            'waitingsince DESC, id DESC',
            'id',
            0,
            1
        );
        $latest = reset($latest);
        $household = $latest ? households::full(households::get((int) $latest->id, $centreid)) : null;
        return [
            'waiting' => households::waiting_count($centreid),
            'latest' => $household ? ['id' => (int) $household->id, 'adults' => panel::adults_line($household->adults),
                'listurl' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]))->out(false),
                // For the living hall: what of theirs comes in through the door.
                'children' => vestibulo::children(
                    array_values($household->children),
                    centres::offers($centreid),
                    '',
                    $centre
                )] : null,
        ];
    }

    /**
     * The list by the rule of time: on top the families that come today and what was promised and is due (or was
     * promised by whoever is away); then those that wait, each day written once and its families under it, the one
     * waiting longest first; and, in «All», the ones answered after a line.
     *
     * @param array $list Households, as households::inbox() gives them.
     * @param int $centreid
     * @param int $now
     * @param int $userid Who looks.
     * @return array
     */
    public static function days(array $list, int $centreid, int $now, int $userid = 0): array {
        $c = self::C;
        $today = self::coming_today($centreid, $now);
        $due = attend::due($centreid, $now);
        $groups = [];
        $rows = self::rows($list, $now, $today, $userid, $due);
        foreach ($list as $id => $household) {
            $row = $rows[$id];
            if (isset($due[$id])) {
                $key = 'prometido';
                $groups[$key] = $groups[$key] ?? ['key' => $key, 'kind' => 'prometido',
                    'day' => get_string('panel_group_promised', $c), 'sub' => '', 'answered' => false, 'rows' => []];
            } else if (isset($today[$id])) {
                $key = 'hoy';
                $groups[$key] = $groups[$key] ?? ['key' => $key, 'kind' => 'hoy', 'day' => get_string('panel_day_today', $c),
                    'sub' => get_string('panel_coming_today', $c), 'answered' => false, 'rows' => []];
            } else if ($household->waitingsince > 0) {
                $days = panel::days_between((int) $household->waitingsince, $now);
                $key = 'espera' . $days;
                $groups[$key] = $groups[$key] ?? ['key' => $key, 'kind' => 'espera',
                    'day' => self::day_name((int) $household->waitingsince, $days),
                    'sub' => $days >= 2 ? get_string('panel_ago_days', $c, $days) : '', 'answered' => false, 'rows' => []];
            } else if (settled::gone($household->children)) {
                // They left: last, behind the line of those in the centre.
                $key = 'sefueron';
                $groups[$key] = $groups[$key] ?? ['key' => $key, 'kind' => 'sefueron',
                    'day' => get_string('panel_group_gone', $c), 'sub' => '', 'answered' => true, 'rows' => []];
            } else if (settled::in_centre($household->children)) {
                // Enrolled: in the centre, behind a line, until something happens.
                $key = 'encentro';
                $groups[$key] = $groups[$key] ?? ['key' => $key, 'kind' => 'encentro',
                    'day' => get_string('panel_group_incentre', $c), 'sub' => get_string('panel_group_incentre_sub', $c),
                    'answered' => true, 'rows' => []];
            } else {
                $key = 'contestadas';
                $groups[$key] = $groups[$key] ?? ['key' => $key, 'kind' => 'contestadas',
                    'day' => get_string('panel_group_answered', $c), 'sub' => '', 'answered' => true, 'rows' => []];
            }
            $groups[$key]['rows'][] = $row;
        }
        // Today first, then the waiting days from the oldest, then the answered ones.
        $order = fn($key) => $key === 'prometido' ? -2000000 : ($key === 'hoy' ? -1000000 : ($key === 'contestadas' ? 1000000 :
            ($key === 'encentro' ? 2000000 : ($key === 'sefueron' ? 3000000 : -(int) substr($key, 6)))));
        uksort($groups, fn($a, $b) => $order($a) <=> $order($b));
        return array_values(array_map(fn($g) => $g + ['count' => count($g['rows'])], $groups));
    }

    /**
     * The name of a day in the list: «Hoy», «Ayer», «Jueves 5», or the date.
     *
     * @param int $time
     * @param int $days How many days ago.
     * @return string
     */
    protected static function day_name(int $time, int $days): string {
        $c = self::C;
        if ($days === 0) {
            return get_string('panel_day_today', $c);
        }
        if ($days === 1) {
            return get_string('panel_day_yesterday', $c);
        }
        $name = $days < 7 ? userdate($time, '%A %d') : userdate($time, get_string('strftimedateshort', 'langconfig'));
        return \core_text::strtoupper(\core_text::substr($name, 0, 1)) . \core_text::substr($name, 1);
    }

    /**
     * The families that come today, to a visit or an event: the hour and who receives them.
     *
     * @param int $centreid
     * @param int $now
     * @return array Household id => text.
     */
    protected static function coming_today(int $centreid, int $now): array {
        global $DB;
        $zone = \core_date::get_user_timezone_object();
        $start = (new \DateTime('@' . $now))->setTimezone($zone)->setTime(0, 0)->getTimestamp();
        $rows = $DB->get_records_sql(
            'SELECT s.id, s.householdid, COALESCE(t.timestart, e.timestart) AS timestart, e.hosts
                                        FROM {local_oksigeniaeguahe_signup} s
                                        JOIN {' . events::T . '} e ON e.id = s.eventid
                                   LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
                                       WHERE e.centreid = :centreid AND s.attended = 0
                                             AND COALESCE(t.timestart, e.timestart) >= :start
                                             AND COALESCE(t.timestart, e.timestart) < :end',
            ['centreid' => $centreid, 'start' => $start, 'end' => $start + DAYSECS]
        );
        $out = [];
        foreach ($rows as $row) {
            $hosts = $row->hosts ? centres::hosts($centreid, array_map('intval', explode(',', (string) $row->hosts))) : [];
            $out[$row->householdid] = get_string($hosts ? 'panel_coming_with' : 'panel_coming_at', self::C, (object) [
                'hour' => panel::hour((int) $row->timestart), 'who' => implode(', ', array_map(fn($h) => $h->first, $hosts))]);
        }
        return $out;
    }

    /**
     * The rows of the inbox: the hour, the adults, the children with their course and stage, how and when they wrote,
     * and what is filtered by.
     *
     * @param array $list Households, as households::inbox() gives them.
     * @param int $now
     * @param array $today Household id => the visit of today, in words.
     * @param int $userid Who looks.
     * @param array $due Household id => the promise that is due.
     * @return array Household id => row.
     */
    public static function rows(array $list, int $now, array $today = [], int $userid = 0, array $due = []): array {
        global $DB;
        $c = self::C;
        $latest = households::latest(array_keys($list));
        $rows = [];
        foreach ($list as $household) {
            $info = $latest[$household->id] ?? ['last' => null, 'said' => null, 'answer' => null];
            $waiting = $household->waitingsince > 0;
            $moment = $waiting ? (int) $household->waitingsince : (int) ($info['answer']->timecreated ??
                $household->lastcontact);
            $last = $info['last'];
            $channel = $last ? (string) $last->channel : (string) $household->firstchannel;
            $via = get_string('via_' . (in_array($channel, lists::CHANNELS, true) || $channel === lists::PAGE ? $channel :
                'other'), $c);
            if ($last) {
                $via .= ', ' . panel::when_words((int) $last->timecreated, $now);
            }
            if (!empty($household->moving)) {
                $via .= ' · ' . get_string('panel_moving', $c);
            }
            $children = [];
            $search = [];
            $courses = [];
            $stages = [];
            foreach ($household->children as $child) {
                $children[] = [
                    'tag' => panel::tag($child),
                    'course' => $child->offer ? format_string($child->offer->name) : get_string('panel_nocourse', $c),
                    'stage' => $child->stage !== 'inquiry' ? get_string('stage_' . $child->stage, $c) : '',
                ];
                $search[] = $child->firstname . ' ' . $child->tagname;
                $courses[] = 'o' . (int) $child->offerid;
                $stages[] = $child->stage;
            }
            foreach ($household->adults as $adult) {
                $search[] = $adult->firstname . ' ' . $adult->lastname . ' ' . $adult->email . ' ' . $adult->phone;
            }
            $rows[$household->id] = [
                'id' => (string) $household->id,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
                'waiting' => $waiting,
                'answered' => !$waiting,
                'hour' => panel::hour($moment),
                'stamp' => ['top' => get_string($waiting ? 'panel_stamp_came' : 'panel_answered', $c),
                    'day' => rtrim(userdate($moment, '%a %d'), '.'), 'hour' => panel::hour($moment)],
                'adults' => panel::adults_line($household->adults),
                'children' => $children,
                'via' => $via,
                'viaicon' => panel::channel_icon($channel),
                'owner' => self::owner_face($household, $userid),
                'typing' => attend::typing((int) $household->id, $userid),
                'promise' => isset($due[$household->id]) ? attend::promise_context($due[$household->id], $now, $userid) : null,
                'visit' => isset($today[$household->id]) ? ['text' => $today[$household->id]] : null,
                'courses' => implode(' ', array_unique($courses)),
                'stages' => implode(' ', array_unique($stages)),
                'search' => \core_text::strtolower(implode(' ', $search)),
            ];
        }
        return $rows;
    }

    /**
     * The small face of who attends a family, with whether they are away in its title.
     *
     * @param \stdClass $household
     * @param int $userid
     * @return array|null
     */
    protected static function owner_face(\stdClass $household, int $userid): ?array {
        $owner = attend::owner($household, $userid);
        if ($owner && $owner['away'] !== '') {
            $owner['first'] .= ' (' . $owner['away'] . ')';
        }
        return $owner;
    }

    /**
     * The filters by entry course and by stage, as links that work without a script.
     *
     * @param \stdClass $centre
     * @param array $base The parameters of the page now.
     * @param int $course
     * @param string $stage
     * @return array courses, stages
     */
    protected static function filters(\stdClass $centre, array $base, int $course, string $stage): array {
        $c = self::C;
        $url = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/index.php', $params))->out(false);
        $courses = [['name' => get_string('panel_all_courses', $c), 'key' => '',
            'url' => $url(array_diff_key($base, ['curso' => 1])), 'current' => !$course]];
        $places = self::places($centre);
        foreach (centres::offers((int) $centre->id, true) as $offer) {
            if ((string) $offer->schoolyear === html_entity_decode($places['year'])) {
                $courses[] = ['name' => format_string($offer->name), 'key' => 'o' . $offer->id,
                    'url' => $url(['curso' => $offer->id] + $base), 'current' => $course === (int) $offer->id];
            }
        }
        $stages = [['name' => get_string('panel_all_stages', $c), 'key' => '',
            'url' => $url(array_diff_key($base, ['etapa' => 1])), 'current' => $stage === '']];
        foreach (lists::STAGES as $each) {
            $stages[] = ['name' => get_string('stage_' . $each, $c), 'key' => $each,
                'url' => $url(['etapa' => $each] + $base), 'current' => $stage === $each];
        }
        return ['courses' => $courses, 'stages' => $stages];
    }

    /**
     * The way of the campaign: how many families are at each step, each counted where its most advanced child is
     * (the waiting list, in the step of the place); the families already of the centre and the closed ways do not count.
     *
     * @param int $centreid
     * @return array|null total, sentence, def, steps; null without families.
     */
    public static function path(int $centreid): ?array {
        global $DB;
        $c = self::C;
        $rows = $DB->get_recordset_sql(
            'SELECT c.id, c.householdid, c.stage
                                          FROM {' . households::CHILDREN . '} c
                                          JOIN {' . households::T . '} h ON h.id = c.householdid
                                         WHERE h.centreid = :centreid AND h.incentre = 0 AND c.stage <> :closed',
            ['centreid' => $centreid, 'closed' => 'closed']
        );
        $furthest = [];
        foreach ($rows as $row) {
            $step = lists::STAGE_STEP[$row->stage] ?? 1;
            $furthest[$row->householdid] = max($furthest[$row->householdid] ?? 0, $step);
        }
        $rows->close();
        if (!$furthest) {
            return null;
        }
        $counts = array_count_values($furthest);
        $steps = [];
        foreach (panel_household::PATH as $i => $key) {
            $n = (int) ($counts[$i + 1] ?? 0);
            $steps[] = ['key' => $key, 'name' => get_string($key === 'admitted' ? 'panel_stage_place' : 'stage_' . $key, $c),
                'n' => $n, 'line' => get_string('panel_path_' . $key . ($n === 1 ? '_one' : ''), $c, $n)];
        }
        $total = count($furthest);
        return ['total' => $total, 'sentence' => get_string(
            $total === 1 ? 'panel_path_total_one' : 'panel_path_total',
            $c,
            $total
        ), 'def' => get_string('panel_path_def', $c), 'steps' => $steps];
    }

    /**
     * The next open days and events, for the epilogue.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array
     */
    protected static function next_events(\stdClass $centre, int $now): array {
        $out = [];
        foreach (events::for_centre((int) $centre->id) as $event) {
            if (empty($event->tailored) && empty($event->householdid) && $event->timeend >= $now) {
                $out[] = $event;
            }
        }
        usort($out, fn($a, $b) => $a->timestart <=> $b->timestart);
        return array_map(fn($e) => panel_events::row($e, [], $now), array_slice($out, 0, 3));
    }

    /**
     * Who answered last, when nobody waits: «La última la contestó Lucía hoy a las 9:12».
     *
     * @param int $centreid
     * @param int $now
     * @return string
     */
    protected static function last_answer(int $centreid, int $now): string {
        global $DB;
        $answer = $DB->get_records(
            households::CONTACTS,
            ['centreid' => $centreid, 'direction' => 'out'],
            'timecreated DESC, id DESC',
            'id, userid, timecreated',
            0,
            1
        );
        $answer = reset($answer);
        if (!$answer) {
            return get_string('panel_inbox_upto_text', self::C);
        }
        $first = (string) $DB->get_field('user', 'firstname', ['id' => $answer->userid]);
        return get_string('panel_inbox_all_answered', self::C, (object) ['who' => $first,
            'when' => panel::when_words((int) $answer->timecreated, $now)]);
    }

    /**
     * The visits the person guides or receives in the days to come: a visit of a family with its child, or an event.
     *
     * @param int $centreid
     * @param int $userid
     * @return array
     */
    public static function visits(int $centreid, int $userid): array {
        $c = self::C;
        $out = [];
        foreach (array_slice(visits::mine($centreid, $userid), 0, self::VISITS) as $event) {
            $family = $event->householdid ? households::get((int) $event->householdid, $centreid) : null;
            $leaf = panel::leaf((int) $event->timestart);
            $hour = panel::hour((int) $event->timestart);
            $minutes = (int) round(($event->timeend - $event->timestart) / MINSECS);
            if ($family) {
                $full = households::full($family);
                $adult = reset($full->adults);
                $surname = $adult ? (string) (preg_split('/\s+/u', trim((string) $adult->lastname))[0] ?? '') : '';
                $child = reset($full->children);
                $out[] = $leaf + [
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $family->id]))->out(false),
                    'title' => $hour . ' · ' . ($surname !== '' ? get_string('panel_family_of', $c, $surname) :
                        panel::adults_line($full->adults)),
                    'tag' => $child ? panel::tag($child) : '',
                    'detail' => implode(' · ', array_filter([format_string((string) $event->place),
                        get_string('panel_minutes', $c, $minutes)])),
                    'place' => $child ? '' : format_string((string) $event->place),
                ];
            } else {
                $out[] = $leaf + [
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
                    'title' => $hour . ' · ' . format_string($event->name),
                    'tag' => '',
                    'detail' => '',
                    'place' => trim((string) $event->place) !== '' ?
                        get_string('panel_you_receive', $c, format_string($event->place)) : '',
                ];
            }
        }
        return $out;
    }

    /**
     * The places of each course of the latest school year offered: free, interested, visited and enrolled, and the rail.
     *
     * @param \stdClass $centre
     * @return array year, list
     */
    public static function places(\stdClass $centre): array {
        $offers = centres::offers((int) $centre->id, true);
        if (!$offers) {
            return ['year' => '', 'list' => []];
        }
        // The offers come with the latest school year first.
        $year = (string) reset($offers)->schoolyear;
        $counts = households::offer_counts((int) $centre->id);
        $list = [];
        foreach ($offers as $offer) {
            if ((string) $offer->schoolyear !== $year) {
                continue;
            }
            $bystage = $counts[$offer->id] ?? [];
            $enrolled = (int) ($bystage['enrolled'] ?? 0);
            $total = (int) $offer->places;
            $list[] = [
                'name' => format_string($offer->name),
                'free' => max(0, $total - $enrolled),
                'total' => $total,
                'rail' => $total > 0 ? panel::rail(
                    $total,
                    $enrolled,
                    object: panel::hanging_object((string) $offer->name, $centre)
                ) :
                    '',
                'ropero' => $total > 0 ? vestibulo::ropero(
                    $total,
                    $enrolled,
                    (int) ($bystage['waitlist'] ?? 0),
                    (string) $offer->name,
                    $centre
                ) : false,
                'interested' => array_sum($bystage) - ($bystage['closed'] ?? 0) - $enrolled,
                'visited' => ($bystage['visit'] ?? 0) + ($bystage['application'] ?? 0) + ($bystage['admitted'] ?? 0) +
                    ($bystage['waitlist'] ?? 0),
                'enrolled' => $enrolled,
            ];
        }
        return ['year' => s($year), 'list' => $list];
    }
}
