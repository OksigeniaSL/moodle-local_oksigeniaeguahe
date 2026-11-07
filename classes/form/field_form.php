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

namespace local_oksigeniaeguahe\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\lists;

/**
 * A question of a form: its kind, what it says in the language of the centre and, if they want, in the others, and
 * whether it waits for an answer to an earlier question.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $lang = $this->_customdata['lang'];
        $mform->addElement('hidden', 'e');
        $mform->setType('e', PARAM_INT);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $types = [];
        foreach (forms::TYPES as $type) {
            $types[$type] = get_string('field_type_' . $type, $c);
        }
        $mform->addElement('select', 'type', get_string('field_type', $c), $types);
        $mform->addHelpButton('type', 'field_type', $c);
        $noptions = array_values(array_diff(forms::TYPES, forms::WITHOPTIONS));
        $this->texts($lang, true, $noptions);
        $mform->addElement('advcheckbox', 'required', get_string('field_required', $c));
        $mform->hideIf('required', 'type', 'eq', 'section');
        $mform->addElement(
            'select',
            'showif',
            get_string('field_showif', $c),
            [0 => get_string('field_showif_always', $c)] + $this->_customdata['earlier']
        );
        $mform->addHelpButton('showif', 'field_showif', $c);
        $mform->addElement('text', 'showvalue', get_string('field_showvalue', $c), ['size' => 30, 'maxlength' => 255]);
        $mform->setType('showvalue', PARAM_TEXT);
        $mform->hideIf('showvalue', 'showif', 'eq', 0);
        $mform->addHelpButton('showvalue', 'field_showvalue', $c);
        $mform->addElement('header', 'otherlangs', get_string('field_otherlangs', $c));
        $mform->setExpanded('otherlangs', false);
        $mform->addElement('static', 'otherlangshelp', '', get_string('field_otherlangs_help', $c));
        foreach (lists::LANGS as $other) {
            if ($other !== $lang) {
                $this->texts($other, false, $noptions);
            }
        }
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * What a question says in one language.
     *
     * @param string $lang
     * @param bool $main Whether it is the language of the centre.
     * @param string[] $noptions The kinds of question without options.
     */
    protected function texts(string $lang, bool $main, array $noptions): void {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $name = lists::LANG_NAMES[$lang] ?? $lang;
        $mform->addElement(
            'text',
            'label_' . $lang,
            get_string('field_label', $c) . ($main ? '' : ' · ' . $name),
            ['size' => 60, 'maxlength' => 255]
        );
        $mform->setType('label_' . $lang, PARAM_TEXT);
        if ($main) {
            $mform->addRule('label_' . $lang, null, 'required', null, 'client');
        }
        $mform->addElement(
            'textarea',
            'help_' . $lang,
            get_string('field_help', $c) . ($main ? '' : ' · ' . $name),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('help_' . $lang, PARAM_TEXT);
        $mform->addElement(
            'textarea',
            'options_' . $lang,
            get_string('field_options', $c) . ($main ? '' : ' · ' . $name),
            ['rows' => 5, 'cols' => 40]
        );
        $mform->setType('options_' . $lang, PARAM_TEXT);
        $mform->hideIf('options_' . $lang, 'type', 'in', $noptions);
        if ($main) {
            $mform->addHelpButton('options_' . $lang, 'field_options', $c);
        }
    }

    /**
     * What a question cannot be: without words, or with fewer than two options to choose.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $c = 'local_oksigeniaeguahe';
        $lang = $this->_customdata['lang'];
        if (in_array($data['type'] ?? '', forms::WITHOPTIONS, true)) {
            $options = array_filter(array_map('trim', preg_split('/\R/', (string) ($data['options_' . $lang] ?? ''))));
            if (count($options) < 2) {
                $errors['options_' . $lang] = get_string('field_err_options', $c);
            }
        }
        if (!empty($data['showif']) && trim((string) ($data['showvalue'] ?? '')) === '') {
            $errors['showvalue'] = get_string('field_err_showvalue', $c);
        }
        return $errors;
    }
}
