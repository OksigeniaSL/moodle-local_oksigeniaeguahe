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
 * What a family does with what Eguahe gives it, counted: opening its page, how far down it goes, what it touches there,
 * the mails it got and what it pressed in them. The centre reads it added up in «Cifras» and, for one family, as facts
 * in its file («they opened their page 4 times; the last, on Tuesday»). Never a score, a ranking or a guess about a
 * family.
 *
 * Opening the page is told by the page itself, once it has been in sight for a moment, so the robots that read the
 * links of a mail are not counted as a family. Opening it again within half an hour is the same visit. Whatever the team
 * does on the page of a family, looking at it from the panel, is not counted.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seen {
    /** @var string */
    const T = 'local_oksigeniaeguahe_seen';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] What is noted. */
    const KINDS = ['open', 'part', 'touch', 'mailsent', 'story', 'share'];

    /** @var string[] The parts of the page of a family, from the top down. */
    const PARTS = ['wall', 'visit', 'hosts', 'told', 'data'];

    /** @var string[] What can be touched on the page of a family. */
    const TOUCHES = ['drawing_seen', 'drawing_enlarged', 'tales', 'directions', 'calendar', 'question', 'phone_join',
        'language', 'download', 'drawing_sent', 'write', 'call', 'hobbies', 'volunteer', 'open_act', 'share_act',
        'share_drawing', 'share_family', 'apply'];

    /** @var string[] What is done with the story of the first day; the page, when it matters, goes in «via» (p0 to p9). */
    const STORY = ['opened', 'page', 'like', 'unlike', 'back', 'enlarged', 'again', 'saved', 'sent', 'whole', 'repeated',
        'musicoff', 'read'];

    /** @var int The most a household notes in a day: beyond it, nothing more is kept. */
    const MOST = 400;

    /** @var int Opening the page again within this time is the same visit. */
    const VISIT = 1800;

    /** @var string[] Touched again and again in one visit, it is once: each chip kept is not one more time. */
    const ONCE_A_VISIT = ['hobbies'];

    /**
     * The mail a link came from, when it is one of those Eguahe sends; else nothing.
     *
     * @param string $via
     * @return string
     */
    public static function via(string $via): string {
        return in_array($via, familymail::KINDS, true) ? $via : '';
    }

    /**
     * Whether who is looking is someone of the team of the centre: what they do on the page of a family is not counted.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function from_team(\stdClass $centre): bool {
        global $USER;
        return isloggedin() && !isguestuser() && centres::can_work((int) $centre->id, (int) $USER->id);
    }

    /**
     * Notes one thing a family did.
     *
     * @param \stdClass $household
     * @param string $kind open, part, touch or mailsent.
     * @param string $what The part, the thing touched or the kind of mail.
     * @param string $via The mail it came from.
     * @param int $now
     * @return bool Whether it was noted.
     */
    public static function record(\stdClass $household, string $kind, string $what = '', string $via = '', int $now = 0): bool {
        global $DB;
        $now = $now ?: time();
        // In the story, «via» is the page of the book; in what is shared, the piece.
        if ($kind === 'story') {
            $via = preg_match('/^p[0-9]$/', $via) ? $via : '';
        } else if ($kind === 'share') {
            $via = in_array($via, share::PIECES, true) ? $via : '';
        } else {
            $via = self::via($via);
        }
        $lists = ['open' => [''], 'part' => self::PARTS, 'touch' => self::TOUCHES, 'mailsent' => familymail::KINDS,
            'story' => self::STORY, 'share' => share::COUNTED];
        if ($kind === 'share' && $via === '') {
            return false;
        }
        if (!isset($lists[$kind]) || !in_array($what, $lists[$kind], true)) {
            return false;
        }
        $id = (int) $household->id;
        // What strangers do with what the family shared (open it, pass it on) has an allowance of its own: a link passed
        // round a group never leaves the family's own page uncounted.
        $own = $kind === 'share' ? 'kind = ?' : 'kind <> ?';
        if (
            $DB->count_records_select(self::T, "householdid = ? AND timecreated > ? AND $own", [$id, $now - DAYSECS, 'share'])
                >= self::MOST
        ) {
            return false;
        }
        $again = $kind === 'touch' && in_array($what, self::ONCE_A_VISIT, true) && $DB->record_exists_select(
            self::T,
            'householdid = ? AND kind = ? AND what = ? AND timecreated > ?',
            [$id, 'touch', $what, $now - self::VISIT]
        );
        if ($again) {
            return false;
        }
        // The book opened again a moment later is the same reading.
        $reading = $kind === 'story' && $what === 'opened' && $DB->record_exists_select(
            self::T,
            'householdid = ? AND kind = ? AND what = ? AND timecreated > ?',
            [$id, 'story', 'opened', $now - self::VISIT]
        );
        if ($reading) {
            return false;
        }
        if ($kind === 'open' && $via === '') {
            // The same visit: opened again a moment later, or the page that comes back after sending something.
            $last = (int) $DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {' . self::T . '} WHERE householdid = ? AND kind = ?',
                [$id, 'open']
            );
            if ($last && $now - $last < self::VISIT) {
                return false;
            }
        }
        $DB->insert_record(self::T, (object) ['centreid' => $household->centreid, 'householdid' => $id, 'kind' => $kind,
            'what' => $what !== '' ? $what : null, 'via' => $via !== '' ? $via : null, 'timecreated' => $now]);
        return true;
    }

    /**
     * A mail went to a family.
     *
     * @param \stdClass $household
     * @param string $kind
     * @param int $now
     */
    public static function mail_sent(\stdClass $household, string $kind, int $now = 0): void {
        self::record($household, 'mailsent', $kind, '', $now);
    }

    /**
     * Everything noted of some households, the oldest first.
     *
     * @param int[] $householdids
     * @return \stdClass[]
     */
    public static function of(array $householdids): array {
        global $DB;
        if (!$householdids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_values($householdids));
        return array_values($DB->get_records_select(
            self::T,
            'householdid ' . $in,
            $params,
            'timecreated ASC, id ASC',
            'id, householdid, kind, what, via, timecreated'
        ));
    }

    /**
     * Everything noted of some households, one row at a time, without holding it all in memory: for a centre with
     * thousands of families. The order is by family block, not by time.
     *
     * @param int[] $householdids
     * @return \Generator \stdClass rows.
     */
    public static function each(array $householdids): \Generator {
        global $DB;
        foreach (array_chunk(array_values($householdids), 1000) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_recordset_select(
                self::T,
                'householdid ' . $in,
                $params,
                '',
                'id, householdid, kind, what, via, timecreated'
            );
            foreach ($rows as $row) {
                yield $row;
            }
            $rows->close();
        }
    }

    /**
     * What was noted of a family, added up, for its file: how many times it opened its page and on how many days, the
     * last time, how far down it went, what it touches most and what it pressed in the mails.
     *
     * @param int $householdid
     * @return array opens, days, last, deepest (a part or ''), touches (what => times, most first) and mails (kind =>
     *               clicks).
     */
    public static function summary(int $householdid): array {
        $out = ['opens' => 0, 'days' => 0, 'last' => 0, 'deepest' => '', 'touches' => [], 'mails' => []];
        $days = [];
        $deepest = -1;
        foreach (self::of([$householdid]) as $row) {
            if ($row->kind === 'open') {
                $out['opens']++;
                $out['last'] = max($out['last'], (int) $row->timecreated);
                $days[self::day((int) $row->timecreated)] = true;
            } else if ($row->kind === 'part') {
                $deepest = max($deepest, (int) array_search($row->what, self::PARTS, true));
            } else if ($row->kind === 'touch') {
                $out['touches'][$row->what] = ($out['touches'][$row->what] ?? 0) + 1;
            }
            // In the story «via» is its page, and in what is shared, the piece: only the rest came from a mail.
            if ($row->via && !in_array($row->kind, ['mailsent', 'story', 'share'], true)) {
                $out['mails'][$row->via] = ($out['mails'][$row->via] ?? 0) + 1;
            }
        }
        arsort($out['touches']);
        $out['days'] = count($days);
        $out['deepest'] = $deepest >= 0 ? self::PARTS[$deepest] : '';
        return $out;
    }

    /**
     * The day of a time, as the centre lives it.
     *
     * @param int $time
     * @return string Y-m-d.
     */
    public static function day(int $time): string {
        return (new \DateTime('@' . $time))->setTimezone(\core_date::get_server_timezone_object())->format('Y-m-d');
    }

    /**
     * What a family can take with its data: what was noted, as it was noted.
     *
     * @param int $householdid
     * @return array[]
     */
    public static function export(int $householdid): array {
        return array_map(fn($row) => ['kind' => $row->kind, 'what' => (string) $row->what, 'via' => (string) $row->via,
            'time' => date('c', (int) $row->timecreated)], self::of([$householdid]));
    }

    /**
     * Forgets everything noted of a family.
     *
     * @param int $householdid
     */
    public static function delete(int $householdid): void {
        global $DB;
        $DB->delete_records(self::T, ['householdid' => $householdid]);
    }
}
