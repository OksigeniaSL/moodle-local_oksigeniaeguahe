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
 * Someone of the team as the families see them: whether they are shown, their photo, what they do and a line of theirs.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class host_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        foreach (['c', 'm'] as $hidden) {
            $mform->addElement('hidden', $hidden);
            $mform->setType($hidden, PARAM_INT);
        }
        $mform->addElement('static', 'who', get_string('hosts_person', $c), s($this->_customdata['name'] ?? ''));
        $mform->addElement('advcheckbox', 'public', get_string('hosts_public', $c));
        $mform->addHelpButton('public', 'hosts_public', $c);
        $mform->addElement('filemanager', 'photo', get_string('hosts_photo', $c), null, ['subdirs' => 0, 'maxfiles' => 1,
            'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp']]);
        $mform->addHelpButton('photo', 'hosts_photo', $c);
        $mform->addElement('text', 'rolename', get_string('hosts_role', $c), ['size' => 40]);
        $mform->setType('rolename', PARAM_TEXT);
        $mform->addHelpButton('rolename', 'hosts_role', $c);
        $mform->addElement('textarea', 'says', get_string('hosts_says', $c), ['rows' => 2, 'cols' => 60]);
        $mform->setType('says', PARAM_TEXT);
        $mform->addHelpButton('says', 'hosts_says', $c);
        $mform->addElement('text', 'sortorder', get_string('hosts_order', $c), ['size' => 3]);
        $mform->setType('sortorder', PARAM_INT);
        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
