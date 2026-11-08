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

use local_oksigeniaeguahe\local\lists;

/**
 * A centre: how families see it, what it says about their data, its area, its offer with the places, and its team.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class centre_form extends \moodleform {
    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $offers = (int) ($this->_customdata['offers'] ?? 0);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'lookheader', get_string('centre_look', $c));
        $mform->addElement('text', 'name', get_string('centre_name', $c), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('select', 'lang', get_string('centre_lang', $c), lists::LANG_NAMES);
        $mform->addElement('text', 'country', get_string('centre_country', $c), ['size' => 3, 'maxlength' => 2]);
        $mform->setType('country', PARAM_ALPHA);
        $mform->addHelpButton('country', 'centre_country', $c);
        $mform->addElement('text', 'colour', get_string('centre_colour', $c), ['size' => 8, 'maxlength' => 7]);
        $mform->setType('colour', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('colour', 'centre_colour', $c);
        $mform->addElement('filemanager', 'logo', get_string('centre_logo', $c), null, ['subdirs' => 0,
            'maxfiles' => 1, 'accepted_types' => ['.png', '.svg', '.jpg', '.webp']]);
        $mform->addElement('advcheckbox', 'logoplate', get_string('centre_logoplate', $c));
        $mform->addHelpButton('logoplate', 'centre_logoplate', $c);

        // What the families see of the centre on their page: where it is, how to reach it, what a visit is like.
        $mform->addElement('header', 'familiesheader', get_string('centre_families', $c));
        $mform->addElement('text', 'address', get_string('centre_address', $c), ['size' => 50]);
        $mform->setType('address', PARAM_TEXT);
        $mform->addElement('text', 'phone', get_string('centre_phone', $c), ['size' => 20]);
        $mform->setType('phone', PARAM_TEXT);
        $mform->addElement('text', 'email', get_string('centre_email', $c), ['size' => 40]);
        $mform->setType('email', PARAM_RAW_TRIMMED);
        $mform->addElement('textarea', 'welcome', get_string('centre_welcome', $c), ['rows' => 2, 'cols' => 60]);
        $mform->setType('welcome', PARAM_TEXT);
        $mform->addHelpButton('welcome', 'centre_welcome', $c);
        if (\local_oksigeniaeguahe\local\messaging::available()) {
            // Only when the site has Marandu: without it, there is nothing to choose.
            $modes = [];
            foreach (\local_oksigeniaeguahe\local\messaging::MODES as $mode) {
                $modes[$mode] = get_string('centre_messaging_' . $mode, $c);
            }
            $mform->addElement('select', 'messaging', get_string('centre_messaging', $c), $modes);
            $mform->setDefault('messaging', 'notices');
            $mform->addHelpButton('messaging', 'centre_messaging', $c);
        }
        // The skin of the panel of the team: the one the offer suggests, or one chosen.
        $centreid = (int) ($this->_customdata['centreid'] ?? 0);
        $skins = ['' => get_string('centre_skin_auto', $c, get_string('skin_' .
            \local_oksigeniaeguahe\local\panel::proposed_skin($centreid), $c))];
        foreach (\local_oksigeniaeguahe\local\panel::SKINS as $skin) {
            $skins[$skin] = get_string('skin_' . $skin, $c);
        }
        $mform->addElement('select', 'skin', get_string('centre_skin', $c), $skins);
        $mform->addHelpButton('skin', 'centre_skin', $c);
        if (!empty($this->_customdata['hostsurl'])) {
            $mform->addElement(
                'static',
                'hostslink',
                get_string('hosts_title', $c),
                \html_writer::link($this->_customdata['hostsurl'], get_string('hosts_edit', $c))
            );
        }

        $mform->addElement('header', 'dataheader', get_string('centre_data', $c));
        $mform->addElement('text', 'controller', get_string('centre_controller', $c), ['size' => 50]);
        $mform->setType('controller', PARAM_TEXT);
        $mform->addHelpButton('controller', 'centre_controller', $c);
        $mform->addElement('text', 'rightscontact', get_string('centre_rightscontact', $c), ['size' => 50]);
        $mform->setType('rightscontact', PARAM_TEXT);
        $mform->addElement('text', 'dpocontact', get_string('centre_dpocontact', $c), ['size' => 50]);
        $mform->setType('dpocontact', PARAM_TEXT);
        $mform->addElement('text', 'retention', get_string('centre_retention', $c), ['size' => 4]);
        $mform->setType('retention', PARAM_INT);
        $mform->setDefault('retention', 24);
        $mform->addHelpButton('retention', 'centre_retention', $c);

        $mform->addElement('header', 'areaheader', get_string('centre_area', $c));
        $mform->addElement('textarea', 'municipalities', get_string('centre_municipalities', $c), ['rows' => 6,
            'cols' => 50]);
        $mform->setType('municipalities', PARAM_TEXT);
        $mform->addHelpButton('municipalities', 'centre_municipalities', $c);

        $mform->addElement('header', 'offerheader', get_string('offers_title', $c));
        $mform->addElement('static', 'offerhelp', '', get_string('centre_offers_help', $c));
        $row = [
            $mform->createElement('hidden', 'offerid'),
            $mform->createElement('text', 'offername', get_string('offer', $c), ['size' => 24]),
            $mform->createElement('text', 'offeryear', get_string('offer_year', $c), ['size' => 8]),
            $mform->createElement('text', 'offerplaces', get_string('offer_places', $c), ['size' => 4]),
            $mform->createElement('advcheckbox', 'offeractive', get_string('offer_active', $c)),
        ];
        $group = [$mform->createElement('group', 'offerrow', get_string('offer', $c), $row, ' ', false)];
        $this->repeat_elements($group, max(3, $offers + 2), [
            'offerid' => ['type' => PARAM_INT],
            'offername' => ['type' => PARAM_TEXT],
            'offeryear' => ['type' => PARAM_TEXT],
            'offerplaces' => ['type' => PARAM_INT],
            'offeractive' => ['default' => 1],
        ], 'offerrows', 'offeradd', 2, get_string('offers_more', $c), true);

        $mform->addElement('header', 'teamheader', get_string('centre_team', $c));
        $mform->addElement('autocomplete', 'members', get_string('centre_members', $c), [], [
            'ajax' => 'core_user/form_user_selector',
            'multiple' => true,
            'valuehtmlcallback' => function ($userid) {
                $user = \core_user::get_user((int) $userid);
                return $user ? fullname($user) . ' (' . s($user->email) . ')' : false;
            },
        ]);
        $mform->addHelpButton('members', 'centre_members', $c);

        $providers = $this->_customdata['providers'] ?? [];
        if ($providers) {
            $mform->addElement('header', 'aiheader', get_string('centre_ai', $c));
            $mform->addElement(
                'select',
                'aiprovider',
                get_string('centre_aiprovider', $c),
                [0 => get_string('centre_ai_none', $c)] + $providers
            );
            $mform->addHelpButton('aiprovider', 'centre_aiprovider', $c);
        }
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * A colour that is a colour, a country of two letters, a school year for each offer with a name.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $c = 'local_oksigeniaeguahe';
        if (trim($data['email'] ?? '') !== '' && !validate_email(trim($data['email']))) {
            $errors['email'] = get_string('invalidemail');
        }
        if (trim($data['colour'] ?? '') !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', trim($data['colour']))) {
            $errors['colour'] = get_string('centre_err_colour', $c);
        }
        if (trim($data['country'] ?? '') !== '' && !preg_match('/^[A-Za-z]{2}$/', trim($data['country']))) {
            $errors['country'] = get_string('centre_err_country', $c);
        }
        foreach ($data['offername'] ?? [] as $i => $name) {
            if (trim((string) $name) !== '' && trim((string) ($data['offeryear'][$i] ?? '')) === '') {
                $errors["offerrow[$i]"] = get_string('centre_err_offeryear', $c);
            }
        }
        return $errors;
    }
}
