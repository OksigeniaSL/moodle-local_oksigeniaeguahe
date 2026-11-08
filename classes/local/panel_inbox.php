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

    /**
     * The context of the inbox.
     *
     * @param \stdClass $centre
     * @param bool $all Every family, not only those that wait.
     * @param int $userid Who looks.
     * @param bool $canact Whether they may write down a family.
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $all, int $userid, bool $canact, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $c = self::C;
        $centreid = (int) $centre->id;
        $waiting = households::waiting_count($centreid);
        $total = $DB->count_records(households::T, ['centreid' => $centreid]);
        $list = households::inbox($centreid, !$all);
        $rows = self::rows($list, $now);
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
        $places = self::places($centre);
        $newfamily = new \moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centreid]);
        $visits = self::visits($centreid, $userid);
        return [
            'head' => panel::head([
                'title' => $title,
                'sub' => $sub,
                'action' => $canact ? ['url' => $newfamily->out(false),
                    'name' => get_string('household_new', $c), 'icon' => panel::icon('mas1')] : null,
            ]),
            'fresh' => $fresh,
            'showtools' => !$fresh,
            'all' => $all,
            'waiting' => $waiting,
            'total' => $total,
            'waitingurl' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]))->out(false),
            'allurl' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid, 'all' => 1]))->out(false),
            'rows' => $rows,
            'hasrows' => (bool) $rows,
            'chev' => panel::icon('der', 'chev'),
            'emptybag' => panel::hanging(0, ''),
            'emptytitle' => get_string($fresh ? 'panel_inbox_fresh_empty' : 'panel_inbox_upto', $c),
            'emptytext' => get_string($fresh ? 'panel_inbox_fresh_text' : 'panel_inbox_upto_text', $c),
            'newevent' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centreid]))->out(false),
            'newform' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centreid]))->out(false),
            'visits' => $visits,
            'hasvisits' => (bool) $visits,
            'year' => $places['year'],
            'places' => $places['list'],
            'hasplaces' => (bool) $places['list'],
        ];
    }

    /**
     * The rows of the inbox: the wait or the answer, the adults, what they said, how and when, and the children.
     *
     * @param array $list Households, as households::inbox() gives them.
     * @param int $now
     * @return array
     */
    public static function rows(array $list, int $now): array {
        global $DB;
        $c = self::C;
        $latest = households::latest(array_keys($list));
        $userids = array_filter(array_map(fn($l) => (int) ($l['answer']->userid ?? 0), $latest));
        $people = $userids ? $DB->get_records_list('user', 'id', array_unique($userids), '', 'id, firstname') : [];
        $rows = [];
        foreach ($list as $household) {
            $info = $latest[$household->id] ?? ['last' => null, 'said' => null, 'answer' => null];
            if ($household->waitingsince > 0) {
                $words = panel::wait_words((int) $household->waitingsince, $now);
                [$main, $sub] = [$words['main'], $words['sub']];
            } else {
                $answer = $info['answer'];
                $main = get_string('panel_answered', $c);
                $sub = $answer ? panel::when_words((int) $answer->timecreated, $now) .
                    (isset($people[$answer->userid]) ? ', ' . $people[$answer->userid]->firstname : '') : '';
            }
            $last = $info['last'];
            $channel = $last ? (string) $last->channel : (string) $household->firstchannel;
            $via = get_string('via_' . (in_array($channel, lists::CHANNELS, true) ? $channel : 'other'), $c);
            if ($last) {
                $via .= ', ' . panel::when_words((int) $last->timecreated, $now);
            }
            if (!empty($household->moving)) {
                $via .= ' · ' . get_string('panel_moving', $c);
            }
            $children = [];
            $search = [];
            foreach ($household->children as $child) {
                $children[] = [
                    'tag' => panel::tag($child),
                    'course' => $child->offer ? format_string($child->offer->name) : get_string('panel_nocourse', $c),
                    'stage' => $child->stage !== 'inquiry' ? get_string('stage_' . $child->stage, $c) : '',
                ];
                $search[] = $child->firstname . ' ' . $child->tagname;
            }
            foreach ($household->adults as $adult) {
                $search[] = $adult->firstname . ' ' . $adult->lastname . ' ' . $adult->email . ' ' . $adult->phone;
            }
            $said = $info['said'] ? trim(preg_replace('/\s+/u', ' ', (string) $info['said']->body)) : '';
            $rows[] = [
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
                'waiting' => $household->waitingsince > 0,
                'main' => $main,
                'sub' => $sub,
                'adults' => panel::adults_line($household->adults),
                'said' => $said !== '' ? get_string('panel_quote', $c, \core_text::substr($said, 0, 200)) : '',
                'via' => $via,
                'viaicon' => panel::channel_icon($channel),
                'children' => $children,
                'search' => \core_text::strtolower(implode(' ', $search)),
            ];
        }
        return $rows;
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
                'rail' => $total > 0 ? panel::rail($total, $enrolled) : '',
                'interested' => array_sum($bystage) - ($bystage['closed'] ?? 0) - $enrolled,
                'visited' => ($bystage['visit'] ?? 0) + ($bystage['application'] ?? 0) + ($bystage['admitted'] ?? 0) +
                    ($bystage['waitlist'] ?? 0),
                'enrolled' => $enrolled,
            ];
        }
        return ['year' => s($year), 'list' => $list];
    }
}
