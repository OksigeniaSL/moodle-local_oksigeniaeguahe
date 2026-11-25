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

    /** @var int How far from the first day of a school year its own start may be. */
    const AROUND = 60 * DAYSECS;

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
     * @return array Token => [first, last (contacts), contacts, grade, visited, applied, enrolled, incentre, enrolledat, students]
     */
    public static function households(\stdClass $import): array {
        global $DB;
        $households = [];
        $rows = $DB->get_recordset(self::CONTACTS, ['importid' => $import->id], 'timecontact ASC, id ASC');
        foreach ($rows as $row) {
            $h = $households[$row->household] ?? ['first' => $row, 'last' => $row, 'contacts' => 0, 'grade' => null,
                'visited' => false, 'applied' => false, 'enrolled' => false, 'incentre' => false, 'children' => [],
                'enrolledat' => 0, 'students' => 0];
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
        [$first, $family] = self::came_in((int) $import->centreid);
        if (!$first) {
            return $households;
        }
        foreach ($households as $token => $h) {
            $since = (int) $h['first']->timecontact;
            foreach (array_keys(($family[$token] ?? []) + $h['children']) as $student) {
                $when = (int) ($first[$student] ?? 0);
                if ($when >= $since && $when <= $since + self::ENROLWINDOW) {
                    $households[$token]['enrolled'] = true;
                    $households[$token]['students']++;
                    $households[$token]['enrolledat'] = $households[$token]['enrolledat'] ?: $when;
                }
            }
        }
        return $households;
    }

    /** @var array Centre id => when its students came in and the students of each family, once per request. */
    protected static $camein = [];

    /**
     * When each student came into a centre, as its lists say, and the students of each family.
     *
     * @param int $centreid
     * @return array[] student => moment, and household token => student => true
     */
    protected static function came_in(int $centreid): array {
        global $DB;
        if (isset(self::$camein[$centreid]) && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return self::$camein[$centreid];
        }
        // When each student came into the centre, and the students of each family. A new one («join») says it; on the rolls,
        // the first school year a student is on. Those on the oldest roll imported were there already: when they came is
        // not known, so they never count as an enrolment of a campaign.
        $oldest = (string) $DB->get_field_sql(
            "SELECT MIN(schoolyear) FROM {" . self::STUDENTS . "} WHERE centreid = :centreid AND movement = 'roll'
                AND " . imports::NOTPARTIAL,
            ['centreid' => $centreid]
        );
        $first = [];
        $rows = $DB->get_recordset_select(
            self::STUDENTS,
            "centreid = :centreid AND movement <> 'leave' AND timein > 0 AND " . imports::NOTPARTIAL,
            ['centreid' => $centreid],
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
            return self::$camein[$centreid] = [[], []];
        }
        $family = [];
        $links = $DB->get_recordset_select(
            self::STUDENTS,
            'centreid = :centreid AND household IS NOT NULL',
            ['centreid' => $centreid],
            '',
            'id, household, student'
        );
        foreach ($links as $link) {
            $family[$link->household][$link->student] = true;
        }
        $links->close();
        return self::$camein[$centreid] = [$first, $family];
    }

    /**
     * The school year before another: «2025-26» before «2026-27», «2025» before «2026».
     *
     * @param string $year
     * @return string
     */
    public static function year_before(string $year): string {
        $first = (int) substr($year, 0, 4);
        return strlen($year) > 4 ? sprintf('%d-%02d', $first - 1, $first % 100) : (string) ($first - 1);
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
     * A date of entry from years before is never a join of this one.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @return array With, besides, dated (moment and joins or leaves) and undated (the leaves without a date).
     */
    public static function movements(int $centreid, string $schoolyear): array {
        global $DB;
        $students = [];
        // A list with only a part of the centre (its nursery) is not the whole list of the year: it does not count here.
        $rows = $DB->get_records_select(
            self::STUDENTS,
            'centreid = :centreid AND schoolyear = :year AND ' . imports::NOTPARTIAL,
            ['centreid' => $centreid, 'year' => $schoolyear],
            'id ASC'
        );
        foreach ($rows as $r) {
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
        // A date from before the year is the day the student came into the centre, years ago: it was there already. The
        // start of the year is the day most students joined, among the days around its first one.
        $opens = imports::year_start($schoolyear);
        $days = [];
        foreach ($students as $student => $s) {
            if ($s['timein'] && $s['timein'] < $opens - self::AROUND) {
                $students[$student]['timein'] = 0;
            } else if ($s['timein'] && $s['timein'] < $opens + self::AROUND && $s['timein'] !== $opens - HOURSECS) {
                // Not those that came years ago, put there from its start (imports).
                $day = userdate($s['timein'], '%Y-%m-%d', 99, false);
                $days[$day] = ($days[$day] ?? 0) + 1;
            }
        }
        arsort($days);
        $startday = (string) array_key_first($days);
        $start = $startday !== '' ? (int) strtotime($startday . ' 12:00') : ($students ? $opens : 0);
        $limit = $start + self::STARTDAYS * DAYSECS;
        $dated = [];
        $undated = 0;
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
                $dated[] = [$s['timein'], 'joins'];
                $bymonth[$m] = ($bymonth[$m] ?? ['month' => $m, 'joins' => 0, 'leaves' => 0]);
                $bymonth[$m]['joins']++;
            }
            if ($left) {
                $undated += $s['timeout'] > 0 ? 0 : 1;
                if ($s['timeout'] > 0) {
                    $m = userdate($s['timeout'], '%Y-%m');
                    $dated[] = [$s['timeout'], 'leaves'];
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
            'places' => array_values($places), 'from' => $schools['from'], 'to' => $schools['to'],
            'dated' => $dated, 'undated' => $undated];
    }

    /**
     * The school years whose lists say someone joined the centre, the latest first.
     *
     * @param int $centreid
     * @return string[]
     */
    public static function years_with_joins(int $centreid): array {
        global $DB;
        $joined = array_flip(array_map('strval', $DB->get_fieldset_sql(
            "SELECT DISTINCT schoolyear FROM {" . self::STUDENTS . "} WHERE centreid = :centreid AND movement = 'join'",
            ['centreid' => $centreid]
        )));
        return array_values(array_filter(array_map('strval', self::school_years($centreid)), fn($y) => isset($joined[$y])));
    }

    /**
     * The school years with new students, the latest first: those whose lists say someone joined, and those Eguahe has
     * already enrolled children for.
     *
     * @param int $centreid
     * @return string[]
     */
    public static function years_with_new(int $centreid): array {
        global $DB;
        $live = $DB->get_fieldset_sql(
            "SELECT DISTINCT o.schoolyear
               FROM {" . households::CHILDREN . "} c
               JOIN {" . centres::OFFERS . "} o ON o.id = c.offerid AND o.centreid = c.centreid
              WHERE c.centreid = :centreid AND c.stage = 'enrolled' AND c.roster = 0 AND c.lefttime = 0",
            ['centreid' => $centreid]
        );
        $years = array_values(array_unique(array_merge(self::years_with_joins($centreid), array_filter(array_map('strval', $live)))));
        rsort($years);
        return $years;
    }

    /**
     * Where the new students of a school year come from, worked out once per request.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @return array As origins_of() gives it.
     */
    public static function origins(int $centreid, string $schoolyear): array {
        static $done = [];
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            // The tests change the lists between calls.
            return self::origins_of($centreid, $schoolyear);
        }
        return $done[$centreid . '|' . $schoolyear] ??= self::origins_of($centreid, $schoolyear);
    }

    /** @var string[] The kinds of new students: those already at the centre the year before, those that were there and come
     * back, brothers and sisters, children of the staff, new families, and those without data of their family. */
    const GROUPS = ['inner', 'back', 'sibling', 'staff', 'newfamily', 'unknown'];

    /** @var string[] What brought the new families: the centre's own channels, an ad, other ways, another list, nothing known. */
    const BROUGHT = ['own', 'ad', 'other', 'campaign', 'none'];

    /**
     * Where the new students of a school year come from, by their keys and never by a name: already at the centre the
     * year before (an inner step, such as from its nursery), there years before and back, brothers and sisters of students
     * who were already there, new families (and of these, what Eguahe measured or they said; if not, the ones that left their
     * contact before in an ad, in another campaign or act, or in nothing counted), and those whose family left no contact to
     * tell. Brothers and sisters need the whole list of the year; without it they are not told apart.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @return array total, hasroll, groups (GROUPS: n and by grade), newby (BROUGHT), kinds (student => group), parts (student
     *     => what brought it), homes (student => household keys), families (student => household of Eguahe) and centre.
     */
    protected static function origins_of(int $centreid, string $schoolyear): array {
        global $DB;
        $new = $roll = $grade = $homes = $past = [];
        $rows = $DB->get_recordset_select(
            self::STUDENTS,
            "centreid = :centreid AND movement <> 'leave'",
            ['centreid' => $centreid],
            '',
            'id, student, household, movement, grade, schoolyear, importid'
        );
        $partial = array_flip($DB->get_fieldset_select(imports::T, 'id', 'centreid = ? AND partial = 1', [$centreid]));
        $whole = false;
        foreach ($rows as $row) {
            if ((string) $row->schoolyear === $schoolyear) {
                if ($row->movement === 'join') {
                    $new[$row->student] = true;
                } else if ($row->movement === 'roll') {
                    $roll[$row->student] = true;
                    $whole = $whole || !isset($partial[$row->importid]);
                }
                if ((string) $row->grade !== '' && ($row->movement === 'roll' || !isset($grade[$row->student]))) {
                    $grade[$row->student] = (string) $row->grade;
                }
            } else if (strcmp((string) $row->schoolyear, $schoolyear) < 0) {
                $past[(string) $row->schoolyear][$row->student] = true;
            }
            if ($row->household) {
                $homes[$row->student][$row->household] = true;
            }
        }
        $rows->close();
        // An inner step is of who was there the year before; of who was there only earlier, that it is back.
        $before = $past[self::year_before($schoolyear)] ?? [];
        $inner = array_intersect_key($new, $before);
        $back = array_diff_key(array_intersect_key($new, array_reduce($past, fn($all, $some) => $all + $some, [])), $inner);
        // Families with a child already at the centre this year: on the list and not new, or new by an inner step.
        $there = array_diff_key($roll, $new) + $inner;
        $centre = [];
        foreach (array_keys($there) as $student) {
            foreach (array_keys($homes[$student] ?? []) as $home) {
                $centre[$home] = true;
            }
        }
        // What each family left before in a campaign: an ad (a contact from ads, or in a campaign with money spent on
        // it, like ads that took families to the website), or another campaign or act; and when it first did.
        $paid = [];
        foreach ($DB->get_records(imports::T, ['centreid' => $centreid, 'kind' => 'contacts'], '', 'id, label') as $import) {
            $spend = self::spend($centreid, (string) $import->label);
            if (array_filter($spend, fn($k) => substr($k, -1) === '|', ARRAY_FILTER_USE_KEY)) {
                $paid[$import->id] = true;
            }
        }
        $ads = [];
        $other = [];
        $contacts = $DB->get_recordset_select(
            self::CONTACTS,
            'centreid = :centreid',
            ['centreid' => $centreid],
            '',
            'id, household, channel, importid, timecontact'
        );
        foreach ($contacts as $row) {
            $time = (int) $row->timecontact;
            if ($row->channel === 'meta' || isset($paid[$row->importid])) {
                $ads[$row->household] = min($ads[$row->household] ?? $time, $time);
            } else {
                $other[$row->household] = min($other[$row->household] ?? $time, $time);
            }
        }
        $contacts->close();
        $groups = [];
        foreach (self::GROUPS as $key) {
            $groups[$key] = ['n' => 0, 'grades' => []];
        }
        $newby = array_fill_keys(self::BROUGHT, 0);
        $kinds = [];
        $partof = [];
        // The families of Eguahe of those students, with what is known of how they came.
        $eguahe = self::eguahe($centreid, $schoolyear, array_intersect_key($homes, $new) + array_fill_keys(array_keys($new), []));
        $first = [];
        if ($eguahe) {
            $object = centres::get($centreid);
            $facts = $object ? facts::read($object, ['key' => 'all', 'year' => '', 'since' => 0, 'until' => 0, 'import' => null]) : [];
            foreach ($eguahe as $student => $hid) {
                if (isset($facts[$hid])) {
                    $first[$student] = [arrival::brought($facts[$hid]['channel']), $facts[$hid]['first']];
                }
            }
        }
        // And what who knows the families marked: the children who were already there, those with brothers or sisters and
        // those of the staff, found in the lists by the emails and phones of their family.
        $inside = ties::inside($centreid);
        $marked = [];
        foreach (
            $inside ? $DB->get_records_select(
                listmatch::T,
                "centreid = ? AND schoolyear = ? AND kind = 'enrolled' AND childid > 0",
                [$centreid, $schoolyear],
                '',
                'id, student, childid'
            ) : [] as $row
        ) {
            if (isset($inside[(int) $row->childid])) {
                $marked[$row->student] = true;
            }
        }
        $tied = ['sibling' => [], 'staff' => []];
        foreach (ties::families($centreid) as $id => $what) {
            foreach (array_keys(array_intersect_key($tied, $what)) as $kind) {
                $tied[$kind][] = $id;
            }
        }
        $tied = array_map(fn($ids) => alumni::family_tokens($centreid, $ids), $tied);
        $centre += $tied['sibling'] + $tied['staff'];
        foreach (array_keys($new) as $student) {
            $mine = array_keys($homes[$student] ?? []);
            if (isset($inner[$student]) || isset($marked[$student])) {
                $kind = 'inner';
            } else if (isset($back[$student])) {
                $kind = 'back';
            } else if (!$mine && !isset($eguahe[$student])) {
                $kind = 'unknown';
            } else if (array_intersect_key(array_flip($mine), array_diff_key($centre, $tied['staff']) + $tied['sibling'])) {
                $kind = 'sibling';
            } else if (array_intersect_key(array_flip($mine), $tied['staff'])) {
                $kind = 'staff';
            } else {
                $kind = 'newfamily';
                // What came first, of what is known: an ad, what Eguahe measured or they said, or another list. A list says
                // the family was in it, never what brought it.
                $said = [];
                $ad = array_intersect_key($ads, array_flip($mine));
                if ($ad) {
                    $said['ad'] = min($ad);
                }
                if (isset($first[$student]) && $first[$student][0] !== 'none') {
                    $by = $first[$student][0];
                    $said[$by] = min($said[$by] ?? $first[$student][1], $first[$student][1]);
                }
                asort($said);
                $by = $said ? (string) array_key_first($said) : (array_intersect_key(array_flip($mine), $other) ? 'campaign' : 'none');
                $newby[$by]++;
                $partof[$student] = $by;
            }
            $kinds[$student] = $kind;
            $groups[$kind]['n']++;
            $label = isset($grade[$student]) ? grades::label($centreid, $grade[$student]) : '';
            $groups[$kind]['grades'][$label] = ($groups[$kind]['grades'][$label] ?? 0) + 1;
        }
        // The families of Eguahe itself: a child enrolled for this school year that no whole list of the year has yet
        // (before the list is imported, or with only part of it). With a whole list, the list is the truth.
        if (!$whole) {
            foreach (self::live_new($centreid, $schoolyear, $eguahe) as $student => $live) {
                $new[$student] = true;
                $homes[$student] = [$live['home'] => true];
                $eguahe[$student] = $live['family'];
                $kind = $live['kind'];
                if ($kind === 'newfamily') {
                    $newby[$live['by']]++;
                    $partof[$student] = $live['by'];
                }
                $kinds[$student] = $kind;
                $groups[$kind]['n']++;
                $groups[$kind]['grades'][$live['grade']] = ($groups[$kind]['grades'][$live['grade']] ?? 0) + 1;
            }
        }
        // The new students whose family has a mother, a father or someone who studied at the centre, as far as it is known.
        $known = alumni::known($centreid);
        $alumni = ['n' => 0, 'fresh' => 0, 'known' => $known, 'by' => []];
        if ($known) {
            $tokens = alumni::tokens($centreid);
            $studied = alumni::families($centreid);
            foreach (array_keys($new) as $student) {
                if (array_intersect_key($homes[$student] ?? [], $tokens) || isset($studied[$eguahe[$student] ?? 0])) {
                    $alumni['n']++;
                    $alumni['fresh'] += in_array($kinds[$student], ['newfamily', 'unknown'], true) ? 1 : 0;
                    // By shelf, to put their mortarboard on their backpacks: inner, sibling, or the part of new families.
                    $shelf = $kinds[$student] === 'newfamily' ? $partof[$student] : $kinds[$student];
                    $alumni['by'][$shelf] = ($alumni['by'][$shelf] ?? 0) + 1;
                }
            }
        }
        return ['total' => count($new), 'hasroll' => $whole, 'groups' => $groups, 'newby' => $newby,
            'kinds' => $kinds, 'parts' => $partof, 'homes' => array_map('array_keys', array_intersect_key($homes, $new)),
            'families' => array_intersect_key($eguahe, $new), 'centre' => $centre, 'alumni' => $alumni];
    }

    /**
     * The family of Eguahe of each new student of a school year: the one whose child the list enrolled and, if not, one with
     * the same emails or phones, first the one with a child enrolled for that year.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @param array $homes Student => its household tokens, as keys.
     * @return int[] Student => household.
     */
    protected static function eguahe(int $centreid, string $schoolyear, array $homes): array {
        global $DB;
        $out = [];
        if (!$homes) {
            return $out;
        }
        $families = $DB->get_records_select(households::T, 'centreid = ? AND roster = 0 AND guest = 0', [$centreid], 'id ASC', 'id');
        $rows = $DB->get_records_select(
            listmatch::T,
            "centreid = ? AND schoolyear = ? AND kind IN ('enrolled', 'ask')",
            [$centreid, $schoolyear],
            'kind DESC, id ASC',
            'id, student, householdid, kind'
        );
        foreach ($rows as $row) {
            if (isset($homes[$row->student], $families[(int) $row->householdid])) {
                $out[$row->student] = $out[$row->student] ?? (int) $row->householdid;
            }
        }
        $left = array_diff_key($homes, $out);
        if ($left && $families) {
            $enrolled = array_flip($DB->get_fieldset_sql(
                "SELECT DISTINCT c.householdid
                   FROM {" . households::CHILDREN . "} c
                   JOIN {" . centres::OFFERS . "} o ON o.id = c.offerid
                  WHERE c.centreid = :centreid AND c.stage = 'enrolled' AND c.roster = 0 AND o.schoolyear = :year",
                ['centreid' => $centreid, 'year' => $schoolyear]
            ));
            $byhome = [];
            foreach (facts::tokens($centreid, array_keys($families)) as $id => $tokens) {
                foreach ($tokens as $token) {
                    $byhome[$token][] = (int) $id;
                }
            }
            foreach ($left as $student => $tokens) {
                $ids = array_values(array_unique(array_merge([], ...array_map(fn($t) => $byhome[$t] ?? [], array_keys($tokens)))));
                usort($ids, fn($a, $b) => [isset($enrolled[$b]), $a] <=> [isset($enrolled[$a]), $b]);
                if ($ids) {
                    $out[$student] = $ids[0];
                }
            }
        }
        return $out;
    }

    /**
     * The children Eguahe has enrolled for a school year who are not yet on an imported list of that year (the list
     * did not find them, or there is none): new students too. One already there (the team marked it, or its family is in the
     * nursery), an inner step; of a family already at the centre, a brother or sister, or of its staff; else a new family, by
     * an ad (it came by Meta or by a paid link), the centre's own channels, other ways, or nothing known.
     *
     * @param int $centreid
     * @param string $schoolyear
     * @param int[]|null $matched The families of the students its lists already have (eguahe()): each is one of its children.
     * @return array[] By a key of their own («eg» and the child): home, family, kind, by and grade.
     */
    public static function live_new(int $centreid, string $schoolyear, ?array $matched = null): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT c.id, c.householdid, o.name AS grade, h.firstchannel, h.arrivalsource,
                    h.arrivalmedium, h.arrivalref, h.heardfrom
               FROM {' . households::CHILDREN . '} c
               JOIN {' . households::T . '} h ON h.id = c.householdid
               JOIN {' . centres::OFFERS . '} o ON o.id = c.offerid
              WHERE c.centreid = :centreid AND c.stage = :enrolled AND c.roster = 0 AND c.lefttime = 0 AND h.guest = 0
                    AND o.schoolyear = :year
           ORDER BY c.id ASC',
            ['centreid' => $centreid, 'enrolled' => 'enrolled', 'year' => $schoolyear]
        );
        if (!$rows) {
            return [];
        }
        // Those the list of the year already found are counted with it: by its child and, when the list only knows the family
        // (it was enrolled by hand before, or two files were put together after), one child for each of its students.
        if ($matched === null) {
            $homes = [];
            $students = $DB->get_recordset_select(
                self::STUDENTS,
                "centreid = :centreid AND schoolyear = :year AND movement = 'join'",
                ['centreid' => $centreid, 'year' => $schoolyear],
                '',
                'id, student, household'
            );
            foreach ($students as $row) {
                $homes[$row->student] = ($homes[$row->student] ?? []) + ($row->household ? [$row->household => true] : []);
            }
            $students->close();
            $matched = self::eguahe($centreid, $schoolyear, $homes);
        }
        $listed = array_flip($DB->get_fieldset_select(
            listmatch::T,
            'childid',
            "centreid = ? AND schoolyear = ? AND kind = 'enrolled' AND childid > 0",
            [$centreid, $schoolyear]
        ));
        $taken = array_count_values($matched);
        foreach ($rows as $row) {
            if (isset($listed[(int) $row->id]) && !empty($taken[(int) $row->householdid])) {
                $taken[(int) $row->householdid]--;
            }
        }
        $inside = ties::inside($centreid);
        // What brought each family is its first contact, as Numbers counts it by channel: an ad, the centre's own work, or
        // nothing measured. An open day it came to is a door, never what brought it.
        $centre = centres::get($centreid);
        $who = who::all_of_centre($centreid, $schoolyear);
        $out = [];
        foreach ($rows as $row) {
            $hid = (int) $row->householdid;
            if (isset($listed[(int) $row->id])) {
                continue;
            }
            if (!empty($taken[$hid])) {
                $taken[$hid]--;
                continue;
            }
            $its = $who[$hid][0] ?? 'new';
            $out['eg' . $row->id] = [
                'home' => 'eg:h' . $hid,
                'family' => $hid,
                // A child already there goes on; of its family, children inside before someone who works there.
                'kind' => isset($inside[(int) $row->id]) || $its === 'nursery' ? 'inner' : (['centre' => 'sibling', 'staff' => 'staff'][$its] ??
                    'newfamily'),
                'by' => arrival::brought($row->firstchannel === 'meta' ? 'campaign' : arrival::channel($row, $centre ?: null)),
                'grade' => format_string((string) $row->grade),
            ];
        }
        return $out;
    }

    /**
     * What the ads brought in a school year: for each imported campaign whose contacts came from ads and each campaign of ads
     * of Eguahe (in a time, when given), its families, how many were already of the centre, how many of those enrolled a child
     * this year, how many new families enrolled one, the new students of new families that came out of it, what it cost and
     * what each of those students cost. A family that left its contact in several counts once, in the first; the others say
     * how many they had again. It is the most that can be put down to the ad: leaving a contact in it does not prove the ad
     * brought them.
     *
     * @param int $centreid
     * @param array $origins As origins() gives them for the school year.
     * @param int $from Only campaigns with a contact from this time; 0, any.
     * @param int $to And before this one; 0, any.
     * @param string $year Only the families whose campaign fills this school year; empty, any.
     * @return array[] id (0, one of Eguahe), label, families, again, centre, centrein, newin, students, spend (or null), each (or
     *     null), first and last (when the contacts came), the most students first.
     */
    public static function publicity(int $centreid, array $origins, int $from = 0, int $to = 0, string $year = ''): array {
        global $DB;
        $byhome = [];
        $anynew = [];
        $add = function (string $home, string $student) use (&$byhome, &$anynew, $origins): void {
            $anynew[$home] = true;
            if (($origins['kinds'][$student] ?? '') === 'newfamily') {
                $byhome[$home][$student] = true;
            }
        };
        foreach ($origins['homes'] as $student => $homes) {
            foreach ($homes as $home) {
                $add((string) $home, (string) $student);
            }
        }
        foreach ($origins['families'] ?? [] as $student => $hid) {
            $add('eg:h' . $hid, (string) $student);
        }
        $cards = [];
        foreach ($DB->get_records(imports::T, ['centreid' => $centreid, 'kind' => 'contacts'], 'id ASC') as $import) {
            $homes = [];
            $fromads = false;
            $rows = $DB->get_recordset_select(
                self::CONTACTS,
                'importid = :id',
                ['id' => $import->id],
                '',
                'id, household, channel, timecontact'
            );
            foreach ($rows as $row) {
                $time = (int) $row->timecontact;
                $homes[$row->household] = [min($homes[$row->household][0] ?? $time, $time), max($homes[$row->household][1] ?? $time, $time)];
                $fromads = $fromads || $row->channel === 'meta';
            }
            $rows->close();
            // Publicity: contacts from ads, or a campaign with money spent on it (ads that took families to the website).
            $spend = self::spend($centreid, (string) $import->label);
            $channels = array_filter($spend, fn($k) => substr($k, -1) === '|', ARRAY_FILTER_USE_KEY);
            if ($homes && ($fromads || $channels)) {
                $cards[] = ['id' => (int) $import->id, 'label' => (string) $import->label, 'homes' => $homes, 'kin' => [],
                    'spend' => $channels ? (float) array_sum($channels) : null];
            }
        }
        foreach (self::live_ads($centreid, $origins) as $card) {
            $cards[] = $card;
        }
        $centre = centres::get($centreid);
        foreach ($year !== '' && $centre ? $cards : [] as $i => $card) {
            $cards[$i]['homes'] = array_filter($card['homes'], fn($times) => facts::year_name($centre, (int) $times[0]) === $year);
        }
        $cards = array_values(array_filter($cards, fn($card) => (bool) $card['homes']));
        // Each family in the first campaign it left its contact in, by any of the keys it is known with.
        usort($cards, fn($a, $b) => min(array_column($a['homes'], 0)) <=> min(array_column($b['homes'], 0)));
        $seen = [];
        $out = [];
        foreach ($cards as $card) {
            $times = array_merge(array_column($card['homes'], 0), array_column($card['homes'], 1));
            if (($from && max($times) < $from) || ($to && min($times) >= $to)) {
                continue;
            }
            $row = ['id' => $card['id'], 'label' => $card['label'], 'families' => 0, 'again' => 0, 'centre' => 0, 'centrein' => 0,
                'newin' => 0, 'students' => 0, 'spend' => $card['spend'], 'each' => null, 'first' => min($times), 'last' => max($times)];
            $students = [];
            foreach (array_keys($card['homes']) as $home) {
                $keys = array_merge([(string) $home], $card['kin'][$home] ?? []);
                if (array_intersect_key(array_flip($keys), $seen)) {
                    $row['again']++;
                    continue;
                }
                $row['families']++;
                $enrolled = (bool) array_intersect_key(array_flip($keys), $anynew);
                $its = array_reduce($keys, fn($all, $key) => $all + ($byhome[$key] ?? []), []);
                if (array_intersect_key(array_flip($keys), $origins['centre']) || !empty($card['inside'][$home])) {
                    $row['centre']++;
                    $row['centrein'] += $enrolled ? 1 : 0;
                } else if ($its) {
                    $row['newin']++;
                    $students += $its;
                }
            }
            foreach (array_keys($card['homes']) as $home) {
                $seen += array_fill_keys(array_merge([(string) $home], $card['kin'][$home] ?? []), true);
            }
            $row['students'] = count($students);
            $row['each'] = $row['spend'] !== null && $students ? round($row['spend'] / count($students), 2) : null;
            $out[] = $row;
        }
        usort($out, fn($a, $b) => [$b['students'], $b['families'], $a['label']] <=> [$a['students'], $a['families'], $b['label']]);
        return $out;
    }

    /**
     * The campaigns of ads of Eguahe itself: the families that came by one (an ad counts only when the family was new), by the
     * name the link brought, each with the keys the same family has in the imported lists.
     *
     * @param int $centreid
     * @param array $origins
     * @return array[] id (0), label, homes, kin, inside and spend, as publicity() takes them.
     */
    protected static function live_ads(int $centreid, array $origins): array {
        $centre = centres::get($centreid);
        $out = [];
        if (!$centre) {
            return $out;
        }
        $ads = array_filter(
            facts::read($centre, ['key' => 'all', 'year' => '', 'since' => 0, 'until' => 0, 'import' => null]),
            fn($f) => $f['channel'] === 'campaign' && $f['voices']['basis'] === 'measured'
        );
        if (!$ads) {
            return $out;
        }
        $tokens = facts::tokens($centreid, array_keys($ads));
        $spend = [];
        foreach (self::spend($centreid, panel_metrics::LIVE) as $key => $amount) {
            [$channel, $source] = array_pad(explode('|', (string) $key, 2), 2, '');
            if ($channel === 'row' && $source !== '') {
                $spend[$source] = $amount;
            }
        }
        foreach ($ads as $id => $f) {
            $name = $f['voices']['campaign'];
            $key = $name !== '' ? $name : '-';
            $out[$key] = $out[$key] ?? ['id' => 0, 'label' => $name !== '' ? $name :
                get_string('panel_new_ads_noname', 'local_oksigeniaeguahe'), 'homes' => [], 'kin' => [], 'inside' => [],
                'spend' => $name !== '' && isset($spend[$name]) ? (float) $spend[$name] : null];
            $out[$key]['homes']['eg:h' . $id] = [$f['first'], $f['first']];
            $out[$key]['kin']['eg:h' . $id] = array_map('strval', $tokens[$id] ?? []);
            $out[$key]['inside']['eg:h' . $id] = in_array($f['who'], who::CENTRE, true);
        }
        return array_values($out);
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
     * Of some families, those that signed up to an act or a visit, and whether they came.
     *
     * @param int[] $ids
     * @return string[] household => came or signed
     */
    protected static function with_acts(array $ids): array {
        global $DB;
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_records_sql('SELECT householdid, MAX(attended) AS attended FROM {' . events::SIGNUPS . "}
                                           WHERE householdid $in GROUP BY householdid", $params);
            foreach ($rows as $row) {
                $out[(int) $row->householdid] = (int) $row->attended ? 'came' : 'signed';
            }
        }
        return $out;
    }

    /** @var string[] When a family wrote first, in the time of the site: the part of the week that may wait longer. */
    const BANDS = ['weekmorning', 'weekafternoon', 'night', 'weekend'];

    /**
     * How long families wait for a first answer from a person, those that still wait for it counted too: what nobody has
     * answered is not left out, and a median is only there when more than half of them have an answer. Also by where they
     * wrote first and by when (BANDS), to see where the waits are. Hours are measured only between two moments that are known:
     * what only has its day, and what a sheet of the team says it did (which comes in with the hour it was loaded), is an answer
     * whose time is not known, and it is told of how many it is. An invitation to many is not an answer; a call nobody took is
     * not either: it is an attempt, counted apart. Families already of the centre and those that only came to a talk are not asked here, unless they are named.
     *
     * @param int $centreid
     * @param int[]|null $ids Only these families; null, those of admissions.
     * @param int $now
     * @return array who (answered, waiting, tried, unreached, met and quiet: household id => true), asked (every family that wrote),
     *     unreached (only called, with nobody answering), met (given a visit or in touch some way, with no answer written down),
     *     quiet (nothing is known of them), answered, timed (of those, with both hours
     *     known), waiting (without any answer), tried (of those,
     *     called and nobody took it), longest (hours of the one that waits most, or null), open
     *     (waiting, by how long: day, two and more), median (hours, or null), and bychannel and byband: name => [median, count
     *     (answered with their time known), asked, answered]
     */
    public static function response(int $centreid, ?array $ids = null, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $only = $ids === null ? null : array_flip($ids);
        // The first message and the first answer of each family, and the channel of its first message.
        $rows = [];
        $homes = [];
        $tried = [];
        [$missed, $sheet] = self::sheet_words();
        $lines = $DB->get_recordset_sql("SELECT c.id, c.householdid, c.direction, c.channel, c.dayonly, c.timecreated, c.body, c.aim,
                                                h.waitingsince, h.waitingdayonly
                                           FROM {local_oksigeniaeguahe_contact} c
                                           JOIN {local_oksigeniaeguahe_household} h ON h.id = c.householdid
                                          WHERE c.centreid = :centreid AND h.guest = 0 AND c.direction <> 'note'
                                                AND (c.aim IS NULL OR c.aim = 'tried')" . ($only === null ? ' AND h.incentre = 0' : '') . "
                                       ORDER BY c.householdid, c.timecreated, c.id", ['centreid' => $centreid]);
        foreach ($lines as $line) {
            $id = (int) $line->householdid;
            if ($only !== null && !isset($only[$id])) {
                continue;
            }
            $homes[$id] = [(int) $line->waitingsince, (int) $line->waitingdayonly];
            $rows[$id] = $rows[$id] ?? ['in' => null, 'out' => null];
            if ($line->direction === 'in') {
                $rows[$id]['in'] = $rows[$id]['in'] ?? $line;
                continue;
            }
            $did = self::did((string) $line->body);
            if ($line->aim === 'tried' || ($did && !array_diff_key($did, $missed))) {
                $tried[$id] = true;
            } else if ($rows[$id]['in'] && !$rows[$id]['out']) {
                $line->sheet = $did && !array_diff_key($did, $sheet);
                $rows[$id]['out'] = $line;
            }
        }
        $lines->close();
        $out = ['asked' => 0, 'answered' => 0, 'timed' => 0, 'waiting' => 0, 'tried' => 0, 'unreached' => 0, 'met' => 0, 'quiet' => 0,
            'longest' => null, 'open' => ['day' => 0, 'two' => 0, 'more' => 0]];
        // Those that wrote, have no answer and are not waiting: what there is of them.
        $loose = array_keys(array_filter($rows, fn($row, $id) => $row['in'] && !$row['out'] && !$homes[$id][0], ARRAY_FILTER_USE_BOTH));
        $touched = teamnotes::touched($loose);
        $acts = self::with_acts($loose);
        $kinds = ['unreached' => [], 'met' => [], 'quiet' => []];
        $out['within'] = ['day' => 0, 'two' => 0];
        $all = [];
        $who = [];
        $bychannel = [];
        $byband = [];
        $zone = \core_date::get_server_timezone();
        foreach ($rows as $id => $row) {
            [$in, $answer] = [$row['in'], $row['out']];
            if (!$in) {
                // It wrote nothing.
                continue;
            }
            $kind = '';
            if (!$answer && !$homes[$id][0]) {
                $kind = isset($touched[$id]) || ($acts[$id] ?? '') === 'came' ? 'met' : (isset($tried[$id]) ? 'unreached' : 'quiet');
                if ($kind === 'quiet' && (isset($acts[$id]) || $in->channel === 'event')) {
                    // Nothing that waits for an answer (it signed up to an open day).
                    continue;
                }
            }
            $out['asked']++;
            if ($kind !== '') {
                $out[$kind]++;
                $kinds[$kind][$id] = true;
                // Answered at a time nobody knows, or not answered.
                $all[] = $kind === 'met' ? false : null;
                $bychannel[(string) $in->channel][] = end($all);
                $byband[self::band((int) $in->timecreated, $zone)][] = end($all);
                continue;
            }
            $who[$id] = (bool) $answer;
            // Hours to the answer; null, not yet; false, answered without its hour.
            $wait = null;
            if ($answer) {
                $out['answered']++;
                $wait = $in->dayonly || $answer->dayonly || $answer->sheet ? false :
                    (float) (($answer->timecreated - $in->timecreated) / HOURSECS);
                $out['timed'] += $wait === false ? 0 : 1;
                $out['within']['day'] += $wait !== false && $wait <= 24 ? 1 : 0;
                $out['within']['two'] += $wait !== false && $wait <= 48 ? 1 : 0;
            } else {
                $since = $homes[$id][1] ? max(0, panel::days_between($homes[$id][0], $now)) * 24.0 : (float) (max(0, $now - $homes[$id][0]) / HOURSECS);
                $out['waiting']++;
                $out['tried'] += isset($tried[$id]) ? 1 : 0;
                $out['longest'] = max((float) $out['longest'], $since);
                $out['open'][$since < 24 ? 'day' : ($since < 48 ? 'two' : 'more')]++;
            }
            $all[] = $wait;
            $bychannel[(string) $in->channel][] = $wait;
            $byband[self::band((int) $in->timecreated, $zone)][] = $wait;
        }
        $split = fn(array $groups) => array_map(fn($list) => ['median' => self::median($list),
            'count' => count(array_filter($list, 'is_float')), 'asked' => count($list),
            'answered' => count(array_filter($list, fn($w) => $w !== null))], $groups);
        $byband = array_merge(array_intersect_key(array_flip(self::BANDS), $byband), $byband);
        return $out + ['who' => ['answered' => array_filter($who, fn($w) => $w), 'waiting' => array_filter($who, fn($w) => !$w),
            'tried' => $tried] + $kinds, 'median' => self::median($all), 'bychannel' => $split($bychannel), 'byband' => $split($byband)];
    }

    /**
     * The middle of some waits, in hours: those without an answer yet are longer than any other, so with half of them or more
     * still waiting there is none; those answered without their hour say nothing.
     *
     * @param array $waits Hours, null (not yet) or false (not known).
     * @return float|null
     */
    public static function median(array $waits): ?float {
        $known = array_values(array_filter($waits, fn($w) => $w !== false));
        $answered = array_map('floatval', array_values(array_filter($known, fn($w) => $w !== null)));
        sort($answered);
        $n = count($known);
        if (!$answered || 2 * count($answered) <= $n && count($answered) < $n) {
            return null;
        }
        return round($answered[(int) floor(($n - 1) / 2)], 1);
    }

    /**
     * What the lines a sheet of the team leaves say it did, in every language: a call nobody took, and everything else (an
     * answer whose hour the sheet does not write).
     *
     * @return array[] missed and all, each text => true
     */
    protected static function sheet_words(): array {
        static $words = null;
        if ($words !== null) {
            return $words;
        }
        $missed = [];
        $all = [];
        foreach (['es', 'en', 'pt', 'pt_br'] as $lang) {
            foreach (
                ['noanswer', 'wrongnumber', 'invited', 'infosent', 'familycalls', 'callback', 'contacted_email', 'contacted_other',
                    'contacted_phone', 'contacted_whatsapp'] as $key
            ) {
                $said = get_string_manager()->get_string('teamnote_' . $key, 'local_oksigeniaeguahe', null, $lang);
                $all[$said] = true;
                if (in_array($key, ['noanswer', 'wrongnumber'], true)) {
                    $missed[$said] = true;
                }
            }
        }
        return $words = [$missed, $all];
    }

    /**
     * Whether a line of the team is a call nobody took: an attempt, not an answer.
     *
     * @param \stdClass $line aim and body.
     * @return bool
     */
    public static function is_try(\stdClass $line): bool {
        $did = self::did((string) $line->body);
        return $line->aim === 'tried' || ($did && !array_diff_key($did, self::sheet_words()[0]));
    }

    /**
     * What a line of the team says it did, each thing apart, without what the sheet wrote after.
     *
     * @param string $body
     * @return array text => true
     */
    protected static function did(string $body): array {
        $said = array_filter(array_map(fn($one) => trim($one, " .\t\n"), explode('. ', explode(' · ', $body, 2)[0])), fn($one) => $one !== '');
        return array_fill_keys($said, true);
    }

    /**
     * The part of the week a moment falls in: mornings or afternoons of working days, nights, or the weekend.
     *
     * @param int $time
     * @param string $zone
     * @return string One of BANDS.
     */
    public static function band(int $time, string $zone): string {
        $date = usergetdate($time, $zone);
        if ((int) $date['wday'] === 0 || (int) $date['wday'] === 6) {
            return 'weekend';
        }
        $hour = (int) $date['hours'];
        return $hour >= 8 && $hour < 15 ? 'weekmorning' : ($hour >= 15 && $hour < 21 ? 'weekafternoon' : 'night');
    }
}
