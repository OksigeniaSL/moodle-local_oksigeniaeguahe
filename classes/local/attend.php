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
 * How the team shares the families (decision of Enrique, 8 November 2026): who attends each one (by default, the last
 * person who answered it), what was promised to it and for which day, the relay of the families of whoever is away,
 * the answers of always the centre keeps, and who is writing to a family right now. None of it is a list of tasks: it
 * lives in the family and in the order of the inbox.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attend {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string */
    const PROMISES = 'local_oksigeniaeguahe_promise';

    /** @var string */
    const REPLIES = 'local_oksigeniaeguahe_reply';

    /** @var int How many answers of always are suggested at once. */
    const SUGGESTED = 3;

    /** @var string[] Words too common to tell two questions apart. */
    const COMMON = ['para', 'como', 'pero', 'porque', 'que', 'una', 'uno', 'los', 'las', 'del', 'con', 'por', 'hay', 'tienen',
        'tiene', 'esta', 'este', 'muy', 'mas', 'hola', 'gracias', 'buenos', 'buenas', 'dias', 'tardes', 'saludo', 'saludos',
        'the', 'and', 'for', 'with', 'you', 'your', 'have', 'there', 'thanks', 'hello', 'uma', 'com', 'para', 'obrigado',
        'obrigada', 'voces', 'tem', 'sobre'];

    /**
     * Who attends a family, as the panel shows a person, with whether it is the one who looks and until when they are
     * away.
     *
     * @param \stdClass $household
     * @param int $viewer
     * @return array|null first, name, picture, initials, mine, away
     */
    public static function owner(\stdClass $household, int $viewer): ?array {
        global $DB;
        if (empty($household->ownerid)) {
            return null;
        }
        $user = $DB->get_record('user', ['id' => $household->ownerid, 'deleted' => 0]);
        if (!$user) {
            return null;
        }
        $away = self::away_until((int) $household->centreid, (int) $user->id);
        $person = panel::person($user);
        return $person + [
            'mine' => (int) $user->id === $viewer,
            'away' => $away ? get_string('panel_away_until', self::C, (object) ['who' => $person['first'],
                'day' => userdate($away, get_string('strftimedateshort', 'langconfig'))]) : '',
        ];
    }

    /**
     * Someone of the team takes a family; it says so in its conversation.
     *
     * @param \stdClass $household
     * @param int $userid
     */
    public static function take(\stdClass $household, int $userid): void {
        global $DB;
        if ((int) $household->ownerid === $userid) {
            return;
        }
        $DB->set_field(households::T, 'ownerid', $userid, ['id' => $household->id]);
        $household->ownerid = $userid;
        households::note($household, $userid, get_string('panel_took', self::C));
    }

    /**
     * The person who answers a family attends it, if nobody did yet.
     *
     * @param \stdClass $household
     * @param int $userid
     */
    public static function answered_by(\stdClass $household, int $userid): void {
        global $DB;
        if ($userid && empty($household->ownerid)) {
            $DB->set_field(households::T, 'ownerid', $userid, ['id' => $household->id]);
            $household->ownerid = $userid;
        }
    }

    /**
     * Until when a person of the team is away; 0 when here.
     *
     * @param int $centreid
     * @param int $userid
     * @return int
     */
    public static function away_until(int $centreid, int $userid): int {
        global $DB;
        $until = (int) $DB->get_field(centres::MEMBERS, 'awayuntil', ['centreid' => $centreid, 'userid' => $userid]);
        return $until > time() ? $until : 0;
    }

    /**
     * A person of the team says until when they are away (the end of that day), or that they are back (0).
     *
     * @param int $centreid
     * @param int $userid
     * @param int $until
     */
    public static function set_away(int $centreid, int $userid, int $until): void {
        global $DB;
        if ($until > 0) {
            $until = (new \DateTime('@' . $until))->setTimezone(\core_date::get_user_timezone_object())
                ->setTime(23, 59, 59)->getTimestamp();
        }
        $DB->set_field(centres::MEMBERS, 'awayuntil', max(0, $until), ['centreid' => $centreid, 'userid' => $userid]);
    }

    /**
     * What is promised to a family, from what comes next when answering it: text and, if it has one, its day.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $body
     * @param int $day Any moment of the day it is for; 0, no day.
     * @return int|null The promise, or null without text.
     */
    public static function promise(\stdClass $household, int $userid, string $body, int $day = 0): ?int {
        global $DB;
        $body = trim(preg_replace('/\s+/u', ' ', $body));
        if ($body === '') {
            return null;
        }
        $dueday = $day ? (new \DateTime('@' . $day))->setTimezone(\core_date::get_user_timezone_object())->setTime(0, 0)
            ->getTimestamp() : 0;
        return (int) $DB->insert_record(self::PROMISES, (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'userid' => $userid,
            'body' => \core_text::substr($body, 0, 255),
            'dueday' => $dueday,
            'timecreated' => time(),
        ]);
    }

    /**
     * Anyone says a promise is done; it stays in the conversation.
     *
     * @param \stdClass $household
     * @param int $promiseid
     * @param int $userid
     */
    public static function done(\stdClass $household, int $promiseid, int $userid): void {
        global $DB;
        $promise = $DB->get_record(self::PROMISES, ['id' => $promiseid, 'householdid' => $household->id, 'timedone' => 0]);
        if (!$promise) {
            return;
        }
        $DB->update_record(self::PROMISES, (object) ['id' => $promise->id, 'timedone' => time(), 'doneby' => $userid]);
        households::note($household, $userid, get_string('panel_promise_kept', self::C, $promise->body));
    }

    /**
     * The open promise of a family that comes first: the one due earliest, then the one without a day.
     *
     * @param int $householdid
     * @return \stdClass|null
     */
    public static function open_promise(int $householdid): ?\stdClass {
        global $DB;
        $open = $DB->get_records(
            self::PROMISES,
            ['householdid' => $householdid, 'timedone' => 0],
            'CASE WHEN dueday = 0 THEN 1 ELSE 0 END, dueday ASC, id ASC',
            '*',
            0,
            1
        );
        return reset($open) ?: null;
    }

    /**
     * The promises that go up in the inbox: those due today or before, and those of whoever is away.
     *
     * @param int $centreid
     * @param int $now
     * @return array Household id => promise.
     */
    public static function due(int $centreid, int $now): array {
        global $DB;
        $today = (new \DateTime('@' . $now))->setTimezone(\core_date::get_user_timezone_object())->setTime(0, 0)->getTimestamp();
        $away = $DB->get_fieldset_select(centres::MEMBERS, 'userid', 'centreid = ? AND awayuntil > ?', [$centreid, $now]);
        $out = [];
        foreach ($DB->get_records(self::PROMISES, ['centreid' => $centreid, 'timedone' => 0], 'dueday ASC, id ASC') as $promise) {
            $isdue = $promise->dueday > 0 && $promise->dueday <= $today;
            if (($isdue || in_array($promise->userid, $away)) && !isset($out[$promise->householdid])) {
                $out[$promise->householdid] = $promise;
            }
        }
        return $out;
    }

    /**
     * A promise in a sentence, said to the team: «Prometisteis llamarles para el lunes 9; hoy es miércoles 11.»
     *
     * @param \stdClass $promise
     * @param int $now
     * @return string
     */
    public static function sentence(\stdClass $promise, int $now): string {
        $c = self::C;
        $body = trim((string) $promise->body);
        $body = \core_text::strtolower(\core_text::substr($body, 0, 1)) . \core_text::substr($body, 1);
        $body = rtrim($body, '.');
        if (!$promise->dueday) {
            return get_string('panel_promised', $c, $body);
        }
        $days = panel::days_between((int) $promise->dueday, $now);
        $a = (object) ['body' => $body, 'day' => userdate((int) $promise->dueday, '%A %d'), 'today' => userdate($now, '%A %d')];
        if ($days === 0) {
            return get_string('panel_promised_today', $c, $a);
        }
        return get_string($days > 0 ? 'panel_promised_late' : 'panel_promised_for', $c, $a);
    }

    /**
     * A promise as the templates draw it, with who promised it and «Done».
     *
     * @param \stdClass $promise
     * @param int $now
     * @param int $viewer
     * @return array text, who, action, sesskey, id
     */
    public static function promise_context(\stdClass $promise, int $now, int $viewer): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $promise->userid]);
        $text = self::sentence($promise, $now);
        $away = $user ? self::away_until((int) $promise->centreid, (int) $user->id) : 0;
        if ($away) {
            $text .= ' ' . get_string('panel_away_until', self::C, (object) ['who' => $user->firstname,
                'day' => userdate($away, get_string('strftimedateshort', 'langconfig'))]) . '.';
        }
        return [
            'text' => $text,
            'who' => $user ? panel::person($user) + ['mine' => (int) $user->id === $viewer] : null,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $promise->householdid]))->out(false),
            'sesskey' => sesskey(),
            'id' => (string) $promise->id,
        ];
    }

    /**
     * Keeps an answer as one of always of the centre: the names of the family become a gap, and the words of what the
     * family asked are kept to suggest it again.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $body The answer as it was sent.
     * @return int
     */
    public static function keep_reply(\stdClass $household, int $userid, string $body): int {
        global $DB;
        $full = households::full($household);
        $greeting = trim(panel_household::greeting($full));
        $text = trim($body);
        if ($greeting !== '' && strpos($text, $greeting) === 0) {
            $text = trim(\core_text::substr($text, \core_text::strlen($greeting)));
        }
        foreach ($full->adults as $adult) {
            if (trim((string) $adult->firstname) !== '') {
                $text = str_replace(trim((string) $adult->firstname), '{names}', $text);
            }
        }
        $said = '';
        foreach (array_reverse($full->contacts) as $contact) {
            if ($contact->direction === 'in' && trim((string) $contact->body) !== '') {
                $said = (string) $contact->body;
                break;
            }
        }
        $words = preg_split('/\s+/u', trim($text));
        $title = implode(' ', array_slice($words, 0, 7)) . (count($words) > 7 ? '…' : '');
        return (int) $DB->insert_record(self::REPLIES, (object) [
            'centreid' => $household->centreid,
            'userid' => $userid,
            'title' => \core_text::substr($title, 0, 100),
            'body' => $text,
            'words' => implode(' ', self::words($said . ' ' . $text)),
            'uses' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * The answers of always that fit what a family asked, by the words they share: three at most, the best first.
     *
     * @param \stdClass $full The household, as households::full() gives it.
     * @return array id, title, uses, text (with the names of the family and its greeting)
     */
    public static function suggest(\stdClass $full): array {
        global $DB;
        $said = '';
        foreach (array_reverse($full->contacts) as $contact) {
            if ($contact->direction === 'in' && trim((string) $contact->body) !== '') {
                $said = (string) $contact->body;
                break;
            }
        }
        $asked = self::words($said);
        if (!$asked) {
            return [];
        }
        $scored = [];
        foreach ($DB->get_records(self::REPLIES, ['centreid' => $full->centreid]) as $reply) {
            $shared = count(array_intersect($asked, explode(' ', (string) $reply->words)));
            if ($shared > 0) {
                $scored[] = [$shared, (int) $reply->uses, $reply];
            }
        }
        usort($scored, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $out = [];
        foreach (array_slice($scored, 0, self::SUGGESTED) as [, , $reply]) {
            $out[] = [
                'id' => (string) $reply->id,
                'title' => format_string($reply->title),
                'uses' => $reply->uses ? get_string(
                    (int) $reply->uses === 1 ? 'panel_used_once' : 'panel_used_times',
                    self::C,
                    (int) $reply->uses
                ) : '',
                'text' => self::fill($reply, $full),
            ];
        }
        return $out;
    }

    /**
     * An answer of always for a family: its greeting and its names in the gaps.
     *
     * @param \stdClass $reply
     * @param \stdClass $full
     * @return string
     */
    public static function fill(\stdClass $reply, \stdClass $full): string {
        $names = array_values(array_filter(array_map(fn($a) => trim((string) $a->firstname), $full->adults)));
        $last = array_pop($names);
        $list = $names ? get_string('andlist', self::C, (object) ['list' => implode(', ', $names), 'last' => $last]) :
            (string) $last;
        return panel_household::greeting($full) . "\n\n" . str_replace('{names}', $list, (string) $reply->body);
    }

    /**
     * An answer of always chosen without the script: counted, and its text for the composer.
     *
     * @param \stdClass $full
     * @param int $replyid
     * @return string|null
     */
    public static function use_reply(\stdClass $full, int $replyid): ?string {
        global $DB;
        $reply = $DB->get_record(self::REPLIES, ['id' => $replyid, 'centreid' => $full->centreid]);
        if (!$reply) {
            return null;
        }
        $DB->set_field(self::REPLIES, 'uses', (int) $reply->uses + 1, ['id' => $reply->id]);
        return self::fill($reply, $full);
    }

    /**
     * The plain words of a text that tell what it is about.
     *
     * @param string $text
     * @return string[]
     */
    public static function words(string $text): array {
        $words = array_filter(explode(' ', grades::plain($text)), fn($w) => \core_text::strlen($w) > 3 &&
            !in_array($w, self::COMMON, true) && !ctype_digit($w));
        return array_values(array_unique($words));
    }

    /**
     * Someone is writing to a family: the composer beats every 20 seconds and the beat lasts 45.
     *
     * @param int $householdid
     * @param int $userid
     */
    public static function beat(int $householdid, int $userid): void {
        \cache::make(self::C, 'typing')->set('h' . $householdid, $userid);
    }

    /**
     * Who else is writing to a family now.
     *
     * @param int $householdid
     * @param int $viewer
     * @return array|null The person, as the panel shows them.
     */
    public static function typing(int $householdid, int $viewer): ?array {
        global $DB;
        $userid = (int) \cache::make(self::C, 'typing')->get('h' . $householdid);
        if (!$userid || $userid === $viewer) {
            return null;
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        return $user ? panel::person($user) : null;
    }
}
