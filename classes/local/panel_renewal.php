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

    /** @var string[] The order of the rows of a course: who does not go on, who does not know, who said nothing, who goes on. */
    const ORDER = ['leave', 'unsure', 'none', 'stay'];

    /**
     * The context of the template panel_renewal.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, int $now): array {
        $c = self::C;
        $centreid = (int) $centre->id;
        $year = trim((string) ($centre->renewalyear ?? ''));
        $open = renewal::open($centre, $now);
        $day = get_string('strftimedaydate', 'langconfig');
        $short = get_string('strftimedateshort', 'langconfig');
        $courses = $year !== '' ? renewal::by_course($centre) : [];
        $totals = array_fill_keys(self::ORDER, 0);
        $households = [];
        $groups = [];
        foreach ($courses as $course) {
            $rows = [];
            foreach (self::ORDER as $kind) {
                foreach ($course[$kind] as [$child, $householdid, $row]) {
                    $totals[$kind]++;
                    $households[$householdid] = $households[$householdid] ?? self::household($householdid, $centreid);
                    $adults = $households[$householdid];
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
            $stay = count($course['stay']);
            $groups[] = [
                'offerid' => (int) $course['offer']->id,
                'title' => format_string($course['offer']->name),
                'next' => get_string('panel_renewal_next', $c, format_string($course['next']->name)),
                'count' => get_string('panel_renewal_count', $c, (object) ['stay' => $stay, 'all' => count($rows)]),
                'leave' => count($course['leave']),
                'rows' => $rows,
            ];
        }
        $asked = array_sum($totals);
        $told = renewal::told($centre);
        $totell = $year !== '' ? count(renewal::to_tell($centre)) : 0;
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
                'tell' => $totell ? get_string('panel_renewal_tell', $c, $totell) : '',
                'told' => $told['count'] ? get_string('panel_renewal_told', $c, (object) ['count' => $told['count'],
                    'day' => userdate($told['time'], $day)]) : '',
            ] : false,
            'groups' => $groups,
            'hasgroups' => (bool) $groups,
            'canact' => $canact,
            'action' => $action,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The adults of a family, in a line.
     *
     * @param int $householdid
     * @param int $centreid
     * @return string
     */
    protected static function household(int $householdid, int $centreid): string {
        $household = households::get($householdid, $centreid);
        return $household ? panel::adults_line(households::full($household)->adults) : '';
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
                    return ['url' => $url, 'text' => get_string('panel_renewal_link_start', self::C, s($cand['year']))];
                }
            }
            return false;
        }
        $asked = 0;
        $answered = 0;
        $leave = 0;
        foreach (renewal::by_course($centre) as $course) {
            $all = count($course['stay']) + count($course['leave']) + count($course['unsure']) + count($course['none']);
            $asked += $all;
            $answered += $all - count($course['none']);
            $leave += count($course['leave']);
        }
        if (!$asked) {
            return false;
        }
        return [
            'url' => $url,
            'text' => get_string('panel_renewal_link', self::C, (object) ['year' => s((string) $centre->renewalyear),
                'answered' => $answered, 'asked' => $asked, 'leave' => $leave]),
        ];
    }
}
