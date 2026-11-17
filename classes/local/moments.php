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
 * Each function at its moment: when its time comes, the inbox says it among the work of today, with the way in, and it
 * goes once it is done or someone says it is known. An open act near without its kit for the networks; the first family
 * with a place, while it is not said who makes their common record; and, before the next school year, its offer, which
 * asking who goes on needs.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moments {
    /** @var string Component. */
    const C = 'local_oksigeniaeguahe';

    /** @var int How long before an open act its kit is offered. */
    const KIT_BEFORE = 21 * DAYSECS;

    /** @var int How long before the next school year begins its offer is asked for. */
    const OFFER_BEFORE = 150 * DAYSECS;

    /** @var string The setting that keeps, for each centre, the moments someone said were known. */
    const KNOWN = 'momentsknown_';

    /**
     * The moments of a centre now, as notes of the work of today: title, text, url, link, and the key to say it is known.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array[]
     */
    public static function due(\stdClass $centre, int $now): array {
        if (!empty($centre->practice)) {
            return [];
        }
        $known = self::known((int) $centre->id);
        $out = [];
        foreach ([self::kit($centre, $now), self::family($centre), self::offer($centre, $now)] as $moment) {
            if ($moment && !in_array($moment['key'], $known, true)) {
                $out[] = $moment;
            }
        }
        return $out;
    }

    /**
     * Says a moment is known: it is not said again.
     *
     * @param int $centreid
     * @param string $key
     */
    public static function know(int $centreid, string $key): void {
        $known = self::known($centreid);
        if (!preg_match('/^[a-z]+(-[0-9]{1,10}(-[0-9]{4}(-[0-9]{2,4})?)?)?$/', $key) || in_array($key, $known, true)) {
            return;
        }
        // The last ones are enough: the key of an act or of a school year that passed is not asked again.
        $known = array_slice(array_merge($known, [$key]), -60);
        set_config(self::KNOWN . $centreid, implode(',', $known), self::C);
    }

    /**
     * The moments of a centre someone said were known.
     *
     * @param int $centreid
     * @return string[]
     */
    protected static function known(int $centreid): array {
        $value = (string) get_config(self::C, self::KNOWN . $centreid);
        return $value === '' ? [] : explode(',', $value);
    }

    /**
     * The next open act of the coming weeks whose kit nobody has opened.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array|null
     */
    protected static function kit(\stdClass $centre, int $now): ?array {
        global $DB;
        $events = $DB->get_records_select(
            events::T,
            'centreid = ? AND open = 1 AND cancelled = 0 AND timestart > ? AND timestart <= ?',
            [$centre->id, $now, $now + self::KIT_BEFORE],
            'timestart ASC'
        );
        foreach ($events as $event) {
            if (!kit::has($event, $now) || !empty(kit::kept($event)['seen'])) {
                continue;
            }
            return ['key' => 'kit-' . $event->id, 'title' => get_string('moment_kit_title', self::C),
                'text' => get_string('moment_kit', self::C, (object) ['name' => format_string($event->name),
                    'day' => userdate((int) $event->timestart, get_string('strftimedaydate', 'langconfig'))]),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/kit.php', ['id' => $event->id]))->out(false),
                'link' => get_string('moment_kit_link', self::C)];
        }
        return null;
    }

    /**
     * The first family with a place, where the common record of families is on the site: who makes their record, Eguahe
     * or the management system of the centre, so that nobody is there twice.
     *
     * @param \stdClass $centre
     * @return array|null
     */
    protected static function family(\stdClass $centre): ?array {
        global $DB;
        if (!family::available()) {
            return null;
        }
        $placed = $DB->record_exists_select(
            households::CHILDREN,
            "centreid = ? AND stage = 'enrolled' AND roster = 0 AND (lefttime IS NULL OR lefttime = 0)",
            [$centre->id]
        );
        if (!$placed) {
            return null;
        }
        return ['key' => 'family', 'title' => get_string('moment_family_title', self::C),
            'text' => get_string('moment_family', self::C),
            'url' => (new \moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]))->out(false) . '#aj-despues',
            'link' => get_string('moment_family_link', self::C)];
    }

    /**
     * Before the next school year begins, its offer, when the families with a place this year have no course to go on to:
     * asking who goes on needs it.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array|null
     */
    protected static function offer(\stdClass $centre, int $now): ?array {
        foreach (renewal::candidates($centre) as $cand) {
            $begins = imports::year_start($cand['year']);
            if ($cand['ready'] || $now < $begins - self::OFFER_BEFORE || $now >= $begins) {
                continue;
            }
            return ['key' => 'offer-' . preg_replace('/[^0-9]+/', '-', $cand['year']),
                'title' => get_string('moment_offer_title', self::C, s($cand['year'])),
                'text' => get_string('moment_offer', self::C, s($cand['year'])),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]))->out(false) . '#aj-oferta',
                'link' => get_string('moment_offer_link', self::C)];
        }
        return null;
    }
}
