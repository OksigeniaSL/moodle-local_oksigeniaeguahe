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
 * Whether what comes in through each entry of a centre is still coming: zero and «not known» are not the same, so
 * «nobody is waiting» is only said of what arrived. An entry can go quiet in four ways, and all of them used to look like
 * nobody writing: it stops calling, it calls and is refused (its key changed), it calls and nothing in it is understood
 * (a column was renamed, a tab is gone), or the families stop coming through it for longer than it ever was.
 *
 * A sheet calls every night even with nothing new, so its silence is known in a day and a half; a form, an address or a
 * tool is only compared with itself, and one that brings few families is never hurried.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pulse {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int An entry that calls every night is quiet after this long: one night missed, with room. */
    const NIGHT = 36 * HOURSECS;

    /** @var int The calls of the night that are kept. */
    const BEATS = 5;

    /** @var int Two calls nearer than this are the same one. */
    const APART = 12 * HOURSECS;

    /** @var int The shortest silence that is said of an entry that only brings what families write. */
    const FLOOR = 5 * DAYSECS;

    /** @var int How far back its own rhythm is looked for. */
    const HISTORY = 120 * DAYSECS;

    /** @var int The families and the days it needs to have a rhythm. */
    const FAMILIES = 8;

    /** @var int The days with families it needs to have a rhythm. */
    const DAYS = 6;

    /** @var int The rows in a row that nothing was understood of before it is said. */
    const UNREAD = 5;

    /** @var int The days of the tape of an entry, today the last one. */
    const TAPE = 28;

    /** @var string The states that are said: what arrived may not be all there is. */
    const SILENT = ['refused', 'missing', 'unread', 'quiet'];

    /**
     * An entry called and was let in, with something new or with nothing.
     *
     * @param \stdClass $entry
     * @param int $understood The rows something was made of, new or already here.
     * @param int $unread The rows nothing was made of.
     * @param bool $night Whether it is the call of every night: everything again, or a beat with nothing in it.
     * @param string[] $missing The tabs its script could not find.
     * @param int $now
     */
    public static function called(
        \stdClass $entry,
        int $understood,
        int $unread,
        bool $night = false,
        array $missing = [],
        int $now = 0
    ): void {
        global $DB;
        $now = $now ?: time();
        $changes = (object) ['id' => $entry->id, 'calledat' => $now, 'refusedat' => 0];
        // A sheet sent whole goes in several calls: rows with nobody at its end say nothing when the ones before were read.
        $sameread = !(int) ($entry->unread ?? 0) && $now - (int) ($entry->calledat ?? 0) < HOURSECS;
        if ($understood) {
            $changes->unread = 0;
            $changes->unreadat = 0;
        } else if ($unread && !$sameread) {
            $changes->unread = min(100000, (int) ($entry->unread ?? 0) + $unread);
            $changes->unreadat = (int) ($entry->unreadat ?? 0) ?: $now;
        }
        if ($night) {
            $beats = self::beats($entry);
            if (!$beats || $now - end($beats) >= self::APART) {
                $beats[] = $now;
            }
            $changes->beats = implode(',', array_slice($beats, -self::BEATS));
            // Only the call of the night looks at every tab.
            $names = array_filter(array_map(fn($t) => trim((string) $t), $missing), fn($t) => $t !== '');
            $changes->missing = $names ? \core_text::substr(implode(', ', $names), 0, 255) : null;
            $changes->missingat = $names ? ((int) ($entry->missingat ?? 0) ?: $now) : 0;
        }
        $DB->update_record(entries::T, $changes);
        foreach ((array) $changes as $field => $value) {
            $entry->$field = $value;
        }
    }

    /**
     * An entry called with a key that is not its own, and was not let in: since when, until it is let in again. Never for
     * a form, whose address anyone has.
     *
     * @param \stdClass $entry
     * @param int $now
     */
    public static function refused(\stdClass $entry, int $now = 0): void {
        global $DB;
        if ($entry->kind === 'form' || (int) ($entry->refusedat ?? 0)) {
            return;
        }
        $entry->refusedat = $now ?: time();
        $DB->set_field(entries::T, 'refusedat', $entry->refusedat, ['id' => $entry->id]);
    }

    /**
     * Someone opened a form: it is where it was put, and can be seen.
     *
     * @param \stdClass $entry
     * @param int $now
     */
    public static function opened(\stdClass $entry, int $now = 0): void {
        global $DB;
        if (\core_useragent::is_web_crawler()) {
            return;
        }
        $DB->execute(
            'UPDATE {' . entries::T . '} SET opened = opened + 1, calledat = :t WHERE id = :id',
            ['t' => $now ?: time(), 'id' => $entry->id]
        );
    }

    /**
     * The last calls of the night of an entry.
     *
     * @param \stdClass $entry
     * @return int[] Oldest first.
     */
    protected static function beats(\stdClass $entry): array {
        return array_values(array_filter(array_map('intval', explode(',', (string) ($entry->beats ?? '')))));
    }

    /**
     * Whether an entry calls every night, as far as its last two calls tell.
     *
     * @param \stdClass $entry
     * @return bool
     */
    public static function nightly(\stdClass $entry): bool {
        $beats = array_slice(self::beats($entry), -2);
        return count($beats) === 2 && $beats[1] - $beats[0] <= self::NIGHT;
    }

    /**
     * How an entry is: whether what comes through it is still coming, as far as it can be told.
     *
     * @param \stdClass $entry
     * @param int $now
     * @return array id, name, state (ok, never or one of SILENT), since, last (the last family), longest (days, for its
     *     own rhythm) and key (of this silence, to say it is known).
     */
    public static function of(\stdClass $entry, int $now): array {
        $out = ['id' => (int) $entry->id, 'name' => format_string($entry->name), 'state' => 'ok', 'since' => 0,
            'last' => (int) $entry->lastreceived, 'longest' => 0];
        $called = (int) ($entry->calledat ?? 0);
        if ((int) ($entry->refusedat ?? 0)) {
            $out['state'] = 'refused';
            $out['since'] = (int) $entry->refusedat;
        } else if (self::nightly($entry) && $now - $called > self::NIGHT) {
            $out['state'] = 'quiet';
            $out['since'] = $called;
        } else if (trim((string) ($entry->missing ?? '')) !== '') {
            $out['state'] = 'missing';
            $out['since'] = (int) ($entry->missingat ?? 0);
        } else if ((int) ($entry->unread ?? 0) >= self::UNREAD) {
            $out['state'] = 'unread';
            $out['since'] = (int) ($entry->unreadat ?? 0);
        } else if (!self::nightly($entry)) {
            $rhythm = self::rhythm($entry, $now);
            if ($rhythm && $now - $rhythm['last'] > max(self::FLOOR, 2 * $rhythm['longest'])) {
                $out['state'] = 'quiet';
                $out['since'] = $rhythm['last'];
                $out['longest'] = max(1, (int) round($rhythm['longest'] / DAYSECS));
            } else if (!$out['last'] && !$called && !(int) ($entry->testedat ?? 0)) {
                $out['state'] = 'never';
            }
        }
        // One key for each silence: saying it is known does not hide the next one.
        $sum = crc32($out['state'] . '|' . $out['since'] . '|' . (string) ($entry->missing ?? ''));
        $out['key'] = sprintf('silent-%d-%04d-%04d', $out['id'], $sum % 10000, intdiv($sum, 10000) % 10000);
        return $out;
    }

    /**
     * The rhythm of an entry that only brings what families write: the longest it was quiet between two of them, and
     * the last one. Null while it has brought too few, or on too few days, to have one.
     *
     * @param \stdClass $entry
     * @param int $now
     * @return array|null longest and last.
     */
    protected static function rhythm(\stdClass $entry, int $now): ?array {
        global $DB;
        $times = array_map('intval', $DB->get_fieldset_select(
            households::CONTACTS,
            'timecreated',
            "entryid = :entry AND direction = 'in' AND timecreated > :from AND timecreated <= :now ORDER BY timecreated ASC",
            ['entry' => $entry->id, 'from' => $now - self::HISTORY, 'now' => $now]
        ));
        $days = array_unique(array_map(fn($t) => userdate($t, '%Y%m%d', 99, false), $times));
        if (count($times) < self::FAMILIES || count($days) < self::DAYS) {
            return null;
        }
        $longest = 0;
        for ($i = 1; $i < count($times); $i++) {
            $longest = max($longest, $times[$i] - $times[$i - 1]);
        }
        return ['longest' => $longest, 'last' => end($times)];
    }

    /**
     * Every open entry of a centre, as it is.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[] As of() gives them, each with its entry.
     */
    public static function of_centre(\stdClass $centre, int $now): array {
        if (!empty($centre->practice)) {
            return [];
        }
        $out = [];
        foreach (entries::for_centre((int) $centre->id) as $entry) {
            if (!empty($entry->active)) {
                $out[] = self::of($entry, $now) + ['entry' => $entry];
            }
        }
        return $out;
    }

    /**
     * The entries of a centre that may not be bringing all there is.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[]
     */
    public static function silent(\stdClass $centre, int $now): array {
        return array_values(array_filter(self::of_centre($centre, $now), fn($p) => in_array($p['state'], self::SILENT, true)));
    }

    /**
     * What a silence is, in words: which entry, since when and what may be behind it.
     *
     * @param array $pulse From of_centre().
     * @param int $now
     * @param bool $named Whether it says the name of the entry and since when; not where its card and its tag say them.
     * @return string
     */
    public static function words(array $pulse, int $now, bool $named = true): string {
        $c = self::C;
        $entry = $pulse['entry'];
        $a = (object) ['name' => $pulse['name'], 'since' => $pulse['since'] ? self::since($pulse['since'], $now) : '',
            'tabs' => (string) ($entry->missing ?? ''), 'n' => (int) ($entry->unread ?? 0), 'days' => $pulse['longest'],
            'opened' => (int) ($entry->opened ?? 0)];
        $here = $named ? '' : '_here';
        if ($pulse['state'] !== 'quiet') {
            return get_string('pulse_' . $pulse['state'] . $here, $c, $a);
        }
        if (self::nightly($entry)) {
            return get_string('pulse_quiet_call' . $here, $c, $a);
        }
        $text = get_string(($a->days === 1 ? 'pulse_quiet_one' : 'pulse_quiet') . $here, $c, $a);
        if ($entry->kind !== 'form') {
            return $text;
        }
        // A form that is still opened is there; whether it can be sent is tried in a minute.
        return $text . ' ' . get_string($a->opened ? 'pulse_quiet_opened' : 'pulse_quiet_unopened', $c, $a);
    }

    /**
     * How an entry is, for its stamp, and since when: «Quiet», «since Saturday at 2:38».
     *
     * @param array $pulse From of_centre().
     * @param int $now
     * @return array|false word and since (empty when it is not known); false when there is nothing to say of it.
     */
    public static function tag(array $pulse, int $now) {
        if (!in_array($pulse['state'], self::SILENT, true)) {
            return false;
        }
        return ['word' => get_string('pulse_tag_' . $pulse['state'], self::C),
            'since' => $pulse['since'] ? self::since($pulse['since'], $now) : ''];
    }

    /**
     * The tape of each entry: what came through it on each of the last days, and the days that are not known: it was quiet,
     * not let in, not understood or without some of its tabs.
     *
     * @param \stdClass $centre
     * @param array[] $pulses From of_centre().
     * @param int $now
     * @return array[] By entry, its days, the oldest first: n (how many families), h (the height of its stroke, 1 to 4, 0 with
     *     none), quiet (not known) and before (before the entry was there).
     */
    public static function tapes(\stdClass $centre, array $pulses, int $now): array {
        global $DB;
        $came = [];
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            "centreid = :centre AND direction = 'in' AND entryid > 0 AND timecreated > :from AND timecreated <= :now",
            ['centre' => $centre->id, 'from' => $now - (self::TAPE + 1) * DAYSECS, 'now' => $now],
            '',
            'id, entryid, householdid, timecreated'
        );
        foreach ($rows as $row) {
            // Families, not messages: one that writes twice in a day is one.
            $came[(int) $row->entryid][panel::days_between((int) $row->timecreated, $now)][(int) $row->householdid] = true;
        }
        $rows->close();
        $out = [];
        foreach ($pulses as $pulse) {
            $born = panel::days_between((int) $pulse['entry']->timecreated, $now);
            // Not known from the day after its last call or family; not let in, not understood or with tabs it does not find,
            // from the very day. A day families came is never said not to be known: what came by the other tabs is seen.
            $quiet = $pulse['since'] && in_array($pulse['state'], self::SILENT, true) ?
                panel::days_between($pulse['since'], $now) - ($pulse['state'] === 'quiet' ? 1 : 0) : -1;
            $days = [];
            for ($ago = self::TAPE - 1; $ago >= 0; $ago--) {
                $n = count($came[$pulse['id']][$ago] ?? []);
                $days[] = ['n' => $n, 'h' => $n ? min(4, 1 + (int) floor(log($n, 2))) : 0, 'quiet' => !$n && $ago <= $quiet,
                    'before' => !$n && $ago > max($born, $quiet)];
            }
            $out[$pulse['id']] = $days;
        }
        return $out;
    }

    /**
     * Since when, in words: «since 2:38», «since yesterday at 2:38», «since Friday at 2:38».
     *
     * @param int $time
     * @param int $now
     * @return string
     */
    protected static function since(int $time, int $now): string {
        $days = panel::days_between($time, $now);
        $hour = panel::hour($time);
        if ($days <= 1) {
            return get_string($days <= 0 ? 'panel_since_today' : 'panel_since_yesterday', self::C, $hour);
        }
        $day = $days < 7 ? userdate($time, '%A') : userdate($time, get_string('strftimedateshort', 'langconfig'));
        return get_string('panel_since_day', self::C, (object) ['day' => $day, 'time' => $hour]);
    }

    /**
     * When the last thing came through an entry, for its line: the last family, and the last call or how many opened it.
     *
     * @param array $pulse From of_centre().
     * @param int $now
     * @return string
     */
    public static function line(array $pulse, int $now): string {
        return implode(' · ', array_filter([$pulse['last'] ? get_string('pulse_last', self::C, panel::when_words(
            $pulse['last'],
            $now
        )) : get_string('pulse_nobody_yet', self::C), self::call($pulse, $now)]));
    }

    /**
     * When an entry last called, though it brought nothing; a form, how many times it was opened. Not of one that does not
     * call or is not let in: its tag says since when.
     *
     * @param array $pulse From of_centre().
     * @param int $now
     * @return string Empty when it is not known yet.
     */
    public static function call(array $pulse, int $now): string {
        $c = self::C;
        $entry = $pulse['entry'];
        $called = (int) ($entry->calledat ?? 0);
        if ($entry->kind === 'form') {
            $opened = (int) ($entry->opened ?? 0);
            if ($opened) {
                $key = ($pulse['last'] ? 'pulse_opened' : 'pulse_opened_never') . ($opened === 1 ? '_one' : '');
                return get_string($key, $c, (object) ['n' => $opened, 'when' => panel::when_words($called, $now)]);
            }
            return '';
        }
        // Nor of the call that brought the last family, which is already said.
        return $called > $pulse['last'] + 5 * MINSECS && !in_array($pulse['state'], ['quiet', 'refused'], true) ?
            get_string('pulse_called', $c, panel::when_words($called, $now)) : '';
    }
}
