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

/**
 * A file of what already happened: its kind, what it is (the campaign or the school year) and the file.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $mform->addElement('hidden', 'c');
        $mform->setType('c', PARAM_INT);
        $mform->addElement('static', 'importhelp', '', get_string('import_help', $c));
        $mform->addElement('select', 'kind', get_string('import_kind', $c), [
            'contacts' => get_string('import_kind_contacts', $c),
            'students' => get_string('import_kind_students', $c),
            'totals' => get_string('import_kind_totals', $c),
        ]);
        $mform->addElement('text', 'label', get_string('import_label', $c), ['size' => 20]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', null, 'required', null, 'client');
        $mform->addHelpButton('label', 'import_label', $c);
        $mform->addElement('filepicker', 'file', get_string('import_file', $c), null, ['accepted_types' => ['.csv']]);
        $mform->addRule('file', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('import_go', $c));
    }
}
