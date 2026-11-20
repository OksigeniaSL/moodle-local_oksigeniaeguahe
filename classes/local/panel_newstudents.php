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
 * «Quién entra y quién sale», the sheet of Cifras that tells what really happened with the new students of a school year
 * (Jujol's design, 21 November 2026; docs/18-analisis.md of hq): what comes out of the numbers, what it seems and what
 * really happens, where the new students come from, what each campaign of ads and each act brought with its money and
 * the hours of the team, and the joins and leaves during the year. Every number is a button with its definition; both
 * versions of what changes with or without those already of the centre are in the page.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_newstudents {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int Families or students a conclusion needs behind it: with fewer, a percentage misleads. */
    const MIN = 10;

    /**
     * A string with a number in it, in the singular when the number is one (its key ending in _one, if there is one).
     *
     * @param string $key
     * @param int $n
     * @param mixed $a
     * @return string
     */
    protected static function one(string $key, int $n, $a): string {
        $one = $n === 1 && get_string_manager()->string_exists($key . '_one', self::C);
        return get_string($one ? $key . '_one' : $key, self::C, $a);
    }

    /** @var string The hours of the team written by the centre, by campaign or act and task. */
    const HOURS = 'local_oksigeniaeguahe_hours';

    /** @var string[] The usual tasks offered to add a line of hours. */
    const TASKS = ['meetings', 'social', 'saturdays', 'phone', 'whatsapp', 'desk', 'agency'];

    /** @var int The months of a school year drawn, from its first one. */
    const MONTHS = 10;

    /** @var array Definitions gathered while building, read by the box of each number. */
    protected static $explain = [];

    /**
     * The sheet for a school year.
     *
     * @param \stdClass $centre
     * @param string $schoolyear '' for the last one with new students.
     * @param bool $withcentre Whether those already of the centre count (the switch).
     * @param callable $url Builds a link to Cifras from its view.
     * @param bool $canact Whether the person may write hours.
     * @param int $now
     * @return array|null [newstudents, head title, years (filter), count (filter)], or null without new students.
     */
    public static function context(
        \stdClass $centre,
        string $schoolyear,
        bool $withcentre,
        callable $url,
        bool $canact,
        int $now = 0
    ): ?array {
        $c = self::C;
        $now = $now ?: time();
        $centreid = (int) $centre->id;
        self::$explain = [];
        $years = [];
        foreach (metrics::school_years($centreid) as $year) {
            if (metrics::origins($centreid, (string) $year)['total'] > 0) {
                $years[] = (string) $year;
            }
        }
        if (!$years) {
            return null;
        }
        $year = in_array($schoolyear, $years, true) ? $schoolyear : $years[0];
        $o = metrics::origins($centreid, $year);
        $g = array_map(fn($x) => (int) $x['n'], $o['groups']);
        $total = (int) $o['total'];
        $centren = $g['inner'] + $g['sibling'];
        $moves = self::moves($centre, $year, $now);
        $campaigns = self::campaigns($centre, $year, $o, $canact);
        $ads = array_values(array_filter($campaigns, fn($k) => !$k['act']));
        $contacts = array_sum(array_map(fn($k) => (int) $k['contactsn'], $ads));
        $contactscentre = array_sum(array_map(fn($k) => (int) $k['centre'], $ads));
        $adstudents = (int) $o['newby']['ad'];
        $origins = self::origins($o, $year, $url);
        $truth = $contacts ? [
            'seems' => self::glue(get_string('panel_new_seems_text', $c, (object) [
                'contacts' => self::button('ex-contacts-all', self::num($contacts)),
                'total' => self::button('ex-total', (string) $total)])),
            'is' => self::glue(get_string('panel_new_is_text', $c, (object) ['centre' => self::button('ex-centre', (string) $centren),
                'inner' => self::button('ex-inner', (string) $g['inner']),
                'sibling' => self::button('ex-sibling', (string) $g['sibling']),
                'fresh' => self::button('ex-newfamily', (string) $g['newfamily']),
                'ad' => self::button('ex-ad', (string) $adstudents), 'total' => $total])),
        ] : false;
        self::define(
            'ex-total',
            self::one('panel_ex_total_t', $total, $total),
            get_string('panel_ex_total_w', $c, $year),
            get_string('panel_ex_total_h', $c),
            get_string('panel_ex_total_c', $c)
        );
        self::define(
            'ex-centre',
            self::one('panel_ex_centre_t', $centren, $centren),
            get_string(
                'panel_ex_centre_w',
                $c,
                (object) ['inner' => $g['inner'], 'sibling' => $g['sibling']]
            ),
            get_string('panel_ex_centre_h', $c),
            get_string('panel_ex_centre_c', $c)
        );
        if ($contacts) {
            self::define(
                'ex-contacts-all',
                self::one('panel_ex_contacts_all_t', $contacts, self::num($contacts)),
                get_string('panel_ex_contacts_all_w', $c, (object) ['year' => $year, 'n' => count($ads)]),
                get_string('panel_ex_contacts_all_h', $c),
                self::one('panel_ex_contacts_all_c', $contactscentre, $contactscentre)
            );
        }
        $newstudents = [
            'withcentre' => $withcentre,
            'conclusions' => self::conclusions($centre, $o, $campaigns, $moves),
            'truth' => $truth,
            'origins' => $origins,
            'ads' => $campaigns ? [
                'heroall' => get_string('panel_new_x_of_y', $c, (object) ['x' => $adstudents, 'y' => $total]),
                'heronew' => get_string('panel_new_x_of_y', $c, (object) ['x' => $adstudents, 'y' => $g['newfamily']]),
                'heroalltext' => get_string('panel_new_hero_ads_all', $c),
                'heronewtext' => get_string('panel_new_hero_ads_new', $c),
                'campaigns' => $campaigns,
            ] : false,
            'moves' => $moves ?: false,
        ];
        $year0 = panel_metrics::year($centre, $year);
        $history = panel_metrics::history($centre);
        if ($year0) {
            $year0['ancha'] = true;
        }
        if ($history) {
            $history['ancha'] = true;
        }
        $newstudents += ['hasyear' => (bool) $year0, 'year' => $year0 ?: false, 'hashistory' => (bool) $history,
            'history' => $history ?: false, 'explain' => array_values(self::$explain)];
        $joins = $moves ? (int) $moves['joins'] : 0;
        $leaves = $moves ? (int) $moves['leaves'] : 0;
        return [
            'newstudents' => $newstudents,
            'title' => get_string('panel_new_title', $c, (object) ['n' => self::one('panel_new_title_students', $total, $total),
                'joins' => self::one('panel_new_title_joins', $joins, $joins),
                'leaves' => self::one('panel_new_title_leaves', $leaves, $leaves), 'year' => $year]),
            'years' => array_map(
                fn($y) => ['name' => $y, 'url' => $url(['year' => $y])->out(false), 'current' => $y === $year],
                $years
            ),
            'count' => [
                ['key' => 'con', 'name' => get_string('panel_new_with_centre', $c), 'url' => $url(['withcentre' => 1])->out(false),
                    'current' => $withcentre],
                ['key' => 'sin', 'name' => get_string('panel_new_without_centre', $c), 'url' => $url(['withcentre' => 0])->out(false),
                    'current' => !$withcentre],
            ],
            'year' => $year,
        ];
    }

    /**
     * Where the new students come from: one backpack per student, by shelves.
     *
     * @param array $o As metrics::origins() gives it.
     * @param string $year
     * @param callable $url
     * @return array
     */
    protected static function origins(array $o, string $year, callable $url): array {
        $c = self::C;
        $g = array_map(fn($x) => (int) $x['n'], $o['groups']);
        $total = (int) $o['total'];
        $fresh = $g['newfamily'] + $g['unknown'];
        $pct = fn(int $n, int $of) => $of ? round(100 * $n / $of) . ' %' : '—';
        $both = fn(string $con, string $sin) => '<span class="con">' . $con . '</span><span class="sin">' . $sin . '</span>';
        $courses = function (array $grades) use ($c): string {
            arsort($grades);
            $out = [];
            foreach (array_slice($grades, 0, 3, true) as $name => $n) {
                $out[] = ($name !== '' ? $name : get_string('metrics_nograde', $c)) . ' ' . $n;
            }
            return implode(' · ', $out);
        };
        $bags = fn(string $k, int $n) => array_fill(0, max(0, $n), ['k' => $k]);
        $rows = [];
        foreach (['inner', 'sibling'] as $key) {
            if ($key === 'sibling' && !$o['hasroll']) {
                continue;
            }
            // An empty shelf is not drawn (its number, in the sentences, still says what it is).
            if ($g[$key]) {
                $rows[] = ['key' => $key, 'name' => get_string('panel_new_group_' . $key, $c),
                'sub' => get_string('panel_new_group_' . $key . '_sub', $c), 'n' => $g[$key], 'pct' => $pct($g[$key], $total),
                'pctnew' => '', 'centre' => true, 'explain' => 'ex-' . $key, 'courses' => $courses($o['groups'][$key]['grades']),
                'bags' => $bags($key, $g[$key]), 'hasparts' => false, 'parts' => []];
            }
            self::define(
                'ex-' . $key,
                self::one('panel_ex_' . $key . '_t', (int) $g[$key], (object) ['n' => $g[$key],
                'pct' => $both('(' . $pct($g[$key], $total) . ')', '')]),
                get_string('panel_ex_' . $key . '_w', $c),
                get_string('panel_ex_' . $key . '_h', $c),
                get_string('panel_ex_' . $key . '_c', $c)
            );
        }
        $parts = [];
        $partbags = [];
        foreach (['ad', 'campaign', 'none'] as $by) {
            $n = (int) $o['newby'][$by];
            if (!$n) {
                continue;
            }
            $parts[] = ['key' => $by, 'n' => $n, 'name' => get_string('panel_new_by_' . $by, $c), 'explain' => 'ex-' . $by];
            $partbags = array_merge($partbags, $bags($by, $n));
            self::define(
                'ex-' . $by,
                self::one('panel_ex_' . $by . '_t', $n, (object) ['n' => $n,
                'pct' => $both('(' . get_string('panel_new_of_all', $c, $pct($n, $total)) . ')', '(' . $pct($n, $fresh) . ')')]),
                get_string('panel_ex_' . $by . '_w', $c),
                get_string('panel_ex_' . $by . '_h', $c),
                get_string('panel_ex_' . $by . '_c', $c)
            );
        }
        $rows[] = ['key' => 'newfamily', 'name' => get_string('panel_new_group_newfamily', $c),
            'sub' => get_string('panel_new_group_newfamily_sub', $c), 'n' => $g['newfamily'], 'pct' => $pct($g['newfamily'], $total),
            'pctnew' => $pct($g['newfamily'], $fresh), 'centre' => false, 'explain' => 'ex-newfamily',
            'courses' => $courses($o['groups']['newfamily']['grades']), 'bags' => $partbags, 'hasparts' => (bool) $parts,
            'parts' => $parts];
        self::define(
            'ex-newfamily',
            self::one('panel_ex_newfamily_t', (int) $g['newfamily'], (object) ['n' => $g['newfamily'],
            'pct' => $both('(' . $pct($g['newfamily'], $total) . ')', '(' . $pct($g['newfamily'], $fresh) . ')')]),
            get_string('panel_ex_newfamily_w', $c),
            get_string('panel_ex_newfamily_h', $c),
            get_string('panel_ex_newfamily_c', $c)
        );
        if ($g['unknown']) {
            $rows[] = ['key' => 'unknown', 'name' => get_string('panel_new_group_unknown', $c), 'sub' => '', 'n' => $g['unknown'],
                'pct' => $pct($g['unknown'], $total), 'pctnew' => $pct($g['unknown'], $fresh), 'centre' => false,
                'explain' => 'ex-unknown', 'courses' => $courses($o['groups']['unknown']['grades']),
                'bags' => $bags('unknown', $g['unknown']), 'hasparts' => false, 'parts' => []];
            self::define(
                'ex-unknown',
                get_string('panel_ex_unknown_t', $c, $g['unknown']),
                get_string('panel_ex_unknown_w', $c),
                get_string('panel_ex_unknown_h', $c),
                get_string('panel_ex_unknown_c', $c)
            );
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = ['name' => $row['name'], 'n' => $row['n'], 'pct' => $both($row['pct'], $row['pctnew'] ?: '—'),
                'part' => false];
            foreach ($row['parts'] as $part) {
                $table[] = ['name' => $part['name'], 'n' => $part['n'], 'pct' => $both(
                    $pct($part['n'], $total),
                    $pct($part['n'], $fresh)
                ), 'part' => true];
            }
        }
        return [
            'cut' => get_string('panel_origins_cut', $c, (object) ['year' => $year, 'n' => $total]),
            'heroall' => get_string('panel_new_x_of_y', $c, (object) ['x' => $g['newfamily'], 'y' => $total]),
            'heronew' => (string) $g['newfamily'],
            'heroalltext' => get_string('panel_new_hero_origins_all', $c),
            'heronewtext' => get_string('panel_new_hero_origins_new', $c, $total),
            'rows' => $rows,
            'table' => $table,
            'fold' => get_string('panel_new_fold', $c, (object) ['n' => $g['inner'] + $g['sibling'], 'inner' => $g['inner'],
                'sibling' => $g['sibling']]),
            'unfoldurl' => $url(['withcentre' => 1])->out(false),
            'nosiblings' => !$o['hasroll'],
        ];
    }

    /**
     * What each campaign of ads and each act brought for a school year: the way from the families to the enrolment,
     * a hundred dots, the money and the hours of the team. The campaigns are the imported ones whose contacts came from
     * ads in the year before it starts; the acts, those of Eguahe in that time with families that came.
     *
     * @param \stdClass $centre
     * @param string $year
     * @param array $o As metrics::origins() gives it.
     * @param bool $canact
     * @return array[]
     */
    protected static function campaigns(\stdClass $centre, string $year, array $o, bool $canact): array {
        global $DB;
        $c = self::C;
        $centreid = (int) $centre->id;
        [$from, $to] = self::window($year);
        $value = (float) ($centre->hourvalue ?? 0);
        $written = self::written($centreid);
        $out = [];
        foreach (metrics::publicity($centreid, $o, $from, $to) as $p) {
            $out[] = self::card(
                $centre,
                'import',
                (int) $p['id'],
                $p['label'],
                self::when((int) $p['first'], (int) $p['last']),
                false,
                (int) $p['families'],
                (int) $p['centre'],
                (int) $p['centrein'],
                (int) $p['newin'],
                (int) $p['students'],
                $p['spend'],
                [],
                $written['import'][(int) $p['id']] ?? [],
                $value,
                $canact
            );
        }
        foreach (self::acts($centreid, $from, $to) as $act) {
            $auto = [];
            if ($act['hosthours'] > 0) {
                $auto[] = ['h' => $act['hosthours'], 'name' => self::one('panel_new_auto_act', (int) $act['hosts'], (object) [
                    'hours' => self::hours($act['length']), 'people' => $act['hosts']])];
            }
            $out[] = self::card(
                $centre,
                'event',
                (int) $act['id'],
                $act['name'],
                userdate(
                    $act['time'],
                    get_string('strftimedate', 'langconfig')
                ),
                true,
                $act['came'],
                $act['centre'],
                $act['centrein'],
                $act['newin'],
                $act['students'],
                null,
                $auto,
                $written['event'][(int) $act['id']] ?? [],
                $value,
                $canact
            );
        }
        return $out;
    }

    /**
     * One card: a campaign of ads or an act.
     *
     * @param \stdClass $centre
     * @param string $scope import or event.
     * @param int $id
     * @param string $label
     * @param string $when
     * @param bool $act
     * @param int $contacts Families that left their contact, or that came.
     * @param int $centrefams Of them, already of the centre.
     * @param int $centrein Of these, those that enrolled a child this year.
     * @param int $newin New families of them that enrolled a child.
     * @param int $students New students of those new families.
     * @param float|null $spend
     * @param array $auto Hours Eguahe counts by itself: h, name.
     * @param array $lines Hours the centre wrote: task => hours.
     * @param float $value What an hour is worth, 0 when not given.
     * @param bool $canact
     * @return array
     */
    protected static function card(
        \stdClass $centre,
        string $scope,
        int $id,
        string $label,
        string $when,
        bool $act,
        int $contacts,
        int $centrefams,
        int $centrein,
        int $newin,
        int $students,
        ?float $spend,
        array $auto,
        array $lines,
        float $value,
        bool $canact
    ): array {
        $c = self::C;
        $key = $scope . $id;
        $id2 = fn(string $w) => 'ex-' . $key . '-' . $w;
        $newcontacts = max(0, $contacts - $centrefams);
        $allin = $newin + $centrein;
        $both = fn(string $con, string $sin) => '<span class="con">' . $con . '</span><span class="sin">' . $sin . '</span>';
        $all = self::dots($newin, $centrein, $contacts);
        $new = self::dots($newin, 0, $newcontacts);
        $less = get_string('panel_new_less_than_one', $c);
        $perall = $all['per'] === null ? $less : (string) $all['per'];
        $pernew = $new['per'] === null ? $less : (string) $new['per'];
        $autoh = array_sum(array_column($auto, 'h'));
        $writh = array_sum($lines);
        $allh = $autoh + $writh;
        $money = $spend !== null ? centres::money($centre, (float) $spend) : '—';
        $each = $students ? get_string('panel_js_hours_each', $c, (object) [
            'money' => $spend !== null ? centres::money($centre, (float) $spend / $students) : '—',
            'hours' => self::hours($allh / $students)]) : '—';
        $writtenrows = [];
        foreach ($lines as $task => $h) {
            $writtenrows[] = ['task' => $task, 'name' => self::task_name($task), 'h' => $h, 'hl' => self::hours($h)];
        }
        $tasks = [];
        foreach (self::TASKS as $task) {
            if (!array_key_exists($task, $lines)) {
                $tasks[] = ['task' => $task, 'name' => self::task_name($task)];
            }
        }
        $what = $act ? get_string('panel_ex_act_contacts_w', $c, format_string($label)) :
            get_string('panel_ex_ad_contacts_w', $c, format_string($label));
        self::define(
            $id2('contacts'),
            self::one($act ? 'panel_ex_act_contacts_t' : 'panel_ex_ad_contacts_t', (int) $contacts, self::num($contacts)),
            $what,
            get_string($act ? 'panel_ex_act_contacts_h' : 'panel_ex_ad_contacts_h', $c),
            get_string($act ? 'panel_ex_act_contacts_c' : 'panel_ex_ad_contacts_c', $c)
        );
        self::define(
            $id2('money'),
            get_string('panel_ex_money_t', $c, $money),
            get_string($act ? 'panel_ex_money_act_w' : 'panel_ex_money_ads_w', $c),
            get_string('panel_ex_money_h', $c),
            get_string('panel_ex_money_c', $c)
        );
        self::define(
            $id2('hours'),
            get_string('panel_ex_hours_t', $c, self::hours($allh)),
            get_string('panel_ex_hours_w', $c),
            get_string('panel_ex_hours_h', $c, (object) ['auto' => self::hours($autoh), 'written' => self::hours($writh)]),
            get_string('panel_ex_hours_c', $c)
        );
        self::define(
            $id2('centre'),
            self::one('panel_ex_card_centre_t', (int) $centrefams, $centrefams),
            get_string('panel_ex_card_centre_w', $c),
            get_string('panel_ex_card_centre_h', $c),
            get_string('panel_ex_card_centre_c', $c) . ' ' .
            $both(get_string('panel_new_still_count', $c), get_string('panel_new_taken_out', $c))
        );
        self::define(
            $id2('in'),
            $both(self::one('panel_ex_in_all_t', (int) $allin, $allin), self::one('panel_ex_in_new_t', (int) $newin, $newin)),
            $both(get_string('panel_ex_in_all_w', $c, $centrein), get_string('panel_ex_in_new_w', $c)),
            get_string('panel_ex_in_h', $c),
            get_string('panel_ex_in_c', $c)
        );
        self::define(
            $id2('per'),
            $both(get_string('panel_ex_per_t', $c, $perall), get_string('panel_ex_per_t', $c, $pernew)),
            get_string('panel_ex_per_w', $c),
            $both(
                get_string('panel_ex_per_h', $c, (object) ['k' => $allin, 'of' => self::num($contacts),
                    'p' => format_float($contacts ? 100 * $allin / $contacts : 0, 1, true, true)]),
                get_string('panel_ex_per_h', $c, (object) ['k' => $newin, 'of' => self::num($newcontacts),
                    'p' => format_float($newcontacts ? 100 * $newin / $newcontacts : 0, 1, true, true)])
            ),
            get_string('panel_ex_per_c', $c)
        );
        self::define($id2('each'), get_string('panel_ex_each_t', $c, $each), get_string($act ? 'panel_ex_each_act_w' :
            'panel_ex_each_ads_w', $c), get_string('panel_ex_each_h', $c, (object) ['money' => $money,
            'hours' => self::hours($allh), 'n' => $students]), get_string('panel_ex_each_c', $c));
        return [
            'key' => $key, 'label' => format_string($label), 'when' => $when, 'act' => $act, 'open' => false,
            'contacts' => self::num($contacts), 'contactsn' => $contacts, 'centre' => $centrefams,
            'newcontacts' => self::num($newcontacts), 'allin' => $allin, 'newin' => $newin, 'allper' => $perall,
            'newper' => $pernew, 'alldots' => $all['list'], 'newdots' => $new['list'],
            'allsentence' => get_string(
                'panel_new_hundred_all' . (!$all['per'] ? '_less' : ($all['per'] === 1 ? '_one' : '')),
                $c,
                (object) ['n' => '<b>' . $perall . '</b>', 'k' => $allin, 'of' => self::num($contacts)]
            ),
            'newsentence' => get_string(
                'panel_new_hundred_new' . (!$new['per'] ? '_less' : ($new['per'] === 1 ? '_one' : '')),
                $c,
                (object) ['n' => '<b>' . $pernew . '</b>', 'k' => $newin, 'of' => self::num($newcontacts)]
            ),
            'each' => $each, 'students' => $students,
            'steplabel' => get_string($act ? 'panel_new_came' : 'panel_new_left_contact', $c),
            'money' => $money, 'moneyn' => (float) ($spend ?? 0),
            'moneywhat' => get_string($act ? 'panel_new_money_act' : 'panel_new_money_ads', $c),
            'hours' => self::hours($allh), 'hoursn' => $allh,
            'hoursmoney' => $value > 0 ? get_string('panel_js_hours_money', $c, (object) [
                'money' => centres::money($centre, $allh * $value), 'value' => centres::money($centre, $value)]) :
                get_string('panel_js_hours_novalue', $c),
            'eachmoney' => $students && $value > 0 ? get_string(
                'panel_js_hours_with',
                $c,
                centres::money($centre, ((float) ($spend ?? 0) + $allh * $value) / $students)
            ) : '',
            'autohours' => array_map(fn($a) => ['h' => $a['h'], 'hl' => self::hours($a['h']), 'name' => $a['name']], $auto),
            'hasauto' => (bool) $auto,
            'written' => $writtenrows, 'tasks' => $canact ? $tasks : [], 'hastasks' => $canact && $tasks, 'canwrite' => $canact,
            'saveurl' => (new \moodle_url('/local/oksigeniaeguahe/metrics.php', ['c' => $centre->id]))->out(false),
            'sesskey' => sesskey(),
            'value' => $value, 'valuel' => $value > 0 ? centres::money($centre, $value) : get_string('panel_js_hours_value_none', $c),
            'scope' => $scope, 'scopeid' => $id, 'currency' => centres::currency($centre),
            'ex' => ['contacts' => $id2('contacts'), 'centre' => $id2('centre'), 'in' => $id2('in'), 'per' => $id2('per'),
                'each' => $id2('each'), 'money' => $id2('money'), 'hours' => $id2('hours')],
        ];
    }

    /**
     * The acts of Eguahe between two times with families that came: how many came, of the centre, and enrolled.
     *
     * @param int $centreid
     * @param int $from
     * @param int $to
     * @return array[] id, name, time, length (hours), hosts, hosthours, came, centre, centrein, newin, students.
     */
    public static function acts(int $centreid, int $from, int $to): array {
        global $DB;
        $events = $DB->get_records_select(
            events::T,
            "centreid = :centreid AND kind IN ('openday', 'other') AND timestart >= :from AND timestart < :to",
            ['centreid' => $centreid, 'from' => $from, 'to' => $to],
            'timestart ASC'
        );
        $out = [];
        foreach ($events as $event) {
            $came = $DB->get_fieldset_select(events::SIGNUPS, 'householdid', 'eventid = ? AND attended > 0', [$event->id]);
            if (!$came) {
                continue;
            }
            [$in, $params] = $DB->get_in_or_equal($came);
            $homes = $DB->get_records_select(households::T, "id $in", $params, '', 'id, incentre, roster, familylink');
            $kids = $DB->get_records_select(households::CHILDREN, "householdid $in", $params, '', 'id, householdid, stage, roster');
            $enrolled = [];
            foreach ($kids as $kid) {
                if ($kid->stage === 'enrolled' && empty($kid->roster)) {
                    $enrolled[(int) $kid->householdid][] = (int) $kid->id;
                }
            }
            $centre = 0;
            $centrein = 0;
            $newin = 0;
            $students = 0;
            foreach ($homes as $home) {
                $ofcentre = !empty($home->incentre) || !empty($home->roster) || (int) $home->familylink > 0;
                $centre += $ofcentre ? 1 : 0;
                if (!empty($enrolled[(int) $home->id])) {
                    if ($ofcentre) {
                        $centrein++;
                    } else {
                        $newin++;
                        $students += count($enrolled[(int) $home->id]);
                    }
                }
            }
            $length = max(0, ((int) $event->timeend - (int) $event->timestart) / HOURSECS);
            $hosts = count(array_filter(explode(',', (string) ($event->hosts ?? ''))));
            $out[] = ['id' => (int) $event->id, 'name' => (string) $event->name, 'time' => (int) $event->timestart,
                'length' => $length, 'hosts' => max(1, $hosts), 'hosthours' => round($length * max(1, $hosts), 1),
                'came' => count($homes), 'centre' => $centre, 'centrein' => $centrein, 'newin' => $newin, 'students' => $students];
        }
        return $out;
    }

    /**
     * Joins and leaves during the school year, month by month with the year before in pencil, by stage, and why and
     * where they go and come from.
     *
     * @param \stdClass $centre
     * @param string $year
     * @param int $now
     * @return array|null
     */
    protected static function moves(\stdClass $centre, string $year, int $now): ?array {
        $c = self::C;
        $centreid = (int) $centre->id;
        $m = metrics::movements($centreid, $year);
        $prev = sprintf('%d-%02d', (int) substr($year, 0, 4) - 1, ((int) substr($year, 0, 4)) % 100);
        $before = metrics::movements($centreid, $prev);
        if (!$m['totals']['joins'] && !$m['totals']['leaves'] && !$m['bymonth']) {
            return null;
        }
        $start = imports::year_start($year);
        $first = new \DateTimeImmutable('@' . $start);
        $first = $first->setTimezone(\core_date::get_server_timezone_object())->modify('first day of this month');
        $by = [];
        foreach ($m['bymonth'] as $row) {
            $by[$row['month']] = $row;
        }
        $bb = [];
        foreach ($before['bymonth'] as $row) {
            $bb[$row['month']] = $row;
        }
        $nowmonth = userdate($now, '%Y-%m');
        $rows = [];
        $most = 1;
        for ($k = 0; $k < self::MONTHS; $k++) {
            $month = $first->modify('+' . $k . ' month');
            $key = $month->format('Y-m');
            $prevkey = $month->modify('-1 year')->format('Y-m');
            $row = ['key' => $key, 'time' => $month->getTimestamp(), 'joins' => (int) ($by[$key]['joins'] ?? 0),
                'leaves' => (int) ($by[$key]['leaves'] ?? 0), 'bjoins' => (int) ($bb[$prevkey]['joins'] ?? 0),
                'bleaves' => (int) ($bb[$prevkey]['leaves'] ?? 0), 'future' => $key > $nowmonth, 'current' => $key === $nowmonth];
            $most = max($most, $row['joins'], $row['leaves'], $row['bjoins'], $row['bleaves']);
            $rows[] = $row;
        }
        $joins = 0;
        $leaves = 0;
        $bleaves = 0;
        $months = [];
        foreach ($rows as $row) {
            $short = userdate($row['time'], '%b');
            $full = userdate($row['time'], '%B');
            if (!$row['future']) {
                $joins += $row['joins'];
                $leaves += $row['leaves'];
                $bleaves += $row['bleaves'];
            }
            $id = 'ex-m-' . $row['key'];
            self::define(
                $id,
                $row['future'] ? get_string('panel_ex_month_future_t', $c, $full) :
                get_string($row['current'] ? 'panel_ex_month_now_t' : 'panel_ex_month_t', $c, (object) ['month' => $full,
                'joins' => $row['joins'], 'leaves' => $row['leaves']]),
                $row['future'] ?
                get_string('panel_ex_month_future_w', $c, (object) ['month' => $full, 'joins' => $row['bjoins'],
                'leaves' => $row['bleaves']]) : get_string('panel_ex_month_w', $c, $full),
                get_string('panel_ex_month_h', $c),
                $row['future'] ? '' : get_string('panel_ex_month_c', $c, (object) ['month' => $full, 'joins' => $row['bjoins'],
                'leaves' => $row['bleaves']])
            );
            $months[] = ['name' => $short, 'fullname' => $full, 'future' => $row['future'], 'current' => $row['current'],
                'explain' => $id, 'joinsn' => $row['future'] ? '' : $row['joins'], 'leavesn' => $row['future'] ? '' : $row['leaves'],
                'av' => $row['future'] ? 0 : round($row['joins'] / $most, 3), 'bv' => $row['future'] ? 0 : round($row['leaves'] / $most, 3),
                'gav' => round($row['bjoins'] / $most, 3), 'gbv' => round($row['bleaves'] / $most, 3),
                'before' => '+' . $row['bjoins'] . ' · −' . $row['bleaves']];
        }
        // By stage, with each course in its box.
        $stages = [];
        foreach ($m['bygrade'] as $g) {
            $age = grades::age((string) $g['grade'], $centre);
            $stage = $age !== null ? grades::stage_of($age, $centre) : null;
            $name = $stage ? (string) $stage['name'] : get_string('metrics_nograde', $c);
            $s = $stages[$name] ?? ['name' => $name, 'from' => $stage ? (int) $stage['from'] : 99, 'joins' => 0, 'leaves' => 0,
                'detail' => []];
            $s['joins'] += (int) $g['joins'];
            $s['leaves'] += (int) $g['leaves'];
            if ($g['joins'] || $g['leaves']) {
                $s['detail'][] = get_string('panel_new_stage_course', $c, (object) ['course' => $g['grade'], 'joins' => $g['joins'],
                    'leaves' => $g['leaves']]);
            }
            $stages[$name] = $s;
        }
        $stages = array_values(array_filter($stages, fn($s) => $s['joins'] || $s['leaves']));
        usort($stages, fn($a, $b) => $a['from'] <=> $b['from']);
        $smost = max(1, ...array_map(fn($s) => max($s['joins'], $s['leaves']), $stages ?: [['joins' => 1, 'leaves' => 1]]));
        $maxleaves = $stages ? max(array_column($stages, 'leaves')) : 0;
        $top = $stages ? array_reduce($stages, fn($a, $b) => !$a || $b['leaves'] > $a['leaves'] ? $b : $a) : null;
        $stagerows = [];
        foreach ($stages as $s) {
            $id = 'ex-s-' . substr(md5($s['name']), 0, 8);
            self::define($id, get_string('panel_ex_stage_t', $c, (object) ['stage' => $s['name'], 'joins' => $s['joins'],
                'leaves' => $s['leaves']]), get_string('panel_ex_stage_w', $c, $s['name']), get_string('panel_ex_stage_h', $c) .
                ($s['detail'] ? ' ' . implode('; ', $s['detail']) . '.' : ''), $s['leaves'] && $s['leaves'] === $maxleaves &&
                $m['totals']['leaves'] ? get_string('panel_ex_stage_c', $c, round(100 * $s['leaves'] / $m['totals']['leaves'])) : '');
            $stagerows[] = ['name' => $s['name'], 'joins' => $s['joins'], 'leaves' => $s['leaves'],
                'av' => round($s['joins'] / $smost, 3), 'bv' => round($s['leaves'] / $smost, 3), 'explain' => $id];
        }
        $list = function (string $title, array $rows, bool $out): array {
            $top = max(1, ...array_column($rows ?: [['n' => 1]], 'n'));
            return ['title' => $title, 'rows' => array_map(fn($r) => ['name' => $r['name'], 'n' => $r['n'],
                'v' => round($r['n'] / $top, 3), 'out' => $out], $rows)];
        };
        $lists = [];
        $reasons = array_map(fn($r) => ['name' => $r['reason'], 'n' => (int) $r['count']], $m['reasons']);
        if ($reasons) {
            $lists[] = $list(get_string('panel_new_why_leave', $c), $reasons, true);
        }
        if ($m['to']) {
            $lists[] = $list(get_string('panel_new_where_to', $c), array_map(fn($r) => ['name' => $r['school'],
                'n' => (int) $r['count']], $m['to']), true);
        }
        if ($m['from']) {
            $lists[] = $list(get_string('panel_new_where_from', $c), array_map(fn($r) => ['name' => $r['school'],
                'n' => (int) $r['count']], $m['from']), false);
        }
        $done = !array_filter($rows, fn($r) => $r['future'] || $r['current']);
        $diff = $leaves - $bleaves;
        $topname = $top ? $top['name'] : '';
        $topn = $top ? $top['leaves'] : 0;
        $stagetext = $topname !== '' && $topn ? ' ' . get_string(
            $done ? 'panel_new_trend_stage_done' : 'panel_new_trend_stage',
            $c,
            (object) ['stage' => $topname, 'n' => $topn]
        ) : '';
        self::define(
            'ex-m-joins',
            self::one('panel_ex_joins_t', $joins, $joins),
            get_string('panel_ex_joins_w', $c),
            get_string('panel_ex_joins_h', $c),
            get_string('panel_ex_joins_c', $c)
        );
        self::define(
            'ex-m-leaves',
            self::one('panel_ex_leaves_t', $leaves, $leaves),
            get_string('panel_ex_leaves_w', $c),
            get_string('panel_ex_joins_h', $c),
            $before['bymonth'] ? get_string('panel_ex_leaves_c', $c, $bleaves) : ''
        );
        return [
            'cut' => $done ? get_string('panel_new_moves_cut_done', $c, $year) : get_string('panel_new_moves_cut', $c),
            'joins' => $joins, 'leaves' => $leaves, 'exjoins' => 'ex-m-joins', 'exleaves' => 'ex-m-leaves',
            'bleaves' => $bleaves, 'hasbefore' => (bool) $before['bymonth'], 'topstage' => $topname, 'topn' => $topn,
            'trend' => $done ? get_string('panel_new_trend_done', $c, (object) ['joins' => $joins, 'leaves' => $leaves]) .
                $stagetext : ($before['bymonth'] ? get_string('panel_new_trend_' . ($diff > 0 ? 'more' : ($diff < 0 ? 'less' :
                'same')), $c, (object) ['leaves' => $leaves, 'diff' => abs($diff), 'before' => $bleaves]) . $stagetext : ''),
            'months' => $months, 'stages' => $stagerows, 'lists' => $lists, 'def' => get_string('panel_new_moves_def', $c),
        ];
    }

    /**
     * What comes out of the numbers, from fixed rules, each with its number and its why.
     *
     * @param \stdClass $centre
     * @param array $o
     * @param array $campaigns
     * @param array|null $moves
     * @return array|false
     */
    protected static function conclusions(\stdClass $centre, array $o, array $campaigns, ?array $moves) {
        $c = self::C;
        $items = [];
        $ads = array_filter($campaigns, fn($k) => !$k['act']);
        $acts = array_filter($campaigns, fn($k) => $k['act']);
        $sumk = fn(array $list, string $key) => array_sum(array_map(fn($k) => (float) $k[$key], $list));
        $kind = fn(string $k) => ['kind' => $k, 'kindname' => get_string('panel_new_kind_' .
            ['fuerte' => 'strong', 'flojo' => 'weak', 'tendencia' => 'trend'][$k], $c), 'ai' => false];
        if ($ads) {
            $newin = (int) $sumk($ads, 'newin');
            $base = (int) ($sumk($ads, 'contactsn') - $sumk($ads, 'centre'));
            $per = $base ? 100 * $newin / $base : 0;
            if ($base >= self::MIN && ($per < 5 || $per >= 10)) {
                $items[] = $kind($per < 5 ? 'flojo' : 'fuerte') + ['anchor' => 'publicidad',
                    'text' => get_string($per >= 10 ? 'panel_new_c_ads_good' : ('panel_new_c_ads' . ($newin === 0 ? '_none' :
                        ($per < 1 ? '_less' : (round($per) == 1 ? '_one' : '')))), $c, (object) ['n' => '<b>' . round($per) . '</b>']),
                    'why' => get_string('panel_new_c_ads_why', $c, (object) ['k' => $newin, 'of' => self::num($base),
                        'money' => centres::money($centre, $sumk($ads, 'moneyn')), 'hours' => self::hours($sumk($ads, 'hoursn'))])];
            }
        }
        foreach ($acts as $act) {
            $base = (int) $act['contactsn'] - (int) $act['centre'];
            $per = $base ? 100 * (int) $act['newin'] / $base : 0;
            if ($base >= self::MIN && $per >= 10) {
                $items[] = $kind('fuerte') + ['anchor' => 'publicidad', 'text' => get_string('panel_new_c_act', $c, (object) [
                    'act' => $act['label'], 'n' => '<b>' . round($per) . '</b>']), 'why' => get_string(
                        'panel_new_c_act_why',
                        $c,
                        (object) ['k' => $act['newin'], 'of' => $base, 'hours' => $act['hours']]
                    )];
            }
        }
        $g = array_map(fn($x) => (int) $x['n'], $o['groups']);
        $centren = $g['inner'] + $g['sibling'];
        if ($o['total'] >= self::MIN && $centren * 2 > $o['total']) {
            $items[] = $kind('fuerte') + ['anchor' => 'de-donde', 'text' => get_string('panel_new_c_inside', $c, (object) [
                'n' => '<b>' . get_string('panel_new_x_of_y', $c, (object) ['x' => $centren, 'y' => $o['total']]) . '</b>']),
                'why' => get_string('panel_new_c_inside_why', $c, (object) ['inner' => $g['inner'], 'sibling' => $g['sibling']])];
        }
        if (
            $moves && $moves['hasbefore'] && $moves['leaves'] !== $moves['bleaves'] &&
            max($moves['leaves'], $moves['bleaves']) >= self::MIN
        ) {
            $items[] = $kind('tendencia') + ['anchor' => 'altas-y-bajas', 'text' => get_string($moves['leaves'] > $moves['bleaves'] ?
                'panel_new_c_leaves_more' : 'panel_new_c_leaves_less', $c, (object) ['n' => '<b>' . $moves['leaves'] . '</b>',
                'before' => $moves['bleaves']]), 'why' => $moves['topstage'] !== '' ? get_string(
                    'panel_new_c_leaves_why',
                    $c,
                    (object) ['n' => $moves['topn'], 'stage' => $moves['topstage']]
                ) : ''];
        }
        return $items ? ['items' => $items, 'def' => get_string('panel_new_conclusions_def', $c)] : false;
    }

    /**
     * A hundred dots: those enrolled of new families, of the centre and the rest; half a dot when under 1 in 100.
     *
     * @param int $nnew
     * @param int $ncentre
     * @param int $base
     * @return array list (k: n, c, m or ''), per (or null when under one).
     */
    public static function dots(int $nnew, int $ncentre, int $base): array {
        if ($base <= 0) {
            return ['list' => array_fill(0, 100, ['k' => '']), 'per' => 0];
        }
        $raw = 100 * ($nnew + $ncentre) / $base;
        $total = (int) min(100, round($raw));
        if ($raw > 0 && $total === 0) {
            return ['list' => array_merge([['k' => 'm']], array_fill(0, 99, ['k' => ''])), 'per' => null];
        }
        $n = min($total, max($nnew ? 1 : 0, (int) round(100 * $nnew / $base)));
        return ['list' => array_merge(
            array_fill(0, $n, ['k' => 'n']),
            array_fill(0, $total - $n, ['k' => 'c']),
            array_fill(0, 100 - $total, ['k' => ''])
        ), 'per' => $total];
    }

    /**
     * The year before a school year starts, when its campaign happens: from twelve months before its start to three
     * months after (who joins in the first weeks).
     *
     * @param string $year
     * @return int[] [from, to]
     */
    public static function window(string $year): array {
        $start = imports::year_start($year);
        return [$start - 365 * DAYSECS, $start + 92 * DAYSECS];
    }

    /**
     * The hours each campaign and act has written by the centre.
     *
     * @param int $centreid
     * @return array scope => id => task => hours.
     */
    public static function written(int $centreid): array {
        global $DB;
        $out = ['import' => [], 'event' => []];
        foreach ($DB->get_records(self::HOURS, ['centreid' => $centreid], 'id ASC') as $row) {
            $out[$row->scope][(int) $row->scopeid][(string) $row->task] = (float) $row->hours;
        }
        return $out;
    }

    /**
     * Writes the hours of a task of a campaign or an act: 0 takes the line away.
     *
     * @param int $centreid
     * @param string $scope import or event.
     * @param int $scopeid
     * @param string $task
     * @param float $hours
     * @param int $userid
     */
    public static function set_hours(int $centreid, string $scope, int $scopeid, string $task, float $hours, int $userid): void {
        global $DB;
        $owner = ['import' => imports::T, 'event' => events::T][$scope] ?? null;
        if (!$owner || !$DB->record_exists($owner, ['id' => $scopeid, 'centreid' => $centreid])) {
            return;
        }
        $task = \core_text::substr(trim(clean_param($task, PARAM_TEXT)), 0, 100);
        if ($task === '') {
            return;
        }
        $where = ['centreid' => $centreid, 'scope' => $scope, 'scopeid' => $scopeid, 'task' => $task];
        $row = $DB->get_record(self::HOURS, $where);
        $hours = max(0, min(10000, round($hours, 1)));
        if ($row && !$hours) {
            $DB->delete_records(self::HOURS, ['id' => $row->id]);
        } else if ($row) {
            $DB->update_record(self::HOURS, (object) ['id' => $row->id, 'hours' => $hours, 'userid' => $userid,
                'timemodified' => time()]);
        } else if ($hours) {
            $DB->insert_record(self::HOURS, (object) ($where + ['hours' => $hours, 'userid' => $userid, 'timemodified' => time()]));
        }
    }

    /**
     * The name of a task of hours: one of the usual ones in the language of the page, or as it was written.
     *
     * @param string $task
     * @return string
     */
    public static function task_name(string $task): string {
        return in_array($task, self::TASKS, true) ? get_string('panel_new_task_' . $task, self::C) : format_string($task);
    }

    /**
     * When the contacts of a campaign came, said short.
     *
     * @param int $first
     * @param int $last
     * @return string
     */
    protected static function when(int $first, int $last): string {
        if (!$first) {
            return '';
        }
        $a = userdate($first, '%B %Y');
        $b = userdate($last, '%B %Y');
        return $a === $b ? $a : get_string('panel_new_from_to', self::C, (object) ['from' => $a, 'to' => $b]);
    }

    /**
     * A number of hours, with one decimal.
     *
     * @param float $h
     * @return string
     */
    public static function hours(float $h): string {
        return get_string('panel_js_hours_n', self::C, format_float(
            round($h, 1),
            round($h, 1) == (int) round($h, 1) ? 0 : 1,
            true,
            true
        ));
    }

    /**
     * A whole number, as the centre's language writes it.
     *
     * @param int $n
     * @return string
     */
    protected static function num(int $n): string {
        return format_float($n, 0, true, true);
    }

    /**
     * Keeps the punctuation right after a number with it, so a «)» or a «;» never goes alone to the next line.
     *
     * @param string $html
     * @return string
     */
    protected static function glue(string $html): string {
        return preg_replace(
            '~(<button type="button" class="an-toque"[^>]*>[^<]*</button>)([).,;:»]+)~u',
            '<span class="an-junto">$1$2</span>',
            $html
        );
    }

    /**
     * A number that is a button: its box says what it is.
     *
     * @param string $id
     * @param string $text
     * @return string
     */
    protected static function button(string $id, string $text): string {
        return '<button type="button" class="an-toque" data-explica="' . s($id) . '">' . $text . '</button>';
    }

    /**
     * Keeps the definition of a number: its title, what it is, how it is counted and what to keep in mind.
     *
     * @param string $id
     * @param string $title
     * @param string $what
     * @param string $how
     * @param string $careful
     */
    protected static function define(string $id, string $title, string $what, string $how, string $careful): void {
        self::$explain[$id] = ['id' => $id, 'title' => $title, 'what' => $what, 'how' => $how, 'careful' => $careful];
    }
}
