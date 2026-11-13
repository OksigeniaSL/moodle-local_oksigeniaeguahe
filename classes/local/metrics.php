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
 * The numbers that do not deceive: never the contacts alone, always the way to the enrolment and the balance of those
 * who come and go. Each number has one definition, written where it is shown, so one year compares with the next.
 *
 * Households, not contacts: a family that writes three times is one. A channel is seen from both ends, the first contact
 * and the last, because no source alone is the truth.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class metrics {
    /** @var string */
    const CONTACTS = 'local_oksigeniaeguahe_pastcontact';

    /** @var string */
    const STUDENTS = 'local_oksigeniaeguahe_student';

    /** @var string */
    const TOTALS = 'local_oksigeniaeguahe_enrolcount';

    /** @var string */
    const SPEND = 'local_oksigeniaeguahe_spend';

    /** @var int Days after the start of a school year that still count as starting it. */
    const STARTDAYS = 15;

    /**
     * @var int A family that enrols a child within this time of its first contact came from that campaign: a year and a
     * season, so that the same enrolment never counts for two campaigns in a row.
     */
    const ENROLWINDOW = YEARSECS + 92 * DAYSECS;

    /**
     * The households of an imported campaign, each with what it did. A household is enrolled when the file said so, or
     * when a student of its family (by its email or phone) or the child it named appears for the first time in the lists
     * of the centre after its first contact.
     *
     * @param \stdClass $import
     * @return array Token => [first, last (contacts), contacts, grade, visited, applied, enrolled, incentre, enrolledat]
     */
    public static function households(\stdClass $import): array {
        global $DB;
        $households = [];
        $rows = $DB->get_recordset(self::CONTACTS, ['importid' => $import->id], 'timecontact ASC, id ASC');
        foreach ($rows as $row) {
            $h = $households[$row->household] ?? ['first' => $row, 'last' => $row, 'contacts' => 0, 'grade' => null,
                'visited' => false, 'applied' => false, 'enrolled' => false, 'incentre' => false, 'children' => [],
                'enrolledat' => 0];
            $h['last'] = $row;
            $h['contacts']++;
            $h['grade'] = $h['grade'] ?? $row->graderequested;
            $h['visited'] = $h['visited'] || in_array($row->kind, ['visit_attended', 'open_day_attended'], true);
            $h['applied'] = $h['applied'] || $row->kind === 'application';
            $h['enrolled'] = $h['enrolled'] || (bool) $row->enrolled;
            $h['enrolledat'] = $row->enrolled ? max($h['enrolledat'], (int) $row->timeenrolled) : $h['enrolledat'];
            $h['incentre'] = $h['incentre'] || !empty($row->incentre);
            if (!empty($row->student)) {
                $h['children'][$row->student] = true;
            }
            $households[$row->household] = $h;
        }
        $rows->close();
        // When each student came into the centre, and the students of each family. A new one («join») says it; on the rolls,
        // the first school year a student is on. Those on the oldest roll imported were there already: when they came is
        // not known, so they never count as an enrolment of a campaign.
        $oldest = (string) $DB->get_field_sql(
            "SELECT MIN(schoolyear) FROM {" . self::STUDENTS . "} WHERE centreid = :centreid AND movement = 'roll'",
            ['centreid' => $import->centreid]
        );
        $first = [];
        $rows = $DB->get_recordset_select(
            self::STUDENTS,
            "centreid = :centreid AND movement <> 'leave' AND timein > 0",
            ['centreid' => $import->centreid],
            '',
            'id, student, schoolyear, timein, movement'
        );
        $joined = [];
        foreach ($rows as $row) {
            if ($row->movement === 'join') {
                $joined[$row->student] = min($joined[$row->student] ?? PHP_INT_MAX, (int) $row->timein);
            } else if ((string) $row->schoolyear !== $oldest) {
                $first[$row->student] = min($first[$row->student] ?? PHP_INT_MAX, (int) $row->timein);
            } else {
                $first[$row->student] = 0;
            }
        }
        $rows->close();
        foreach ($first as $student => $when) {
            // On the oldest roll: unknown; a later roll gives when, unless it was there on the oldest one.
            if ($when === 0) {
                unset($first[$student]);
            }
        }
        $first = $joined + $first;
        if (!$first) {
            return $households;
        }
        $family = [];
        $links = $DB->get_recordset_select(
            self::STUDENTS,
            'centreid = :centreid AND household IS NOT NULL',
            ['centreid' => $import->centreid],
            '',
            'id, household, student'
        );
        foreach ($links as $link) {
            $family[$link->household][$link->student] = true;
        }
        $links->close();
        foreach ($households as $token => $h) {
            $since = (int) $h['first']->timecontact;
            foreach (array_keys(($family[$token] ?? []) + $h['children']) as $student) {
                $when = (int) ($first[$student] ?? 0);
                if ($when >= $since && $when <= $since + self::ENROLWINDOW) {
                    $households[$token]['enrolled'] = true;
                    $households[$token]['enrolledat'] = $households[$token]['enrolledat'] ?: $when;
                }
            }
        }
        return $households;
    }

    /**
     * The way of an imported campaign, from the first contact to the enrolment, and where the families came from.
     *
     * @param \stdClass $import
     * @return array
     */
    public static function campaign(\stdClass $import): array {
        $households = self::households($import);
        // The families already at the centre are not prospects: counted apart, out of the way to the enrolment.
        $incentre = count(array_filter($households, fn($h) => $h['incentre']));
        $households = array_filter($households, fn($h) => !$h['incentre']);
        $totals = ['households' => count($households), 'contacts' => 0, 'inquiries' => 0, 'visited' => 0, 'applied' => 0,
            'enrolled' => 0, 'incentre' => $incentre];
        // The rates follow the same households: of those who asked, or came, how many were enrolled.
        $inquiredenrolled = 0;
        $visitedenrolled = 0;
        $groups = ['channel' => [], 'source' => [], 'platform' => [], 'month' => [], 'grade' => []];
        $add = function (string $by, string $key, string $label, array $h, string $end = 'first') use (&$groups): void {
            $g = $groups[$by][$key] ?? ['label' => $label, 'households' => 0, 'visited' => 0, 'enrolled' => 0,
                'lastenrolled' => 0, 'key' => $key];
            if ($end === 'first') {
                $g['households']++;
                $g['visited'] += $h['visited'] ? 1 : 0;
                $g['enrolled'] += $h['enrolled'] ? 1 : 0;
            } else {
                $g['lastenrolled'] += $h['enrolled'] ? 1 : 0;
            }
            $groups[$by][$key] = $g;
        };
        foreach ($households as $h) {
            $totals['contacts'] += $h['contacts'];
            $totals['inquiries'] += $h['grade'] ? 1 : 0;
            $totals['visited'] += $h['visited'] ? 1 : 0;
            $totals['applied'] += $h['applied'] ? 1 : 0;
            $totals['enrolled'] += $h['enrolled'] ? 1 : 0;
            $inquiredenrolled += $h['grade'] && $h['enrolled'] ? 1 : 0;
            $visitedenrolled += $h['visited'] && $h['enrolled'] ? 1 : 0;
            foreach (['first', 'last'] as $end) {
                $row = $h[$end];
                $add('channel', $row->channel, get_string('channel_' . $row->channel, 'local_oksigeniaeguahe'), $h, $end);
                $source = $row->channel . '|' . (string) $row->sourcedetail;
                $add('source', $source, (string) ($row->sourcedetail ?: get_string(
                    'channel_' . $row->channel,
                    'local_oksigeniaeguahe'
                )), $h, $end);
            }
            $first = $h['first'];
            if ($first->channel === 'meta') {
                $platform = (string) ($first->utmsource ?: '?');
                $add('platform', $platform, ['ig' => 'Instagram', 'fb' => 'Facebook'][$platform] ?? $platform, $h);
            }
            $add('month', userdate((int) $first->timecontact, '%Y-%m'), userdate((int) $first->timecontact, '%B %Y'), $h);
            $grade = (string) ($h['grade'] ?? '');
            $add('grade', $grade, $grade !== '' ? $grade : get_string('offer_unknown', 'local_oksigeniaeguahe'), $h);
        }
        $spend = self::spend((int) $import->centreid, (string) $import->label);
        foreach ($groups as $by => $list) {
            foreach ($list as $key => $g) {
                $g['rate'] = $g['households'] ? round(100 * $g['enrolled'] / $g['households'], 1) : 0;
                $cost = $by === 'channel' ? ($spend[$key . '|'] ?? null) : ($by === 'source' ? ($spend[$key] ?? null) : null);
                $g['spend'] = $cost;
                $g['costperenrolled'] = $cost !== null && $g['enrolled'] ? round($cost / $g['enrolled'], 2) : null;
                $list[$key] = $g;
            }
            if ($by === 'month') {
                ksort($list);
            } else {
                uasort($list, fn($a, $b) => [$b['households'], $a['label']] <=> [$a['households'], $b['label']]);
            }
            $groups[$by] = array_values($list);
        }
        $rate = fn(int $part, int $whole) => $whole ? round(100 * $part / $whole, 1) : 0;
        $totals['inquirytoenrolled'] = $rate($inquiredenrolled, $totals['inquiries']);
        $totals['visitedtoenrolled'] = $rate($visitedenrolled, $totals['visited']);
        $channels = array_filter($spend, fn($k) => substr($k, -1) === '|', ARRAY_FILTER_USE_KEY);
        $totals['spend'] = array_sum($channels) ?: null;
        $totals['costperenrolled'] = $totals['spend'] && $totals['enrolled'] ? round($totals['spend'] / $totals['enrolled'], 2) :
            null;
        return ['totals' => $totals] + $groups;
    }

    /**
     * What a centre spent in a campaign, by channel and by campaign of a channel.
     *
     * @param int $centreid
     * @param string $label
     * @return array "channel|source" => amount ("channel|" for the whole channel).
     */
    public static function spend(int $centreid, string $label): array {
        global $DB;
        $whole = [];
        $parts = [];
        foreach ($DB->get_records(self::SPEND, ['centreid' => $centreid, 'label' => $label]) as $row) {
            if ((string) $row->sourcedetail === '') {
                $whole[$row->channel] = ($whole[$row->channel] ?? 0) + (float) $row->amount;
            } else {
                $parts[$row->channel . '|' . $row->sourcedetail] = (float) $row->amount;
            }
        }
        $spend = $parts;
        // A channel costs what the centre gave for all of it or, if it only gave its campaigns, their sum.
        foreach ($parts as $key => $amount) {
            $channel = strstr($key, '|', true);
            if (!isset($whole[$channel])) {
                $spend[$channel . '|'] = ($spend[$channel . '|'] ?? 0) + $amount;
            }
        }
        foreach ($whole as $channel => $amount) {
            $spend[$channel . '|'] = $amount;
        }
        return $spend;
    }

    /**
     * Notes what a centre spent on a channel, or on one campaign of it, in a campaign: 0 says it cost nothing, and
     * nothing removes what was noted.
     *
     * @param int $centreid
     * @param string $label
     * @param string $channel
     * @param string $source Empty for the whole channel.
     * @param float|null $amount
     * @param int $userid
     */
    public static function set_spend(
        int $centreid,
        string $label,
        string $channel,
        string $source,
        ?float $amount,
        int $userid
    ): void {
        global $DB;
        $where = ['centreid' => $centreid, 'label' => $label, 'channel' => $channel];
        $rows = $DB->get_records(self::SPEND, $where);
        foreach ($rows as $row) {
            if ((string) $row->sourcedetail === $source) {
                $DB->delete_records(self::SPEND, ['id' => $row->id]);
            }
        }
        if ($amount !== null && $amount >= 0) {
            $DB->insert_record(self::SPEND, (object) ($where + ['sourcedetail' => $source === '' ? null : $source,
                'amount' => round($amount, 2), 'userid' => $userid, 'timemodified' => time()]));
        }
    }

    /**
     * The school years a centre has students of.
     *
     * @param int $centreid
     * @return string[]
     */
    public static function school_years(int $centreid): array {
        global $DB;
        return $DB->get_fieldset_sql('SELECT DISTINCT schoolyear FROM {' . self::STUDENTS . '} WHERE centreid = :centreid
                                   ORDER BY schoolyear DESC', ['centreid' => $centreid]);
    }

    /**
     * Who came and who went in a school year: the start, the new students, the joins and leaves during the year with
     * their balance, by course and by month, the reasons of the leaves, the schools they came from and went to, and
     * where the students live. One student counts once, though the list of the year and the list of the new ones both
     * name it. Without the whole list of the year, the start and the end are not known and are not made up.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @return array
     */
    public static function movements(int $centreid, string $schoolyear): array {
        global $DB;
        $students = [];
        foreach ($DB->get_records(self::STUDENTS, ['centreid' => $centreid, 'schoolyear' => $schoolyear], 'id ASC') as $r) {
            $s = $students[$r->student] ?? ['roll' => false, 'new' => false, 'left' => false, 'timein' => 0,
                'timeout' => 0, 'grade' => '', 'municipality' => null, 'locality' => '', 'reason' => '', 'from' => '',
                'to' => ''];
            $movement = (string) ($r->movement ?? 'roll');
            $s['roll'] = $s['roll'] || $movement === 'roll';
            $s['new'] = $s['new'] || $movement === 'join';
            if ($movement === 'leave' || (int) $r->timeout !== 0) {
                $s['left'] = true;
                $s['timeout'] = max($s['timeout'], (int) $r->timeout);
                $s['reason'] = $s['reason'] ?: trim((string) $r->outreason);
                $s['to'] = $movement === 'leave' ? ($s['to'] ?: trim((string) $r->otherschool)) : $s['to'];
            }
            if ($movement !== 'leave' && (int) $r->timein > 0) {
                $s['timein'] = $s['timein'] ? min($s['timein'], (int) $r->timein) : (int) $r->timein;
                $s['from'] = $movement === 'join' ? ($s['from'] ?: trim((string) $r->otherschool)) : $s['from'];
            }
            if ($s['grade'] === '' || $movement === 'roll') {
                $s['grade'] = $r->grade !== '' ? grades::label($centreid, (string) $r->grade) : $s['grade'];
            }
            if ($s['municipality'] === null || $movement === 'roll') {
                $s['municipality'] = $r->municipality ?? $s['municipality'];
                $s['locality'] = (string) ($r->locality ?: $s['locality']);
            }
            $students[$r->student] = $s;
        }
        $hasroll = (bool) array_filter($students, fn($s) => $s['roll']);
        // The start of the year is the day most students joined.
        $days = array_count_values(array_map(
            fn($s) => userdate($s['timein'], '%Y-%m-%d', 99, false),
            array_filter($students, fn($s) => $s['timein'] > 0)
        ));
        arsort($days);
        $startday = (string) array_key_first($days);
        $start = $startday !== '' ? (int) strtotime($startday . ' 12:00') : 0;
        $limit = $start + self::STARTDAYS * DAYSECS;
        $totals = ['start' => 0, 'new' => 0, 'joins' => 0, 'leaves' => 0, 'now' => 0];
        $bygrade = [];
        $bymonth = [];
        $reasons = [];
        $places = [];
        $schools = ['from' => [], 'to' => []];
        $nograde = get_string('metrics_nograde', 'local_oksigeniaeguahe');
        foreach ($students as $s) {
            $joined = $s['timein'] > $limit;
            $new = $s['new'] && !$joined;
            $left = $s['left'];
            $grade = $s['grade'] !== '' ? $s['grade'] : $nograde;
            $g = $bygrade[$grade] ?? ['grade' => $grade, 'start' => 0, 'new' => 0, 'joins' => 0, 'leaves' => 0, 'now' => 0];
            foreach (['start' => !$joined, 'new' => $new, 'joins' => $joined, 'leaves' => $left, 'now' => !$left] as $k => $yes) {
                $g[$k] += $yes ? 1 : 0;
                $totals[$k] += $yes ? 1 : 0;
            }
            $bygrade[$grade] = $g;
            if ($joined) {
                $m = userdate($s['timein'], '%Y-%m');
                $bymonth[$m] = ($bymonth[$m] ?? ['month' => $m, 'joins' => 0, 'leaves' => 0]);
                $bymonth[$m]['joins']++;
            }
            if ($left) {
                if ($s['timeout'] > 0) {
                    $m = userdate($s['timeout'], '%Y-%m');
                    $bymonth[$m] = ($bymonth[$m] ?? ['month' => $m, 'joins' => 0, 'leaves' => 0]);
                    $bymonth[$m]['leaves']++;
                }
                $reasons[$s['reason']] = ($reasons[$s['reason']] ?? 0) + 1;
                if ($s['to'] !== '') {
                    $schools['to'][$s['to']] = ($schools['to'][$s['to']] ?? 0) + 1;
                }
            } else {
                $key = (string) ($s['municipality'] ?? '');
                $places[$key] = ($places[$key] ?? ['code' => $key, 'locality' => $s['locality'], 'students' => 0]);
                $places[$key]['students']++;
            }
            if (($s['new'] || $joined) && $s['from'] !== '') {
                $schools['from'][$s['from']] = ($schools['from'][$s['from']] ?? 0) + 1;
            }
        }
        // The balance of the year: who came (new at the start and during the year) less who left.
        $totals['net'] = $totals['new'] + $totals['joins'] - $totals['leaves'];
        if (!$hasroll) {
            $totals['start'] = null;
            $totals['now'] = null;
        }
        foreach ($bygrade as $k => $g) {
            $bygrade[$k]['net'] = $g['new'] + $g['joins'] - $g['leaves'];
        }
        ksort($bymonth);
        arsort($reasons);
        $reasonlist = [];
        foreach ($reasons as $reason => $count) {
            $reasonlist[] = ['reason' => $reason !== '' ? $reason : get_string('metrics_noreason', 'local_oksigeniaeguahe'),
                'count' => $count];
        }
        uasort($places, fn($a, $b) => $b['students'] <=> $a['students']);
        $present = array_sum(array_column($places, 'students'));
        foreach ($places as $k => $p) {
            $places[$k]['share'] = $present ? round(100 * $p['students'] / $present, 1) : 0;
        }
        foreach ($schools as $end => $list) {
            arsort($list);
            $schools[$end] = array_map(
                fn($name, $count) => ['school' => $name, 'count' => $count],
                array_keys($list),
                array_values($list)
            );
        }
        return ['schoolyear' => $schoolyear, 'start' => $start, 'hasroll' => $hasroll, 'totals' => $totals,
            'bygrade' => array_values($bygrade), 'bymonth' => array_values($bymonth), 'reasons' => $reasonlist,
            'places' => array_values($places), 'from' => $schools['from'], 'to' => $schools['to']];
    }

    /**
     * The students of each course and school year: the totals imported, and the start of each year imported by student.
     *
     * @param int $centreid
     * @return array [years (ascending), grades (in the order first seen), matrix grade => year => students, totals by year]
     */
    public static function history(int $centreid): array {
        global $DB;
        $matrix = [];
        $grades = [];
        foreach ($DB->get_records(self::TOTALS, ['centreid' => $centreid], 'schoolyear ASC, id ASC') as $row) {
            $grade = grades::label($centreid, (string) $row->grade);
            $matrix[$grade][$row->schoolyear] = ($matrix[$grade][$row->schoolyear] ?? 0) + (int) $row->students;
            $grades[$grade] = true;
        }
        foreach (self::school_years($centreid) as $year) {
            $m = self::movements($centreid, $year);
            if (!$m['hasroll']) {
                // Only the new ones and the leaves: the students of each course that year are not known.
                continue;
            }
            foreach ($m['bygrade'] as $g) {
                // The year imported by student counts as it started, like the totals of the other years.
                $matrix[$g['grade']][$year] = $g['start'];
                $grades[$g['grade']] = true;
            }
        }
        $years = [];
        foreach ($matrix as $row) {
            $years += array_flip(array_keys($row));
        }
        $years = array_keys($years);
        sort($years);
        $totals = [];
        foreach ($years as $year) {
            $totals[$year] = array_sum(array_map(fn($row) => $row[$year] ?? 0, $matrix));
        }
        return [$years, array_keys($grades), $matrix, $totals];
    }

    /**
     * The families that came through Eguahe, how far they went, by where they first came from: the entry and the place
     * it was shared from, or the channel. Families that move to the area, apart; families already of the centre, only
     * counted.
     *
     * @param int $centreid
     * @return array rows, moving, total (each: name, households, visited, applied, admitted, enrolled, closed), incentre
     */
    public static function live(int $centreid): array {
        global $DB;
        $households = $DB->get_records_sql("SELECT h.id, h.incentre, h.moving, h.firstchannel
                                              FROM {" . households::T . "} h
                                             WHERE h.centreid = :centreid", ['centreid' => $centreid]);
        if (!$households) {
            return ['rows' => [], 'moving' => null, 'total' => null, 'incentre' => 0];
        }
        // How far each family went: the furthest of its children, and whether all of them closed.
        $far = [];
        $closed = [];
        foreach (
            $DB->get_records_select(
                households::CHILDREN,
                'centreid = :centreid',
                ['centreid' => $centreid],
                '',
                'id, householdid, stage'
            ) as $child
        ) {
            $id = (int) $child->householdid;
            $closed[$id] = ($closed[$id] ?? true) && $child->stage === 'closed';
            if ($child->stage !== 'closed') {
                $far[$id] = max($far[$id] ?? 1, lists::STAGE_STEP[$child->stage] ?? 1);
            }
        }
        // Where each family first came from: the entry and the place it was shared from, or the channel.
        $first = [];
        $entries = $DB->get_records_menu(entries::T, ['centreid' => $centreid], '', 'id, name');
        foreach (
            $DB->get_records_select(
                households::CONTACTS,
                "centreid = :centreid AND direction = 'in' AND channel <> 'page'",
                ['centreid' => $centreid],
                'timecreated ASC, id ASC',
                'id, householdid, channel, entryid, detail'
            ) as $contact
        ) {
            $id = (int) $contact->householdid;
            if (isset($first[$id])) {
                continue;
            }
            $name = $contact->entryid && isset($entries[$contact->entryid]) ? format_string($entries[$contact->entryid]) :
                get_string(
                    'channel_' . (in_array($contact->channel, lists::CHANNELS, true) ? $contact->channel : 'other'),
                    'local_oksigeniaeguahe'
                );
            $first[$id] = $name . ($contact->detail ? ' · ' . $contact->detail : '');
        }
        $blank = fn(string $name) => ['name' => $name, 'households' => 0, 'visited' => 0, 'applied' => 0, 'admitted' => 0,
            'enrolled' => 0, 'closed' => 0];
        $add = function (array &$row, int $id) use ($far, $closed) {
            $row['households']++;
            $step = $far[$id] ?? 1;
            $row['visited'] += $step >= 2 ? 1 : 0;
            $row['applied'] += $step >= 3 ? 1 : 0;
            $row['admitted'] += $step >= 4 ? 1 : 0;
            $row['enrolled'] += $step >= 5 ? 1 : 0;
            $row['closed'] += !empty($closed[$id]) ? 1 : 0;
        };
        $rows = [];
        $moving = $blank(get_string('metrics_live_moving', 'local_oksigeniaeguahe'));
        $total = $blank(get_string('metrics_live_total', 'local_oksigeniaeguahe'));
        $incentre = 0;
        foreach ($households as $household) {
            $id = (int) $household->id;
            if ($household->incentre) {
                // Families already of the centre: counted apart, never in the numbers of who comes.
                $incentre++;
                continue;
            }
            if ($household->moving) {
                // Families that move to the area: a group of their own, not lost for coming later.
                $add($moving, $id);
                continue;
            }
            $key = $first[$id] ?? get_string('channel_' . (in_array($household->firstchannel, lists::CHANNELS, true) ?
                $household->firstchannel : 'other'), 'local_oksigeniaeguahe');
            $rows[$key] = $rows[$key] ?? $blank($key);
            $add($rows[$key], $id);
            $add($total, $id);
        }
        uasort($rows, fn($a, $b) => [$b['enrolled'], $b['households']] <=> [$a['enrolled'], $a['households']]);
        return ['rows' => array_values($rows), 'moving' => $moving['households'] ? $moving : null,
            'total' => $total['households'] ? $total : null, 'incentre' => $incentre];
    }

    /**
     * The campaign in Eguahe itself: how long the families wait for a first answer from a person, and who has none yet.
     *
     * @param int $centreid
     * @return array median hours to the first answer, households answered, households still waiting.
     */
    public static function response(int $centreid): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT c.householdid,
                                             MIN(CASE WHEN c.direction = 'in' THEN c.timecreated END) AS firstin,
                                             MIN(CASE WHEN c.direction = 'out' THEN c.timecreated END) AS firstout
                                        FROM {local_oksigeniaeguahe_contact} c
                                        JOIN {local_oksigeniaeguahe_household} h ON h.id = c.householdid
                                       WHERE c.centreid = :centreid AND h.incentre = 0
                                    GROUP BY c.householdid", ['centreid' => $centreid]);
        $hours = [];
        foreach ($rows as $row) {
            if ($row->firstin && $row->firstout && $row->firstout >= $row->firstin) {
                $hours[] = ($row->firstout - $row->firstin) / HOURSECS;
            }
        }
        sort($hours);
        $median = $hours ? $hours[(int) floor((count($hours) - 1) / 2)] : null;
        return ['median' => $median === null ? null : round($median, 1), 'answered' => count($hours),
            'waiting' => households::waiting_count($centreid)];
    }
}
