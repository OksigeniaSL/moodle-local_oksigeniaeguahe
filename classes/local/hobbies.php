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
 * What the adults of a family like doing in their free time, if they want to tell it on their page: each adult their
 * own, from a list and with one thing of their own. The centre reads it added up, to think of activities for the
 * families (if many say board games, a workshop of board games) and whom to invite to an open act that goes with it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hobbies {
    /** @var string */
    const T = 'local_oksigeniaeguahe_hobby';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The list, in the order it is offered. */
    const LIST = ['sport', 'reading', 'cooking', 'nature', 'music', 'boardgames', 'languages', 'crafts', 'technology',
        'cinema', 'travel', 'art', 'dance', 'gardening', 'photography', 'volunteering'];

    /** @var string Something of their own, written. */
    const OTHER = 'other';

    /**
     * What each adult of a household told.
     *
     * @param int $householdid
     * @return array adult id => [keys => string[], other => string]
     */
    public static function of(int $householdid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::T, ['householdid' => $householdid], 'id ASC') as $row) {
            $out[$row->adultid] = $out[$row->adultid] ?? ['keys' => [], 'other' => ''];
            if ($row->hobby === self::OTHER) {
                $out[$row->adultid]['other'] = (string) $row->other;
            } else {
                $out[$row->adultid]['keys'][] = $row->hobby;
            }
        }
        return $out;
    }

    /**
     * Keeps what an adult of the household told, instead of what they had told: unmarking everything takes it all out.
     *
     * @param \stdClass $household
     * @param int $adultid
     * @param string[] $keys
     * @param string $other
     * @param int $now
     * @return bool False when the adult is not of the household.
     */
    public static function save(\stdClass $household, int $adultid, array $keys, string $other, int $now): bool {
        global $DB;
        if (!$DB->record_exists(households::ADULTS, ['id' => $adultid, 'householdid' => $household->id])) {
            return false;
        }
        $keys = array_values(array_intersect(self::LIST, $keys));
        $other = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $other)), 0, 100);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::T, ['adultid' => $adultid, 'householdid' => $household->id]);
        $row = ['centreid' => $household->centreid, 'householdid' => $household->id, 'adultid' => $adultid,
            'timecreated' => $now];
        foreach ($keys as $key) {
            $DB->insert_record(self::T, (object) ($row + ['hobby' => $key]));
        }
        if ($other !== '') {
            $DB->insert_record(self::T, (object) ($row + ['hobby' => self::OTHER, 'other' => $other]));
        }
        $transaction->allow_commit();
        return true;
    }

    /**
     * The name of one, in a language of the families or, without one, in the language of who reads.
     *
     * @param string $key
     * @param string|null $lang
     * @return string
     */
    public static function name(string $key, ?string $lang = null): string {
        return $lang === null ? get_string('hobby_' . $key, self::C) : publiclang::str('hobby_' . $key, $lang);
    }

    /**
     * What one adult told, said in a list: «cooking, board games and sewing».
     *
     * @param array $told keys and other.
     * @param string|null $lang
     * @return string
     */
    public static function words(array $told, ?string $lang = null): string {
        $words = array_map(fn($k) => \core_text::strtolower(self::name($k, $lang)), $told['keys']);
        if (($told['other'] ?? '') !== '') {
            // What they wrote, inside the sentence: its first letter small, the rest as they wrote it.
            $words[] = \core_text::strtolower(\core_text::substr($told['other'], 0, 1)) . \core_text::substr($told['other'], 1);
        }
        if (count($words) < 2) {
            return (string) ($words[0] ?? '');
        }
        $last = array_pop($words);
        return $lang === null ? get_string('andlist', self::C, (object) ['list' => implode(', ', $words), 'last' => $last]) :
            familypage::join(array_merge($words, [$last]), $lang);
    }

    /**
     * Added up for some households: how many adults say each thing, and what they wrote of their own, by its words.
     *
     * @param int[] $householdids
     * @return array adults (of those households), told (adults who said something), list (key => adults, most first)
     *               and others (written thing => adults, most first).
     */
    public static function counts(array $householdids): array {
        global $DB;
        $out = ['adults' => 0, 'told' => 0, 'list' => [], 'others' => []];
        if (!$householdids) {
            return $out;
        }
        [$in, $params] = $DB->get_in_or_equal(array_values($householdids));
        $out['adults'] = $DB->count_records_select(households::ADULTS, 'householdid ' . $in, $params);
        $told = [];
        $written = [];
        foreach ($DB->get_records_select(self::T, 'householdid ' . $in, $params, '', 'id, adultid, hobby, other') as $row) {
            $told[$row->adultid] = true;
            if ($row->hobby === self::OTHER) {
                // The same thing written a little differently (a capital, an accent) is the same thing, named as most
                // wrote it.
                $name = trim((string) $row->other, " .\t");
                $key = \core_text::strtolower(\core_text::specialtoascii($name));
                $written[$key] = $written[$key] ?? ['forms' => [], 'adults' => []];
                $written[$key]['forms'][$name] = ($written[$key]['forms'][$name] ?? 0) + 1;
                $written[$key]['adults'][$row->adultid] = true;
            } else {
                $out['list'][$row->hobby][$row->adultid] = true;
            }
        }
        $out['told'] = count($told);
        $out['list'] = array_map('count', $out['list']);
        arsort($out['list']);
        foreach ($written as $w) {
            // As most wrote it; between as many, the one with its accents.
            $forms = array_keys($w['forms']);
            usort($forms, fn($x, $y) => [$w['forms'][$y], strlen($y)] <=> [$w['forms'][$x], strlen($x)]);
            $name = (string) $forms[0];
            $name = \core_text::strtoupper(\core_text::substr($name, 0, 1)) . \core_text::substr($name, 1);
            $out['others'][$name] = count($w['adults']);
        }
        arsort($out['others']);
        return $out;
    }

    /**
     * The households of some where an adult says one of these things.
     *
     * @param int[] $householdids
     * @param string[] $keys
     * @return int[]
     */
    public static function households_with(array $householdids, array $keys): array {
        global $DB;
        $keys = array_values(array_intersect(self::LIST, $keys));
        if (!$householdids || !$keys) {
            return [];
        }
        [$inh, $ph] = $DB->get_in_or_equal(array_values($householdids), SQL_PARAMS_NAMED, 'h');
        [$ink, $pk] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'k');
        return array_map('intval', $DB->get_fieldset_select(
            self::T,
            'DISTINCT householdid',
            'householdid ' . $inh . ' AND hobby ' . $ink,
            $ph + $pk
        ));
    }

    /**
     * How many of some households have an adult who says each thing of the list, the most first.
     *
     * @param int[] $householdids
     * @return array key => households.
     */
    public static function families_by(array $householdids): array {
        global $DB;
        if (!$householdids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_values($householdids));
        $out = [];
        $sql = 'SELECT hobby, COUNT(DISTINCT householdid) AS n FROM {' . self::T . '} WHERE householdid ' . $in .
            ' AND hobby <> ? GROUP BY hobby';
        foreach ($DB->get_records_sql($sql, array_merge($params, [self::OTHER])) as $row) {
            $out[$row->hobby] = (int) $row->n;
        }
        arsort($out);
        return $out;
    }

    /**
     * What a family can take with its data.
     *
     * @param int $householdid
     * @return array[]
     */
    public static function export(int $householdid): array {
        global $DB;
        $names = $DB->get_records_menu(households::ADULTS, ['householdid' => $householdid], '', 'id, firstname');
        $out = [];
        foreach (self::of($householdid) as $adultid => $told) {
            $out[] = ['adult' => (string) ($names[$adultid] ?? ''), 'likes' => $told['keys'], 'other' => $told['other']];
        }
        return $out;
    }

    /**
     * Forgets what a family told.
     *
     * @param int $householdid
     */
    public static function delete(int $householdid): void {
        global $DB;
        $DB->delete_records(self::T, ['householdid' => $householdid]);
    }
}
