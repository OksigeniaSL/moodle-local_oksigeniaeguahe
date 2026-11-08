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

use local_oksigeniaeguahe\form\household_form;

/**
 * Writing down a family in the panel (a call, the desk, the door of an open day), or changing it: the fields of the
 * form of Moodle that checks and saves it, with what was written and what is wrong, for a page that draws them itself.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_familyform {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The context of the page.
     *
     * @param household_form $form
     * @param \stdClass $centre
     * @param \stdClass|null $household Null for a new family.
     * @param \moodle_url $action
     * @param \moodle_url $back
     * @return array
     */
    public static function context(
        household_form $form,
        \stdClass $centre,
        ?\stdClass $household,
        \moodle_url $action,
        \moodle_url $back
    ): array {
        $c = self::C;
        $values = $form->values();
        $errors = $form->errors();
        $value = fn(string $name) => (string) ($values[$name] ?? '');
        $isnew = $household === null;
        $options = function (array $list, string $chosen): array {
            $out = [];
            foreach ($list as $key => $name) {
                $out[] = ['value' => $key, 'name' => $name, 'selected' => (string) $key === $chosen];
            }
            return $out;
        };
        $field = fn(string $name) => ['name' => $name, 'value' => $value($name), 'error' => $errors[$name] ?? ''];

        $children = [];
        $offers = household_form::offer_options($centre);
        for ($i = 0; $i < household_form::CHILDREN; $i++) {
            $name = $field("childname{$i}");
            $children[] = [
                'i' => $i,
                'number' => $i + 1,
                'id' => (int) $value("childid{$i}"),
                'name' => $name,
                'tag' => $field("childtag{$i}"),
                'offers' => $options($offers, $value("offerid{$i}")),
                'offername' => "offerid{$i}",
                'open' => $i === 0 || $name['value'] !== '',
            ];
        }
        $adults = [];
        for ($i = 0; $i < household_form::ADULTS; $i++) {
            $adult = [
                'i' => $i,
                'id' => (int) $value("adultid{$i}"),
                'firstname' => $field("firstname{$i}"),
                'lastname' => $field("lastname{$i}"),
                'phone' => $field("phone{$i}"),
                'email' => $field("email{$i}"),
                'required' => $i === 0,
            ];
            $adult['open'] = $i === 0 || $adult['firstname']['value'] !== '' || $adult['email']['value'] !== '' ||
                $adult['phone']['value'] !== '' || $adult['phone']['error'] !== '' || $adult['email']['error'] !== '';
            $adults[] = $adult;
        }
        $heard = ['' => get_string('form_heard_unknown', $c)] + lists::options(lists::HEARD_FROM, 'heard_');
        $consents = [];
        foreach (lists::CONSENT_CHANNELS as $channel) {
            $consents[] = ['name' => 'consent' . $channel, 'label' => get_string('consent_' . $channel, $c),
                'checked' => !empty($values['consent' . $channel])];
        }
        return [
            'head' => [
                'back' => ['url' => $back->out(false), 'name' => $isnew ? get_string('tab_inbox', $c) :
                    panel::adults_line(households::full($household)->adults)],
                'title' => get_string($isnew ? 'household_new' : 'household_edit', $c),
                'sub' => $isnew ? get_string('panel_ff_sub', $c) : '',
            ],
            'action' => $action->out(false),
            'sesskey' => sesskey(),
            'marker' => '_qf__' . $form->identifier(),
            'centreid' => (int) $centre->id,
            'id' => $household ? (int) $household->id : 0,
            'isnew' => $isnew,
            'haserrors' => (bool) $errors,
            'children' => $children,
            'adults' => $adults,
            'channels' => $options(lists::options(lists::HAND_CHANNELS, 'channel_'), $value('channel')),
            'events' => $options(household_form::event_options($centre), $value('eventid') ?: '0'),
            'hasevents' => count(household_form::event_options($centre)) > 1,
            'came' => !empty($values['came']),
            'people' => $value('people') !== '' ? $value('people') : '1',
            'body' => $value('body'),
            'awaits' => !empty($values['awaits']),
            'places' => $options(household_form::place_options($centre), $value('municipality')),
            'placeother' => $field('municipalityother'),
            'heard' => $options($heard, $value('heardfrom')),
            'incentre' => !empty($values['incentre']),
            'langs' => $options(lists::LANG_NAMES, $value('lang') ?: (string) $centre->lang),
            'consents' => $consents,
            'backurl' => $back->out(false),
        ];
    }
}
