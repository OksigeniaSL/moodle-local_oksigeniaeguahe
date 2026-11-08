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
 * The forms of a centre, made in Eguahe: who would study and the adults with how to answer them, as in every sign-up,
 * plus the questions the centre needs, in each of its languages, shown only when an earlier answer calls for them.
 * A form is an entry of kind «form»: its page, inside the web of the centre or by its link, from each place it is
 * shared with a name of its own, so the numbers say where each family came from.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forms {
    /** @var string */
    const FIELDS = 'local_oksigeniaeguahe_field';

    /** @var string */
    const ANSWERS = 'local_oksigeniaeguahe_answer';

    /** @var string[] Kinds of question. */
    const TYPES = ['choice', 'multichoice', 'yesno', 'scale', 'text', 'longtext', 'date', 'number', 'section'];

    /** @var string[] Kinds of question with options to choose. */
    const WITHOPTIONS = ['choice', 'multichoice', 'scale'];

    /** @var string[] The common questions a form may ask besides who and how: where they live, how they heard of the
     * centre, whether they are already of the centre, and what they want to tell. */
    const BLOCKS = ['place', 'heard', 'incentre', 'message'];

    /**
     * The questions of a form, in order.
     *
     * @param int $entryid
     * @return \stdClass[]
     */
    public static function fields(int $entryid): array {
        global $DB;
        return $entryid ? $DB->get_records(self::FIELDS, ['entryid' => $entryid], 'sortorder ASC, id ASC') : [];
    }

    /**
     * A question of a form.
     *
     * @param int $id
     * @param int $entryid
     * @return \stdClass|null
     */
    public static function field(int $id, int $entryid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::FIELDS, ['id' => $id, 'entryid' => $entryid]) ?: null;
    }

    /**
     * The common questions a form asks.
     *
     * @param \stdClass $entry
     * @return string[]
     */
    public static function blocks(\stdClass $entry): array {
        return array_values(array_intersect(self::BLOCKS, explode(',', (string) $entry->blocks)));
    }

    /**
     * The texts of a question in a language: what the centre wrote in it, or in its own language.
     *
     * @param \stdClass $field
     * @param string $lang
     * @param string $fallback The language of the centre.
     * @return array label, help and options.
     */
    public static function texts(\stdClass $field, string $lang, string $fallback): array {
        $all = json_decode((string) $field->texts, true) ?: [];
        $mine = $all[$lang] ?? [];
        $base = $all[$fallback] ?? (reset($all) ?: []);
        $label = trim((string) ($mine['label'] ?? '')) ?: trim((string) ($base['label'] ?? ''));
        $help = trim((string) ($mine['help'] ?? '')) ?: trim((string) ($base['help'] ?? ''));
        $baseoptions = array_values(array_filter(array_map('trim', (array) ($base['options'] ?? []))));
        $options = array_values(array_filter(array_map('trim', (array) ($mine['options'] ?? []))));
        // The options of each language go in the same order: the answer is kept in the language of the centre.
        if (count($options) !== count($baseoptions)) {
            $options = $baseoptions;
        }
        return ['label' => $label, 'help' => $help, 'options' => $options, 'baseoptions' => $baseoptions];
    }

    /**
     * Keeps a question: new when it has no id.
     *
     * @param \stdClass $entry
     * @param \stdClass $data type, required, showif, showvalue and, by language, label, help and options (one a line).
     * @return int
     */
    public static function save_field(\stdClass $entry, \stdClass $data): int {
        global $DB;
        $texts = [];
        foreach (lists::LANGS as $lang) {
            $label = trim((string) ($data->{'label_' . $lang} ?? ''));
            $help = trim((string) ($data->{'help_' . $lang} ?? ''));
            $options = array_values(array_filter(array_map(
                fn($o) => \core_text::substr(trim($o), 0, 200),
                preg_split('/\R/', (string) ($data->{'options_' . $lang} ?? ''))
            ), fn($o) => $o !== ''));
            if ($label !== '' || $help !== '' || $options) {
                $texts[$lang] = ['label' => \core_text::substr($label, 0, 255), 'help' => \core_text::substr($help, 0, 1000),
                    'options' => array_slice($options, 0, 30)];
            }
        }
        $type = in_array($data->type ?? '', self::TYPES, true) ? $data->type : 'text';
        if ($type === 'scale' && !array_filter(array_column($texts, 'options'))) {
            // A scale in words starts with four: «Nothing · A little · Quite · A lot», in every language.
            foreach (lists::LANGS as $lang) {
                $texts[$lang] = ($texts[$lang] ?? ['label' => '', 'help' => '']) + ['options' => []];
                $texts[$lang]['options'] = preg_split('/\R/', publiclang::str('scale_words', $lang));
            }
        }
        $showif = (int) ($data->showif ?? 0);
        $row = (object) [
            'type' => $type,
            'texts' => json_encode($texts, JSON_UNESCAPED_UNICODE),
            'required' => $type === 'section' || empty($data->required) ? 0 : 1,
            'showif' => $showif && self::field($showif, (int) $entry->id) && $showif !== (int) ($data->id ?? 0) ? $showif : 0,
            'showvalue' => $showif ? \core_text::substr(trim((string) ($data->showvalue ?? '')), 0, 255) : null,
            'timemodified' => time(),
        ];
        if (!empty($data->id) && self::field((int) $data->id, (int) $entry->id)) {
            $row->id = (int) $data->id;
            $DB->update_record(self::FIELDS, $row);
            return $row->id;
        }
        $row->centreid = $entry->centreid;
        $row->entryid = $entry->id;
        $row->sortorder = (int) $DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {' . self::FIELDS .
            '} WHERE entryid = ?', [$entry->id]);
        $row->timecreated = $row->timemodified;
        return (int) $DB->insert_record(self::FIELDS, $row);
    }

    /**
     * Moves a question one place up or down.
     *
     * @param \stdClass $entry
     * @param int $fieldid
     * @param int $step -1 or 1.
     */
    public static function move(\stdClass $entry, int $fieldid, int $step): void {
        global $DB;
        $ids = array_keys(self::fields((int) $entry->id));
        $at = array_search($fieldid, $ids);
        $to = $at === false ? false : $at + ($step < 0 ? -1 : 1);
        if ($to === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        foreach ($ids as $i => $id) {
            $DB->set_field(self::FIELDS, 'sortorder', $i + 1, ['id' => $id]);
        }
    }

    /**
     * A copy of a question, just after it, with its texts in every language and its condition.
     *
     * @param \stdClass $entry
     * @param int $fieldid
     * @return int The new one, or 0 if there was none to copy.
     */
    public static function duplicate(\stdClass $entry, int $fieldid): int {
        global $DB;
        $field = self::field($fieldid, (int) $entry->id);
        if (!$field) {
            return 0;
        }
        $DB->execute(
            'UPDATE {' . self::FIELDS . '} SET sortorder = sortorder + 1 WHERE entryid = ? AND sortorder > ?',
            [$entry->id, $field->sortorder]
        );
        $copy = clone($field);
        unset($copy->id);
        $copy->sortorder = (int) $field->sortorder + 1;
        $copy->timecreated = $copy->timemodified = time();
        return (int) $DB->insert_record(self::FIELDS, $copy);
    }

    /**
     * Removes a question; what families answered to it stays with them.
     *
     * @param \stdClass $entry
     * @param int $fieldid
     */
    public static function delete_field(\stdClass $entry, int $fieldid): void {
        global $DB;
        $DB->set_field(self::FIELDS, 'showif', 0, ['entryid' => $entry->id, 'showif' => $fieldid]);
        $DB->delete_records(self::FIELDS, ['id' => $fieldid, 'entryid' => $entry->id]);
    }

    /**
     * What came for the questions of a form, from the page that was sent.
     *
     * @param int $entryid
     * @return array field id => answer (a list for several options).
     */
    public static function read(int $entryid): array {
        $answers = [];
        foreach (self::fields($entryid) as $field) {
            if ($field->type === 'section') {
                continue;
            }
            $answers[$field->id] = $field->type === 'multichoice' ?
                array_values(array_filter(array_map('trim', optional_param_array('f' . $field->id, [], PARAM_TEXT)))) :
                trim(optional_param('f' . $field->id, '', PARAM_TEXT));
        }
        return $answers;
    }

    /**
     * Whether a question is shown: always, or when the question it depends on has the answer it waits for.
     *
     * @param \stdClass $field
     * @param array $answers
     * @return bool
     */
    public static function shown(\stdClass $field, array $answers): bool {
        if (!$field->showif) {
            return true;
        }
        $answer = $answers[$field->showif] ?? '';
        $want = recognise::plain((string) $field->showvalue);
        // After a yes or no, the answer is kept as yes or no, whatever the language the team wrote it in.
        $wants = [$want];
        if (in_array($want, ['si', 'yes', 'sim'], true)) {
            $wants[] = 'yes';
        } else if (in_array($want, ['no', 'nao'], true)) {
            $wants[] = 'no';
        }
        $given = array_map([recognise::class, 'plain'], is_array($answer) ? $answer : [$answer]);
        return (bool) array_intersect($wants, $given);
    }

    /**
     * What is missing or wrong in the answers, by question.
     *
     * @param int $entryid
     * @param array $answers
     * @param string $lang
     * @param string $fallback
     * @return array 'f<id>' => message
     */
    public static function check(int $entryid, array $answers, string $lang, string $fallback): array {
        $errors = [];
        foreach (self::fields($entryid) as $field) {
            if ($field->type === 'section' || !self::shown($field, $answers)) {
                continue;
            }
            $answer = $answers[$field->id] ?? '';
            $texts = self::texts($field, $lang, $fallback);
            $empty = is_array($answer) ? !$answer : $answer === '';
            if ($empty) {
                if ($field->required) {
                    $errors['f' . $field->id] = publiclang::str('pub_err_answer', $lang, $texts['label']);
                }
                continue;
            }
            if (in_array($field->type, self::WITHOPTIONS, true)) {
                foreach ((array) $answer as $one) {
                    if (!in_array($one, $texts['baseoptions'], true)) {
                        $errors['f' . $field->id] = publiclang::str('pub_err_answer', $lang, $texts['label']);
                    }
                }
            } else if ($field->type === 'yesno' && !in_array($answer, ['yes', 'no'], true)) {
                $errors['f' . $field->id] = publiclang::str('pub_err_answer', $lang, $texts['label']);
            } else if ($field->type === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer)) {
                $errors['f' . $field->id] = publiclang::str('pub_err_date', $lang, $texts['label']);
            } else if ($field->type === 'number' && !is_numeric(str_replace(',', '.', $answer))) {
                $errors['f' . $field->id] = publiclang::str('pub_err_number', $lang, $texts['label']);
            }
        }
        return $errors;
    }

    /**
     * Keeps what a family answered: the questions shown and answered, as they were asked in the language of the centre.
     *
     * @param \stdClass $household
     * @param \stdClass $entry
     * @param array $answers
     * @param string $fallback
     * @return string[] The answers, a line each, for its history.
     */
    public static function save_answers(\stdClass $household, \stdClass $entry, array $answers, string $fallback): array {
        global $DB;
        $lines = [];
        foreach (self::fields((int) $entry->id) as $field) {
            $answer = $answers[$field->id] ?? '';
            if ($field->type === 'section' || !self::shown($field, $answers) || $answer === '' || $answer === []) {
                continue;
            }
            $texts = self::texts($field, $fallback, $fallback);
            $value = is_array($answer) ? implode(', ', $answer) : $answer;
            if ($field->type === 'yesno') {
                $value = get_string($answer === 'yes' ? 'yes' : 'no');
            }
            $DB->insert_record(self::ANSWERS, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
                'entryid' => $entry->id, 'fieldid' => $field->id, 'label' => \core_text::substr($texts['label'], 0, 255),
                'value' => \core_text::substr($value, 0, 4000), 'timecreated' => time()]);
            $lines[] = $texts['label'] . ': ' . $value;
        }
        return $lines;
    }

    /**
     * What a family answered in the forms of the centre, the newest first.
     *
     * @param int $householdid
     * @return \stdClass[] With label, value, entryid and timecreated.
     */
    public static function answers_of(int $householdid): array {
        global $DB;
        return array_values($DB->get_records(self::ANSWERS, ['householdid' => $householdid], 'timecreated DESC, id ASC'));
    }

    /**
     * The questions of a form for its page: each with what it needs to be drawn and what was answered.
     *
     * @param \stdClass $entry
     * @param string $lang
     * @param string $fallback
     * @param array $answers
     * @param array $errors
     * @return array
     */
    public static function context(\stdClass $entry, string $lang, string $fallback, array $answers, array $errors): array {
        $out = [];
        $values = [];
        foreach (self::fields((int) $entry->id) as $field) {
            $texts = self::texts($field, $lang, $fallback);
            $answer = $answers[$field->id] ?? ($field->type === 'multichoice' ? [] : '');
            $options = [];
            foreach ($texts['options'] as $i => $label) {
                $value = $texts['baseoptions'][$i] ?? $label;
                $values[$field->id][] = $value;
                $options[] = ['value' => $value, 'label' => $label, 'id' => 'f' . $field->id . '-' . $i,
                    'checked' => is_array($answer) ? in_array($value, $answer, true) : $answer === $value];
            }
            if ($field->type === 'yesno') {
                foreach (['yes', 'no'] as $value) {
                    $options[] = ['value' => $value, 'label' => publiclang::str('pub_' . $value, $lang),
                        'id' => 'f' . $field->id . '-' . $value, 'checked' => $answer === $value];
                }
            }
            $error = $errors['f' . $field->id] ?? '';
            $out[] = [
                'id' => (int) $field->id,
                'name' => 'f' . $field->id,
                'label' => $texts['label'],
                'help' => $texts['help'],
                'required' => (bool) $field->required,
                'value' => is_array($answer) ? '' : $answer,
                'options' => $options,
                'error' => $error,
                'invalid' => $error !== '',
                'hidden' => !self::shown($field, $answers),
                'showif' => $field->showif ? 'f' . $field->showif : '',
                'showvalue' => $field->type === 'yesno' ? '' : (string) $field->showvalue,
                'issection' => $field->type === 'section',
                'istext' => $field->type === 'text',
                'islong' => $field->type === 'longtext',
                'isdate' => $field->type === 'date',
                'isnumber' => $field->type === 'number',
                'ischips' => in_array($field->type, ['choice', 'yesno', 'scale'], true),
                'isscale' => $field->type === 'scale',
                'ismulti' => $field->type === 'multichoice',
            ];
        }
        // A question shown after a yes or no waits for the value of the form, not its words.
        foreach (self::fields((int) $entry->id) as $field) {
            if ($field->showif) {
                $parent = self::field((int) $field->showif, (int) $entry->id);
                foreach ($out as $i => $row) {
                    if ($row['id'] === (int) $field->id && $parent && $parent->type === 'yesno') {
                        $out[$i]['showvalue'] = in_array(
                            recognise::plain((string) $field->showvalue),
                            ['si', 'yes', 'sim'],
                            true
                        ) ? 'yes' : 'no';
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The places a form is shared from, each with its key for the link.
     *
     * @param \stdClass $entry
     * @return array key => name
     */
    public static function origins(\stdClass $entry): array {
        return json_decode((string) $entry->origins, true) ?: [];
    }

    /**
     * A new place a form is shared from («Instagram», «Cartel de la puerta»), with a link of its own.
     *
     * @param \stdClass $entry
     * @param string $name
     * @return string Its key.
     */
    public static function add_origin(\stdClass $entry, string $name): string {
        global $DB;
        $name = \core_text::substr(trim($name), 0, 60);
        $origins = self::origins($entry);
        $key = substr(preg_replace('/[^a-z0-9]+/', '', recognise::plain($name)), 0, 16) ?: 'o';
        $base = $key;
        for ($i = 2; isset($origins[$key]); $i++) {
            $key = $base . $i;
        }
        $origins[$key] = $name;
        $entry->origins = json_encode($origins, JSON_UNESCAPED_UNICODE);
        $DB->set_field(entries::T, 'origins', $entry->origins, ['id' => $entry->id]);
        return $key;
    }

    /**
     * Stops sharing a form from a place; families that came from it keep it in their history.
     *
     * @param \stdClass $entry
     * @param string $key
     */
    public static function remove_origin(\stdClass $entry, string $key): void {
        global $DB;
        $origins = self::origins($entry);
        unset($origins[$key]);
        $entry->origins = json_encode($origins, JSON_UNESCAPED_UNICODE);
        $DB->set_field(entries::T, 'origins', $entry->origins, ['id' => $entry->id]);
    }

    /**
     * A form as if it were an event, for the page of signing up: no day, no turns, nobody counted.
     *
     * @param \stdClass $entry
     * @return \stdClass
     */
    public static function as_event(\stdClass $entry): \stdClass {
        return (object) ['id' => 0, 'centreid' => $entry->centreid, 'kind' => 'form', 'name' => (string) $entry->name,
            'place' => '', 'timestart' => 0, 'timeend' => 0, 'capacity' => 0, 'open' => 1, 'hosts' => '', 'tailored' => 0,
            'householdid' => 0, 'formid' => (int) $entry->id, 'token' => ''];
    }
}
