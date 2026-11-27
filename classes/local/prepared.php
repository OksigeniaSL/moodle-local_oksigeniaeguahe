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
 * Prepared messages: what the team of a centre says often (the thanks after a visit, the waiting list, a document that is
 * missing), written once in its settings and used from the file of each family with what is each family's already in it.
 * Without greeting or foot: Eguahe puts both. The gaps are kept as {key}, and written in the settings as their words in
 * brackets in the language of the centre ([los niños]). A text with a gap that has no value for a family is not sent. One
 * message may be the one after a visit: proposed in the file of each family that came and has not had it, and, if the
 * centre wants it, sent by itself, from 9 to 20, in the place of the thanks of always.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prepared {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string */
    const T = 'local_oksigeniaeguahe_prepared';

    /** @var array The gaps, each with the icon of its piece. */
    const GAPS = ['children' => 'etiqueta', 'visitday' => 'actos', 'course' => 'birrete', 'applylink' => 'enlace',
        'networks' => 'globo'];

    /** @var int For how many days after a visit its thanks is still proposed. */
    const DAYS = 14;

    /**
     * The messages of a centre, in the order they were written.
     *
     * @param int $centreid
     * @return \stdClass[]
     */
    public static function all(int $centreid): array {
        global $DB;
        return array_values($DB->get_records(self::T, ['centreid' => $centreid], 'id ASC'));
    }

    /**
     * One message of a centre.
     *
     * @param int $centreid
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $centreid, int $id): ?\stdClass {
        global $DB;
        return $id ? ($DB->get_record(self::T, ['id' => $id, 'centreid' => $centreid]) ?: null) : null;
    }

    /**
     * The message after a visit, if the centre has one.
     *
     * @param int $centreid
     * @return \stdClass|null
     */
    public static function after_visit(int $centreid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_select(self::T, 'centreid = ? AND aftervisit = 1', [$centreid], 'id ASC', '*', 0, 1);
        return reset($rows) ?: null;
    }

    /**
     * The word of a gap in the language of a centre, as it goes in brackets.
     *
     * @param string $key
     * @param string $lang
     * @return string
     */
    public static function word(string $key, string $lang): string {
        return get_string_manager()->get_string('prepared_gap_' . $key, self::C, null, $lang);
    }

    /**
     * A text written with the words of the gaps in brackets, as it is kept: each gap as {key}.
     *
     * @param string $text
     * @param string $lang
     * @return string
     */
    public static function to_keys(string $text, string $lang): string {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        foreach (array_keys(self::GAPS) as $key) {
            $text = str_ireplace('[' . self::word($key, $lang) . ']', '{' . $key . '}', $text);
        }
        return $text;
    }

    /**
     * A text kept, with the words of its gaps in brackets, to write it again.
     *
     * @param string $body
     * @param string $lang
     * @return string
     */
    public static function to_words(string $body, string $lang): string {
        foreach (array_keys(self::GAPS) as $key) {
            $body = str_replace('{' . $key . '}', '[' . self::word($key, $lang) . ']', $body);
        }
        return $body;
    }

    /**
     * Keeps a message: new, changed, removed or made the one after a visit (and sent by itself, or not). Only one message is
     * the one after a visit.
     *
     * @param \stdClass $centre
     * @param int $id 0, a new one.
     * @param string $name
     * @param string $text With the words of the gaps in brackets.
     * @param int|null $after Whether it is the one after a visit: null, as it was.
     * @param int|null $auto Whether it goes by itself: null, as it was.
     * @return int Its id; 0, nothing to keep.
     */
    public static function save(\stdClass $centre, int $id, string $name, string $text, ?int $after = null, ?int $auto = null): int {
        global $DB;
        $lang = (string) ($centre->lang ?: current_language());
        $name = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 100);
        $body = self::to_keys($text, $lang);
        $now = time();
        $row = self::get((int) $centre->id, $id);
        if (!$row) {
            if ($body === '') {
                return 0;
            }
            $row = (object) ['centreid' => $centre->id, 'name' => $name !== '' ? $name : get_string('prepared_new', self::C),
                'body' => $body, 'aftervisit' => 0, 'auto' => 0, 'uses' => 0, 'timecreated' => $now, 'timemodified' => $now];
            $row->id = (int) $DB->insert_record(self::T, $row);
        } else if ($body !== '') {
            $DB->update_record(self::T, (object) ['id' => $row->id, 'name' => $name !== '' ? $name : $row->name, 'body' => $body,
                'timemodified' => $now]);
        }
        if ($auto !== null) {
            // Going by itself after a visit, it is the one after a visit.
            $after = $auto ? 1 : ($after ?? (int) $row->aftervisit);
        }
        if ($after !== null) {
            if ($after) {
                $DB->set_field_select(self::T, 'aftervisit', 0, 'centreid = ? AND id <> ?', [$centre->id, $row->id]);
                $DB->set_field_select(self::T, 'auto', 0, 'centreid = ? AND id <> ?', [$centre->id, $row->id]);
            }
            $DB->update_record(self::T, (object) ['id' => $row->id, 'aftervisit' => $after ? 1 : 0,
                'auto' => $after && ($auto ?? (int) $row->auto) ? 1 : 0, 'timemodified' => $now]);
        }
        return (int) $row->id;
    }

    /**
     * Removes a message; what was sent with it stays in the history of each family.
     *
     * @param \stdClass $centre
     * @param int $id
     */
    public static function remove(\stdClass $centre, int $id): void {
        global $DB;
        $DB->delete_records(self::T, ['id' => $id, 'centreid' => $centre->id]);
    }

    /**
     * What each gap says for a family: null when it has no value (the text then is not sent with it).
     *
     * @param \stdClass $centre
     * @param \stdClass $full households::full().
     * @param int $now
     * @return array key => value or null
     */
    public static function values(\stdClass $centre, \stdClass $full, int $now): array {
        global $DB;
        $lang = (string) ($centre->lang ?: current_language());
        $list = function (array $names) use ($lang): string {
            $names = array_values(array_unique(array_filter(array_map('trim', $names))));
            $last = array_pop($names);
            return $names ? get_string_manager()->get_string('andlist', self::C, (object) ['list' => implode(', ', $names),
                'last' => $last], $lang) : (string) $last;
        };
        $children = array_filter((array) ($full->children ?? []), fn($c) => ($c->stage ?? '') !== 'closed');
        $kids = $list(array_map(fn($c) => (string) (($c->tagname ?? '') ?: $c->firstname), $children));
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name');
        $courses = $list(array_map(fn($c) => format_string((string) ($offers[(int) ($c->offerid ?? 0)] ?? '')), $children));
        $visit = self::visit($full, $now);
        $networks = trim((string) ($centre->networks ?? '')) !== '' ? $list(centres::networks($centre)) : '';
        return [
            'children' => $kids !== '' ? $kids : null,
            'visitday' => $visit ? trim(userdate($visit, get_string_manager()->get_string('prepared_day', self::C, null, $lang))) : null,
            'course' => $courses !== '' ? $courses : null,
            'applylink' => trim((string) ($centre->applyurl ?? '')) !== '' ? trim((string) $centre->applyurl) : null,
            'networks' => $networks !== '' ? $networks : null,
        ];
    }

    /**
     * When a family last came to a visit or an open day of the centre (as «Who came» says), or 0.
     *
     * @param \stdClass $household
     * @param int $now
     * @return int
     */
    public static function visit(\stdClass $household, int $now): int {
        global $DB;
        $last = 0;
        foreach (appointments::of($household, $now) as $one) {
            if ($one['state'] !== 'came' || $one['start'] > $now) {
                continue;
            }
            // A talk or a party is not a visit to know the centre: its thanks are those of the act.
            if ($one['kind'] === 'act' && $DB->get_field(events::T, 'kind', ['id' => (int) $one['eventid']]) !== 'openday') {
                continue;
            }
            $last = max($last, (int) $one['start']);
        }
        return $last;
    }

    /**
     * A message written for a family: its text with what is the family's in each gap, the gaps it has no value for in
     * brackets, and what is missing in words.
     *
     * @param \stdClass $message
     * @param array $values Of values().
     * @param string $lang
     * @return array text, missing (the keys) and said (what is missing and where it is written)
     */
    public static function fill(\stdClass $message, array $values, string $lang): array {
        $missing = [];
        $text = preg_replace_callback('/\{([a-z]+)\}/', function ($m) use ($values, $lang, &$missing) {
            if (!array_key_exists($m[1], self::GAPS)) {
                return $m[0];
            }
            if (($values[$m[1]] ?? null) === null) {
                $missing[$m[1]] = true;
                return '[' . self::word($m[1], $lang) . ']';
            }
            return (string) $values[$m[1]];
        }, (string) $message->body);
        $said = array_map(fn($key) => get_string('prepared_missing_' . $key, self::C), array_keys($missing));
        return ['text' => $text, 'missing' => array_keys($missing), 'said' => implode(' ', $said)];
    }

    /**
     * Whether a text about to go still has a gap no family value went into.
     *
     * @param string $text
     * @param string $lang
     * @return bool
     */
    public static function has_gap(string $text, string $lang): bool {
        foreach (array_keys(self::GAPS) as $key) {
            if (\core_text::strpos(\core_text::strtolower($text), \core_text::strtolower('[' . self::word($key, $lang) . ']')) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * A message was sent to a family: one more use.
     *
     * @param int $centreid
     * @param int $id
     */
    public static function used(int $centreid, int $id): void {
        global $DB;
        $DB->execute('UPDATE {' . self::T . '} SET uses = uses + 1 WHERE id = ? AND centreid = ?', [$id, $centreid]);
    }

    /**
     * The visit of a family whose thanks is due: it came, within some days, and nothing of the message after a visit went to
     * it since.
     *
     * @param \stdClass $centre
     * @param int $householdid
     * @param int $now
     * @return int The moment of the visit; 0, none due.
     */
    public static function due(\stdClass $centre, int $householdid, int $now): int {
        global $DB;
        $message = self::after_visit((int) $centre->id);
        $household = $message ? households::get($householdid, (int) $centre->id) : null;
        $visit = $household ? self::visit($household, $now) : 0;
        if (!$visit || $visit < $now - self::DAYS * DAYSECS) {
            return 0;
        }
        $sent = $DB->record_exists_select(
            households::CONTACTS,
            'householdid = ? AND prepared = ? AND timecreated >= ?',
            [$householdid, $message->id, $visit]
        );
        return $sent ? 0 : $visit;
    }

    /**
     * When the thanks of a family went last, as the history says, or 0.
     *
     * @param \stdClass $centre
     * @param int $householdid
     * @return array time and channel, or empty
     */
    public static function sent(\stdClass $centre, int $householdid): array {
        global $DB;
        $message = self::after_visit((int) $centre->id);
        if (!$message) {
            return [];
        }
        $rows = $DB->get_records(
            households::CONTACTS,
            ['householdid' => $householdid, 'prepared' => $message->id],
            'timecreated DESC',
            'id, timecreated, channel',
            0,
            1
        );
        $row = reset($rows);
        return $row ? ['time' => (int) $row->timecreated, 'channel' => (string) $row->channel] : [];
    }

    /**
     * The families of a centre whose thanks after a visit is due, when a person sends them.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int[]
     */
    public static function due_households(\stdClass $centre, int $now): array {
        $message = self::after_visit((int) $centre->id);
        if (!$message || !empty($centre->practice)) {
            return [];
        }
        $out = [];
        foreach (appointments::agenda($centre, $now - self::DAYS * DAYSECS, $now, $now) as $one) {
            $id = (int) $one['householdid'];
            if ($one['state'] === 'came' && !isset($out[$id]) && self::due($centre, $id, $now)) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * What the settings show: the messages as cards, the open one with its text and, beside it, the letter as a family of
     * example will read it.
     *
     * @param \stdClass $centre
     * @param string $open The id of the open one, «new», or empty for the first.
     * @return array The context of panel_prepared.
     */
    public static function settings_context(\stdClass $centre, string $open): array {
        $c = self::C;
        $lang = (string) ($centre->lang ?: current_language());
        $url = fn(array $params) => (new \moodle_url(
            '/local/oksigeniaeguahe/centre.php',
            ['id' => $centre->id] + $params,
            'aj-preparados'
        ))->out(false);
        $messages = self::all((int) $centre->id);
        $current = null;
        if ($open !== 'new') {
            foreach ($messages as $message) {
                if ($open === '' ? !$current : (int) $open === (int) $message->id) {
                    $current = $message;
                }
            }
        }
        $example = self::example($centre);
        $bits = function (string $body, ?array $values) use ($lang): array {
            $out = [];
            foreach (preg_split('/(\{[a-z]+\})/', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
                $key = preg_match('/^\{([a-z]+)\}$/', $piece, $m) && isset(self::GAPS[$m[1]]) ? $m[1] : '';
                if ($key === '') {
                    $out[] = ['text' => $piece, 'fill' => false, 'gap' => false];
                } else if ($values !== null && ($values[$key] ?? null) !== null) {
                    $out[] = ['text' => (string) $values[$key], 'fill' => true, 'gap' => false];
                } else {
                    $out[] = ['text' => $values === null ? self::word($key, $lang) : '[' . self::word($key, $lang) . ']',
                        'fill' => false, 'gap' => true];
                }
            }
            return $out;
        };
        $list = [];
        foreach ($messages as $message) {
            $first = (string) strtok((string) $message->body, "\n");
            $list[] = ['id' => (int) $message->id, 'name' => format_string($message->name), 'firstbits' => $bits($first, null),
                'auto' => (bool) $message->auto, 'uses' => $message->auto ? get_string('prepared_uses_auto', $c) :
                ($message->aftervisit ? get_string('prepared_uses_after', $c) : ((int) $message->uses ? get_string(
                    (int) $message->uses === 1 ? 'prepared_uses_one' : 'prepared_uses',
                    $c,
                    (int) $message->uses
                ) : get_string('prepared_uses_none', $c))), 'current' => $current && (int) $current->id === (int) $message->id,
                'url' => $url(['pm' => $message->id])];
        }
        $gaps = [];
        foreach (self::GAPS as $key => $icon) {
            $gaps[] = ['key' => $key, 'word' => self::word($key, $lang), 'label' => get_string('prepared_label_' . $key, $c),
                'icon' => panel::icon($icon), 'example' => (string) $example['values'][$key]];
        }
        $body = $current ? (string) $current->body : '';
        $paras = [];
        foreach (preg_split('/\n{2,}/', trim($body)) as $para) {
            if (trim($para) !== '') {
                $paras[] = ['bits' => $bits($para, $example['values'])];
            }
        }
        return [
            'list' => $list,
            'newurl' => $url(['pm' => 'new']),
            'gaps' => $gaps,
            'current' => $open === 'new' || $current ? ['id' => $current ? (int) $current->id : 0,
                'name' => $current ? format_string($current->name) : '', 'text' => self::to_words($body, $lang),
                'auto' => $current && $current->auto, 'after' => $current && $current->aftervisit, 'paras' => $paras,
                'survey' => $current && $current->auto ? get_string('prepared_survey_example', $c) : ''] : false,
            'greeting' => $example['greeting'],
            'sign' => format_string($centre->name),
            'family' => $example['family'],
            'action' => $url($current ? ['pm' => $current->id] : ($open === 'new' ? ['pm' => 'new'] : [])),
        ];
    }

    /**
     * A family of example, to see a message as a family reads it: the gaps of the centre, its own where it has them.
     *
     * @param \stdClass $centre
     * @return array values, greeting and family
     */
    protected static function example(\stdClass $centre): array {
        $c = self::C;
        $values = ['children' => get_string('prepared_example_children', $c), 'visitday' => get_string('prepared_example_day', $c),
            'course' => get_string('prepared_example_course', $c), 'applylink' => trim((string) ($centre->applyurl ?? '')) ?:
            null, 'networks' => trim((string) ($centre->networks ?? '')) !== '' ? implode(', ', centres::networks($centre)) : null];
        return ['values' => $values, 'greeting' => get_string('prepared_example_greeting', $c),
            'family' => get_string('prepared_example_family', $c)];
    }

    /**
     * Keeps what the settings sent of the open message: its name and text, made the one after a visit or sent by itself,
     * or removed.
     *
     * @param \stdClass $centre
     * @return int|null The message to show again (0, none); null, nothing of the messages was sent.
     */
    public static function from_form(\stdClass $centre): ?int {
        $remove = optional_param('pmremove', 0, PARAM_INT);
        if ($remove) {
            self::remove($centre, $remove);
            return 0;
        }
        $id = optional_param('pmid', null, PARAM_INT);
        if ($id === null) {
            return null;
        }
        $auto = optional_param('pmauto', null, PARAM_INT);
        $after = optional_param('pmafter', null, PARAM_INT);
        return self::save(
            $centre,
            (int) $id,
            optional_param('pmname', '', PARAM_TEXT),
            optional_param('pmtext', '', PARAM_TEXT),
            $after,
            $auto
        ) ?: (int) $id;
    }

    /**
     * What the composer of a file offers: each message written for this family, with what is missing said.
     *
     * @param \stdClass $centre
     * @param \stdClass $full households::full().
     * @param int $chosen The one in the composer, if any.
     * @param bool $canmanage Whether whoever looks writes the settings.
     * @param string $action The address of the file.
     * @param int $now
     * @return array|false The context of panel_prepared_pick; false, the centre has none.
     */
    public static function pick_context(\stdClass $centre, \stdClass $full, int $chosen, bool $canmanage, string $action, int $now) {
        $messages = self::all((int) $centre->id);
        if (!$messages) {
            return false;
        }
        $lang = (string) ($full->lang ?: $centre->lang ?: current_language());
        $values = self::values($centre, $full, $now);
        $greeting = panel_household::greeting($full);
        $list = [];
        $picked = false;
        foreach ($messages as $message) {
            $filled = self::fill($message, $values, $lang);
            $one = ['id' => (int) $message->id, 'name' => format_string($message->name),
                'first' => (string) strtok($filled['text'], "\n"), 'auto' => (bool) $message->auto,
                'text' => $greeting . "\n\n" . $filled['text'], 'missing' => $filled['said'],
                'current' => (int) $message->id === $chosen];
            $list[] = $one;
            if ($one['current']) {
                $picked = $one;
            }
        }
        return ['list' => $list, 'chosen' => $picked, 'action' => $action,
            'settingsurl' => $canmanage ? (new \moodle_url(
                '/local/oksigeniaeguahe/centre.php',
                ['id' => $centre->id],
                'aj-preparados'
            ))->out(false) : ''];
    }

    /**
     * The envelope of the thanks in the file of a family that came: to send them, going by themselves, or sent.
     *
     * @param \stdClass $centre
     * @param \stdClass $full households::full().
     * @param string $url The file.
     * @param int $now
     * @return array|false url, name, inside, sent and auto; false, nothing to say
     */
    public static function thanks_context(\stdClass $centre, \stdClass $full, string $url, int $now) {
        $c = self::C;
        $message = self::after_visit((int) $centre->id);
        if (!$message) {
            return false;
        }
        $visit = self::visit($full, $now);
        $sent = self::sent($centre, (int) $full->id);
        $due = $visit && $visit >= $now - self::DAYS * DAYSECS && (!$sent || $sent['time'] < $visit);
        if (!$due && !($sent && $sent['time'] >= $now - self::DAYS * DAYSECS)) {
            return false;
        }
        $values = self::values($centre, $full, $now);
        $put = array_values(array_filter([$values['children'], $values['visitday']]));
        $auto = '';
        if ($due && $message->auto) {
            $at = invites::daytime($now);
            $auto = $at > $now ? get_string('prepared_thanks_auto_at', $c, (object) ['day' => userdate(
                $at,
                get_string('strftimedaydate', 'langconfig')
            ), 'hour' => panel::hour($at)]) : get_string('prepared_thanks_auto_soon', $c);
        }
        return [
            'url' => (new \moodle_url($url, ['gracias' => 1], 'contestar'))->out(false),
            'name' => format_string($message->name),
            'inside' => $put ? get_string('prepared_thanks_inside', $c, implode(' · ', $put)) : '',
            'sent' => !$due && $sent ? get_string('prepared_thanks_sent', $c, (object) ['day' => userdate(
                $sent['time'],
                get_string('strftimedaydate', 'langconfig')
            ), 'hour' => panel::hour($sent['time']),
                'way' => get_string('channel_' . (in_array($sent['channel'], lists::CHANNELS, true) ? $sent['channel'] : 'other'), $c)]) : '',
            'auto' => $auto,
        ];
    }

    /**
     * The thanks after a visit, sent by themselves where the centre wants it: from 9 to 20, once for each family and visit,
     * by mail as a person sends them, with the link of its survey when the visit has one. A text with a gap a family has no
     * value for is not sent: its file keeps proposing it.
     *
     * @param int $now
     * @return int How many went.
     */
    public static function send_due(int $now): int {
        global $DB;
        if (invites::daytime($now) !== $now) {
            return 0;
        }
        $n = 0;
        foreach ($DB->get_records_select(self::T, 'auto = 1 AND aftervisit = 1') as $message) {
            $centre = centres::get((int) $message->centreid);
            if (!$centre || !empty($centre->practice)) {
                continue;
            }
            foreach (self::due_households($centre, $now) as $id) {
                $household = households::get($id, (int) $centre->id);
                if ($household && self::send($centre, $household, $message, $now)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /**
     * A prepared message to a family by mail, as a person of the team sends it, with a line in its history that says which.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param \stdClass $message
     * @param int $now
     * @return bool Whether it went.
     */
    public static function send(\stdClass $centre, \stdClass $household, \stdClass $message, int $now): bool {
        global $DB;
        $full = households::full($household);
        $lang = (string) ($household->lang ?: $centre->lang ?: current_language());
        $filled = self::fill($message, self::values($centre, $full, $now), $lang);
        if ($filled['missing'] || !familymail::may_email($household) || !writeby::email_ok($household, $centre, 'thanks')) {
            return false;
        }
        $body = panel_household::greeting($full) . "\n\n" . $filled['text'];
        $sent = false;
        try {
            $sent = familymail::send('reply', $household, $centre, null, $lang, households::new_link($household, 'reply'), null, $body);
        } catch (\Throwable $e) {
            $sent = false;
        }
        if (!$sent) {
            return false;
        }
        households::told($household, 0, 'email', $body);
        self::mark_last($household, (int) $message->id, 0);
        self::used((int) $centre->id, (int) $message->id);
        return true;
    }

    /**
     * The last thing a person (or Eguahe) sent to a family was written with a prepared message: its line says which.
     *
     * @param \stdClass $household
     * @param int $id
     * @param int $userid Who sent it; 0, Eguahe.
     */
    public static function mark_last(\stdClass $household, int $id, int $userid): void {
        global $DB;
        $last = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {' . households::CONTACTS . '} WHERE householdid = ? AND direction = ? AND userid = ?',
            [$household->id, 'out', $userid]
        );
        if ($last) {
            $DB->set_field(households::CONTACTS, 'prepared', $id, ['id' => $last]);
        }
    }

    /**
     * What a person wrote and sent, kept as one more prepared message: without its greeting, and with the names of the
     * children of that family made the gap of the children.
     *
     * @param \stdClass $centre
     * @param \stdClass $full households::full().
     * @param string $body
     * @return int Its id; 0, nothing to keep.
     */
    public static function keep_written(\stdClass $centre, \stdClass $full, string $body): int {
        $lang = (string) ($centre->lang ?: current_language());
        $text = trim(str_replace(["\r\n", "\r"], "\n", $body));
        $greeting = trim(panel_household::greeting($full));
        if ($greeting !== '' && \core_text::strpos($text, $greeting) === 0) {
            $text = trim(\core_text::substr($text, \core_text::strlen($greeting)));
        }
        $children = self::values($centre, $full, time())['children'];
        if ($children !== null && $children !== '') {
            $text = str_replace($children, '[' . self::word('children', $lang) . ']', $text);
        }
        $name = \core_text::substr(trim((string) strtok($text, "\n")), 0, 40);
        return self::save($centre, 0, $name, $text);
    }
}
