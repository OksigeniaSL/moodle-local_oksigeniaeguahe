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
 * The same family written down twice: the mother asks for a visit on one side and the father on the other, each with
 * their own email and phone, so nothing joins them. A child with the same name and the same birthday in two households
 * of a centre, or the same name asking for the same course, says they may be one. A person decides: «They are the same
 * family» puts them together in the older one; «No, they are different» is kept and never asked again. Never joined on
 * its own.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lookalike {
    /** @var string Two households a person said are not the same family. */
    const T = 'local_oksigeniaeguahe_notsame';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string What came from the other household when two were put together, to separate them again. */
    const LOG = 'local_oksigeniaeguahe_mergelog';

    /** @var string[] What a household has that goes with it as a whole, by its householdid, when two are put together. */
    const MOVED = [households::CONSENTS, households::CONTACTS, households::LINKS, visits::REQUESTS, forms::ANSWERS,
        attend::PROMISES, surveys::T, seen::T, hobbies::T, siblings::T, invites::T, entries::ARRIVALS, roster::T, events::T,
        renewal::T, listmatch::T, teamnotes::T, alumni::T];

    /** @var int Two families asking for the same course look alike only if they came within this time of each other. */
    const NEAR = 180 * DAYSECS;

    /**
     * The households of its centre that may be this same family, each with the child that says so.
     *
     * @param \stdClass $household
     * @return array householdid => [child (its name), why (birthdate or course), birthdate]
     */
    public static function of(\stdClass $household): array {
        global $DB;
        $mine = $DB->get_records(
            households::CHILDREN,
            ['householdid' => $household->id],
            'id ASC',
            'id, firstname, birthdate, offerid, stage'
        );
        foreach ($mine as $child) {
            $child->roster = (int) ($household->roster ?? 0);
        }
        $names = [];
        foreach ($mine as $child) {
            $plain = grades::plain((string) $child->firstname);
            if ($plain !== '') {
                $names[$plain][] = $child;
            }
        }
        if (!$names) {
            return [];
        }
        $births = array_values(array_filter(array_unique(array_map(fn($k) => trim((string) $k->birthdate), $mine))));
        $offers = array_values(array_filter(array_unique(array_map(fn($k) => (int) $k->offerid, $mine))));
        $where = [];
        $params = ['centreid' => $household->centreid, 'me' => $household->id];
        if ($births) {
            [$in, $p] = $DB->get_in_or_equal($births, SQL_PARAMS_NAMED, 'b');
            $where[] = "c.birthdate $in";
            $params += $p;
        }
        if ($offers) {
            [$in, $p] = $DB->get_in_or_equal($offers, SQL_PARAMS_NAMED, 'o');
            $where[] = "c.offerid $in";
            $params += $p;
        }
        if (!$where) {
            return [];
        }
        $others = $DB->get_records_sql(
            'SELECT c.id, c.householdid, c.firstname, c.birthdate, c.offerid, c.stage, h.roster, h.timecreated
               FROM {' . households::CHILDREN . '} c
               JOIN {' . households::T . '} h ON h.id = c.householdid
              WHERE c.centreid = :centreid AND c.householdid <> :me AND (' . implode(' OR ', $where) . ')
           ORDER BY h.timecreated ASC, c.id ASC',
            $params
        );
        $apart = self::apart((int) $household->centreid, (int) $household->id);
        $out = [];
        foreach ($others as $other) {
            $id = (int) $other->householdid;
            if (isset($out[$id]) || isset($apart[$id])) {
                continue;
            }
            foreach ($names[grades::plain((string) $other->firstname)] ?? [] as $child) {
                $why = self::why($child, $other, abs((int) $household->timecreated - (int) $other->timecreated));
                if ($why !== '') {
                    $out[$id] = ['child' => (string) $child->firstname, 'why' => $why,
                        'birthdate' => trim((string) $child->birthdate)];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Why two children of the same name may be the same child: the same birthday, or the same course asked for by two
     * families that came near in time. Two different birthdays are two children, and two children already at the
     * centre (enrolled, or brought from its roll) with the same name in the same course are classmates.
     *
     * @param \stdClass $one
     * @param \stdClass $other
     * @param int $apart How far apart in time their households came.
     * @return string birthdate, course or '' when they are not.
     */
    public static function why(\stdClass $one, \stdClass $other, int $apart): string {
        $a = trim((string) $one->birthdate);
        $b = trim((string) $other->birthdate);
        if ($a !== '' && $a === $b) {
            return 'birthdate';
        }
        if ($a !== '' && $b !== '') {
            return '';
        }
        $settled = fn($k) => ($k->stage ?? '') === 'enrolled' || !empty($k->roster);
        if ($settled($one) || $settled($other)) {
            return '';
        }
        return (int) $one->offerid && (int) $one->offerid === (int) $other->offerid && $apart <= self::NEAR ? 'course' : '';
    }

    /**
     * The households a person said are not the same family as this one.
     *
     * @param int $centreid
     * @param int $householdid
     * @return array householdid => true
     */
    protected static function apart(int $centreid, int $householdid): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records_select(
            self::T,
            'centreid = :centreid AND (householda = :a OR householdb = :b)',
            ['centreid' => $centreid, 'a' => $householdid, 'b' => $householdid],
            '',
            'id, householda, householdb'
        );
        foreach ($rows as $row) {
            $out[(int) $row->householda === $householdid ? (int) $row->householdb : (int) $row->householda] = true;
        }
        return $out;
    }

    /**
     * A person says two households are different families: it is not asked again.
     *
     * @param int $centreid
     * @param int $one
     * @param int $other
     * @param int $userid
     */
    public static function distinct(int $centreid, int $one, int $other, int $userid): void {
        global $DB;
        [$a, $b] = [min($one, $other), max($one, $other)];
        if ($a === $b || $DB->record_exists(self::T, ['centreid' => $centreid, 'householda' => $a, 'householdb' => $b])) {
            return;
        }
        $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'householda' => $a, 'householdb' => $b,
            'userid' => $userid, 'timecreated' => time()]);
    }

    /**
     * The households that may be written down twice, for the inbox: pairs where at least one came since a time.
     *
     * @param int $centreid
     * @param int $since
     * @return array[] [newer householdid, older householdid], the newest first.
     */
    public static function pairs(int $centreid, int $since): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT c.id, c.householdid, c.firstname, c.birthdate, c.offerid, c.stage, h.roster, h.timecreated
               FROM {' . households::CHILDREN . '} c
               JOIN {' . households::T . '} h ON h.id = c.householdid
              WHERE c.centreid = :centreid
           ORDER BY h.timecreated DESC, h.id DESC, c.id ASC',
            ['centreid' => $centreid]
        );
        $byname = [];
        foreach ($rows as $row) {
            $plain = grades::plain((string) $row->firstname);
            if ($plain !== '') {
                $byname[$plain][] = $row;
            }
        }
        $apart = [];
        foreach ($DB->get_records(self::T, ['centreid' => $centreid], '', 'id, householda, householdb') as $row) {
            $apart[$row->householda . ':' . $row->householdb] = true;
        }
        $pairs = [];
        foreach ($byname as $children) {
            foreach ($children as $i => $one) {
                if ((int) $one->timecreated < $since) {
                    continue;
                }
                foreach (array_slice($children, $i + 1) as $other) {
                    [$a, $b] = [(int) $one->householdid, (int) $other->householdid];
                    $key = min($a, $b) . ':' . max($a, $b);
                    if ($a === $b || isset($apart[$key]) || isset($pairs[$key])) {
                        continue;
                    }
                    if (self::why($one, $other, abs((int) $one->timecreated - (int) $other->timecreated)) !== '') {
                        $pairs[$key] = [$a, $b];
                    }
                }
            }
        }
        return array_values($pairs);
    }

    /**
     * Puts two households of the same family together in the older one: its adults, its children (the same child
     * once), what was said, the permissions, what it answered, what it was promised and one sign-up for each act,
     * the largest. The links of both keep opening its page. The other is gone, and its history says so.
     *
     * @param \stdClass $keep
     * @param \stdClass $gone
     * @param int $userid
     * @return \stdClass The household that stays.
     */
    public static function merge(\stdClass $keep, \stdClass $gone, int $userid): \stdClass {
        global $DB;
        if ((int) $keep->centreid !== (int) $gone->centreid || (int) $keep->id === (int) $gone->id) {
            throw new \coding_exception('Only two households of the same centre can be put together.');
        }
        if ((int) $gone->timecreated < (int) $keep->timecreated) {
            [$keep, $gone] = [$gone, $keep];
        }
        $k = (int) $keep->id;
        $g = (int) $gone->id;
        $transaction = $DB->start_delegated_transaction();
        $before = households::full($gone);
        // What comes from the other household, to separate them again if it was a mistake.
        $keepevents = $DB->get_records_menu(households::SIGNUPS, ['householdid' => $k], '', 'eventid, id');
        $gonesignups = $DB->get_records(households::SIGNUPS, ['householdid' => $g]);
        $adults = self::merge_adults($k, $g);
        $children = self::merge_children($k, $g);
        self::merge_signups($k, $g, $children);
        $log = ['household' => array_diff_key((array) $gone, ['id' => 1]), 'tables' => [],
            'adults' => array_values(array_diff(array_keys($before->adults), array_keys($adults))),
            'children' => array_values(array_diff(array_keys($before->children), array_keys($children))),
            'samechildren' => [], 'signups' => [], 'joinedsignups' => []];
        foreach ($children as $from => $to) {
            $log['samechildren'][] = ['into' => $to] + array_diff_key((array) $before->children[$from], ['id' => 1]);
        }
        foreach ($gonesignups as $row) {
            if (isset($keepevents[$row->eventid])) {
                $log['joinedsignups'][] = array_diff_key((array) $row, ['id' => 1]);
            } else {
                $log['signups'][] = (int) $row->id;
            }
        }
        // Answers of a survey: one each, the one already there.
        $have = [];
        foreach ($DB->get_records(surveys::T, ['householdid' => $k], '', 'id, eventid, fieldid') as $row) {
            $have[$row->eventid . ':' . $row->fieldid] = true;
        }
        foreach ($DB->get_records(surveys::T, ['householdid' => $g], '', 'id, eventid, fieldid') as $row) {
            if (isset($have[$row->eventid . ':' . $row->fieldid])) {
                $DB->delete_records(surveys::T, ['id' => $row->id]);
            }
        }
        foreach (self::MOVED as $table) {
            $log['tables'][$table] = array_map('intval', $DB->get_fieldset_select($table, 'id', 'householdid = ?', [$g]));
            $DB->set_field($table, 'householdid', $k, ['householdid' => $g]);
        }
        foreach ($adults as $from => $to) {
            $DB->set_field(households::CONSENTS, 'adultid', $to, ['adultid' => $from]);
            $DB->set_field(hobbies::T, 'adultid', $to, ['adultid' => $from]);
            $DB->set_field(alumni::T, 'adultid', $to, ['householdid' => $k, 'adultid' => $from]);
        }
        // What the household says of itself: the older one keeps what it has, and takes what only the other knew.
        $changes = ['id' => $k, 'timemodified' => time(),
            'lastcontact' => max((int) $keep->lastcontact, (int) $gone->lastcontact)];
        $waiting = array_filter([(int) $keep->waitingsince, (int) $gone->waitingsince]);
        $changes['waitingsince'] = $waiting ? min($waiting) : 0;
        foreach (
            ['municipality', 'municipalityname', 'heardfrom', 'heardwho', 'heardnet', 'invitesign', 'whyother',
                'ownerid', 'familylink', 'roster'] as $field
        ) {
            if (empty($keep->$field) && !empty($gone->$field)) {
                $changes[$field] = $gone->$field;
                if ($field === 'whyother') {
                    $changes['whyothertime'] = $gone->whyothertime;
                }
            }
        }
        if (arrival::of($keep)[0] === '' && arrival::of($gone)[0] !== '') {
            foreach (['arrivalsource', 'arrivalmedium', 'arrivalcampaign', 'arrivalref'] as $field) {
                $changes[$field] = $gone->$field;
            }
        }
        foreach (['incentre', 'moving', 'self', 'volunteer'] as $field) {
            if (empty($keep->$field) && !empty($gone->$field)) {
                $changes[$field] = $gone->$field;
            }
        }
        foreach (['thanked', 'applysent'] as $field) {
            $changes[$field] = max((int) $keep->$field, (int) $gone->$field);
        }
        // The summary of the AI said one half of the family: written again when someone asks.
        $changes['aisummary'] = null;
        $changes['aisummarytime'] = 0;
        $DB->update_record(households::T, (object) $changes);
        $DB->delete_records_select(self::T, 'householda = :a OR householdb = :b', ['a' => $g, 'b' => $g]);
        attend::forget_family($gone);
        if (class_exists('\\local_oksigeniamarandu\\api') && method_exists('\\local_oksigeniamarandu\\api', 'unlink')) {
            // The phones joined with the other one join again from the page of the family.
            try {
                \local_oksigeniamarandu\api::unlink('local_oksigeniaeguahe', $g);
            } catch (\Throwable $e) {
                debugging('Eguahe: Marandu did not unlink household ' . $g . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $DB->delete_records(households::T, ['id' => $g]);
        $DB->insert_record(self::LOG, (object) ['centreid' => $keep->centreid, 'householdid' => $k,
            'data' => json_encode($log), 'userid' => $userid, 'timecreated' => time()]);
        $kept = households::get($k, (int) $keep->centreid);
        households::note($kept, $userid, get_string('lookalike_merged_note', self::C, (object) [
            'adults' => panel::adults_line($before->adults) ?: '—',
            'date' => userdate((int) $gone->timecreated, get_string('strftimedate', 'langconfig')),
        ]));
        $transaction->allow_commit();
        return households::get($k, (int) $keep->centreid);
    }

    /**
     * The adults of the other household come to this one; the same person (the same email or phone) only once.
     *
     * @param int $keep
     * @param int $gone
     * @return array The adults that were the same: their id in the other household => their id in this one.
     */
    protected static function merge_adults(int $keep, int $gone): array {
        global $DB;
        $here = $DB->get_records(households::ADULTS, ['householdid' => $keep], 'id ASC');
        $same = [];
        foreach ($DB->get_records(households::ADULTS, ['householdid' => $gone], 'id ASC') as $adult) {
            $match = null;
            foreach ($here as $mine) {
                if (
                    (!empty($adult->email) && $adult->email === $mine->email) ||
                    (!empty($adult->phone) && $adult->phone === $mine->phone)
                ) {
                    $match = $mine;
                    break;
                }
            }
            if ($match) {
                $fill = [];
                foreach (['email', 'phone', 'lastname'] as $field) {
                    if (empty($match->$field) && !empty($adult->$field)) {
                        $fill[$field] = $adult->$field;
                    }
                }
                if ($fill) {
                    $DB->update_record(households::ADULTS, (object) (['id' => $match->id] + $fill));
                }
                $same[(int) $adult->id] = (int) $match->id;
                $DB->delete_records(households::ADULTS, ['id' => $adult->id]);
            } else {
                $DB->set_field(households::ADULTS, 'householdid', $keep, ['id' => $adult->id]);
            }
        }
        return $same;
    }

    /**
     * The children of the other household come to this one; the same child (the same name, and the same birthday when
     * both have one) only once, with what only the other knew of it and the furthest step either reached.
     *
     * @param int $keep
     * @param int $gone
     * @return array The children that were the same: their id in the other household => their id in this one.
     */
    protected static function merge_children(int $keep, int $gone): array {
        global $DB;
        $here = $DB->get_records(households::CHILDREN, ['householdid' => $keep], 'id ASC');
        $same = [];
        foreach ($DB->get_records(households::CHILDREN, ['householdid' => $gone], 'id ASC') as $child) {
            $match = null;
            foreach ($here as $mine) {
                $a = trim((string) $mine->birthdate);
                $b = trim((string) $child->birthdate);
                if (
                    grades::plain((string) $mine->firstname) === grades::plain((string) $child->firstname) &&
                    ($a === '' || $b === '' || $a === $b) && !in_array((int) $mine->id, $same, true)
                ) {
                    $match = $mine;
                    break;
                }
            }
            if (!$match) {
                $DB->set_field(households::CHILDREN, 'householdid', $keep, ['id' => $child->id]);
                continue;
            }
            $fill = ['id' => $match->id, 'timemodified' => time()];
            foreach (['birthdate', 'tagname', 'latinname', 'familychild', 'startyear'] as $field) {
                if (empty($match->$field) && !empty($child->$field)) {
                    $fill[$field] = $child->$field;
                }
            }
            if (!(int) $match->offerid && (int) $child->offerid) {
                $fill['offerid'] = $child->offerid;
            }
            // The furthest step either reached, a closed way giving way to an open one.
            $step = fn($k) => $k->stage === 'closed' ? 0 : (lists::STAGE_STEP[$k->stage] ?? 1);
            if ($step($child) > $step($match)) {
                foreach (['stage', 'stagetime', 'closedreason', 'started'] as $field) {
                    $fill[$field] = $child->$field;
                }
            }
            $DB->update_record(households::CHILDREN, (object) $fill);
            // Its answer about next year, unless this one has it for the same year.
            foreach ($DB->get_records(renewal::T, ['childid' => $child->id]) as $row) {
                if ($DB->record_exists(renewal::T, ['childid' => $match->id, 'schoolyear' => $row->schoolyear])) {
                    $DB->delete_records(renewal::T, ['id' => $row->id]);
                } else {
                    $DB->update_record(renewal::T, (object) ['id' => $row->id, 'childid' => $match->id,
                        'householdid' => $keep]);
                }
            }
            self::move_drawing((int) $child->id, (int) $match->id);
            $DB->delete_records(households::CHILDREN, ['id' => $child->id]);
            $same[(int) $child->id] = (int) $match->id;
        }
        return $same;
    }

    /**
     * One sign-up for each act: the one already there, with the most people of the two, and coming if either came.
     *
     * @param int $keep
     * @param int $gone
     * @param array $children The children that were the same, from merge_children().
     */
    protected static function merge_signups(int $keep, int $gone, array $children): void {
        global $DB;
        $here = [];
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $keep]) as $row) {
            $here[(int) $row->eventid] = $row;
        }
        $renumber = function (?string $was) use ($children): ?string {
            if ($was === null || $was === '') {
                return $was;
            }
            $pairs = [];
            foreach (explode(',', $was) as $pair) {
                [$id, $stage] = array_pad(explode(':', $pair, 2), 2, '');
                $pairs[] = ($children[(int) $id] ?? (int) $id) . ':' . $stage;
            }
            return implode(',', $pairs);
        };
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $gone]) as $row) {
            $mine = $here[(int) $row->eventid] ?? null;
            if (!$mine) {
                $DB->update_record(households::SIGNUPS, (object) ['id' => $row->id, 'householdid' => $keep,
                    'wasstages' => $renumber($row->wasstages)]);
                continue;
            }
            $DB->update_record(households::SIGNUPS, (object) ['id' => $mine->id,
                'people' => max((int) $mine->people, (int) $row->people),
                'attended' => max((int) $mine->attended, (int) $row->attended),
                'thanked' => max((int) $mine->thanked, (int) $row->thanked),
                'reminded' => max((int) $mine->reminded, (int) $row->reminded),
                'slotid' => (int) $mine->slotid ?: (int) $row->slotid,
                'wasstages' => $mine->wasstages ?: $renumber($row->wasstages)]);
            $DB->delete_records(households::SIGNUPS, ['id' => $row->id]);
        }
    }

    /**
     * The drawing of a child written down twice goes to the one that stays, unless it already has one.
     *
     * @param int $from
     * @param int $to
     */
    protected static function move_drawing(int $from, int $to): void {
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        foreach ([drawings::AREA, drawings::STROKES] as $area) {
            if ($fs->get_area_files($contextid, self::C, $area, $to, 'id', false)) {
                continue;
            }
            foreach ($fs->get_area_files($contextid, self::C, $area, $from, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['itemid' => $to], $file);
            }
        }
        drawings::delete($from);
    }

    /**
     * What the file of a household shows: the families that may be this one, with what says so and the two answers.
     *
     * @param \stdClass $household
     * @param bool $canact
     * @return array|false
     */
    public static function context(\stdClass $household, bool $canact) {
        $found = self::of($household);
        if (!$found) {
            return false;
        }
        $c = self::C;
        $action = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false);
        $out = [];
        foreach ($found as $id => $why) {
            $other = households::get((int) $id, (int) $household->centreid);
            if (!$other) {
                continue;
            }
            $full = households::full($other);
            $who = panel::adults_line($full->adults) ?: get_string('panel_no_name', $c);
            $reason = $why['why'] === 'birthdate' ?
                get_string('lookalike_why_birthdate', $c, (object) ['child' => format_string($why['child']),
                    'date' => userdate((int) strtotime($why['birthdate'] . ' 12:00'), get_string('strftimedate', 'langconfig'))]) :
                get_string('lookalike_why_course', $c, format_string($why['child']));
            $older = (int) $other->timecreated <= (int) $household->timecreated;
            $out[] = [
                'id' => (int) $id,
                'line' => get_string('lookalike_line', $c, (object) ['who' => $who, 'since' => userdate(
                    (int) $other->timecreated,
                    get_string('strftimedateshort', 'langconfig')
                )]),
                'reason' => $reason,
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false),
                'what' => get_string($older ? 'lookalike_merge_into_other' : 'lookalike_merge_into_this', $c, $who),
            ];
        }
        return $out ? ['others' => $out, 'canact' => $canact, 'action' => $action, 'sesskey' => sesskey()] : false;
    }

    /**
     * Separates a household in two, when it was two families: the adults chosen go to a new one with their permissions
     * and what they like; each child stays, goes or is in both; each sign-up to an act stays, goes or is in both; the
     * lines of the conversation chosen go too. Coming from two households put together (its log), the new one is the
     * other as it was: when it came, how it heard of the centre, where it arrived from, its links and what it had.
     * Without a log, the links sent since the first adult that goes arrived stop working: both families may have them.
     *
     * @param \stdClass $household
     * @param array $choice adults (ids that go), children (id => go or both), signups (event id => go or both),
     *     contacts (ids that go) and log (the id of the log of a merge, or 0).
     * @param int $userid
     * @return \stdClass The new household.
     */
    public static function split(\stdClass $household, array $choice, int $userid): \stdClass {
        global $DB;
        $full = households::full($household);
        $h = (int) $household->id;
        $centreid = (int) $household->centreid;
        $going = array_values(array_intersect(array_map('intval', $choice['adults'] ?? []), array_keys($full->adults)));
        if (!$going || count($going) >= count($full->adults)) {
            throw new \moodle_exception('lookalike_split_adults_error', self::C);
        }
        $logrow = !empty($choice['log']) ? $DB->get_record(self::LOG, ['id' => (int) $choice['log'], 'householdid' => $h]) : null;
        $log = $logrow ? (array) json_decode((string) $logrow->data, true) : [];
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $first = min(array_map(fn($id) => (int) $full->adults[$id]->timecreated ?: $now, $going));
        $was = (array) ($log['household'] ?? []);
        $id = households::create($centreid, (object) ['lang' => $was['lang'] ?? $household->lang,
            'firstchannel' => $was['firstchannel'] ?? $household->firstchannel]);
        $fields = ['id' => $id, 'timecreated' => (int) ($was['timecreated'] ?? $first), 'timemodified' => $now];
        foreach (
            ['municipality', 'municipalityname', 'moving', 'self', 'heardfrom', 'heardwho', 'heardnet', 'incentre', 'volunteer',
                'thanked', 'invitesign', 'familylink', 'roster', 'whyother', 'whyothertime', 'arrivalsource', 'arrivalmedium',
                'arrivalcampaign', 'arrivalref', 'applysent', 'ownerid'] as $field
        ) {
            if (array_key_exists($field, $was)) {
                $fields[$field] = $was[$field];
            }
        }
        $DB->update_record(households::T, (object) $fields);

        // The adults, with their permissions and what they like.
        [$in, $params] = $DB->get_in_or_equal($going);
        $DB->set_field_select(households::ADULTS, 'householdid', $id, "id $in", $params);
        $DB->set_field_select(households::CONSENTS, 'householdid', $id, "adultid $in", $params);
        $DB->set_field_select(hobbies::T, 'householdid', $id, "adultid $in", $params);
        $DB->set_field_select(alumni::T, 'householdid', $id, "adultid $in", $params);

        // The children: each stays, goes, or is in both families (a child written down twice, again in each).
        $same = [];
        foreach ((array) ($log['samechildren'] ?? []) as $row) {
            $same[(int) $row['into']] = $row;
        }
        $children = 0;
        foreach ((array) ($choice['children'] ?? []) as $childid => $how) {
            $child = $full->children[(int) $childid] ?? null;
            if (!$child || !in_array($how, ['go', 'both'], true)) {
                continue;
            }
            $children++;
            if ($how === 'go') {
                $DB->set_field(households::CHILDREN, 'householdid', $id, ['id' => $child->id]);
                $DB->set_field(renewal::T, 'householdid', $id, ['childid' => $child->id]);
                continue;
            }
            $copy = isset($same[(int) $child->id]) ? (object) array_diff_key($same[(int) $child->id], ['into' => 1]) :
                (object) array_diff_key((array) $child, ['id' => 1]);
            $copy->householdid = $id;
            $copy->centreid = $centreid;
            $copy->familychild = 0;
            $copy->timemodified = $now;
            $newchild = (int) $DB->insert_record(households::CHILDREN, $copy);
            self::copy_drawing((int) $child->id, $newchild);
        }

        // The sign-ups: each stays, goes, or is in both, with the people of each family.
        $joined = [];
        foreach ((array) ($log['joinedsignups'] ?? []) as $row) {
            $joined[(int) $row['eventid']] = $row;
        }
        $people = max(1, count($going) + $children);
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $h]) as $signup) {
            $how = $choice['signups'][(int) $signup->eventid] ?? '';
            if ($how === 'go') {
                $DB->set_field(households::SIGNUPS, 'householdid', $id, ['id' => $signup->id]);
            } else if ($how === 'both') {
                $copy = isset($joined[(int) $signup->eventid]) ? (object) $joined[(int) $signup->eventid] :
                    (object) array_diff_key((array) $signup, ['id' => 1]);
                $copy->householdid = $id;
                $copy->people = isset($joined[(int) $signup->eventid]) ? (int) $copy->people : $people;
                $copy->wasstages = null;
                $DB->insert_record(households::SIGNUPS, $copy);
                if (!isset($joined[(int) $signup->eventid])) {
                    $DB->set_field(households::SIGNUPS, 'people', max(1, (int) $signup->people - $people), ['id' => $signup->id]);
                }
            }
        }

        // The lines of the conversation chosen.
        $lines = array_map('intval', (array) ($choice['contacts'] ?? []));
        if ($lines) {
            [$in, $params] = $DB->get_in_or_equal($lines, SQL_PARAMS_NAMED);
            $DB->set_field_select(households::CONTACTS, 'householdid', $id, "householdid = :h AND id $in", ['h' => $h] + $params);
        }

        // What the other household had, from the log: its links, requests, answers, promises and the rest.
        if ($log) {
            foreach ((array) ($log['tables'] ?? []) as $table => $ids) {
                if (
                    !in_array($table, self::MOVED, true) || !$ids ||
                        in_array($table, [households::CONSENTS, hobbies::T, households::CONTACTS, renewal::T, alumni::T], true)
                ) {
                    continue;
                }
                [$in, $params] = $DB->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED);
                $DB->set_field_select($table, 'householdid', $id, "householdid = :h AND id $in", ['h' => $h] + $params);
            }
            $DB->delete_records(self::LOG, ['id' => $logrow->id]);
        } else {
            // Both families may have the links sent since the first that goes arrived: they stop working.
            $DB->delete_records_select(households::LINKS, 'householdid = :h AND timecreated >= :t', ['h' => $h, 't' => $first]);
        }
        foreach ([$h, $id] as $one) {
            self::reckon($one);
        }
        self::distinct($centreid, $h, $id, $userid);
        $new = households::get($id, $centreid);
        $stayed = households::get($h, $centreid);
        $newfull = households::full($new);
        households::note($stayed, $userid, get_string('lookalike_split_note', self::C, panel::adults_line($newfull->adults)));
        households::note($new, $userid, get_string(
            'lookalike_split_note_new',
            self::C,
            panel::adults_line(households::full($stayed)->adults)
        ));
        $transaction->allow_commit();
        return households::get($id, $centreid);
    }

    /**
     * Whether a household waits for an answer and when it was last in touch, from what its conversation says now.
     *
     * @param int $householdid
     */
    protected static function reckon(int $householdid): void {
        global $DB;
        $lines = $DB->get_records_select(
            households::CONTACTS,
            "householdid = ? AND direction <> 'note'",
            [$householdid],
            'timecreated ASC, id ASC',
            'id, direction, channel, timecreated'
        );
        $waiting = 0;
        $last = 0;
        foreach ($lines as $line) {
            $last = max($last, (int) $line->timecreated);
            if ($line->direction === 'out') {
                $waiting = 0;
            } else if ($line->direction === 'in' && $line->channel !== 'page' && !$waiting) {
                $waiting = (int) $line->timecreated;
            }
        }
        $changes = ['id' => $householdid, 'waitingsince' => $waiting];
        if ($last) {
            $changes['lastcontact'] = $last;
        }
        $DB->update_record(households::T, (object) $changes);
    }

    /**
     * The drawing of a child that is now in two families, in both.
     *
     * @param int $from
     * @param int $to
     */
    protected static function copy_drawing(int $from, int $to): void {
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        foreach ([drawings::AREA, drawings::STROKES] as $area) {
            foreach ($fs->get_area_files($contextid, self::C, $area, $from, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['itemid' => $to], $file);
            }
        }
    }

    /**
     * What the file of a household shows to separate it: its adults, children, sign-ups and the last lines of its
     * conversation, marked as they came when it comes from two households put together.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param bool $canact
     * @return array|false
     */
    public static function split_context(\stdClass $household, \stdClass $full, bool $canact) {
        global $DB;
        if (!$canact || count($full->adults) < 2) {
            return false;
        }
        $c = self::C;
        $logs = $DB->get_records(self::LOG, ['householdid' => $household->id], 'timecreated DESC', '*', 0, 1);
        $logrow = reset($logs) ?: null;
        $log = $logrow ? (array) json_decode((string) $logrow->data, true) : [];
        $goadults = array_map('intval', (array) ($log['adults'] ?? []));
        $gochildren = array_map('intval', (array) ($log['children'] ?? []));
        $bothchildren = array_map(fn($r) => (int) $r['into'], (array) ($log['samechildren'] ?? []));
        $gosignups = array_map('intval', (array) ($log['signups'] ?? []));
        $bothevents = array_map(fn($r) => (int) $r['eventid'], (array) ($log['joinedsignups'] ?? []));
        $golines = array_map('intval', (array) ($log['tables'][households::CONTACTS] ?? []));
        $options = function (string $now) use ($c): array {
            return array_map(fn($v) => ['value' => $v, 'label' => get_string('lookalike_split_' . $v, $c),
                'selected' => $v === $now], ['stay', 'go', 'both']);
        };
        $adults = [];
        foreach ($full->adults as $adult) {
            $adults[] = ['id' => (int) $adult->id, 'name' => trim($adult->firstname . ' ' . $adult->lastname),
                'contact' => (string) ($adult->email ?: $adult->phone), 'checked' => in_array((int) $adult->id, $goadults, true)];
        }
        $children = [];
        foreach ($full->children as $child) {
            $now = in_array((int) $child->id, $gochildren, true) ? 'go' :
                (in_array((int) $child->id, $bothchildren, true) ? 'both' : 'stay');
            $children[] = ['id' => (int) $child->id, 'name' => panel::tag($child), 'options' => $options($now)];
        }
        $signups = [];
        foreach ($DB->get_records(households::SIGNUPS, ['householdid' => $household->id]) as $signup) {
            $event = $DB->get_record(events::T, ['id' => $signup->eventid], 'id, name, timestart');
            if (!$event) {
                continue;
            }
            $now = in_array((int) $signup->id, $gosignups, true) ? 'go' :
                (in_array((int) $signup->eventid, $bothevents, true) ? 'both' : 'stay');
            $signups[] = ['eventid' => (int) $event->id, 'name' => format_string($event->name) . ' · ' .
                userdate((int) $event->timestart, get_string('strftimedateshort', 'langconfig')), 'options' => $options($now)];
        }
        $lines = [];
        $rows = $DB->get_records_select(
            households::CONTACTS,
            "householdid = ? AND direction <> 'note'",
            [$household->id],
            'timecreated DESC, id DESC',
            'id, direction, body, timecreated',
            0,
            40
        );
        foreach ($rows as $row) {
            $lines[] = ['id' => (int) $row->id, 'checked' => in_array((int) $row->id, $golines, true),
                'when' => userdate((int) $row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'text' => shorten_text(trim(preg_replace('/\s+/u', ' ', (string) $row->body)), 90)];
        }
        $from = '';
        if ($logrow) {
            $names = array_map(fn($a) => $a['name'], array_filter($adults, fn($a) => $a['checked']));
            $from = get_string('lookalike_split_from_log', $c, (object) ['who' => implode(', ', $names) ?: '—',
                'date' => userdate((int) $logrow->timecreated, get_string('strftimedate', 'langconfig'))]);
        }
        return ['adults' => $adults, 'children' => $children, 'haschildren' => (bool) $children, 'signups' => $signups,
            'hassignups' => (bool) $signups, 'lines' => $lines, 'haslines' => (bool) $lines, 'fromlog' => $from,
            'logid' => $logrow ? (int) $logrow->id : 0, 'nolog' => !$logrow,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey()];
    }
}
