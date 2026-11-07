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
    const STRINGS = ['pub_come_meet', 'pub_tagname', 'pub_tagname_hint', 'pub_if_you_wish', 'pub_add_child', 'pub_offer_unknown',
        'pub_you', 'pub_you_hint', 'pub_another_adult', 'pub_slot', 'pub_incentre', 'pub_incentre_yes', 'pub_incentre_no',
        'pub_more', 'pub_people', 'pub_where', 'pub_where_choose', 'pub_where_other', 'pub_where_moving', 'pub_heardfrom',
        'pub_message', 'pub_message_hint', 'pub_channels', 'pub_channels_hint', 'pub_privacy_link', 'pub_info_more',
        'pub_submit', 'pub_sending', 'pub_no_place'];

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
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $field = function (
            string $name,
            string $label,
            string $value,
            string $type = 'text',
            string $autocomplete = '',
            string $inputmode = ''
        ) use ($errors): array {
            return ['name' => $name, 'label' => $label, 'value' => $value, 'type' => $type, 'autocomplete' => $autocomplete,
                'inputmode' => $inputmode, 'error' => $errors[$name] ?? '', 'invalid' => isset($errors[$name])];
        };
        // The adults: the first always; the second when something was written in it.
        $adults = [];
        foreach ($data['adults'] as $i => $adult) {
            $shown = $i === 0 || implode('', $adult) !== '' || isset($errors["a{$i}email"]) || isset($errors["a{$i}phone"]);
            $adults[] = ['index' => $i, 'hidden' => !$shown, 'fields' => [
                $field("a{$i}first", publiclang::str('pub_firstname', $lang), $adult['firstname'], 'text', 'given-name'),
                $field("a{$i}last", publiclang::str('pub_lastname', $lang), $adult['lastname'], 'text', 'family-name'),
                $field("a{$i}phone", publiclang::str('pub_phone', $lang), $adult['phone'], 'tel', 'tel', 'tel'),
                $field("a{$i}email", publiclang::str('pub_email', $lang), $adult['email'], 'email', 'email'),
            ]];
        }
        $offers = [];
        foreach (centres::offers((int) $centre->id, true) as $offer) {
            $offers[] = ['id' => $offer->id, 'name' => format_string($offer->name) . ' · ' . s($offer->schoolyear)];
        }
        // The children: the first always; the others when something was written in them.
        $children = [];
        $labels = [];
        foreach ($data['children'] as $i => $child) {
            $shown = $i === 0 || $child['firstname'] !== '' || ($child['tagname'] ?? '') !== '' || $child['offerid'];
            $label = familypage::label_name((object) ['firstname' => $child['firstname'],
                'tagname' => $child['tagname'] ?? '']);
            $labels[] = $label;
            $children[] = [
                'index' => $i,
                'hidden' => !$shown,
                'namelabel' => publiclang::str($i ? 'pub_child_name' : 'pub_ask_name', $lang),
                'name' => $child['firstname'],
                'nameerror' => $errors["c{$i}name"] ?? '',
                'tag' => $child['tagname'] ?? '',
                'tagplaceholder' => preg_split('/\s+/', $child['firstname'])[0] ?? '',
                'courselabel' => $label !== '' ? publiclang::str('pub_course_for', $lang, $label) :
                    publiclang::str('pub_course_for_none', $lang),
                'coursewith' => publiclang::str('pub_course_for', $lang, '{name}'),
                'coursewithout' => publiclang::str('pub_course_for_none', $lang),
                'offererror' => $errors["c{$i}offer"] ?? '',
                'offers' => array_map(fn($o) => $o + ['selected' => (int) $o['id'] === (int) $child['offerid']], $offers),
            ];
        }
        $shownchildren = count(array_filter($children, fn($c) => !$c['hidden']));
        $places = [];
        foreach (centres::municipalities($centre) as $code => $name) {
            $places[] = ['value' => $code, 'name' => $name, 'selected' => (string) $code === $data['municipality']];
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
        // Turns of one day show their hours; turns of several days, their dates too. Past turns are not shown.
        $days = array_unique(array_map(fn($t) => userdate((int) $t->timestart, '%Y%m%d'), $all));
        foreach ($all as $slot) {
            if ($slot->timeend < time()) {
                continue;
            }
            $left = $slot->capacity ? max(0, (int) $slot->capacity - (int) $slot->people) : null;
            $slots[] = [
                'id' => $slot->id,
                'when' => count($days) > 1 ? mail::when($slot, $lang) : mail::hours($slot, $lang),
                'left' => $left === null ? '' : ($left === 0 ? publiclang::str('pub_slot_full', $lang) :
                    publiclang::str($left === 1 ? 'pub_slot_left_one' : 'pub_slot_left', $lang, $left)),
                'disabled' => $left === 0,
                'checked' => (int) $slot->id === (int) ($data['slot'] ?? 0),
            ];
        }
        $errorlist = [];
        foreach ($errors as $name => $message) {
            $errorlist[] = ['field' => $name === 'channels' ? 'channels' : ($name === 'altcha' ? 'altcha' : $name),
                'message' => $message];
        }
        $hosts = centres::hosts((int) $centre->id, array_filter(array_map('intval', explode(',', (string) $event->hosts))));
        $firsts = array_map(fn($h) => $h->first, $hosts);
        $parts = publicpage::date_parts((int) $event->timestart, $lang);
        $municipality = (string) $data['municipality'];
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
            'pegs' => publicpage::pegs($labels, false, $shownchildren) + ['live' => true],
            'children' => $children,
            'canaddchild' => $shownchildren < count($children),
            'singlechild' => $shownchildren === 1,
            'courselegend' => $children[0]['courselabel'],
            'courselegendmany' => publiclang::str('pub_who_comes', $lang),
            'weekday' => $ucfirst($parts['weekday']),
            'daymonth' => $parts['daymonth'],
            'hours' => familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
            'place' => $event->place ? format_string($event->place) : '',
            'address' => !empty($centre->address) ? format_string($centre->address) : '',
            'welcome' => !empty($centre->welcome) ? format_string($centre->welcome) : '',
            'hashosts' => (bool) $hosts,
            'hosts' => familypage::hosts($hosts),
            'willreceive' => $hosts ? publiclang::str(
                count($hosts) > 1 ? 'pub_will_receive_many' : 'pub_will_receive_one',
                $lang,
                familypage::join($firsts, $lang)
            ) : '',
            'action' => (new \moodle_url($url, ['lang' => $lang]))->out(false),
            'sesskey' => sesskey(),
            'adults' => $adults,
            'canaddadult' => (bool) array_filter($adults, fn($a) => $a['hidden']),
            'hasoffers' => (bool) $offers,
            'people' => (int) $data['people'] ?: 2,
            'hasslots' => (bool) $slots,
            'slots' => $slots,
            'sloterror' => $errors['slot'] ?? '',
            'isother' => $event->kind === 'other',
            'incentreyes' => ($data['incentre'] ?? '') === 'yes',
            'incentreno' => ($data['incentre'] ?? '') === 'no',
            'peopleerror' => $errors['people'] ?? '',
            'places' => $places,
            'otherselected' => $municipality === 'other',
            'movingselected' => $municipality === 'moving',
            'lejoshidden' => !in_array($municipality, ['other', 'moving'], true) && $data['municipalityother'] === '',
            'lejoslabel' => publiclang::str(
                $municipality === 'moving' ? 'pub_where_moving_label' : 'pub_where_other_label',
                $lang
            ),
            'lejosother' => publiclang::str('pub_where_other_label', $lang),
            'lejosmoving' => publiclang::str('pub_where_moving_label', $lang),
            'municipalityother' => $data['municipalityother'],
            'heard' => $heard,
            'message' => $data['message'],
            'channels' => $channels,
            'channelerror' => $errors['channels'] ?? '',
            'info' => self::first_layer($centre, $lang),
            'privacyurl' => (new \moodle_url('/local/oksigeniaeguahe/privacy.php', ['c' => $centre->id,
                'lang' => $lang]))->out(false),
            'haserrors' => (bool) $errors,
            'errortitle' => count($errors) === 1 ? publiclang::str('pub_errors_one', $lang) :
                publiclang::str('pub_errors_many', $lang, count($errors)),
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
        // How long they are kept goes in the second layer, with the rest: the first stays short on a phone.
        foreach (['who', 'why', 'basis', 'whom', 'rights'] as $part) {
            $text = publiclang::str('info_' . $part, $lang, $a);
            if ($part === 'rights' && $a->contact !== '') {
                $text .= ' ' . publiclang::str('info_rights_contact', $lang, $a);
            }
            $lines[] = ['label' => publiclang::str('info_' . $part . '_label', $lang), 'text' => $text];
        }
        return $lines;
    }
}
