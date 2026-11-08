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
 * What the scene of the skin «vestíbulo» needs from Eguahe (the contract of the art director, 8 November 2026): each
 * child with the name used at home, its course, the age of its course, whether a brother or sister shares it, and its
 * drawing; the families waiting, in the order of the queue; the hour of the centre and its texts. The scene draws it in
 * pub/pieles/vestibulo/escena.js; the page carries it in data-escena, as JSON.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vestibulo {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int The families the card over the hall names; the rest, in the inbox under it. */
    const CARD = 6;

    /**
     * Whether a centre has the living hall as its skin.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function on(\stdClass $centre): bool {
        return panel::skin($centre) === 'vestibulo';
    }

    /**
     * The children of a household, as the scene takes them.
     *
     * @param array $children With offer (or offerid and the offers given).
     * @param array $offers Offer id => offer, when the children do not carry theirs.
     * @param string $token The private link of the family, to give the drawing of each on its page; '' for none.
     * @return array
     */
    public static function children(array $children, array $offers = [], string $token = ''): array {
        $out = [];
        $byoffer = [];
        foreach ($children as $child) {
            $byoffer[(int) $child->offerid] = ($byoffer[(int) $child->offerid] ?? 0) + 1;
        }
        foreach ($children as $child) {
            $offer = $child->offer ?? ($offers[$child->offerid] ?? null);
            $course = $offer ? format_string($offer->name) : '';
            $tag = familypage::label_name($child);
            $row = [
                'tag' => $tag !== '' ? $tag : (string) $child->firstname,
                'course' => $course,
                'age' => $course !== '' ? grades::age($course) : null,
                'sibling_same_course' => (int) $child->offerid > 0 && $byoffer[(int) $child->offerid] > 1,
            ];
            if ($token !== '') {
                $row['drawing'] = self::drawing((int) $child->id, $token);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The drawing of a child, straightened, with whether it lies or stands, from the page of its family; false without
     * one.
     *
     * @param int $childid
     * @param string $token The private link of the family.
     * @return array|false
     */
    public static function drawing(int $childid, string $token) {
        $file = drawings::file($childid);
        if (!$file) {
            return false;
        }
        $info = $file->get_imageinfo();
        $landscape = $info && (int) $info['width'] >= (int) $info['height'];
        $url = new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'dibujo' => $childid,
            't' => $file->get_timemodified()]);
        return ['url' => $url->out(false), 'orientation' => $landscape ? 'landscape' : 'portrait'];
    }

    /**
     * The data of the hall of the inbox: the families waiting, the longest first, a day each for when they are many,
     * whom the board says hello to (the children of the next family coming today), the hour and the texts.
     *
     * @param \stdClass $centre
     * @param array $waiting Households waiting, as households::inbox() gives them, in its order.
     * @param int $now
     * @return array
     */
    public static function hall(\stdClass $centre, array $waiting, int $now): array {
        $families = [];
        foreach ($waiting as $household) {
            $families[] = ['children' => array_slice(self::children($household->children), 0, 4),
                'day' => self::day((int) $household->waitingsince)];
        }
        return self::common($centre, $now) + ['kind' => 'bandeja', 'families' => $families,
            'greet' => self::greet((int) $centre->id, $now)];
    }

    /**
     * The data of the hall of the page of a family: each child in its place, their drawings on the table and the hello;
     * their backpacks hung once they came, else the hooks wait with their names.
     *
     * @param \stdClass $centre
     * @param \stdClass $full The household, as households::full() gives it.
     * @param bool $hung
     * @param int $now
     * @param string $token The private link of the family, for the drawings.
     * @param string $lang The language of the page.
     * @return array
     */
    public static function family(\stdClass $centre, \stdClass $full, bool $hung, int $now, string $token, string $lang): array {
        $children = self::children(array_values($full->children), centres::offers((int) $centre->id), $token);
        return self::common($centre, $now, $lang) + ['kind' => 'familia', 'hung' => $hung,
            'children' => array_slice($children, 0, 4)];
    }

    /**
     * A course for the cloakroom of the places: its pegs, those taken, those on the waiting list and the age that picks
     * what hangs, as JSON for data-ropero.
     *
     * @param int $places
     * @param int $taken
     * @param int $waiting
     * @param string $course
     * @return string
     */
    public static function ropero(int $places, int $taken, int $waiting, string $course): string {
        return json_encode(['places' => $places, 'enrolled' => min($places, $taken), 'waiting' => $waiting,
            'age' => grades::age($course)]);
    }

    /**
     * The card over the hall: the first families of the queue, with since when and their children.
     *
     * @param array $waiting
     * @param int $now
     * @return array
     */
    public static function card(array $waiting, int $now): array {
        $out = [];
        foreach (array_slice(array_values($waiting), 0, self::CARD) as $household) {
            $tags = array_values(array_filter(array_map([familypage::class, 'label_name'], $household->children)));
            $out[] = [
                'adults' => panel::adults_line($household->adults),
                'since' => panel::wait_words((int) $household->waitingsince, $now)['since'],
                'tags' => familypage::join($tags, current_language()),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            ];
        }
        return $out;
    }

    /**
     * The base the scene finds its pieces from, and what goes in every hall: the centre, its hour and the texts.
     *
     * @param \stdClass $centre
     * @param int $now
     * @param string|null $lang The language of a page of a family; null for that of whoever looks.
     * @return array
     */
    protected static function common(\stdClass $centre, int $now, ?string $lang = null): array {
        $c = self::C;
        $local = (new \DateTime('@' . $now))->setTimezone(\core_date::get_user_timezone_object());
        $say = fn(string $id) => $lang === null ? get_string($id, $c) : publiclang::str($id, $lang);
        $days = [];
        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $days[] = \core_text::strtolower(get_string_manager()->get_string($day, 'calendar', null, $lang ?? current_language()));
        }
        return [
            'base' => (new \moodle_url('/local/oksigeniaeguahe/'))->out(false),
            'centre' => format_string($centre->name),
            'time' => (int) $local->format('G') + (int) $local->format('i') / 60,
            // For the day the board says: in the time of whoever looks, as the browser reads a date.
            'date' => $local->format('Y-m-d\\TH:i:00'),
            'texts' => ['hello' => $say('vestibulo_hello'), 'and' => $say('vestibulo_and'), 'today' => $say('vestibulo_today'),
                'days' => $days],
        ];
    }

    /**
     * A day in short, for the knob of each day when there are many families: «jue 5».
     *
     * @param int $time
     * @return string
     */
    protected static function day(int $time): string {
        if (!$time) {
            return '';
        }
        return \core_text::strtolower(trim(preg_replace('/\s+/', ' ', userdate($time, get_string('vestibulo_day', self::C)))));
    }

    /**
     * Whom the board says hello to: the children of the next family coming today that has not come yet.
     *
     * @param int $centreid
     * @param int $now
     * @return string[]
     */
    protected static function greet(int $centreid, int $now): array {
        global $DB;
        $zone = \core_date::get_user_timezone_object();
        $end = (new \DateTime('@' . $now))->setTimezone($zone)->setTime(0, 0)->getTimestamp() + DAYSECS;
        $rows = $DB->get_records_sql(
            'SELECT s.id, s.householdid
               FROM {' . events::SIGNUPS . '} s
               JOIN {' . events::T . '} e ON e.id = s.eventid
          LEFT JOIN {' . events::SLOTS . '} t ON t.id = s.slotid
              WHERE e.centreid = :centreid AND s.attended = 0
                    AND COALESCE(t.timeend, e.timeend) >= :now AND COALESCE(t.timestart, e.timestart) < :end
           ORDER BY COALESCE(t.timestart, e.timestart) ASC, s.id ASC',
            ['centreid' => $centreid, 'now' => $now, 'end' => $end],
            0,
            1
        );
        $row = reset($rows);
        if (!$row) {
            return [];
        }
        $children = $DB->get_records(households::CHILDREN, ['householdid' => $row->householdid], 'id ASC');
        return array_values(array_filter(array_map([familypage::class, 'label_name'], $children)));
    }
}
