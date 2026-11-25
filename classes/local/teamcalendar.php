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

    /** @var int How much the script may say at once. */
    const MAXBYTES = 3145728;

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
        // Whoever receives, also one the families do not see: it is the calendar of the team.
        $hosts = [];
        foreach (centres::members((int) $centre->id) as $member) {
            $hosts[(int) $member->memberid] = fullname($member);
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
                if (!$household || !$DB->record_exists('local_oksigeniaeguahe_signup', ['eventid' => $event->id])) {
                    // Nobody comes to it any more.
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
                // What it lasts, whatever it is; half an hour when nobody wrote until when.
                'end' => (int) $event->timeend > (int) $event->timestart ? (int) $event->timeend : (int) $event->timestart + 30 * MINSECS,
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
     * The script of Google the centre pastes once, with an account that can write in the calendar of admissions and see the
     * ones to show behind: every ten minutes it says what there is and does what Eguahe answers (calsync). Which calendars
     * are chosen in the settings, with a touch: nothing in it has to be changed.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function script(\stdClass $centre): string {
        $feed = self::url($centre, 'json');
        return <<<JS
// Eguahe and Google Calendar, both ways, every ten minutes.
// 1. In script.google.com, with an account that can change the calendar of admissions: a new project, paste this and save.
// 2. Run «install» once and accept. Which calendars, in the settings of Eguahe.
const EGUAHE = '{$feed}';

function say(body) {
  const answer = UrlFetchApp.fetch(EGUAHE, {method: 'post', contentType: 'application/json', payload: JSON.stringify(body)});
  return JSON.parse(answer.getContentText());
}

function at(date) {
  return Math.floor(date.getTime() / 1000);
}

function sync() {
  // One at a time: two runs at once would write the same one twice.
  const lock = typeof LockService === 'undefined' ? null : LockService.getScriptLock();
  if (lock && !lock.tryLock(1000)) {
    return;
  }
  try {
    while (run()) {
      Utilities.sleep(1000);
    }
  } finally {
    if (lock) {
      lock.releaseLock();
    }
  }
}

function run() {
  const store = PropertiesService.getScriptProperties();
  const last = JSON.parse(store.getProperty('eguahe') || '{"calendar": "", "behind": []}');
  const now = Date.now();
  const from = new Date(now - 30 * 86400000);
  const to = new Date(now + 365 * 86400000);
  const soon = new Date(now + 90 * 86400000);
  const body = {calendar: last.calendar, events: [], behind: [], erased: last.erased || [], calendars: CalendarApp.getAllCalendars().map(function(c) {
    return {id: c.getId(), name: c.getName()};
  })};
  const behind = function(cal, e) {
    const allday = e.isAllDayEvent();
    const day = function(date) {
      return Utilities.formatDate(date, Session.getScriptTimeZone(), 'yyyy-MM-dd');
    };
    body.behind.push({calendar: cal.getName(), title: String(e.getVisibility()) === 'PRIVATE' ? '' : e.getTitle(), allday: allday,
      start: allday ? day(e.getAllDayStartDate()) : at(e.getStartTime()), end: allday ? day(e.getAllDayEndDate()) : at(e.getEndTime())});
  };
  const mine = {};
  const cal = last.calendar ? CalendarApp.getCalendarById(last.calendar) : null;
  if (last.calendar && !cal) {
    throw new Error('The calendar chosen in Eguahe cannot be seen with this account.');
  }
  if (cal) {
    cal.getEvents(from, to).forEach(function(e) {
      const tag = e.getTag('eguahe');
      if (tag) {
        mine[tag] = mine[tag] || [];
        body.events.push({uid: tag, n: mine[tag].length, start: at(e.getStartTime()), end: at(e.getEndTime())});
        mine[tag].push(e);
      } else if (e.getStartTime() < soon && e.getEndTime().getTime() > now - 7 * 86400000) {
        behind(cal, e);
      }
    });
  }
  last.behind.forEach(function(id) {
    const other = CalendarApp.getCalendarById(id);
    if (other) {
      other.getEvents(new Date(now - 7 * 86400000), soon).forEach(function(e) {
        behind(other, e);
      });
    }
  });
  const told = say(body);
  (cal && told.calendar === last.calendar ? told.ops : []).forEach(function(op) {
    const e = (mine[op.uid] || [])[op.n || 0];
    if (op.do === 'delete') {
      if (e) {
        e.deleteEvent();
      }
      return;
    }
    const start = new Date(op.start * 1000);
    const end = new Date(op.end * 1000);
    if (op.do === 'create' && !e) {
      cal.createEvent(op.title, start, end, {location: op.location, description: op.description}).setTag('eguahe', op.uid);
      return;
    }
    if (!e) {
      return;
    }
    if (op.do === 'set') {
      e.setTime(start, end);
    }
    if (e.getTitle() !== op.title) {
      e.setTitle(op.title);
    }
    if (e.getLocation() !== op.location) {
      e.setLocation(op.location);
    }
    if (e.getDescription() !== op.description) {
      e.setDescription(op.description);
    }
  });
  // Families that erased their data: their visits of long ago too.
  const erase = cal && told.calendar === last.calendar ? told.erase || [] : [];
  if (erase.length) {
    cal.getEvents(new Date(now - 1100 * 86400000), from).forEach(function(e) {
      if (erase.indexOf(e.getTag('eguahe')) >= 0) {
        e.deleteEvent();
      }
    });
  }
  store.setProperty('eguahe', JSON.stringify({calendar: told.calendar, behind: told.behind, erased: erase}));
  // Just chosen in Eguahe: at once, without waiting for the next time.
  return told.calendar !== last.calendar || told.behind.join() !== last.behind.join();
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
