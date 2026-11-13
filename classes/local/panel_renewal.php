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
 * Who goes on (renewal.php): the centre asks its families, for a school year, whether each child goes on; here it starts
 * and stops asking, writes once to whoever has not answered, and sees it course by course, who does not go on first.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_renewal {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int Above how many children the page lists one course at a time. */
    const LIST = 400;

    /** @var string[] The order of the rows of a course: who does not go on, who does not know, who said nothing, who goes on. */
    const ORDER = ['leave', 'unsure', 'none', 'stay'];

    /**
     * The context of the template panel_renewal.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @param string $picked The value of the roll lifted to be hung, by its id; empty, none.
     * @param int $course The course whose children are listed, when there are too many to list them all.
     * @return array
     */
    public static function context(
        \stdClass $centre,
        bool $canact,
        int $now,
        string $picked = '',
        int $course = 0
    ): array {
        global $DB;
        $c = self::C;
        $centreid = (int) $centre->id;
        $year = trim((string) ($centre->renewalyear ?? ''));
        $open = renewal::open($centre, $now);
        $day = get_string('strftimedaydate', 'langconfig');
        $short = get_string('strftimedateshort', 'langconfig');
        $courses = $year !== '' ? renewal::by_course($centre) : [];
        $totals = array_fill_keys(self::ORDER, 0);
        // The adults of every family at once, for the line under each child: one query, not one per family.
        $households = [];
        foreach (
            $DB->get_recordset_select(
                households::ADULTS,
                'centreid = ?',
                [$centreid],
                'id ASC',
                'id, householdid, firstname, lastname'
            ) as $adult
        ) {
            $households[(int) $adult->householdid][] = $adult;
        }
        $many = array_sum(array_map(fn($g) => count($g['stay']) + count($g['leave']) + count($g['unsure']) +
            count($g['none']), $courses)) > self::LIST;
        $groups = [];
        foreach ($courses as $group) {
            $listed = !$many || (int) $group['offer']->id === $course;
            $rows = [];
            $all = 0;
            foreach (self::ORDER as $kind) {
                foreach ($group[$kind] as [$child, $householdid, $row]) {
                    $totals[$kind]++;
                    $all++;
                    if (!$listed) {
                        continue;
                    }
                    $adults = panel::adults_line($households[$householdid] ?? []);
                    $said = '';
                    if ($row) {
                        $who = (int) $row->userid ? \core_user::get_user((int) $row->userid) : null;
                        $said = get_string($who ? 'panel_renewal_said_by' : 'panel_renewal_said_family', $c, (object) [
                            'day' => userdate((int) $row->timemodified, $short), 'who' => $who ? fullname($who) : '',
                            'answer' => get_string('renewal_state_' . $row->answer, $c) . ($row->answer === 'leave' &&
                                $row->reason ? ' (' . get_string('left_' . $row->reason, $c) . ')' : '')]);
                    }
                    $rows[] = [
                        'id' => (int) $child->id,
                        'kind' => $kind,
                        'tag' => panel::tag($child),
                        'adults' => $adults,
                        'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $householdid]))->out(false) .
                            '#sigue-' . (int) $child->id,
                        'said' => $said !== '' ? $said : get_string('panel_renewal_unanswered', $c),
                        'note' => $row && trim((string) $row->note) !== '' ? (string) $row->note : '',
                        'answers' => array_map(fn($key) => ['key' => $key, 'label' => get_string('renewal_answer_' . $key, $c),
                            'pressed' => $row && $row->answer === $key], renewal::ANSWERS),
                        'search' => \core_text::strtolower($child->firstname . ' ' . $child->tagname . ' ' . $adults),
                    ];
                }
            }
            $stay = count($group['stay']);
            $groups[] = [
                'offerid' => (int) $group['offer']->id,
                'title' => format_string($group['offer']->name),
                'next' => get_string('panel_renewal_next', $c, format_string($group['next']->name)),
                'count' => get_string('panel_renewal_count', $c, (object) ['stay' => $stay, 'all' => $all]),
                'leave' => count($group['leave']),
                'rows' => $rows,
                // In a big centre, a course at a time: the others with their numbers and the way to list them.
                'listed' => $listed,
                'listurl' => $listed ? '' : (new \moodle_url('/local/oksigeniaeguahe/renewal.php', ['c' => $centreid,
                    'curso' => (int) $group['offer']->id], 'curso' . (int) $group['offer']->id))->out(false),
            ];
        }
        $asked = array_sum($totals);
        $told = renewal::told($centre);
        $totell = $year !== '' ? count(renewal::to_tell($centre)) : 0;
        // The mails waiting to go, in the background.
        $sending = false;
        foreach (\core\task\manager::get_adhoc_tasks('\\local_oksigeniaeguahe\\task\\tell_renewal') as $task) {
            $sending = $sending || (int) ($task->get_custom_data()->centreid ?? 0) === $centreid;
        }
        $answered = $asked - $totals['none'];
        // The state of the question: never asked, asking now (or soon), or asked and closed.
        $until = (int) ($centre->renewaluntil ?? 0);
        $state = $year === '' ? 'start' : ($until && $now >= $until ? 'closed' : 'open');
        $candidates = array_map(fn($cand) => $cand + [
            'label' => get_string('panel_renewal_year', $c, s($cand['year'])),
            'line' => $cand['ready'] ? get_string('panel_renewal_year_children', $c, $cand['children']) :
                get_string('panel_renewal_year_nooffer', $c),
            'current' => $cand['year'] === $year,
        ], renewal::candidates($centre));
        $action = (new \moodle_url('/local/oksigeniaeguahe/renewal.php', ['c' => $centreid]))->out(false);
        $untilday = $until ? $until - DAYSECS : 0;
        return [
            'head' => panel::head([
                'title' => get_string('panel_renewal', $c),
                'ante' => $year !== '' ? get_string('panel_renewal_year', $c, s($year)) : '',
                'sub' => $year === '' ? get_string('panel_renewal_sub', $c) : ($asked ? get_string(
                    'panel_renewal_totals',
                    $c,
                    (object) ['answered' => $answered, 'asked' => $asked, 'stay' => $totals['stay'],
                        'leave' => $totals['leave'], 'unsure' => $totals['unsure']]
                ) : get_string('panel_renewal_nobody', $c)),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]))->out(false),
                    'name' => get_string('tab_inbox', $c)],
            ]),
            'start' => $state !== 'open' ? [
                'closed' => $state === 'closed' ? get_string('panel_renewal_closed_on', $c, (object) ['year' => s($year),
                    'day' => userdate($untilday, $day)]) : '',
                'candidates' => $candidates,
                'hascandidates' => (bool) $candidates,
            ] : false,
            'open' => $state === 'open' ? [
                'since' => $now < (int) $centre->renewalfrom ? get_string('panel_renewal_from_future', $c, userdate(
                    (int) $centre->renewalfrom,
                    $day
                )) : get_string('panel_renewal_since', $c, userdate((int) $centre->renewalfrom, $day)),
                'until' => $until ? get_string('panel_renewal_until', $c, userdate($untilday, $day)) :
                    get_string('panel_renewal_no_end', $c),
                'leaf' => $until ? panel::leaf($untilday) : false,
                'date' => ['labelled' => false, 'arialabel' => get_string('panel_renewal_until_label', $c)] +
                    panel::date_input('until', $until ? userdate($untilday, '%Y-%m-%d') : '', $centre, 'rm-hasta'),
                'hasend' => (bool) $until,
                'tell' => $totell && !$sending ? get_string('panel_renewal_tell', $c, $totell) : '',
                'told' => $told['count'] ? get_string('panel_renewal_told', $c, (object) ['count' => $told['count'],
                    'day' => userdate($told['time'], $day)]) : '',
                'sending' => $sending ? get_string('panel_renewal_sending', $c) : '',
            ] : false,
            'groups' => $groups,
            'hasgroups' => (bool) $groups,
            'roster' => roster::available() ? self::roster($centre, $picked) : false,
            'canact' => $canact,
            'action' => $action,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The families that were already at the centre, from its roll: where the course of each student comes from (each
     * way a button), the school year they are in now, what each cohort, group or value is (the course pressed, the one
     * it most likely is marked to confirm), and how many students have a course; then bringing them.
     *
     * @param \stdClass $centre
     * @param string $picked The id of the value lifted to be hung; empty, none.
     * @return array
     */
    protected static function roster(\stdClass $centre, string $picked = ''): array {
        $c = self::C;
        $source = roster::source($centre);
        ['userids' => $userids, 'nameless' => $nameless, 'count' => $count, 'groups' => $groups] = roster::students();
        $sources = roster::sources($userids);
        $kinds = [['key' => 'cohort', 'arg' => '', 'label' => get_string('panel_roster_cohort', $c),
            'pressed' => $source && $source[0] === 'cohort']];
        foreach ($sources['courses'] as $id => $name) {
            $kinds[] = ['key' => 'group', 'arg' => (string) $id, 'label' => get_string('panel_roster_group', $c, $name),
                'pressed' => $source && $source[0] === 'group' && $source[1] === (string) $id];
        }
        if ($groups) {
            // Only the group the record keeps from the data of the centre, for a centre without cohorts or groups.
            $kinds[] = ['key' => 'family', 'arg' => '', 'label' => get_string('panel_roster_family', $c),
                'pressed' => $source && $source[0] === 'family'];
        }
        foreach ($sources['fields'] as $short => $name) {
            $kinds[] = ['key' => 'profile', 'arg' => (string) $short, 'label' => get_string('panel_roster_field', $c, $name),
                'pressed' => $source && $source[0] === 'profile' && $source[1] === (string) $short];
        }
        $year = trim((string) ($centre->rosteryear ?? ''));
        $years = array_values(array_unique(array_map(fn($o) => (string) $o->schoolyear, centres::offers((int) $centre->id))));
        sort($years);
        $offers = roster::offers($centre);
        // The courses by age, from the youngest, as the shelves of the offer.
        uasort($offers, fn($a, $b) => [grades::age((string) $a->name, $centre) ?? 99, (int) $a->sortorder, (int) $a->id] <=>
            [grades::age((string) $b->name, $centre) ?? 99, (int) $b->sortorder, (int) $b->id]);
        $out = [
            'students' => get_string('panel_roster_students', $c, $count),
            'nameless' => $nameless ? get_string('panel_roster_nameless', $c, $nameless) : '',
            'kinds' => $kinds,
            'kind' => $source ? $source[0] : '',
            'arg' => $source ? $source[1] : '',
            'years' => array_map(fn($y) => ['year' => $y, 'label' => get_string('panel_renewal_year', $c, s($y)),
                'pressed' => $y === $year], $years),
            'year' => $year,
            'noyear' => $source && $year === '',
            'nooffer' => $source && $year !== '' && !$offers ? get_string('panel_roster_nooffer', $c, s($year)) : '',
            'values' => [],
            'hasvalues' => false,
            'summary' => '',
            'guesses' => false,
            'canbring' => false,
            'brought' => '',
            'rack' => [],
            'loose' => [],
            'hasloose' => false,
            'notcourses' => [],
            'picked' => false,
            'dropurl' => '',
            // Open while there is something to say: where, the school year, a value nobody said, or bringing them.
            'open' => true,
        ];
        if (!$source || !$offers) {
            return $out;
        }
        $overview = roster::overview($centre);
        $guesses = 0;
        $base = new \moodle_url('/local/oksigeniaeguahe/renewal.php', ['c' => $centre->id]);
        // The courses of the system as the hooks of a rack, a row for each stage; what cannot be placed in one, apart.
        $rack = [];
        foreach ($offers as $plain => $offer) {
            $age = grades::age((string) $offer->name, $centre);
            $stage = $age !== null ? grades::stage_of($age, $centre) : null;
            $key = $stage ? 's' . str_pad((string) $stage['from'], 3, '0', STR_PAD_LEFT) : 'zotros';
            $rack[$key] = $rack[$key] ?? ['key' => $key, 'name' => $stage ? $stage['name'] :
                get_string('panel_roster_other', $c), 'ages' => $stage ? get_string(
                    'panel_roster_ages',
                    $c,
                    (object) ['from' => $stage['from'], 'to' => $stage['to']]
                ) : '', 'grades' => []];
            // The hook, not the value: inside the lifted tag, «value» is the name of the tag.
            $rack[$key]['grades'][$plain] = ['value' => (string) $offer->name, 'hook' => (string) $offer->name,
                'name' => format_string($offer->name), 'tags' => []];
        }
        $loose = [];
        $notcourses = [];
        $lifted = false;
        foreach ($overview['values'] as $row) {
            $id = dechex(crc32($row['value']));
            $mapped = $row['grade'];
            $guess = $mapped === null ? $row['guess'] : '';
            $tag = ['id' => $id, 'value' => $row['value'], 'count' => get_string('panel_roster_count', $c, $row['count']),
                'guess' => $guess !== '', 'grade' => $mapped ?? $guess, 'picked' => $id === $picked,
                'url' => (new \moodle_url($base, ['cohorte' => $id], 'padron-tengo'))->out(false)];
            if ($id === $picked) {
                $lifted = ['id' => $id, 'value' => $row['value'], 'count' => $tag['count']];
            }
            $where = grades::plain((string) ($mapped ?? $guess));
            if ($mapped === '') {
                $notcourses[] = $tag;
                continue;
            }
            $placed = false;
            foreach ($rack as $key => $stage) {
                if ($where !== '' && isset($stage['grades'][$where])) {
                    $rack[$key]['grades'][$where]['tags'][] = $tag;
                    $placed = true;
                }
            }
            $guesses += $placed && $guess !== '' ? 1 : 0;
            if (!$placed) {
                $loose[] = $tag;
            }
            $out['values'][] = ['pending' => $mapped === null];
        }
        ksort($rack);
        $out['rack'] = array_values(array_map(fn($stage) => ['grades' => array_values($stage['grades'])] + $stage, $rack));
        $out['loose'] = $loose;
        $out['hasloose'] = (bool) $loose;
        $out['notcourses'] = $notcourses;
        $out['picked'] = $lifted;
        $out['dropurl'] = (new \moodle_url($base, [], 'padron'))->out(false);
        $brought = roster::brought($centre);
        $out['hasvalues'] = (bool) $out['values'];
        $out['summary'] = get_string('panel_roster_placed', $c, (object) ['placed' => $overview['placed'],
            'students' => $overview['students']]);
        $out['guesses'] = $guesses ? get_string('panel_roster_accept', $c, $guesses) : false;
        $out['canbring'] = $overview['placed'] > 0;
        $pending = count(array_filter($out['values'], fn($v) => $v['pending']));
        $out['open'] = $pending > 0 || !$brought['time'] || $lifted;
        $out['brought'] = $brought['time'] ? get_string('panel_roster_brought', $c, (object) [
            'families' => $brought['families'], 'children' => $brought['children'],
            'day' => userdate($brought['time'], get_string('strftimedatetimeshort', 'langconfig'))]) : '';
        return $out;
    }

    /**
     * The link of the inbox to who goes on, while the centre asks: how many answered and how many do not go on.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array|false url, text.
     */
    public static function link(\stdClass $centre, int $now) {
        $url = (new \moodle_url('/local/oksigeniaeguahe/renewal.php', ['c' => $centre->id]))->out(false);
        if (!renewal::open($centre, $now)) {
            // A school year ready to be asked about that was never asked: the way in.
            foreach (renewal::candidates($centre) as $cand) {
                if ($cand['ready'] && $cand['year'] !== trim((string) ($centre->renewalyear ?? ''))) {
                    return ['url' => $url, 'text' => get_string('panel_renewal_link_start', self::C, s($cand['year'])),
                        'pegs' => []];
                }
            }
            return false;
        }
        $asked = 0;
        $answered = 0;
        $leave = 0;
        $pegs = [];
        foreach (renewal::by_course($centre) as $course) {
            $all = count($course['stay']) + count($course['leave']) + count($course['unsure']) + count($course['none']);
            $asked += $all;
            $answered += $all - count($course['none']);
            $leave += count($course['leave']);
            // A hook for each child, as on «Who goes on»: full, crossed, half or empty.
            foreach (self::ORDER as $kind) {
                foreach ($course[$kind] as $unused) {
                    $pegs[] = ['kind' => $kind];
                }
            }
        }
        if (!$asked) {
            return false;
        }
        return [
            'url' => $url,
            'text' => get_string('panel_renewal_link', self::C, (object) ['year' => s((string) $centre->renewalyear),
                'answered' => $answered, 'asked' => $asked, 'leave' => $leave]),
            'pegs' => $pegs,
        ];
    }
}
