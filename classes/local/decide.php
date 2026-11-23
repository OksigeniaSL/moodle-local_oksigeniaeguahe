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
 * How the families of a campaign decide, all of them together and never one family marked: those that stayed in their
 * first word, how long they take from it to a visit and to enrolling, those that come more than once, those that had
 * been in an earlier campaign, those that did not enrol a child before and come back with another, and those that
 * enrolled with the course already started after looking months before. By the block of the channel they came by and
 * by who they are, so the centre sees what works, for whom, and how fast.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class decide {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int With nothing after its first word for this long, a family stayed there. */
    const QUIET = 21 * DAYSECS;

    /** @var int How long before enrolling a family that enrolls with the course started had come to look. */
    const LOOKED = 120 * DAYSECS;

    /** @var int The cases a median needs before it is said. */
    const MIN = 5;

    /**
     * What each family of the campaign did, from what Eguahe keeps of it: its words, the acts it came to, its children
     * and their courses, and the past campaigns imported by the centre.
     *
     * @param \stdClass $centre
     * @param array[] $families Of panel_metrics, with id, first, step and enrolledat; only those of Eguahe count.
     * @param int $since When this campaign started: what came before is an earlier one.
     * @return array id => first, words, visits, tovisit and toenrol (days or null), returning, otherchild, latejoin.
     */
    public static function facts(\stdClass $centre, array $families, int $since): array {
        global $DB;
        $ids = array_values(array_filter(array_map(fn($f) => (int) ($f['id'] ?? 0), $families)));
        if (!$ids) {
            return [];
        }
        $words = [];
        $visits = [];
        $children = [];
        $keys = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $rows = $DB->get_recordset_select(
                households::CONTACTS,
                "householdid $in AND direction = 'in' AND channel <> :page",
                $params + ['page' => lists::PAGE],
                'timecreated ASC',
                'id, householdid, timecreated'
            );
            foreach ($rows as $row) {
                $words[(int) $row->householdid][] = (int) $row->timecreated;
            }
            $rows->close();
            $rows = $DB->get_recordset_sql("SELECT s.id, s.householdid, e.timestart
                                              FROM {" . events::SIGNUPS . "} s
                                              JOIN {" . events::T . "} e ON e.id = s.eventid
                                             WHERE s.householdid $in AND s.attended > 0", $params);
            foreach ($rows as $row) {
                $visits[(int) $row->householdid][] = (int) $row->timestart;
            }
            $rows->close();
            $rows = $DB->get_recordset_sql("SELECT c.id, c.householdid, c.stage, c.stagetime, o.id AS offerid, o.schoolyear,
                                                   o.firstday
                                              FROM {" . households::CHILDREN . "} c
                                         LEFT JOIN {" . centres::OFFERS . "} o ON o.id = c.offerid
                                             WHERE c.householdid $in AND c.roster = 0", $params);
            foreach ($rows as $row) {
                $children[(int) $row->householdid][] = $row;
            }
            $rows->close();
            $rows = $DB->get_recordset_select(households::ADULTS, "householdid $in", $params, '', 'id, householdid, email, phone');
            foreach ($rows as $row) {
                $keys[(int) $row->householdid][] = $row->email ? pseudonym::email((string) $row->email) : null;
                $keys[(int) $row->householdid][] = $row->phone ? pseudonym::phone((string) $row->phone) : null;
            }
            $rows->close();
        }
        // The families that left a contact in a campaign imported by the centre, before this one.
        $before = [];
        $tokens = (new pseudonym((int) $centre->id))->find_each('household', $keys);
        $all = array_values(array_unique(array_merge([], ...array_values($tokens))));
        foreach (array_chunk($all, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            foreach (
                $DB->get_fieldset_select(
                    metrics::CONTACTS,
                    'DISTINCT household',
                    "centreid = :centreid AND household $in AND timecontact > 0 AND timecontact < :since",
                    $params + ['centreid' => $centre->id, 'since' => $since]
                ) as $token
            ) {
                $before[$token] = true;
            }
        }
        $days = fn(int $from, int $to) => (int) round(max(0, $to - $from) / DAYSECS);
        $out = [];
        foreach ($families as $f) {
            $id = (int) ($f['id'] ?? 0);
            if (!$id) {
                continue;
            }
            $first = min(array_filter([(int) $f['first'], $words[$id][0] ?? 0]));
            $came = $visits[$id] ?? [];
            sort($came);
            $firstvisit = $came ? $came[0] : 0;
            $mine = $children[$id] ?? [];
            // A child of an earlier school year that was not enrolled, and another one asking later.
            $years = [];
            foreach ($mine as $child) {
                if ((string) $child->schoolyear !== '') {
                    $years[(string) $child->schoolyear][] = $child->stage;
                }
            }
            ksort($years);
            $other = false;
            $seenopen = false;
            foreach ($years as $stages) {
                if ($seenopen) {
                    $other = true;
                    break;
                }
                $seenopen = !in_array('enrolled', $stages, true);
            }
            // Enrolled once its course had started, having come to look months before.
            $late = false;
            foreach ($mine as $child) {
                if ($child->stage !== 'enrolled' || !$child->offerid) {
                    continue;
                }
                $start = firstdays::of($child, $centre) ?: (int) make_timestamp((int) substr((string) $child->schoolyear, 0, 4), 9, 1);
                $late = $late || ((int) $child->stagetime > $start && $first <= (int) $child->stagetime - self::LOOKED);
            }
            $out[$id] = [
                'first' => $first,
                'words' => count($words[$id] ?? []),
                'visits' => count($came),
                'tovisit' => $firstvisit && $firstvisit >= $first ? $days($first, $firstvisit) : null,
                'toenrol' => (int) $f['enrolledat'] > 0 ? $days($first, (int) $f['enrolledat']) : null,
                'returning' => $first < $since || (bool) array_intersect_key(array_flip($tokens[$id] ?? []), $before),
                'otherchild' => $other,
                'latejoin' => $late,
            ];
        }
        return $out;
    }

    /**
     * How a group of families decided, added up.
     *
     * @param array[] $families Of panel_metrics, each with id and step.
     * @param array $facts From facts().
     * @param int $now
     * @return array n, quiet (families old enough to tell), stayed, multi, multienrolled, once, onceenrolled, tovisit and
     *     toenrol (lists of days), returning, returningenrolled, otherchild, otherchildenrolled, latejoin.
     */
    public static function add(array $families, array $facts, int $now): array {
        $out = ['n' => 0, 'quiet' => 0, 'stayed' => 0, 'multi' => 0, 'multienrolled' => 0, 'once' => 0, 'onceenrolled' => 0,
            'tovisit' => [], 'toenrol' => [], 'returning' => 0, 'returningenrolled' => 0, 'otherchild' => 0,
            'otherchildenrolled' => 0, 'latejoin' => 0];
        foreach ($families as $f) {
            $x = $facts[(int) ($f['id'] ?? 0)] ?? null;
            if (!$x) {
                continue;
            }
            $enrolled = $f['step'] >= 5;
            $out['n']++;
            if ($x['first'] <= $now - self::QUIET) {
                $out['quiet']++;
                // Nothing after their first word: no other message, no visit, no step, no enrolling.
                $out['stayed'] += $x['words'] <= 1 && !$x['visits'] && $f['step'] <= 1 && !$enrolled ? 1 : 0;
            }
            if ($x['visits'] >= 2) {
                $out['multi']++;
                $out['multienrolled'] += $enrolled ? 1 : 0;
            } else if ($x['visits'] === 1) {
                $out['once']++;
                $out['onceenrolled'] += $enrolled ? 1 : 0;
            }
            if ($x['tovisit'] !== null) {
                $out['tovisit'][] = $x['tovisit'];
            }
            if ($x['toenrol'] !== null) {
                $out['toenrol'][] = $x['toenrol'];
            }
            foreach (['returning', 'otherchild'] as $key) {
                if ($x[$key]) {
                    $out[$key]++;
                    $out[$key . 'enrolled'] += $enrolled ? 1 : 0;
                }
            }
            $out['latejoin'] += $x['latejoin'] ? 1 : 0;
        }
        return $out;
    }

    /**
     * The middle of a list of days and its middle half, when there are enough of them.
     *
     * @param int[] $days
     * @return array|null median, low and high.
     */
    public static function middle(array $days): ?array {
        if (count($days) < self::MIN) {
            return null;
        }
        sort($days);
        $at = function (float $q) use ($days): int {
            $pos = $q * (count($days) - 1);
            $low = (int) floor($pos);
            $high = (int) ceil($pos);
            return (int) round($days[$low] + ($days[$high] - $days[$low]) * ($pos - $low));
        };
        return ['median' => $at(0.5), 'low' => $at(0.25), 'high' => $at(0.75)];
    }

    /**
     * How the families of a campaign decide, in sentences and by row: by the block of the channel they came by and by
     * who they are.
     *
     * @param \stdClass $centre
     * @param array[] $families Of panel_metrics, with who and voices.channel.
     * @param int $since When the campaign started.
     * @param int $now
     * @return array|false lines (text), rows (name, n, stayed, tovisit, toenrol, multi), def; false with nothing to say.
     */
    public static function summary(\stdClass $centre, array $families, int $since, int $now) {
        $c = self::C;
        $families = array_values(array_filter($families, fn($f) => (int) ($f['id'] ?? 0) > 0));
        $facts = self::facts($centre, $families, $since);
        if (!$facts) {
            return false;
        }
        $all = self::add($families, $facts, $now);
        $lines = self::lines($all);
        if (!$lines) {
            return false;
        }
        // By block and by who: each row with the same four figures, so they can be set side by side.
        $blockof = [];
        foreach (arrival::CHANNELS as $key) {
            $blockof[arrival::channel_name($key)] = arrival::block($key);
        }
        $groups = [];
        foreach (['own', 'paid', 'other', 'unmeasured'] as $block) {
            $groups[get_string('panel_bychannel_group_' . $block, $c)] = array_filter(
                $families,
                fn($f) => ($blockof[$f['voices']['channel'] ?? ''] ?? '') === $block
            );
        }
        $groups[get_string('who_new', $c)] = array_filter($families, fn($f) => ($f['who'] ?? 'new') === 'new');
        $groups[get_string('decide_knew', $c)] = array_filter($families, fn($f) => ($f['who'] ?? 'new') !== 'new');
        $rows = [];
        foreach ($groups as $name => $group) {
            $sum = self::add($group, $facts, $now);
            if (!$sum['n']) {
                continue;
            }
            $visit = self::middle($sum['tovisit']);
            $enrol = self::middle($sum['toenrol']);
            $rows[] = ['name' => $name, 'n' => $sum['n'],
                'stayed' => $sum['quiet'] ? round(100 * $sum['stayed'] / $sum['quiet']) . ' %' : '—',
                'tovisit' => $visit ? get_string('decide_days', $c, $visit['median']) : '—',
                'toenrol' => $enrol ? get_string('decide_days', $c, $enrol['median']) : '—',
                'multi' => (string) $sum['multi'],
                'who' => in_array($name, [get_string('who_new', $c), get_string('decide_knew', $c)], true)];
        }
        // The rows of who they are, apart from those of the channels.
        foreach ($rows as $i => $row) {
            $rows[$i]['split'] = $row['who'] && ($i === 0 || !$rows[$i - 1]['who']);
        }
        return ['lines' => array_map(fn($l) => ['text' => $l], $lines), 'rows' => $rows,
            'def' => get_string('decide_def', $c, self::QUIET / DAYSECS), 'raw' => self::raw($all)];
    }

    /**
     * The sentences of a sum: only what has something to say.
     *
     * @param array $sum From add().
     * @return string[]
     */
    public static function lines(array $sum): array {
        $c = self::C;
        $out = [];
        if ($sum['quiet']) {
            $out[] = get_string('decide_stayed', $c, (object) ['n' => $sum['stayed'], 'of' => $sum['quiet'],
                'pct' => round(100 * $sum['stayed'] / $sum['quiet']) . ' %', 'days' => self::QUIET / DAYSECS]);
        }
        foreach (['tovisit', 'toenrol'] as $key) {
            $m = self::middle($sum[$key]);
            if ($m) {
                $out[] = get_string('decide_' . $key, $c, (object) ($m + ['n' => count($sum[$key])]));
            }
        }
        if ($sum['multi']) {
            $out[] = get_string('decide_multi', $c, (object) ['n' => $sum['multi'],
                'frac' => panel_metrics::fraction($sum['multienrolled'], $sum['multi']),
                'oncefrac' => panel_metrics::fraction($sum['onceenrolled'], $sum['once'])]);
        }
        foreach (['returning', 'otherchild'] as $key) {
            if ($sum[$key]) {
                $out[] = get_string('decide_' . $key, $c, (object) ['n' => $sum[$key], 'enrolled' => $sum[$key . 'enrolled']]);
            }
        }
        if ($sum['latejoin']) {
            $out[] = get_string('decide_latejoin', $c, $sum['latejoin']);
        }
        return $out;
    }

    /**
     * The sum for the AI of the reports, with words for each figure.
     *
     * @param array $sum From add().
     * @return array
     */
    protected static function raw(array $sum): array {
        $visit = self::middle($sum['tovisit']);
        $enrol = self::middle($sum['toenrol']);
        return ['families' => $sum['n'], 'old enough to tell (three weeks)' => $sum['quiet'],
            'stayed in their first word' => $sum['stayed'],
            'days from the first word to the first visit (median, middle half)' => $visit,
            'days from the first word to enrolling (median, middle half)' => $enrol,
            'came more than once' => $sum['multi'], 'came more than once and enrolled' => $sum['multienrolled'],
            'came once' => $sum['once'], 'came once and enrolled' => $sum['onceenrolled'],
            'had been in an earlier campaign' => $sum['returning'],
            'had been in an earlier campaign and enrolled' => $sum['returningenrolled'],
            'did not enrol a child before and come back with another' => $sum['otherchild'],
            'enrolled with the course started, having looked months before' => $sum['latejoin']];
    }
}
