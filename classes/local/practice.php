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
 * The practice centre of the guided helps: one per site, in no list and in no task, where the real screens of Eguahe run
 * with made-up families while a pointer shows how something is done, and where whoever watches can try it. Nothing it does
 * leaves the site (no mail, no notice, no shared record, no short link), and every day it starts again as it was.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class practice {
    /** @var string Component. */
    const C = 'local_oksigeniaeguahe';

    /** @var string The setting that says on which day the practice centre was made, as Y-m-d. */
    const MADE = 'practicemade';

    /** @var string Where the family the help «answering a family» answers is kept. */
    const ANSWER = 'practiceanswer';

    /**
     * The practice centre, made as it should be today: made again when it is from another day, or new when there is none.
     *
     * @param int $now
     * @return \stdClass
     */
    public static function centre(int $now = 0): \stdClass {
        global $DB, $USER;
        // A visitor trying Eguahe practises in their own centre.
        $mine = demo::centre_of((int) ($USER->id ?? 0));
        if ($mine) {
            return $mine;
        }
        $now = $now ?: time();
        $id = (int) $DB->get_field(centres::T, 'id', ['practice' => 1], IGNORE_MULTIPLE);
        if ($id && get_config(self::C, self::MADE) === date('Y-m-d', $now)) {
            return centres::get($id);
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniaeguahe')->get_lock('practice', 10);
        try {
            // Someone else may have made it while this waited.
            $id = (int) $DB->get_field(centres::T, 'id', ['practice' => 1], IGNORE_MULTIPLE);
            if (!$id || get_config(self::C, self::MADE) !== date('Y-m-d', $now)) {
                $id = self::make($id, $now);
                set_config(self::MADE, date('Y-m-d', $now), self::C);
            }
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
        return centres::get($id);
    }

    /**
     * Empties the practice centre (or creates it) and fills it again: its offer, an open day today at the door, another
     * in two weeks, a request for a visit of its own, and families at different points of their way. Every name is made up
     * and no address can receive anything.
     *
     * @param int $id 0 to create it.
     * @param int $now
     * @param int $kind 1, the practice centre of the helps; demo::KIND, the centre of a visitor.
     * @return int Its id.
     */
    public static function make(int $id, int $now, int $kind = 1): int {
        global $DB;
        $lang = current_language();
        $data = (object) [
            'name' => get_string('practice_name', self::C),
            'lang' => in_array($lang, lists::LANGS, true) ? $lang : 'en',
            'country' => (string) (get_config('core', 'country') ?: 'ES'),
            'colour' => '#2f5d62',
            'retention' => 1,
            'address' => get_string('practice_address', self::C),
            'phone' => '+34 900 000 000',
            // A domain reserved for examples, that receives nothing: whatever a person tries, nothing arrives anywhere.
            'email' => 'admisiones@example.com',
            'replydays' => 2,
            'guide' => 'perro',
        ];
        if ($id) {
            self::empty($id);
            centres::update($id, $data);
        } else {
            $id = centres::create($data);
        }
        $DB->set_field(centres::T, 'practice', $kind, ['id' => $id]);
        $str = fn(string $key) => get_string($key, self::C);
        $offers = [];
        foreach ([['practice_offer_1', 25], ['practice_offer_2', 4], ['practice_offer_3', 6]] as $i => [$key, $places]) {
            $offers[] = centres::save_offer($id, (object) ['schoolyear' => self::school_year($now), 'name' => $str($key),
                'places' => $places, 'active' => 1, 'sortorder' => $i]);
        }
        // An open day of today, from nine to nine as a real one, but always under way when the helps open its door; and
        // another in two weeks.
        $today = usergetmidnight($now);
        $door = events::get(events::save($id, (object) ['kind' => 'openday', 'name' => $str('practice_openday'),
            'place' => $str('practice_place'), 'timestart' => min(
                $now - MINSECS,
                max($today + MINSECS, min($today + 9 * HOURSECS, $now - HOURSECS))
            ),
            'timeend' => max($today + 21 * HOURSECS, (int) ceil(($now + 2 * HOURSECS) / HOURSECS) * HOURSECS),
            'capacity' => 80, 'open' => 1]), $id);
        $later = $today + 14 * DAYSECS + 10 * HOURSECS;
        events::save($id, (object) ['kind' => 'openday', 'name' => $str('practice_openday'), 'place' => $str('practice_place'),
            'timestart' => $later, 'timeend' => $later + 3 * HOURSECS, 'capacity' => 120, 'open' => 1]);
        $asking = events::get(events::save($id, (object) ['kind' => 'visit', 'name' => $str('practice_tailored'),
            'place' => $str('practice_place'), 'timestart' => $now, 'timeend' => $now + 90 * DAYSECS, 'capacity' => 0,
            'open' => 1, 'tailored' => 1]), $id);
        $families = [
            [['Lucía', 'Benítez Ortega'], [['Martín', 0]], 'webform', 'practice_msg_1', 2, 'door'],
            [['Andrés', 'Rivero Santana'], [['Vega', 0], ['Hugo', 1]], 'phone', 'practice_msg_2', 20, 'door'],
            [['Nadia', 'El Idrissi'], [['Yasmín', 2]], 'email', 'practice_msg_3', 30, ''],
            [['Jorge', 'Delgado Cabrera'], [['Alba', 0]], 'openday', '', 50, 'door'],
            [['Rosa', 'Hernández León'], [['Gael', 1]], 'webform', 'practice_msg_4', 70, 'ask'],
        ];
        foreach ($families as $n => [$adult, $children, $channel, $message, $hoursago, $what]) {
            $household = households::get(households::create($id, (object) ['lang' => $data->lang, 'firstchannel' => $channel,
                'heardfrom' => 'search']), $id);
            // An address of the domain reserved for examples, as a family writes it: nothing leaves a practice centre,
            // and nothing would arrive anywhere.
            $local = \core_text::strtolower(\core_text::specialtoascii($adult[0] . '.' . strtok($adult[1], ' ')));
            $adultid = households::add_adult($household, (object) ['firstname' => $adult[0], 'lastname' => $adult[1],
                'email' => preg_replace('/[^a-z.]+/', '', $local) . '@example.com']);
            households::consent($household, $adultid, 'email', true, 'form', $data->lang);
            foreach ($children as [$name, $offer]) {
                households::add_child($household, $name, $offers[$offer]);
            }
            $text = $message !== '' ? $str($message) : $str('practice_msg_none');
            households::received($household, $channel, $text, $message !== '', $now - $hoursago * HOURSECS);
            if ($what === 'door') {
                events::sign_up($door, (int) $household->id, 2 + ($n % 2));
            }
            if ($what === 'ask') {
                visits::request($household, $asking, [2, 4], ['afternoon'], 2);
            }
            // The family the help «answering a family» answers: its question is the one the help's text answers.
            if ($message === 'practice_msg_1') {
                set_config(self::answer_key($id), (int) $household->id, 'local_oksigeniaeguahe');
            }
        }
        return $id;
    }

    /**
     * The family the help «answering a family» answers in a practice centre.
     *
     * @param int $id
     * @return int
     */
    public static function answer(int $id): int {
        return (int) get_config(self::C, self::answer_key($id));
    }

    /**
     * Where that family is kept: one setting for the practice centre of the helps, one for each visitor's centre.
     *
     * @param int $id
     * @return string
     */
    private static function answer_key(int $id): string {
        global $DB;
        return (int) $DB->get_field(centres::T, 'practice', ['id' => $id]) === 1 ? self::ANSWER : self::ANSWER . '_' . $id;
    }

    /**
     * Takes away a visitor's centre, with everything in it.
     *
     * @param int $id
     */
    public static function remove(int $id): void {
        global $DB;
        if ((int) $DB->get_field(centres::T, 'practice', ['id' => $id]) !== demo::KIND) {
            return;
        }
        foreach ($DB->get_fieldset_select(centres::MEMBERS, 'userid', 'centreid = ?', [$id]) as $userid) {
            centres::remove_member($id, (int) $userid);
        }
        self::empty($id);
        unset_config(self::answer_key($id), self::C);
        $DB->delete_records(centres::T, ['id' => $id]);
    }

    /**
     * Takes away all of the practice centre but itself and its team: every other row of Eguahe with its id, and the
     * drawings of its children.
     *
     * @param int $id
     */
    private static function empty(int $id): void {
        global $DB;
        if (!centres::is_practice($id)) {
            return;
        }
        foreach ($DB->get_fieldset_select(households::CHILDREN, 'id', 'centreid = ?', [$id]) as $childid) {
            drawings::delete((int) $childid);
        }
        foreach ($DB->get_fieldset_select(events::T, 'id', 'centreid = ?', [$id]) as $eventid) {
            acts::drop((int) $eventid, acts::POSTER);
            acts::drop((int) $eventid, acts::SPEAKER);
        }
        $dbman = $DB->get_manager();
        foreach ($DB->get_tables(false) as $table) {
            if (strpos($table, 'local_oksigeniaeguahe_') !== 0 || $table === centres::T || $table === centres::MEMBERS) {
                continue;
            }
            $columns = $DB->get_columns($table);
            if (isset($columns['centreid']) && $dbman->table_exists($table)) {
                $DB->delete_records($table, ['centreid' => $id]);
            }
        }
    }

    /**
     * The school year whose places the centre is filling now, as «2027-28»: the next one from February.
     *
     * @param int $now
     * @return string
     */
    private static function school_year(int $now): string {
        $year = (int) date('Y', $now) + ((int) date('n', $now) >= 2 ? 1 : 0);
        return $year . '-' . substr((string) ($year + 1), -2);
    }
}
