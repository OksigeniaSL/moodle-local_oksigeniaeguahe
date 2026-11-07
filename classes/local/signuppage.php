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
 * What the page of signing up for an event shows: the event, the form with what the family already wrote, its errors
 * by field, and the information about the data in its first layer.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signuppage {
    /** @var string[] Strings the template uses. */
    const STRINGS = ['pub_intro', 'pub_who', 'pub_firstname', 'pub_lastname', 'pub_phone', 'pub_email', 'pub_reach_hint',
        'pub_another_adult', 'pub_students', 'pub_student_name', 'pub_student_offer', 'pub_offer_unknown',
        'pub_another_student', 'pub_people', 'pub_where', 'pub_where_other', 'pub_where_choose', 'pub_heardfrom',
        'pub_heardfrom_choose', 'pub_message', 'pub_message_hint', 'pub_channels', 'pub_channels_hint', 'pub_submit',
        'pub_errors', 'pub_required', 'pub_optional', 'pub_info_more', 'pub_slot', 'pub_incentre', 'pub_incentre_yes',
        'pub_incentre_no', 'pub_tagname', 'pub_tagname_hint'];

    /**
     * The context of the template public_signup.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @param array $data What the family sent, if it sent something.
     * @param array $errors Field => message.
     * @param \moodle_url $url
     * @return array
     */
    public static function context(
        \stdClass $event,
        \stdClass $centre,
        string $lang,
        array $data,
        array $errors,
        \moodle_url $url
    ): array {
        global $CFG;
        $str = publicpage::strings($lang, self::STRINGS);
        $field = function (
            string $name,
            string $label,
            string $value,
            string $type = 'text',
            string $autocomplete = '',
            bool $required = false
        ) use ($errors): array {
            return ['name' => $name, 'label' => $label, 'value' => $value, 'type' => $type,
                'autocomplete' => $autocomplete, 'required' => $required, 'error' => $errors[$name] ?? '',
                'invalid' => isset($errors[$name])];
        };
        $adults = [];
        foreach ($data['adults'] as $i => $adult) {
            $adults[] = [
                'first' => $i === 0,
                'fields' => [
                    $field("a{$i}first", $str['pub_firstname'], $adult['firstname'], 'text', 'given-name', $i === 0),
                    $field("a{$i}last", $str['pub_lastname'], $adult['lastname'], 'text', 'family-name'),
                    $field("a{$i}phone", $str['pub_phone'], $adult['phone'], 'tel', 'tel'),
                    $field("a{$i}email", $str['pub_email'], $adult['email'], 'email', 'email'),
                ],
            ];
        }
        $offers = [];
        foreach (centres::offers((int) $centre->id, true) as $offer) {
            $offers[] = ['id' => $offer->id, 'name' => format_string($offer->name) . ' (' . s($offer->schoolyear) . ')'];
        }
        $children = [];
        foreach ($data['children'] as $i => $child) {
            $children[] = [
                'first' => $i === 0,
                'name' => $field("c{$i}name", $str['pub_student_name'], $child['firstname'], 'text', 'off', $i === 0),
                'tag' => $field("c{$i}tag", $str['pub_tagname'], $child['tagname'] ?? '', 'text', 'off'),
                'offer' => "c{$i}offer",
                'offererror' => $errors["c{$i}offer"] ?? '',
                'offers' => array_map(fn($o) => $o + ['selected' => (int) $o['id'] === (int) $child['offerid']], $offers),
            ];
        }
        // The rows that are not the first stay folded, unless something was written in them.
        $moreadults = false;
        foreach (array_slice($data['adults'], 1, null, true) as $i => $adult) {
            $moreadults = $moreadults || implode('', $adult) !== '' || isset($errors["a{$i}email"]) ||
                isset($errors["a{$i}phone"]);
        }
        $morechildren = false;
        foreach (array_slice($data['children'], 1) as $child) {
            $morechildren = $morechildren || $child['firstname'] !== '' || $child['offerid'];
        }
        $places = [];
        foreach (centres::municipalities($centre) as $code => $name) {
            $places[] = ['value' => $code, 'name' => $name, 'selected' => $code === $data['municipality']];
        }
        $heard = [];
        foreach (lists::HEARD_FROM as $value) {
            $heard[] = ['value' => $value, 'name' => publiclang::str('heard_' . $value, $lang),
                'selected' => $value === $data['heardfrom']];
        }
        $channels = [];
        foreach (lists::CONSENT_CHANNELS as $channel) {
            $channels[] = ['name' => 'consent' . $channel, 'label' => publiclang::str('pub_consent_' . $channel, $lang),
                'checked' => !empty($data['consents'][$channel])];
        }
        $slots = [];
        $all = events::slots((int) $event->id);
        // Turns of one day show their hours; turns of several days, their dates too.
        $days = array_unique(array_map(fn($t) => userdate((int) $t->timestart, '%Y%m%d'), $all));
        foreach ($all as $slot) {
            $left = $slot->capacity ? max(0, (int) $slot->capacity - (int) $slot->people) : null;
            $past = $slot->timeend < time();
            $slots[] = [
                'id' => $slot->id,
                'when' => count($days) > 1 ? mail::when($slot, $lang) : mail::hours($slot, $lang),
                'left' => $left === null ? '' : publiclang::str($left ? 'pub_slot_left' : 'pub_slot_full', $lang, $left),
                'disabled' => $past || $left === 0,
                'checked' => (int) $slot->id === (int) ($data['slot'] ?? 0),
            ];
        }
        $errorlist = [];
        foreach ($errors as $name => $message) {
            $errorlist[] = ['field' => $name, 'message' => $message];
        }
        $altchalang = ['es' => 'es-es', 'en' => 'en', 'pt' => 'pt-pt', 'pt_br' => 'pt-br'][$lang] ?? 'en';
        $altcha = json_encode([
            'auto' => 'onfocus',
            'hideFooter' => true,
            'humanInteractionSignature' => false,
            'language' => $altchalang,
            'type' => 'checkbox',
        ]);
        return $str + [
            'event' => format_string($event->name),
            'when' => mail::when($event, $lang),
            'place' => $event->place ? format_string($event->place) : '',
            'action' => (new \moodle_url($url, ['lang' => $lang]))->out(false),
            'sesskey' => sesskey(),
            'adults' => $adults,
            'moreadultsopen' => $moreadults,
            'children' => $children,
            'morechildrenopen' => $morechildren,
            'hasoffers' => (bool) $offers,
            'people' => (int) $data['people'] ?: 1,
            'hasslots' => (bool) $slots,
            'slots' => $slots,
            'sloterror' => $errors['slot'] ?? '',
            'isother' => $event->kind === 'other',
            'incentreyes' => ($data['incentre'] ?? '') === 'yes',
            'incentreno' => ($data['incentre'] ?? '') === 'no',
            'peopleerror' => $errors['people'] ?? '',
            'hasplaces' => (bool) $places,
            'places' => $places,
            'municipalityother' => $data['municipalityother'],
            'heard' => $heard,
            'message' => $data['message'],
            'channels' => $channels,
            'info' => self::first_layer($centre, $lang),
            'privacyurl' => (new \moodle_url('/local/oksigeniaeguahe/privacy.php', ['c' => $centre->id,
                'lang' => $lang]))->out(false),
            'haserrors' => (bool) $errors,
            'errorlist' => $errorlist,
            'formerror' => $errors['form'] ?? '',
            'altchaerror' => $errors['altcha'] ?? '',
            'altchaurl' => $CFG->wwwroot . '/local/oksigeniaeguahe/altcha.php',
            'altchaconfig' => $altcha,
            'altchalang' => $altchalang,
            'altchai18n' => $altchalang === 'en' ? '' : $altchalang,
        ];
    }

    /**
     * The first layer of information about the data: who, what for, on what grounds, to whom, and the rights.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return array Lines with a label and a text.
     */
    public static function first_layer(\stdClass $centre, string $lang): array {
        $a = (object) [
            'centre' => format_string($centre->controller ?: $centre->name),
            'contact' => s($centre->rightscontact ?: ''),
            'months' => (int) $centre->retention,
        ];
        $lines = [];
        foreach (['who', 'why', 'basis', 'whom', 'keep', 'rights'] as $part) {
            $text = publiclang::str('info_' . $part, $lang, $a);
            if ($part === 'rights' && $a->contact !== '') {
                $text .= ' ' . publiclang::str('info_rights_contact', $lang, $a);
            }
            $lines[] = ['label' => publiclang::str('info_' . $part . '_label', $lang), 'text' => $text];
        }
        return $lines;
    }
}
