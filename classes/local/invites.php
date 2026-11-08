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
 * The open acts of the centre (a festival, a contest, a talk, an activity for parents) for the families that are not of
 * the centre yet: a person sends the invitation, once per act, to those who let the centre write to them by email,
 * with no child enrolled and still on their way; nothing goes by itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invites {
    /** @var string */
    const T = 'local_oksigeniaeguahe_invite';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * Whether an act is one to invite the families to: an open act still to come and open to sign up.
     *
     * @param \stdClass $event
     * @param int $now
     * @return bool
     */
    public static function fits(\stdClass $event, int $now): bool {
        return $event->kind === 'other' && !empty($event->open) && empty($event->tailored) && (int) $event->timestart > $now;
    }

    /**
     * The families to invite to an act: not of the centre yet (no child enrolled, not all of their ways stopped, not
     * already of the centre), that let it write to them by email and have one, not signed up to it and not invited yet.
     *
     * @param \stdClass $event
     * @return \stdClass[] Households.
     */
    public static function eligible(\stdClass $event): array {
        global $DB;
        $centreid = (int) $event->centreid;
        $households = $DB->get_records(households::T, ['centreid' => $centreid, 'incentre' => 0]);
        if (!$households) {
            return [];
        }
        $children = [];
        foreach ($DB->get_records(households::CHILDREN, ['centreid' => $centreid], '', 'id, householdid, stage') as $child) {
            $children[$child->householdid][] = $child->stage;
        }
        $emails = [];
        $adults = $DB->get_records_select(
            households::ADULTS,
            'centreid = ? AND email IS NOT NULL AND email <> ?',
            [$centreid, ''],
            '',
            'id, householdid'
        );
        foreach ($adults as $adult) {
            $emails[$adult->householdid] = true;
        }
        $done = array_flip(array_merge(
            $DB->get_fieldset_select(self::T, 'householdid', 'eventid = ?', [$event->id]),
            $DB->get_fieldset_select(events::SIGNUPS, 'householdid', 'eventid = ?', [$event->id])
        ));
        $out = [];
        foreach ($households as $id => $household) {
            $stages = $children[$id] ?? [];
            $going = !$stages || array_diff($stages, ['closed']);
            if (isset($done[$id]) || empty($emails[$id]) || in_array('enrolled', $stages, true) || !$going) {
                continue;
            }
            if (familymail::may_email($household)) {
                $out[$id] = $household;
            }
        }
        return $out;
    }

    /**
     * How many families were invited to an act.
     *
     * @param int $eventid
     * @return int
     */
    public static function count(int $eventid): int {
        global $DB;
        return $DB->count_records(self::T, ['eventid' => $eventid]);
    }

    /**
     * Sends the invitation to an act to each family that can get it: a new link to their page, the mail with the act
     * and how to sign up, a line in their history, and kept so they are not invited twice.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid Who sends it.
     * @param int $now
     * @return int How many were invited.
     */
    public static function send(\stdClass $event, \stdClass $centre, int $userid, int $now): int {
        global $DB;
        if (!self::fits($event, $now)) {
            return 0;
        }
        $sent = 0;
        $pseudo = (object) ['eventid' => (int) $event->id, 'eventname' => $event->name, 'timestart' => $event->timestart,
            'timeend' => $event->timeend, 'place' => $event->place, 'hosts' => $event->hosts, 'people' => 0,
            'token' => $event->token];
        $note = get_string('invite_note', self::C, format_string($event->name));
        foreach (self::eligible($event) as $household) {
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            $token = households::new_link($household, 'invitation');
            if (!familymail::send('openinvite', $household, $centre, $pseudo, $lang, $token)) {
                continue;
            }
            $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id,
                'householdid' => $household->id, 'userid' => $userid, 'timecreated' => $now]);
            households::told($household, $userid, 'email', $note);
            $sent++;
        }
        return $sent;
    }
}
