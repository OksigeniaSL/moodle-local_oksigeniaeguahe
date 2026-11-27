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
 * What a family writes in the chat of the centre (Marandu, over the chat of the team), whether its number is joined or not:
 * a line in the history of each family with that phone, never waiting (the team answers it in the chat, which Eguahe does not
 * see), and what it seems to say of an invitation proposed in its file and beside the conversation, to confirm with a touch.
 * People answer by any way, whatever the way the letter went: a letter by mail is answered in the chat, at the desk or by
 * phone. Nothing is noted by itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chatin {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * A message that came in the chat.
     *
     * @param string $phone Digits with the country code, without «+».
     * @param string $text
     * @param int $time When the family wrote it.
     * @param string $url The conversation.
     * @param int $messageid Of the chat, to know it again.
     * @param int $now
     * @return int How many families it was kept for.
     */
    public static function take(string $phone, string $text, int $time, string $url, int $messageid, int $now = 0): int {
        global $DB;
        $now = $now ?: time();
        $digits = preg_replace('/\D+/', '', $phone);
        $text = trim($text);
        if (strlen($digits) < 7 || $text === '' || !$messageid) {
            return 0;
        }
        $n = 0;
        foreach (self::households($digits) as $household) {
            $centre = centres::get((int) $household->centreid);
            if (!$centre || !empty($centre->practice)) {
                continue;
            }
            $key = hash('sha256', $centre->id . '|chat|' . $messageid);
            if ($DB->record_exists(replies::T, ['centreid' => $centre->id, 'msgkey' => $key])) {
                continue;
            }
            // In a conversation that the messaging of Eguahe takes, the words of a joined family are already in its history.
            $taken = messaging::mode($centre) === 'conversation' && messaging::joined($household, $centre);
            $contactid = 0;
            if (!$taken) {
                households::received($household, 'whatsapp', $text, false, min($time ?: $now, $now), 0, '', '+' . $digits);
                $contactid = (int) $DB->get_field_sql(
                    'SELECT MAX(id) FROM {' . households::CONTACTS . '} WHERE householdid = ? AND direction = ?',
                    [$household->id, 'in']
                );
            }
            [$eventid, $proposal] = replies::proposal($household, '', $text, $now);
            $DB->insert_record(replies::T, (object) ['centreid' => $centre->id, 'householdid' => $household->id,
                'contactid' => $contactid, 'msgkey' => $key, 'eventid' => $eventid, 'proposal' => $proposal, 'state' => '',
                'way' => 'chat', 'timecreated' => $now]);
            $n++;
        }
        return $n;
    }

    /**
     * The families with a phone, of any centre.
     *
     * @param string $digits
     * @return \stdClass[]
     */
    protected static function households(string $digits): array {
        global $DB;
        $ids = $DB->get_fieldset_select(
            households::ADULTS,
            'DISTINCT householdid',
            'phone = :a OR phone = :b',
            ['a' => '+' . $digits, 'b' => $digits]
        );
        $out = [];
        foreach ($ids as $id) {
            $household = $DB->get_record(households::T, ['id' => $id]);
            if ($household) {
                $out[] = $household;
            }
        }
        return $out;
    }
}
