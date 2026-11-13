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
 * Visits of their own: a family asks for one saying the days and the part of the day that suit them, and someone of the
 * team proposes the day and the hour and who guides them. The visit is an event of that family alone, with its sign-up,
 * so it shows on its page, in its calendar and in its reminder like any other, and its guide sees it in the inbox.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class visits {
    /** @var string */
    const REQUESTS = 'local_oksigeniaeguahe_request';

    /** @var int[] Days of the week a family may choose, ISO (1 Monday … 6 Saturday). */
    const DAYS = [1, 2, 3, 4, 5, 6];

    /** @var string[] Parts of the day. */
    const PERIODS = ['morning', 'afternoon'];

    /**
     * Keeps what a family asked for, or changes what it asked for before and is still waiting.
     *
     * @param \stdClass $household
     * @param \stdClass $event The event of asking.
     * @param int[] $days
     * @param string[] $periods
     * @param int $people
     * @return int
     */
    public static function request(\stdClass $household, \stdClass $event, array $days, array $periods, int $people): int {
        global $DB;
        $row = (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'eventid' => $event->id,
            'days' => implode(',', array_values(array_intersect(self::DAYS, array_map('intval', $days)))) ?: null,
            'periods' => implode(',', array_values(array_intersect(self::PERIODS, $periods))) ?: null,
            'people' => max(1, min(20, $people)),
            'status' => 'pending',
            'timemodified' => time(),
        ];
        $current = $DB->get_record(self::REQUESTS, ['householdid' => $household->id, 'status' => 'pending']);
        if ($current) {
            $row->id = $current->id;
            $DB->update_record(self::REQUESTS, $row);
            return (int) $current->id;
        }
        $row->timecreated = $row->timemodified;
        return (int) $DB->insert_record(self::REQUESTS, $row);
    }

    /**
     * What a family is waiting for: its request still without a day.
     *
     * @param int $householdid
     * @return \stdClass|null
     */
    public static function pending(int $householdid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::REQUESTS, ['householdid' => $householdid, 'status' => 'pending']) ?: null;
    }

    /**
     * What suits a family, in words: «lunes o miércoles, por la mañana».
     *
     * @param \stdClass $request
     * @param string $lang
     * @return string
     */
    public static function suits(\stdClass $request, string $lang): string {
        $days = array_filter(array_map('intval', explode(',', (string) $request->days)));
        $periods = array_filter(explode(',', (string) $request->periods));
        $names = [];
        $locale = $lang === 'pt_br' ? 'pt_BR' : ($lang === 'pt' ? 'pt_PT' : $lang);
        foreach ($days as $day) {
            // A Monday in the past, plus the day: its name in the language of the family.
            $time = strtotime('2026-11-02 12:00') + ($day - 1) * DAYSECS;
            $names[] = class_exists('\IntlDateFormatter') ? (new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                \core_date::get_server_timezone_object(),
                null,
                'EEEE'
            ))->format($time) :
                userdate($time, '%A');
        }
        $when = $names ? familypage::join($names, $lang) : publiclang::str('visit_anyday', $lang);
        if (count($periods) === 1) {
            $when .= ', ' . publiclang::str('visit_period_' . reset($periods), $lang);
        }
        return $when;
    }

    /**
     * Gives a family a visit of its own: an event for it alone, with its guide, and its sign-up. The request it was
     * waiting with, if any, is answered.
     *
     * @param \stdClass $household
     * @param int $start
     * @param int $minutes
     * @param int $guide The member of the team who guides them.
     * @param string $place
     * @param int $people
     * @return \stdClass The event of the visit.
     */
    public static function propose(
        \stdClass $household,
        int $start,
        int $minutes,
        int $guide,
        string $place,
        int $people
    ): \stdClass {
        global $DB;
        $centreid = (int) $household->centreid;
        // The name the family reads: who guides them.
        $host = $guide ? (centres::hosts($centreid, [$guide])[0] ?? null) : null;
        $lang = $household->lang ?: 'es';
        $name = $host ? publiclang::str('visit_name', $lang, $host->first) : publiclang::str('visit_name_team', $lang);
        $transaction = $DB->start_delegated_transaction();
        // A visit of its own still to come is replaced by this one, never kept beside it: one day for the family, and
        // no reminder of the old one.
        $now = time();
        $old = $DB->get_records_select(
            events::T,
            'householdid = ? AND timeend >= ? AND cancelled = 0',
            [$household->id, $now],
            '',
            'id'
        );
        foreach (array_keys($old) as $oldid) {
            $DB->delete_records(events::SIGNUPS, ['eventid' => $oldid]);
            $DB->update_record(events::T, (object) ['id' => $oldid, 'cancelled' => $now, 'open' => 0, 'timemodified' => $now]);
        }
        $eventid = events::save($centreid, (object) ['kind' => 'visit', 'name' => \core_text::substr($name, 0, 255),
            'place' => $place, 'timestart' => $start, 'timeend' => $start + max(15, $minutes) * MINSECS,
            'capacity' => 0, 'open' => 0, 'hosts' => [$guide]]);
        $DB->set_field(events::T, 'householdid', $household->id, ['id' => $eventid]);
        $event = events::get($eventid, $centreid);
        events::sign_up($event, (int) $household->id, $people);
        $request = self::pending((int) $household->id);
        if ($request) {
            $DB->update_record(self::REQUESTS, (object) ['id' => $request->id, 'status' => 'scheduled', 'visitid' => $eventid,
                'timemodified' => time()]);
        }
        foreach (array_keys($old) as $oldid) {
            $DB->set_field(self::REQUESTS, 'visitid', $eventid, ['householdid' => $household->id, 'visitid' => $oldid]);
        }
        $transaction->allow_commit();
        return $event;
    }

    /**
     * What families asked for in an event of asking: the ones waiting first, then the ones with their visit.
     *
     * @param \stdClass $event
     * @return \stdClass[] Requests with household (as households::full() gives it) and visit (the event, if any).
     */
    public static function requests(\stdClass $event): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records(
            self::REQUESTS,
            ['centreid' => $event->centreid, 'eventid' => $event->id],
            'status ASC, timecreated ASC'
        );
        foreach ($rows as $row) {
            $household = households::get((int) $row->householdid, (int) $event->centreid);
            if (!$household) {
                continue;
            }
            $row->household = households::full($household);
            $row->visit = $row->visitid ? (events::get((int) $row->visitid, (int) $event->centreid) ?: null) : null;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The days of the week for the chips of a form, in the language of the family.
     *
     * @param string $lang
     * @return array Day => its name, with a capital.
     */
    public static function day_names(string $lang): array {
        $locale = $lang === 'pt_br' ? 'pt_BR' : ($lang === 'pt' ? 'pt_PT' : $lang);
        $out = [];
        foreach (self::DAYS as $day) {
            $time = strtotime('2026-11-02 12:00') + ($day - 1) * DAYSECS;
            $name = class_exists('\IntlDateFormatter') ? (new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                \core_date::get_server_timezone_object(),
                null,
                'EEEE'
            ))->format($time) :
                userdate($time, '%A');
            $out[$day] = \core_text::strtoupper(\core_text::substr($name, 0, 1)) . \core_text::substr($name, 1);
        }
        return $out;
    }

    /**
     * The visits of the days to come that a person of the team guides or receives.
     *
     * @param int $centreid
     * @param int $userid
     * @return \stdClass[] Events with their family, if they are of one.
     */
    public static function mine(int $centreid, int $userid): array {
        global $DB;
        $member = $DB->get_field(centres::MEMBERS, 'id', ['centreid' => $centreid, 'userid' => $userid]);
        if (!$member) {
            return [];
        }
        $out = [];
        foreach (
            $DB->get_records_select(
                events::T,
                'centreid = :centreid AND timeend > :now AND hosts IS NOT NULL AND cancelled = 0',
                ['centreid' => $centreid, 'now' => time()],
                'timestart ASC'
            ) as $event
        ) {
            if (in_array((int) $member, array_map('intval', explode(',', (string) $event->hosts)), true)) {
                $out[] = $event;
            }
        }
        return $out;
    }
}
