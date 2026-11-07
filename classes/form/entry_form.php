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

/**
 * The settings of an entry: its name and whether it is open; for a form, also what families read and the common
 * questions it asks.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entry_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $isform = !empty($this->_customdata['isform']);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'settings');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('text', 'name', get_string('entry_name', $c), ['size' => 40, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'entry_name', $c);
        if (!empty($this->_customdata['isemail'])) {
            // The address the centre forwards its mailbox of admissions to, as Oksigenia gives it.
            $mform->addElement('text', 'title', get_string('entry_email_address', $c), ['size' => 40, 'maxlength' => 255]);
            $mform->setType('title', PARAM_EMAIL);
            $mform->addHelpButton('title', 'entry_email_address', $c);
        }
        if ($isform) {
            $mform->addElement('text', 'title', get_string('entry_title', $c), ['size' => 40, 'maxlength' => 255]);
            $mform->setType('title', PARAM_TEXT);
            $mform->addHelpButton('title', 'entry_title', $c);
            $mform->addElement('textarea', 'lead', get_string('entry_lead', $c), ['rows' => 3, 'cols' => 60]);
            $mform->setType('lead', PARAM_TEXT);
            $group = [];
            foreach (forms::BLOCKS as $block) {
                $group[] = $mform->createElement('advcheckbox', 'block_' . $block, '', get_string('entry_block_' . $block, $c));
            }
            $mform->addGroup($group, 'blocks', get_string('entry_blocks', $c), '<br>', false);
            $mform->addHelpButton('blocks', 'entry_blocks', $c);
        }
        $mform->addElement('advcheckbox', 'acknowledge', get_string('entry_acknowledge', $c));
        $mform->addHelpButton('acknowledge', 'entry_acknowledge', $c);
        $mform->addElement('advcheckbox', 'active', get_string('entry_active', $c));
        $this->add_action_buttons(false);
    }
}
