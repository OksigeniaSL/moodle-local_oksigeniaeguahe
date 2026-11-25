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
 * What the last lists of the centre did by themselves, told the next morning: the children they enrolled, the families
 * they found to be of the centre and what they ask, each with «It is not so»; and a list that came cut short and did not
 * take the place of the one there was.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listnews {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string The lists that came cut short, by centre. */
    const HELD = 'listheld_';

    /** @var string The lists a person said are right though shorter, by centre. */
    const ACCEPTED = 'listaccepted_';

    /** @var int How long the page shows what they did. */
    const SHOWN = 60 * DAYSECS;

    /**
     * What the lists did by themselves since someone said it was known: what they enrolled and whom they found of the
     * centre, and what waits for a person.
     *
     * @param int $centreid
     * @param int $after Only those after this row.
     * @param int $since Only those since this time, or that still wait.
     * @return \stdClass[] Rows of listmatch, the last first.
     */
    public static function rows(int $centreid, int $after = 0, int $since = 0): array {
        global $DB;
        return $DB->get_records_select(
            listmatch::T,
            "centreid = :centreid AND id > :after
             AND ((kind = 'enrolled' AND was IS NOT NULL AND said = 0 AND timecreated >= :s1)
                  OR (kind = 'incentre' AND marked = :made AND said = 0 AND timecreated >= :s2)
                  OR (kind IN ('ask', 'maybe', 'gone') AND done = 0))",
            ['centreid' => $centreid, 'after' => $after, 's1' => $since, 's2' => $since, 'made' => listmatch::MADE],
            'timecreated DESC, id DESC'
        );
    }

    /**
     * The last row someone said was known.
     *
     * @param int $centreid
     * @return int
     */
    protected static function known(int $centreid): int {
        $ids = [0];
        foreach (explode(',', (string) get_config(self::C, moments::KNOWN . $centreid)) as $key) {
            if (preg_match('/^list-(\d+)$/', $key, $m)) {
                $ids[] = (int) $m[1];
            }
        }
        return max($ids);
    }

    /**
     * The notes of the work of today: what the last lists did, and a list that came cut short.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @return array[] As panel_inbox has them.
     */
    public static function notes(\stdClass $centre, bool $canact): array {
        $centreid = (int) $centre->id;
        $c = self::C;
        $url = (new \moodle_url('/local/oksigeniaeguahe/lista.php', ['c' => $centreid]))->out(false);
        $note = ['leaf' => false, 'blank' => false, 'url' => $url, 'known' => false];
        $out = [];
        foreach (self::held($centreid) as $label => $held) {
            $out[] = ['title' => get_string('listnews_held_title', $c), 'short' => get_string('listnews_held_title', $c),
                'text' => get_string('listnews_held', $c, (object) ['label' => s($label), 'rows' => (int) $held['rows'],
                    'had' => (int) $held['had']]), 'link' => get_string('listnews_held_see', $c)] + $note;
        }
        $rows = self::rows($centreid, self::known($centreid), time() - self::SHOWN);
        if (!$rows) {
            return $out;
        }
        // The children it enrolled; of the rest, the families.
        $n = ['enrolled' => 0];
        $homes = ['incentre' => [], 'ask' => []];
        foreach ($rows as $row) {
            if ($row->kind === 'enrolled') {
                $n['enrolled']++;
            } else {
                $homes[$row->kind === 'incentre' ? 'incentre' : 'ask'][(int) $row->householdid] = true;
            }
        }
        $n += array_map('count', $homes);
        $parts = [];
        foreach (['enrolled', 'incentre', 'ask'] as $what) {
            if ($n[$what]) {
                $parts[] = get_string('listnews_' . $what . ($n[$what] === 1 ? '_one' : ''), $c, $n[$what]);
            }
        }
        $out[] = ['title' => get_string('listnews_title', $c), 'short' => get_string('listnews_title', $c),
            'text' => get_string('listnews_text', $c, panel::and_list($parts)), 'link' => get_string('listnews_see', $c),
            'known' => $canact ? ['key' => 'list-' . max(array_keys($rows)),
                'action' => (new \moodle_url('/local/oksigeniaeguahe/moment.php'))->out(false), 'centreid' => $centreid,
                'sesskey' => sesskey()] : false] + $note;
        return $out;
    }

    /**
     * The page: what they did, family by family, each with its answer.
     *
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array
     */
    public static function context(\stdClass $centre, bool $canact, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $c = self::C;
        $centreid = (int) $centre->id;
        $rows = self::rows($centreid, 0, $now - self::SHOWN);
        $people = households::some($centreid, array_values(array_unique(array_map(fn($r) => (int) $r->householdid, $rows))));
        $offers = centres::offers($centreid);
        $taken = [];
        if ($rows) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($people) ?: [0]);
            foreach (
                $DB->get_records_select(
                    listmatch::T,
                    "householdid $in AND kind IN ('enrolled', 'inside', 'gone') AND childid > 0",
                    $params,
                    'id ASC',
                    'id, childid'
                ) as $row
            ) {
                $taken[(int) $row->childid] = true;
            }
        }
        $groups = ['ask' => [], 'enrolled' => [], 'incentre' => []];
        foreach ($rows as $row) {
            $home = $people[(int) $row->householdid] ?? null;
            if (!$home) {
                continue;
            }
            $asks = in_array($row->kind, ['ask', 'maybe', 'gone'], true);
            $children = array_column((array) $home->children, null, 'id');
            $child = $children[(int) $row->childid] ?? null;
            $date = userdate((int) $row->timein ?: (int) $row->timecreated, get_string('strftimedate', 'langconfig'));
            $one = ['id' => (int) $row->id, 'adults' => panel::adults_line($home->adults) ?: get_string('chat_family_noname', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $home->id], 'segun-lista'))->out(false),
                'householdid' => (int) $home->id, 'when' => panel::when_words((int) $row->timecreated, $now), 'children' => [],
                'text' => '', 'ask' => false, 'undo' => false];
            if ($asks) {
                $one['ask'] = listmatch::question($row, $children, $taken, $offers);
            } else if ($row->kind === 'enrolled') {
                $one['children'] = $child && panel::tag($child) !== '' ? [['tag' => panel::tag($child)]] : [];
                $one['text'] = get_string('listnews_row_enrolled', $c, (object) ['grade' => $row->grade, 'date' => $date]);
                $one['undo'] = $child && $child->stage === 'enrolled';
            } else {
                $one['text'] = get_string('listnews_row_incentre', $c, (object) ['grade' => $row->grade, 'year' => $row->schoolyear]);
                $one['undo'] = true;
            }
            $groups[$asks ? 'ask' : $row->kind][] = $one;
        }
        $out = [];
        foreach ($groups as $key => $list) {
            if ($list) {
                $out[] = ['key' => $key, 'name' => get_string('listnews_group_' . $key, $c), 'help' => get_string(
                    'listnews_group_' . $key . '_help',
                    $c
                ), 'families' => $list];
            }
        }
        $held = [];
        foreach (self::held($centreid) as $label => $one) {
            $held[] = ['label' => s($label), 'text' => get_string('listnews_held', $c, (object) ['label' => s($label),
                'rows' => (int) $one['rows'], 'had' => (int) $one['had']]),
                'accepted' => (bool) ($one['accepted'] ?? false)];
        }
        return ['head' => panel::head(['title' => get_string('listnews_title', $c), 'sub' => get_string(
            $out ? 'listnews_sub' : 'listnews_none',
            $c
        ), 'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centreid]))->out(false),
            'name' => get_string('tab_inbox', $c)]]),
            'groups' => $out, 'held' => $held, 'hasheld' => (bool) $held, 'canact' => $canact, 'sesskey' => sesskey(),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/lista.php', ['c' => $centreid]))->out(false)];
    }

    /**
     * The lists of a centre that came cut short and did not take the place of the one there was.
     *
     * @param int $centreid
     * @return array[] The name of each list => rows, had, time, told and accepted.
     */
    public static function held(int $centreid): array {
        return array_filter((array) json_decode((string) get_config(self::C, self::HELD . $centreid), true));
    }

    /**
     * Writes which lists came cut short and which came whole.
     *
     * @param int $centreid
     * @param array $lists The name of each list => rows and had, or null when it came whole.
     * @param int $now
     */
    public static function hold(int $centreid, array $lists, int $now): void {
        $had = self::held($centreid);
        $held = $had;
        foreach ($lists as $label => $one) {
            unset($held[$label]);
            if ($one) {
                // The same one, night after night, is told once.
                $held[$label] = $one + ['time' => (int) ($had[$label]['time'] ?? $now), 'told' => (int) ($had[$label]['told'] ?? 0)];
            }
        }
        if ($held !== $had) {
            set_config(self::HELD . $centreid, json_encode($held), self::C);
        }
    }

    /**
     * A person says a shorter list is right: the next time it comes, it takes the place of the one there was.
     *
     * @param int $centreid
     * @param string $label
     * @return bool
     */
    public static function accept(int $centreid, string $label): bool {
        $held = self::held($centreid);
        if (!isset($held[$label])) {
            return false;
        }
        $accepted = (array) json_decode((string) get_config(self::C, self::ACCEPTED . $centreid), true);
        $accepted[$label] = (int) $held[$label]['rows'];
        set_config(self::ACCEPTED . $centreid, json_encode($accepted), self::C);
        $held[$label]['accepted'] = 1;
        set_config(self::HELD . $centreid, json_encode($held), self::C);
        return true;
    }

    /**
     * Whether a person said that a list this short is right; said once, for the next time it comes.
     *
     * @param int $centreid
     * @param string $label
     * @param int $rows
     * @return bool
     */
    public static function accepted(int $centreid, string $label, int $rows): bool {
        $accepted = (array) json_decode((string) get_config(self::C, self::ACCEPTED . $centreid), true);
        if (!isset($accepted[$label]) || $rows < imports::KEEPS * (int) $accepted[$label]) {
            return false;
        }
        unset($accepted[$label]);
        set_config(self::ACCEPTED . $centreid, json_encode($accepted), self::C);
        return true;
    }

    /**
     * Tells the team, once, of a list that came cut short: without it nobody would know the numbers are of another day.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int How many notices were sent.
     */
    public static function tell(\stdClass $centre, int $now): int {
        $sent = 0;
        $held = self::held((int) $centre->id);
        $url = (new \moodle_url('/local/oksigeniaeguahe/lista.php', ['c' => $centre->id]))->out(false);
        foreach ($held as $label => $one) {
            if (!empty($one['told']) || !empty($centre->practice)) {
                continue;
            }
            $held[$label]['told'] = $now;
            foreach (teamnotice::recipients($centre) as $member) {
                $user = \core_user::get_user((int) $member->id);
                if (!$user || !empty($user->suspended) || !empty($user->deleted)) {
                    continue;
                }
                $lang = force_current_language($user->lang ?: ($centre->lang ?: 'es'));
                try {
                    $words = get_string('listnews_held', self::C, (object) ['label' => $label, 'rows' => (int) $one['rows'],
                        'had' => (int) $one['had']]);
                    $message = new \core\message\message();
                    $message->component = self::C;
                    $message->name = 'listheld';
                    $message->userfrom = \core_user::get_noreply_user();
                    $message->userto = $user;
                    $message->subject = get_string('listnews_held_title', self::C);
                    $message->fullmessage = $words . "\n\n" . $url;
                    $message->fullmessageformat = FORMAT_PLAIN;
                    $message->fullmessagehtml = '<p>' . s($words) . '</p><p><a href="' . s($url) . '">' .
                        s(get_string('listnews_held_see', self::C)) . '</a></p>';
                    $message->smallmessage = $message->subject;
                    $message->notification = 1;
                    $message->contexturl = $url;
                    $message->contexturlname = get_string('listnews_held_see', self::C);
                    $sent += message_send($message) ? 1 : 0;
                } finally {
                    force_current_language($lang);
                }
            }
        }
        if ($held) {
            set_config(self::HELD . $centre->id, json_encode($held), self::C);
        }
        return $sent;
    }
}
