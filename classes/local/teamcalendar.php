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
 * The calendar of the team: the visits and the acts of admissions of a centre, with their time, in the calendar the team
 * already shares, so whoever looks at it sees whether a visit falls on something else (an evaluation, an act of the
 * school). A private link per centre (cal.php): as a calendar file for Outlook, Apple or any calendar that subscribes to
 * an address, and as data for a short script of Google that copies them into a calendar of Google every ten minutes,
 * with their changes and what was cancelled. A new link makes the one before stop.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teamcalendar {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int What is shown before today: a month, for whoever looks back. */
    const BEFORE = 30 * DAYSECS;

    /** @var int What is shown after today: a year. */
    const AFTER = 365 * DAYSECS;

    /**
     * The secret of the link of a centre, made the first time it is asked for.
     *
     * @param int $centreid
     * @param bool $renew A new one: the link before stops working.
     * @return string
     */
    public static function key(int $centreid, bool $renew = false): string {
        $name = 'calkey_' . $centreid;
        $key = (string) get_config(self::C, $name);
        if ($key === '' || $renew) {
            $key = bin2hex(random_bytes(16));
            set_config($name, $key, self::C);
        }
        return $key;
    }

    /**
     * Whether a key is the one of a centre.
     *
     * @param int $centreid
     * @param string $key
     * @return bool
     */
    public static function key_ok(int $centreid, string $key): bool {
        $kept = (string) get_config(self::C, 'calkey_' . $centreid);
        return $kept !== '' && $key !== '' && hash_equals($kept, $key);
    }

    /**
     * The private link of the calendar of a centre.
     *
     * @param \stdClass $centre
     * @param string $format ics or json.
     * @return string
     */
    public static function url(\stdClass $centre, string $format = 'ics'): string {
        return (new \moodle_url('/local/oksigeniaeguahe/cal.php', ['c' => $centre->id, 'k' => self::key((int) $centre->id)] +
            ($format === 'json' ? ['f' => 'json'] : [])))->out(false);
    }

    /**
     * The visits and acts of a centre in the window, as the calendar shows them.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array Each with uid, title, start, end, location, description and url.
     */
    public static function items(\stdClass $centre, int $now = 0): array {
        global $DB;
        $c = self::C;
        $now = $now ?: time();
        $events = $DB->get_records_select(
            'local_oksigeniaeguahe_event',
            'centreid = :centreid AND cancelled = 0 AND timestart > 0 AND timestart >= :from AND timestart <= :to
             AND NOT (tailored = 1 AND COALESCE(householdid, 0) = 0)',
            ['centreid' => $centre->id, 'from' => $now - self::BEFORE, 'to' => $now + self::AFTER],
            'timestart ASC'
        );
        $hosts = [];
        foreach (centres::hosts((int) $centre->id) as $host) {
            $hosts[(int) $host->memberid] = $host->name;
        }
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name');
        $address = trim((string) ($centre->address ?? ''));
        $items = [];
        foreach ($events as $event) {
            $who = [];
            foreach (array_filter(explode(',', (string) $event->hosts)) as $id) {
                if (!empty($hosts[(int) $id])) {
                    $who[] = $hosts[(int) $id];
                }
            }
            $lines = [];
            if ((int) $event->householdid) {
                // A visit of one family: who they are and who would come.
                $household = households::get((int) $event->householdid, (int) $centre->id);
                if (!$household) {
                    continue;
                }
                $full = households::full($household);
                $family = panel::adults_line($full->adults) ?: get_string('chat_family_noname', $c);
                $title = get_string('cal_visit_title', $c, $family);
                $children = [];
                foreach ($full->children as $child) {
                    if (in_array($child->stage, ['enrolled', 'closed'], true)) {
                        continue;
                    }
                    $children[] = (familypage::label_name($child) ?: get_string('chat_child_noname', $c)) .
                        (isset($offers[$child->offerid]) ? ' (' . format_string($offers[$child->offerid]) . ')' : '');
                }
                if ($children) {
                    $lines[] = get_string('cal_children', $c, implode(', ', $children));
                }
                $url = new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
            } else {
                // An act: how many families come, and by turn when it has turns.
                $signups = $DB->get_records('local_oksigeniaeguahe_signup', ['eventid' => $event->id], '', 'id, slotid');
                $n = count($signups);
                $title = get_string('cal_act_title', $c, (object) ['name' => format_string($event->name),
                    'n' => get_string($n === 1 ? 'cal_families_one' : 'cal_families', $c, $n)]);
                $slots = $DB->get_records('local_oksigeniaeguahe_slot', ['eventid' => $event->id], 'timestart ASC');
                foreach ($slots as $slot) {
                    $k = count(array_filter($signups, fn($s) => (int) $s->slotid === (int) $slot->id));
                    $lines[] = get_string('cal_turn', $c, (object) ['time' => panel::hour((int) $slot->timestart) . '–' .
                        panel::hour((int) $slot->timeend), 'n' => get_string($k === 1 ? 'cal_families_one' : 'cal_families', $c, $k)]);
                }
                $url = new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]);
            }
            if ($who) {
                array_unshift($lines, get_string('cal_hosts', $c, implode(', ', $who)));
            }
            $lines[] = get_string('cal_open', $c, $url->out(false));
            $items[] = [
                'uid' => 'eguahe-' . $centre->id . '-' . $event->id,
                'title' => $title,
                'start' => (int) $event->timestart,
                'end' => max((int) $event->timeend, (int) $event->timestart + HOURSECS / 2),
                'location' => implode(', ', array_filter([$event->place ? format_string($event->place) : '', $address])),
                'description' => implode("\n", $lines),
                'updated' => (int) ($event->timemodified ?? $event->timecreated),
            ];
        }
        return $items;
    }

    /**
     * The calendar file of the team: a calendar of its own that any calendar can subscribe to.
     *
     * @param \stdClass $centre
     * @param array $items From items().
     * @return string
     */
    public static function ics(\stdClass $centre, array $items): string {
        global $CFG;
        $escape = fn(string $text) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
        $utc = fn(int $time) => gmdate('Ymd\THis\Z', $time);
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost';
        $name = get_string('cal_name', self::C, format_string($centre->name));
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . $escape(str_replace('/', ' ', $name)) . '//Calendar//ES',
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . $escape($name), 'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H'];
        foreach ($items as $item) {
            array_push(
                $lines,
                'BEGIN:VEVENT',
                'UID:' . $item['uid'] . '@' . $host,
                'DTSTAMP:' . $utc(time()),
                'LAST-MODIFIED:' . $utc($item['updated']),
                'DTSTART:' . $utc($item['start']),
                'DTEND:' . $utc($item['end']),
                'SUMMARY:' . $escape($item['title']),
                'LOCATION:' . $escape($item['location']),
                'DESCRIPTION:' . $escape($item['description']),
                'STATUS:CONFIRMED',
                'TRANSP:OPAQUE',
                'END:VEVENT'
            );
        }
        $lines[] = 'END:VCALENDAR';
        // Lines of the format are folded at 75 octets, never inside a character.
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
     * The data for the script of Google: the window it keeps and what is in it.
     *
     * @param \stdClass $centre
     * @param array $items From items().
     * @param int $now
     * @return array
     */
    public static function data(\stdClass $centre, array $items, int $now = 0): array {
        $now = $now ?: time();
        return ['calendar' => get_string('cal_name', self::C, format_string($centre->name)), 'from' => $now - self::BEFORE,
            'to' => $now + self::AFTER, 'now' => $now, 'items' => $items];
    }

    /**
     * The script of Google the centre pastes once in the account of its calendar (script.google.com): every ten minutes
     * it copies the visits and acts into that calendar, changes what changed and takes out of the days to come what is
     * no longer there. Each event it makes carries its tag, and it never touches another one.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function script(\stdClass $centre): string {
        $feed = self::url($centre, 'json');
        return <<<JS
// Eguahe → Google Calendar: the visits and acts of admissions, every ten minutes.
// 1. In script.google.com, with the account of the calendar: a new project, paste this and save.
// 2. Below, the calendar: 'primary' is the main one of the account; for another one, its ID (its settings,
//    «Integrate calendar»).
// 3. Run «install» once and accept: from then on it goes by itself.
const FEED = '{$feed}';
const CALENDAR = 'primary';

function sync() {
  const data = JSON.parse(UrlFetchApp.fetch(FEED).getContentText());
  const cal = CALENDAR === 'primary' ? CalendarApp.getDefaultCalendar() : CalendarApp.getCalendarById(CALENDAR);
  const now = new Date(data.now * 1000);
  const mine = {};
  cal.getEvents(new Date(data.from * 1000), new Date(data.to * 1000)).forEach(function(e) {
    const tag = e.getTag('eguahe');
    if (tag) {
      mine[tag] = e;
    }
  });
  const seen = {};
  data.items.forEach(function(it) {
    seen[it.uid] = true;
    const start = new Date(it.start * 1000);
    const end = new Date(it.end * 1000);
    const e = mine[it.uid];
    if (!e) {
      cal.createEvent(it.title, start, end, {location: it.location, description: it.description}).setTag('eguahe', it.uid);
      return;
    }
    if (e.getTitle() !== it.title) {
      e.setTitle(it.title);
    }
    if (e.getStartTime().getTime() !== start.getTime() || e.getEndTime().getTime() !== end.getTime()) {
      e.setTime(start, end);
    }
    if (e.getLocation() !== it.location) {
      e.setLocation(it.location);
    }
    if (e.getDescription() !== it.description) {
      e.setDescription(it.description);
    }
  });
  Object.keys(mine).forEach(function(tag) {
    if (!seen[tag] && mine[tag].getStartTime() > now) {
      mine[tag].deleteEvent();
    }
  });
}

function install() {
  ScriptApp.getProjectTriggers().forEach(function(t) {
    ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('sync').timeBased().everyMinutes(10).create();
  sync();
}
JS;
    }
}
