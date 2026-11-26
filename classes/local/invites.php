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

    /** @var string Where the permission to write by phone to a number that did not join comes from (Enrique, 22-11 and 26-11). */
    const IMPLIED = 'eguahe:invite';

    /** @var int[] While a send by phone is being asked for: household id => the act, before its rows carry its number. */
    protected static $asking = [];

    /** @var string Instead of something they like: only the families of former students of the centre. */
    const ALUMNI = 'antiguos';

    /** @var string The families already of the centre that came through Eguahe: only when they are chosen. */
    const OFCENTRE = 'delcentro';

    /** @var int How many letters go out at a time, and every how long: many at once end in spam. */
    const BATCH = 15;

    /** @var int */
    const PAUSE = 25 * MINSECS;

    /** @var int[] From which hour and until which one they go out, in the time of the server. */
    const DAYTIME = [9, 20];

    /**
     * Whether an act is one to invite the families to: an open day or an open act, still to come and open to sign up.
     *
     * @param \stdClass $event
     * @param int $now
     * @return bool
     */
    public static function fits(\stdClass $event, int $now): bool {
        return in_array($event->kind, ['openday', 'other'], true) && !empty($event->open) && empty($event->tailored) &&
            (int) $event->timestart > $now;
    }

    /**
     * Whether the site can send the invitation to the phones, with the sends to many of Marandu.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function by_phone(\stdClass $centre): bool {
        // A practice or demo centre never sends anything to a phone.
        return empty($centre->practice) && messaging::mode($centre) !== 'off' &&
            method_exists('\local_oksigeniamarandu\api', 'bulk_request');
    }

    /**
     * The families to invite to an act: not of the centre yet (no child enrolled, not all of their ways stopped, not
     * already of the centre), not asking not to be written to or invited, not signed up to it and not invited yet; each with the
     * ways the invitation reaches it (reachemail, reachphone).
     *
     * @param \stdClass $event
     * @return \stdClass[] Households, by id.
     */
    public static function eligible(\stdClass $event): array {
        return self::reachable($event, false);
    }

    /**
     * The families already of the centre that came through Eguahe (they ask for another child, or were enrolled by it):
     * never invited with the others, only when they are chosen. The families of the roll of the centre are told by the
     * centre, not from here.
     *
     * @param \stdClass $event
     * @return \stdClass[] As eligible() gives them.
     */
    public static function of_centre(\stdClass $event): array {
        return self::reachable($event, true);
    }

    /**
     * The families an invitation can reach, of one side or the other: not of the centre yet, or already of it.
     *
     * @param \stdClass $event
     * @param bool $ofcentre
     * @param bool $sending Whether the letter of these families is the one going out now: those in the queue count too, and
     *     one invited by phone with the same act still gets its letter.
     * @return \stdClass[]
     */
    protected static function reachable(\stdClass $event, bool $ofcentre, bool $sending = false): array {
        global $DB;
        $centreid = (int) $event->centreid;
        $households = $DB->get_records(households::T, ['centreid' => $centreid, 'guest' => 0] + ($ofcentre ? ['roster' => 0] :
            ['incentre' => 0]));
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
            'id, householdid, email'
        );
        // An address whose mail comes back gets no letter until a person says the right one, and one of the centre itself none.
        $failing = bounces::failing(array_keys($adults));
        $own = $centre ? self::centre_addresses($centre) : ['domains' => [], 'emails' => []];
        foreach ($adults as $adult) {
            if (!isset($failing[(int) $adult->id]) && !self::of_centre_address((string) $adult->email, $own)) {
                $emails[$adult->householdid] = true;
            }
        }
        // Two files that may be the same family, and nobody said yet: one letter, to the older.
        $twins = self::newer_twins($centreid);
        $done = array_flip(array_merge(
            $DB->get_fieldset_select(
                self::T,
                'householdid',
                'eventid = ?' . ($sending ? ' AND (channel IN (?, ?, ?) OR COALESCE(reply, ?) <> ?)' : ''),
                $sending ? [$event->id, '', 'email', 'both', '', ''] : [$event->id]
            ),
            $DB->get_fieldset_select(events::SIGNUPS, 'householdid', 'eventid = ?', [$event->id])
        ));
        $phones = $centre && self::by_phone($centre);
        // A number that did not join may get it too, where Marandu writes to one: whoever said nothing about it lets it come.
        $numbers = $phones && self::numbers_ready() ? self::numbers($centreid) : [];
        // Who lets the centre write by email, read once for the whole centre (one query per family does not scale).
        $allowed = households::emailable($centreid);
        // Those whose invitation is already on its way, waiting for the task.
        $queued = $sending ? [] : self::queued((int) $event->id);
        $twice = self::shared_addresses($centreid);
        $out = [];
        foreach ($households as $id => $household) {
            $stages = $children[$id] ?? [];
            // A family that left the centre is left in peace, unless the centre wants it invited.
            $gone = in_array('left', $stages, true) && !array_diff($stages, ['left', 'closed']);
            $ok = $gone ? !empty($centre->invitegone) : (!$stages || array_diff($stages, ['closed']));
            $enrolled = in_array('enrolled', $stages, true);
            if ($ofcentre) {
                // Of the centre by its list, or by an enrolled child.
                if (!$enrolled && (empty($household->incentre) || $gone)) {
                    continue;
                }
            } else if ($enrolled || !$ok) {
                continue;
            }
            $mute = (int) $household->noinvites || !writeby::may_send($household, 'openinvite');
            if (isset($done[$id]) || isset($queued[$id]) || $mute) {
                continue;
            }
            $household->reachemail = !empty($emails[$id]) && self::by_mail($household, $centre, !empty($allowed[$id]));
            $household->number = $numbers[$id] ?? '';
            $household->reachphone = $phones && writeby::phone_ok($household, 'openinvite') &&
                ($household->number !== '' || messaging::joined($household, $centre));
            if ($household->reachemail && isset($twice[$id]) && !self::first_of($household, $twice[$id], $households, $event)) {
                // Two files of the same people: one letter, and none if either said no more of them.
                $household->reachemail = false;
            }
            if ($household->reachemail || $household->reachphone) {
                $out[$id] = $household;
            }
        }
        // Of two files that may be one family, the older gets it; the newer, only if the older cannot.
        foreach ($twins as $newer => $older) {
            if (isset($out[$newer]) && (isset($out[$older]) || isset($done[$older]) || isset($queued[$older]))) {
                unset($out[$newer]);
            }
        }
        return $out;
    }

    /**
     * Of two files of a centre that may be the same family and nobody has said, the newer: the older gets the letter.
     *
     * @param int $centreid
     * @return array household id => the older one
     */
    public static function newer_twins(int $centreid): array {
        $out = [];
        foreach (lookalike::pairs($centreid, time() - lookalike::NEAR) as [$newer, $older]) {
            $out[(int) $newer] = (int) $older;
        }
        return $out;
    }

    /**
     * The families of a centre with an address that is also of another one of its families.
     *
     * @param int $centreid
     * @return array householdid => the ids of the others.
     */
    protected static function shared_addresses(int $centreid): array {
        global $DB;
        $by = [];
        $rows = $DB->get_records_select(households::ADULTS, "centreid = ? AND COALESCE(email, '') <> ''", [$centreid], 'id ASC', 'id, householdid, email');
        foreach ($rows as $row) {
            $by[\core_text::strtolower(trim($row->email))][(int) $row->householdid] = true;
        }
        $out = [];
        foreach ($by as $homes) {
            foreach (count($homes) > 1 ? array_keys($homes) : [] as $id) {
                $out[$id] = ($out[$id] ?? []) + array_diff_key($homes, [$id => true]);
            }
        }
        return array_map('array_keys', $out);
    }

    /**
     * Whether the letter of an act goes to this family and not to another file of the same people: the older one gets it, and
     * neither does if one of them has it already, has signed up, or said no to being invited or written to.
     *
     * @param \stdClass $household
     * @param int[] $others
     * @param \stdClass[] $households Those of the centre that were read.
     * @param \stdClass $event
     * @return bool
     */
    protected static function first_of(\stdClass $household, array $others, array $households, \stdClass $event): bool {
        global $DB;
        $mine = \core_text::strtolower(households::recipient($household));
        foreach ($others as $id) {
            $other = $households[$id] ?? $DB->get_record(households::T, ['id' => $id]);
            $same = $other && $DB->record_exists_select(
                households::ADULTS,
                'householdid = ? AND ' . $DB->sql_equal('email', '?', false),
                [$id, $mine]
            );
            if (!$same) {
                continue;
            }
            $has = $DB->record_exists(self::T, ['eventid' => $event->id, 'householdid' => $id]) ||
                $DB->record_exists(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $id]);
            $theirs = \core_text::strtolower(households::recipient($other)) === $mine;
            $first = $theirs && $id < (int) $household->id && empty($other->guest) && empty($other->incentre);
            if ($has || (int) $other->noinvites || writeby::silent($other) || $first) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether an invitation goes by email: where the family lets the centre write by it. One that marked only phones and has
     * joined none is written to by email until it does, as its page tells it, and so it is invited.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param bool $allowed Whether some adult lets the centre write by email.
     * @return bool
     */
    protected static function by_mail(\stdClass $household, \stdClass $centre, bool $allowed): bool {
        $ways = writeby::chosen($household);
        return ($allowed || ($ways && !in_array('email', $ways, true))) &&
            writeby::email_ok($household, $centre, 'openinvite');
    }

    /**
     * Whether a family wants to be invited to events: the letters and the messages of the acts, and nothing else. What is of
     * its admission goes on by the ways it marked (writeby). A line of it stays in its history.
     *
     * @param \stdClass $household Changed in place.
     * @param bool $want
     * @param string $source family or team.
     * @param int $userid The person of the team who noted it, if one did.
     * @param int $now
     * @return bool Whether it changed.
     */
    public static function wanted(\stdClass $household, bool $want, string $source, int $userid = 0, int $now = 0): bool {
        global $DB;
        if ($want === !(int) $household->noinvites) {
            return false;
        }
        $household->noinvites = $want ? 0 : ($now ?: time());
        $DB->update_record(households::T, (object) ['id' => $household->id, 'noinvites' => $household->noinvites,
            'timemodified' => $now ?: time()]);
        $centre = centres::get((int) $household->centreid);
        households::note($household, $userid, get_string_manager()->get_string(
            'invites_noted_' . ($source === 'team' ? 'team' : 'family') . ($want ? '_on' : '_off'),
            self::C,
            null,
            (string) ($centre->lang ?? '') ?: current_language()
        ));
        return true;
    }

    /**
     * The families that have the letter of an act and are not signed up to it: if it changes or is called off, nobody tells
     * them by itself. Not those that said no, nor those that asked not to be written to or invited.
     *
     * @param \stdClass $event
     * @return \stdClass[] Households, by id.
     */
    public static function with_letter(\stdClass $event): array {
        global $DB;
        $centre = centres::get((int) $event->centreid);
        $rows = $DB->get_records_sql(
            'SELECT h.*
               FROM {' . households::T . '} h
               JOIN {' . self::T . "} i ON i.householdid = h.id AND i.eventid = :eventid
              WHERE h.noinvites = 0 AND h.guest <> " . households::SAMPLE . " AND COALESCE(i.reply, '') <> 'no'
                    AND COALESCE(i.channel, '') IN ('', 'email', 'both')
                    AND NOT EXISTS (SELECT 1 FROM {" . events::SIGNUPS . '} s WHERE s.eventid = i.eventid AND s.householdid = h.id)
           ORDER BY h.id ASC',
            ['eventid' => (int) $event->id]
        );
        return array_filter($rows, fn($h) => $centre && writeby::may_send($h, 'openinvite') && households::recipient($h) !== '' &&
            self::by_mail($h, $centre, familymail::may_email($h)));
    }

    /**
     * Tells one of them that the act changed, or that it is called off: a letter as the one it has.
     *
     * @param \stdClass $event As it is now.
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param string $kind change or cancelled.
     * @param string $message What the team adds.
     * @param int $userid
     * @return bool
     */
    public static function tell_change(
        \stdClass $event,
        \stdClass $centre,
        \stdClass $household,
        string $kind,
        string $message,
        int $userid
    ): bool {
        if (!writeby::may_send($household, 'openinvite') || (int) $household->noinvites) {
            return false;
        }
        $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
        $letter = self::of_letter($event);
        $letter->invited = true;
        $token = households::new_link($household, 'letter');
        [$subject, $html, $text, $images] = actmail::build($kind, $household, $centre, $event, $letter, $lang, $token, $message);
        if (get_config(self::C, 'letterplain')) {
            $html = '';
        }
        $sent = mail::send_rich($centre, households::recipient($household), $subject, $html, $text, $images);
        notices::log($household, $kind, $letter, 'email', $sent ? 'sent' : 'failed', $sent ? '' : 'mail');
        if ($sent) {
            households::told($household, $userid, 'email', get_string(
                $kind === 'change' ? 'invite_change_note' : 'invite_off_note',
                self::C,
                format_string($event->name)
            ), true);
        }
        return $sent;
    }

    /**
     * How many families were invited to an act.
     *
     * @param int $eventid
     * @return int
     */
    public static function count(int $eventid): int {
        global $DB;
        return $DB->count_records_select(self::T, 'eventid = ? AND ' . households::NOTSAMPLE, [$eventid]);
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
     * The families of an act whose invitation by email is queued and not sent yet: not offered again meanwhile.
     *
     * @param int $eventid
     * @return array householdid => true
     */
    public static function queued(int $eventid): array {
        $ids = [];
        foreach (self::tasks($eventid) as $task) {
            foreach ((array) ($task->get_custom_data()->ids ?? []) as $id) {
                $ids[(int) $id] = true;
            }
        }
        return $ids;
    }

    /**
     * Queues the invitation of an act to the families it may go to: by email in the background, by phone through
     * Marandu (which may wait for an approval).
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     * @param int $now
     * @param string $group Only the families of one of groups(), by its key.
     * @param int[]|null $ids Only these families, the ones left ticked; null for all.
     * @return array email, phone, status, said
     */
    public static function queue(
        \stdClass $event,
        \stdClass $centre,
        int $userid,
        int $now,
        string $group = '',
        ?array $ids = null
    ): array {
        $none = ['email' => 0, 'phone' => 0, 'status' => '', 'said' => ''];
        if (!self::fits($event, $now)) {
            return $none;
        }
        $eligible = self::eligible($event);
        $ofcentre = [];
        if ($group !== '' || $ids !== null) {
            $ofcentre = self::of_centre($event);
            $groups = $group !== '' ? self::groups($event, $eligible, $ofcentre) : [];
            $eligible += $ofcentre;
            // Never the whole list when the group is no longer there.
            $eligible = $group !== '' ? array_intersect_key($eligible, array_flip($groups[$group]['ids'] ?? [])) : $eligible;
        }
        if ($ids !== null) {
            $eligible = array_intersect_key($eligible, array_flip(array_map('intval', $ids)));
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
                $now,
                array_filter(array_map(fn($h) => (string) ($h->number ?? ''), array_intersect_key($eligible, array_flip($phone))))
            ));
        }
        if ($email) {
            $task = new \local_oksigeniaeguahe\task\send_invites();
            $task->set_custom_data(['eventid' => (int) $event->id, 'centreid' => (int) $centre->id, 'userid' => $userid,
                'ids' => $email, 'words' => (string) ($event->invitewords ?? ''),
                // Those already of the centre that a person chose: one that becomes of it while it waits gets no letter.
                'centre' => array_values(array_intersect($email, array_keys($ofcentre)))]);
            $task->set_next_run_time(self::daytime($now));
            \core\task\manager::queue_adhoc_task($task, true);
            $out['email'] = count($email);
        }
        return $out;
    }

    /**
     * Asks Marandu to send the invitation to the phones of some families: it goes now, or waits for whoever approves
     * it. Each family is kept as invited by phone with the send, to not ask twice; one that has the letter by mail already,
     * by both. The message carries a link of the family that, as the one of the letter, opens the act and nothing else.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param \stdClass[] $households
     * @param int $userid
     * @param int $now
     * @param string[] $numbers household id => its number, for those that did not join a phone: written to by permission.
     * @param bool $sample One family of example, for a person of the centre to see it: it goes at once, at any hour.
     * @return array phone, status and said.
     */
    protected static function request(
        \stdClass $event,
        \stdClass $centre,
        array $households,
        int $userid,
        int $now,
        array $numbers = [],
        bool $sample = false
    ): array {
        global $DB;
        $items = [];
        $links = [];
        foreach ($households as $household) {
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            [$plang, $params] = self::phone_params($event, $centre, $lang);
            // A message may be passed on, as a letter: its link opens the act and answers for it, never the page of the family.
            $token = households::new_link($household, 'letter');
            $links[(int) $household->id] = (int) (access::link($token)->id ?? 0);
            $item = [
                'itemid' => (int) $household->id,
                'params' => $params,
                'url' => new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token, 'idioma' => $lang, 'k' => $token,
                    'de' => 'telefono']),
                'lang' => $plang,
            ];
            if (($numbers[(int) $household->id] ?? '') !== '') {
                $item += ['phone' => $numbers[(int) $household->id], 'implied' => self::IMPLIED];
            }
            $items[] = $item;
        }
        $label = get_string('invite_bulk_label', self::C, (object) ['event' => format_string($event->name),
            'date' => userdate((int) $event->timestart, get_string('strftimedateshort', 'langconfig'))]);
        // A send that goes at once (a sample, or one under the threshold) may ask still() before its number is in the rows.
        self::$asking = array_fill_keys(array_map(fn($h) => (int) $h->id, $households), (int) $event->id);
        try {
            $answer = $sample && count($items) === 1 ?
                \local_oksigeniamarandu\api::bulk_request(messaging::COMPONENT, self::NOTICE, $items, $userid, $label, '', true) :
                \local_oksigeniamarandu\api::bulk_request(messaging::COMPONENT, self::NOTICE, $items, $userid, $label);
        } catch (\Throwable $e) {
            debugging('Eguahe: the invitation by phone was not asked for: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['phone' => 0, 'status' => '', 'said' => ''];
        } finally {
            self::$asking = [];
        }
        $bulkid = (int) ($answer['id'] ?? 0);
        foreach ($households as $household) {
            $row = $DB->get_record(self::T, ['eventid' => $event->id, 'householdid' => $household->id]);
            if ($row) {
                $DB->update_record(self::T, (object) ['id' => $row->id, 'channel' => 'both', 'bulkid' => $bulkid,
                    'said' => self::wording($event), 'phonelink' => $links[(int) $household->id]]);
            } else {
                $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id,
                    'householdid' => $household->id, 'userid' => $userid, 'channel' => 'phone', 'bulkid' => $bulkid,
                    'said' => self::wording($event), 'phonelink' => $links[(int) $household->id], 'timecreated' => $now]);
            }
        }
        if (($answer['status'] ?? '') === 'queued') {
            self::told_by_phone($bulkid, $userid);
        }
        return ['phone' => count($households), 'status' => (string) ($answer['status'] ?? ''),
            'said' => self::state($bulkid)['said'] ?? ''];
    }

    /**
     * What the message of an act says, in the language of a family: the centre, the act, and its day and hour.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @return array [the language for Marandu, the three values]
     */
    public static function phone_params(\stdClass $event, \stdClass $centre, string $lang): array {
        $plang = method_exists('\local_oksigeniamarandu\api', 'language') ? \local_oksigeniamarandu\api::language($lang) : $lang;
        $parts = publicpage::date_parts((int) $event->timestart, $plang);
        return [$plang, [format_string($centre->name), format_string($event->name), $parts['long'] . ', ' . $parts['hour']]];
    }

    /**
     * Whether Marandu writes by phone to a number that did not join, with the permission of the plugin.
     *
     * @return bool
     */
    public static function numbers_ready(): bool {
        try {
            return method_exists('\local_oksigeniamarandu\api', 'bulk_phones') && \local_oksigeniamarandu\api::bulk_phones();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The number of each family of a centre, as it was written down: the first adult that has one.
     *
     * @param int $centreid
     * @return string[] household id => number with its country.
     */
    public static function numbers(int $centreid): array {
        global $DB;
        $out = [];
        $adults = $DB->get_records_select(
            households::ADULTS,
            "centreid = ? AND phone IS NOT NULL AND phone <> ''",
            [$centreid],
            'id ASC',
            'id, householdid, phone'
        );
        foreach ($adults as $adult) {
            $number = trim((string) $adult->phone);
            if (!isset($out[(int) $adult->householdid]) && preg_match('/^\+\d{8,15}$/', $number)) {
                $out[(int) $adult->householdid] = $number;
            }
        }
        return $out;
    }

    /**
     * The families of an act that have its letter by mail and may have it by phone too: they have not answered it, are not
     * signed up, let the centre invite them and have not said not by phone (saying nothing lets it come).
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int[]|null $only Only these families, a family of example among them too (to see it as a family would); null
     *     for all of the act.
     * @return array households (those it goes to, by id), numbers (by id, of those that did not join), joined, waiting (with
     *     a number, while Marandu cannot write to one), nophone and notphone (how many).
     */
    public static function phone_too(\stdClass $event, \stdClass $centre, ?array $only = null): array {
        global $DB;
        $out = ['households' => [], 'numbers' => [], 'joined' => 0, 'waiting' => 0, 'nophone' => 0, 'notphone' => 0];
        if (!self::fits($event, time()) || !self::by_phone($centre) || $only === []) {
            return $out;
        }
        $where = "eventid = ? AND (channel IS NULL OR channel IN ('', 'email')) AND COALESCE(reply, '') = ''";
        $params = [$event->id];
        if ($only !== null) {
            [$in, $more] = $DB->get_in_or_equal(array_map('intval', $only));
            $where .= ' AND householdid ' . $in;
            $params = array_merge($params, $more);
        } else {
            $where .= ' AND ' . households::NOTSAMPLE;
        }
        $rows = $DB->get_records_select(self::T, $where, $params, 'id ASC', 'id, householdid');
        $signed = array_flip($DB->get_fieldset_select(events::SIGNUPS, 'householdid', 'eventid = ?', [$event->id]));
        $ready = self::numbers_ready();
        $numbers = self::numbers((int) $centre->id);
        foreach ($rows as $row) {
            $id = (int) $row->householdid;
            $household = isset($signed[$id]) ? null : $DB->get_record(households::T, ['id' => $id, 'centreid' => $centre->id]);
            $example = $only !== null && (int) ($household->guest ?? 0) === households::SAMPLE;
            if (
                !$household || ((int) $household->guest && !$example) || (int) $household->noinvites ||
                    !writeby::may_send($household, 'openinvite')
            ) {
                continue;
            }
            if (!writeby::phone_ok($household, 'openinvite')) {
                $out['notphone']++;
            } else if (messaging::joined($household, $centre)) {
                $out['joined']++;
                $out['households'][$id] = $household;
            } else if (!isset($numbers[$id])) {
                $out['nophone']++;
            } else if ($ready) {
                $out['households'][$id] = $household;
                $out['numbers'][$id] = $numbers[$id];
            } else {
                $out['waiting']++;
            }
        }
        return $out;
    }

    /**
     * What sending by phone the letter of those that have it by mail would be: Marandu's quote.
     *
     * @param \stdClass $centre
     * @param array $too From phone_too().
     * @return array|null
     */
    public static function phone_quote(\stdClass $centre, array $too): ?array {
        $ids = array_keys($too['households']);
        if (!$ids) {
            return null;
        }
        try {
            return $too['numbers'] ? \local_oksigeniamarandu\api::bulk_quote(messaging::COMPONENT, self::NOTICE, $ids, '', $too['numbers']) :
                \local_oksigeniamarandu\api::bulk_quote(messaging::COMPONENT, self::NOTICE, $ids);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The letter of an act, by phone too, to the families that have it by mail (phone_too()).
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     * @param int $now
     * @param int[]|null $only Only these families, as phone_too() takes them.
     * @return array phone, status and said.
     */
    public static function queue_phone_too(\stdClass $event, \stdClass $centre, int $userid, int $now, ?array $only = null): array {
        $too = self::phone_too($event, $centre, $only);
        if (!$too['households']) {
            return ['phone' => 0, 'status' => '', 'said' => ''];
        }
        // One family of example, named: to see it now, as a family would, whatever the hour.
        $one = count($too['households']) === 1 ? reset($too['households']) : null;
        $sample = $only !== null && $one && (int) $one->guest === households::SAMPLE;
        return self::request($event, $centre, $too['households'], $userid, $now, $too['numbers'], $sample);
    }

    /**
     * A family answered the invitation by phone, writing: a «no» is «we do not come» to that act, as on the page of the
     * letter, and nothing more; it does not stop the way, which only a word to stop does.
     *
     * @param int $bulkid
     * @param int $householdid
     * @param string $answer no.
     * @param int $time
     * @return string|null The answer to the family, in its language; null when it is no invitation of this send.
     */
    public static function answered_by_phone(int $bulkid, int $householdid, string $answer, int $time): ?string {
        global $DB;
        if ($answer !== 'no' || !$bulkid) {
            return null;
        }
        $row = $DB->get_record(self::T, ['bulkid' => $bulkid, 'householdid' => $householdid]);
        $event = $row ? $DB->get_record(events::T, ['id' => $row->eventid]) : null;
        $household = $event ? households::get($householdid, (int) $event->centreid) : null;
        if (!$household) {
            return null;
        }
        $centre = centres::get((int) $event->centreid);
        $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?? 'es');
        if (!self::reply($event, $household, 'no', 0, $time ?: time())) {
            // Already over, closed or called off: nothing to note, and nothing that would read wrong.
            return null;
        }
        self::note_way($event, $household, 'phone');
        return publiclang::str('invite_phone_no_answer', $lang, publicpage::text($event->name));
    }

    /**
     * By which of the phones the message of a family went, when it is known: WhatsApp when it went to its number by permission,
     * or the only one it joined; empty when it joined both.
     *
     * @param int $householdid
     * @param array|null $state Of phone_states().
     * @return string whatsapp, telegram or empty.
     */
    public static function phone_channel(int $householdid, ?array $state): string {
        if (!empty($state['implied'])) {
            return 'whatsapp';
        }
        try {
            $ways = method_exists('\local_oksigeniamarandu\api', 'item_channels') ?
                (array) \local_oksigeniamarandu\api::item_channels(messaging::COMPONENT, $householdid) : [];
        } catch (\Throwable $e) {
            $ways = [];
        }
        $on = array_keys(array_filter(['whatsapp' => !empty($ways['whatsapp']['joined']) || !empty($ways['whatsapp']['implied']),
            'telegram' => !empty($ways['telegram']['joined'])]));
        return count($on) === 1 ? $on[0] : '';
    }

    /**
     * How the message of each family of a send by phone is doing, as Marandu says: queued, waiting, sent, delivered, read,
     * failed, nowhatsapp or skipped, with why.
     *
     * @param int $bulkid
     * @return array household id => [status, time, why, implied (written to a number that did not join: by WhatsApp)]
     */
    public static function phone_states(int $bulkid): array {
        if (!$bulkid || !method_exists('\local_oksigeniamarandu\api', 'bulk_items')) {
            return [];
        }
        try {
            $out = [];
            foreach (\local_oksigeniamarandu\api::bulk_items($bulkid) as $item) {
                $item = (array) $item;
                $out[(int) $item['itemid']] = ['status' => (string) ($item['status'] ?? ''), 'time' => (int) ($item['time'] ?? 0),
                    'why' => (string) ($item['why'] ?? ''), 'implied' => !empty($item['implied'])];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
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
        // Marandu says to which families it let it go; before it did, only whoever still takes the phones.
        $released = self::state($bulkid)['released'] ?? null;
        $released = is_array($released) ? array_map('intval', $released) : null;
        foreach ($DB->get_records(self::T, ['bulkid' => $bulkid]) as $row) {
            if ($released !== null && !in_array((int) $row->householdid, $released, true)) {
                // It did not go to this one: not invited by phone, and it may be, later.
                if ($row->channel === 'phone') {
                    $DB->delete_records(self::T, ['id' => $row->id]);
                } else {
                    $DB->update_record(self::T, (object) ['id' => $row->id, 'channel' => 'email', 'bulkid' => 0]);
                }
                continue;
            }
            $household = $DB->get_record(households::T, ['id' => $row->householdid]);
            $event = $DB->get_record(events::T, ['id' => $row->eventid], 'id, name');
            $centre = $household ? centres::get((int) $household->centreid) : null;
            // Only of those it went to: not whoever, meanwhile, let go of the phones or of the invitations.
            $went = $centre && ($released !== null || (!(int) $household->noinvites && writeby::phone_ok($household, 'openinvite') &&
                (messaging::joined($household, $centre) || ((int) $row->phonelink && self::numbers_ready()))));
            if ($went && $event) {
                if (!(int) ($row->phonesent ?? 0)) {
                    $DB->set_field(self::T, 'phonesent', time(), ['id' => $row->id]);
                }
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
     * What an invitation by phone says of an act, to know whether it is still true.
     *
     * @param \stdClass $event
     * @return string
     */
    protected static function wording(\stdClass $event): string {
        return sha1(implode('|', [$event->name, (int) $event->timestart, (int) $event->timeend]));
    }

    /**
     * An act was changed or called off: what waits for approval and is no longer true is taken back, so that approving it
     * invites nobody to what is not. The families of that send can be invited again.
     *
     * @param \stdClass $event As it is now.
     * @return int How many sends were taken back.
     */
    public static function withdraw_stale(\stdClass $event): int {
        global $DB;
        $taken = 0;
        $rows = $DB->get_records_select(
            self::T,
            'eventid = ? AND bulkid > 0',
            [(int) $event->id],
            'bulkid ASC, id ASC',
            'id, bulkid, userid, said'
        );
        $sends = [];
        foreach ($rows as $row) {
            $sends[(int) $row->bulkid] = $row;
        }
        foreach ($sends as $bulkid => $row) {
            $true = empty($event->cancelled) && !empty($event->open) && ((string) $row->said === '' ||
                (string) $row->said === self::wording($event));
            if ($true || (self::state($bulkid)['status'] ?? '') !== 'pending') {
                continue;
            }
            if (method_exists('\local_oksigeniamarandu\api', 'bulk_withdraw')) {
                try {
                    $why = get_string('invite_withdraw_reason', self::C);
                    if (\local_oksigeniamarandu\api::bulk_withdraw(messaging::COMPONENT, $bulkid, $why)) {
                        self::decided($bulkid, 'cancelled', (int) $row->userid);
                        $taken++;
                    }
                } catch (\Throwable $e) {
                    debugging('Eguahe: a send by phone was not taken back: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            } else if (self::withdraw($bulkid, (int) $row->userid)) {
                $taken++;
            }
        }
        return $taken;
    }

    /**
     * Whether one message of a send still goes to a family, and with which values: after waiting for approval, or at once,
     * while it is being asked for.
     *
     * @param int $householdid
     * @param int $bulkid
     * @return array|bool True, as it was asked; false, it no longer goes; else params, its values as they are now.
     */
    public static function still(int $householdid, int $bulkid) {
        global $DB;
        $row = $DB->get_record(self::T, ['householdid' => $householdid, 'bulkid' => $bulkid], '*', IGNORE_MULTIPLE);
        if (!$row && isset(self::$asking[$householdid])) {
            // Asked while the send is made: the row of its act, or none yet (only by phone), as it will be written.
            $row = $DB->get_record(self::T, ['householdid' => $householdid, 'eventid' => self::$asking[$householdid]]) ?:
                (object) ['eventid' => self::$asking[$householdid], 'said' => ''];
        }
        $household = $row ? $DB->get_record(households::T, ['id' => $householdid]) : null;
        $event = $household ? events::get((int) $row->eventid, (int) $household->centreid) : null;
        $centre = $event ? centres::get((int) $event->centreid) : null;
        if (
            !$centre || !empty($event->cancelled) || empty($event->open) || (int) $event->timestart <= time() ||
                (int) $household->noinvites || !writeby::phone_ok($household, 'openinvite') ||
                $DB->record_exists(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $householdid])
        ) {
            return false;
        }
        if ((string) $row->said === '' || (string) $row->said === self::wording($event)) {
            return true;
        }
        $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
        $lang = method_exists('\local_oksigeniamarandu\api', 'language') ? \local_oksigeniamarandu\api::language($lang) : $lang;
        $parts = publicpage::date_parts((int) $event->timestart, $lang);
        return ['params' => [format_string($centre->name), format_string($event->name), $parts['long'] . ', ' . $parts['hour']]];
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
     * The groups the families an act may invite can be told apart by: what they like, those of former students, the
     * course they ask for and whether they signed up to another act.
     *
     * @param \stdClass $event
     * @param \stdClass[] $eligible As eligible() gives them.
     * @param \stdClass[] $ofcentre As of_centre() gives them.
     * @return array key => [kind (hobby, alumni, course, act or centre), name, ids]; of an act, also its row and its short name
     */
    public static function groups(\stdClass $event, array $eligible, array $ofcentre = []): array {
        global $DB;
        $ids = array_map('intval', array_keys($eligible));
        $out = [];
        if ($ofcentre) {
            $out[self::OFCENTRE] = ['kind' => 'centre', 'name' => get_string('panel_invite_ofcentre', self::C),
                'ids' => array_map('intval', array_keys($ofcentre))];
        }
        if (!$ids) {
            return $out;
        }
        foreach (array_keys(hobbies::families_by($ids)) as $key) {
            $out[$key] = ['kind' => 'hobby', 'name' => hobbies::name($key), 'ids' => hobbies::households_with($ids, [$key])];
        }
        $alumni = array_values(array_intersect($ids, array_keys(alumni::families((int) $event->centreid))));
        if ($alumni) {
            $out[self::ALUMNI] = ['kind' => 'alumni', 'name' => get_string('alumni_invite_chip', self::C), 'ids' => $alumni];
        }
        // The course each child still on its way asks for, by its name: the same course of two school years is one.
        $courses = [];
        $with = [];
        $sql = 'SELECT k.id, k.householdid, o.name, o.sortorder
                  FROM {' . households::CHILDREN . '} k
                  JOIN {' . centres::OFFERS . "} o ON o.id = k.offerid
                 WHERE k.centreid = ? AND k.stage <> ?
              ORDER BY o.sortorder, o.name, k.id";
        foreach ($DB->get_records_sql($sql, [$event->centreid, 'closed']) as $row) {
            if (isset($eligible[$row->householdid])) {
                $courses[(string) $row->name][(int) $row->householdid] = true;
                $with[(int) $row->householdid] = true;
            }
        }
        foreach ($courses as $name => $homes) {
            $out['curso-' . substr(sha1($name), 0, 10)] = ['kind' => 'course', 'name' => format_string($name),
                'ids' => array_keys($homes)];
        }
        $without = array_values(array_diff($ids, array_keys($with)));
        if ($courses && $without) {
            $out['sincurso'] = ['kind' => 'course', 'name' => get_string('panel_invite_nocourse', self::C), 'ids' => $without];
        }
        // The last acts of the centre, by whether they signed up.
        $signed = [];
        $sql = 'SELECT s.id, s.eventid, s.householdid FROM {' . events::SIGNUPS . '} s WHERE s.centreid = ? AND s.eventid <> ?';
        foreach ($DB->get_records_sql($sql, [$event->centreid, (int) $event->id]) as $row) {
            if (isset($eligible[$row->householdid])) {
                $signed[(int) $row->eventid][(int) $row->householdid] = true;
            }
        }
        $acts = $signed ? $DB->get_records_select(
            events::T,
            "centreid = ? AND kind IN ('openday', 'other') AND tailored = 0",
            [$event->centreid],
            'timestart DESC, id DESC',
            'id, name, timestart'
        ) : [];
        $n = 0;
        foreach ($acts as $act) {
            $in = array_keys($signed[(int) $act->id] ?? []);
            if (!$in || ++$n > 3) {
                continue;
            }
            $name = (object) ['event' => format_string($act->name), 'date' => userdate(
                (int) $act->timestart,
                get_string('strftimedateshort', 'langconfig')
            )];
            $act = (int) $act->id;
            $row = get_string('panel_invite_act', self::C, $name);
            $out['acto-' . $act] = ['kind' => 'act', 'name' => get_string('panel_invite_act_in', self::C, $name), 'ids' => $in,
                'row' => $row, 'short' => get_string('panel_invite_signed', self::C)];
            $rest = array_values(array_diff($ids, $in));
            if ($rest) {
                $out['sinacto-' . $act] = ['kind' => 'act', 'name' => get_string('panel_invite_act_out', self::C, $name),
                    'ids' => $rest, 'row' => $row, 'short' => get_string('panel_invite_unsigned', self::C)];
            }
        }
        return $out;
    }

    /**
     * The moment itself if it is of daytime; if not, when the next one starts.
     *
     * @param int $time
     * @return int
     */
    public static function daytime(int $time): int {
        $date = (new \DateTimeImmutable('@' . $time))->setTimezone(\core_date::get_server_timezone_object());
        $hour = (int) $date->format('G');
        if ($hour >= self::DAYTIME[0] && $hour < self::DAYTIME[1]) {
            return $time;
        }
        return ($hour >= self::DAYTIME[1] ? $date->modify('+1 day') : $date)->setTime(self::DAYTIME[0], 0)->getTimestamp();
    }

    /**
     * When the last of some letters goes out, if the first ones go at a moment.
     *
     * @param int $count
     * @param int $first
     * @return int
     */
    public static function last_at(int $count, int $first): int {
        $time = self::daytime($first);
        for ($left = $count - self::BATCH; $left > 0; $left -= self::BATCH) {
            $time = self::daytime($time + self::PAUSE);
        }
        return $time;
    }

    /**
     * What is still on its way of an act: how many families, and when the next ones go out.
     *
     * @param int $eventid
     * @return int[] [families, next]
     */
    public static function on_the_way(int $eventid): array {
        $n = 0;
        $next = 0;
        foreach (self::tasks($eventid) as $task) {
            $n += count((array) ($task->get_custom_data()->ids ?? []));
            $next = $next ? min($next, (int) $task->get_next_run_time()) : (int) $task->get_next_run_time();
        }
        return [$n, $next];
    }

    /**
     * Stops what has not gone out yet: those families can be invited again.
     *
     * @param int $eventid
     * @return int How many families were left.
     */
    public static function stop(int $eventid): int {
        global $DB;
        [$n] = self::on_the_way($eventid);
        // Who was left, to send it to them later with «Resume».
        $left = self::paused($eventid);
        foreach (self::tasks($eventid) as $task) {
            $left = array_merge($left, array_map('intval', (array) ($task->get_custom_data()->ids ?? [])));
        }
        set_config('paused_' . $eventid, $left ? json_encode(array_values(array_unique($left))) : null, self::C);
        foreach (self::tasks($eventid) as $task) {
            $DB->delete_records('task_adhoc', ['id' => $task->get_id()]);
        }
        return $n;
    }

    /**
     * The families whose letter was stopped before it went out, kept to send it later.
     *
     * @param int $eventid
     * @return int[]
     */
    public static function paused(int $eventid): array {
        $ids = json_decode((string) get_config(self::C, 'paused_' . $eventid), true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * The letter that was stopped, on its way again to those it had not reached, at the same pace; whoever signed up, was
     * invited or asked for no more since is left out, as always.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     * @return array As queue().
     */
    public static function resume(\stdClass $event, \stdClass $centre, int $userid): array {
        $ids = self::paused((int) $event->id);
        set_config('paused_' . $event->id, null, self::C);
        return $ids ? self::queue($event, $centre, $userid, time(), '', $ids) : ['email' => 0, 'phone' => 0, 'status' => '', 'said' => ''];
    }

    /**
     * The families left out only because they never said yes to email, given it by a person of the team who knows they left
     * their contact to be written to: each adult with an address and no word on email yet. Never one who said no, nor a family
     * that asked not to be written to or chose other ways. A line in the history says who gave it.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     * @return int How many families.
     */
    public static function permit(\stdClass $event, \stdClass $centre, int $userid): int {
        $n = 0;
        foreach (self::permittable($event, $centre) as [$household, $adults]) {
            foreach ($adults as $adult) {
                households::consent($household, $adult, 'email', true, 'team', (string) ($household->lang ?: $centre->lang));
            }
            households::note($household, $userid, get_string('invite_permit_note', self::C));
            $n++;
        }
        return $n;
    }

    /**
     * The families a person can give the email permission in the step of the letter: left out only for never saying yes, with
     * an adult with an address who never said a word on email; never a family that asked not to be written to.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return array household id => [the household, the ids of its adults].
     */
    public static function permittable(\stdClass $event, \stdClass $centre): array {
        global $DB;
        $out = [];
        foreach (invitecheck::left_out($event, $centre, [])['nopermission'] ?? [] as $id) {
            $household = $DB->get_record(households::T, ['id' => $id, 'centreid' => $centre->id]);
            if (!$household || writeby::silent($household) || (int) $household->noinvites) {
                continue;
            }
            $ways = writeby::chosen($household);
            if ($ways !== null && !in_array('email', $ways, true)) {
                continue;
            }
            $said = households::permissions((int) $household->id);
            $adults = [];
            foreach (households::full($household)->adults as $adult) {
                if (trim((string) $adult->email) !== '' && !array_key_exists('email', $said[$adult->id] ?? [])) {
                    $adults[] = (int) $adult->id;
                }
            }
            if ($adults) {
                $out[(int) $id] = [$household, $adults];
            }
        }
        return $out;
    }

    /**
     * The addresses of the centre itself: those of its team and the ones of its own domain (never a public one, as gmail.com).
     *
     * @param \stdClass $centre
     * @return array domains and emails, lowercase.
     */
    public static function centre_addresses(\stdClass $centre): array {
        $emails = [];
        $domains = [];
        foreach ([(string) ($centre->email ?? ''), (string) ($centre->emailsettled ?? '')] as $email) {
            $email = \core_text::strtolower(trim($email));
            if ($email === '' || strpos($email, '@') === false) {
                continue;
            }
            $emails[] = $email;
            $domain = substr($email, strrpos($email, '@') + 1);
            // A public provider, or a domain kept for examples, is never the centre's own.
            if (!in_array($domain, emailcheck::DOMAINS, true) && !mail::reserved($email)) {
                $domains[] = $domain;
            }
        }
        foreach (centres::members((int) $centre->id) as $member) {
            if (trim((string) $member->email) !== '') {
                $emails[] = \core_text::strtolower(trim((string) $member->email));
            }
        }
        return ['domains' => array_values(array_unique($domains)), 'emails' => array_values(array_unique($emails))];
    }

    /**
     * Whether an address is of the centre itself: one of its team, or of its domain or one under it.
     *
     * @param string $email
     * @param array $own From centre_addresses().
     * @return bool
     */
    public static function of_centre_address(string $email, array $own): bool {
        $email = \core_text::strtolower(trim($email));
        if (in_array($email, $own['emails'], true)) {
            return true;
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        foreach ($own['domains'] as $mine) {
            if ($domain === $mine || substr($domain, -strlen('.' . $mine)) === '.' . $mine) {
                return true;
            }
        }
        return false;
    }

    /**
     * Where the letter of a family goes: the address it would get mail at, but never one of the centre itself nor one whose
     * mail comes back; empty if it has no other.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return string
     */
    public static function letter_address(\stdClass $household, \stdClass $centre): string {
        $own = self::centre_addresses($centre);
        $adults = households::full($household)->adults;
        $failing = bounces::failing(array_keys($adults));
        $permissions = households::permissions((int) $household->id);
        $first = '';
        foreach ($adults as $adult) {
            $email = trim((string) $adult->email);
            if ($email === '' || isset($failing[(int) $adult->id]) || self::of_centre_address($email, $own)) {
                continue;
            }
            if (!empty($permissions[$adult->id]['email'])) {
                return $email;
            }
            $first = $first ?: $email;
        }
        return $first;
    }

    /**
     * Two files of a family are one now: the letters still on their way to the one that is gone go to the one that stays.
     *
     * @param int $gone
     * @param int $keep
     */
    public static function renumber(int $gone, int $keep): void {
        global $DB;
        foreach (\core\task\manager::get_adhoc_tasks(\local_oksigeniaeguahe\task\send_invites::class) as $task) {
            $data = (array) $task->get_custom_data();
            $changed = false;
            foreach (['ids', 'centre'] as $list) {
                $ids = array_map('intval', (array) ($data[$list] ?? []));
                if (in_array($gone, $ids, true)) {
                    $data[$list] = array_values(array_unique(array_map(fn($id) => $id === $gone ? $keep : $id, $ids)));
                    $changed = true;
                }
            }
            if ($changed) {
                $task->set_custom_data($data);
                $DB->set_field('task_adhoc', 'customdata', $task->get_custom_data_as_string(), ['id' => $task->get_id()]);
            }
        }
    }

    /**
     * The tasks in the background with the letters of an act.
     *
     * @param int $eventid
     * @return \core\task\adhoc_task[]
     */
    protected static function tasks(int $eventid): array {
        return array_filter(
            \core\task\manager::get_adhoc_tasks(\local_oksigeniaeguahe\task\send_invites::class),
            fn($task) => (int) ($task->get_custom_data()->eventid ?? 0) === $eventid
        );
    }

    /**
     * Of the families shown, the ones left out of the invitation being prepared, kept for the session of whoever prepares
     * it: whoever was unticked stays so, whatever is pressed before it goes.
     *
     * @param int $eventid
     * @return int[]
     */
    public static function left_out(int $eventid): array {
        global $SESSION;
        return array_map('intval', array_keys($SESSION->local_oksigeniaeguahe_leftout[$eventid] ?? []));
    }

    /**
     * Keeps who is ticked and who is not, of the families shown.
     *
     * @param int $eventid
     * @param int[] $shown
     * @param int[] $ticked
     */
    public static function tick(int $eventid, array $shown, array $ticked): void {
        global $SESSION;
        $out = array_flip(self::left_out($eventid));
        foreach ($shown as $id) {
            if (in_array((int) $id, $ticked, true)) {
                unset($out[(int) $id]);
            } else {
                $out[(int) $id] = true;
            }
        }
        $SESSION->local_oksigeniaeguahe_leftout[$eventid] = array_map(fn($x) => true, $out);
    }

    /**
     * Leaves something to say in the block of the invitation, the next time it is shown.
     *
     * @param int $eventid
     * @param string $text
     * @param bool $error
     */
    public static function say(int $eventid, string $text, bool $error = false): void {
        global $SESSION;
        $SESSION->local_oksigeniaeguahe_invitesaid[$eventid] = ['text' => $text, 'error' => $error];
    }

    /**
     * What was left to say, once.
     *
     * @param int $eventid
     * @return array|false text, error
     */
    public static function said(int $eventid) {
        global $SESSION;
        $said = $SESSION->local_oksigeniaeguahe_invitesaid[$eventid] ?? false;
        unset($SESSION->local_oksigeniaeguahe_invitesaid[$eventid]);
        return $said;
    }

    /**
     * What the letter of an act needs of it.
     *
     * @param \stdClass $event
     * @return \stdClass
     */
    public static function of_letter(\stdClass $event): \stdClass {
        return (object) ['eventid' => (int) $event->id, 'eventname' => $event->name, 'timestart' => $event->timestart,
            'timeend' => $event->timeend, 'place' => $event->place, 'hosts' => $event->hosts, 'people' => 0,
            'token' => $event->token, 'free' => (int) ($event->free ?? 0), 'kind' => $event->kind,
            'about' => $event->about ?? null, 'speakers' => $event->speakers ?? null, 'topic' => $event->topic ?? null,
            'word' => $event->word ?? null, 'audience' => $event->audience ?? null];
    }

    /**
     * Sends the letter to whoever is going to send it, to see how it arrives: nothing is counted.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param \stdClass $user
     * @return bool
     */
    public static function try_on(\stdClass $event, \stdClass $centre, \stdClass $user): bool {
        publiclang::$self = false;
        [$subject, $html, $text] = familymail::letter(
            trim((string) $user->firstname),
            $centre,
            self::of_letter($event),
            (string) ($centre->lang ?: 'es'),
            '',
            (string) ($event->invitewords ?? '')
        );
        return mail::send_rich(
            $centre,
            (string) $user->email,
            $subject,
            get_config(self::C, 'letterplain') ? '' : $html,
            $text
        );
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
     * @param string $words What the team added, as it was when the invitation was sent.
     * @param int[]|null $chosen Of them, those already of the centre that a person chose; null, whichever they are.
     * @return int How many were sent.
     */
    public static function send(
        \stdClass $event,
        \stdClass $centre,
        int $userid,
        int $now,
        array $ids,
        string $words = '',
        ?array $chosen = null
    ): int {
        global $DB;
        if (!self::fits($event, $now)) {
            return 0;
        }
        $sent = 0;
        $pseudo = self::of_letter($event);
        $note = get_string('invite_note', self::C, format_string($event->name));
        // As they are now, which may be hours after they were chosen: one that signed up meanwhile, was enrolled or closed, or
        // asked for no more of these, gets no letter.
        $theirs = self::reachable($event, true, true);
        $still = self::reachable($event, false, true) + ($chosen === null ? $theirs : array_intersect_key($theirs, array_flip($chosen)));
        foreach ($ids as $id) {
            if (empty($still[(int) $id]->reachemail)) {
                // Said on the page of the act, and in the file of the family: never in silence.
                $one = $DB->get_record(households::T, ['id' => (int) $id, 'centreid' => $centre->id]);
                if ($one && !$DB->record_exists(self::T, ['eventid' => $event->id, 'householdid' => $one->id, 'channel' => 'email'])) {
                    notices::log($one, 'openinvite', $pseudo, 'email', 'skipped', self::why_not($event, $one));
                }
                continue;
            }
            $household = $DB->get_record(households::T, ['id' => (int) $id, 'centreid' => $centre->id]);
            if (
                !$household || (int) $household->noinvites || !writeby::may_send($household, 'openinvite') ||
                !self::by_mail($household, $centre, familymail::may_email($household))
            ) {
                continue;
            }
            $row = $DB->get_record(self::T, ['eventid' => $event->id, 'householdid' => $household->id]);
            if ($row && in_array((string) $row->channel, ['', 'email', 'both'], true)) {
                continue;
            }
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            // The letter may be passed on: its link opens the page of the act and nothing else.
            $token = households::new_link($household, 'letter');
            $to = self::letter_address($household, $centre);
            if ($to === '' || !familymail::send('openinvite', $household, $centre, $pseudo, $lang, $token, $to, $words)) {
                continue;
            }
            if ($row) {
                $DB->update_record(self::T, (object) ['id' => $row->id, 'channel' => 'both', 'mailsent' => $now]);
            } else {
                $DB->insert_record(self::T, (object) ['centreid' => $centre->id, 'eventid' => $event->id,
                    'householdid' => $household->id, 'userid' => $userid, 'channel' => 'email', 'bulkid' => 0,
                    'mailsent' => $now, 'timecreated' => $now]);
            }
            households::told($household, $userid, 'email', $note, true);
            $sent++;
        }
        return $sent;
    }

    /**
     * The letter of an act once more to a family that had it and did not get it (its mail came back, and now it has the right
     * address): only while the act is to come and the family still lets the centre write to it.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @return bool Whether it went.
     */
    public static function send_again(\stdClass $event, \stdClass $household): bool {
        global $DB;
        $centre = centres::get((int) $household->centreid);
        if (
            !$centre || (int) $event->centreid !== (int) $centre->id || !self::fits($event, time()) || (int) $household->noinvites ||
                !writeby::may_send($household, 'openinvite') || !self::by_mail($household, $centre, familymail::may_email($household)) ||
                $DB->record_exists(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $household->id])
        ) {
            return false;
        }
        $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
        $token = households::new_link($household, 'letter');
        $to = self::letter_address($household, $centre);
        if ($to === '' || !familymail::send('openinvite', $household, $centre, self::of_letter($event), $lang, $token, $to)) {
            return false;
        }
        households::told($household, 0, 'email', get_string('invite_note_again', self::C, format_string($event->name)), true);
        return true;
    }

    /**
     * Why the letter of an act did not go to a family it was queued for.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @return string One of notices::REASONS.
     */
    protected static function why_not(\stdClass $event, \stdClass $household): string {
        global $DB;
        if ($DB->record_exists(events::SIGNUPS, ['eventid' => $event->id, 'householdid' => $household->id])) {
            return 'signedup';
        }
        if ((int) $household->noinvites) {
            return 'noinvites';
        }
        $why = notices::why($household);
        if ($why === 'nopermission' && familymail::may_email($household)) {
            // It did allow its email: something else changed (a child enrolled, a family of the centre now).
            return 'changed';
        }
        return in_array($why, ['silent', 'noemail', 'nopermission'], true) ? $why : 'changed';
    }

    /**
     * Of the last letters of an act, those that were on their way and did not go, and why.
     *
     * @param int $eventid
     * @return array|false text and names (name, url, note)
     */
    public static function skipped(int $eventid) {
        global $DB;
        $rows = $DB->get_records_select(
            notices::T,
            "eventid = ? AND kind = 'openinvite' AND state = 'skipped' AND " . households::NOTSAMPLE,
            [$eventid],
            'id DESC'
        );
        $names = [];
        foreach ($rows as $row) {
            if (isset($names[$row->householdid]) || $DB->record_exists(self::T, ['eventid' => $eventid, 'householdid' => $row->householdid])) {
                continue;
            }
            $adults = $DB->get_records(households::ADULTS, ['householdid' => $row->householdid], 'id ASC');
            $names[$row->householdid] = ['name' => panel::adults_line($adults) ?: get_string('invitecheck_noname', self::C),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $row->householdid]))->out(false),
                'note' => get_string('notice_why_' . ($row->reason ?: 'changed'), self::C)];
        }
        $n = count($names);
        return $n ? ['text' => get_string($n === 1 ? 'invite_skipped_one' : 'invite_skipped', self::C, $n), 'names' => array_values($names)] :
            false;
    }

    /**
     * A family invited looked at the page its letter opens, for a moment, for the first time: never a robot that only
     * follows the links of a mail, since the page itself says it.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @param int $now
     * @param string $via The way it came by: email or phone, as way_of() says.
     */
    public static function opened(\stdClass $event, \stdClass $household, int $now = 0, string $via = ''): void {
        global $DB;
        $row = $DB->get_record(self::T, ['eventid' => $event->id, 'householdid' => $household->id]);
        if ($row && !(int) $row->opened) {
            $DB->set_field(self::T, 'opened', $now ?: time(), ['id' => $row->id]);
        }
        self::note_way($event, $household, $via);
    }

    /**
     * The way a family came to the page of its letter: by the message of its phone when the link it opened is the one that
     * message carries; by its mail if not.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @param string $kept What the page keeps of the letter (joinpage::letter_of()).
     * @return string email or phone; empty when it is no letter of this act.
     */
    public static function way_of(\stdClass $event, \stdClass $household, string $kept): string {
        global $DB;
        $row = $DB->get_record(self::T, ['eventid' => $event->id, 'householdid' => $household->id], 'id, phonelink, channel');
        if (!$row || !preg_match('/^(\d+)\./', $kept, $m)) {
            return '';
        }
        return (int) $row->phonelink && (int) $row->phonelink === (int) $m[1] ? 'phone' : ((string) $row->channel === 'phone' ? 'phone' : 'email');
    }

    /**
     * The way a family first came to its letter, kept once: what worked.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @param string $via email or phone.
     */
    public static function note_way(\stdClass $event, \stdClass $household, string $via): void {
        global $DB;
        if (!in_array($via, ['email', 'phone'], true)) {
            return;
        }
        $DB->set_field_select(
            self::T,
            'via',
            $via,
            "eventid = ? AND householdid = ? AND (via IS NULL OR via = '')",
            [$event->id, $household->id]
        );
    }

    /**
     * What a family invited said last.
     *
     * @param int $eventid
     * @param int $householdid
     * @return string yes, no or empty.
     */
    public static function answer(int $eventid, int $householdid): string {
        global $DB;
        return (string) $DB->get_field(self::T, 'reply', ['eventid' => $eventid, 'householdid' => $householdid]);
    }

    /**
     * A family invited says whether it comes, with one touch: it is kept at once, with its place, and it can change its
     * mind. «Not this time» only says that: it takes away no permission.
     *
     * @param \stdClass $event
     * @param \stdClass $household
     * @param string $reply yes or no.
     * @param int $people How many come; 0, as many adults as the family has.
     * @param int $now
     * @return bool Whether it was kept; false when there is no room left, or nothing is open.
     */
    public static function reply(\stdClass $event, \stdClass $household, string $reply, int $people = 0, int $now = 0): bool {
        global $DB;
        $now = $now ?: time();
        if (!in_array($reply, ['yes', 'no'], true) || empty($event->open) || !empty($event->cancelled) || $event->timeend < $now) {
            return false;
        }
        $already = (int) $DB->get_field(events::SIGNUPS, 'people', ['eventid' => $event->id, 'householdid' => $household->id]);
        $name = format_string($event->name);
        $asked = $people;
        if ($reply === 'yes') {
            $people = max(1, min(events::MAXPEOPLE, $people ?: ($already ?: max(1, count(households::full($household)->adults)))));
            $slots = events::slots((int) $event->id);
            if (!$slots && !$asked && !$already && $people > 1 && !events::has_room($event, $people)) {
                // As many adults as they are do not fit: one, and the page lets them see it.
                $people = 1;
            }
            if ($slots || !events::has_room($event, $people, $already)) {
                // With turns there is one to choose, on the whole form; without room, nothing is promised.
                return false;
            }
            if ($people !== $already) {
                events::sign_up($event, (int) $household->id, $people);
            }
            if (!$already) {
                // What they said, in their history, once: nobody has to answer it.
                households::received($household, 'event', get_string('invite_yes_note', self::C, (object) ['event' => $name,
                    'people' => $people]), false, $now, 0, '', '', false);
            }
        } else if (self::answer((int) $event->id, (int) $household->id) !== 'no') {
            $line = get_string('invite_no_note', self::C, $name);
            if ($already) {
                events::unsign($event, $household, $line, false);
            } else {
                households::note($household, 0, $line);
            }
        }
        $row = $DB->get_record(self::T, ['eventid' => $event->id, 'householdid' => $household->id]);
        if ($row) {
            $DB->update_record(self::T, (object) ['id' => $row->id, 'opened' => (int) $row->opened ?: $now, 'reply' => $reply,
                'replied' => $now]);
        } else {
            // From a letter sent by hand, or the one tried on: kept all the same.
            $DB->insert_record(self::T, (object) ['centreid' => $event->centreid, 'eventid' => $event->id, 'householdid' =>
                $household->id, 'userid' => 0, 'channel' => 'email', 'bulkid' => 0, 'opened' => $now, 'timecreated' => $now,
                'reply' => $reply, 'replied' => $now]);
        }
        return true;
    }

    /**
     * How the letter of an act is going, in numbers: sent, on their way, back, opened, and who comes and who does not.
     *
     * @param int $eventid
     * @return int[] sent, queued, bounced, opened, yes and no.
     */
    public static function tally(int $eventid): array {
        global $DB;
        $rows = $DB->get_records_select(self::T, 'eventid = ? AND ' . households::NOTSAMPLE, [$eventid], '', 'id, opened, reply, channel, via');
        [$queued] = self::on_the_way($eventid);
        $out = ['sent' => count($rows), 'queued' => $queued, 'bounced' => count(bounces::of_act($eventid)),
            'opened' => count(array_filter($rows, fn($r) => (int) $r->opened || (string) $r->reply !== '')),
            'yes' => count(array_filter($rows, fn($r) => $r->reply === 'yes')),
            'no' => count(array_filter($rows, fn($r) => $r->reply === 'no'))];
        // By more than one way: how many went by phone, and by which way they came.
        $phone = count(array_filter($rows, fn($r) => in_array((string) $r->channel, ['phone', 'both'], true)));
        if ($phone) {
            $out += ['phone' => $phone, 'viaemail' => count(array_filter($rows, fn($r) => (string) $r->via === 'email')),
                'viaphone' => count(array_filter($rows, fn($r) => (string) $r->via === 'phone'))];
        }
        return $out;
    }

    /**
     * What the families invited to an act said: who comes, who does not, who opened it and said nothing, and who has not
     * opened it.
     *
     * @param int $eventid
     * @return array yes, no, opened and none, each a list of households by id => when.
     */
    public static function replies(int $eventid): array {
        global $DB;
        $out = ['yes' => [], 'no' => [], 'opened' => [], 'none' => []];
        $rows = $DB->get_records_select(self::T, 'eventid = ? AND ' . households::NOTSAMPLE, [$eventid], 'replied DESC, opened DESC, timecreated DESC, id ASC');
        foreach ($rows as $row) {
            $key = in_array((string) $row->reply, ['yes', 'no'], true) ? (string) $row->reply : ((int) $row->opened ? 'opened' : 'none');
            // When it said it, opened it or, if neither, when the letter went.
            $out[$key][(int) $row->householdid] = (int) $row->replied ?: ((int) $row->opened ?: (int) $row->timecreated);
        }
        return $out;
    }
}
