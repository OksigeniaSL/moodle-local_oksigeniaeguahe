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

namespace local_oksigeniaeguahe;

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\guide;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\share;

/**
 * What the other plugins of Oksigenia read from Eguahe, never about a family. For now, the open acts of each centre for
 * the screens of Hechauka: an open day, a talk or a festival with open sign-up.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var int How far ahead an open act is told. */
    const AHEAD = 30 * DAYSECS;

    /**
     * The open acts of the centres, under way and coming, for a screen: what, when and where, its turns, the address to
     * sign up (the screen draws its QR) and the guide of the centre with its greeting. For the act of today, how many
     * families have come: a number, never who. Never the practice centre, a cancelled act or a visit for one family.
     *
     * @param int|null $centreid One centre, or all of them.
     * @param int|null $now
     * @return array[] By start: centreid, centre, colour, lang, id, kind, name, place, timestart, timeend, today, turns
     *     (timestart, timeend, full), url, capacity, signed, came (null but today) and guide (img, path of its file on
     *     disk and hello, or null).
     */
    public static function open_acts(?int $centreid = null, ?int $now = null): array {
        global $CFG, $DB;
        $now = $now ?? time();
        $centres = centres::all();
        if ($centreid !== null) {
            $centres = isset($centres[$centreid]) ? [$centreid => $centres[$centreid]] : [];
        }
        if (!$centres) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($centres), SQL_PARAMS_NAMED);
        $events = $DB->get_records_select(
            events::T,
            "centreid $in AND open = 1 AND cancelled = 0 AND timeend > :now AND timestart <= :until",
            $params + ['now' => $now, 'until' => $now + self::AHEAD],
            'timestart ASC, id ASC'
        );
        $midnight = usergetmidnight($now);
        $out = [];
        foreach ($events as $event) {
            if (!share::act_open($event, $now)) {
                continue;
            }
            $centre = $centres[(int) $event->centreid];
            $lang = (string) ($centre->lang ?? 'es');
            $today = (int) $event->timestart < $midnight + DAYSECS;
            $turns = [];
            foreach (events::slots((int) $event->id) as $slot) {
                $turns[] = ['timestart' => (int) $slot->timestart, 'timeend' => (int) $slot->timeend,
                    'full' => (int) $slot->capacity > 0 && (int) $slot->people >= (int) $slot->capacity];
            }
            $kind = guide::of($centre);
            $said = trim((string) ($centre->guidehello ?? ''));
            // Seen from afar: the larger picture; and its file, for screens that show only what they keep themselves.
            $guide = $kind ? ['img' => str_replace('-256.webp', '-512.webp', guide::picture($kind, 'saluda')['src']),
                'path' => $CFG->dirroot . '/local/oksigeniaeguahe/pub/guia/' . $kind . '-saluda-512.webp',
                'hello' => $said !== '' ? format_string($said, true, ['escape' => false]) :
                    publiclang::str('pub_guide_hello', $lang)] : null;
            $out[] = [
                'centreid' => (int) $centre->id,
                'centre' => format_string($centre->name, true, ['escape' => false]),
                'colour' => (string) ($centre->colour ?? ''),
                'lang' => $lang,
                'id' => (int) $event->id,
                'kind' => (string) $event->kind,
                'name' => format_string($event->name, true, ['escape' => false]),
                'place' => format_string((string) $event->place, true, ['escape' => false]),
                'timestart' => (int) $event->timestart,
                'timeend' => (int) $event->timeend,
                'today' => $today,
                'turns' => $turns,
                'url' => events::short_url($event) ?? events::url($event)->out(false),
                'capacity' => (int) $event->capacity,
                'signed' => events::people((int) $event->id),
                'came' => $today ? (int) $DB->count_records_select(
                    events::SIGNUPS,
                    'eventid = ? AND attended > 0',
                    [$event->id]
                ) : null,
                'guide' => $guide,
            ];
        }
        return $out;
    }

    /**
     * The visits of today, for a screen at the door of a centre where visitors wait (Hechauka, «La visita de hoy»): an
     * hour before, «Today Ana welcomes you», with her face and her role; on a touch, how long it lasts and where it goes.
     * Visits made for one family and the open days with their turns; never the act where families ask for a visit of
     * their own (it has no hour, only the time to ask), a name of a family nor anything of theirs, only how many signed
     * up and how many came.
     *
     * @param int|null $centreid One centre, or every one of the site.
     * @param int|null $now
     * @return array A list, by when they start, each with centreid, centre, colour, lang, id, kind, tailored (made for one
     *     family), name, place, timestart, timeend, minutes, turns (timestart, timeend, minutes), hosts (name, first,
     *     role, says, photo: its URL, photofile: its stored_file, for screens that keep their own), signed (people),
     *     came (households that came), where (how far the guide goes with them, as the centre wrote it), miniature (the
     *     URL of the centre's drawn plan, or null) and miniaturefile (its stored_file, or null).
     */
    public static function visits_today(?int $centreid = null, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $centres = centres::all();
        if ($centreid !== null) {
            $centres = isset($centres[$centreid]) ? [$centreid => $centres[$centreid]] : [];
        }
        // The practice centre of the helps and the centres of visitors to the demo are not real centres.
        $centres = array_filter($centres, fn($c) => empty($c->practice));
        if (!$centres) {
            return [];
        }
        $midnight = usergetmidnight($now);
        [$in, $params] = $DB->get_in_or_equal(array_keys($centres), SQL_PARAMS_NAMED);
        $events = $DB->get_records_select(
            events::T,
            "centreid $in AND cancelled = 0 AND kind IN ('openday', 'visit') AND timestart < :end AND timeend > :start
             AND (tailored = 0 OR householdid > 0)",
            $params + ['start' => $midnight, 'end' => $midnight + DAYSECS],
            'timestart ASC, id ASC'
        );
        $context = \context_system::instance()->id;
        $out = [];
        foreach ($events as $event) {
            $centre = $centres[(int) $event->centreid];
            $hosts = [];
            $ids = array_filter(array_map('intval', explode(',', (string) $event->hosts)));
            foreach (centres::hosts((int) $centre->id, $ids) as $host) {
                $hosts[] = ['name' => $host->name, 'first' => $host->first, 'role' => $host->role, 'says' => $host->says,
                    'photo' => $host->photo ? $host->photo->out(false) : null,
                    'photofile' => centres::host_photo_file((int) $host->memberid)];
            }
            $turns = [];
            foreach (events::slots((int) $event->id) as $slot) {
                $turns[] = ['timestart' => (int) $slot->timestart, 'timeend' => (int) $slot->timeend,
                    'minutes' => (int) round(((int) $slot->timeend - (int) $slot->timestart) / MINSECS)];
            }
            $where = trim((string) ($centre->guidewhere ?? ''));
            $files = get_file_storage()->get_area_files(
                $context,
                'local_oksigeniaeguahe',
                'miniature',
                (int) $centre->id,
                'filename',
                false
            );
            $miniature = centres::miniature((int) $centre->id);
            $out[] = [
                'centreid' => (int) $centre->id,
                'centre' => format_string($centre->name, true, ['escape' => false]),
                'colour' => (string) ($centre->colour ?? ''),
                'lang' => (string) ($centre->lang ?? 'es'),
                'id' => (int) $event->id,
                'kind' => (string) $event->kind,
                'tailored' => !empty($event->householdid),
                'name' => format_string($event->name, true, ['escape' => false]),
                'place' => format_string((string) $event->place, true, ['escape' => false]),
                'timestart' => (int) $event->timestart,
                'timeend' => (int) $event->timeend,
                'minutes' => (int) round(((int) $event->timeend - (int) $event->timestart) / MINSECS),
                'turns' => $turns,
                'hosts' => $hosts,
                'signed' => events::people((int) $event->id),
                'came' => (int) $DB->count_records_select(events::SIGNUPS, 'eventid = ? AND attended > 0', [$event->id]),
                'where' => $where !== '' ? format_string($where, true, ['escape' => false]) : '',
                'miniature' => $miniature ? $miniature->out(false) : null,
                'miniaturefile' => reset($files) ?: null,
            ];
        }
        return $out;
    }
    /**
     * The families a person of the team may start a conversation with, by name: for the conversations of Marandu,
     * which already find the families of the common record and the contacts of the chat, and not the ones that only
     * ask for a place. Each word written starts a word of the name of an adult or a child of the family, without
     * accents. Only the centres of that person (never the practice ones), only families that came through Eguahe (the
     * ones from the roll are in the common record), and only adults with a phone.
     *
     * @param int $userid
     * @param string $query
     * @param int $limit
     * @return array A list of name, phone (E.164) and line (what the family is: who it asks a place for, and the course).
     */
    public static function chat_candidates(int $userid, string $query, int $limit): array {
        global $DB;
        $plain = fn(string $text) => trim(preg_replace('/[^a-z0-9]+/', ' ', \core_text::strtolower(
            (string) \core_text::specialtoascii($text)
        )));
        $words = array_values(array_filter(explode(' ', $plain($query))));
        $centres = array_filter(centres::for_user($userid), fn($c) => !centres::is_practice((int) $c->id));
        if (!$words || !$centres || $limit < 1) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($centres), SQL_PARAMS_NAMED);
        $homes = $DB->get_records_select(
            households::T,
            "centreid $in AND roster = 0",
            $params,
            'lastcontact DESC',
            'id, centreid, incentre'
        );
        if (!$homes) {
            return [];
        }
        $adults = [];
        $names = [];
        foreach (
            $DB->get_records_select(
                households::ADULTS,
                "centreid $in",
                $params,
                'id ASC',
                'id, householdid, firstname, lastname, phone'
            ) as $adult
        ) {
            $adults[$adult->householdid][] = $adult;
            $names[$adult->householdid][] = $adult->firstname . ' ' . $adult->lastname;
        }
        $children = [];
        foreach (
            $DB->get_records_select(
                households::CHILDREN,
                "centreid $in",
                $params,
                'id ASC',
                'id, householdid, firstname, tagname, offerid, stage'
            ) as $child
        ) {
            $children[$child->householdid][] = $child;
            $names[$child->householdid][] = $child->firstname . ' ' . $child->tagname;
        }
        $offers = [];
        foreach ($centres as $centre) {
            foreach (centres::offers((int) $centre->id) as $offer) {
                $offers[(int) $offer->id] = format_string($offer->name);
            }
        }
        $out = [];
        foreach ($homes as $home) {
            $said = ' ' . $plain(implode(' ', $names[$home->id] ?? [])) . ' ';
            foreach ($words as $word) {
                if (strpos($said, ' ' . $word) === false) {
                    continue 2;
                }
            }
            // What the family is, in a line: who it asks a place for, with the course.
            $asking = [];
            foreach ($children[$home->id] ?? [] as $child) {
                if (!in_array($child->stage, ['enrolled', 'closed'], true)) {
                    $name = trim((string) $child->firstname) !== '' ? format_string($child->firstname) :
                        get_string('chat_child_noname', 'local_oksigeniaeguahe');
                    $asking[] = $name . (isset($offers[(int) $child->offerid]) ? ' (' . $offers[(int) $child->offerid] . ')' : '');
                }
            }
            $line = $asking ? get_string('chat_candidate_asking', 'local_oksigeniaeguahe', implode(', ', $asking)) :
                get_string($home->incentre ? 'chat_kind_centre' : 'chat_candidate_family', 'local_oksigeniaeguahe');
            foreach ($adults[$home->id] ?? [] as $adult) {
                if ((string) $adult->phone === '') {
                    continue;
                }
                $out[] = ['name' => trim(format_string($adult->firstname) . ' ' . format_string((string) $adult->lastname)),
                    'phone' => (string) $adult->phone, 'line' => $line];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }
}
