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
 * Open days and visits: their public link and QR, who signed up, who came, and the calendar file for each family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class events {
    /** @var string */
    const T = 'local_oksigeniaeguahe_event';

    /** @var string */
    const SLOTS = 'local_oksigeniaeguahe_slot';

    /** @var string */
    const SIGNUPS = 'local_oksigeniaeguahe_signup';

    /** @var string[] Open days, visits, and the rest (a party, a talk), which are counted apart. */
    const KINDS = ['openday', 'visit', 'other'];

    /** @var array The channel each kind of event brings a household from. */
    const CHANNEL = ['openday' => 'openday', 'visit' => 'visit', 'other' => 'event'];

    /**
     * An event of a centre.
     *
     * @param int $id
     * @param int $centreid
     * @return \stdClass|null
     */
    public static function get(int $id, int $centreid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::T, ['id' => $id, 'centreid' => $centreid]) ?: null;
    }

    /**
     * The event of a public link.
     *
     * @param string $token
     * @return \stdClass|null
     */
    public static function by_token(string $token): ?\stdClass {
        global $DB;
        if (!preg_match('/^[a-z0-9]{20}$/', $token)) {
            return null;
        }
        return $DB->get_record(self::T, ['token' => $token]) ?: null;
    }

    /**
     * The events of a centre, the next ones first and then the past.
     *
     * @param int $centreid
     * @return \stdClass[] With signedup (households) and people.
     */
    public static function for_centre(int $centreid): array {
        global $DB;
        $events = $DB->get_records_sql('SELECT e.*, COUNT(s.id) AS signedup, COALESCE(SUM(s.people), 0) AS people,
                                               COALESCE(SUM(CASE WHEN s.attended > 0 THEN 1 ELSE 0 END), 0) AS came
                                          FROM {' . self::T . '} e
                                     LEFT JOIN {local_oksigeniaeguahe_signup} s ON s.eventid = e.id
                                         WHERE e.centreid = :centreid
                                      GROUP BY e.id, e.centreid, e.kind, e.name, e.place, e.timestart, e.timeend, e.capacity,
                                               e.token, e.open, e.timecreated, e.timemodified
                                      ORDER BY e.timestart DESC', ['centreid' => $centreid]);
        $now = time();
        $next = array_filter($events, fn($e) => $e->timeend >= $now);
        $past = array_filter($events, fn($e) => $e->timeend < $now);
        uasort($next, fn($a, $b) => $a->timestart <=> $b->timestart);
        return $next + $past;
    }

    /**
     * Saves an event: new when it has no id.
     *
     * @param int $centreid
     * @param \stdClass $data kind, name, place, timestart, timeend, capacity, open and, to change one, id.
     * @return int
     */
    public static function save(int $centreid, \stdClass $data): int {
        global $DB;
        $row = (object) [
            'centreid' => $centreid,
            'kind' => in_array($data->kind ?? '', self::KINDS, true) ? $data->kind : 'openday',
            'name' => \core_text::substr(trim((string) $data->name), 0, 255),
            'place' => trim((string) ($data->place ?? '')) === '' ? null : \core_text::substr(trim($data->place), 0, 255),
            'timestart' => (int) $data->timestart,
            'timeend' => max((int) $data->timestart, (int) $data->timeend),
            'capacity' => max(0, (int) ($data->capacity ?? 0)),
            'open' => empty($data->open) ? 0 : 1,
            'timemodified' => time(),
        ];
        if (!empty($data->id)) {
            self::get((int) $data->id, $centreid) ?? throw new \moodle_exception('invalidrecord', 'error');
            $row->id = (int) $data->id;
            $DB->update_record(self::T, $row);
        } else {
            $row->token = self::new_token();
            $row->timecreated = $row->timemodified;
            $row->id = (int) $DB->insert_record(self::T, $row);
        }
        if (isset($data->slots) && is_array($data->slots)) {
            self::save_slots(self::get($row->id, $centreid), $data->slots);
        }
        return $row->id;
    }

    /**
     * The turns of an event, in their order.
     *
     * @param int $eventid
     * @return \stdClass[] With the people signed up in each.
     */
    public static function slots(int $eventid): array {
        global $DB;
        return $DB->get_records_sql('SELECT t.*, COALESCE(SUM(s.people), 0) AS people
                                       FROM {' . self::SLOTS . '} t
                                  LEFT JOIN {' . self::SIGNUPS . '} s ON s.slotid = t.id
                                      WHERE t.eventid = :eventid
                                   GROUP BY t.id, t.centreid, t.eventid, t.timestart, t.timeend, t.capacity
                                   ORDER BY t.timestart ASC, t.id ASC', ['eventid' => $eventid]);
    }

    /**
     * Saves the turns of an event: those given are kept or changed, those without an hour go if nobody signed up in
     * them. With turns, the event goes from the first to the end of the last.
     *
     * @param \stdClass $event
     * @param array $slots Each [id, timestart, timeend, capacity]; id 0 for a new one, timestart 0 to remove it.
     */
    public static function save_slots(\stdClass $event, array $slots): void {
        global $DB;
        $existing = $DB->get_records(self::SLOTS, ['eventid' => $event->id]);
        foreach ($slots as $slot) {
            $slot = (object) $slot;
            $id = (int) ($slot->id ?? 0);
            if ($id && !isset($existing[$id])) {
                continue;
            }
            if (empty($slot->timestart)) {
                if ($id && !$DB->record_exists(self::SIGNUPS, ['slotid' => $id])) {
                    $DB->delete_records(self::SLOTS, ['id' => $id]);
                }
                continue;
            }
            $row = (object) ['centreid' => $event->centreid, 'eventid' => $event->id, 'timestart' => (int) $slot->timestart,
                'timeend' => max((int) $slot->timestart, (int) ($slot->timeend ?? 0)),
                'capacity' => max(0, (int) ($slot->capacity ?? 0))];
            if ($id) {
                $row->id = $id;
                $DB->update_record(self::SLOTS, $row);
            } else {
                $DB->insert_record(self::SLOTS, $row);
            }
        }
        $span = $DB->get_record_sql('SELECT MIN(timestart) AS first, MAX(timeend) AS last FROM {' . self::SLOTS . '}
                                      WHERE eventid = :eventid', ['eventid' => $event->id]);
        if ($span && $span->first) {
            $DB->update_record(self::T, (object) ['id' => $event->id, 'timestart' => (int) $span->first,
                'timeend' => (int) $span->last]);
        }
    }

    /**
     * A token for a public link: twenty letters and digits, easy to type from a poster.
     *
     * @return string
     */
    protected static function new_token(): string {
        global $DB;
        do {
            $token = '';
            $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
            for ($i = 0; $i < 20; $i++) {
                $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($DB->record_exists(self::T, ['token' => $token]));
        return $token;
    }

    /**
     * The public link of an event, the one its QR opens.
     *
     * @param \stdClass $event
     * @return \moodle_url
     */
    public static function url(\stdClass $event): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token]);
    }

    /**
     * People signed up for an event.
     *
     * @param int $eventid
     * @return int
     */
    public static function people(int $eventid): int {
        global $DB;
        return (int) $DB->get_field_sql('SELECT COALESCE(SUM(people), 0) FROM {local_oksigeniaeguahe_signup}
                                          WHERE eventid = :eventid', ['eventid' => $eventid]);
    }

    /**
     * Whether a household can still sign up: the event is open, has not finished and has room for its people.
     *
     * @param \stdClass $event
     * @param int $people
     * @param int $already People the household had signed up already, who do not count twice.
     * @param int $slotid The turn chosen, when the event has turns.
     * @return bool
     */
    public static function has_room(\stdClass $event, int $people, int $already = 0, int $slotid = 0): bool {
        if (!$event->open || $event->timeend < time()) {
            return false;
        }
        $slots = self::slots((int) $event->id);
        if ($slots) {
            // By turns: the turn chosen, not finished, with room.
            $slot = $slots[$slotid] ?? null;
            return $slot && $slot->timeend >= time() && (!$slot->capacity ||
                $slot->people - $already + $people <= $slot->capacity);
        }
        return !$event->capacity || self::people($event->id) - $already + $people <= $event->capacity;
    }

    /**
     * Signs a household up for an event, or changes how many come.
     *
     * @param \stdClass $event
     * @param int $householdid
     * @param int $people
     * @param int $slotid The turn, when the event has turns.
     * @return int The sign-up.
     */
    public static function sign_up(\stdClass $event, int $householdid, int $people, int $slotid = 0): int {
        global $DB;
        $people = max(1, min(20, $people));
        $current = $DB->get_record(self::SIGNUPS, ['eventid' => $event->id, 'householdid' => $householdid]);
        if ($current) {
            $DB->update_record(self::SIGNUPS, (object) ['id' => $current->id, 'people' => $people,
                'slotid' => $slotid ?: (int) $current->slotid]);
            return (int) $current->id;
        }
        return (int) $DB->insert_record(self::SIGNUPS, (object) ['centreid' => $event->centreid,
            'eventid' => $event->id, 'householdid' => $householdid, 'slotid' => $slotid, 'people' => $people,
            'attended' => 0, 'timecreated' => time()]);
    }

    /**
     * When a household is expected: its turn, or the event.
     *
     * @param \stdClass $event
     * @param int $slotid
     * @return \stdClass timestart and timeend.
     */
    public static function when(\stdClass $event, int $slotid): \stdClass {
        global $DB;
        $slot = $slotid ? $DB->get_record(self::SLOTS, ['id' => $slotid, 'eventid' => $event->id]) : null;
        return (object) ['timestart' => (int) ($slot->timestart ?? $event->timestart),
            'timeend' => (int) ($slot->timeend ?? $event->timeend)];
    }

    /**
     * Who signed up for an event, with their adults and children.
     *
     * @param \stdClass $event
     * @return \stdClass[] Sign-ups with the household (adults and children).
     */
    public static function signups(\stdClass $event): array {
        global $DB;
        $signups = $DB->get_records('local_oksigeniaeguahe_signup', ['eventid' => $event->id], 'timecreated ASC');
        if (!$signups) {
            return [];
        }
        $households = households::inbox($event->centreid, false, 0);
        foreach ($signups as $signup) {
            $signup->household = $households[$signup->householdid] ?? null;
        }
        return array_filter($signups, fn($s) => $s->household !== null);
    }

    /**
     * The household came (or, by mistake, it did not): its children's way moves to the visit, unless the event is
     * neither an open day nor a visit (a party, a talk), which is counted apart.
     *
     * @param \stdClass $event
     * @param int $signupid
     * @param bool $came
     */
    public static function attend(\stdClass $event, int $signupid, bool $came): void {
        global $DB;
        $signup = $DB->get_record(
            'local_oksigeniaeguahe_signup',
            ['id' => $signupid, 'eventid' => $event->id],
            '*',
            MUST_EXIST
        );
        $DB->set_field('local_oksigeniaeguahe_signup', 'attended', $came ? time() : 0, ['id' => $signup->id]);
        if ($came && $event->kind !== 'other') {
            households::advance((int) $signup->householdid, 'visit');
        }
    }

    /**
     * The calendar file of an event, for a family to keep it in their phone.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $description In the language of the family.
     * @param \stdClass|null $when The turn of the family (timestart, timeend), when it has one.
     * @return string
     */
    public static function ics(\stdClass $event, \stdClass $centre, string $description, ?\stdClass $when = null): string {
        global $CFG;
        $escape = fn(string $text) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
        $utc = fn(int $time) => gmdate('Ymd\THis\Z', $time);
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost';
        $when = $when ?? $event;
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Oksigenia//Eguahe//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:eguahe-event-' . $event->id . '@' . $host,
            'DTSTAMP:' . $utc(time()),
            'DTSTART:' . $utc((int) $when->timestart),
            'DTEND:' . $utc((int) max($when->timeend, $when->timestart)),
            'SUMMARY:' . $escape($event->name . ' · ' . $centre->name),
            'DESCRIPTION:' . $escape($description),
        ];
        if (!empty($event->place)) {
            $lines[] = 'LOCATION:' . $escape($event->place);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        // Lines of the format are folded at 75 octets.
        $folded = [];
        foreach ($lines as $line) {
            while (strlen($line) > 75) {
                $cut = 75;
                while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                    $cut--;
                }
                $folded[] = substr($line, 0, $cut);
                $line = ' ' . substr($line, $cut);
            }
            $folded[] = $line;
        }
        return implode("\r\n", $folded) . "\r\n";
    }

    /**
     * The QR of the public link of an event, as SVG.
     *
     * @param \stdClass $event
     * @return string
     */
    public static function qr_svg(\stdClass $event): string {
        $qr = new \core_qrcode(self::url($event)->out(false));
        $svg = $qr->getBarcodeSVGcode(6, 6, 'black');
        // Inside a page: without the XML prologue, and scaling to its box.
        $svg = substr($svg, strpos($svg, '<svg'));
        return preg_replace_callback('/<svg width="([\d.]+)" height="([\d.]+)"/', fn($m) =>
            '<svg viewBox="0 0 ' . $m[1] . ' ' . $m[2] . '" role="img" aria-hidden="true"', $svg, 1);
    }
}
