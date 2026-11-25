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
 * The report of one act: how it went, said first, and what there is behind each number. An act of Eguahe, with what the sheet
 * of the team and the imported files say of that day, or one that is only in files.
 *
 * Every number comes with the households behind it, so the screen can open them in the inbox (familylists). What is not known
 * is told apart and is never a no: nobody having noted who came is not that nobody came. The families already of the centre,
 * those that only came to the act, those that were not a family and the roll go apart, and are in no other number.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class act_report {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int Whoever signs up this long before it starts, or later, did it at the door. */
    const DOOR = HOURSECS;

    /** @var int The acts it is set against. */
    const OTHERS = 8;

    /**
     * The acts of a centre, the newest first: the open days and the other events of Eguahe, and those only in files.
     *
     * @param \stdClass $centre
     * @param int $now
     * @param bool $ahead With those still to come.
     * @return array key => key, name, time, end, event (or null), files (import ids), talk
     */
    public static function all(\stdClass $centre, int $now = 0, bool $ahead = false): array {
        $now = $now ?: time();
        $days = act_files::days($centre);
        $out = [];
        $events = array_filter(events::for_centre((int) $centre->id), fn($e) => in_array($e->kind, ['openday', 'other'], true) &&
            empty($e->tailored) && empty($e->householdid) && empty($e->cancelled));
        // A file of a day goes with the open day of that day, if there is one.
        usort($events, fn($a, $b) => [$a->kind === 'other', (int) $a->timestart, (int) $a->id] <=> [$b->kind === 'other',
            (int) $b->timestart, (int) $b->id]);
        foreach ($events as $event) {
            $day = userdate((int) $event->timestart, '%Y-%m-%d', 99, false);
            $files = $days[$day]['files'] ?? [];
            unset($days[$day]);
            $out['e' . $event->id] = ['key' => 'e' . $event->id, 'name' => format_string($event->name), 'time' => (int) $event->timestart,
                'end' => (int) $event->timeend, 'event' => $event, 'files' => $files, 'talk' => $event->kind === 'other'];
        }
        foreach ($days as $day => $one) {
            $key = 'd' . str_replace('-', '', $day);
            $out[$key] = ['key' => $key, 'name' => format_string($one['name']), 'time' => $one['time'], 'end' => $one['time'],
                'event' => null, 'files' => $one['files'], 'talk' => false];
        }
        $out = array_filter($out, fn($act) => $ahead || $act['time'] <= $now);
        uasort($out, fn($a, $b) => [$b['time'], $a['key']] <=> [$a['time'], $b['key']]);
        return $out;
    }

    /** @var array The facts of the campaigns asked for, while a report is made. */
    protected static $facts = [];

    /**
     * Who was at an act and what they did after, as lists of families.
     *
     * @param \stdClass $centre
     * @param array $act Of all().
     * @param int $now
     * @return array lists (what => rows, each a family with id, people, moment and state), facts (the families of its campaign)
     *     and year
     */
    public static function people(\stdClass $centre, array $act, int $now): array {
        global $DB;
        $cid = (int) $centre->id;
        $year = $act['event'] ? facts::year_at($centre, $act['time'], facts::years($cid)) : facts::year_name($centre, $act['time']);
        $key = $cid . '|' . $year;
        if (!isset(self::$facts[$key])) {
            $campaigns = facts::campaigns($centre, $now);
            $campaign = ['key' => 'year', 'year' => $year, 'since' => 0, 'until' => 0, 'import' => null];
            foreach ($campaigns as $one) {
                $campaign = $one['year'] === $year ? $one : $campaign;
            }
            self::$facts = [$key => facts::all($centre, $campaign, $now)];
        }
        $facts = self::$facts[$key];
        $lists = array_fill_keys(['signed', 'came', 'walkin', 'notcame', 'unknown', 'sheet', 'sheetcame', 'guests', 'never', 'roster',
            'centre', 'all'], []);
        $day = userdate($act['time'], '%Y-%m-%d', 99, false);
        $ofday = fn(array $times) => array_filter($times, fn($t) => userdate((int) $t, '%Y-%m-%d', 99, false) === $day, ARRAY_FILTER_USE_KEY);
        $outcomes = facts::sheet_outcomes($centre);
        $seen = [];
        if ($act['event']) {
            $rows = $DB->get_records_sql(
                "SELECT s.id, s.householdid, s.people, s.attended, s.missed, s.timecreated, COALESCE(t.timestart, e.timestart) AS start,
                        COALESCE(t.timeend, e.timeend) AS finish, h.guest, h.roster, h.outreason
                   FROM {" . events::SIGNUPS . "} s
                   JOIN {" . events::T . "} e ON e.id = s.eventid
                   JOIN {" . households::T . "} h ON h.id = s.householdid
              LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
                  WHERE s.eventid = :eventid AND h.guest <> 2
               ORDER BY s.timecreated ASC, s.id ASC",
                ['eventid' => $act['event']->id]
            );
            foreach ($rows as $row) {
                $hid = (int) $row->householdid;
                $seen[$hid] = true;
                $said = $ofday($outcomes[$hid] ?? []);
                $state = (int) $row->attended ? 'came' : ((int) $row->missed ? 'notcame' : (in_array('came', $said, true) ? 'came' :
                    ($said ? 'notcame' : 'unknown')));
                $one = ['id' => $hid, 'people' => max(1, (int) $row->people), 'state' => $state, 'time' => (int) $row->timecreated,
                    'moment' => self::moment((int) $row->timecreated, (int) $row->start, (int) $row->finish)];
                if (in_array((string) $row->outreason, outs::NEVER, true)) {
                    $lists['never'][] = $one;
                    continue;
                }
                if ((int) $row->guest) {
                    $lists['guests'][] = $one;
                    continue;
                }
                $lists['all'][] = $one;
                // At the door; one noted after the act may have signed up before: it is not known.
                $walkin = $state === 'came' && $one['moment'] === 'door';
                $lists[$walkin ? 'walkin' : 'signed'][] = $one;
                if (!$walkin) {
                    $lists[$state][] = $one;
                }
                if ((int) $row->roster) {
                    $lists['roster'][] = $one;
                } else if (in_array($facts[$hid]['who'] ?? 'new', who::CENTRE, true)) {
                    $lists['centre'][] = $one;
                }
            }
        }
        // Those the files of that day say, each once, and not signed up in Eguahe.
        foreach ($act['files'] ? $facts : [] as $f) {
            if (!array_intersect($act['files'], $f['sources']) || ($f['id'] && isset($seen[$f['id']]))) {
                continue;
            }
            $one = ['id' => (int) $f['id'], 'people' => 0, 'state' => $f['came'] ? 'came' : 'unknown', 'time' => $f['first'],
                'moment' => self::moment($f['first'], $act['time'], $act['end']), 'fact' => $f, 'file' => true];
            $lists['all'][] = $one;
            $lists['signed'][] = $one;
            $lists[$one['state']][] = $one;
            if ($f['group'] === 'centre') {
                $lists['centre'][] = $one;
            }
            if ($f['id']) {
                $seen[$f['id']] = true;
            }
        }
        // And those the sheet of the team has that day, who are not signed up.
        if ($act['event'] && !$act['talk']) {
            $start = usergetmidnight($act['time']);
            foreach (appointments::agenda($centre, $start, $start + DAYSECS, $now) as $item) {
                if ($item['id'][0] !== 'n' || isset($seen[$item['householdid']]) || !isset($facts[$item['householdid']])) {
                    continue;
                }
                $seen[$item['householdid']] = true;
                $said = $ofday($outcomes[$item['householdid']] ?? []);
                $came = $item['state'] === 'came' || in_array('came', $said, true);
                $one = ['id' => $item['householdid'], 'people' => 0, 'state' => $came ? 'came' : 'unknown', 'time' => 0, 'moment' => 'unknown'];
                $lists['sheet'][] = $one;
                if ($came) {
                    $lists['sheetcame'][] = $one;
                }
            }
        }
        return ['lists' => $lists, 'facts' => $facts, 'year' => $year];
    }

    /**
     * When a family signed up, against the act: before the eve, the eve, the same day, at the door or after.
     *
     * @param int $time
     * @param int $start
     * @param int $end
     * @return string before, eve, day, door, after or unknown
     */
    public static function moment(int $time, int $start, int $end): string {
        if (!$time) {
            return 'unknown';
        }
        if ($time > $end && $end > $start) {
            return 'after';
        }
        if ($time >= $start - self::DOOR && $end > $start) {
            return 'door';
        }
        $days = panel::days_between($time, $start);
        return $days <= 0 ? ($time > $start && $end <= $start ? 'after' : 'day') : ($days === 1 ? 'eve' : 'before');
    }

    /**
     * The families of admissions that were to be at an act and did something after it, and the rest of the lists a number can
     * open.
     *
     * @param \stdClass $centre
     * @param array $act
     * @param int $now
     * @param array|null $people From people(), when it is at hand.
     * @return array what => household ids (0, a family only in a file)
     */
    public static function lists(\stdClass $centre, array $act, int $now, ?array $people = null): array {
        $people = $people ?? self::people($centre, $act, $now);
        $out = array_map(fn($rows) => array_column($rows, 'id'), $people['lists']);
        $apart = array_flip(array_merge($out['centre'], $out['roster']));
        unset($apart[0]);
        // Of the campaign: without those already of the centre; a family only in a file, by its own group.
        $rows = array_values(array_filter($people['lists']['all'], fn($r) => $r['id'] ? !isset($apart[$r['id']]) && isset($people['facts'][$r['id']]) :
            ($r['fact']['group'] ?? '') === 'campaign'));
        $out['mine'] = array_column($rows, 'id');
        $fact = fn(array $row) => $row['fact'] ?? $people['facts'][$row['id']];
        $start = $act['time'];
        $pick = fn(callable $is) => array_column(array_values(array_filter($rows, fn($row) => $is($fact($row), $row))), 'id');
        $stage = fn(array $k) => $k['stage'] === 'closed' ? $k['far'] : $k['stage'];
        $ids = array_values(array_filter($out['mine']));
        $after = self::after($centre, $ids, $start, $now);
        $kid = fn(array $f, callable $is) => (bool) array_filter($f['kids'], fn($k) => $k['mine'] && $k['stagetime'] >= $start && $is($k));
        $applied = fn(array $f) => $kid($f, fn($k) => in_array($stage($k), facts::APPLIED, true));
        $enrolled = fn(array $f) => $f['kids'] ? $kid($f, fn($k) => $k['stage'] === 'enrolled' && !$k['left']) :
            ($f['enrolled'] && $f['enrolledat'] >= $start);
        $out['minecame'] = $pick(fn($f, $row) => $row['state'] === 'came');
        $out['applied'] = $pick(fn($f) => $applied($f) || $enrolled($f));
        $out['enrolled'] = $pick(fn($f) => $enrolled($f));
        $out['after'] = $pick(fn($f, $row) => isset($after['wrote'][$row['id']]) || isset($after['appointment'][$row['id']]) ||
            $applied($f) || $enrolled($f));
        $out['before'] = $pick(fn($f) => !$enrolled($f) && $f['enrolled']);
        $out['gone'] = $pick(fn($f) => $f['closed'] || $f['went']);
        $out['waiting'] = $pick(fn($f) => $f['waiting'] > 0 && !$f['closed']);
        $out['waitingsince'] = $pick(fn($f) => $f['waiting'] >= $start && !$f['closed']);
        $out['answered'] = $pick(fn($f, $row) => isset($after['answered'][$row['id']]));
        $out['tried'] = $pick(fn($f, $row) => isset($after['tried'][$row['id']]) && !isset($after['answered'][$row['id']]));
        $out['appointment'] = $pick(fn($f, $row) => isset($after['ahead'][$row['id']]));
        $busy = array_flip(array_filter(array_merge($out['after'], $out['gone'], $out['waiting'], $out['answered'], $out['tried'], $out['appointment'])));
        $out['nothing'] = $pick(fn($f, $row) => $row['id'] && !isset($busy[$row['id']]));
        // Came, still on their way, and nobody has written to them since.
        $out['unwritten'] = $pick(fn($f, $row) => $row['id'] && $row['state'] === 'came' && !isset($after['answered'][$row['id']]) && !$f['closed'] &&
            !$f['enrolled'] && !$applied($f));
        $out['minenotcame'] = $pick(fn($f, $row) => $row['state'] === 'notcame');
        $out['mineunknown'] = $pick(fn($f, $row) => $row['state'] === 'unknown');
        $out['nokids'] = $pick(fn($f) => $f['nokids']);
        foreach (['fresh' => ['new'], 'knew' => ['former', 'alumni']] as $key => $kinds) {
            $out[$key] = $pick(fn($f) => in_array($f['who'], $kinds, true));
        }
        $out['inside'] = array_values(array_unique(array_merge($out['centre'], $out['roster'])));
        $out['noheard'] = $pick(fn($f) => $f['heard'] === '' && $f['voices']['said'] === '');
        return $out;
    }

    /**
     * What some families and the centre did from an act on.
     *
     * @param \stdClass $centre
     * @param int[] $ids
     * @param int $start
     * @param int $now
     * @return array wrote, answered, tried, appointment (since) and ahead (still to come), each household id => true
     */
    protected static function after(\stdClass $centre, array $ids, int $start, int $now): array {
        global $DB;
        $out = ['wrote' => [], 'answered' => [], 'tried' => [], 'appointment' => [], 'ahead' => []];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $rows = $DB->get_recordset_select(
                households::CONTACTS,
                "householdid $in AND direction <> 'note' AND timecreated >= :start AND (aim IS NULL OR aim = 'tried')",
                $params + ['start' => $start],
                'id ASC',
                'id, householdid, direction, aim, body'
            );
            foreach ($rows as $row) {
                $key = $row->direction === 'in' ? 'wrote' : (metrics::is_try($row) ? 'tried' : 'answered');
                $out[$key][(int) $row->householdid] = true;
            }
            $rows->close();
        }
        $mine = array_flip($ids);
        $sameday = userdate($start, '%Y-%m-%d', 99, false);
        foreach ($ids ? appointments::agenda($centre, $start + MINSECS, $now + YEARSECS, $now) : [] as $item) {
            // Its own visits, and the open days it signed up to after. A day of the sheet without its hour that is the day of the
            // act is the act.
            if ($item['dayonly'] && $sameday === userdate($item['start'], '%Y-%m-%d', 99, false)) {
                continue;
            }
            if (isset($mine[$item['householdid']]) && in_array($item['state'], ['coming', 'came', 'unknown'], true) && $item['kind'] !== 'act') {
                $out['appointment'][$item['householdid']] = true;
                if ($item['start'] >= $now) {
                    $out['ahead'][$item['householdid']] = true;
                }
            }
        }
        return $out;
    }

    /**
     * The whole report.
     *
     * @param \stdClass $centre
     * @param string $key Of all().
     * @param int $now
     * @return array|null As written in the contract; null, there is no such act.
     */
    public static function build(\stdClass $centre, string $key, int $now = 0): ?array {
        global $DB;
        $now = $now ?: time();
        $c = self::C;
        $acts = self::all($centre, $now, true);
        $act = $acts[$key] ?? null;
        if (!$act) {
            return null;
        }
        $people = self::people($centre, $act, $now);
        $lists = self::lists($centre, $act, $now, $people);
        $rows = $people['lists'];
        $num = fn(string $what, int $of = 0, array $more = []) => self::num($centre, $key, $what, $lists[$what] ?? [], $of, $more);
        $signed = count($rows['signed']);
        $mine = count($lists['mine']);
        $past = $act['time'] <= $now;
        $marked = $signed - count($rows['unknown']);
        $persons = array_sum(array_map(fn($r) => $r['state'] === 'came' ? $r['people'] : 0, $rows['all']));
        $event = $act['event'];
        $word = $event ? acts::team_word($event) : get_string('actr_word', $c);
        $out = ['key' => $key, 'name' => $act['name'], 'word' => $word, 'talk' => $act['talk'], 'imported' => !$event, 'past' => $past,
            'when' => userdate($act['time'], get_string('strftimedaydate', 'langconfig')),
            'hours' => $event ? get_string('panel_hours', $c, (object) ['from' => panel::hour($act['time']), 'to' => panel::hour($act['end'])]) : '',
            'place' => $event ? format_string((string) $event->place) : '', 'year' => $people['year'],
            'question' => self::question($act, $now),
            'answer' => self::answer($act, $rows, $lists, $now),
            'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id, 'acto' => $key]))->out(false)];
        $out['figures'] = array_values(array_filter([$num('signed', 0, ['split' => self::split($rows['signed'], $lists)]),
            $past ? $num('came', $signed, ['split' => self::split($rows['came'], $lists)]) : null,
            $rows['walkin'] ? $num('walkin') : null, $rows['notcame'] ? $num('notcame', $signed) : null,
            $past && $rows['unknown'] ? $num('unknown', $signed) : null,
            $persons ? ['key' => 'people', 'name' => get_string('actr_people', $c), 'n' => $persons, 'ids' => [], 'url' => false, 'line' => '',
                'share' => '', 'list' => '', 'split' => '', 'noids' => ''] : null]));
        // From the poster to the place: the families of admissions only.
        $steps = [];
        foreach ($past ? ['mine', 'minecame', 'after', 'applied', 'enrolled'] : ['mine'] as $what) {
            if ($what === 'minecame' && !$marked && !$rows['walkin']) {
                continue;
            }
            $steps[] = $num($what, $mine, $what === 'mine' ? ['share' => ''] : ['split' => self::of_came($what, $lists)]);
        }
        $out['funnel'] = $mine ? ['steps' => $steps, 'stay' => self::stay($centre, $key, $rows, $lists, $past), 'def' => get_string('actr_funnel_def', $c)] : false;
        $apart = array_values(array_filter(array_map(fn($what) => $rows[$what] ? $num($what) : null, ['centre', 'roster', 'guests', 'never'])));
        if ($lists['before']) {
            $apart[] = $num('before');
        }
        $out['apart'] = $apart ?: false;
        $out['who'] = self::who($centre, $act, $people, $lists, $num);
        $out += self::ways($centre, $act, $people, $lists);
        $out['moment'] = self::moments($centre, $key, $rows);
        $out['spend'] = self::spend($centre, $act, $rows);
        $since = array_values(array_filter(array_map(fn($what) => $lists[$what] ? $num($what, $mine) : null, ['answered', 'tried', 'appointment',
            'waiting', 'gone', 'nothing'])));
        $waits = array_filter(array_map(fn($id) => $people['facts'][$id]['waiting'] ?? 0, $lists['waiting']));
        $reasons = [];
        foreach ($lists['gone'] as $id) {
            $why = $people['facts'][$id]['why'] ?? '';
            if ($why !== '') {
                $reasons[$why][] = $id;
            }
        }
        $out['since'] = $past && $since ? ['rows' => $since, 'longest' => $waits ? get_string('actr_longest', $c, panel_metrics::hours_words(
            ($now - min($waits)) / HOURSECS
        )) : '', 'reasons' => array_map(
            fn($why, $ids) => ['name' => get_string('out_' . $why, $c), 'n' => count($ids), 'ids' => $ids],
            array_keys($reasons),
            $reasons
        )] : false;
        $out['said'] = $event ? self::said($centre, $event, $lists, $act['time']) : false;
        $out['others'] = self::others($centre, $acts, $act, $now);
        $out['sheet'] = $rows['sheet'] ? ['n' => count($rows['sheet']), 'came' => count($rows['sheetcame'])] + $num('sheet') : false;
        $out['unknown'] = array_values(array_filter([$past && $rows['unknown'] ? $num('unknown', $signed) : null,
            $lists['noheard'] ? $num('noheard', $mine) : null, $lists['nokids'] ? $num('nokids', $mine) : null])) ?: false;
        $out['todo'] = self::todo($centre, $act, $rows, $lists, $past) ?: false;
        $out['sources'] = self::sources($centre, $act, $rows);
        self::$facts = [];
        return $out;
    }

    /**
     * A number with the families behind it.
     *
     * @param \stdClass $centre
     * @param string $key Of the act.
     * @param string $what
     * @param int[] $ids
     * @param int $of
     * @param array $more
     * @return array key, name, n, ids, list, url, line, share, split and noids
     */
    protected static function num(\stdClass $centre, string $key, string $what, array $ids, int $of = 0, array $more = []): array {
        $c = self::C;
        $n = count($ids);
        $known = array_values(array_unique(array_filter($ids)));
        $list = 'act-' . $key . '-' . $what;
        $name = familylists::name('actr_num_' . $what, $n);
        return $more + ['key' => $what, 'name' => $name, 'n' => $n, 'ids' => $known, 'list' => $known ? $list : '',
            'url' => $known ? familylists::url($centre, $list) : false, 'line' => $of ? get_string('actr_line', $c, (object) [
            'name' => $name, 'n' => $n, 'of' => $of]) : get_string('actr_line_alone', $c, (object) ['name' => $name, 'n' => $n]),
            'share' => $of >= facts::FEW ? panel_metrics::percent($n, $of) : '', 'split' => '',
            'noids' => $n > count($known) ? get_string('actr_noids' . ($n - count($known) === 1 ? '_one' : ''), $c, $n - count($known)) : ''];
    }

    /**
     * How an act is asked about.
     *
     * @param array $act
     * @param int $now
     * @return string
     */
    public static function question(array $act, int $now): string {
        // With its year when it is not of these months.
        $near = abs($now - $act['time']) < 300 * DAYSECS;
        $day = trim(preg_replace('/\s+/u', ' ', userdate($act['time'], get_string($near ? 'actr_day' : 'actr_day_year', self::C))));
        return get_string($act['time'] > $now ? 'actr_question_ahead' : 'actr_question', self::C, (object) ['name' => $act['name'], 'day' => $day]);
    }

    /**
     * How it went, in a sentence.
     *
     * @param array $act
     * @param array $rows Of people().
     * @param array $lists
     * @param int $now
     * @return string
     */
    public static function answer(array $act, array $rows, array $lists, int $now): string {
        $c = self::C;
        $one = fn(string $key, int $n, $a = null) => get_string($key . ($n === 1 ? '_one' : ''), $c, $a ?? $n);
        $signed = count($rows['signed']);
        $came = count($rows['came']);
        $unknown = count($rows['unknown']);
        if (!$signed && !$rows['walkin']) {
            return get_string('actr_answer_nobody', $c);
        }
        if ($act['time'] > $now) {
            return $one('actr_answer_ahead', $signed);
        }
        $out = [];
        if (!$signed) {
            $out[] = $one('actr_answer_onlywalkin', count($rows['walkin']));
        } else if ($unknown === $signed) {
            $out[] = $one('actr_answer_unmarked', $signed);
        } else if ($signed === 1) {
            $out[] = get_string($came ? 'actr_answer_one_came' : 'actr_answer_one_notcame', $c);
        } else {
            $how = $came === $signed ? '_all' : ($came === 1 ? '_one' : ($came ? '' : ($unknown ? '_notknown' : '_none')));
            $out[] = get_string('actr_answer_came' . $how, $c, (object) ['came' => $came, 'signed' => $signed]);
            if ($unknown) {
                $out[] = $one('actr_answer_unknown', $unknown);
            }
        }
        if ($signed && $rows['walkin']) {
            $out[] = $one('actr_answer_walkin', count($rows['walkin']));
        }
        if (!$act['talk'] && $lists['mine']) {
            $out[] = $lists['enrolled'] ? get_string('actr_answer_enrolled', $c, (object) ['applied' => count($lists['applied']),
                'enrolled' => count($lists['enrolled'])]) : ($lists['applied'] ? $one('actr_answer_applied', count($lists['applied'])) :
                get_string('actr_answer_noapplied', $c));
        }
        return implode(' ', $out);
    }

    /**
     * How many of some families are of the campaign and how many were already of the centre.
     *
     * @param array[] $rows
     * @param array $lists
     * @return string Empty when all are of one side.
     */
    protected static function split(array $rows, array $lists): string {
        $inside = array_flip(array_filter($lists['inside']));
        $centre = count(array_filter($rows, fn($r) => $r['id'] ? isset($inside[$r['id']]) : ($r['fact']['group'] ?? '') === 'centre'));
        $mine = count($rows) - $centre;
        return $centre ? get_string('actr_split', self::C, (object) ['mine' => $mine, 'centre' => $centre]) : '';
    }

    /**
     * Of a step of the way, how many are of families said to have come.
     *
     * @param string $what
     * @param array $lists
     * @return string
     */
    protected static function of_came(string $what, array $lists): string {
        if ($what === 'minecame' || !$lists[$what]) {
            return '';
        }
        $came = count(array_intersect(array_filter($lists[$what]), array_filter($lists['minecame'])));
        return $came === count($lists[$what]) ? '' : get_string('actr_ofcame', self::C, (object) ['came' => $came,
            'rest' => count($lists[$what]) - $came]);
    }

    /**
     * Those that stayed at each step, said.
     *
     * @param \stdClass $centre
     * @param string $key
     * @param array $rows
     * @param array $lists
     * @param bool $past
     * @return array[]
     */
    protected static function stay(\stdClass $centre, string $key, array $rows, array $lists, bool $past): array {
        $out = [];
        if (!$past) {
            return $out;
        }
        foreach (['minenotcame', 'mineunknown', 'nothing'] as $what) {
            if ($lists[$what]) {
                $n = count($lists[$what]);
                $out[] = ['key' => $what, 'n' => $n, 'ids' => array_values(array_filter($lists[$what])), 'list' => 'act-' . $key . '-' . $what,
                    'url' => familylists::url($centre, 'act-' . $key . '-' . $what),
                    'text' => get_string('actr_stay_' . $what . ($n === 1 ? '_one' : ''), self::C, $n)];
            }
        }
        return $out;
    }

    /**
     * Who they were: those with nothing to do with the centre, those that knew it and those already of it; and the children by
     * stage and course.
     *
     * @param \stdClass $centre
     * @param array $act
     * @param array $people
     * @param array $lists
     * @param callable $num
     * @return array|false
     */
    protected static function who(\stdClass $centre, array $act, array $people, array $lists, callable $num) {
        if (!$people['lists']['all']) {
            return false;
        }
        $kinds = array_values(array_filter(array_map(fn($what) => $lists[$what] ? $num($what) : null, ['fresh', 'knew', 'inside'])));
        $stages = [];
        $children = 0;
        foreach ($lists['mine'] as $i => $id) {
            $f = $people['facts'][$id] ?? null;
            foreach ($f ? $f['kids'] : [] as $kid) {
                if (!$kid['mine']) {
                    continue;
                }
                $children++;
                $age = $kid['name'] !== '' ? grades::age($kid['name'], $centre) : null;
                $stage = $age !== null ? grades::stage_of($age, $centre) : null;
                $name = $kid['name'] === '' ? get_string('actr_nocourse', self::C) : ($stage ? (string) $stage['name'] : get_string('metrics_nograde', self::C));
                $stages[$name] = $stages[$name] ?? ['name' => $name, 'from' => $kid['name'] === '' ? 99 : ($stage ? (int) $stage['from'] : 98), 'n' => 0,
                    'courses' => []];
                $stages[$name]['n']++;
                $course = $kid['name'] === '' ? '' : format_string($kid['name']);
                if ($course !== '') {
                    $stages[$name]['courses'][$course] = $stages[$name]['courses'][$course] ?? ['name' => $course, 'n' => 0, 'ids' => []];
                    $stages[$name]['courses'][$course]['n']++;
                    $stages[$name]['courses'][$course]['ids'][] = $id;
                }
            }
        }
        usort($stages, fn($a, $b) => [$a['from'], $a['name']] <=> [$b['from'], $b['name']]);
        return ['kinds' => $kinds, 'children' => $children, 'nokids' => $lists['nokids'] ? $num('nokids') : false,
            'stages' => array_map(fn($s) => ['name' => $s['name'], 'n' => $s['n'], 'courses' => array_values($s['courses'])], $stages)];
    }

    /**
     * How they heard of it, the channel they came by and what they signed up through.
     *
     * @param \stdClass $centre
     * @param array $act
     * @param array $people
     * @param array $lists
     * @return array heard, channels and through, each rows and def, or false
     */
    protected static function ways(\stdClass $centre, array $act, array $people, array $lists): array {
        global $DB;
        $c = self::C;
        $heard = [];
        $channels = [];
        $apart = array_flip(array_filter($lists['inside']));
        $rows = array_filter($people['lists']['all'], fn($r) => $r['id'] ? !isset($apart[$r['id']]) : ($r['fact']['group'] ?? '') === 'campaign');
        foreach ($rows as $row) {
            $f = $row['fact'] ?? $people['facts'][$row['id']] ?? null;
            if (!$f) {
                continue;
            }
            if ($f['voices']['said'] !== '') {
                $heard[$f['voices']['said']][] = $row['id'];
            }
            $channels[$f['voices']['channel']][] = $row['id'];
        }
        // The form, the place or the channel of the sign-up itself: the line of that moment.
        $through = [];
        $entries = $DB->get_records_menu(entries::T, ['centreid' => $centre->id], '', 'id, name');
        $ids = array_values(array_filter(array_column($people['lists']['all'], 'id')));
        $lines = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $set = $DB->get_recordset_select(
                households::CONTACTS,
                "householdid $in AND direction <> 'out' AND channel <> 'page'",
                $params,
                'timecreated ASC, id ASC',
                'id, householdid, channel, entryid, detail, timecreated'
            );
            foreach ($set as $line) {
                $lines[(int) $line->householdid][] = $line;
            }
            $set->close();
        }
        foreach ($people['lists']['all'] as $row) {
            $name = '';
            foreach ($lines[$row['id']] ?? [] as $line) {
                if (abs((int) $line->timecreated - $row['time']) <= 10 * MINSECS) {
                    $name = $line->entryid && isset($entries[$line->entryid]) ? format_string($entries[$line->entryid]) .
                        ($line->detail ? ' · ' . $line->detail : '') : get_string('channel_' . (in_array($line->channel, lists::CHANNELS, true) ?
                        $line->channel : 'other'), $c);
                }
            }
            $through[$name !== '' ? $name : get_string(empty($row['file']) ? 'actr_through_team' : 'actr_through_file', $c)][] = $row['id'];
        }
        $list = function (array $groups): array {
            uksort($groups, fn($a, $b) => [count($groups[$b]), (string) $a] <=> [count($groups[$a]), (string) $b]);
            return array_map(
                fn($name, $ids) => ['name' => (string) $name, 'n' => count($ids), 'ids' => array_values(array_filter($ids))],
                array_keys($groups),
                $groups
            );
        };
        return ['heard' => $heard ? ['rows' => $list($heard), 'def' => get_string('actr_heard_def', $c)] : false,
            'channels' => $channels ? ['rows' => $list($channels), 'def' => get_string('actr_channels_def', $c)] : false,
            'through' => $through ? ['rows' => $list($through)] : false];
    }

    /**
     * When they signed up.
     *
     * @param \stdClass $centre
     * @param string $key
     * @param array $rows
     * @return array|false
     */
    protected static function moments(\stdClass $centre, string $key, array $rows) {
        $by = [];
        foreach ($rows['all'] as $row) {
            $by[$row['moment']][] = $row['id'];
        }
        $out = [];
        foreach (['before', 'eve', 'day', 'door', 'after', 'unknown'] as $moment) {
            if (!empty($by[$moment])) {
                $out[] = ['key' => $moment, 'name' => get_string('actr_moment_' . $moment, self::C), 'n' => count($by[$moment]),
                    'ids' => array_values(array_filter($by[$moment]))];
            }
        }
        return $out ? ['rows' => $out] : false;
    }

    /**
     * What it cost: the money the centre noted for it and for its ads, and the hours of the team. What nobody noted is said.
     *
     * @param \stdClass $centre
     * @param array $act
     * @param array $rows
     * @return array known, text, each, hours and url
     */
    protected static function spend(\stdClass $centre, array $act, array $rows): array {
        $c = self::C;
        $amount = $act['event'] ? panel_newstudents::act_spend((int) $centre->id, (int) $act['event']->id) : null;
        $labels = array_map(fn($f) => $f['label'], array_intersect_key(act_files::of_centre($centre), array_flip($act['files'])));
        foreach (array_unique($labels) as $label) {
            foreach (metrics::spend((int) $centre->id, $label) as $key => $cost) {
                if (substr((string) $key, -1) === '|') {
                    $amount = (float) $amount + (float) $cost;
                }
            }
        }
        $came = count($rows['came']) + count($rows['walkin']);
        $hours = 0.0;
        if ($act['event']) {
            $hours = max(0, ($act['end'] - $act['time']) / HOURSECS) * max(1, count(array_filter(explode(',', (string) ($act['event']->hosts ?? '')))));
            $hours += array_sum(panel_newstudents::written((int) $centre->id)['event'][(int) $act['event']->id] ?? []);
        }
        return ['known' => $amount !== null, 'text' => $amount === null ? get_string('panel_new_money_none', $c) : centres::money($centre, $amount),
            'each' => $amount && $came ? get_string('actr_spend_each', $c, centres::money($centre, $amount / $came)) : '',
            'hours' => $hours > 0 ? get_string('actr_spend_hours', $c, panel_newstudents::hours($hours)) : '',
            'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id, 'hoja' => 'curso'], 'publicidad'))->out(false)];
    }

    /**
     * What they said: the survey of the act and what they asked after it.
     *
     * @param \stdClass $centre
     * @param \stdClass $event
     * @param array $lists
     * @param int $start
     * @return array|false
     */
    protected static function said(\stdClass $centre, \stdClass $event, array $lists, int $start) {
        global $DB;
        $results = surveys::results($event, $centre, current_language());
        $asked = [];
        foreach (array_chunk(array_values(array_filter($lists['mine'])), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $lines = $DB->get_recordset_select(
                households::CONTACTS,
                "householdid $in AND direction = 'in' AND timecreated >= :start",
                $params + ['start' => $start],
                'timecreated DESC, id DESC',
                'id, householdid, body'
            );
            foreach ($lines as $line) {
                foreach (questions::of((string) $line->body) as $question) {
                    if (count($asked) < 20) {
                        $asked[] = ['text' => $question[0] ?? $question['text'] ?? '', 'householdid' => (int) $line->householdid,
                            'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $line->householdid]))->out(false)];
                    }
                }
            }
            $lines->close();
        }
        return $results['told'] || $asked ? ['told' => $results['told'], 'came' => $results['came'], 'survey' => array_values(array_map(
            fn($q) => ['label' => $q['label'], 'n' => $q['n'], 'rows' => $q['rows'], 'quotes' => array_map(fn($x) => ['text' => $x['text'],
                'householdid' => $x['householdid']], $q['quotes'])],
            array_filter($results['questions'], fn($q) => $q['n'] > 0)
        )), 'asked' => $asked] : false;
    }

    /**
     * The same numbers of the other acts of its kind, to read it against them. What a file does not say is not known.
     *
     * @param \stdClass $centre
     * @param array $acts Of all().
     * @param array $act
     * @param int $now
     * @return array|false
     */
    protected static function others(\stdClass $centre, array $acts, array $act, int $now) {
        $c = self::C;
        $rows = [];
        $same = array_filter($acts, fn($a) => $a['talk'] === $act['talk'] && $a['time'] <= max($now, $act['time']));
        foreach (array_slice($same, 0, self::OTHERS, true) as $key => $one) {
            $people = self::people($centre, $one, $now);
            $lists = self::lists($centre, $one, $now, $people);
            $signed = count($people['lists']['signed']);
            $unknown = count($people['lists']['unknown']);
            $past = $one['time'] <= $now;
            $hascame = $past && ($signed ? $unknown < $signed : (bool) $people['lists']['walkin']);
            $file = !$one['event'];
            $hasapplied = $past && (!$file || $lists['applied']);
            $missing = [];
            if ($past && !$hascame && $signed) {
                $missing[] = get_string($file ? 'actr_missing_filecame' : 'actr_missing_came', $c);
            } else if ($unknown) {
                $missing[] = get_string('actr_missing_some' . ($unknown === 1 ? '_one' : ''), $c, $unknown);
            }
            if ($past && !$hasapplied) {
                $missing[] = get_string('actr_missing_fileapplied', $c);
            }
            $rows[] = ['key' => $key, 'name' => $one['name'], 'when' => userdate($one['time'], get_string('strftimedateshort', 'langconfig')) . ' ' .
                userdate($one['time'], '%Y'), 'imported' => $file, 'current' => $key === $act['key'], 'signed' => $signed,
                'came' => $hascame ? count($people['lists'][$signed ? 'came' : 'walkin']) : null, 'hascame' => $hascame,
                'applied' => $hasapplied ? count($lists['applied']) : null, 'hasapplied' => $hasapplied,
                'enrolled' => $past ? count($lists['enrolled']) : null, 'hasenrolled' => $past, 'unknown' => $past ? $unknown : 0,
                'missing' => implode(' ', $missing), 'url' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id,
                'acto' => $key]))->out(false)];
        }
        return count($rows) > 1 ? ['rows' => $rows, 'def' => get_string('actr_others_def', $c)] : false;
    }

    /**
     * What it is worth doing now, from fixed rules, each with its families.
     *
     * @param \stdClass $centre
     * @param array $act
     * @param array $rows
     * @param array $lists
     * @param bool $past
     * @return array[]
     */
    protected static function todo(\stdClass $centre, array $act, array $rows, array $lists, bool $past): array {
        $c = self::C;
        $out = [];
        $add = function (string $key, array $ids, ?\moodle_url $url = null) use (&$out, $centre, $act, $c): void {
            $n = count($ids);
            if ($n) {
                $list = 'act-' . $act['key'] . '-' . $key;
                $out[] = ['key' => $key, 'n' => $n, 'ids' => array_values(array_filter($ids)), 'list' => $list,
                    'url' => $url ? $url->out(false) : familylists::url($centre, $list),
                    'text' => get_string('actr_todo_' . $key . ($n === 1 ? '_one' : ''), $c, $n), 'button' => get_string('actr_todo_' . $key . '_do', $c)];
            }
        };
        if (!$past) {
            return $out;
        }
        $add('waitingsince', $lists['waitingsince']);
        if ($act['event']) {
            $add('unknown', array_column(array_filter($rows['unknown'], fn($r) => empty($r['file'])), 'id'), new \moodle_url(
                '/local/oksigeniaeguahe/agenda.php',
                ['c' => $centre->id, 'pasada' => 1, 'acto' => $act['event']->id]
            ));
        }
        $add('unwritten', $lists['unwritten']);
        $add('nokids', $lists['nokids']);
        return $out;
    }

    /**
     * Where the numbers come from.
     *
     * @param \stdClass $centre
     * @param array $act
     * @param array $rows
     * @return array[] name, what, families and date
     */
    protected static function sources(\stdClass $centre, array $act, array $rows): array {
        $c = self::C;
        $out = [];
        if ($act['event']) {
            $out[] = ['name' => 'Eguahe', 'what' => get_string('actr_source_eguahe', $c),
                'families' => count(array_filter($rows['all'], fn($r) => empty($r['file']))) + count($rows['guests']), 'date' => ''];
        }
        if ($rows['sheet']) {
            $out[] = ['name' => get_string('actr_source_sheet', $c), 'what' => get_string('actr_source_sheet_what', $c),
                'families' => count($rows['sheet']), 'date' => ''];
        }
        foreach (array_intersect_key(act_files::of_centre($centre), array_flip($act['files'])) as $file) {
            $out[] = ['name' => format_string($file['label']), 'what' => get_string('actr_source_file', $c), 'families' => count(array_filter(
                $rows['all'],
                fn($r) => in_array($file['id'], $r['fact']['sources'] ?? [], true)
            )), 'date' => userdate($file['imported'], get_string('strftimedate', 'langconfig'))];
        }
        return $out;
    }
}
