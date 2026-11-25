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
 * What the centre sent each family by itself (the confirmation, the reminder, a change, the thanks), whether it went out, by
 * what way and, when it did not, why; what is still to go and when; and sending it again. What went before this was kept is
 * not known, never «sent».
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notices {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string */
    const T = 'local_oksigeniaeguahe_notice';

    /** @var string[] The kinds that are kept. */
    const KINDS = ['confirmation', 'reminder', 'change', 'cancelled', 'invitation', 'thanks', 'request', 'received', 'openinvite',
        'renewal'];

    /** @var string[] Those that can go again. */
    const AGAIN = ['confirmation', 'reminder', 'change', 'invitation'];

    /** @var string[] Why one did not go. */
    const REASONS = ['silent', 'nopermission', 'noemail', 'phone', 'mail', 'signedup', 'noinvites', 'changed', 'off'];

    /**
     * Keeps what happened with one.
     *
     * @param \stdClass $household
     * @param string $kind
     * @param \stdClass|null $signup The act or the visit it is of, as familymail::signup() gives it.
     * @param string $channel email or phone.
     * @param string $state sent, failed or skipped.
     * @param string $reason One of REASONS; empty when it went.
     * @param int $userid Who sent it again; 0, it went by itself.
     */
    public static function log(
        \stdClass $household,
        string $kind,
        ?\stdClass $signup,
        string $channel,
        string $state,
        string $reason = '',
        int $userid = 0
    ): void {
        global $DB;
        if (!in_array($kind, self::KINDS, true) && !($kind === 'reply' && $channel === 'phone')) {
            return;
        }
        $DB->insert_record(self::T, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
            'eventid' => (int) ($signup->eventid ?? 0),
            'kind' => $kind, 'what' => $signup ? \core_text::substr((string) ($signup->eventname ?? ''), 0, 255) : null,
            'timestart' => (int) ($signup->timestart ?? 0), 'channel' => $channel, 'state' => $state,
            'reason' => $reason === '' ? null : $reason, 'userid' => $userid, 'timecreated' => time()]);
    }

    /**
     * Sends one only to the phones of a family, when it does not go by email, and keeps what happened.
     *
     * @param string $kind
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $signup
     * @param string $lang
     * @return bool
     */
    public static function phones(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        \stdClass $signup,
        string $lang
    ): bool {
        $sent = messaging::notify($kind, $household, $centre, $signup, $lang);
        $reason = $sent ? self::pieces($sent) : self::why($household);
        self::log($household, $kind, $signup, $sent ? 'phone' : 'email', $sent ? self::onphone() : 'skipped', $reason);
        return $sent > 0;
    }

    /**
     * What is known of a notice just given to Marandu: in its queue, when Marandu tells afterwards how it went; else, sent.
     *
     * @return string
     */
    public static function onphone(): string {
        return method_exists('\local_oksigeniamarandu\api', 'item_reaches') ? 'inqueue' : 'sent';
    }

    /**
     * How many phones one is in the queue of, and how many of them it did not go out on, as it is kept while it is there: it has
     * gone nowhere only when it failed on all of them.
     *
     * @param int $phones
     * @param int $failed
     * @return string
     */
    public static function pieces(int $phones, int $failed = 0): string {
        return 'q' . max(1, $phones) . ($failed ? '-' . $failed : '');
    }

    /** @var string[] What the family must know: when it did not go out on any phone, it goes by email. */
    const MUSTKNOW = ['confirmation', 'reminder', 'change', 'invitation'];

    /**
     * Marandu says how a notice it had in its queue went. What the family must know and went nowhere goes by email; an answer of
     * the team that did not go out leaves the family waiting again, and says so.
     *
     * @param int $householdid
     * @param string $notice Its name in Marandu.
     * @param string $status sent, skipped, expired, failed or cancelled.
     * @param int $queued When it was put in the queue.
     * @param int $now
     * @return bool Whether it was one of ours.
     */
    public static function done(int $householdid, string $notice, string $status, int $queued = 0, int $now = 0): bool {
        global $DB;
        $now = $now ?: time();
        $kind = array_search($notice, messaging::NOTICES, true);
        $household = $kind ? $DB->get_record(households::T, ['id' => $householdid]) : null;
        if (!$household) {
            return false;
        }
        $rows = $DB->get_records_select(
            self::T,
            "householdid = ? AND kind = ? AND channel = 'phone' AND state IN ('inqueue', 'sent') AND timecreated >= ?",
            [$householdid, $kind, max(0, ($queued ?: $now - 3 * DAYSECS) - MINSECS)],
            'timecreated ASC, id ASC',
            '*',
            0,
            1
        );
        $row = reset($rows);
        if (!$row) {
            return false;
        }
        if ($status === 'sent') {
            $DB->set_field(self::T, 'state', 'sent', ['id' => $row->id]);
            return true;
        }
        if ($status === 'cancelled') {
            // It stopped being true, and it was taken back.
            $DB->update_record(self::T, (object) ['id' => $row->id, 'state' => 'skipped', 'reason' => 'off']);
            return true;
        }
        [$phones, $failed] = array_map('intval', explode('-', substr((string) $row->reason, 1), 2)) + [0, 0];
        if ($failed + 1 < $phones) {
            // It may still go out on another of their phones, or it has.
            $DB->set_field(self::T, 'reason', self::pieces($phones, $failed + 1), ['id' => $row->id]);
            return true;
        }
        $DB->update_record(self::T, (object) ['id' => $row->id, 'state' => $status === 'failed' ? 'failed' : 'skipped', 'reason' => 'phone']);
        if ($kind === 'reply') {
            households::note($household, 0, get_string('reply_not_out', self::C));
            if (!(int) $household->waitingsince) {
                $DB->update_record(households::T, (object) ['id' => $householdid, 'waitingsince' => $queued ?: $now, 'waitingdayonly' => 0]);
            }
            return true;
        }
        $centre = centres::get((int) $household->centreid);
        $signup = $centre ? familymail::signup($household, (int) $row->eventid) : null;
        $mailed = $DB->record_exists_select(
            self::T,
            "householdid = ? AND kind = ? AND eventid = ? AND channel = 'email' AND state = 'sent' AND timecreated >= ?",
            [$householdid, $kind, (int) $row->eventid, (int) $row->timecreated - MINSECS]
        );
        $still = $signup && (int) $signup->timestart > $now && ($kind !== 'reminder' ||
            userdate((int) $signup->timestart, '%Y%m%d', 99, false) === userdate($now + DAYSECS, '%Y%m%d', 99, false));
        if ($still && !$mailed && in_array($kind, self::MUSTKNOW, true)) {
            messaging::$bymail[] = $householdid;
            ob_start();
            try {
                familymail::send(
                    $kind,
                    $household,
                    $centre,
                    $signup,
                    (string) ($household->lang ?: $centre->lang),
                    $kind === 'reminder' ? null : households::new_link($household, $kind)
                );
            } catch (\Throwable $e) {
                self::log($household, $kind, $signup, 'email', 'failed', 'mail');
            } finally {
                ob_end_clean();
                messaging::$bymail = array_values(array_diff(messaging::$bymail, [$householdid]));
            }
        }
        return true;
    }

    /**
     * Why a notice did not go to a family, when nothing carried it: it asked for nothing, it has no email, nobody let the
     * centre write by it, or its way is a phone and that did not take it.
     *
     * @param \stdClass $household
     * @return string One of REASONS
     */
    public static function why(\stdClass $household): string {
        if (writeby::silent($household)) {
            return 'silent';
        }
        if (households::recipient($household) === '') {
            return 'noemail';
        }
        return writeby::chosen($household) === null ? 'nopermission' : 'phone';
    }

    /**
     * The notices of a family: what is still to go, first; then what went or did not, the last one first; and what is not
     * known.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array[] id (0 when it is not kept), kind, eventid, what (the act), start (its moment), channel, state (queued; blocked,
     *     it will not go as things are; sent, failed, skipped or unknown), reason, at, due, again (whether it can go again)
     */
    public static function of(\stdClass $household, \stdClass $centre, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $kept = $DB->get_records(self::T, ['householdid' => $household->id], 'timecreated DESC, id DESC', '*', 0, 60);
        $signups = [];
        foreach (households::full($household)->signups as $signup) {
            $signups[(int) $signup->eventid] = $signup;
        }
        $out = [];
        $zone = \core_date::get_server_timezone_object();
        $reminded = [];
        foreach ($kept as $row) {
            if ($row->kind === 'reminder') {
                $reminded[(int) $row->eventid] = true;
            }
        }
        foreach ($signups as $eventid => $signup) {
            $start = (int) $signup->timestart;
            if ($start <= $now || (!empty($signup->eventtailored) && empty($signup->eventhousehold))) {
                continue;
            }
            $eve = (new \DateTimeImmutable('@' . $start))->setTimezone($zone)->modify('-1 day')->setTime(9, 0)->getTimestamp();
            if ((int) $signup->reminded) {
                if (empty($reminded[$eventid])) {
                    // Looked at before this was kept.
                    $out[] = self::row(['kind' => 'reminder', 'eventid' => $eventid, 'state' => 'unknown',
                        'at' => (int) $signup->reminded], $signups);
                }
            } else if (userdate($start, '%Y%m%d', 99, false) > userdate($now, '%Y%m%d', 99, false)) {
                $reach = self::may($household, $centre, 'reminder', actmail::is($signup));
                $out[] = self::row(['kind' => 'reminder', 'eventid' => $eventid, 'state' => $reach ? 'queued' : 'blocked',
                    'reason' => $reach ? '' : self::why($household), 'due' => max($eve, $now)], $signups);
            }
        }
        foreach ($kept as $row) {
            if (!in_array($row->kind, self::KINDS, true)) {
                // An answer of the team in the queue of the phones: it is in their history, with what it said.
                continue;
            }
            $start = (int) ($signups[(int) $row->eventid]->timestart ?? 0);
            $out[] = self::row(['id' => (int) $row->id, 'kind' => $row->kind, 'eventid' => (int) $row->eventid,
                'what' => format_string((string) $row->what), 'start' => (int) $row->timestart, 'channel' => $row->channel,
                'state' => $row->state, 'reason' => in_array($row->reason, self::REASONS, true) ? $row->reason : '', 'at' => (int) $row->timecreated,
                'again' => in_array($row->kind, self::AGAIN, true) && $start > $now &&
                    self::may($household, $centre, $row->kind, actmail::is($signups[(int) $row->eventid] ?? null))], $signups);
        }
        return $out;
    }

    /**
     * Whether one could reach a family now: by email, the reminder only with its permission, or on the phones it joined.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $kind
     * @param bool $act Whether it is of an act that is not a visit: only by email.
     * @return bool
     */
    protected static function may(\stdClass $household, \stdClass $centre, string $kind, bool $act = false): bool {
        if (!writeby::may_send($household, $kind)) {
            return false;
        }
        $email = $kind === 'reminder' ? writeby::email_now($household, $centre, $kind, $act) :
            writeby::email_ok($household, $centre, $kind, $act);
        return ($email && households::recipient($household) !== '') ||
            (!$act && writeby::phone_ok($household, $kind) && messaging::reaches($household, $centre, $kind));
    }

    /**
     * A notice with all its parts.
     *
     * @param array $parts
     * @param \stdClass[] $signups By event.
     * @return array
     */
    protected static function row(array $parts, array $signups): array {
        $signup = $signups[$parts['eventid']] ?? null;
        return $parts + ['id' => 0, 'kind' => '', 'eventid' => 0, 'what' => $signup ? format_string($signup->eventname) : '',
            'start' => (int) ($signup->timestart ?? 0), 'channel' => '', 'state' => 'unknown', 'reason' => '', 'at' => 0, 'due' => 0,
            'again' => false];
    }

    /**
     * Sends one again.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $id
     * @return bool|null Whether it went; null when it cannot go again.
     */
    public static function resend(\stdClass $household, \stdClass $centre, int $id): ?bool {
        global $DB;
        $row = $DB->get_record(self::T, ['id' => $id, 'householdid' => $household->id]);
        $signup = $row ? familymail::signup($household, (int) $row->eventid) : null;
        if (
            !$row || !in_array($row->kind, self::AGAIN, true) || !$signup || (int) $signup->timestart <= time() ||
                !self::may($household, $centre, (string) $row->kind, actmail::is($signup))
        ) {
            return null;
        }
        $lang = (string) ($household->lang ?: $centre->lang);
        if ($row->kind === 'reminder' && !writeby::email_now($household, $centre, 'reminder', actmail::is($signup))) {
            // Without the email allowed, only on the phones it joined, as the one of the eve.
            return self::phones('reminder', $household, $centre, $signup, $lang);
        }
        if ($row->kind === 'change') {
            global $USER;
            $event = events::get((int) $row->eventid, (int) $centre->id);
            return $event ? (bool) panel_eventform::tell($centre, $event, [(int) $household->id], (int) $USER->id) : null;
        }
        ob_start();
        try {
            return familymail::send(
                (string) $row->kind,
                $household,
                $centre,
                $signup,
                $lang,
                $row->kind === 'reminder' ? null : households::new_link($household, (string) $row->kind)
            );
        } catch (\Throwable $e) {
            return false;
        } finally {
            ob_end_clean();
        }
    }
}
