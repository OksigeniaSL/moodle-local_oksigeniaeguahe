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

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\panel;
use local_oksigeniaeguahe\local\phone;
use local_oksigeniaeguahe\local\writeby;

/**
 * A household noted by the team (a call, someone at the desk or at the door of an open day), or changed.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class household_form extends \moodleform {
    /** @var int Rows of adults. */
    const ADULTS = 2;

    /** @var int Rows of children. */
    const CHILDREN = 3;

    /**
     * The fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'local_oksigeniaeguahe';
        $centre = $this->_customdata['centre'];
        $isnew = empty($this->_customdata['householdid']);
        $mform->addElement('hidden', 'c', $centre->id);
        $mform->setType('c', PARAM_INT);
        $mform->addElement('hidden', 'id', (int) ($this->_customdata['householdid'] ?? 0));
        $mform->setType('id', PARAM_INT);

        if ($isnew) {
            $mform->addElement('header', 'howheader', get_string('form_how', $c));
            $mform->addElement('select', 'channel', get_string('channel', $c), ['' => get_string('choosedots')] + lists::options(
                lists::HAND_CHANNELS,
                'channel_'
            ));
            $mform->addElement('select', 'eventid', get_string('form_event', $c), self::event_options($centre));
            $mform->setDefault('eventid', (int) ($this->_customdata['eventid'] ?? 0));
            foreach (array_keys(self::event_options($centre)) as $eventid) {
                // The turns of each act that has them.
                $slots = $eventid ? self::slot_options((int) $eventid) : [];
                if ($slots) {
                    $mform->addElement('select', 'slot' . $eventid, get_string('form_slot', $c), $slots);
                    $mform->hideIf('slot' . $eventid, 'eventid', 'neq', $eventid);
                }
            }
            $mform->addElement('advcheckbox', 'came', get_string('form_came', $c));
            $mform->hideIf('came', 'eventid', 'eq', 0);
            $mform->addElement('text', 'people', get_string('form_people', $c), ['size' => 3]);
            $mform->setType('people', PARAM_INT);
            $mform->setDefault('people', 1);
            $mform->hideIf('people', 'eventid', 'eq', 0);
        }

        for ($i = 0; $i < self::ADULTS; $i++) {
            $mform->addElement('header', "adultheader{$i}", get_string($i ? 'form_adult2' : 'form_adult1', $c));
            if ($i) {
                $mform->setExpanded("adultheader{$i}", false);
            }
            $mform->addElement('hidden', "adultid{$i}", 0);
            $mform->setType("adultid{$i}", PARAM_INT);
            $mform->addElement('text', "firstname{$i}", get_string('firstname'), ['size' => 30]);
            $mform->setType("firstname{$i}", PARAM_TEXT);
            $mform->addElement('text', "lastname{$i}", get_string('lastname'), ['size' => 30]);
            $mform->setType("lastname{$i}", PARAM_TEXT);
            $mform->addElement('text', "phone{$i}", get_string('phone', $c), ['size' => 20, 'inputmode' => 'tel']);
            $mform->setType("phone{$i}", PARAM_RAW_TRIMMED);
            $mform->addElement('text', "email{$i}", get_string('email'), ['size' => 30]);
            $mform->setType("email{$i}", PARAM_RAW_TRIMMED);
        }
        $mform->addRule('firstname0', null, 'required', null, 'client');

        $mform->addElement('header', 'childrenheader', get_string('children', $c));
        $offers = self::offer_options($centre);
        for ($i = 0; $i < self::CHILDREN; $i++) {
            $mform->addElement('hidden', "childid{$i}", 0);
            $mform->setType("childid{$i}", PARAM_INT);
            $row = [
                $mform->createElement('text', "childname{$i}", get_string('form_childname', $c), ['size' => 20]),
                $mform->createElement(
                    'text',
                    "childtag{$i}",
                    get_string('form_tagname', $c),
                    ['size' => 12, 'placeholder' => get_string('form_tagname', $c)]
                ),
                $mform->createElement('select', "offerid{$i}", get_string('offer', $c), $offers),
            ];
            $mform->addGroup($row, "child{$i}", get_string('form_child', $c, $i + 1), ' ', false);
            $mform->setType("childname{$i}", PARAM_TEXT);
            $mform->setType("childtag{$i}", PARAM_TEXT);
            // Their date of birth (YYYY-MM-DD), if the family gave it: for their birthday.
            $mform->addElement('text', "childbirth{$i}", get_string('form_birthdate', $c), ['size' => 10]);
            $mform->setType("childbirth{$i}", PARAM_RAW_TRIMMED);
        }

        if (!$isnew) {
            // How it came in, to put it right.
            $mform->addElement('select', 'firstchannel', get_string('channel', $c), lists::options(
                self::first_channels((string) ($this->_customdata['firstchannel'] ?? '')),
                'channel_'
            ));
        }
        $mform->addElement('header', 'aboutheader', get_string('form_about', $c));
        $mform->addElement('select', 'municipality', get_string('municipality', $c), self::place_options($centre));
        $mform->addElement('text', 'municipalityother', get_string('form_place_other', $c), ['size' => 30]);
        $mform->setType('municipalityother', PARAM_TEXT);
        $mform->hideIf('municipalityother', 'municipality', 'neq', 'other');
        $mform->addElement(
            'select',
            'heardfrom',
            get_string('heardfrom', $c),
            ['' => get_string('form_heard_unknown', $c)] + lists::options(lists::HEARD_FROM, 'heard_')
        );
        $mform->addElement('text', 'heardnet', get_string('heardnet', $c), ['size' => 30, 'maxlength' => 60]);
        $mform->setType('heardnet', PARAM_TEXT);
        $mform->hideIf('heardnet', 'heardfrom', 'neq', 'social');
        $mform->addElement('advcheckbox', 'incentre', get_string('incentre', $c));
        $mform->addHelpButton('incentre', 'incentre', $c);
        $mform->addElement('select', 'lang', get_string('form_lang', $c), lists::LANG_NAMES);
        $mform->setDefault('lang', $centre->lang);

        if ($isnew) {
            $mform->addElement('textarea', 'body', get_string('form_said', $c), ['rows' => 3, 'cols' => 50]);
            $mform->setType('body', PARAM_TEXT);
            $mform->addElement('advcheckbox', 'awaits', get_string('form_awaits', $c));
            $mform->setDefault('awaits', 1);
            $mform->addElement('header', 'consentheader', get_string('permissions', $c));
            $mform->addElement('static', 'consenthelp', '', get_string('form_consent_help', $c));
            foreach (lists::CONSENT_CHANNELS as $channel) {
                $mform->addElement('advcheckbox', 'consent' . $channel, get_string('consent_' . $channel, $c));
            }
            // Telegram, where the site has it: they are written to there once they join it from their page.
            if (in_array('telegram', writeby::channels($centre), true)) {
                $mform->addElement('advcheckbox', 'consenttelegram', get_string('consent_telegram', $c));
            }
        }
        $this->add_action_buttons(true, get_string($isnew ? 'household_save_new' : 'savechanges', $isnew ? $c : 'moodle'));
    }

    /**
     * The events a family noted by the team can come to: those not over, open to all.
     *
     * @param \stdClass $centre
     * @return array id => name and day; 0, none.
     */
    public static function event_options(\stdClass $centre): array {
        $options = [0 => get_string('form_noevent', 'local_oksigeniaeguahe')];
        foreach (events::for_centre((int) $centre->id) as $event) {
            if ($event->timeend >= time() - DAYSECS && empty($event->householdid) && empty($event->tailored) && empty($event->cancelled)) {
                $options[$event->id] = format_string($event->name) . ' · ' .
                    userdate((int) $event->timestart, get_string('strftimedatetimeshort', 'langconfig'));
            }
        }
        return $options;
    }

    /**
     * The ways a family can have come in, to put it right: those a person notes (the ads, by the network they bring), and
     * the one it has.
     *
     * @param string $has
     * @return string[]
     */
    public static function first_channels(string $has): array {
        $list = array_diff(lists::HAND_CHANNELS, array_keys(lists::HAND_ADS));
        return array_values(array_unique(array_merge($list, in_array($has, lists::CHANNELS, true) ? [$has] : [])));
    }

    /**
     * The turns of an act, with how many places are left in each.
     *
     * @param int $eventid
     * @return array id => the hour and what is left; 0, none chosen; empty when it has no turns.
     */
    public static function slot_options(int $eventid): array {
        $slots = events::slots($eventid);
        if (!$slots) {
            return [];
        }
        $out = [0 => get_string('form_noslot', 'local_oksigeniaeguahe')];
        foreach ($slots as $slot) {
            $out[$slot->id] = panel::hour((int) $slot->timestart) . '–' . panel::hour((int) $slot->timeend) . ((int) $slot->capacity ?
                ' · ' . panel::free_of(max(0, (int) $slot->capacity - (int) $slot->people), (int) $slot->capacity) : '');
        }
        return $out;
    }

    /**
     * The courses of the offer a child can ask for.
     *
     * @param \stdClass $centre
     * @return array id => course and school year; 0, not known yet.
     */
    public static function offer_options(\stdClass $centre): array {
        $options = [0 => get_string('offer_unknown', 'local_oksigeniaeguahe')];
        foreach (centres::offers((int) $centre->id) as $offer) {
            $options[$offer->id] = centres::offer_label($offer);
        }
        return $options;
    }

    /**
     * Where a family can live: the municipalities of the centre, or another one written.
     *
     * @param \stdClass $centre
     * @return array code => name
     */
    public static function place_options(\stdClass $centre): array {
        $c = 'local_oksigeniaeguahe';
        return ['' => get_string('form_place_unknown', $c)] + centres::municipalities($centre) +
            ['other' => get_string('form_place_other', $c)];
    }

    /**
     * What was sent, or what the form was given to start with, for a page that draws the form itself.
     *
     * @return array
     */
    public function values(): array {
        return (array) $this->_form->exportValues();
    }

    /**
     * What is wrong in what was sent, by field.
     *
     * @return array
     */
    public function errors(): array {
        return (array) $this->_form->_errors;
    }

    /**
     * The name Moodle gives the form, which a page that draws it itself sends back.
     *
     * @return string
     */
    public function identifier(): string {
        return $this->get_form_identifier();
    }

    /**
     * Checks the numbers and addresses, and that there is a way to reach the family.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $c = 'local_oksigeniaeguahe';
        $centre = $this->_customdata['centre'];
        for ($i = 0; $i < self::ADULTS; $i++) {
            if (trim($data["email{$i}"] ?? '') !== '' && households::email($data["email{$i}"]) === null) {
                $errors["email{$i}"] = get_string('invalidemail');
            }
            if (trim($data["phone{$i}"] ?? '') !== '' && phone::normalise($data["phone{$i}"], $centre->country) === null) {
                $errors["phone{$i}"] = get_string('form_err_phone', $c);
            }
        }
        for ($i = 0; $i < self::CHILDREN; $i++) {
            $birth = trim($data["childbirth{$i}"] ?? '');
            if ($birth !== '' && \local_oksigeniaeguahe\local\birthdays::clean($birth, time()) === null) {
                $errors["childbirth{$i}"] = get_string('form_err_birthdate', $c);
            }
        }
        if (empty($this->_customdata['householdid']) && !in_array($data['channel'] ?? '', lists::HAND_CHANNELS, true)) {
            // Otherwise it stays «Other» for good, and the numbers with it.
            $errors['channel'] = get_string('form_err_channel', $c);
        }
        $door = !empty($this->_customdata['door']);
        if (!$door && trim($data['email0'] ?? '') === '' && trim($data['phone0'] ?? '') === '') {
            $errors['phone0'] = get_string('form_err_reach', $c);
        }
        return $errors;
    }
}
