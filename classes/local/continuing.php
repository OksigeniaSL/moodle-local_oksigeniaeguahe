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
 * A continuing student: a child already at the centre (in its nursery, or a student of another stage) that asks for the
 * next course. It is not admitted, it goes on: its place is kept for it from the moment it is marked so, and the only thing
 * to know is whether it goes on, which its family says and a person of the team notes. Saying yes is all the formality;
 * the lists of the centre move it to its new class when the school year changes. Never a new student attracted by a
 * campaign, nor one lost by it, whatever open day its family came to.
 *
 * It is marked by a person (what ties a child to the centre) or, when there is no doubt, by the list of the centre: a child
 * of the list in a course whose next one, the school year after, is the course the family asks for.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class continuing {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] What a family can say: it goes on, they do not know yet, it does not. */
    const ANSWERS = ['stay', 'unsure', 'leave'];

    /** @var string[] The steps from which its place is kept: those of asking for one. */
    const ASKING = ['inquiry', 'visit', 'application', 'waitlist'];

    /** @var string[] What answer takes it to which step. */
    const STAGE = ['stay' => 'enrolled', 'unsure' => 'admitted', 'leave' => 'closed'];

    /** @var string[] Why it does not go on, when it is also a reason to close a way. */
    const CLOSING = ['moved' => 'moved', 'othercentre' => 'othercentre', 'cost' => 'cost'];

    /**
     * What makes a child a continuing one: already in the nursery, or already a student; empty, it is not.
     *
     * @param int $childid
     * @return string nursery, student or nothing.
     */
    public static function kind(int $childid): string {
        global $DB;
        if (!$childid) {
            return '';
        }
        $kinds = $DB->get_fieldset_select(ties::T, 'kind', "childid = ? AND kind IN ('nursery', 'student')", [$childid]);
        return in_array('nursery', $kinds, true) ? 'nursery' : ($kinds ? 'student' : '');
    }

    /**
     * The continuing children of a centre that still ask for nothing but to go on: neither enrolled nor gone.
     *
     * @param int $centreid
     * @return array household id => child ids
     */
    public static function families(int $centreid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.householdid
               FROM {" . households::CHILDREN . "} c
               JOIN {" . ties::T . "} t ON t.childid = c.id AND t.kind IN ('nursery', 'student')
               JOIN {" . households::T . "} h ON h.id = c.householdid
              WHERE c.centreid = :centreid AND c.roster = 0 AND h.guest = 0
                    AND c.stage <> 'closed' AND (c.stage <> 'enrolled' OR c.contanswer = 'stay')",
            ['centreid' => $centreid]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->householdid][] = (int) $row->id;
        }
        return $out;
    }

    /**
     * The places kept for continuing children who have not said yet, by course: they are taken until they say no.
     *
     * @param int $centreid
     * @return array offer id => how many
     */
    public static function reserved(int $centreid): array {
        global $DB;
        return array_map('intval', $DB->get_records_sql_menu(
            "SELECT c.offerid, COUNT(DISTINCT c.id)
               FROM {" . households::CHILDREN . "} c
               JOIN {" . ties::T . "} t ON t.childid = c.id AND t.kind IN ('nursery', 'student')
               JOIN {" . households::T . "} h ON h.id = c.householdid
              WHERE c.centreid = :centreid AND c.roster = 0 AND h.guest = 0 AND c.offerid > 0
                    AND c.stage NOT IN ('enrolled', 'closed')
           GROUP BY c.offerid",
            ['centreid' => $centreid]
        ));
    }

    /**
     * The continuing children of a centre whose place is kept and who have not said they do not go on.
     *
     * @param int $centreid
     * @return \stdClass[]
     */
    protected static function waiting(int $centreid): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.householdid, c.offerid
               FROM {" . households::CHILDREN . "} c
               JOIN {" . ties::T . "} t ON t.childid = c.id AND t.kind IN ('nursery', 'student')
              WHERE c.centreid = :centreid AND c.roster = 0 AND c.offerid > 0 AND c.stage = 'admitted'
                    AND COALESCE(c.contanswer, '') <> 'leave'",
            ['centreid' => $centreid]
        );
    }

    /**
     * Where a continuing child is, in a few words, for a line: its place kept, it goes on, or it does not.
     *
     * @param string $stage
     * @return string
     */
    public static function word(string $stage): string {
        return get_string('cont_word_' . (['enrolled' => 'stay', 'closed' => 'leave'][$stage] ?? 'kept'), self::C);
    }

    /**
     * Marked as continuing: one asking for a place has it kept, and the step it had is kept to go back to.
     *
     * @param int $childid
     * @return bool Whether its step changed.
     */
    public static function reserve(int $childid): bool {
        global $DB;
        $child = $DB->get_record(households::CHILDREN, ['id' => $childid]);
        if (!$child || (int) $child->roster || !in_array((string) $child->stage, self::ASKING, true)) {
            return false;
        }
        if (trim((string) ($child->contstage ?? '')) === '') {
            $DB->set_field(households::CHILDREN, 'contstage', $child->stage, ['id' => $child->id]);
        }
        households::set_stage($child, 'admitted');
        return true;
    }

    /**
     * No longer marked as continuing: back to the step it had, unless the list of the centre already enrolled it; what was
     * said of going on goes, and the list does not mark it again by itself.
     *
     * @param int $childid
     * @return bool Whether its step changed.
     */
    public static function release(int $childid): bool {
        global $DB;
        $child = $DB->get_record(households::CHILDREN, ['id' => $childid]);
        if (!$child) {
            return false;
        }
        $was = trim((string) ($child->contstage ?? ''));
        $DB->update_record(households::CHILDREN, (object) ['id' => $child->id, 'contstage' => null, 'contanswer' => null,
            'contreason' => null, 'contnote' => null, 'contby' => 0, 'conttime' => 0, 'contlist' => time()]);
        $bylist = $DB->record_exists(listmatch::T, ['childid' => $child->id, 'kind' => 'enrolled']);
        if ($was === '' || !in_array($was, lists::STAGES, true) || $was === (string) $child->stage || $bylist) {
            return false;
        }
        households::set_stage($child, $was);
        return true;
    }

    /**
     * What the family said of going on, as a person of the team notes it: yes is enrolled (saying it is all the
     * formality), not yet keeps its place, no closes its way with why. A line in its history says it.
     *
     * @param \stdClass $household
     * @param int $childid
     * @param string $answer stay, unsure or leave.
     * @param string $reason Why it does not go on, of settled::LEFT_REASONS.
     * @param string $note
     * @param int $userid
     * @param int $now
     * @return bool
     */
    public static function answer(
        \stdClass $household,
        int $childid,
        string $answer,
        string $reason,
        string $note,
        int $userid,
        int $now = 0
    ): bool {
        global $DB;
        $now = $now ?: time();
        $child = $DB->get_record(households::CHILDREN, ['id' => $childid, 'householdid' => $household->id]);
        if (!$child || !in_array($answer, self::ANSWERS, true) || self::kind($childid) === '') {
            return false;
        }
        // «No sigue» touched again keeps why and what they said; a chip or the line change only theirs.
        if ($answer === 'leave' && $reason === '' && (string) ($child->contanswer ?? '') === 'leave') {
            $reason = (string) ($child->contreason ?? '');
            $note = $note !== '' ? $note : (string) ($child->contnote ?? '');
        }
        $reason = $answer === 'leave' && in_array($reason, settled::LEFT_REASONS, true) ? $reason : ($answer === 'leave' ?
            'unknown' : '');
        $note = $answer === 'stay' ? '' : \core_text::substr(trim($note), 0, 255);
        $same = (string) ($child->contanswer ?? '') === $answer && (string) ($child->contreason ?? '') === $reason &&
            (string) ($child->contnote ?? '') === $note;
        if ($same) {
            return false;
        }
        $stage = self::STAGE[$answer];
        $DB->update_record(households::CHILDREN, (object) ['id' => $child->id,
            'contstage' => trim((string) ($child->contstage ?? '')) !== '' ? $child->contstage : $child->stage,
            'contanswer' => $answer, 'contreason' => $reason ?: null, 'contnote' => $note !== '' ? $note : null,
            'contby' => $userid, 'conttime' => $now]);
        if ((string) $child->stage !== $stage) {
            households::set_stage($child, $stage, $answer === 'leave' ? (self::CLOSING[$reason] ?? 'other') : null);
        }
        $centre = centres::get((int) $household->centreid);
        $lang = (string) ($centre->lang ?? '') ?: current_language();
        $offer = $child->offerid ? $DB->get_record(centres::OFFERS, ['id' => $child->offerid], 'id, name, schoolyear') : null;
        $a = (object) ['name' => panel::called($child), 'course' => $offer ? format_string($offer->name) : '',
            'why' => ''];
        $strings = get_string_manager();
        $a->why = $reason !== '' ? $strings->get_string('left_' . $reason, self::C, null, $lang) : '';
        $line = $strings->get_string('cont_noted_' . $answer . ($offer ? '' : '_nocourse'), self::C, $a, $lang);
        if ($note !== '' && $answer !== 'stay') {
            $line .= ' ' . $strings->get_string('cont_noted_note', self::C, $note, $lang);
        }
        households::note($household, $userid, $line);
        return true;
    }

    /**
     * What the file shows of a continuing child, in place of the way of asking for a place.
     *
     * @param \stdClass $child
     * @param string $kind nursery or student.
     * @param \stdClass|null $offer The course it goes on to.
     * @param bool $canact
     * @return array
     */
    public static function context(\stdClass $child, string $kind, ?\stdClass $offer, bool $canact): array {
        global $DB;
        $c = self::C;
        $answer = (string) ($child->contanswer ?? '');
        // What it is now, by its step: the list or a person may have moved it after.
        $answer = ['enrolled' => 'stay', 'closed' => 'leave'][(string) $child->stage] ?? ($answer === 'unsure' ? 'unsure' : '');
        // The state first, and the course without its year, which the line above already says.
        $state = $offer ? get_string('cont_state_' . ($answer ?: 'none'), $c, format_string($offer->name)) :
            get_string('cont_state_' . ($answer ?: 'none') . '_nocourse', $c);
        if ($answer === 'leave' && trim((string) ($child->contreason ?? '')) !== '') {
            $state .= ' · ' . get_string('left_' . $child->contreason, $c);
        }
        // Who said it and when; or that the list marked it, while nobody has said anything.
        $said = '';
        // The year only when it is not this one.
        $day = fn(int $t) => userdate($t, get_string(
            userdate($t, '%Y', 99, false) === userdate(time(), '%Y', 99, false) ? 'strftimedateshort' : 'strftimedate',
            'langconfig'
        ));
        if ((int) ($child->conttime ?? 0)) {
            $by = (int) $child->contby ? $DB->get_record('user', ['id' => (int) $child->contby, 'deleted' => 0]) : null;
            $said = $by ? get_string('cont_said_by', $c, (object) ['who' => fullname($by), 'day' => $day((int) $child->conttime)]) :
                get_string((int) $child->contby ? 'cont_said_on' : 'cont_said_list', $c, $day((int) $child->conttime));
        } else if ((int) ($child->contlist ?? 0) && $DB->record_exists(ties::T, ['childid' => $child->id, 'userid' => 0])) {
            $said = get_string('cont_said_list', $c, $day((int) $child->contlist));
        }
        $reasons = [];
        foreach (settled::LEFT_REASONS as $reason) {
            $reasons[] = ['value' => $reason, 'name' => panel::upper_first(get_string('left_' . $reason, $c)),
                'selected' => (string) ($child->contreason ?? '') === $reason];
        }
        return [
            'from' => get_string('cont_from_' . $kind, $c),
            'state' => $state,
            'ask' => $offer ? get_string('cont_ask', $c, format_string($offer->name)) : get_string('cont_ask_nocourse', $c),
            'stay' => $answer === 'stay',
            'unsure' => $answer === 'unsure',
            'leave' => $answer === 'leave',
            'note' => (string) ($child->contnote ?? ''),
            // Why, as it is kept: what the line keeps when it is changed alone.
            'reason' => trim((string) ($child->contreason ?? '')) ?: 'unknown',
            'said' => $said,
            'reasons' => $reasons,
            'canact' => $canact,
        ];
    }

    /**
     * Each child marked as continuing that is still asking for a place has it kept: after a join of two files, after the
     * update that brings this, or whatever moved it back.
     *
     * @param int $centreid 0, every centre.
     * @return int How many changed.
     */
    public static function sync_all(int $centreid = 0): int {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::ASKING, SQL_PARAMS_NAMED);
        $where = $centreid ? ' AND c.centreid = :centreid' : '';
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT c.id
               FROM {" . households::CHILDREN . "} c
               JOIN {" . ties::T . "} t ON t.childid = c.id AND t.kind IN ('nursery', 'student')
              WHERE c.roster = 0 AND c.stage $in" . $where,
            $params + ($centreid ? ['centreid' => $centreid] : [])
        );
        $n = 0;
        foreach ($ids as $id) {
            $n += self::reserve((int) $id) ? 1 : 0;
        }
        return $n;
    }

    /**
     * What the lists of the centre say by themselves: a family with a child in a course of the list (this school year)
     * that asks, for the school year after, for the course of the next age is a continuing child. Only when there is no
     * doubt: as many children asking as students of that age in the list, or fewer. Never one a person took away.
     *
     * @param \stdClass $centre
     * @param bool $run Whether to mark them; if not, only what it would do.
     * @param int $now
     * @return array marked (child ids), doubt (household ids where it could be but is not clear), confirmed (child ids the
     *     list of the new school year already has in the course they asked for: they go on).
     */
    public static function from_lists(\stdClass $centre, bool $run, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $out = ['marked' => [], 'doubt' => [], 'confirmed' => []];
        if (!empty($centre->practice)) {
            return $out;
        }
        $rows = $DB->get_records_sql(
            "SELECT m.id, m.householdid, m.student, m.grade, m.schoolyear
               FROM {" . listmatch::T . "} m
               JOIN {" . households::T . "} h ON h.id = m.householdid
              WHERE m.centreid = :centreid AND m.kind = 'incentre' AND h.guest = 0 AND h.roster = 0",
            ['centreid' => $centre->id]
        );
        $lists = [];
        $there = [];
        foreach ($rows as $row) {
            $age = grades::age((string) $row->grade, $centre);
            $next = renewal::year_after((string) $row->schoolyear);
            if ($age !== null && $next !== '') {
                $lists[(int) $row->householdid][$next . '|' . ($age + 1)][(string) $row->student] = $age;
                $there[(int) $row->householdid][(string) $row->schoolyear . '|' . $age] = true;
            }
        }
        if (!$lists) {
            return $out;
        }
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name, schoolyear');
        $ages = [];
        foreach ($offers as $offer) {
            $ages[$offer->id] = grades::age((string) $offer->name, $centre);
        }
        // Those already continuing that the list of their new school year has in the course they asked for: they went on,
        // whoever had not said it yet. Never one whose family said no.
        foreach (self::waiting((int) $centre->id) as $child) {
            $offer = $offers[$child->offerid] ?? null;
            $age = $offer ? ($ages[$offer->id] ?? null) : null;
            if ($age === null || !isset($there[(int) $child->householdid][(string) $offer->schoolyear . '|' . $age])) {
                continue;
            }
            $out['confirmed'][] = (int) $child->id;
            $household = $run ? households::get((int) $child->householdid, 0) : null;
            if ($household) {
                self::answer($household, (int) $child->id, 'stay', '', '', 0, $now);
            }
        }
        foreach ($lists as $hid => $wanted) {
            [$in, $params] = $DB->get_in_or_equal(self::ASKING, SQL_PARAMS_NAMED);
            $children = $DB->get_records_select(
                households::CHILDREN,
                "householdid = :hid AND roster = 0 AND contlist = 0 AND offerid > 0 AND stage $in",
                ['hid' => $hid] + $params,
                'id ASC'
            );
            $asking = [];
            foreach ($children as $child) {
                $offer = $offers[$child->offerid] ?? null;
                $age = $offer ? ($ages[$offer->id] ?? null) : null;
                if ($age !== null && self::kind((int) $child->id) === '') {
                    $asking[(string) $offer->schoolyear . '|' . $age][] = $child;
                }
            }
            foreach ($wanted as $key => $students) {
                $these = $asking[$key] ?? [];
                if (!$these) {
                    continue;
                }
                if (count($these) > count($students)) {
                    $out['doubt'][$hid] = $hid;
                    continue;
                }
                $kind = max($students) <= who::NURSERY_AGE ? 'nursery' : 'student';
                foreach ($these as $child) {
                    $out['marked'][] = (int) $child->id;
                    if ($run) {
                        $household = households::get($hid, 0);
                        if ($household) {
                            ties::set($household, (int) $child->id, $kind, true, 0);
                            $DB->set_field(households::CHILDREN, 'contlist', $now, ['id' => $child->id]);
                        }
                    }
                }
            }
        }
        $out['doubt'] = array_values($out['doubt']);
        return $out;
    }
}
