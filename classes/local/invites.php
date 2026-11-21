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
 * the centre yet: a person sends the invitation, once per act, to those still on their way, by the way each family chose
 * (local\writeby): by email, and on WhatsApp or Telegram through Marandu, which says what it costs and waits for whoever
 * approves it when it costs more than the centre allows. Never to a family that asked not to be written to; nothing goes
 * by itself.
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

    /** @var string The notice of Marandu that carries the invitation to a phone. */
    const NOTICE = 'act_invitation';

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
     * Whether the site can send the invitation to the phones, with the sends to many of Marandu.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function by_phone(\stdClass $centre): bool {
        return messaging::mode($centre) !== 'off' && method_exists('\local_oksigeniamarandu\api', 'bulk_request');
    }

    /**
     * The families to invite to an act: not of the centre yet (no child enrolled, not all of their ways stopped, not
     * already of the centre), not asking not to be written to, not signed up to it and not invited yet; each with the
     * ways the invitation reaches it (reachemail, reachphone).
     *
     * @param \stdClass $event
     * @return \stdClass[] Households, by id.
     */
    public static function eligible(\stdClass $event): array {
        global $DB;
        $centreid = (int) $event->centreid;
        $households = $DB->get_records(households::T, ['centreid' => $centreid, 'incentre' => 0]);
        if (!$households) {
            return [];
        }
        $children = [];
        $fields = 'id, householdid, stage, lefttime';
        foreach ($DB->get_records(households::CHILDREN, ['centreid' => $centreid], '', $fields) as $child) {
            // A child who left the centre counts as one who is not of it, for the invitation.
            $children[$child->householdid][] = $child->stage === 'enrolled' && $child->lefttime ? 'left' : $child->stage;
        }
        $centre = centres::get($centreid);
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
        $phones = $centre && self::by_phone($centre);
        $out = [];
        foreach ($households as $id => $household) {
            $stages = $children[$id] ?? [];
            // A family that left the centre is left in peace, unless the centre wants it invited.
            $gone = in_array('left', $stages, true) && !in_array('enrolled', $stages, true);
            $ok = $gone ? !empty($centre->invitegone) : (!$stages || array_diff($stages, ['closed']));
            if (isset($done[$id]) || in_array('enrolled', $stages, true) || !$ok || !writeby::may_send($household, 'openinvite')) {
                continue;
            }
            $household->reachemail = !empty($emails[$id]) && familymail::may_email($household) &&
                writeby::email_ok($household, $centre, 'openinvite');
            $household->reachphone = $phones && writeby::phone_ok($household, 'openinvite') &&
                messaging::joined($household, $centre);
            if ($household->reachemail || $household->reachphone) {
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
     * What the invitation by phone would be, before sending it: how many by WhatsApp (and what it costs) and by Telegram,
     * and whether someone has to approve it.
     *
     * @param \stdClass $centre
     * @param int[] $ids The households reached by phone.
     * @return array|null Marandu's quote, or null without phones or families.
     */
    public static function quote(\stdClass $centre, array $ids): ?array {
        if (!$ids || !self::by_phone($centre)) {
            return null;
        }
        try {
            return \local_oksigeniamarandu\api::bulk_quote(messaging::COMPONENT, self::NOTICE, array_values($ids));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The invitation to an act, by the way of each family: the mails in the background, the phones through Marandu
     * (now, or once approved). Each family once; the families of a send by phone that is turned down can be invited
     * again.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid Who sends it.
     * @param int $now
     * @param string $hobby Only those where an adult said they like it, when it goes with the act; '' for all.
     * @return array email (how many mails go), phone (how many families by phone), status (of the send by phone:
     *               queued, pending or ''), said (Marandu's sentence about it).
     */
    public static function queue(\stdClass $event, \stdClass $centre, int $userid, int $now, string $hobby = ''): array {
        $none = ['email' => 0, 'phone' => 0, 'status' => '', 'said' => ''];
        if (!self::fits($event, $now)) {
            return $none;
        }
        $eligible = self::eligible($event);
        if ($hobby !== '') {
            $eligible = array_intersect_key($eligible, array_flip(hobbies::households_with(array_keys($eligible), [$hobby])));
        }
        $email = array_keys(array_filter($eligible, fn($h) => $h->reachemail));
        $phone = array_keys(array_filter($eligible, fn($h) => $h->reachphone));
        $out = $none;
        if ($phone) {
            $out = array_merge($out, self::request(
                $event,
                $centre,
                array_intersect_key($eligible, array_flip($phone)),
                $userid,
                $now
            ));
        }
        if ($email) {
            $task = new \local_oksigeniaeguahe\task\send_invites();
            $task->set_custom_data(['eventid' => (int) $event->id, 'centreid' => (int) $centre->id, 'userid' => $userid,
                'ids' => $email]);
            \core\task\manager::queue_adhoc_task($task, true);
            $out['email'] = count($email);
        }
        return $out;
    }

    /**
     * Asks Marandu to send the invitation to the phones of some families: it goes now, or waits for whoever approves
     * it. Each family is kept as invited by phone with the send, to not ask twice.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param \stdClass[] $households
     * @param int $userid
     * @param int $now
     * @return array phone, status and said.
     */
    protected static function request(\stdClass $event, \stdClass $centre, array $households, int $userid, int $now): array {
        global $DB;
        $items = [];
        $name = format_string($centre->name);
        foreach ($households as $household) {
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            $plang = method_exists('\local_oksigeniamarandu\api', 'language') ?
                \local_oksigeniamarandu\api::language($lang) : $lang;
            $parts = publicpage::date_parts((int) $event->timestart, $plang);
            $items[] = [
                'itemid' => (int) $household->id,
                'params' => [$name, format_string($event->name), $parts['long'] . ', ' . $parts['hour']],
                'url' => new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token, 'idioma' => $lang,
                    'k' => households::new_link($household, 'invitation'), 'de' => 'openinvite']),
                'lang' => $plang,
            ];
        }
        $label = get_string('invite_bulk_label', self::C, (object) ['event' => format_string($event->name),
            'date' => userdate((int) $event->timestart, get_string('strftimedateshort', 'langconfig'))]);
        try {
            $answer = \local_oksigeniamarandu\api::bulk_request(messaging::COMPONENT, self::NOTICE, $items, $userid, $label);
        } catch (\Throwable $e) {
            debugging('Eguahe: the invitation by phone was not asked for: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['phone' => 0, 'status' => '', 'said' => ''];
        }
        $bulkid = (int) ($answer['id'] ?? 0);
        foreach ($households as $household) {
            $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id,
                'householdid' => $household->id, 'userid' => $userid, 'channel' => 'phone', 'bulkid' => $bulkid,
                'timecreated' => $now]);
        }
        if (($answer['status'] ?? '') === 'queued') {
            self::told_by_phone($bulkid, $userid);
        }
        return ['phone' => count($households), 'status' => (string) ($answer['status'] ?? ''),
            'said' => self::state($bulkid)['said'] ?? ''];
    }

    /**
     * The line in the history of each family a send by phone went to.
     *
     * @param int $bulkid
     * @param int $userid
     */
    protected static function told_by_phone(int $bulkid, int $userid): void {
        global $DB;
        if (!$bulkid) {
            return;
        }
        foreach ($DB->get_records(self::T, ['bulkid' => $bulkid]) as $row) {
            $household = $DB->get_record(households::T, ['id' => $row->householdid]);
            $event = $DB->get_record(events::T, ['id' => $row->eventid], 'id, name');
            if ($household && $event) {
                households::told($household, $userid, 'whatsapp', get_string(
                    'invite_note_phone',
                    self::C,
                    format_string($event->name)
                ), true);
            }
        }
    }

    /**
     * What Marandu says of a send by phone: whether it waits, went or was turned down, and its sentence.
     *
     * @param int $bulkid
     * @return array|null status, said, reason…; null if there is none.
     */
    public static function state(int $bulkid): ?array {
        if (!$bulkid || !method_exists('\local_oksigeniamarandu\api', 'bulk_state')) {
            return null;
        }
        try {
            return \local_oksigeniamarandu\api::bulk_state($bulkid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The last send by phone of an act.
     *
     * @param int $eventid
     * @return int
     */
    public static function last_bulk(int $eventid): int {
        global $DB;
        return (int) $DB->get_field_select(self::T, 'MAX(bulkid)', 'eventid = ?', [$eventid]);
    }

    /**
     * Someone approved or turned down a send by phone (Marandu says so): approved, each family gets its line; turned down
     * or taken back, the families invited only by it can be invited again.
     *
     * @param int $bulkid
     * @param string $status queued, rejected or cancelled.
     * @param int $userid Who decided.
     */
    public static function decided(int $bulkid, string $status, int $userid): void {
        global $DB;
        if (!$bulkid) {
            return;
        }
        if ($status === 'queued') {
            // Approved only for Telegram: the families with no Telegram joined got nothing, and can be invited again.
            if ((self::state($bulkid)['only'] ?? '') === 'telegram') {
                self::release_without_telegram($bulkid);
            }
            $first = $DB->get_records(self::T, ['bulkid' => $bulkid], 'id ASC', 'id, userid', 0, 1);
            if ($first) {
                self::told_by_phone($bulkid, (int) (reset($first)->userid ?? $userid));
            }
            return;
        }
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            $DB->delete_records(self::T, ['bulkid' => $bulkid, 'channel' => 'phone']);
            $DB->set_field(self::T, 'channel', 'email', ['bulkid' => $bulkid, 'channel' => 'both']);
            $DB->set_field(self::T, 'bulkid', 0, ['bulkid' => $bulkid]);
        }
    }

    /**
     * After a send approved only for Telegram, lets go of the families it did not reach (no Telegram joined): invited by
     * phone only, they can be invited again; by both, they keep their mail.
     *
     * @param int $bulkid
     */
    protected static function release_without_telegram(int $bulkid): void {
        global $DB;
        foreach ($DB->get_records(self::T, ['bulkid' => $bulkid]) as $row) {
            $joined = [];
            try {
                $joined = \local_oksigeniamarandu\api::item_channels(messaging::COMPONENT, (int) $row->householdid);
            } catch (\Throwable $e) {
                $joined = [];
            }
            if (!empty($joined['telegram']['joined'])) {
                continue;
            }
            if ((string) $row->channel === 'both') {
                $DB->update_record(self::T, (object) ['id' => $row->id, 'channel' => 'email', 'bulkid' => 0]);
            } else {
                $DB->delete_records(self::T, ['id' => $row->id]);
            }
        }
    }

    /**
     * Takes back a send by phone still waiting for approval.
     *
     * @param int $bulkid
     * @param int $userid
     * @return bool
     */
    public static function withdraw(int $bulkid, int $userid): bool {
        if (!$bulkid || !method_exists('\local_oksigeniamarandu\api', 'bulk_cancel')) {
            return false;
        }
        try {
            $done = \local_oksigeniamarandu\api::bulk_cancel($bulkid, $userid);
        } catch (\Throwable $e) {
            return false;
        }
        if ($done) {
            self::decided($bulkid, 'cancelled', $userid);
        }
        return $done;
    }

    /**
     * Sends the invitation by email, once, to the families it was queued for (see queue()), from a task in the
     * background: a family invited by phone with the same act keeps one record, by both.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid Who sends it.
     * @param int $now
     * @param int[] $ids The households to invite by email.
     * @return int How many were sent.
     */
    public static function send(\stdClass $event, \stdClass $centre, int $userid, int $now, array $ids): int {
        global $DB;
        if (!self::fits($event, $now)) {
            return 0;
        }
        $sent = 0;
        $pseudo = (object) ['eventid' => (int) $event->id, 'eventname' => $event->name, 'timestart' => $event->timestart,
            'timeend' => $event->timeend, 'place' => $event->place, 'hosts' => $event->hosts, 'people' => 0,
            'token' => $event->token];
        $note = get_string('invite_note', self::C, format_string($event->name));
        foreach ($ids as $id) {
            $household = $DB->get_record(households::T, ['id' => (int) $id, 'centreid' => $centre->id]);
            if (!$household || !writeby::may_send($household, 'openinvite') || !familymail::may_email($household)) {
                continue;
            }
            $row = $DB->get_record(self::T, ['eventid' => $event->id, 'householdid' => $household->id]);
            if ($row && in_array((string) $row->channel, ['', 'email', 'both'], true)) {
                continue;
            }
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            $token = households::new_link($household, 'invitation');
            if (!familymail::send('openinvite', $household, $centre, $pseudo, $lang, $token)) {
                continue;
            }
            if ($row) {
                $DB->set_field(self::T, 'channel', 'both', ['id' => $row->id]);
            } else {
                $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id,
                    'householdid' => $household->id, 'userid' => $userid, 'channel' => 'email', 'bulkid' => 0,
                    'timecreated' => $now]);
            }
            households::told($household, $userid, 'email', $note, true);
            $sent++;
        }
        return $sent;
    }
}
