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
     * The families that came on their own between two times: their first word was theirs (no person of the team wrote it
     * down) and not from a sheet of the team.
     *
     * @param int $centreid
     * @param int $from After this time.
     * @param int $to Up to this one.
     * @return \stdClass[] Households by id.
     */
    public static function arrived(int $centreid, int $from, int $to): array {
        global $DB;
        $homes = $DB->get_records_select(
            households::T,
            'centreid = :centreid AND roster = 0 AND timecreated > :from AND timecreated <= :to',
            ['centreid' => $centreid, 'from' => $from, 'to' => $to],
            'timecreated ASC, id ASC'
        );
        if (!$homes) {
            return [];
        }
        $teamsheets = array_flip($DB->get_fieldset_select(entries::T, 'id', 'centreid = ? AND teamsheet = 1', [$centreid]));
        [$in, $params] = $DB->get_in_or_equal(array_keys($homes), SQL_PARAMS_NAMED);
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
        return array_filter($homes, function ($h) use ($firsts, $teamsheets) {
            $first = $firsts[(int) $h->id] ?? null;
            return $first && $first->direction === 'in' && !(int) $first->userid && !isset($teamsheets[(int) $first->entryid]);
        });
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
            $text[] = $l['name'] . ' — ' . $l['line'] . ($l['wrote'] !== '' ? "\n«" . $l['wrote'] . '»' : '') . "\n" . $l['url'];
            $html[] = '<li><a href="' . s($l['url']) . '"><b>' . s($l['name']) . '</b></a><br>' . s($l['line']) .
                ($l['wrote'] !== '' ? '<br><i>«' . s($l['wrote']) . '»</i>' : '') . '</li>';
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
