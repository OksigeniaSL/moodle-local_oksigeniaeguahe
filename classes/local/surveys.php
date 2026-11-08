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
 * After the visit. A family that came finds one open question on its page and in the thanks, «What were you left to
 * ask?», which goes to the thread of the household for a person to answer. If the centre turns on a survey How it went
 * for that event, the band of the page asks it there, the open question first, each answer kept when it is touched,
 * once per family and event. The team reads the results in sentences and quotes, never percentages, never a mark on a
 * family or on a person; each family's answers are in its file.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class surveys {
    /** @var string */
    const T = 'local_oksigeniaeguahe_survey';

    /** @var string The kind of entry of a survey: never a door families come in by. */
    const KIND = 'survey';

    /** @var int Days after the visit the survey is asked. */
    const DAYS = 14;

    /** @var int Hours after the end of the visit the thanks goes. */
    const THANKSAFTER = 2;

    /** @var int Days after the visit after which no thanks goes any more. */
    const THANKSUNTIL = 3;

    /** @var int Below this many answers, the results are only sentences and quotes. */
    const FEW = 3;

    /** @var array The surveys and forms to start from: their questions, with their texts in the language packs. */
    const TEMPLATES = [
        'howitwent' => ['kind' => self::KIND, 'fields' => [
            ['key' => 'imagine', 'type' => 'scale'],
            ['key' => 'clear', 'type' => 'scale'],
            ['key' => 'better', 'type' => 'longtext'],
        ]],
        'askinfo' => ['kind' => 'form', 'fields' => [
            ['key' => 'when', 'type' => 'choice'],
            ['key' => 'know', 'type' => 'multichoice'],
            ['key' => 'more', 'type' => 'longtext'],
        ]],
        'siblings' => ['kind' => 'form', 'fields' => [
            ['key' => 'elder', 'type' => 'text'],
            ['key' => 'when', 'type' => 'choice'],
            ['key' => 'more', 'type' => 'longtext'],
        ]],
    ];

    /**
     * The surveys of a centre.
     *
     * @param int $centreid
     * @return \stdClass[]
     */
    public static function for_centre(int $centreid): array {
        global $DB;
        return $DB->get_records(entries::T, ['centreid' => $centreid, 'kind' => self::KIND], 'name ASC, id ASC');
    }

    /**
     * A new survey or form, empty or from one made, with its questions in every language.
     *
     * @param int $centreid
     * @param string $template A key of TEMPLATES, or '' for an empty survey.
     * @return int The entry.
     */
    public static function create(int $centreid, string $template = ''): int {
        $made = self::TEMPLATES[$template] ?? null;
        $centre = centres::get($centreid);
        $base = $centre && in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        $kind = $made ? $made['kind'] : self::KIND;
        $name = $made ? publiclang::str('tpl_' . $template, $base) : publiclang::str('survey_new_name', $base);
        $id = entries::create($centreid, $kind, $name);
        $entry = entries::get($id, $centreid);
        foreach ($made ? $made['fields'] : [] as $field) {
            $data = (object) ['type' => $field['type'], 'required' => 0];
            foreach (lists::LANGS as $lang) {
                $key = 'tpl_' . $template . '_' . $field['key'];
                $data->{'label_' . $lang} = publiclang::str($key, $lang);
                if (in_array($field['type'], forms::WITHOPTIONS, true)) {
                    $data->{'options_' . $lang} = $field['type'] === 'scale' ? publiclang::str('scale_words', $lang) :
                        publiclang::str($key . '_options', $lang);
                }
            }
            forms::save_field($entry, $data);
        }
        return $id;
    }

    /**
     * The visit a family came to last, once it is over: the event with the hours of its turn.
     *
     * @param \stdClass $household
     * @param int $now
     * @return \stdClass|null The event, with signupid, timestart and timeend of the turn.
     */
    public static function last_visit(\stdClass $household, int $now): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT e.*, s.id AS signupid, COALESCE(t.timestart, e.timestart) AS turnstart,
                    COALESCE(t.timeend, e.timeend) AS turnend
               FROM {' . events::SIGNUPS . '} s
               JOIN {' . events::T . '} e ON e.id = s.eventid
          LEFT JOIN {' . events::SLOTS . '} t ON t.id = s.slotid
              WHERE s.householdid = :householdid AND s.attended > 0 AND e.timeend < :now
           ORDER BY e.timeend DESC, e.id DESC',
            ['householdid' => $household->id, 'now' => $now],
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * The survey of an event, if it has one.
     *
     * @param \stdClass $event
     * @return \stdClass|null
     */
    public static function form(\stdClass $event): ?\stdClass {
        if (empty($event->survey)) {
            return null;
        }
        $entry = entries::get((int) $event->survey, (int) $event->centreid);
        return $entry && $entry->kind === self::KIND ? $entry : null;
    }

    /**
     * Whether the families who came to an event are still asked its survey.
     *
     * @param \stdClass $event
     * @param int $now
     * @return bool
     */
    public static function is_open(\stdClass $event, int $now): bool {
        return self::form($event) !== null && $now > (int) $event->timeend && $now <= (int) $event->timeend + self::DAYS * DAYSECS;
    }

    /**
     * What a family answered after an event, by question: 0 is what they were left to ask.
     *
     * @param int $eventid
     * @param int $householdid
     * @return array fieldid => the answer (a list for several options).
     */
    public static function answers(int $eventid, int $householdid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::T, ['eventid' => $eventid, 'householdid' => $householdid]) as $row) {
            $out[(int) $row->fieldid] = self::value($row->value);
        }
        return $out;
    }

    /**
     * Keeps the answer of a family to one question of the survey of an event, in place of the one before: touching
     * another option changes it, and an empty answer takes it away. A question that no longer shows takes its answer
     * with it.
     *
     * @param \stdClass $household
     * @param \stdClass $event
     * @param int $fieldid
     * @param string|array $answer
     * @param string $fallback The language of the centre.
     * @return bool Whether it was a valid answer.
     */
    public static function save(\stdClass $household, \stdClass $event, int $fieldid, $answer, string $fallback): bool {
        $entry = self::form($event);
        $field = $entry ? forms::field($fieldid, (int) $entry->id) : null;
        if (!$field || $field->type === 'section' || !self::valid($field, $answer, $fallback)) {
            return false;
        }
        $empty = is_array($answer) ? !$answer : trim((string) $answer) === '';
        self::put($household, $event, $fieldid, $empty ? null : (is_array($answer) ? json_encode(
            array_values($answer),
            JSON_UNESCAPED_UNICODE
        ) : \core_text::substr(trim((string) $answer), 0, 4000)));
        $answers = self::answers((int) $event->id, (int) $household->id);
        foreach (forms::fields((int) $entry->id) as $other) {
            if ($other->showif && isset($answers[$other->id]) && !forms::shown($other, $answers)) {
                self::put($household, $event, (int) $other->id, null);
            }
        }
        return true;
    }

    /**
     * What a family was left to ask after a visit: it goes to the thread of the household, which waits for a person to
     * answer, and is kept with the visit. Whoever receives the family attends it if nobody did yet: the page said so.
     *
     * @param \stdClass $household
     * @param \stdClass $event
     * @param string $text
     * @param int|null $now
     */
    public static function ask(\stdClass $household, \stdClass $event, string $text, ?int $now = null): void {
        global $DB;
        $text = \core_text::substr(trim($text), 0, 4000);
        if ($text === '') {
            return;
        }
        $now = $now ?? time();
        households::received($household, lists::PAGE, $text, true, $now, 0, format_string($event->name));
        $before = self::answers((int) $event->id, (int) $household->id)[0] ?? '';
        self::put($household, $event, 0, $before !== '' ? $before . "\n\n" . $text : $text, $now);
        $host = self::host($event);
        if ($host && $host->userid) {
            attend::answered_by($household, $host->userid);
        }
        $DB->set_field(households::T, 'timemodified', $now, ['id' => $household->id]);
    }

    /**
     * Who receives the families of an event, the first of them: the one the page names.
     *
     * @param \stdClass $event
     * @return \stdClass|null As centres::hosts() gives them.
     */
    public static function host(\stdClass $event): ?\stdClass {
        $ids = array_filter(array_map('intval', explode(',', (string) ($event->hosts ?? ''))));
        return $ids ? (centres::hosts((int) $event->centreid, $ids)[0] ?? null) : null;
    }

    /**
     * What the families who came to an event told after it: how many, the last one, and by question its words in
     * their order with how many said each, the yes and the no, and the texts as quotes with the name used at home.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang The language of the person who reads them.
     * @return array came, told, last (time), questions: label, type, rows (label, n), yes, no, quotes (text, who).
     */
    public static function results(\stdClass $event, \stdClass $centre, string $lang): array {
        global $DB;
        $base = (string) ($centre->lang ?: 'es');
        $came = (int) $DB->count_records_select(events::SIGNUPS, 'eventid = ? AND attended > 0', [$event->id]);
        $rows = $DB->get_records(self::T, ['eventid' => $event->id], 'timemodified ASC, id ASC');
        $byfield = [];
        $families = [];
        $last = 0;
        foreach ($rows as $row) {
            if ($row->value === null || $row->value === '') {
                continue;
            }
            $byfield[(int) $row->fieldid][] = $row;
            $families[(int) $row->householdid] = true;
            $last = max($last, (int) $row->timemodified);
        }
        $names = self::home_names(array_keys($families));
        $quotes = fn(array $list) => array_map(fn($row) => ['text' => (string) $row->value,
            'who' => $names[(int) $row->householdid] ?? ''], array_reverse($list));
        $questions = [];
        if (!empty($byfield[0])) {
            $questions[] = ['id' => 0, 'label' => publiclang::str('pub_after_question', $lang), 'type' => 'longtext',
                'n' => count($byfield[0]), 'rows' => [], 'quotes' => $quotes($byfield[0])];
        }
        $entry = self::form($event);
        foreach ($entry ? forms::fields((int) $entry->id) : [] as $field) {
            if ($field->type === 'section') {
                continue;
            }
            $list = $byfield[(int) $field->id] ?? [];
            $texts = forms::texts($field, $lang, $base);
            $question = ['id' => (int) $field->id, 'label' => $texts['label'], 'type' => $field->type, 'n' => count($list),
                'rows' => [], 'quotes' => []];
            if (in_array($field->type, forms::WITHOPTIONS, true) || $field->type === 'yesno') {
                $options = $field->type === 'yesno' ? ['yes', 'no'] : $texts['baseoptions'];
                $labels = $field->type === 'yesno' ? [get_string('yes'), get_string('no')] : $texts['options'];
                $counts = array_fill_keys($options, 0);
                foreach ($list as $row) {
                    foreach ((array) self::value($row->value) as $one) {
                        if (isset($counts[$one])) {
                            $counts[$one]++;
                        }
                    }
                }
                foreach ($options as $i => $option) {
                    $question['rows'][] = ['label' => $labels[$i] ?? $option, 'n' => $counts[$option]];
                }
            } else {
                $question['quotes'] = $quotes($list);
            }
            $questions[] = $question;
        }
        return ['came' => $came, 'told' => count($families), 'last' => $last, 'questions' => $questions];
    }

    /**
     * The event before this one of the same centre with the same survey and answers, to read below in soft.
     *
     * @param \stdClass $event
     * @return \stdClass|null
     */
    public static function previous(\stdClass $event): ?\stdClass {
        global $DB;
        if (empty($event->survey)) {
            return null;
        }
        $rows = $DB->get_records_sql(
            'SELECT e.*
               FROM {' . events::T . '} e
              WHERE e.centreid = :centreid AND e.survey = :survey AND e.id <> :id AND e.timeend < :start
                    AND EXISTS (SELECT 1 FROM {' . self::T . '} a WHERE a.eventid = e.id)
           ORDER BY e.timeend DESC, e.id DESC',
            ['centreid' => $event->centreid, 'survey' => $event->survey, 'id' => $event->id, 'start' => $event->timestart],
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * What a family told after each visit, for its file: the event, and each question with its answer.
     *
     * @param \stdClass $household
     * @param string $lang
     * @return array A list: eventid, name, timestart, answers (label, value).
     */
    public static function of_household(\stdClass $household, string $lang): array {
        global $DB;
        $centre = centres::get((int) $household->centreid);
        $base = (string) ($centre->lang ?? 'es');
        $out = [];
        $rows = $DB->get_records(self::T, ['householdid' => $household->id], 'eventid ASC, fieldid ASC');
        foreach ($rows as $row) {
            if ($row->value === null || $row->value === '' || (int) $row->fieldid === 0) {
                continue;
            }
            $event = $out[$row->eventid]['event'] ?? $DB->get_record(events::T, ['id' => $row->eventid]);
            $entry = $event ? self::form($event) : null;
            $field = $entry ? forms::field((int) $row->fieldid, (int) $entry->id) : null;
            if (!$field) {
                continue;
            }
            $texts = forms::texts($field, $lang, $base);
            $value = self::value($row->value);
            $words = [];
            foreach ((array) $value as $one) {
                $i = array_search($one, $texts['baseoptions'], true);
                $words[] = $field->type === 'yesno' ? get_string($one === 'yes' ? 'yes' : 'no') :
                    ($i !== false ? ($texts['options'][$i] ?? $one) : $one);
            }
            $out[$row->eventid] = $out[$row->eventid] ?? ['event' => $event, 'answers' => []];
            $out[$row->eventid]['answers'][] = ['label' => $texts['label'], 'value' => implode(', ', $words)];
        }
        $list = [];
        foreach ($out as $eventid => $item) {
            $list[] = ['eventid' => (int) $eventid, 'name' => format_string($item['event']->name),
                'timestart' => (int) $item['event']->timestart, 'answers' => $item['answers']];
        }
        usort($list, fn($a, $b) => $b['timestart'] <=> $a['timestart']);
        return $list;
    }

    /**
     * Takes away what a household told after its visits, when it is erased.
     *
     * @param int $householdid
     */
    public static function erase(int $householdid): void {
        global $DB;
        $DB->delete_records(self::T, ['householdid' => $householdid]);
    }

    /**
     * A stored answer as it was given: a list for several options.
     *
     * @param string|null $value
     * @return string|array
     */
    protected static function value(?string $value) {
        $value = (string) $value;
        if (str_starts_with($value, '[')) {
            $list = json_decode($value, true);
            if (is_array($list)) {
                return $list;
            }
        }
        return $value;
    }

    /**
     * Whether an answer fits its question: one of its options, yes or no, a date or a number.
     *
     * @param \stdClass $field
     * @param string|array $answer
     * @param string $fallback
     * @return bool
     */
    protected static function valid(\stdClass $field, $answer, string $fallback): bool {
        if (is_array($answer) ? !$answer : trim((string) $answer) === '') {
            return true;
        }
        $options = forms::texts($field, $fallback, $fallback)['baseoptions'];
        if ($field->type === 'multichoice') {
            return is_array($answer) && !array_diff($answer, $options);
        }
        if (is_array($answer)) {
            return false;
        }
        return match ($field->type) {
            'choice', 'scale' => in_array($answer, $options, true),
            'yesno' => in_array($answer, ['yes', 'no'], true),
            'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer),
            'number' => is_numeric(str_replace(',', '.', $answer)),
            default => true,
        };
    }

    /**
     * Keeps, changes or takes away one answer.
     *
     * @param \stdClass $household
     * @param \stdClass $event
     * @param int $fieldid
     * @param string|null $value
     * @param int|null $now
     */
    protected static function put(\stdClass $household, \stdClass $event, int $fieldid, ?string $value, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $key = ['eventid' => $event->id, 'householdid' => $household->id, 'fieldid' => $fieldid];
        $row = $DB->get_record(self::T, $key);
        if ($value === null) {
            if ($row) {
                $DB->delete_records(self::T, ['id' => $row->id]);
            }
            return;
        }
        if ($row) {
            $DB->update_record(self::T, (object) ['id' => $row->id, 'value' => $value, 'timemodified' => $now]);
            return;
        }
        $DB->insert_record(self::T, (object) ($key + ['centreid' => $household->centreid, 'value' => $value,
            'timecreated' => $now, 'timemodified' => $now]));
    }

    /**
     * The name each household uses at home, from the labels of its children, for the quotes.
     *
     * @param int[] $householdids
     * @return array householdid => name.
     */
    protected static function home_names(array $householdids): array {
        global $DB;
        if (!$householdids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($householdids);
        $names = [];
        foreach ($DB->get_records_select(households::CHILDREN, "householdid $in", $params, 'id ASC') as $child) {
            $tag = familypage::label_name($child);
            if ($tag !== '') {
                $names[(int) $child->householdid][] = $tag;
            }
        }
        return array_map(fn($list) => familypage::join($list, current_language()), $names);
    }
}
