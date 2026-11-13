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
 * The re-enrolment (the fifth part of the plan of the first version, 7-11): the centre asks its families, for the next
 * school year, whether each child goes on; they answer from their page («Yes», «No», with why if they wish, or «Not yet»)
 * and the team sees it course by course. From it, the signs that a family may leave: facts with their day, never a mark.
 *
 * Who is asked: the children enrolled in a course of the school year before the one asked about, who have not left,
 * and who have a course to go on to (the next age, in that year); the last course of the centre is not asked.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renewal {
    /** @var string */
    const T = 'local_oksigeniaeguahe_renewal';

    /** @var string[] What a family can say. */
    const ANSWERS = ['stay', 'leave', 'unsure'];

    /** @var string The detail of the line left in the history of a family when it was told by email. */
    const TOLD = 'renewal:';

    /** @var int How many weeks without opening their page is a sign, while the re-enrolment is open. */
    const QUIET = 3 * WEEKSECS;

    /**
     * Whether a centre is asking now.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return bool
     */
    public static function open(\stdClass $centre, int $now): bool {
        $year = trim((string) ($centre->renewalyear ?? ''));
        return $year !== '' && $now >= (int) $centre->renewalfrom &&
            (!(int) $centre->renewaluntil || $now < (int) $centre->renewaluntil);
    }

    /**
     * The school year before one: 2028-29 gives 2027-28; a year alone, the one before.
     *
     * @param string $year
     * @return string
     */
    public static function year_before(string $year): string {
        if (preg_match('/^(\d{4})-(\d{2})$/', $year, $m)) {
            return ((int) $m[1] - 1) . '-' . sprintf('%02d', ((int) $m[2] + 99) % 100);
        }
        return preg_match('/^\d{4}$/', $year) ? (string) ((int) $year - 1) : '';
    }

    /**
     * The school year after one: 2027-28 gives 2028-29; a year alone, the next.
     *
     * @param string $year
     * @return string
     */
    public static function year_after(string $year): string {
        if (preg_match('/^(\d{4})-(\d{2})$/', $year, $m)) {
            return ((int) $m[1] + 1) . '-' . sprintf('%02d', ((int) $m[2] + 1) % 100);
        }
        return preg_match('/^\d{4}$/', $year) ? (string) ((int) $year + 1) : '';
    }

    /**
     * The school years a centre can ask about: the one after each year with children in the centre, the most recent
     * first, and whether its courses are ready (without the offer of that year, nobody has a course to go on to).
     *
     * @param \stdClass $centre
     * @return array[] year, ready, children (how many would be asked).
     */
    public static function candidates(\stdClass $centre): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT o.schoolyear, COUNT(1) AS n
               FROM {' . households::CHILDREN . '} c
               JOIN {' . centres::OFFERS . '} o ON o.id = c.offerid AND o.centreid = c.centreid
              WHERE c.centreid = :centreid AND c.stage = :stage AND (c.lefttime IS NULL OR c.lefttime = 0)
           GROUP BY o.schoolyear',
            ['centreid' => $centre->id, 'stage' => 'enrolled']
        );
        $out = [];
        foreach ($rows as $row) {
            $year = self::year_after((string) $row->schoolyear);
            if ($year === '') {
                continue;
            }
            $asked = count(self::children((object) (['renewalyear' => $year] + (array) $centre)));
            $out[$year] = ['year' => $year, 'ready' => $asked > 0, 'children' => $asked];
        }
        krsort($out);
        return array_values($out);
    }

    /**
     * Starts asking about a school year, or changes its days; with no year, it stops being asked about.
     *
     * @param \stdClass $centre
     * @param string $year
     * @param int $from
     * @param int $until The first moment it is no longer asked; 0, without an end.
     */
    public static function set(\stdClass $centre, string $year, int $from, int $until): void {
        global $DB;
        $year = \core_text::substr(trim($year), 0, 9);
        $DB->update_record(centres::T, (object) ['id' => $centre->id, 'renewalyear' => $year !== '' ? $year : null,
            'renewalfrom' => $year !== '' ? $from : 0, 'renewaluntil' => $year !== '' ? max(0, $until) : 0,
            'timemodified' => time()]);
        $centre->renewalyear = $year !== '' ? $year : null;
        $centre->renewalfrom = $year !== '' ? $from : 0;
        $centre->renewaluntil = $year !== '' ? max(0, $until) : 0;
    }

    /**
     * How many families were told by email for the school year asked about, and when the first one.
     *
     * @param \stdClass $centre
     * @return array count, time.
     */
    public static function told(\stdClass $centre): array {
        global $DB;
        $year = trim((string) ($centre->renewalyear ?? ''));
        if ($year === '') {
            return ['count' => 0, 'time' => 0];
        }
        $row = $DB->get_record_sql(
            'SELECT COUNT(DISTINCT householdid) AS n, MIN(timecreated) AS t
               FROM {' . households::CONTACTS . '}
              WHERE centreid = :centreid AND detail = :detail',
            ['centreid' => $centre->id, 'detail' => self::TOLD . $year]
        );
        return ['count' => (int) ($row->n ?? 0), 'time' => (int) ($row->t ?? 0)];
    }

    /**
     * The children a centre asks about, with the course they are in and the one they would go on to.
     *
     * @param \stdClass $centre
     * @param int $householdid Only those of a family; 0, all.
     * @return array[] child id => [child, now (offer), next (offer)].
     */
    public static function children(\stdClass $centre, int $householdid = 0): array {
        global $DB;
        $year = trim((string) ($centre->renewalyear ?? ''));
        if ($year === '') {
            return [];
        }
        $before = self::year_before($year);
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        // The courses of the year asked about, by their age: where each child would go.
        $next = [];
        foreach ($offers as $offer) {
            $age = grades::age((string) $offer->name, $centre);
            if ((string) $offer->schoolyear === $year && $age !== null && !empty($offer->active)) {
                $next[$age] = $next[$age] ?? $offer;
            }
        }
        $params = ['centreid' => $centre->id, 'stage' => 'enrolled'] + ($householdid ? ['householdid' => $householdid] : []);
        $out = [];
        // The age of each course once, not once per child: a big centre has thousands.
        $ages = [];
        foreach ($DB->get_records(households::CHILDREN, $params, 'id ASC') as $child) {
            $offer = $offers[$child->offerid] ?? null;
            if (!empty($child->lefttime) || !$offer || (string) $offer->schoolyear !== $before) {
                continue;
            }
            $age = $ages[$offer->id] = array_key_exists($offer->id, $ages) ? $ages[$offer->id] :
                grades::age((string) $offer->name, $centre);
            if ($age !== null && isset($next[$age + 1])) {
                $out[(int) $child->id] = [$child, $offer, $next[$age + 1]];
            }
        }
        return $out;
    }

    /**
     * What has been said for a school year, by child.
     *
     * @param \stdClass $centre
     * @param int $householdid Only those of a family; 0, all.
     * @return \stdClass[] child id => record.
     */
    public static function answers(\stdClass $centre, int $householdid = 0): array {
        global $DB;
        $year = trim((string) ($centre->renewalyear ?? ''));
        if ($year === '') {
            return [];
        }
        $params = ['centreid' => $centre->id, 'schoolyear' => $year] + ($householdid ? ['householdid' => $householdid] : []);
        $out = [];
        foreach ($DB->get_records(self::T, $params) as $row) {
            $out[(int) $row->childid] = $row;
        }
        return $out;
    }

    /**
     * Keeps what a family or the team says of a child; a child the centre does not ask about is not kept.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param int $childid
     * @param string $answer stay, leave or unsure.
     * @param string $reason Why they leave, of settled::LEFT_REASONS; else nothing.
     * @param string $note
     * @param int $userid The person of the team; 0, the family.
     * @param int $now
     * @return bool
     */
    public static function answer(
        \stdClass $centre,
        \stdClass $household,
        int $childid,
        string $answer,
        string $reason,
        string $note,
        int $userid,
        int $now
    ): bool {
        global $DB;
        if (!in_array($answer, self::ANSWERS, true) || !isset(self::children($centre, (int) $household->id)[$childid])) {
            return false;
        }
        $year = trim((string) $centre->renewalyear);
        $row = (object) ['centreid' => $centre->id, 'householdid' => $household->id, 'childid' => $childid,
            'schoolyear' => $year, 'answer' => $answer,
            'reason' => $answer === 'leave' && in_array($reason, settled::LEFT_REASONS, true) ? $reason : null,
            'note' => $answer === 'stay' ? null : (\core_text::substr(trim($note), 0, 1000) ?: null),
            'userid' => $userid, 'timemodified' => $now];
        $id = $DB->get_field(self::T, 'id', ['childid' => $childid, 'schoolyear' => $year]);
        if ($id) {
            $row->id = $id;
            $DB->update_record(self::T, $row);
        } else {
            $DB->insert_record(self::T, $row);
        }
        return true;
    }

    /**
     * What a family taps on its page. Without an answer, the one it had (a reason or a note on its own); the reason tapped
     * again is taken back. When the answer changes, a line in its history, which waits for a person if the child does not
     * go on; what they write, as a message that waits.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param int $childid
     * @param string $answer stay, leave, unsure or nothing.
     * @param string $reason
     * @param string|null $note Null when the page did not send it.
     * @param string $lang The language of the page.
     * @param int $now
     * @return bool
     */
    public static function from_family(
        \stdClass $centre,
        \stdClass $household,
        int $childid,
        string $answer,
        string $reason,
        ?string $note,
        string $lang,
        int $now
    ): bool {
        // Only while the centre asks: a page left open after the last day changes nothing.
        $asked = self::open($centre, $now) ? (self::children($centre, (int) $household->id)[$childid] ?? null) : null;
        if (!$asked) {
            return false;
        }
        $current = self::answers($centre, (int) $household->id)[$childid] ?? null;
        $was = $current ? (string) $current->answer : '';
        $answer = $answer !== '' ? $answer : $was;
        $oldreason = $was === 'leave' ? (string) $current->reason : '';
        if ($reason !== '' && $reason === $oldreason && $answer === $was) {
            $reason = '';
        } else if ($reason === '') {
            $reason = $oldreason;
        }
        $oldnote = (string) ($current->note ?? '');
        $note = $note === null ? $oldnote : \core_text::substr(trim($note), 0, 1000);
        if (!self::answer($centre, $household, $childid, $answer, $reason, $note, 0, $now)) {
            return false;
        }
        $a = (object) ['name' => familypage::label_name($asked[0]) ?: trim((string) $asked[0]->firstname),
            'year' => (string) $centre->renewalyear, 'course' => format_string($asked[2]->name)];
        if ($answer !== $was) {
            households::received(
                $household,
                lists::PAGE,
                publiclang::str('pub_renewal_said_' . $answer, $lang, $a),
                $answer === 'leave',
                $now
            );
        }
        if ($answer !== 'stay' && $note !== '' && $note !== $oldnote) {
            households::received($household, lists::PAGE, $note, true, $now, 0, (string) $centre->renewalyear);
        }
        return true;
    }

    /**
     * What a person of the team notes for a child, because the family said it in person or by phone: the same three
     * answers, and why for one that does not go on (the reason tapped again is taken back). A note stays in the history
     * when the answer changes; what the family wrote on its page is kept.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param int $childid
     * @param string $answer stay, leave, unsure or nothing (a reason on its own).
     * @param string $reason
     * @param int $userid
     * @param int $now
     * @return bool
     */
    public static function from_team(
        \stdClass $centre,
        \stdClass $household,
        int $childid,
        string $answer,
        string $reason,
        int $userid,
        int $now
    ): bool {
        $asked = self::children($centre, (int) $household->id)[$childid] ?? null;
        if (!$asked) {
            return false;
        }
        $current = self::answers($centre, (int) $household->id)[$childid] ?? null;
        $was = $current ? (string) $current->answer : '';
        $answer = $answer !== '' ? $answer : $was;
        $oldreason = $was === 'leave' ? (string) $current->reason : '';
        if ($reason !== '' && $reason === $oldreason && $answer === $was) {
            $reason = '';
        } else if ($reason === '') {
            $reason = $oldreason;
        }
        if (!self::answer($centre, $household, $childid, $answer, $reason, (string) ($current->note ?? ''), $userid, $now)) {
            return false;
        }
        if ($answer !== $was) {
            households::note($household, $userid, get_string('renewal_noted_' . $answer, 'local_oksigeniaeguahe', (object) [
                'name' => panel::tag($asked[0]), 'year' => (string) $centre->renewalyear]));
        }
        return true;
    }

    /**
     * The re-enrolment course by course of the year now: who goes on, who does not, who does not know and who has not
     * answered, with their families.
     *
     * @param \stdClass $centre
     * @return array[] offer id => [offer (now), next (offer), stay, leave, unsure, none: lists of [child, household, row]].
     */
    public static function by_course(\stdClass $centre): array {
        global $DB;
        $answers = self::answers($centre);
        $out = [];
        foreach (self::children($centre) as $childid => [$child, $offer, $next]) {
            $key = (int) $offer->id;
            $out[$key] = $out[$key] ?? ['offer' => $offer, 'next' => $next, 'stay' => [], 'leave' => [], 'unsure' => [],
                'none' => []];
            $row = $answers[$childid] ?? null;
            $out[$key][$row ? $row->answer : 'none'][] = [$child, (int) $child->householdid, $row];
        }
        uasort($out, fn($a, $b) => [(int) $a['offer']->sortorder, (int) $a['offer']->id] <=> [(int) $b['offer']->sortorder,
            (int) $b['offer']->id]);
        return $out;
    }

    /**
     * The signs that a family may leave: facts, each with its day, never a mark. That it will not go on (and why, if it
     * said), that it does not know yet, that it has not answered while the centre asks, that it has not opened its page
     * for some weeks while the centre asks, and that a child has left during the course.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array[] kind (leave, unsure, none, quiet, left), text and time (its day).
     */
    public static function signs(\stdClass $household, \stdClass $centre, int $now): array {
        global $DB;
        $c = 'local_oksigeniaeguahe';
        $out = [];
        $children = self::children($centre, (int) $household->id);
        $answers = self::answers($centre, (int) $household->id);
        $date = fn(int $t) => userdate($t, get_string('strftimedate', 'langconfig'));
        foreach ($children as $childid => [$child]) {
            $name = panel::tag($child);
            $row = $answers[$childid] ?? null;
            if ($row && $row->answer === 'leave') {
                $out[] = ['kind' => 'leave', 'text' => get_string(
                    $row->reason ? 'renewal_sign_leave_why' : 'renewal_sign_leave',
                    $c,
                    (object) ['name' => $name, 'year' => $centre->renewalyear, 'day' => $date((int) $row->timemodified),
                        'why' => $row->reason ? get_string('left_' . $row->reason, $c) : '']
                ), 'time' => (int) $row->timemodified];
            } else if ($row && $row->answer === 'unsure') {
                $out[] = ['kind' => 'unsure', 'text' => get_string('renewal_sign_unsure', $c, (object) ['name' => $name,
                    'year' => $centre->renewalyear, 'day' => $date((int) $row->timemodified)]), 'time' => (int) $row->timemodified];
            } else if (!$row && self::open($centre, $now)) {
                $out[] = ['kind' => 'none', 'text' => get_string('renewal_sign_none', $c, (object) ['name' => $name,
                    'year' => $centre->renewalyear, 'day' => $date((int) $centre->renewalfrom)]),
                    'time' => (int) $centre->renewalfrom];
            }
        }
        if ($children && self::open($centre, $now)) {
            $last = (int) $DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {' . seen::T . '} WHERE householdid = ?',
                [$household->id]
            );
            if ($now - max($last, (int) $centre->renewalfrom) >= self::QUIET) {
                $out[] = ['kind' => 'quiet', 'text' => $last ? get_string('renewal_sign_quiet', $c, $date($last)) :
                    get_string('renewal_sign_never', $c), 'time' => $last];
            }
        }
        foreach ($DB->get_records_select(households::CHILDREN, 'householdid = ? AND lefttime > 0', [$household->id]) as $gone) {
            $out[] = ['kind' => 'left', 'text' => get_string('renewal_sign_left', $c, (object) ['name' => panel::tag($gone),
                'day' => $date((int) $gone->lefttime)]), 'time' => (int) $gone->lefttime];
        }
        return $out;
    }

    /**
     * The families with a child not answered yet that let the centre email them and were not written to for the school
     * year asked about.
     *
     * @param \stdClass $centre
     * @return int[]
     */
    public static function to_tell(\stdClass $centre): array {
        global $DB;
        $year = trim((string) ($centre->renewalyear ?? ''));
        if ($year === '') {
            return [];
        }
        $answers = self::answers($centre);
        // Who was written to already, and who lets the centre write by email: two queries, not three per child.
        $told = array_flip(array_map('intval', $DB->get_fieldset_select(
            households::CONTACTS,
            'DISTINCT householdid',
            'centreid = ? AND detail = ?',
            [$centre->id, self::TOLD . $year]
        )));
        $emailable = households::emailable((int) $centre->id);
        $out = [];
        foreach (self::children($centre) as $childid => [$child]) {
            $householdid = (int) $child->householdid;
            if (!isset($answers[$childid]) && !isset($told[$householdid]) && isset($emailable[$householdid])) {
                $out[$householdid] = $householdid;
            }
        }
        return array_values($out);
    }

    /**
     * Tells by email, once for the school year, the families asked about that have an email: their page, where they
     * answer. A line stays in the history of each one.
     *
     * @param \stdClass $centre
     * @param int $userid Who sends it.
     * @param int $now
     * @return int How many families were written to.
     */
    public static function tell(\stdClass $centre, int $userid, int $now): int {
        global $DB;
        $year = trim((string) ($centre->renewalyear ?? ''));
        if ($year === '') {
            return 0;
        }
        $sent = 0;
        foreach (self::to_tell($centre) as $householdid) {
            $household = households::get($householdid, (int) $centre->id);
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            $token = households::new_link($household, 'renewal');
            if (familymail::send('renewal', $household, $centre, null, $lang, $token)) {
                $DB->insert_record(households::CONTACTS, (object) ['centreid' => $centre->id, 'householdid' => $householdid,
                    'direction' => 'out', 'channel' => 'email', 'body' => get_string(
                        'renewal_told_line',
                        'local_oksigeniaeguahe',
                        $year
                    ), 'detail' => self::TOLD . $year, 'userid' => $userid, 'timecreated' => $now]);
                // Sent to many at once: it does not keep the data of a family that never answers any longer.
                $sent++;
            }
        }
        return $sent;
    }
}
