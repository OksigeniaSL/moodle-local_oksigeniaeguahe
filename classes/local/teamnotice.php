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
 * «A new family»: the team hears of each family that comes on its own (a form, the sign-up of an act, the mailbox, a
 * message), by the bell and by mail, without having to look at the inbox. Never of what the team itself wrote down or
 * brought from its sheets. Those the centre chose get it; with nobody chosen, those who usually answer, and else whoever
 * runs its settings. Several at once go in one notice, one family a line.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teamnotice {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int More families than this at once go in a single notice. */
    const TOGETHER = 3;

    /** @var int The longest piece of what they wrote that goes in the notice. */
    const SNIPPET = 240;

    /**
     * Who the centre chose to hear of each new family.
     *
     * @param int $centreid
     * @return int[] User ids.
     */
    public static function chosen(int $centreid): array {
        return array_values(array_filter(array_map('intval', explode(',', (string) get_config(self::C, 'notifynew_' . $centreid)))));
    }

    /**
     * One person of the team in or out of those who hear of each new family.
     *
     * @param int $centreid
     * @param int $userid Only a member of the team of the centre.
     */
    public static function toggle(int $centreid, int $userid): void {
        if (!isset(centres::members($centreid)[$userid])) {
            return;
        }
        $chosen = self::chosen($centreid);
        $chosen = in_array($userid, $chosen, true) ? array_diff($chosen, [$userid]) : array_merge($chosen, [$userid]);
        set_config('notifynew_' . $centreid, implode(',', array_unique($chosen)), self::C);
    }

    /**
     * Who hears of each new family: those chosen; with nobody chosen, those who usually answer; else whoever runs the
     * settings of the centre. Only members of its team.
     *
     * @param \stdClass $centre
     * @return \stdClass[] Users by id.
     */
    public static function recipients(\stdClass $centre): array {
        $members = centres::members((int) $centre->id);
        $chosen = array_intersect_key($members, array_flip(self::chosen((int) $centre->id)));
        if ($chosen) {
            return $chosen;
        }
        $answer = [];
        foreach (centres::answerers($centre) as $host) {
            if (isset($members[(int) $host->userid])) {
                $answer[(int) $host->userid] = $members[(int) $host->userid];
            }
        }
        return $answer ?: array_filter($members, fn($m) => !empty($m->admin));
    }

    /**
     * The families that came on their own between two times: their first word was theirs, and no person of the team wrote
     * it down, by hand or as a row of its sheet (that is a note). A contact of the ads that comes in through the sheet of
     * the team is a family that wrote. One an entry brought counts from when it came in, though it wrote days before.
     *
     * @param int $centreid
     * @param int $from After this time.
     * @param int $to Up to this one.
     * @return \stdClass[] Households by id.
     */
    public static function arrived(int $centreid, int $from, int $to): array {
        global $DB;
        // The guest of an act that now asks for a place, or a family that had gone, came on its own that day.
        $back = $DB->get_records_select(
            households::T,
            'centreid = :centreid AND roster = 0 AND guest = 0 AND cameback > :from AND cameback <= :to',
            ['centreid' => $centreid, 'from' => $from, 'to' => $to],
            'cameback ASC, id ASC'
        );
        $homes = $DB->get_records_select(
            households::T,
            'centreid = :centreid AND roster = 0 AND guest = 0 AND timecreated > :from AND timecreated <= :to',
            ['centreid' => $centreid, 'from' => min($from, $to - entries::PASTDAYS * DAYSECS), 'to' => $to],
            'timecreated ASC, id ASC'
        );
        if (!$homes) {
            return $back;
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($homes), SQL_PARAMS_NAMED);
        // An entry leaves the family its link in the same moment it comes in.
        $links = $DB->get_records_sql_menu(
            'SELECT householdid, MIN(timecreated) FROM {' . households::LINKS . "}
              WHERE householdid $in AND reason = :reason GROUP BY householdid",
            $params + ['reason' => 'entry']
        );
        $firsts = [];
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            "householdid $in",
            $params,
            'timecreated ASC, id ASC',
            'id, householdid, direction, userid, entryid'
        );
        foreach ($rows as $row) {
            $firsts[(int) $row->householdid] = $firsts[(int) $row->householdid] ?? $row;
        }
        $rows->close();
        return array_filter($homes, function ($h) use ($firsts, $links, $from, $to) {
            $first = $firsts[(int) $h->id] ?? null;
            if (!$first || $first->direction !== 'in' || (int) $first->userid) {
                return false;
            }
            $came = max((int) $h->timecreated, (int) $first->entryid ? (int) ($links[$h->id] ?? 0) : 0);
            return $came > $from && $came <= $to;
        }) + $back;
    }

    /**
     * A family that was not in admissions asks for a place on its own: the team hears of it as of a new one.
     *
     * @param int $householdid
     */
    public static function back(int $householdid): void {
        global $DB;
        $DB->set_field(households::T, 'cameback', time(), ['id' => $householdid]);
    }

    /**
     * A family erased its data: the notices of the bell that said it came go too.
     *
     * @param int $householdid
     * @return int How many.
     */
    public static function forget(int $householdid): int {
        global $DB;
        $url = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $householdid]))->out(false);
        $like = $DB->sql_like('fullmessage', ':mention', false);
        $mine = fn($row) => $row->contexturl === $url || preg_match('/' . preg_quote($url, '/') . '(?!\d)/', (string) $row->fullmessage);
        $rows = array_filter($DB->get_records_select(
            'notifications',
            "component = :component AND eventtype = :type AND $like",
            ['component' => self::C, 'type' => 'newfamily', 'mention' => '%' . $DB->sql_like_escape($url) . '%'],
            'id ASC',
            'id, contexturl, fullmessage'
        ), $mine);
        if ($rows) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($rows));
            $DB->delete_records_select('message_popup_notifications', "notificationid $in", $params);
            $DB->delete_records_select('notifications', "id $in", $params);
        }
        return count($rows);
    }

    /**
     * A family in a line for the team: who they are, the children and their courses, where they came in by and from,
     * and what they wrote.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @return array name (the adults), line (the rest), wrote (a piece of what they wrote, or '') and url (their file).
     */
    public static function line(\stdClass $centre, \stdClass $household): array {
        global $DB;
        $c = self::C;
        $full = households::full($household);
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name');
        $children = [];
        foreach ($full->children as $child) {
            $name = trim((string) $child->firstname) !== '' ? trim((string) $child->firstname) : get_string('chat_child_noname', $c);
            $children[] = $name . (isset($offers[$child->offerid]) ? ' (' . format_string(
                $offers[$child->offerid],
                true,
                ['escape' => false]
            ) . ')' : '');
        }
        $first = $DB->get_records(
            households::CONTACTS,
            ['householdid' => $household->id, 'direction' => 'in'],
            'timecreated ASC, id ASC',
            'id, channel, entryid, detail, body',
            0,
            1
        );
        $first = $first ? reset($first) : null;
        $door = [];
        if ($first && $first->entryid) {
            $door[] = format_string(
                (string) $DB->get_field(entries::T, 'name', ['id' => $first->entryid]),
                true,
                ['escape' => false]
            );
        } else {
            $door[] = get_string('channel_' . (in_array($household->firstchannel, lists::CHANNELS, true) ?
                $household->firstchannel : 'other'), $c);
        }
        if ($first && trim((string) $first->detail) !== '') {
            $door[] = trim((string) $first->detail);
        }
        $site = arrival::of($household)[0];
        if ($site !== '') {
            $door[] = get_string('teamnotice_from', $c, arrival::shown($site));
        }
        $parts = [];
        if ($children) {
            $parts[] = implode(', ', $children);
        }
        $parts[] = implode(' · ', $door);
        $wrote = $first ? trim(preg_replace('/\s+/u', ' ', html_to_text((string) $first->body, 0, false))) : '';
        return [
            'name' => panel::adults_line($full->adults) ?: get_string('chat_family_noname', $c),
            'line' => implode(' — ', $parts),
            'wrote' => \core_text::strlen($wrote) > self::SNIPPET ? \core_text::substr($wrote, 0, self::SNIPPET - 1) . '…' : $wrote,
            'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
        ];
    }

    /**
     * Tells the team of the families that came: one notice each, or one for all when they are more than TOGETHER.
     *
     * @param \stdClass $centre
     * @param \stdClass[] $households
     * @return int How many notices were sent.
     */
    public static function send(\stdClass $centre, array $households): int {
        if (!$households || !empty($centre->practice)) {
            return 0;
        }
        $sent = 0;
        foreach (self::recipients($centre) as $member) {
            $user = \core_user::get_user((int) $member->id);
            if (!$user || !empty($user->suspended) || !empty($user->deleted)) {
                continue;
            }
            // In the language of the person who reads it.
            $lang = force_current_language($user->lang ?: ($centre->lang ?: 'es'));
            try {
                $lines = array_map(fn($h) => self::line($centre, $h), array_values($households));
                $groups = count($lines) > self::TOGETHER ? [$lines] : array_map(fn($l) => [$l], $lines);
                foreach ($groups as $group) {
                    $sent += self::message($centre, $user, $group) ? 1 : 0;
                }
            } finally {
                force_current_language($lang);
            }
        }
        return $sent;
    }

    /**
     * Tells the team, once for each silence, of the entries that may not be bringing all there is: whoever waits for the
     * notice of each new family would take its silence for nobody writing. By day only.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int How many notices were sent.
     */
    public static function silences(\stdClass $centre, int $now): int {
        global $DB;
        $hour = (int) userdate($now, '%H', 99, false);
        if (!empty($centre->practice) || $hour < 8 || $hour >= 20) {
            return 0;
        }
        $sent = 0;
        foreach (pulse::silent($centre, $now) as $pulse) {
            if (
                (string) ($pulse['entry']->silenttold ?? '') === $pulse['key'] ||
                    moments::knows((int) $centre->id, $pulse['key'])
            ) {
                continue;
            }
            $DB->set_field(entries::T, 'silenttold', $pulse['key'], ['id' => $pulse['id']]);
            foreach (self::recipients($centre) as $member) {
                $user = \core_user::get_user((int) $member->id);
                if (!$user || !empty($user->suspended) || !empty($user->deleted)) {
                    continue;
                }
                $lang = force_current_language($user->lang ?: ($centre->lang ?: 'es'));
                try {
                    $url = (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $pulse['id']]))->out(false);
                    $words = pulse::words($pulse, $now);
                    $message = new \core\message\message();
                    $message->component = 'local_oksigeniaeguahe';
                    $message->name = 'entrysilent';
                    $message->userfrom = \core_user::get_noreply_user();
                    $message->userto = $user;
                    $message->subject = get_string('pulse_subject', self::C, $pulse['name']);
                    $message->fullmessage = $words . "\n\n" . get_string('pulse_do', self::C) . "\n" . $url;
                    $message->fullmessageformat = FORMAT_PLAIN;
                    $message->fullmessagehtml = '<p>' . s($words) . '</p><p>' . s(get_string('pulse_do', self::C)) .
                        '</p><p><a href="' . s($url) . '">' . s(get_string('pulse_look', self::C)) . '</a></p>';
                    $message->smallmessage = $message->subject;
                    $message->notification = 1;
                    $message->contexturl = $url;
                    $message->contexturlname = get_string('pulse_look', self::C);
                    $sent += message_send($message) ? 1 : 0;
                } finally {
                    force_current_language($lang);
                }
            }
        }
        return $sent;
    }

    /**
     * Tells the team that a message was sent on to the mailbox and nothing in it says whose it is: it is not lost in silence.
     *
     * @param \stdClass $centre
     * @param \stdClass $entry
     * @param string $subject Of the message.
     * @param string $from Who sent it on.
     * @return int How many notices were sent.
     */
    public static function lost_mail(\stdClass $centre, \stdClass $entry, string $subject, string $from): int {
        if (!empty($centre->practice)) {
            return 0;
        }
        $sent = 0;
        $url = (new \moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id]))->out(false);
        foreach (self::recipients($centre) as $member) {
            $user = \core_user::get_user((int) $member->id);
            if (!$user || !empty($user->suspended) || !empty($user->deleted)) {
                continue;
            }
            $lang = force_current_language($user->lang ?: ($centre->lang ?: 'es'));
            try {
                $words = get_string('teamnotice_lost', self::C, (object) ['subject' => $subject !== '' ? $subject : '—', 'from' => $from,
                    'entry' => format_string($entry->name, true, ['escape' => false])]);
                $message = new \core\message\message();
                $message->component = 'local_oksigeniaeguahe';
                $message->name = 'entrysilent';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = get_string('teamnotice_lost_subject', self::C);
                $message->fullmessage = $words . "\n\n" . $url;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = '<p>' . s($words) . '</p><p><a href="' . s($url) . '">' .
                    s(get_string('household_new', self::C)) . '</a></p>';
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                $message->contexturl = $url;
                $message->contexturlname = get_string('household_new', self::C);
                $sent += message_send($message) ? 1 : 0;
            } finally {
                force_current_language($lang);
            }
        }
        return $sent;
    }

    /**
     * One notice to one person, for one family or several.
     *
     * @param \stdClass $centre
     * @param \stdClass $user
     * @param array[] $lines From line().
     * @return bool
     */
    protected static function message(\stdClass $centre, \stdClass $user, array $lines): bool {
        $c = self::C;
        $one = count($lines) === 1;
        $centrename = format_string($centre->name, true, ['escape' => false]);
        $subject = $one ? get_string('teamnotice_subject_one', $c, (object) ['name' => $lines[0]['name'],
            'centre' => $centrename]) : get_string('teamnotice_subject_many', $c, (object) ['n' => count($lines),
            'centre' => $centrename]);
        $text = [];
        $html = [];
        foreach ($lines as $l) {
            $text[] = $l['name'] . ' — ' . $l['line'] . ($l['wrote'] !== '' ? "\n" . panel::quote($l['wrote']) : '') . "\n" . $l['url'];
            $html[] = '<li><a href="' . s($l['url']) . '"><b>' . s($l['name']) . '</b></a><br>' . s($l['line']) .
                ($l['wrote'] !== '' ? '<br><i>' . s(panel::quote($l['wrote'])) . '</i>' : '') . '</li>';
        }
        $inbox = (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false);
        $message = new \core\message\message();
        $message->component = 'local_oksigeniaeguahe';
        $message->name = 'newfamily';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = implode("\n\n", $text) . "\n\n" . get_string('teamnotice_inbox', $c, $inbox);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<ul>' . implode('', $html) . '</ul><p><a href="' . s($inbox) . '">' .
            get_string('teamnotice_inbox_link', $c) . '</a></p>';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $one ? $lines[0]['url'] : $inbox;
        $message->contexturlname = $one ? get_string('teamnotice_open', $c) : get_string('teamnotice_inbox_link', $c);
        return (bool) message_send($message);
    }
}
