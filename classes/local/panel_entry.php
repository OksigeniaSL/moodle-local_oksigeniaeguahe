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
 * A form of the centre in the panel, whatever it looks like: the language it is written in, the questions it always
 * asks, its own questions with one open in its editor, where it is shared, its settings, and the phone with the form as
 * a family sees it. Also what each button posts.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_entry {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The questions with answers to choose, which another one may wait for. */
    const ANSWERED = ['choice', 'multichoice', 'yesno', 'scale'];

    /**
     * The language the form is written in: the one asked, else that of the centre. The parameter is not «lang», which
     * would change the language of the whole of Moodle.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function lang(\stdClass $centre): string {
        $asked = optional_param('idioma', '', PARAM_ALPHAEXT);
        return in_array($asked, lists::LANGS, true) ? $asked : (in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es');
    }

    /**
     * The address of the form, in a language, with a question open.
     *
     * @param \stdClass $entry
     * @param string $lang
     * @param int $open
     * @param string $anchor
     * @return \moodle_url
     */
    public static function url(\stdClass $entry, string $lang, int $open = 0, string $anchor = ''): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $entry->id, 'idioma' => $lang] +
            ($open ? ['q' => $open] : []), $anchor ?: null);
    }

    /**
     * The context of the page of a form.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param string $lang
     * @param int $open The question open in its editor.
     * @return array
     */
    public static function context(\stdClass $entry, \stdClass $centre, string $lang, int $open = 0): array {
        $c = self::C;
        $base = (string) ($centre->lang ?: 'es');
        $all = forms::fields((int) $entry->id);
        $labels = self::labels($all, $lang, $base);
        $langs = [];
        foreach (lists::LANG_NAMES as $code => $name) {
            $missing = count(array_filter($all, fn($f) => $f->type !== 'section' && !self::has_label($f, $code)));
            $langs[] = ['code' => $code, 'name' => $name, 'current' => $code === $lang,
                'missing' => $missing ? get_string('panel_lang_missing', $c, $missing) : '',
                'url' => self::url($entry, $code, $open)->out(false)];
        }
        $fields = [];
        $n = 0;
        foreach (array_values($all) as $i => $field) {
            $n++;
            $isopen = (int) $field->id === $open;
            $fields[] = [
                'id' => (int) $field->id,
                'n' => $n,
                'label' => $labels[$field->id] !== '' ? $labels[$field->id] : get_string('panel_question_untitled', $c),
                'typename' => get_string('field_type_' . $field->type, $c),
                'required' => !empty($field->required),
                'condition' => self::condition($field, $all, $labels, $lang, $base),
                'missing' => self::missing($field, $lang),
                'first' => $i === 0,
                'last' => $i === count($all) - 1,
                'open' => $isopen,
                'editurl' => self::url($entry, $lang, (int) $field->id, 'p-' . $field->id)->out(false),
                'editor' => $isopen ? self::editor($entry, $centre, $field, $lang, self::url(
                    $entry,
                    $lang,
                    (int) $field->id
                )) : false,
            ];
        }
        $blocks = [];
        foreach (forms::BLOCKS as $block) {
            $blocks[] = ['i' => $block, 'name' => publiclang::str('entry_block_' . $block, $lang),
                'checked' => in_array($block, forms::blocks($entry), true), 'fixed' => false];
        }
        $types = [];
        foreach (forms::TYPES as $type) {
            $types[] = ['value' => $type, 'name' => get_string('field_type_' . $type, $c), 'selected' => $type === 'yesno'];
        }
        $action = self::url($entry, $lang, $open)->out(false);
        $issurvey = $entry->kind === surveys::KIND;
        return [
            'head' => panel::head([
                'title' => format_string($entry->name),
                'ante' => get_string($issurvey ? 'panel_survey_ante' : (!empty($entry->active) ? 'panel_form_ante_open' :
                    'panel_form_ante_closed'), $c),
                'sub' => get_string($issurvey ? 'panel_survey_sub' : 'panel_form_sub', $c),
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('tab_entries', $c)],
                'action' => $issurvey ? false : ['url' => '#compartir', 'anchor' => true, 'name' => get_string('entry_share', $c),
                    'icon' => panel::icon('enlace')],
            ]),
            'issurvey' => $issurvey,
            'url' => $action,
            'action' => $action,
            'sesskey' => sesskey(),
            'saved' => '',
            'langs' => $langs,
            'langnote' => get_string($lang === $base ? 'panel_writing_note' : 'panel_writing_note_other', $c, (object) [
                'lang' => get_string('panel_langname_' . $lang, $c), 'base' => get_string('panel_langname_' . $base, $c)]),
            'blocks' => $blocks,
            'fields' => $fields,
            'newfield' => ['types' => $types],
            'phone' => self::phone($entry, $centre, $lang, $open),
            'share' => $issurvey ? false : self::share($entry),
            'settings' => ['name' => (string) $entry->name, 'title' => (string) $entry->title, 'lead' => (string) $entry->lead,
                'acknowledge' => !empty($entry->acknowledge), 'open' => !empty($entry->active)],
            'erasequestion' => get_string('panel_erase_form', $c, format_string($entry->name)),
        ];
    }

    /**
     * The context of the page of one question: its editor and the phone.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param \stdClass $field
     * @param string $lang
     * @return array
     */
    public static function field_context(\stdClass $entry, \stdClass $centre, \stdClass $field, string $lang): array {
        $c = self::C;
        $all = array_values(forms::fields((int) $entry->id));
        $at = array_search((int) $field->id, array_map(fn($f) => (int) $f->id, $all), true);
        $label = forms::texts($field, $lang, (string) ($centre->lang ?: 'es'))['label'];
        $url = new \moodle_url('/local/oksigeniaeguahe/field.php', ['e' => $entry->id, 'id' => $field->id, 'idioma' => $lang]);
        return [
            'head' => panel::head([
                'title' => $label !== '' ? $label : get_string('panel_question_untitled', $c),
                'ante' => get_string('panel_question_of', $c, (object) ['n' => $at + 1, 'total' => count($all)]),
                'back' => ['url' => self::url($entry, $lang, 0, 'preguntas')->out(false), 'name' => format_string($entry->name)],
            ]),
            'editor' => self::editor($entry, $centre, $field, $lang, $url),
            'phone' => self::phone($entry, $centre, $lang, (int) $field->id),
        ];
    }

    /**
     * The editor of a question: its type, its texts in the language it is written in, whether it must be answered,
     * when it appears, and its texts in the other languages with the original beside them.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param \stdClass $field
     * @param string $lang
     * @param \moodle_url $action
     * @return array
     */
    public static function editor(
        \stdClass $entry,
        \stdClass $centre,
        \stdClass $field,
        string $lang,
        \moodle_url $action
    ): array {
        $c = self::C;
        $base = (string) ($centre->lang ?: 'es');
        $all = forms::fields((int) $entry->id);
        $labels = self::labels($all, $lang, $base);
        $raw = json_decode((string) $field->texts, true) ?: [];
        $mine = $raw[$lang] ?? [];
        $prefix = 'q' . $field->id;
        $types = [];
        foreach (forms::TYPES as $type) {
            $types[] = ['value' => $type, 'name' => get_string('field_type_' . $type, $c), 'checked' => $type === $field->type,
                'id' => $prefix . '-t-' . $type];
        }
        // The earlier questions with answers to choose: one of them makes this one appear.
        $questions = [['value' => 0, 'name' => get_string('panel_shows_always', $c), 'selected' => !$field->showif]];
        $answers = [];
        foreach ($all as $other) {
            if ((int) $other->id === (int) $field->id) {
                break;
            }
            if (!in_array($other->type, self::ANSWERED, true)) {
                continue;
            }
            $selected = (int) $field->showif === (int) $other->id;
            $questions[] = ['value' => (int) $other->id, 'name' => $labels[$other->id], 'selected' => $selected];
            if ($selected) {
                foreach (self::options($other, $lang, $base) as $option) {
                    $answers[] = $option + ['selected' => self::same_answer($option['value'], (string) $field->showvalue)];
                }
            }
        }
        $original = forms::texts($field, $base, $base);
        $otherlangs = [];
        foreach (lists::LANG_NAMES as $code => $name) {
            if ($code === $lang) {
                continue;
            }
            $label = trim((string) ($raw[$code]['label'] ?? ''));
            $otherlangs[] = ['code' => $code, 'name' => $name, 'label' => $label, 'help' => (string) ($raw[$code]['help'] ?? ''),
                'original' => trim((string) ($mine['label'] ?? '')) ?: $original['label'],
                'originalhelp' => (string) ($mine['help'] ?? ''), 'missing' => $label === ''];
        }
        return [
            'id' => (int) $field->id,
            'prefix' => $prefix,
            'action' => $action->out(false),
            'sesskey' => sesskey(),
            'saved' => '',
            'types' => $types,
            'label' => (string) ($mine['label'] ?? ''),
            'help' => (string) ($mine['help'] ?? ''),
            'options' => implode("\n", (array) ($mine['options'] ?? [])),
            'hasoptions' => in_array($field->type, forms::WITHOPTIONS, true),
            'isscale' => $field->type === 'scale',
            'required' => !empty($field->required),
            'showif' => count($questions) > 1 ? [
                'has' => (bool) $field->showif,
                'sentence' => $field->showif ? self::condition($field, $all, $labels, $lang, $base) :
                    get_string('panel_shows_always_sentence', $c),
                'questions' => $questions,
                'answers' => $answers,
                'hasanswers' => (bool) $answers,
            ] : null,
            'canpoint' => count($questions) > 1,
            'missing' => self::missing($field, $lang),
            'otherlangs' => $otherlangs,
            'duplicateurl' => '',
            'deleteurl' => '',
        ];
    }

    /**
     * The phone: the form as a family sees it in a language, with the open question marked.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param string $lang
     * @param int $open
     * @return array
     */
    public static function phone(\stdClass $entry, \stdClass $centre, string $lang, int $open): array {
        $base = (string) ($centre->lang ?: 'es');
        $all = forms::fields((int) $entry->id);
        $labels = self::labels($all, $lang, $base);
        $questions = [];
        foreach ($all as $field) {
            $texts = forms::texts($field, $lang, $base);
            $from = $field->showif ? ($all[$field->showif] ?? null) : null;
            $answer = '';
            foreach ($from ? self::options($from, $lang, $base) : [] as $option) {
                $answer = self::same_answer($option['value'], (string) $field->showvalue) ? $option['name'] : $answer;
            }
            $questions[] = [
                'id' => (int) $field->id,
                'label' => $texts['label'],
                'help' => $texts['help'],
                'required' => !empty($field->required),
                'isyesno' => $field->type === 'yesno',
                'ischoice' => in_array($field->type, ['choice', 'scale'], true),
                'ismulti' => $field->type === 'multichoice',
                'islong' => $field->type === 'longtext',
                'istext' => in_array($field->type, ['text', 'date', 'number'], true),
                'options' => array_map(
                    fn($o) => ['value' => $o['value'], 'name' => $o['name']],
                    self::options($field, $lang, $base)
                ),
                'showif' => $from ? (int) $from->id : 0,
                'showvalue' => $from ? self::stored_answer($from, (string) $field->showvalue, $base) : '',
                'conditional' => (bool) $from,
                'conditionnote' => $from ? publiclang::str('panel_phone_if', $lang, (object) ['question' => $labels[$from->id],
                    'answer' => $answer ?: (string) $field->showvalue]) : '',
                'open' => (int) $field->id === $open,
            ];
        }
        $sentence = [];
        $text = publiclang::str('panel_phone_sentence', $lang);
        foreach (preg_split('/(\{[a-z]+\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            $hole = preg_match('/^\{([a-z]+)\}$/', $part, $m);
            $sentence[] = ['text' => $hole ? '' : $part, 'hole' => (bool) $hole, 'k' => $hole ? $m[1] : ''];
        }
        $name = format_string($centre->name);
        if ($entry->kind === surveys::KIND) {
            // A survey after the visit: the open question first, and nothing of signing up.
            array_unshift($questions, ['id' => 0, 'label' => publiclang::str('pub_after_question', $lang),
                'help' => publiclang::str('pub_after_help', $lang), 'required' => false, 'isyesno' => false, 'ischoice' => false,
                'ismulti' => false, 'islong' => true, 'istext' => false, 'options' => [], 'showif' => 0, 'showvalue' => '',
                'conditional' => false, 'conditionnote' => '', 'open' => false]);
            $count = count(array_filter($all, fn($f) => $f->type !== 'section')) + 1;
            return ['centre' => $name, 'initial' => \core_text::strtoupper(\core_text::substr($name, 0, 1)),
                'title' => publiclang::str('pub_howitwent', $lang), 'lead' => publiclang::str('pub_howitwent_lead', $lang, $count),
                'sentence' => [], 'hassentence' => false, 'fixed' => '', 'questions' => $questions, 'blocks' => [],
                'button' => publiclang::str('pub_howitwent_save', $lang), 'faces' => [], 'foot' => '', 'base' => 0,
                'perquestion' => 10];
        }
        return [
            'centre' => $name,
            'initial' => \core_text::strtoupper(\core_text::substr($name, 0, 1)),
            'title' => trim((string) $entry->title) !== '' ? format_string($entry->title) :
                publiclang::str('pub_form_title', $lang),
            'lead' => trim((string) $entry->lead) !== '' ? format_string($entry->lead) : publiclang::str('pub_form_lead', $lang),
            'sentence' => $sentence,
            'hassentence' => true,
            'fixed' => publiclang::str('panel_phone_fixed', $lang),
            'questions' => $questions,
            'blocks' => array_map(fn($b) => ['q' => publiclang::str('entry_block_' . $b, $lang)], forms::blocks($entry)),
            'button' => publiclang::str('pub_submit_form', $lang),
            'faces' => [],
            'foot' => '',
            'base' => 4,
            'perquestion' => 10,
        ];
    }

    /**
     * Where the form is shared from: the general link and each place with its own, its QR and the families it brought.
     *
     * @param \stdClass $entry
     * @return array
     */
    public static function share(\stdClass $entry): array {
        global $DB;
        $c = self::C;
        $brought = $DB->get_records_sql_menu('SELECT COALESCE(detail, \'\') AS site, COUNT(DISTINCT householdid) AS n
                                                FROM {' . households::CONTACTS . '}
                                               WHERE entryid = ? AND direction = ?
                                            GROUP BY COALESCE(detail, \'\')', [$entry->id, 'in']);
        $site = function (string $name, string $key, bool $general) use ($entry, $brought, $c): array {
            $url = (new \moodle_url(entries::form_url($entry), $key !== '' ? ['d' => $key] : []))->out(false);
            $n = (int) ($brought[$general ? '' : $name] ?? 0);
            return ['key' => $key, 'name' => $general ? get_string('entry_origin_none', $c) : $name,
                'short' => preg_replace('#^https?://#', '', $url), 'url' => $url,
                'n' => $n ? get_string($n === 1 ? 'panel_brought_one' : 'panel_brought', $c, $n) : '',
                'qr' => events::qr_of($url), 'general' => $general];
        };
        $sites = [$site('', '', true)];
        foreach (forms::origins($entry) as $key => $name) {
            $sites[] = $site((string) $name, (string) $key, false);
        }
        return ['sites' => $sites, 'embed' => entries::embed_code($entry, format_string($entry->title ?: $entry->name))];
    }

    /**
     * Does what a button of the form posted, and says where to go after it.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param string $lang
     * @param \moodle_url $here The page it came from.
     * @return array [where to go, a message or '']; null where, for the save that happens by itself.
     */
    public static function act(\stdClass $entry, \stdClass $centre, string $lang, \moodle_url $here): array {
        global $DB;
        $c = self::C;
        $action = optional_param('action', '', PARAM_ALPHA);
        $fieldid = optional_param('field', 0, PARAM_INT);
        $field = $fieldid ? forms::field($fieldid, (int) $entry->id) : null;
        switch ($action) {
            case 'blocks':
                $chosen = optional_param_array('blocks', [], PARAM_ALPHA);
                $DB->set_field(
                    entries::T,
                    'blocks',
                    implode(',', array_values(array_intersect(forms::BLOCKS, $chosen))),
                    ['id' => $entry->id]
                );
                return [self::url($entry, $lang, 0, 'preguntas'), get_string('changessaved')];
            case 'moveup':
            case 'movedown':
                forms::move($entry, $fieldid, $action === 'moveup' ? -1 : 1);
                return [self::url($entry, $lang, optional_param('q', 0, PARAM_INT), 'p-' . $fieldid), ''];
            case 'addfield':
                $label = trim(optional_param('label', '', PARAM_TEXT));
                $type = optional_param('type', 'text', PARAM_ALPHA);
                $new = forms::save_field($entry, (object) ['type' => $type, 'label_' . $lang => $label]);
                return [self::url($entry, $lang, $new, 'p-' . $new), ''];
            case 'savefield':
                if ($field) {
                    self::save_field($entry, $field, $lang);
                }
                return [optional_param('autosave', 0, PARAM_BOOL) ? null : $here, get_string('panel_question_saved', $c)];
            case 'duplicate':
                $copy = $field ? forms::duplicate($entry, (int) $field->id) : 0;
                return [self::url($entry, $lang, $copy, $copy ? 'p-' . $copy : 'preguntas'), ''];
            case 'deletefield':
                if ($field) {
                    forms::delete_field($entry, (int) $field->id);
                }
                return [self::url($entry, $lang, 0, 'preguntas'), get_string('field_deleted', $c)];
            case 'addorigin':
                $name = trim(optional_param('name', '', PARAM_TEXT));
                if ($name !== '') {
                    forms::add_origin($entry, $name);
                }
                return [self::url($entry, $lang, 0, 'compartir'), ''];
            case 'removeorigin':
                forms::remove_origin($entry, required_param('key', PARAM_ALPHANUM));
                return [self::url($entry, $lang, 0, 'compartir'), ''];
            case 'settings':
                entries::update($entry, (object) ['name' => optional_param('name', $entry->name, PARAM_TEXT),
                    'acknowledge' => optional_param('acknowledge', 0, PARAM_BOOL),
                    'active' => $entry->kind === surveys::KIND ? 1 : optional_param('active', 0, PARAM_BOOL)]);
                $DB->update_record(entries::T, (object) ['id' => $entry->id,
                    'title' => \core_text::substr(trim(optional_param('title', '', PARAM_TEXT)), 0, 255) ?: null,
                    'lead' => trim(optional_param('lead', '', PARAM_TEXT)) ?: null]);
                return [self::url($entry, $lang), get_string('changessaved')];
            case 'delete':
                entries::delete($entry);
                return [new \moodle_url('/local/oksigeniaeguahe/entries.php', ['c' => $centre->id]),
                    get_string('entry_deleted', $c)];
        }
        return [$here, ''];
    }

    /**
     * Saves a question from its editor: its texts in the language it is written in, the label in the others, keeping
     * what each language had besides.
     *
     * @param \stdClass $entry
     * @param \stdClass $field
     * @param string $lang
     */
    public static function save_field(\stdClass $entry, \stdClass $field, string $lang): void {
        $raw = json_decode((string) $field->texts, true) ?: [];
        $data = (object) ['id' => $field->id, 'type' => optional_param('type', $field->type, PARAM_ALPHA),
            'required' => optional_param('required', 0, PARAM_BOOL), 'showif' => optional_param('showif', 0, PARAM_INT),
            'showvalue' => optional_param('showvalue', '', PARAM_TEXT)];
        foreach (lists::LANGS as $code) {
            $data->{'label_' . $code} = $raw[$code]['label'] ?? '';
            $data->{'help_' . $code} = $raw[$code]['help'] ?? '';
            $data->{'options_' . $code} = implode("\n", (array) ($raw[$code]['options'] ?? []));
            if ($code !== $lang) {
                $data->{'label_' . $code} = optional_param('label_' . $code, $data->{'label_' . $code}, PARAM_TEXT);
            }
        }
        $data->{'label_' . $lang} = optional_param('label', '', PARAM_TEXT);
        $data->{'help_' . $lang} = optional_param('help', '', PARAM_TEXT);
        if (in_array($data->type, forms::WITHOPTIONS, true)) {
            $data->{'options_' . $lang} = optional_param('options', $data->{'options_' . $lang}, PARAM_TEXT);
        }
        forms::save_field($entry, $data);
    }

    /**
     * The label of each question in a language.
     *
     * @param array $fields
     * @param string $lang
     * @param string $base
     * @return array id => label
     */
    protected static function labels(array $fields, string $lang, string $base): array {
        $out = [];
        foreach ($fields as $field) {
            $out[$field->id] = forms::texts($field, $lang, $base)['label'];
        }
        return $out;
    }

    /**
     * Whether a question has its own text in a language.
     *
     * @param \stdClass $field
     * @param string $lang
     * @return bool
     */
    protected static function has_label(\stdClass $field, string $lang): bool {
        $raw = json_decode((string) $field->texts, true) ?: [];
        return trim((string) ($raw[$lang]['label'] ?? '')) !== '';
    }

    /**
     * The languages a question is missing in, in words: «Falta en inglés y portugués».
     *
     * @param \stdClass $field
     * @param string $lang The one it is written in, which does not count.
     * @return string
     */
    protected static function missing(\stdClass $field, string $lang): string {
        if ($field->type === 'section' && !self::has_label($field, $lang)) {
            return '';
        }
        $names = [];
        foreach (lists::LANGS as $code) {
            if ($code !== $lang && !self::has_label($field, $code)) {
                $names[] = get_string('panel_langname_' . $code, self::C);
            }
        }
        return $names ? get_string('panel_missing_in', self::C, familypage::join($names, current_language())) : '';
    }

    /**
     * The answers a question gives to choose, in a language, with the value each keeps.
     *
     * @param \stdClass $field
     * @param string $lang
     * @param string $base
     * @return array value and name.
     */
    protected static function options(\stdClass $field, string $lang, string $base): array {
        if ($field->type === 'yesno') {
            return [['value' => 'yes', 'name' => publiclang::str('pub_yes', $lang)],
                ['value' => 'no', 'name' => publiclang::str('pub_no', $lang)]];
        }
        $texts = forms::texts($field, $lang, $base);
        $out = [];
        foreach ($texts['options'] as $i => $name) {
            $out[] = ['value' => $texts['baseoptions'][$i] ?? $name, 'name' => $name];
        }
        return $out;
    }

    /**
     * Whether an answer is the one a condition waits for, as forms::shown() compares them.
     *
     * @param string $value
     * @param string $wanted
     * @return bool
     */
    protected static function same_answer(string $value, string $wanted): bool {
        $plain = fn(string $s) => recognise::plain($s);
        $yes = ['si', 'yes', 'sim'];
        $no = ['no', 'nao'];
        $a = $plain($value);
        $b = $plain($wanted);
        return $a === $b || (in_array($a, $yes, true) && in_array($b, $yes, true)) ||
            (in_array($a, $no, true) && in_array($b, $no, true));
    }

    /**
     * The value a condition waits for, as the answers of the phone keep it.
     *
     * @param \stdClass $from
     * @param string $wanted
     * @param string $base
     * @return string
     */
    protected static function stored_answer(\stdClass $from, string $wanted, string $base): string {
        foreach (self::options($from, $base, $base) as $option) {
            if (self::same_answer($option['value'], $wanted)) {
                return $option['value'];
            }
        }
        return $wanted;
    }

    /**
     * When a question appears, in a sentence: «Sale si a «¿Necesitan comedor?» contestan «Sí».».
     *
     * @param \stdClass $field
     * @param array $all
     * @param array $labels
     * @param string $lang
     * @param string $base
     * @return string
     */
    protected static function condition(\stdClass $field, array $all, array $labels, string $lang, string $base): string {
        $from = $field->showif ? ($all[$field->showif] ?? null) : null;
        if (!$from) {
            return '';
        }
        $answer = (string) $field->showvalue;
        foreach (self::options($from, $lang, $base) as $option) {
            $answer = self::same_answer($option['value'], (string) $field->showvalue) ? $option['name'] : $answer;
        }
        return get_string('field_condition', self::C, (object) ['question' => $labels[$from->id], 'answer' => $answer]);
    }
}
