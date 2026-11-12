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
    const STRINGS = ['pub_come_meet', 'pub_tagname', 'pub_tagname_hint', 'pub_birthdate', 'pub_birthdate_hint', 'pub_latinname',
        'pub_latinname_hint',
        'pub_if_you_wish', 'pub_add_child', 'pub_offer_unknown',
        'pub_you', 'pub_you_hint', 'pub_another_adult', 'pub_slot', 'pub_incentre', 'pub_incentre_yes', 'pub_incentre_no',
        'pub_more', 'pub_people', 'pub_where', 'pub_where_choose', 'pub_where_other', 'pub_where_moving', 'pub_heardfrom',
        'pub_message', 'pub_message_hint', 'pub_channels', 'pub_channels_hint', 'pub_privacy_link', 'pub_info_more',
        'pub_submit', 'pub_sending', 'pub_no_place', 'pub_when_suits', 'pub_when_suits_hint', 'pub_tailored_title',
        'pub_tailored_lead'];

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
            // Emails and phones are written left to right in any language.
            return ['name' => $name, 'label' => $label, 'value' => $value, 'type' => $type,
                'ltr' => in_array($type, ['email', 'tel'], true), 'autocomplete' => $autocomplete,
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
        // The whole offer of the centre, from the youngest to the oldest: by stage and school year, so the list reads at a
        // glance; each course with the year of birth it is for, to propose it once the date of birth is written.
        [$grouped, $loose] = grades::grouped(array_values(centres::offers((int) $centre->id, true)), $centre);
        $option = fn($o, bool $year) => ['id' => (int) $o->id, 'born' => (int) $o->born,
            'name' => format_string($o->name) . ($year ? ' · ' . s($o->schoolyear) : '')];
        $groups = array_map(fn($g) => ['label' => $g['label'], 'offers' => array_map(
            fn($o) => $option($o, false),
            $g['offers']
        )], $grouped);
        $offers = array_map(fn($o) => $option($o, true), $loose);
        // The children: the first always; the others when something was written in them.
        $children = [];
        $labels = [];
        foreach ($data['children'] as $i => $child) {
            $shown = $i === 0 || $child['firstname'] !== '' || ($child['tagname'] ?? '') !== '' || $child['offerid'] ||
                ($child['birthdate'] ?? '') !== '' || ($child['latinname'] ?? '') !== '';
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
                // The date of birth in three fields, in the order of the country of the centre.
                'birth' => ['form' => 'eg-form', 'child' => ['i' => (string) $i], 'invalid' => isset($errors["c{$i}birth"]),
                    'describedby' => "c{$i}birth-help"] +
                    publicpage::date_input("c{$i}birth", (string) ($child['birthdate'] ?? ''), $lang, $centre),
                // Where names are often written in another script, their name in Latin letters too, for the voice.
                'latin' => $lang === 'ar' ? ['value' => $child['latinname'] ?? ''] : false,
                'birtherror' => $errors["c{$i}birth"] ?? '',
                'tagplaceholder' => preg_split('/\s+/', $child['firstname'])[0] ?? '',
                'courselabel' => $label !== '' ? publiclang::str('pub_course_for', $lang, $label) :
                    publiclang::str('pub_course_for_none', $lang),
                'coursewith' => publiclang::str('pub_course_for', $lang, '{name}'),
                'coursewithout' => publiclang::str('pub_course_for_none', $lang),
                'offererror' => $errors["c{$i}offer"] ?? '',
                'groups' => array_map(fn($g) => ['label' => $g['label'], 'offers' => array_map(
                    fn($o) => $o + ['selected' => (int) $o['id'] === (int) $child['offerid']],
                    $g['offers']
                )], $groups),
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
        $all = empty($event->tailored) ? events::slots((int) $event->id) : [];
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
        $altchalang = ['es' => 'es-es', 'en' => 'en', 'pt' => 'pt-pt', 'pt_br' => 'pt-br', 'ar' => 'ar'][$lang] ?? 'en';
        $altcha = json_encode([
            'auto' => 'onfocus',
            'hideFooter' => true,
            'humanInteractionSignature' => false,
            'language' => $altchalang,
            'type' => 'checkbox',
        ]);
        $days = [];
        foreach (visits::day_names($lang) as $day => $name) {
            $days[] = ['value' => $day, 'name' => $name, 'checked' => in_array($day, $data['days'] ?? [], false)];
        }
        $periods = [];
        foreach (visits::PERIODS as $period) {
            $periods[] = ['value' => $period, 'name' => publiclang::str('pub_period_' . $period, $lang),
                'checked' => in_array($period, $data['periods'] ?? [], true)];
        }
        if (!empty($event->tailored)) {
            $str['pub_submit'] = publiclang::str('pub_submit_request', $lang);
        }
        // A form of the centre: its own questions, and the common ones it asks.
        $isform = $event->kind === 'form';
        $form = !empty($event->formid) ? entries::get((int) $event->formid, (int) $centre->id) : null;
        $blocks = $isform && $form ? forms::blocks($form) : forms::BLOCKS;
        $fields = $form ? forms::context($form, $lang, $centre->lang ?: 'es', $data['answers'] ?? [], $errors) : [];
        if ($isform) {
            $str['pub_submit'] = publiclang::str('pub_submit_form', $lang);
            $str['pub_sending'] = publiclang::str('pub_sending_form', $lang);
            $str['pub_no_place'] = publiclang::str('pub_no_place_form', $lang);
            $str['pub_channels_hint'] = publiclang::str('pub_channels_hint_form', $lang);
        }
        $askincentre = $event->kind === 'other' || ($isform && in_array('incentre', $blocks, true));
        return $str + [
            'isform' => $isform,
            'kicker' => $isform ? '' : format_string($event->name),
            'headline' => $isform ? (trim((string) ($form->title ?? '')) !== '' ? format_string($form->title) :
                publiclang::str('pub_form_title', $lang)) : $str['pub_come_meet'],
            'formlead' => $isform && trim((string) ($form->lead ?? '')) !== '' ? format_string($form->lead) :
                publiclang::str('pub_form_lead', $lang),
            'formside' => publiclang::str('pub_form_side', $lang),
            'fields' => $fields,
            'hasfields' => (bool) $fields,
            'askplace' => in_array('place', $blocks, true),
            'askheard' => in_array('heard', $blocks, true),
            // Which family of the centre told them, if they want to say, to thank them.
            'heardwho' => (string) ($data['heardwho'] ?? ''),
            'heardwhoshown' => ($data['heardfrom'] ?? '') === 'centrefamilies',
            'heardwholabel' => publiclang::str('pub_heardwho', $lang),
            'heardwhohelp' => publiclang::str('pub_heardwho_help', $lang),
            // Which network, when it was one: those of the centre to pick from, or the one they write.
            'heardnet' => (string) ($data['heardnet'] ?? ''),
            'heardnetshown' => ($data['heardfrom'] ?? '') === 'social',
            'heardnetlabel' => publiclang::str('pub_heardnet', $lang),
            'heardnethelp' => publiclang::str('pub_heardnet_help', $lang),
            'networks' => array_map(fn($n) => ['name' => $n], centres::networks($centre)),
            // Brothers and sisters who would come later, where children enter by age and the event is about admissions.
            'asksiblings' => siblings::on($centre) && $event->kind !== 'other',
            'siblingstitle' => publiclang::str('pub_siblings_title', $lang),
            'siblingshelp' => publiclang::str('pub_siblings_help', $lang),
            'siblingname' => publiclang::str('pub_sibling_name', $lang),
            'siblingyear' => publiclang::str('pub_sibling_year', $lang),
            'siblingadd' => publiclang::str('pub_sibling_add', $lang),
            'siblings' => array_map(function ($i) use ($data) {
                $given = $data['siblings'][$i] ?? ['firstname' => '', 'birthyear' => 0];
                $now = (int) date('Y');
                $born = (int) $given['birthyear'];
                $years = array_map(fn($y) => ['value' => $y, 'selected' => $y === $born], range($now, $now - 12));
                // The first row, and those already written; the others wait for «One more».
                return ['index' => $i, 'name' => $given['firstname'], 'hidden' => $i > 0 && $given['firstname'] === '',
                    'years' => $years];
            }, range(0, signup::SIBLINGS - 1)),
            'askmessage' => in_array('message', $blocks, true),
            'askincentre' => $askincentre,
            'askpeople' => !$isform,
            'hasmore' => !$isform || (bool) array_intersect(['place', 'heard', 'message'], $blocks),
            'tailored' => !empty($event->tailored),
            'days' => $days,
            'periods' => $periods,
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
            // Where, on its own line: the place of the act and the address of the centre.
            'where' => implode(', ', array_filter([$event->place ? format_string($event->place) : '',
                !empty($centre->address) ? format_string($centre->address) : ''])),
            'welcome' => !$isform && !empty($centre->welcome) ? format_string($centre->welcome) : '',
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
            'hasoffers' => $groups || $offers,
            'people' => (int) $data['people'] ?: 2,
            'hasslots' => (bool) $slots,
            'slots' => $slots,
            'sloterror' => $errors['slot'] ?? '',
            'isother' => $askincentre,
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
