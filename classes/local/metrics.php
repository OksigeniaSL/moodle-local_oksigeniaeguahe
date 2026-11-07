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
     * The households of an imported campaign, each with what it did.
     *
     * @param int $importid
     * @return array Token => [first, last (contacts), contacts, grade, visited, applied, enrolled]
     */
    protected static function households(int $importid): array {
        global $DB;
        $households = [];
        $rows = $DB->get_recordset(self::CONTACTS, ['importid' => $importid], 'timecontact ASC, id ASC');
        foreach ($rows as $row) {
            $h = $households[$row->household] ?? ['first' => $row, 'last' => $row, 'contacts' => 0, 'grade' => null,
                'visited' => false, 'applied' => false, 'enrolled' => false];
            $h['last'] = $row;
            $h['contacts']++;
            $h['grade'] = $h['grade'] ?? $row->graderequested;
            $h['visited'] = $h['visited'] || in_array($row->kind, ['visit_attended', 'open_day_attended'], true);
            $h['applied'] = $h['applied'] || $row->kind === 'application';
            $h['enrolled'] = $h['enrolled'] || (bool) $row->enrolled;
            $households[$row->household] = $h;
        }
        $rows->close();
        return $households;
    }

    /**
     * The way of an imported campaign, from the first contact to the enrolment, and where the families came from.
     *
     * @param \stdClass $import
     * @return array
     */
    public static function campaign(\stdClass $import): array {
        $households = self::households((int) $import->id);
        $totals = ['households' => count($households), 'contacts' => 0, 'inquiries' => 0, 'visited' => 0, 'applied' => 0,
            'enrolled' => 0];
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
     * Notes what a centre spent on a channel, or on one campaign of it, in a campaign. 0 removes it.
     *
     * @param int $centreid
     * @param string $label
     * @param string $channel
     * @param string $source Empty for the whole channel.
     * @param float $amount
     * @param int $userid
     */
    public static function set_spend(
        int $centreid,
        string $label,
        string $channel,
        string $source,
        float $amount,
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
        if ($amount > 0) {
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
     * Who came and who went in a school year: the start, the joins and leaves during the year with their balance, by
     * course and by month, the reasons of the leaves, and where the students live.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @return array
     */
    public static function movements(int $centreid, string $schoolyear): array {
        global $DB;
        $students = $DB->get_records(self::STUDENTS, ['centreid' => $centreid, 'schoolyear' => $schoolyear], 'id ASC');
        // The start of the year is the day most students joined.
        $days = array_count_values(array_map(fn($s) => userdate((int) $s->timein, '%Y-%m-%d'), $students));
        arsort($days);
        $startday = (string) array_key_first($days);
        $start = $startday !== '' ? (int) strtotime($startday . ' 12:00') : 0;
        $limit = $start + self::STARTDAYS * DAYSECS;
        $totals = ['start' => 0, 'joins' => 0, 'leaves' => 0, 'now' => 0];
        $bygrade = [];
        $bymonth = [];
        $reasons = [];
        $places = [];
        foreach ($students as $s) {
            $joined = (int) $s->timein > $limit;
            $left = (int) $s->timeout > 0;
            $g = $bygrade[$s->grade] ?? ['grade' => $s->grade, 'start' => 0, 'joins' => 0, 'leaves' => 0, 'now' => 0];
            $g['start'] += $joined ? 0 : 1;
            $g['joins'] += $joined ? 1 : 0;
            $g['leaves'] += $left ? 1 : 0;
            $g['now'] += $left ? 0 : 1;
            $bygrade[$s->grade] = $g;
            $totals['start'] += $joined ? 0 : 1;
            $totals['joins'] += $joined ? 1 : 0;
            $totals['leaves'] += $left ? 1 : 0;
            $totals['now'] += $left ? 0 : 1;
            if ($joined) {
                $m = userdate((int) $s->timein, '%Y-%m');
                $bymonth[$m] = ($bymonth[$m] ?? ['month' => $m, 'joins' => 0, 'leaves' => 0]);
                $bymonth[$m]['joins']++;
            }
            if ($left) {
                $m = userdate((int) $s->timeout, '%Y-%m');
                $bymonth[$m] = ($bymonth[$m] ?? ['month' => $m, 'joins' => 0, 'leaves' => 0]);
                $bymonth[$m]['leaves']++;
                $reason = trim((string) $s->outreason);
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            } else {
                $key = (string) ($s->municipality ?? '');
                $places[$key] = ($places[$key] ?? ['code' => $key, 'locality' => (string) $s->locality, 'students' => 0]);
                $places[$key]['students']++;
            }
        }
        $totals['net'] = $totals['joins'] - $totals['leaves'];
        foreach ($bygrade as $k => $g) {
            $bygrade[$k]['net'] = $g['joins'] - $g['leaves'];
        }
        ksort($bymonth);
        arsort($reasons);
        $reasonlist = [];
        foreach ($reasons as $reason => $count) {
            $reasonlist[] = ['reason' => $reason !== '' ? $reason : get_string('metrics_noreason', 'local_oksigeniaeguahe'),
                'count' => $count];
        }
        uasort($places, fn($a, $b) => $b['students'] <=> $a['students']);
        foreach ($places as $k => $p) {
            $places[$k]['share'] = $totals['now'] ? round(100 * $p['students'] / $totals['now'], 1) : 0;
        }
        return ['schoolyear' => $schoolyear, 'start' => $start, 'totals' => $totals, 'bygrade' => array_values($bygrade),
            'bymonth' => array_values($bymonth), 'reasons' => $reasonlist, 'places' => array_values($places)];
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
            $matrix[$row->grade][$row->schoolyear] = (int) $row->students;
            $grades[$row->grade] = true;
        }
        foreach (self::school_years($centreid) as $year) {
            foreach (self::movements($centreid, $year)['bygrade'] as $g) {
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
