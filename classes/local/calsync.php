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
 * The calendar of the team in Google, both ways. The script the centre pastes once (teamcalendar::script) says every ten
 * minutes what there is in it and Eguahe answers what to do. What changes in Eguahe changes there. What a person moves or
 * deletes there is never undone nor applied by itself: Eguahe asks whoever looks at the agenda (move it, call it off, or
 * leave it as it was), because a finger that slips must not call off the visit of a family nor write to it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calsync {
    /** @var string What the calendar has of each act or visit, and what a person changed there. */
    const T = 'local_oksigeniaeguahe_calsync';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int How long without hearing from the script until the settings say so. */
    const LATE = 3 * HOURSECS;

    /** @var int How long after telling the script to write one it is not told again: it takes a while to be there. */
    const WRITING = 15 * MINSECS;

    /** @var int How many visits of families that erased their data wait to be taken out of the calendar, at most. */
    const ERASING = 300;

    /** @var int How many of them have to go at once to think that the calendar is another or was emptied. */
    const EMPTIED = 3;

    /**
     * What the centre chose, and what the script said last.
     *
     * @param \stdClass $centre
     * @return array calendar (its id; empty, not chosen yet), behind (ids), known (id => name), read (when), emptied (when) and
     *     erase (the uids of visits of families that erased their data)
     */
    public static function config(\stdClass $centre): array {
        $kept = json_decode((string) ($centre->calsync ?? ''), true);
        $kept = is_array($kept) ? $kept : [];
        return ['calendar' => (string) ($kept['calendar'] ?? ''), 'behind' => array_values(array_map('strval', (array) ($kept['behind'] ?? []))),
            'known' => array_map('strval', (array) ($kept['known'] ?? [])), 'read' => (int) ($kept['read'] ?? 0),
            'emptied' => (int) ($kept['emptied'] ?? 0), 'erase' => array_values(array_map('strval', (array) ($kept['erase'] ?? [])))];
    }

    /**
     * A family erased its data: its visits go out of the calendar, also those that went by.
     *
     * @param int $centreid
     * @param int[] $eventids
     */
    public static function erased(int $centreid, array $eventids): void {
        $centre = centres::get($centreid);
        $config = $centre ? self::config($centre) : null;
        if (!$eventids || !$config || $config['calendar'] === '') {
            return;
        }
        $uids = array_map(fn($id) => 'eguahe-' . $centreid . '-' . $id, $eventids);
        $config['erase'] = array_slice(array_values(array_unique(array_merge($config['erase'], $uids))), -self::ERASING);
        self::keep($centre, $config);
    }

    /**
     * That the script stopped calling, in a sentence for whoever looks at the week or gives an appointment: what the other
     * calendars say is of then.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return string Empty when it calls, or was never put.
     */
    public static function stopped(\stdClass $centre, int $now): string {
        $config = self::config($centre);
        if (!$config['read'] || $config['read'] >= $now - self::LATE || ($config['calendar'] === '' && !$config['behind'])) {
            return '';
        }
        return get_string('calsync_stopped', self::C, panel::when_words($config['read'], $now));
    }

    /**
     * Keeps it.
     *
     * @param \stdClass $centre Changed in place.
     * @param array $config
     */
    protected static function keep(\stdClass $centre, array $config): void {
        global $DB;
        $centre->calsync = json_encode($config, JSON_UNESCAPED_UNICODE);
        $DB->set_field(centres::T, 'calsync', $centre->calsync, ['id' => $centre->id]);
    }

    /**
     * Chooses the calendar the appointments go to. With another one, what was known of the one before is forgotten.
     *
     * @param \stdClass $centre
     * @param string $id One of the known ones; empty, none.
     */
    public static function choose(\stdClass $centre, string $id): void {
        global $DB;
        $config = self::config($centre);
        if ($id !== '' && !isset($config['known'][$id])) {
            return;
        }
        if ($id !== $config['calendar']) {
            $DB->delete_records(self::T, ['centreid' => $centre->id]);
            $config['emptied'] = 0;
        }
        $config['calendar'] = $id;
        $config['behind'] = array_values(array_diff($config['behind'], [$id]));
        self::keep($centre, $config);
    }

    /**
     * Shows a calendar behind, or stops.
     *
     * @param \stdClass $centre
     * @param string $id
     */
    public static function toggle_behind(\stdClass $centre, string $id): void {
        $config = self::config($centre);
        if (!isset($config['known'][$id]) || $id === $config['calendar']) {
            return;
        }
        $config['behind'] = in_array($id, $config['behind'], true) ? array_values(array_diff($config['behind'], [$id])) :
            array_merge($config['behind'], [$id]);
        self::keep($centre, $config);
    }

    /**
     * What the script says, and what it has to do.
     *
     * @param \stdClass $centre
     * @param array $said calendars (id and name of each), calendar (the one it looked at), events (uid, start and end of each
     *     one of ours in it), and behind (calendar, title, start, end and allday of everything else).
     * @param int $now
     * @return array now, from, to, calendar, behind (ids), erase (uids of long ago to take out) and ops: do (create, set, text,
     *     delete), uid, n (which of two with the same uid) and, but for delete, title, start, end, location and description.
     */
    public static function receive(\stdClass $centre, array $said, int $now): array {
        global $DB;
        // As it is now: a family may have erased its data while the script was on its way.
        $centre->calsync = $DB->get_field(centres::T, 'calsync', ['id' => $centre->id]);
        $config = self::config($centre);
        $known = [];
        foreach (array_slice((array) ($said['calendars'] ?? []), 0, 100) as $one) {
            $id = \core_text::substr(clean_param((string) ($one['id'] ?? ''), PARAM_TEXT), 0, 200);
            if ($id !== '') {
                $known[$id] = \core_text::substr(clean_param((string) ($one['name'] ?? ''), PARAM_TEXT), 0, 100);
            }
        }
        $config['known'] = $known ?: $config['known'];
        $config['read'] = $now;
        busy::replace((int) $centre->id, 'script', (array) ($said['behind'] ?? []), $now);
        $ops = [];
        if ($config['calendar'] !== '' && (string) ($said['calendar'] ?? '') === $config['calendar']) {
            [$ops, $config['emptied']] = self::compare($centre, (array) ($said['events'] ?? []), $config['emptied'], $now);
            $config['erase'] = array_values(array_diff($config['erase'], array_map('strval', array_filter(
                (array) ($said['erased'] ?? []),
                'is_scalar'
            ))));
        }
        self::keep($centre, $config);
        return ['now' => $now, 'from' => $now - teamcalendar::BEFORE, 'to' => $now + teamcalendar::AFTER,
            'calendar' => $config['calendar'], 'behind' => $config['behind'],
            'behindto' => $now + busy::AHEAD, 'erase' => $config['erase'], 'ops' => $ops];
    }

    /**
     * What is in the calendar against what there should be.
     *
     * @param \stdClass $centre
     * @param array $events
     * @param int $emptied When it was found empty; 0, it was not.
     * @param int $now
     * @return array [ops, emptied]
     */
    protected static function compare(\stdClass $centre, array $events, int $emptied, int $now): array {
        global $DB;
        $there = [];
        $twice = [];
        foreach ($events as $i => $one) {
            if (is_array($one) && isset($one['uid'])) {
                $n = (int) ($one['n'] ?? 0);
                $twice[(string) $one['uid']][] = [$n, (int) ($one['start'] ?? 0), (int) ($one['end'] ?? 0)];
            }
        }
        $items = [];
        foreach (teamcalendar::items($centre, $now) as $item) {
            $items[$item['uid']] = $item;
        }
        $rows = [];
        foreach ($DB->get_records(self::T, ['centreid' => $centre->id]) as $row) {
            $rows['eguahe-' . $centre->id . '-' . $row->eventid] = $row;
        }
        $ops = [];
        // The same one twice in the calendar (two runs at once): the one that says what Eguahe says stays.
        $which = [];
        foreach ($twice as $uid => $list) {
            $stays = $list[0];
            foreach ($list as $one) {
                if (isset($items[$uid]) && [$one[1], $one[2]] === [(int) $items[$uid]['start'], (int) $items[$uid]['end']]) {
                    $stays = $one;
                    break;
                }
            }
            foreach ($list as $one) {
                if ($one !== $stays) {
                    $ops[] = ['do' => 'delete', 'uid' => $uid, 'n' => $one[0]];
                }
            }
            $there[$uid] = [$stays[1], $stays[2]];
            $which[$uid] = $stays[0];
        }
        // Everything gone at once is another calendar or an accident: nothing is put back nor asked until a person says so.
        $ahead = array_filter($items, fn($i) => $i['start'] > $now);
        // Those that were in it: one being written was not yet.
        $written = array_filter(array_intersect_key($rows, $ahead), fn($row) => !(int) $row->creating);
        $gone = array_diff_key($written, $there);
        if (count($gone) >= self::EMPTIED && count($gone) === count($written)) {
            return [[], $emptied ?: $now];
        }
        $op = fn(string $do, array $item) => ['do' => $do, 'uid' => $item['uid'], 'n' => $which[$item['uid']] ?? 0, 'title' => $item['title'],
            'start' => $item['start'], 'end' => $item['end'], 'location' => $item['location'], 'description' => $item['description']];
        foreach ($items as $uid => $item) {
            $row = $rows[$uid] ?? null;
            $at = [(int) $item['start'], (int) $item['end']];
            $eventid = (int) substr($uid, strrpos($uid, '-') + 1);
            if (!isset($there[$uid])) {
                if ($item['start'] <= $now) {
                    // What already went by is not written now.
                    continue;
                }
                // Not there yet; or a person said to put it back; or it was deleted there and then changed here.
                if (!$row || (int) $row->restore || (int) $row->creating || [(int) $row->timestart, (int) $row->timeend] !== $at) {
                    if (!$row || (int) $row->creating <= $now - self::WRITING) {
                        $ops[] = $op('create', $item);
                        self::writing($centre, $eventid, $row, $at, $now);
                    }
                } else {
                    self::ask($row, 'deleted', 0, 0, $now);
                }
                continue;
            }
            if ($there[$uid] === $at) {
                self::agree($centre, $eventid, $row, $at, $now);
                $ops[] = $op('text', $item);
            } else if (
                $row && !(int) $row->restore && $item['start'] > $now && $there[$uid] !== [(int) $row->timestart, (int) $row->timeend] &&
                    [(int) $row->timestart, (int) $row->timeend] !== $at
            ) {
                // Changed in both between two times: nobody's is lost, a person says.
                self::ask($row, 'moved', $there[$uid][0], $there[$uid][1], $now);
            } else if (!$row || [(int) $row->timestart, (int) $row->timeend] !== $at || (int) $row->restore) {
                // It changed in Eguahe, or a person said to leave it as it was: the calendar follows.
                $ops[] = $op('set', $item);
            } else if ($item['start'] > $now) {
                self::ask($row, 'moved', $there[$uid][0], $there[$uid][1], $now);
            }
        }
        foreach (array_diff_key($there, $items) as $uid => $at) {
            // Called off in Eguahe: out of the calendar, unless it already went by. Of a family that erased its data, whenever.
            $eventid = (int) substr($uid, strrpos($uid, '-') + 1);
            if ($at[0] > $now || !$DB->record_exists(events::T, ['id' => $eventid, 'centreid' => $centre->id])) {
                $ops[] = ['do' => 'delete', 'uid' => $uid, 'n' => $which[$uid]];
            }
        }
        foreach (array_diff_key($rows, $items) as $row) {
            $DB->delete_records(self::T, ['id' => $row->id]);
        }
        return [$ops, 0];
    }

    /**
     * Both say the same: there is nothing to ask.
     *
     * @param \stdClass $centre
     * @param int $eventid
     * @param \stdClass|null $row
     * @param int[] $at
     * @param int $now
     */
    protected static function agree(\stdClass $centre, int $eventid, ?\stdClass $row, array $at, int $now): void {
        global $DB;
        $record = ['centreid' => (int) $centre->id, 'eventid' => $eventid, 'timestart' => $at[0], 'timeend' => $at[1], 'ask' => '',
            'askstart' => 0, 'askend' => 0, 'asktime' => 0, 'restore' => 0, 'creating' => 0];
        if (!$row) {
            $DB->insert_record(self::T, (object) $record);
        } else if (array_diff_assoc(array_map('strval', $record), array_map('strval', (array) $row))) {
            $DB->update_record(self::T, (object) ($record + ['id' => $row->id]));
        }
    }

    /**
     * The script was told to write one: it is not told again until it has had the time to.
     *
     * @param \stdClass $centre
     * @param int $eventid
     * @param \stdClass|null $row
     * @param int[] $at
     * @param int $now
     */
    protected static function writing(\stdClass $centre, int $eventid, ?\stdClass $row, array $at, int $now): void {
        global $DB;
        $record = ['timestart' => $at[0], 'timeend' => $at[1], 'ask' => '', 'askstart' => 0, 'askend' => 0, 'asktime' => 0,
            'creating' => $now];
        if ($row) {
            $DB->update_record(self::T, (object) ($record + ['id' => $row->id]));
        } else {
            $DB->insert_record(self::T, (object) ($record + ['centreid' => (int) $centre->id, 'eventid' => $eventid, 'restore' => 0]));
        }
    }

    /**
     * A person moved or deleted it in the calendar: to be asked, once for each thing.
     *
     * @param \stdClass $row
     * @param string $ask moved or deleted.
     * @param int $start
     * @param int $end
     * @param int $now
     */
    protected static function ask(\stdClass $row, string $ask, int $start, int $end, int $now): void {
        global $DB;
        if ($row->ask === $ask && (int) $row->askstart === $start && (int) $row->askend === $end) {
            return;
        }
        $DB->update_record(self::T, (object) ['id' => $row->id, 'ask' => $ask, 'askstart' => $start, 'askend' => $end,
            'asktime' => $now]);
    }

    /**
     * Leave it as it was: the next time, the calendar goes back to what Eguahe has.
     *
     * @param \stdClass $centre
     * @param int $eventid
     * @return bool
     */
    public static function restore(\stdClass $centre, int $eventid): bool {
        global $DB;
        $row = $DB->get_record(self::T, ['centreid' => $centre->id, 'eventid' => $eventid]);
        if (!$row || (string) $row->ask === '') {
            return false;
        }
        $DB->update_record(self::T, (object) ['id' => $row->id, 'ask' => '', 'askstart' => 0, 'askend' => 0, 'asktime' => 0,
            'restore' => 1]);
        return true;
    }

    /**
     * The calendar was found empty: a person says to put everything back in it.
     *
     * @param \stdClass $centre
     */
    public static function refill(\stdClass $centre): void {
        global $DB;
        $DB->delete_records(self::T, ['centreid' => $centre->id]);
        $config = self::config($centre);
        $config['emptied'] = 0;
        self::keep($centre, $config);
    }

    /**
     * What was changed in the calendar and nobody has said anything about yet.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @param int $householdid Only of a family; 0, of all.
     * @return array[] id, text, deleted, changeurl, offurl and keepurl
     */
    public static function questions(\stdClass $centre, bool $canact, int $now, int $householdid = 0): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT c.*, e.name, e.householdid, e.timestart AS was, e.timeend AS wasend
               FROM {" . self::T . "} c
               JOIN {" . events::T . "} e ON e.id = c.eventid
              WHERE c.centreid = :centreid AND c.ask <> '' AND e.cancelled = 0 AND e.timestart > :now" .
                ($householdid ? ' AND e.householdid = :household' : '') . "
           ORDER BY e.timestart ASC",
            ['centreid' => $centre->id, 'now' => $now] + ($householdid ? ['household' => $householdid] : [])
        );
        $people = households::some((int) $centre->id, array_filter(array_map(fn($r) => (int) $r->householdid, $rows)));
        $out = [];
        $zone = \core_date::get_server_timezone();
        foreach ($rows as $row) {
            if ($row->ask === 'moved' && (int) $row->askstart === (int) $row->was && (int) $row->askend === (int) $row->wasend) {
                // Already changed here to what it says there: the script has not come back yet.
                continue;
            }
            $family = (int) $row->householdid ? ($people[(int) $row->householdid] ?? null) : null;
            $what = $family ? (panel::adults_line($family->adults) ?: get_string('chat_family_noname', self::C)) : format_string($row->name);
            $when = fn(int $time) => get_string('agenda_when', self::C, panel_agenda::day($time)) . ', ' . panel::hour($time);
            $cita = fn(array $more) => (new \moodle_url('/local/oksigeniaeguahe/cita.php', ['h' => (int) $row->householdid,
                'id' => (int) $row->eventid] + $more))->out(false);
            $event = fn(array $more) => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => (int) $row->eventid] + $more))->out(false);
            $deleted = $row->ask === 'deleted';
            // Only longer or shorter: how long it is now.
            $lasts = !$deleted && (int) $row->askstart === (int) $row->was;
            $out[] = [
                'id' => (int) $row->eventid,
                'what' => $what,
                'text' => get_string($deleted ? 'calsync_deleted' : ($lasts ? 'calsync_lasts' : 'calsync_moved'), self::C, (object) ['what' => $what,
                    'was' => $when((int) $row->was), 'now' => $deleted ? '' : $when((int) $row->askstart), 'minutes' => get_string(
                        'panel_minutes',
                        self::C,
                        max(0, intdiv((int) $row->askend - (int) $row->askstart, MINSECS))
                    )]),
                'deleted' => $deleted,
                // Each way out is the page that already does it: with what the family is told, and its confirmation.
                'changeurl' => !$canact || $deleted ? '' : ($family ? $cita(['a' => (int) $row->askstart,
                    'm' => max(15, intdiv((int) $row->askend - (int) $row->askstart, MINSECS))]) : $event([])),
                'offurl' => !$canact ? '' : ($family ? $cita(['anular' => 1]) : $event([])),
                'keep' => $canact,
                'action' => (new \moodle_url('/local/oksigeniaeguahe/agenda.php', ['c' => $centre->id]))->out(false),
                'sesskey' => sesskey(),
            ];
        }
        return $out;
    }

    /**
     * What the settings show: whether it is heard of, the calendars it sees and what was chosen.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, int $now): array {
        $config = self::config($centre);
        $calendars = [];
        $colours = busy::colours((int) $centre->id);
        foreach ($config['known'] as $id => $name) {
            $calendars[] = ['id' => (string) $id, 'name' => $name !== '' ? $name : (string) $id, 'main' => (string) $id === $config['calendar'],
                'behind' => in_array((string) $id, $config['behind'], true), 'color' => $colours[$name] ?? 0];
        }
        // The one of the appointments first, then those seen behind, then the rest; each group as the script told it.
        usort($calendars, fn($a, $b) => [$b['main'], $b['behind']] <=> [$a['main'], $a['behind']]);
        $main = $config['calendar'] !== '' ? ($config['known'][$config['calendar']] ?? $config['calendar']) : '';
        $late = $config['read'] && $config['read'] < $now - self::LATE;
        return ['heard' => (bool) $config['read'], 'late' => $late, 'calendars' => $calendars, 'hascalendars' => (bool) $calendars,
            'chosen' => $main !== '',
            'state' => !$config['read'] ? get_string('calsync_state_none', self::C) : get_string(
                $late ? 'calsync_state_late' : ($main === '' ? 'calsync_state_choose' : 'calsync_state_ok'),
                self::C,
                (object) ['when' => panel::when_words($config['read'], $now), 'main' => $main]
            ),
            'emptied' => $config['emptied'] ? get_string('calsync_emptied', self::C, $main) : ''];
    }
}
