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
 * The brothers and sisters at home who would come later, only where children enter by age: the family tells them when
 * signing up (a name and the year of birth), or the team notes them. The centre sets one rule, the age children enter
 * at; there are no calendars of cut-off dates by country and no course is worked out (that is for the centre). When the
 * campaign of their year starts, the team reads a proposal and writes to the family, which says yes from its page
 * without filling anything; nothing is ever sent by itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class siblings {
    /** @var string */
    const T = 'local_oksigeniaeguahe_sibling';

    /** @var string[] Where a brother or sister was told. */
    const SOURCES = ['signup', 'page', 'team'];

    /** @var int The most siblings kept for a household. */
    const MOST = 6;

    /**
     * Whether the centre has the layer of brothers and sisters: children enter it by age.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function on(\stdClass $centre): bool {
        return (int) ($centre->siblingage ?? 0) > 0;
    }

    /**
     * The brothers and sisters of a household, the oldest first.
     *
     * @param int $householdid
     * @return \stdClass[]
     */
    public static function of(int $householdid): array {
        global $DB;
        return array_values($DB->get_records(self::T, ['householdid' => $householdid], 'birthyear ASC, id ASC'));
    }

    /**
     * Notes a brother or sister who would come later.
     *
     * @param \stdClass $household
     * @param string $firstname
     * @param int $birthyear
     * @param string $source signup, page or team.
     * @param int $now
     * @return int|null The sibling, or null without a name, with a year that cannot be, or with too many.
     */
    public static function add(\stdClass $household, string $firstname, int $birthyear, string $source, int $now): ?int {
        global $DB;
        $firstname = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $firstname)), 0, 100);
        $year = (int) date('Y', $now);
        if ($firstname === '' || $birthyear < $year - 18 || $birthyear > $year + 1) {
            return null;
        }
        if ($DB->count_records(self::T, ['householdid' => $household->id]) >= self::MOST) {
            return null;
        }
        return (int) $DB->insert_record(self::T, (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'firstname' => $firstname,
            'birthyear' => $birthyear,
            'source' => in_array($source, self::SOURCES, true) ? $source : 'team',
            'timecreated' => $now,
        ]);
    }

    /**
     * Takes a brother or sister out.
     *
     * @param \stdClass $household
     * @param int $id
     */
    public static function remove(\stdClass $household, int $id): void {
        global $DB;
        $DB->delete_records(self::T, ['id' => $id, 'householdid' => $household->id]);
    }

    /**
     * One of a household.
     *
     * @param \stdClass $household
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(\stdClass $household, int $id): ?\stdClass {
        global $DB;
        return $DB->get_record(self::T, ['id' => $id, 'householdid' => $household->id]) ?: null;
    }

    /**
     * The start year of the school year the campaign under way is for: the latest one offered.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int
     */
    public static function campaign_year(\stdClass $centre, int $now): int {
        global $DB;
        $latest = (string) $DB->get_field_sql(
            'SELECT MAX(schoolyear) FROM {' . centres::OFFERS . '} WHERE centreid = ?',
            [$centre->id]
        );
        if (preg_match('/^(\d{4})/', $latest, $m)) {
            return (int) $m[1];
        }
        return (int) date('Y', $now) + ((int) date('n', $now) >= 9 ? 1 : 0);
    }

    /**
     * The school year a brother or sister would enter: the one that starts in the year they turn the age.
     *
     * @param \stdClass $centre
     * @param \stdClass $sibling
     * @return int Its start year.
     */
    public static function turn_year(\stdClass $centre, \stdClass $sibling): int {
        return (int) $sibling->birthyear + (int) $centre->siblingage;
    }

    /**
     * A school year as the centre names them: «2028-29», or «2028» where the year is a calendar year.
     *
     * @param \stdClass $centre
     * @param int $start
     * @return string
     */
    public static function year_label(\stdClass $centre, int $start): string {
        global $DB;
        $latest = (string) $DB->get_field_sql(
            'SELECT MAX(schoolyear) FROM {' . centres::OFFERS . '} WHERE centreid = ?',
            [$centre->id]
        );
        return preg_match('/^\d{4}$/', $latest) ? (string) $start : $start . '-' . substr((string) ($start + 1), -2);
    }

    /**
     * When the campaign of a school year starts: as the one under way began, moved to its year; else the first of
     * October of the year before.
     *
     * @param \stdClass $centre
     * @param int $year The start year of the school year.
     * @param int $now
     * @return int
     */
    public static function campaign_start(\stdClass $centre, int $year, int $now): int {
        $start = (int) ($centre->campaignstart ?? 0);
        if ($start > 0) {
            $date = (new \DateTime('@' . $start))->setTimezone(\core_date::get_user_timezone_object());
            $date->modify(($year - self::campaign_year($centre, $now)) . ' years');
            return $date->getTimestamp();
        }
        return make_timestamp($year - 1, 10, 1);
    }

    /**
     * Whether it is the turn of a brother or sister: the campaign of their year has started, it is not over (their year
     * is still offered or about to be), and the family has not said yes yet.
     *
     * @param \stdClass $centre
     * @param \stdClass $sibling
     * @param int $now
     * @return bool
     */
    public static function in_turn(\stdClass $centre, \stdClass $sibling, int $now): bool {
        if (!self::on($centre) || (int) $sibling->confirmed) {
            return false;
        }
        $year = self::turn_year($centre, $sibling);
        return self::campaign_start($centre, $year, $now) <= $now && $year >= self::campaign_year($centre, $now);
    }

    /**
     * The households of the centre with a brother or sister whose turn it is, according to the last thing known.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array Household id => siblings.
     */
    public static function due(\stdClass $centre, int $now): array {
        global $DB;
        if (!self::on($centre)) {
            return [];
        }
        // A family that left is not offered places any more.
        $gone = $DB->get_fieldset_sql(
            'SELECT DISTINCT c.householdid
               FROM {' . households::CHILDREN . '} c
              WHERE c.centreid = :centreid AND c.lefttime > 0
                    AND NOT EXISTS (SELECT 1 FROM {' . households::CHILDREN . '} s
                                     WHERE s.householdid = c.householdid AND s.stage = :stage AND s.lefttime = 0)',
            ['centreid' => $centre->id, 'stage' => 'enrolled']
        );
        $out = [];
        foreach ($DB->get_records(self::T, ['centreid' => $centre->id, 'confirmed' => 0], 'birthyear ASC, id ASC') as $sibling) {
            if (!in_array($sibling->householdid, $gone) && self::in_turn($centre, $sibling, $now)) {
                $out[$sibling->householdid][] = $sibling;
            }
        }
        return $out;
    }

    /**
     * The family asks to be told when the turn of a brother or sister comes: kept, a line in its history and a promise
     * for the day the campaign of their year starts.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $id
     * @param int $now
     * @return bool False when it was already asked, or it is no sibling of theirs.
     */
    public static function notify(\stdClass $household, \stdClass $centre, int $id, int $now): bool {
        global $DB;
        $sibling = self::get($household, $id);
        if (!$sibling || (int) $sibling->notify || !self::on($centre)) {
            return false;
        }
        $DB->set_field(self::T, 'notify', $now, ['id' => $sibling->id]);
        $lang = in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        $label = self::year_label($centre, self::turn_year($centre, $sibling));
        $a = (object) ['name' => $sibling->firstname, 'year' => $label];
        households::received($household, lists::PAGE, publiclang::str('pub_sibling_notify_note', $lang, $a), false, $now);
        attend::promise(
            $household,
            0,
            publiclang::str('promise_sibling', $lang, $a),
            self::campaign_start($centre, self::turn_year($centre, $sibling), $now)
        );
        return true;
    }

    /**
     * The family says yes from its page, once it is their turn: the brother or sister becomes one more who would come,
     * asking for a place, and the team answers.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $id
     * @param int $now
     * @return bool
     */
    public static function confirm(\stdClass $household, \stdClass $centre, int $id, int $now): bool {
        global $DB;
        $sibling = self::get($household, $id);
        if (!$sibling || !self::in_turn($centre, $sibling, $now)) {
            return false;
        }
        $DB->set_field(self::T, 'confirmed', $now, ['id' => $sibling->id]);
        $childid = households::add_child($household, (string) $sibling->firstname, 0);
        households::set_stage($DB->get_record(households::CHILDREN, ['id' => $childid]), 'application');
        $lang = in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        $a = (object) ['name' => $sibling->firstname, 'year' => self::year_label($centre, self::turn_year($centre, $sibling))];
        households::received($household, lists::PAGE, publiclang::str('pub_sibling_confirm_note', $lang, $a), true, $now);
        return true;
    }

    /**
     * The team wrote to offer a brother or sister a place.
     *
     * @param \stdClass $household
     * @param int $id
     * @param int $now
     */
    public static function proposed(\stdClass $household, int $id, int $now): void {
        global $DB;
        $DB->set_field(self::T, 'proposed', $now, ['id' => $id, 'householdid' => $household->id]);
    }
}
