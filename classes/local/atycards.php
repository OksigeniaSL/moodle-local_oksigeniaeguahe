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
 * What Eguahe adds to the home of the app of Oksigenia Aty for an adult of a family already of the centre: whether
 * each child goes on next year (while the centre asks), their next visit, the story of the first day, a birthday. Each
 * row opens their page in the browser of the phone, by a signed link that lasts a day (app.php). Eguahe works without
 * Aty: this is what Aty adds (Enrique, 13-11). It needs Family to say the families of a person (families_of_user()).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class atycards {
    /** @var int How long a link from the app opens the page of the family. */
    const LASTS = DAYSECS;

    /** @var int The most rows of a card, as Aty shows them. */
    const ROWS = 5;

    /** @var array|null Only for the tests: the families of each person, as families_of_user() would give them. */
    public static $testfamilies = null;

    /**
     * The households of Eguahe an adult with an account belongs to, through the families of the common record: the one
     * Eguahe made for it (eguahe:<household>) or the one a person joined it to (familylink).
     *
     * @param int $userid
     * @return \stdClass[] Households, by id.
     */
    public static function households_of(int $userid): array {
        global $DB;
        $api = family::API;
        if (self::$testfamilies !== null) {
            $families = self::$testfamilies[$userid] ?? [];
        } else if (family::available() && method_exists($api, 'families_of_user')) {
            $families = $api::families_of_user($userid);
        } else {
            return [];
        }
        $ids = [];
        $linked = [];
        foreach ($families as $family) {
            $family = (object) $family;
            $linked[] = (int) $family->familyid;
            $mine = ($family->source ?? '') === family::SOURCE;
            if ($mine && preg_match('/^' . family::SOURCE . ':(\d+)$/', (string) ($family->sourcekey ?? ''), $m)) {
                $ids[] = (int) $m[1];
            }
        }
        if (!$ids && !$linked) {
            return [];
        }
        $where = [];
        $params = [];
        if ($ids) {
            [$in, $p] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'h');
            $where[] = "id $in";
            $params += $p;
        }
        if ($linked) {
            [$in, $p] = $DB->get_in_or_equal($linked, SQL_PARAMS_NAMED, 'f');
            $where[] = "familylink $in";
            $params += $p;
        }
        return $DB->get_records_select(households::T, implode(' OR ', $where), $params);
    }

    /**
     * The cards of a person: one per household of a centre, with what is for them now; none when there is nothing.
     *
     * @param int $userid
     * @param int $now
     * @return array Cards, as Aty's homecards describes them.
     */
    public static function home_cards(int $userid, int $now = 0): array {
        $now = $now ?: time();
        $cards = [];
        foreach (self::households_of($userid) as $household) {
            $centre = centres::get((int) $household->centreid);
            if (!$centre) {
                continue;
            }
            $items = self::rows($household, $centre, $userid, $now);
            if ($items) {
                // The home shows two rows: the rest, and everything else of theirs, on their page.
                $lang = (string) ($household->lang ?: ($centre->lang ?: 'es'));
                $cards[] = ['heading' => format_string($centre->name), 'items' => array_slice($items, 0, self::ROWS),
                    'seeall' => ['label' => publiclang::str('aty_seeall', $lang),
                        'url' => self::url($household, $userid, '', $now)->out(false)]];
            }
        }
        return $cards;
    }

    /**
     * What is for a family now, the most pressing first: whether each child goes on next year, their next visit, the
     * story of the first day and a birthday.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $userid
     * @param int $now
     * @return array Rows.
     */
    public static function rows(\stdClass $household, \stdClass $centre, int $userid, int $now): array {
        $lang = (string) ($household->lang ?: ($centre->lang ?: 'es'));
        $s = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $open = fn(string $anchor) => ['url' => self::url($household, $userid, $anchor, $now)->out(false)];
        $rows = [];
        // Whether each child goes on next year, while the centre asks and nobody said it.
        if (renewal::open($centre, $now)) {
            $said = renewal::answers($centre, (int) $household->id);
            foreach (renewal::children($centre, (int) $household->id) as $childid => [$child]) {
                if (isset($said[$childid])) {
                    continue;
                }
                $name = familypage::label_name($child) ?: (string) $child->firstname;
                // Answered inside the app (output\mobile), as an account of Aty cannot open the site in a browser.
                $rows[] = ['title' => $s('aty_renewal', (object) ['name' => $name, 'year' => s((string) $centre->renewalyear)]),
                    'detail' => $s('aty_renewal_detail'), 'urgent' => true, 'actionlabel' => $s('aty_answer'),
                    'method' => 'mobile_renewal_view', 'args' => ['h' => (int) $household->id],
                    'pagetitle' => $s('pub_renewal_title', (string) $centre->renewalyear)] +
                    ((int) ($centre->renewaluntil ?? 0) ? ['date' => (int) $centre->renewaluntil - 1, 'kind' => 'due'] : []);
            }
        }
        // Their next visit, with its hour.
        $full = households::full($household);
        foreach ($full->signups as $signup) {
            if ((int) $signup->timeend >= $now) {
                $rows[] = ['title' => format_string($signup->eventname), 'detail' => $s('aty_visit'),
                    'date' => (int) $signup->timestart, 'time' => true] + $open('visita');
                break;
            }
        }
        // The story of the first day, by its own link.
        foreach (story::children($household, $centre, $lang, $now) as $child) {
            $name = familypage::label_name($child) ?: (string) $child->firstname;
            $rows[] = ['title' => $s('aty_story', $name), 'detail' => $s('firstday_title'),
                'url' => story::share_url($household, $child, $lang)->out(false)];
        }
        // A birthday, in its days.
        foreach (birthdays::celebrating($household, $centre, $now) as [$child, $day]) {
            $name = familypage::label_name($child) ?: (string) $child->firstname;
            $rows[] = ['title' => $s('aty_birthday', $name), 'detail' => $s('aty_birthday_detail'), 'date' => $day] +
                $open('cumple');
        }
        return $rows;
    }

    /**
     * The link of a row: it opens the page of the family, at its part, for a day.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $anchor
     * @param int $now
     * @return \moodle_url
     */
    public static function url(\stdClass $household, int $userid, string $anchor, int $now): \moodle_url {
        $until = $now + self::LASTS;
        return new \moodle_url('/local/oksigeniaeguahe/app.php', ['h' => $household->id, 'u' => $userid, 't' => $until,
            'a' => $anchor, 's' => share::signature('app:' . $until, (int) $household->id, $userid)]);
    }

    /**
     * Whether a link from the app is good: signed by Eguahe and not past its day.
     *
     * @param int $householdid
     * @param int $userid
     * @param int $until
     * @param string $signature
     * @param int $now
     * @return bool
     */
    public static function valid(int $householdid, int $userid, int $until, string $signature, int $now): bool {
        return $until >= $now && $until <= $now + self::LASTS && share::signed('app:' . $until, $householdid, $userid, $signature);
    }
}
