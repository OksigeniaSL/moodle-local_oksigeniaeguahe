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
 * An open day or a visit: its name, where and when, how many people fit, and whether families can still sign up.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $mform->addElement('hidden', 'c');
        $mform->setType('c', PARAM_INT);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('select', 'kind', get_string('event_kind', $c), [
            'openday' => get_string('event_kind_openday', $c),
            'visit' => get_string('event_kind_visit', $c),
        ]);
        $mform->addElement('text', 'name', get_string('event_name', $c), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('text', 'place', get_string('event_place', $c), ['size' => 50]);
        $mform->setType('place', PARAM_TEXT);
        $mform->addHelpButton('place', 'event_place', $c);
        $mform->addElement('date_time_selector', 'timestart', get_string('event_start', $c), ['step' => 15]);
        $mform->addElement('date_time_selector', 'timeend', get_string('event_end', $c), ['step' => 15]);
        $mform->addElement('text', 'capacity', get_string('event_capacity', $c), ['size' => 5]);
        $mform->setType('capacity', PARAM_INT);
        $mform->setDefault('capacity', 0);
        $mform->addHelpButton('capacity', 'event_capacity', $c);
        $mform->addElement('advcheckbox', 'open', get_string('event_open', $c));
        $mform->setDefault('open', 1);
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * The end after the start.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((int) $data['timeend'] < (int) $data['timestart']) {
            $errors['timeend'] = get_string('event_err_end', 'local_oksigeniaeguahe');
        }
        return $errors;
    }
}
