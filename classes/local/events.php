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
     * @var array How the QR the shortener draws is asked for, the same in every product of Oksigenia: round dots, square
     * corners, in black, nothing in the middle, on white and with no frame, what every reader reads (Hechauka and the art
     * director, 8 November 2026).
     */
    const QRSTYLE = ['puntos' => 'circulos', 'esquinas' => 'cuadradas', 'color' => 'oscuro', 'centro' => 'nada',
        'fondo' => 'blanco', 'marco' => 'no'];

    /** @var int The largest picture of a QR taken from the shortener, in bytes. */
    const QRMOST = 300000;

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
                                               e.token, e.open, e.cancelled, e.timecreated, e.timemodified
                                      ORDER BY e.timestart DESC', ['centreid' => $centreid]);
        $now = time();
        // A visit of its own replaced by another is gone: only the one the family has now.
        $events = array_filter($events, fn($e) => !((int) ($e->householdid ?? 0) && (int) ($e->cancelled ?? 0)));
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
            // A visit of its own: families say when it suits them, and the team proposes the day.
            'tailored' => !empty($data->tailored) && ($data->kind ?? '') === 'visit' ? 1 : 0,
            // Only a form of this centre.
            'formid' => !empty($data->formid) && entries::get((int) $data->formid, $centreid) ? (int) $data->formid : 0,
            'timemodified' => time(),
        ];
        if (isset($data->parking)) {
            // A big act: the miniature of the centre shows where to park.
            $row->parking = empty($data->parking) ? 0 : 1;
        }
        if (isset($data->survey)) {
            // The survey after the visit: one of the centre, or «How it went» made now (-1).
            $survey = (int) $data->survey === -1 ? surveys::create($centreid, 'howitwent') : (int) $data->survey;
            $entry = $survey > 0 ? entries::get($survey, $centreid) : null;
            $row->survey = $entry && $entry->kind === surveys::KIND ? $survey : 0;
        }
        if (isset($data->hosts)) {
            // Only members of the team the centre shows to the families, in the order chosen.
            $public = array_column(centres::hosts($centreid), 'memberid');
            $hosts = array_values(array_intersect(array_map('intval', (array) $data->hosts), $public));
            $row->hosts = $hosts ? implode(',', array_slice($hosts, 0, 8)) : null;
        }
        if (!empty($data->id)) {
            $was = self::get((int) $data->id, $centreid) ?? throw new \moodle_exception('invalidrecord', 'error');
            $row->id = (int) $data->id;
            $DB->update_record(self::T, $row);
            // Another day or hour: the reminder of the day before goes again, for the new day.
            if ((int) $was->timestart !== (int) $row->timestart) {
                $DB->set_field(self::SIGNUPS, 'reminded', 0, ['eventid' => $row->id, 'slotid' => 0]);
            }
        } else {
            $row->token = self::new_token();
            $row->timecreated = $row->timemodified;
            $row->id = (int) $DB->insert_record(self::T, $row);
        }
        if (isset($data->slots) && is_array($data->slots)) {
            self::save_slots(self::get($row->id, $centreid), $data->slots);
        }
        // The moments of an open act in the channel of the organisation, changed, or taken back (with Marandu).
        channel::sync(self::get($row->id, $centreid));
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
                $before = (int) $DB->get_field(self::SLOTS, 'timestart', ['id' => $id]);
                $DB->update_record(self::SLOTS, $row);
                if ($before !== (int) $row->timestart) {
                    // The turn moved: its families are reminded again, for the new hour.
                    $DB->set_field(self::SIGNUPS, 'reminded', 0, ['slotid' => $id]);
                }
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

    /** @var int How many people one sign-up may bring. */
    const MAXPEOPLE = 10;

    /** @var int Sign-ups to one act from one address of the internet in an hour; more look like a flood. */
    const PERHOUR = 20;

    /**
     * The lock of an act while a family signs up: two sending at once never take its last place twice. Null when the
     * site cannot give one in time; the sign-up goes on, as before.
     *
     * @param \stdClass $event
     * @return \core\lock\lock|null
     */
    public static function lock(\stdClass $event): ?\core\lock\lock {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_oksigeniaeguahe_signup');
            return $factory->get_lock('event' . (int) $event->id, 10) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Counts a sign-up sent from an address to an act, and says whether it is still within the hour's allowance.
     *
     * @param \stdClass $event
     * @param string $ip
     * @return bool
     */
    public static function allowed_from(\stdClass $event, string $ip): bool {
        $cache = \cache::make('local_oksigeniaeguahe', 'signupsfrom');
        $key = sha1($ip . '|' . (int) $event->id . '|' . get_site_identifier());
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, $count);
        return $count <= self::PERHOUR;
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
        $people = max(1, min(self::MAXPEOPLE, $people));
        $current = $DB->get_record(self::SIGNUPS, ['eventid' => $event->id, 'householdid' => $householdid]);
        if ($current) {
            $slot = $slotid ?: (int) $current->slotid;
            // Another turn: the reminder goes again, for its hour.
            $DB->update_record(self::SIGNUPS, (object) (['id' => $current->id, 'people' => $people, 'slotid' => $slot] +
                ($slot !== (int) $current->slotid ? ['reminded' => 0] : [])));
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
        // Only the families signed up, never every family of the centre.
        $households = households::some((int) $event->centreid, array_map(fn($s) => (int) $s->householdid, $signups));
        foreach ($signups as $signup) {
            $signup->household = $households[$signup->householdid] ?? null;
        }
        return array_filter($signups, fn($s) => $s->household !== null);
    }

    /**
     * The centre calls an act off: each family signed up is told (by email, if it lets the centre write so), a line stays
     * in its history, and its sign-up goes, so that no reminder and no thanks go out. The act stays, called off and
     * closed.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid Who calls it off.
     * @param string $message What the team adds for the families, if anything.
     * @param int $now
     * @return int How many families were told by email.
     */
    public static function cancel(\stdClass $event, \stdClass $centre, int $userid, string $message, int $now): int {
        global $DB;
        $message = \core_text::substr(trim($message), 0, 2000);
        $told = 0;
        foreach ($DB->get_records(self::SIGNUPS, ['eventid' => $event->id]) as $row) {
            $household = households::get((int) $row->householdid, (int) $centre->id);
            if (!$household) {
                continue;
            }
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            $signup = familymail::signup($household, (int) $event->id);
            $sent = $signup && familymail::may_email($household) && familymail::send(
                'cancelled',
                $household,
                $centre,
                $signup,
                $lang,
                households::new_link($household, 'cancelled'),
                null,
                $message
            );
            if ($sent) {
                $told++;
                households::told($household, $userid, 'email', get_string(
                    'event_cancelled_line',
                    'local_oksigeniaeguahe',
                    format_string($event->name)
                ) . ($message !== '' ? "\n\n" . $message : ''), true);
            } else {
                // No email for this family: the team reads it on its record and tells them some other way.
                households::note($household, $userid, get_string(
                    'event_cancelled_unreached',
                    'local_oksigeniaeguahe',
                    format_string($event->name)
                ));
            }
            $DB->delete_records(self::SIGNUPS, ['id' => $row->id]);
        }
        $DB->update_record(self::T, (object) ['id' => $event->id, 'cancelled' => $now, 'open' => 0, 'timemodified' => $now]);
        // Out of the centre's channel too, also what was already posted.
        channel::sync(self::get((int) $event->id, (int) $centre->id), $now);
        return $told;
    }

    /**
     * A family is not coming to an act after all: its sign-up goes, and a line stays in its history.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @param string $line What stays in its history.
     * @param bool $awaits Whether it waits for a person (the family said it).
     * @return bool
     */
    public static function unsign(\stdClass $event, \stdClass $household, string $line, bool $awaits): bool {
        global $DB;
        $row = $DB->get_record(self::SIGNUPS, ['eventid' => $event->id, 'householdid' => $household->id]);
        if (!$row) {
            return false;
        }
        $DB->delete_records(self::SIGNUPS, ['id' => $row->id]);
        if ($awaits) {
            households::received($household, lists::PAGE, $line, true);
        } else {
            households::note($household, 0, $line);
        }
        return true;
    }

    /**
     * The household came (or, by mistake, it did not): its children's way moves to the visit, unless the event is
     * neither an open day nor a visit (a party, a talk), which is counted apart.
     *
     * @param \stdClass $event
     * @param int $signupid
     * @param bool $came
     * @param int $when When it was marked (at the door without signal, the hour it was marked); 0, now.
     */
    public static function attend(\stdClass $event, int $signupid, bool $came, int $when = 0): void {
        global $DB;
        $signup = $DB->get_record(
            'local_oksigeniaeguahe_signup',
            ['id' => $signupid, 'eventid' => $event->id],
            '*',
            MUST_EXIST
        );
        // A mark from the phone keeps its hour if it is of the last day; otherwise, now.
        $when = $when > time() - DAYSECS && $when <= time() + MINSECS ? $when : time();
        $DB->set_field('local_oksigeniaeguahe_signup', 'attended', $came ? $when : 0, ['id' => $signup->id]);
        if ($came && !(int) $signup->attended && $event->kind !== 'other') {
            // What each child was before, so that taking the mark back takes the way back too.
            $was = [];
            foreach ($DB->get_records(households::CHILDREN, ['householdid' => $signup->householdid]) as $child) {
                if (lists::further((string) $child->stage, 'visit')) {
                    $was[] = (int) $child->id . ':' . $child->stage;
                }
            }
            $DB->set_field(self::SIGNUPS, 'wasstages', $was ? implode(',', $was) : null, ['id' => $signup->id]);
            households::advance((int) $signup->householdid, 'visit');
        } else if (!$came && (int) $signup->attended) {
            // A mark by mistake: each child that is still where the mark left it goes back to where it was.
            foreach (array_filter(explode(',', (string) ($signup->wasstages ?? ''))) as $pair) {
                [$childid, $stage] = array_pad(explode(':', $pair, 2), 2, '');
                $child = $DB->get_record(households::CHILDREN, ['id' => (int) $childid, 'householdid' => $signup->householdid]);
                if ($child && $child->stage === 'visit' && in_array($stage, lists::STAGES, true)) {
                    households::set_stage($child, $stage);
                }
            }
            $DB->set_field(self::SIGNUPS, 'wasstages', null, ['id' => $signup->id]);
        }
    }

    /**
     * The calendar file of a visit (DESIGN.md of the sendings): in the name of the centre, never of Eguahe; published,
     * not an invitation to accept; one fixed identifier per family and visit and a sequence that grows with each change,
     * so sending it again updates the event; an alarm two hours before. Times in UTC: the calendar shows them in the
     * time of whoever opens it.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $description
     * @param \stdClass|null $when The turn of the family, if the event has turns.
     * @param array $extra uid, url and alarm (its text), when known.
     * @return string
     */
    public static function ics(
        \stdClass $event,
        \stdClass $centre,
        string $description,
        ?\stdClass $when = null,
        array $extra = []
    ): string {
        global $CFG;
        $escape = fn(string $text) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
        $utc = fn(int $time) => gmdate('Ymd\THis\Z', $time);
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost';
        $when = $when ?? $event;
        $name = format_string($centre->name);
        $location = implode(', ', array_filter([$name, $event->place ? format_string($event->place) : '',
            (string) ($centre->address ?? '')]));
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . $escape(str_replace('/', ' ', $name)) . '//Visitas//' . strtoupper(substr((string) $centre->lang, 0, 2)),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . ($extra['uid'] ?? 'visita-' . $event->id) . '@' . $host,
            'DTSTAMP:' . $utc(time()),
            'SEQUENCE:' . max(0, (int) ($event->timemodified ?? 0) - (int) ($event->timecreated ?? 0)),
            'DTSTART:' . $utc((int) $when->timestart),
            'DTEND:' . $utc((int) max($when->timeend, $when->timestart)),
            'SUMMARY:' . $escape(format_string($event->name) . ' · ' . $name),
            'LOCATION:' . $escape($location),
            'DESCRIPTION:' . $escape($description),
        ];
        if (!empty($extra['url'])) {
            $lines[] = 'URL:' . $extra['url'];
        }
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        if (!empty($extra['alarm'])) {
            array_push(
                $lines,
                'BEGIN:VALARM',
                'ACTION:DISPLAY',
                'TRIGGER:-PT2H',
                'DESCRIPTION:' . $escape($extra['alarm']),
                'END:VALARM'
            );
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
     * The short link of the public sign-up of an event, for the poster, its QR and the texts: asked once to the
     * shortener of the site, with a word that reads («visita-2111»), and kept. Null without a shortener.
     *
     * @param \stdClass $event
     * @return string|null
     */
    public static function short_url(\stdClass $event): ?string {
        global $DB;
        if (!empty($event->shorturl)) {
            return $event->shorturl;
        }
        $shortener = shortener::of_site();
        if (!$shortener) {
            return null;
        }
        $centre = centres::get((int) $event->centreid);
        $title = get_string('short_title', 'local_oksigeniaeguahe', (object) ['centre' => $centre ? format_string($centre->name) :
            '', 'event' => format_string($event->name)]);
        $link = $shortener->link(
            self::url($event)->out(false),
            $title,
            true,
            'visita-' . userdate((int) $event->timestart, '%d%m')
        );
        if (!$link) {
            return null;
        }
        $event->shorturl = \core_text::substr($link['short'], 0, 255);
        $DB->set_field(self::T, 'shorturl', $event->shorturl, ['id' => $event->id]);
        self::keep_qr($event, $link['qrsvg']);
        return $event->shorturl;
    }

    /**
     * The QR the shortener draws for the short link of an event, kept with it: asked once, and for a short link made
     * before, asked again once. Empty when the shortener does not draw them (a plain YOURLS).
     *
     * @param \stdClass $event
     * @return string SVG markup, to show as a picture.
     */
    public static function designed_qr(\stdClass $event): string {
        if (self::short_url($event) === null) {
            return '';
        }
        if (!isset($event->shortqr)) {
            $shortener = shortener::of_site();
            $link = $shortener ? $shortener->link(self::url($event)->out(false)) : null;
            if ($link) {
                self::keep_qr($event, $link['qrsvg']);
            }
        }
        return (string) ($event->shortqr ?? '');
    }

    /**
     * Brings the QR the shortener drew, in the common style, and keeps it with the event: '' when it draws none. When it
     * cannot be brought now, nothing is kept, and it is asked another time.
     *
     * @param \stdClass $event
     * @param string $address Where the shortener draws it, or ''.
     */
    protected static function keep_qr(\stdClass $event, string $address): void {
        global $CFG, $DB;
        $svg = '';
        if ($address !== '') {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl(['ignoresecurity' => false]);
            $curl->setopt(['CURLOPT_TIMEOUT' => 2 * shortener::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => shortener::TIMEOUT,
                'CURLOPT_MAXREDIRS' => 0, 'CURLOPT_FOLLOWLOCATION' => false]);
            $url = $address . (strpos($address, '?') === false ? '?' : '&') . http_build_query(self::QRSTYLE, '', '&');
            try {
                $svg = (string) $curl->get($url);
            } catch (\Throwable $error) {
                return;
            }
            $info = $curl->get_info();
            $code = is_array($info) && isset($info['http_code']) ? (int) $info['http_code'] : 200;
            $start = ltrim(substr($svg, 0, 512));
            if (
                $code !== 200 || $curl->get_errno() || strlen($svg) > self::QRMOST || strpos($svg, '<svg') === false ||
                (strpos($start, '<svg') !== 0 && strpos($start, '<?xml') !== 0)
            ) {
                return;
            }
        }
        $DB->set_field(self::T, 'shortqr', $svg, ['id' => $event->id]);
        $event->shortqr = $svg;
    }

    /**
     * The QR of the public link of an event: the one the shortener draws, as a picture, else one drawn here, as SVG.
     *
     * @param \stdClass $event
     * @return string HTML.
     */
    public static function qr_svg(\stdClass $event): string {
        $designed = self::designed_qr($event);
        if ($designed !== '') {
            // A picture: nothing in it runs.
            return \html_writer::empty_tag('img', ['src' => 'data:image/svg+xml;base64,' . base64_encode($designed),
                'alt' => '']);
        }
        // The short link, when there is one: fewer modules, read from further and through a photocopy.
        return self::qr_of(self::short_url($event) ?? self::url($event)->out(false));
    }

    /**
     * The QR of any link, to put inside a page.
     *
     * @param string $url
     * @return string SVG.
     */
    public static function qr_of(string $url): string {
        $qr = new \core_qrcode($url);
        $svg = $qr->getBarcodeSVGcode(6, 6, 'black');
        // Inside a page: without the XML prologue, and scaling to its box.
        $svg = substr($svg, strpos($svg, '<svg'));
        return preg_replace_callback('/<svg width="([\d.]+)" height="([\d.]+)"/', fn($m) =>
            '<svg viewBox="0 0 ' . $m[1] . ' ' . $m[2] . '" role="img" aria-hidden="true"', $svg, 1);
    }
}
