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
 * Looking over the invitation of an act before it goes: what a person would want to have seen before hundreds of letters
 * leave. It says what there is, never stops anything.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invitecheck {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] Why a family gets no letter, in the order it is said. */
    const WHY = ['invited', 'signedup', 'centre', 'closed', 'silent', 'noinvites', 'noemail', 'nopermission', 'sameaddress'];

    /**
     * The whole of it.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param array $chosen Those it would go to, as invites::eligible() gives them, by id.
     * @param int $userid Who is going to send it.
     * @param int $now
     * @return array rows (ok, text, names: name and url, hasnames, open), and greetings by household id.
     */
    public static function of(\stdClass $event, \stdClass $centre, array $chosen, int $userid, int $now): array {
        $greetings = [];
        $unnamed = [];
        $wrong = [];
        foreach ($chosen as $id => $household) {
            if (empty($household->reachemail)) {
                continue;
            }
            $first = familymail::first_name($household);
            $lang = in_array($household->lang, lists::LANGS, true) ? $household->lang : ($centre->lang ?: 'es');
            $greetings[(int) $id] = $first === '' ? publiclang::str('mail_letter_hello_noname', $lang) :
                publiclang::str('mail_letter_hello', $lang, $first);
            if ($first === '') {
                $unnamed[] = (int) $id;
            }
            $why = emailcheck::warning(households::recipient($household));
            if ($why !== '') {
                $wrong[(int) $id] = $why;
            }
        }
        $rows = [];
        $add = function (bool $ok, string $text, array $names = [], bool $open = false) use (&$rows): void {
            $rows[] = ['ok' => $ok, 'text' => $text, 'names' => $names, 'hasnames' => (bool) $names, 'open' => $open];
        };
        // The letter itself.
        $tried = self::tried($event, $centre, $userid);
        $add($tried, self::str($tried ? 'invitecheck_tried' : 'invitecheck_nottried'));
        foreach (self::missing($event, $centre, count($chosen)) as $text) {
            $add(false, $text);
        }
        $add(true, self::str('invitecheck_answer_' . self::answers($event)));
        // Whom it goes to.
        $n = count($greetings);
        if ($n) {
            $add(!$unnamed, $unnamed ? self::count('invitecheck_unnamed', count($unnamed), $n) : self::count('invitecheck_named', $n), self::names(
                $unnamed
            ));
        }
        if ($wrong) {
            $add(false, self::count('invitecheck_wrong', count($wrong)), self::names(array_keys($wrong), $wrong), true);
        }
        $reply = trim((string) ($centre->email ?? ''));
        $add($reply !== '', $reply !== '' ? self::str('invitecheck_reply', $reply) : self::str('invitecheck_noreply'));
        // And whom it does not.
        foreach (self::left_out($event, $centre, $chosen) as $why => $ids) {
            $add(true, self::count('invitecheck_out_' . $why, count($ids)), self::names($ids));
        }
        return ['rows' => $rows, 'wrong' => (bool) array_filter($rows, fn($row) => !$row['ok']), 'greetings' => $greetings];
    }

    /**
     * How the families answer it: with one touch, choosing a turn, on the whole form, or not at all.
     *
     * @param \stdClass $event
     * @return string touch, turns, form or free.
     */
    public static function answers(\stdClass $event): string {
        if ((int) ($event->free ?? 0) === acts::FREE) {
            return 'free';
        }
        if (events::slots((int) $event->id)) {
            return 'turns';
        }
        return empty($event->tailored) ? 'touch' : 'form';
    }

    /**
     * What the letter would lack: the place, what it is about, room for so many.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $families
     * @return string[]
     */
    public static function missing(\stdClass $event, \stdClass $centre, int $families): array {
        $out = [];
        if (trim((string) ($event->place ?? '')) === '' && trim((string) ($centre->address ?? '')) === '') {
            $out[] = self::str('invitecheck_noplace');
        }
        if ($event->kind === 'other' && trim((string) ($event->topic ?? '')) === '' && trim((string) ($event->about ?? '')) === '') {
            $out[] = self::str('invitecheck_noabout');
        }
        $room = (int) $event->capacity ? max(0, (int) $event->capacity - events::people((int) $event->id)) : 0;
        if ((int) $event->capacity && $room < $families) {
            $out[] = self::str('invitecheck_room', (object) ['room' => $room, 'n' => $families]);
        }
        return $out;
    }

    /**
     * The letter as it goes now, to know whether it is the one that was tried on.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return string
     */
    public static function mark(\stdClass $event, \stdClass $centre): string {
        publiclang::$self = false;
        [$subject, , $text] = familymail::letter('', $centre, invites::of_letter($event), (string) ($centre->lang ?: 'es'), '', (string)
            ($event->invitewords ?? ''));
        return sha1($subject . "\n" . $text);
    }

    /**
     * Keeps that a person sent it to themselves as it is now.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     */
    public static function keep_tried(\stdClass $event, \stdClass $centre, int $userid): void {
        set_user_preference('local_oksigeniaeguahe_tried', $event->id . ':' . self::mark($event, $centre), $userid);
    }

    /**
     * Whether whoever sends it has seen it arrive as it is now.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     * @return bool
     */
    public static function tried(\stdClass $event, \stdClass $centre, int $userid): bool {
        return get_user_preferences('local_oksigeniaeguahe_tried', '', $userid) === $event->id . ':' . self::mark($event, $centre);
    }

    /**
     * The families that get no letter, each with the first reason that applies.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param array $chosen By id.
     * @return array Reason => ids, in the order of WHY; those left out by hand are not in it.
     */
    public static function left_out(\stdClass $event, \stdClass $centre, array $chosen): array {
        global $DB;
        $centreid = (int) $event->centreid;
        $could = $chosen + invites::eligible($event);
        $invited = array_flip($DB->get_fieldset_select(invites::T, 'householdid', 'eventid = ?', [$event->id])) + invites::queued((int) $event->id);
        $signed = array_flip($DB->get_fieldset_select(events::SIGNUPS, 'householdid', 'eventid = ?', [$event->id]));
        $allowed = households::emailable($centreid);
        $with = array_flip($DB->get_fieldset_select(households::ADULTS, 'householdid', "centreid = ? AND COALESCE(email, '') <> ''", [$centreid]));
        $stages = [];
        foreach ($DB->get_records(households::CHILDREN, ['centreid' => $centreid], 'id ASC', 'id, householdid, stage, lefttime') as $child) {
            $stages[$child->householdid][] = $child->stage === 'enrolled' && $child->lefttime ? 'left' : $child->stage;
        }
        $out = array_fill_keys(self::WHY, []);
        foreach ($DB->get_records(households::T, ['centreid' => $centreid, 'guest' => 0, 'roster' => 0], 'id ASC') as $id => $household) {
            if (isset($could[$id])) {
                continue;
            }
            $mine = $stages[$id] ?? [];
            $chosenways = writeby::chosen($household);
            if (isset($signed[$id])) {
                $why = 'signedup';
            } else if (isset($invited[$id])) {
                $why = 'invited';
            } else if (!empty($household->incentre) || in_array('enrolled', $mine, true)) {
                $why = 'centre';
            } else if ($mine && !array_diff($mine, ['closed', 'left'])) {
                $why = 'closed';
            } else if (writeby::silent($household)) {
                $why = 'silent';
            } else if ((int) $household->noinvites) {
                $why = 'noinvites';
            } else if (!isset($with[$id])) {
                $why = 'noemail';
            } else {
                $why = empty($allowed[$id]) && !$chosenways ? 'nopermission' : 'sameaddress';
            }
            $out[$why][] = (int) $id;
        }
        return array_filter($out);
    }

    /**
     * The names of some families, to open each.
     *
     * @param int[] $ids
     * @param string[] $notes By id, what to say of each.
     * @return array name, url and note.
     */
    protected static function names(array $ids, array $notes = []): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        $adults = [];
        [$in, $params] = $DB->get_in_or_equal($ids);
        foreach ($DB->get_records_select(households::ADULTS, "householdid $in", $params, 'id ASC') as $adult) {
            $adults[(int) $adult->householdid][] = $adult;
        }
        $out = [];
        foreach ($ids as $id) {
            $out[] = ['name' => panel::adults_line($adults[$id] ?? []) ?: self::str('invitecheck_noname'),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false), 'note' => $notes[$id] ?? ''];
        }
        usort($out, fn($a, $b) => strcoll(\core_text::strtolower($a['name']), \core_text::strtolower($b['name'])));
        return $out;
    }

    /**
     * A string of the panel.
     *
     * @param string $key
     * @param mixed $a
     * @return string
     */
    protected static function str(string $key, $a = null): string {
        return get_string($key, self::C, $a);
    }

    /**
     * A string that counts, in one or in many.
     *
     * @param string $key
     * @param int $n
     * @param int $of
     * @return string
     */
    protected static function count(string $key, int $n, int $of = 0): string {
        return get_string($key . ($n === 1 ? '_one' : ''), self::C, $of ? (object) ['n' => $n, 'of' => $of] : $n);
    }
}
