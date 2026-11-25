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
 * The appointments of the families, wherever they come from: the visits the team gives, the acts they signed up to and the
 * visits a sheet of the team says. Each with its state, said by a person: whether they came, they did not, or nobody said
 * it. Given, moved and called off from here, telling the family or not.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class appointments {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string What was said of each, to take it back. */
    const T = 'local_oksigeniaeguahe_apptmark';

    /** @var string[] What a person says of one that has passed. */
    const STATES = ['came', 'notcame', 'unknown'];

    /** @var int Two of a family closer than this may be the same, in days. */
    const NEAR = 7;

    /**
     * The appointments of a family, from the first to the last; what it asked for and has no day yet, at the end.
     *
     * @param \stdClass $household
     * @param int $now
     * @return array[] As agenda() gives them.
     */
    public static function of(\stdClass $household, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $out = self::rows((int) $household->centreid, 's.householdid = :one', ['one' => (int) $household->id], $now);
        foreach (
            $DB->get_records_select(
                visits::REQUESTS,
                "householdid = ? AND status = 'pending'",
                [(int) $household->id],
                'timecreated ASC'
            ) as $request
        ) {
            $out[] = self::item(['id' => 'r' . $request->id, 'householdid' => (int) $household->id, 'kind' => 'visit',
                'source' => 'eguahe', 'state' => 'asked', 'eventid' => (int) $request->eventid, 'people' => (int) $request->people]);
        }
        return $out;
    }

    /**
     * What the families of a centre asked for and has no day yet.
     *
     * @param \stdClass $centre
     * @return array[] As agenda() gives them, with asked (when).
     */
    public static function asked(\stdClass $centre): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(visits::REQUESTS, ['centreid' => $centre->id, 'status' => 'pending'], 'timecreated ASC') as $request) {
            $out[] = self::item(['id' => 'r' . $request->id, 'householdid' => (int) $request->householdid, 'kind' => 'visit',
                'source' => 'eguahe', 'state' => 'asked', 'eventid' => (int) $request->eventid, 'people' => (int) $request->people,
                'asked' => (int) $request->timecreated, 'suits' => visits::suits($request, current_language())]);
        }
        return $out;
    }

    /**
     * The appointments of a centre between two moments.
     *
     * @param \stdClass $centre
     * @param int $from
     * @param int $to Not included.
     * @param int $now
     * @return array[] id (s of a sign-up, n of a note of a sheet, r of something asked), householdid, start, end, dayonly, kind
     *     (visit, act or note), source (eguahe, sheet or hand), state (asked, coming, came, notcame, unknown or off), guide
     *     (its name), guideid, title, place, eventid, contactid and people.
     */
    public static function agenda(\stdClass $centre, int $from, int $to, int $now = 0): array {
        return self::rows((int) $centre->id, 'x.start >= :from AND x.start < :to', ['from' => $from, 'to' => $to], $now ?: time());
    }

    /**
     * One, by its id.
     *
     * @param int $centreid
     * @param string $id
     * @param int $now
     * @return array|null
     */
    public static function one(int $centreid, string $id, int $now = 0): ?array {
        $kind = substr($id, 0, 1);
        $number = (int) substr($id, 1);
        if (!in_array($kind, ['s', 'n'], true) || !$number) {
            return null;
        }
        $rows = self::rows($centreid, 's.id = :number', ['number' => $number], $now ?: time(), [$kind]);
        return $rows[0] ?? null;
    }

    /**
     * The rows, from the sign-ups and from the sheets.
     *
     * @param int $centreid
     * @param string $where On s (the sign-up or the note) and x.start.
     * @param array $params
     * @param int $now
     * @param string[] $kinds s, n or both.
     * @return array[]
     */
    protected static function rows(int $centreid, string $where, array $params, int $now, array $kinds = ['s', 'n']): array {
        global $DB;
        $out = [];
        $hosts = [];
        foreach (centres::members($centreid) as $user) {
            $hosts[(int) $user->memberid] = fullname($user);
        }
        $params['centreid'] = $centreid;
        if (in_array('s', $kinds, true)) {
            $start = 'COALESCE(t.timestart, e.timestart)';
            $rows = $DB->get_records_sql(
                "SELECT s.id, s.householdid, s.eventid, s.people, s.attended, s.missed, e.name, e.kind, e.place, e.hosts, e.cancelled,
                        e.householdid AS own, $start AS timestart, COALESCE(t.timeend, e.timeend) AS timeend
                   FROM {local_oksigeniaeguahe_signup} s
                   JOIN {local_oksigeniaeguahe_event} e ON e.id = s.eventid
              LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
                  WHERE s.centreid = :centreid AND s." . households::NOTSAMPLE . " AND e.tailored = 0 AND " . str_replace('x.start', $start, $where) . "
               ORDER BY timestart ASC, s.id ASC",
                $params
            );
            foreach ($rows as $row) {
                $guide = (int) (array_values(array_filter(array_map('intval', explode(',', (string) $row->hosts))))[0] ?? 0);
                $out[] = self::item([
                    'id' => 's' . $row->id, 'householdid' => (int) $row->householdid, 'start' => (int) $row->timestart,
                    'end' => (int) $row->timeend, 'kind' => (int) $row->own ? 'visit' : 'act', 'source' => 'eguahe',
                    'state' => (int) $row->cancelled ? 'off' : self::state((int) $row->attended, (int) $row->missed, (int) $row->timeend, $now),
                    'guide' => $hosts[$guide] ?? '', 'guideid' => isset($hosts[$guide]) ? $guide : 0, 'title' => format_string($row->name),
                    'place' => format_string((string) $row->place), 'eventid' => (int) $row->eventid, 'people' => (int) $row->people,
                ]);
            }
        }
        if (in_array('n', $kinds, true)) {
            $rows = $DB->get_records_sql(
                "SELECT s.id, s.householdid, s.entryid, s.contactid, s.visit, s.dayonly, s.state
                   FROM {local_oksigeniaeguahe_teamnote} s
                  WHERE s.centreid = :centreid AND s." . households::NOTSAMPLE . " AND s.visit > 0 AND s.replacedby = 0 AND (s.state IS NULL OR s.state <> 'note') AND " .
                    str_replace('x.start', 's.visit', $where) . "
               ORDER BY s.visit ASC, s.id ASC",
                $params
            );
            foreach ($rows as $row) {
                $end = (int) $row->visit + ((int) $row->dayonly ? DAYSECS : HOURSECS);
                $state = (string) $row->state;
                $out[] = self::item([
                    'id' => 'n' . $row->id, 'householdid' => (int) $row->householdid, 'start' => (int) $row->visit, 'end' => $end,
                    'dayonly' => (bool) $row->dayonly, 'kind' => 'note', 'source' => (int) $row->entryid ? 'sheet' : 'hand',
                    'state' => $state !== '' ? $state : ($end > $now ? 'coming' : 'unknown'), 'contactid' => (int) $row->contactid,
                    'title' => get_string('appt_note_title', self::C),
                ]);
            }
        }
        usort($out, fn($a, $b) => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);
        return $out;
    }

    /**
     * An appointment with all its parts.
     *
     * @param array $parts
     * @return array
     */
    protected static function item(array $parts): array {
        return $parts + ['id' => '', 'householdid' => 0, 'start' => 0, 'end' => 0, 'dayonly' => false, 'kind' => 'visit', 'source' => 'eguahe',
            'state' => 'unknown', 'guide' => '', 'guideid' => 0, 'title' => '', 'place' => '', 'eventid' => 0, 'contactid' => 0, 'people' => 0];
    }

    /**
     * What is known of one: that they came, that they did not, that it is still to come or that nobody said it.
     *
     * @param int $came When it was said that they came; 0, nobody.
     * @param int $missed When it was said that they did not.
     * @param int $end
     * @param int $now
     * @return string
     */
    protected static function state(int $came, int $missed, int $end, int $now): string {
        return $came ? 'came' : ($missed ? 'notcame' : ($end > $now ? 'coming' : 'unknown'));
    }

    /**
     * What a person says of several at once: they came, they did not, or it is not known. Kept to be taken back.
     *
     * @param \stdClass $centre
     * @param string[] $ids
     * @param string $state One of STATES.
     * @param int $userid
     * @param int $now
     * @param bool $rest Only those nothing was said of yet: a whole day or act at once never changes what was said of one.
     * @return string The key of this time, for undo(); empty when nothing changed.
     */
    public static function mark(
        \stdClass $centre,
        array $ids,
        string $state,
        int $userid,
        int $now = 0,
        bool $rest = false
    ): string {
        global $DB;
        $now = $now ?: time();
        if (!in_array($state, self::STATES, true)) {
            return '';
        }
        $batch = random_string(12);
        $any = false;
        foreach (array_unique($ids) as $id) {
            $one = self::one((int) $centre->id, (string) $id, $now);
            // Nothing is said of what is still to come, nor of what was called off.
            if (!$one || $one['start'] > $now || in_array($one['state'], ['off', 'asked', $state], true)) {
                continue;
            }
            if ($rest && $one['state'] !== 'unknown') {
                continue;
            }
            $stages = self::put($one, $state, $now);
            $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'householdid' => $one['householdid'], 'batch' => $batch,
                'item' => $one['id'], 'was' => $one['state'], 'state' => $state, 'stages' => $stages === '' ? null : $stages,
                'userid' => $userid, 'timecreated' => $now]);
            $any = true;
        }
        return $any ? $batch : '';
    }

    /**
     * Takes back what was said in one time: each as it was.
     *
     * @param \stdClass $centre
     * @param string $batch
     * @param int $now
     * @return int How many.
     */
    public static function undo(\stdClass $centre, string $batch, int $now = 0): int {
        global $DB;
        $now = $now ?: time();
        $n = 0;
        foreach ($batch === 'apart' ? [] : $DB->get_records(self::T, ['centreid' => $centre->id, 'batch' => $batch], 'id DESC') as $row) {
            $one = self::one((int) $centre->id, (string) $row->item, $now);
            // Something else was said since: that one stays.
            if ($one && $one['state'] === $row->state) {
                // With the way each child had, unless it went on since or they came another time.
                self::put($one, in_array($row->was, self::STATES, true) ? (string) $row->was : 'unknown', $now);
                $n++;
            }
            $DB->delete_records(self::T, ['id' => $row->id]);
        }
        return $n;
    }

    /**
     * Keeps what was said of one, and moves the way of the children as coming always does.
     *
     * @param array $one
     * @param string $state
     * @param int $now
     * @return string The way each child had, as childid:stage separated by commas, of those a note moved; empty, none.
     */
    protected static function put(array $one, string $state, int $now): string {
        global $DB;
        $number = (int) substr($one['id'], 1);
        if ($one['id'][0] === 's') {
            $event = $DB->get_record(events::T, ['id' => $one['eventid']], '*', MUST_EXIST);
            $was = (int) $DB->get_field(events::SIGNUPS, 'attended', ['id' => $number]) > 0;
            if ($was !== ($state === 'came')) {
                events::attend($event, $number, $state === 'came', $now);
            }
            $DB->set_field(events::SIGNUPS, 'missed', $state === 'notcame' ? $now : 0, ['id' => $number]);
            return '';
        }
        $note = $DB->get_record(teamnotes::T, ['id' => $number], '*', MUST_EXIST);
        $DB->update_record(teamnotes::T, (object) ['id' => $number, 'state' => $state === 'unknown' ? null : $state, 'statetime' => $now]);
        self::promise_of($note, $state);
        $was = [];
        if ($state !== 'came' && (string) $note->state === 'came') {
            $marks = $DB->get_records(
                self::T,
                ['householdid' => $note->householdid, 'item' => $one['id'], 'state' => 'came'],
                'timecreated DESC, id DESC',
                'id, stages',
                0,
                1
            );
            self::not_come((int) $note->householdid, $one['id'], $marks ? (string) reset($marks)->stages : '');
        }
        if ($state === 'came') {
            foreach ($DB->get_records(households::CHILDREN, ['householdid' => $one['householdid']]) as $child) {
                if (lists::further((string) $child->stage, 'visit')) {
                    $was[] = (int) $child->id . ':' . $child->stage;
                    households::set_stage($child, 'visit');
                }
            }
        }
        return implode(',', $was);
    }

    /**
     * What the team promised with a visit of a sheet («Su visita, a las 8:44») is done once it is said whether they came; and is
     * open again when that is taken back.
     *
     * @param \stdClass $note
     * @param string $state
     */
    protected static function promise_of(\stdClass $note, string $state): void {
        global $DB;
        $household = households::get((int) $note->householdid, (int) $note->centreid);
        if (!$household) {
            return;
        }
        $bodies = [];
        foreach (array_keys(get_string_manager()->get_list_of_translations(true)) as $lang) {
            $bodies[] = teamnotes::visit_promise((int) $note->visit, true, $lang);
            $bodies[] = teamnotes::visit_promise((int) $note->visit, false, $lang);
        }
        if ($state !== 'unknown') {
            attend::withdraw($household, $bodies, (int) $note->visit);
            return;
        }
        foreach ($DB->get_records(attend::PROMISES, ['householdid' => $household->id, 'doneby' => 0], 'id ASC') as $promise) {
            if ((int) $promise->timedone && in_array((string) $promise->body, $bodies, true) && abs((int) $promise->dueday - (int) $note->visit) < DAYSECS) {
                $DB->set_field(attend::PROMISES, 'timedone', 0, ['id' => $promise->id]);
            }
        }
    }

    /**
     * It is taken back that a family came to one: each child that is still at the visit goes back to the step it had, unless
     * they came another time, which is what moved it then.
     *
     * @param int $householdid
     * @param string $item s and the sign-up, or n and the note.
     * @param string $stages The step each child had, as childid:stage, by commas.
     */
    public static function not_come(int $householdid, string $item, string $stages): void {
        global $DB;
        if ($stages === '') {
            return;
        }
        $number = (int) substr($item, 1);
        $other = $DB->get_records_sql(
            "SELECT s.id, s.wasstages
               FROM {local_oksigeniaeguahe_signup} s
               JOIN {local_oksigeniaeguahe_event} e ON e.id = s.eventid
              WHERE s.householdid = ? AND s.attended > 0 AND e.kind <> 'other' AND s.id <> ?
           ORDER BY s.attended ASC, s.id ASC",
            [$householdid, $item[0] === 's' ? $number : 0],
            0,
            1
        );
        if ($other) {
            $row = reset($other);
            $DB->set_field(events::SIGNUPS, 'wasstages', trim($row->wasstages . ',' . $stages, ','), ['id' => $row->id]);
            return;
        }
        $marks = $DB->get_records_sql(
            "SELECT m.id, m.stages
               FROM {" . self::T . "} m
               JOIN {" . teamnotes::T . "} n ON n.householdid = m.householdid AND n.state = 'came'
              WHERE m.householdid = ? AND m.state = 'came' AND n.id <> ? AND m.item = " . $DB->sql_concat("'n'", 'n.id') . "
           ORDER BY m.timecreated DESC, m.id DESC",
            [$householdid, $item[0] === 'n' ? $number : 0],
            0,
            1
        );
        if ($marks) {
            $row = reset($marks);
            $DB->set_field(self::T, 'stages', trim($row->stages . ',' . $stages, ','), ['id' => $row->id]);
            return;
        }
        foreach (array_filter(explode(',', $stages)) as $pair) {
            [$childid, $stage] = array_pad(explode(':', $pair, 2), 2, '');
            $child = $DB->get_record(households::CHILDREN, ['id' => (int) $childid, 'householdid' => $householdid]);
            if ($child && $child->stage === 'visit' && in_array($stage, lists::STAGES, true)) {
                households::set_stage($child, $stage);
            }
        }
    }

    /**
     * Gives a family a visit, whether it asked for it or not. It says the one it takes the place of, when there was one
     * still to come.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $start
     * @param int $minutes
     * @param int $guide
     * @param string $place
     * @param int $people
     * @param bool $tell Whether the family is told by the centre; no when it was settled on the phone.
     * @return array event, replaced (the one it takes the place of, as agenda() gives it, or null), sent, asked (whether it had
     *     asked for one) and again (whether it was already given just like this: nothing is done twice)
     */
    public static function give(
        \stdClass $household,
        \stdClass $centre,
        int $start,
        int $minutes,
        int $guide,
        string $place,
        int $people,
        bool $tell
    ): array {
        $replaced = null;
        foreach (self::of($household) as $one) {
            $replaced = $one['kind'] === 'visit' && $one['state'] === 'coming' && $one['start'] > time() ?
                $one : $replaced;
        }
        $asked = visits::pending((int) $household->id) !== null;
        if ($replaced && $replaced['start'] === $start && $replaced['end'] === $start + max(15, $minutes) * MINSECS) {
            $event = events::get((int) $replaced['eventid'], (int) $centre->id);
            if ((string) $event->hosts === ($guide ? (string) $guide : '') && trim((string) $event->place) === trim($place)) {
                // Sent twice (a second touch, the page loaded again): it is that one.
                return ['event' => $event, 'replaced' => null, 'sent' => false, 'asked' => $asked, 'again' => true];
            }
        }
        $event = visits::propose($household, $start, $minutes, $guide, $place, $people);
        self::receives($event, $guide);
        return ['event' => $event, 'replaced' => $replaced, 'sent' => $tell && self::send($household, $centre, $event, 'invitation'),
            'asked' => $asked, 'again' => false];
    }

    /**
     * Moves a visit of a family to another day, hour, place or guide.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $eventid
     * @param int $start
     * @param int $minutes
     * @param int $guide
     * @param string $place
     * @param bool $tell
     * @return bool|null Whether the family was told by mail; null when there is no such visit.
     */
    public static function move(
        \stdClass $household,
        \stdClass $centre,
        int $eventid,
        int $start,
        int $minutes,
        int $guide,
        string $place,
        bool $tell
    ): ?bool {
        global $DB;
        $event = $DB->get_record(events::T, ['id' => $eventid, 'householdid' => $household->id, 'cancelled' => 0]);
        if (!$event) {
            return null;
        }
        $old = panel_eventform::values($event);
        events::save((int) $centre->id, (object) ['id' => (int) $event->id, 'kind' => 'visit', 'name' => \core_text::substr(
            visits::name($household, $guide),
            0,
            255
        ), 'place' => $place, 'timestart' => $start, 'timeend' => $start + max(15, $minutes) * MINSECS, 'capacity' => 0, 'open' => 0, 'hosts' => [$guide]]);
        $DB->set_field(events::T, 'householdid', $household->id, ['id' => $event->id]);
        self::receives($event, $guide);
        // The reminder goes for the new day, and nothing said of the old one stands.
        $DB->set_field(events::SIGNUPS, 'reminded', 0, ['eventid' => $event->id, 'householdid' => $household->id]);
        $DB->set_field(events::SIGNUPS, 'missed', 0, ['eventid' => $event->id, 'householdid' => $household->id]);
        return $tell ? self::send($household, $centre, events::get((int) $event->id, (int) $centre->id), 'change', $old) : false;
    }

    /**
     * Who receives a family: anybody of the team. The families see only those the centre shows.
     *
     * @param \stdClass $event
     * @param int $guide The member of the team; 0, nobody yet.
     */
    protected static function receives(\stdClass $event, int $guide): void {
        global $DB;
        $ok = $guide && $DB->record_exists(centres::MEMBERS, ['id' => $guide, 'centreid' => $event->centreid]);
        $event->hosts = $ok ? (string) $guide : null;
        $DB->set_field(events::T, 'hosts', $event->hosts, ['id' => $event->id]);
    }

    /**
     * Calls off a visit of a family.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $eventid
     * @param string $message What the team adds.
     * @param int $userid
     * @param bool $tell
     * @param int $now
     * @return bool Whether there was such a visit.
     */
    public static function off(
        \stdClass $household,
        \stdClass $centre,
        int $eventid,
        string $message,
        int $userid,
        bool $tell,
        int $now = 0
    ): bool {
        global $DB;
        $now = $now ?: time();
        $event = $DB->get_record(events::T, ['id' => $eventid, 'householdid' => $household->id, 'cancelled' => 0]);
        if (!$event || (int) $event->timeend <= $now || $DB->record_exists_select(events::SIGNUPS, 'eventid = ? AND attended > 0', [$eventid])) {
            // It already happened.
            return false;
        }
        if ($tell) {
            events::cancel($event, $centre, $userid, $message, $now);
            return true;
        }
        $DB->delete_records(events::SIGNUPS, ['eventid' => $event->id]);
        messaging::withdraw((int) $household->id, ['confirmation', 'reminder', 'invitation', 'change']);
        $DB->update_record(events::T, (object) ['id' => $event->id, 'cancelled' => $now, 'open' => 0, 'timemodified' => $now]);
        visits::back_to_asking((int) $household->id, (int) $event->id);
        households::note($household, $userid, get_string('appt_off_note', self::C, panel::when_words((int) $event->timestart, $now)));
        return true;
    }

    /**
     * Tells a family its visit, or its change, by the ways it chose.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $event
     * @param string $kind invitation or change.
     * @param array|null $old The visit as it was, from panel_eventform::values(), for a change.
     * @return bool
     */
    protected static function send(
        \stdClass $household,
        \stdClass $centre,
        \stdClass $event,
        string $kind,
        ?array $old = null
    ): bool {
        global $USER;
        if ($kind === 'change') {
            return (bool) panel_eventform::tell($centre, $event, [(int) $household->id], (int) $USER->id, $old);
        }
        $signup = familymail::signup($household, (int) $event->id);
        if (!$signup) {
            return false;
        }
        ob_start();
        try {
            return familymail::send(
                $kind,
                $household,
                $centre,
                $signup,
                (string) ($household->lang ?: $centre->lang),
                households::new_link($household, $kind)
            );
        } catch (\Throwable $e) {
            return false;
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Who else a person of the team receives at an hour, in words; empty when nobody.
     *
     * @param \stdClass $centre
     * @param int $guide
     * @param int $start
     * @param int $end
     * @param int $but The visit that is being changed, which is not counted.
     * @return string
     */
    public static function taken(\stdClass $centre, int $guide, int $start, int $end, int $but = 0): string {
        if (!$guide) {
            return '';
        }
        $names = [];
        foreach (self::agenda($centre, $start - DAYSECS, $end) as $one) {
            if (
                $one['guideid'] !== $guide || $one['state'] === 'off' || $one['dayonly'] || $one['eventid'] === $but ||
                    $one['start'] >= $end || $one['end'] <= $start
            ) {
                continue;
            }
            $household = $one['kind'] === 'act' ? null : households::get($one['householdid'], (int) $centre->id);
            $names[$one['kind'] === 'act' ? $one['title'] : ($household ? panel::adults_line(households::full($household)->adults) : '')] = $one['guide'];
        }
        $names = array_filter($names, fn($guide, $name) => $name !== '', ARRAY_FILTER_USE_BOTH);
        return $names ? get_string('give_taken', self::C, (object) ['guide' => reset($names), 'who' => panel::and_list(array_keys($names))]) : '';
    }

    /**
     * Those of a family that may be one and the same: close in time and from different places (one given in Eguahe, another
     * written in a sheet), or two given in Eguahe and both to come (a family that was in two files). A person says it.
     *
     * @param \stdClass $household
     * @param int $now
     * @return array[] a and b (as agenda() gives them) and days between them.
     */
    public static function twins(\stdClass $household, int $now = 0): array {
        $all = array_values(array_filter(self::of($household, $now), fn($o) => $o['start'] && $o['kind'] !== 'act' && $o['state'] !== 'off'));
        $out = [];
        foreach ($all as $i => $a) {
            foreach (array_slice($all, $i + 1) as $b) {
                $days = abs(panel::days_between($a['start'], $b['start']));
                $two = $a['kind'] === 'visit' && $b['kind'] === 'visit' && $a['state'] === 'coming' && $b['state'] === 'coming';
                if (($two || ($a['source'] !== $b['source'] && $days <= self::NEAR)) && !self::are_apart($household, $a['id'], $b['id'])) {
                    $out[] = ['a' => $a, 'b' => $b, 'days' => $days];
                }
            }
        }
        return $out;
    }

    /**
     * A person says which of two is the appointment: the other stays as a note in the history, and is one no longer. Only
     * the note of a sheet can be left as a note; what was given in Eguahe is called off, without telling anybody.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $id The one that is.
     * @param string $other The one that is not.
     * @param int $userid
     * @param int $now
     * @return bool
     */
    public static function keep(\stdClass $household, \stdClass $centre, string $id, string $other, int $userid, int $now = 0): bool {
        global $DB;
        $now = $now ?: time();
        foreach (self::twins($household, $now) as $pair) {
            $ids = [$pair['a']['id'], $pair['b']['id']];
            if (!in_array($id, $ids, true) || !in_array($other, $ids, true) || $id === $other) {
                continue;
            }
            $gone = $pair['a']['id'] === $other ? $pair['a'] : $pair['b'];
            if ($gone['id'][0] === 'n') {
                $DB->update_record(teamnotes::T, (object) ['id' => (int) substr($gone['id'], 1), 'state' => 'note', 'statetime' => $now]);
                return true;
            }
            return self::off($household, $centre, $gone['eventid'], '', $userid, false, $now);
        }
        return false;
    }

    /**
     * A person says that two are two: they are not asked about again.
     *
     * @param \stdClass $household
     * @param string $id
     * @param string $other
     * @param int $userid
     * @param int $now
     * @return bool
     */
    public static function apart(\stdClass $household, string $id, string $other, int $userid, int $now = 0): bool {
        global $DB;
        foreach (self::twins($household, $now) as $pair) {
            $ids = [$pair['a']['id'], $pair['b']['id']];
            if (in_array($id, $ids, true) && in_array($other, $ids, true) && $id !== $other) {
                sort($ids);
                $DB->insert_record(self::T, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
                    'batch' => 'apart', 'item' => implode('-', $ids), 'was' => '', 'state' => 'apart', 'userid' => $userid,
                    'timecreated' => $now ?: time()]);
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a person said that two are two.
     *
     * @param \stdClass $household
     * @param string $id
     * @param string $other
     * @return bool
     */
    protected static function are_apart(\stdClass $household, string $id, string $other): bool {
        global $DB;
        $ids = [$id, $other];
        sort($ids);
        return $DB->record_exists(self::T, ['householdid' => $household->id, 'batch' => 'apart', 'item' => implode('-', $ids)]);
    }

    /**
     * A week of the team, from its Monday: each day, whether the centre is open and what there is in it.
     *
     * @param \stdClass $centre
     * @param int $monday Any moment of its first day.
     * @param int $now
     * @return array[] day (its start), open and items, as agenda() gives them.
     */
    public static function week(\stdClass $centre, int $monday, int $now = 0): array {
        $zone = \core_date::get_server_timezone_object();
        $first = (new \DateTimeImmutable('@' . $monday))->setTimezone($zone)->setTime(0, 0);
        return self::days($centre, $first->modify('-' . ((int) $first->format('N') - 1) . ' days'), 7, $now);
    }

    /**
     * Some days of the team in a row: each, whether the centre is open and what there is in it.
     *
     * @param \stdClass $centre
     * @param \DateTimeImmutable $first The start of the first day.
     * @param int $n
     * @param int $now
     * @return array[] day (its start), open and items, as agenda() gives them.
     */
    public static function days(\stdClass $centre, \DateTimeImmutable $first, int $n, int $now = 0): array {
        $items = self::agenda($centre, $first->getTimestamp(), $first->modify('+' . $n . ' days')->getTimestamp(), $now);
        $closed = workdays::closed($centre);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $day = $first->modify("+$i days");
            $next = $first->modify('+' . ($i + 1) . ' days')->getTimestamp();
            $out[] = ['day' => $day->getTimestamp(), 'open' => workdays::is_open($centre, $day, $closed),
                'items' => array_values(array_filter($items, fn($o) => $o['start'] >= $day->getTimestamp() && $o['start'] < $next))];
        }
        return $out;
    }

    /**
     * What was said of the appointments of a family goes with it.
     *
     * @param int $householdid
     */
    public static function forget(int $householdid): void {
        global $DB;
        $DB->delete_records(self::T, ['householdid' => $householdid]);
    }
}
