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
                'birth' => $field("childbirth{$i}") + ['max' => date('Y-m-d')],
                'offers' => $options($offers, $value("offerid{$i}")),
                'offername' => "offerid{$i}",
                'emptybag' => panel::hanging($i, '', '', $centre),
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
            'head' => panel::head([
                'back' => ['url' => $back->out(false), 'name' => $isnew ? get_string('tab_inbox', $c) :
                    panel::adults_line(households::full($household)->adults)],
                'title' => get_string($isnew ? 'household_new' : 'household_edit', $c),
                'sub' => $isnew ? get_string('panel_ff_sub', $c) : '',
            ]),
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
            'heardnet' => $field('heardnet'),
            'heardnetshown' => $value('heardfrom') === 'social',
            'networks' => array_map(fn($n) => ['name' => $n], centres::networks($centre)),
            'incentre' => !empty($values['incentre']),
            'langs' => $options(lists::LANG_NAMES, $value('lang') ?: (string) $centre->lang),
            'consents' => $consents,
            'backurl' => $back->out(false),
            'dupurl' => $isnew ? (new \moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id,
                'dup' => 1]))->out(false) :
                '',
            'offersinfo' => self::offers_info($centre),
        ];
    }

    /**
     * For each course of the offer, how many places are free, the next open day and the rail, small.
     *
     * @param \stdClass $centre
     * @return array
     */
    public static function offers_info(\stdClass $centre): array {
        $c = self::C;
        $next = null;
        foreach (events::for_centre((int) $centre->id) as $event) {
            if (
                empty($event->tailored) && empty($event->householdid) && $event->timestart > time() &&
                    (!$next || $event->timestart < $next->timestart)
            ) {
                $next = $event;
            }
        }
        $counts = households::offer_counts((int) $centre->id);
        $out = [];
        foreach (centres::offers((int) $centre->id, true) as $offer) {
            $enrolled = (int) ($counts[$offer->id]['enrolled'] ?? 0);
            $total = (int) $offer->places;
            $out[] = [
                'value' => (int) $offer->id,
                'free' => $total ? get_string('panel_free_of', $c, (object) ['free' => max(0, $total - $enrolled),
                    'total' => $total]) : '',
                'next' => $next ? get_string('panel_next_openday', $c, userdate((int) $next->timestart, '%A %d')) : '',
                'rail' => $total ? panel::rail(
                    $total,
                    $enrolled,
                    object: panel::hanging_object((string) $offer->name, $centre)
                ) : '',
            ];
        }
        return $out;
    }

    /**
     * The families already written down with a phone or an email, for the warning while writing one down.
     *
     * @param \stdClass $centre
     * @param string $phone
     * @param string $email
     * @return array households: sentence, url, open.
     */
    public static function duplicates(\stdClass $centre, string $phone, string $email): array {
        $c = self::C;
        $phone = phone::normalise($phone, $centre->country) ?? '';
        $email = households::email($email) ?? '';
        $id = ($phone !== '' || $email !== '') ? households::find((int) $centre->id, $email ?: null, $phone ?: null) : null;
        $household = $id ? households::get((int) $id, (int) $centre->id) : null;
        if (!$household) {
            return ['households' => []];
        }
        $full = households::full($household);
        $tags = array_map(fn($child) => panel::tag($child), array_values($full->children));
        $last = array_pop($tags);
        $children = $tags ? get_string('andlist', $c, (object) ['list' => implode(', ', $tags), 'last' => $last]) : (string) $last;
        return ['households' => [[
            'sentence' => get_string($children !== '' ? 'panel_dup_with' : 'panel_dup', $c, (object) [
                'adults' => panel::adults_line($full->adults), 'children' => $children,
                'since' => userdate((int) $household->timecreated, get_string('strftimedateshort', 'langconfig'))]),
            'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'open' => get_string('panel_dup_open', $c),
        ]]];
    }
}
