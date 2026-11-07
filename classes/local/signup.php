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
 * A family signs up for an open day or a visit from its phone (the QR of the poster): who they are, who would study
 * and in which course, how many come, where they live and how they heard of the centre, and on which channels the
 * centre may write to them.
 *
 * The same family found again by its email or phone is not created twice: what is new is added to its household.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signup {
    /** @var int Rows for those who would study. */
    const CHILDREN = 4;

    /** @var int Rows for adults. */
    const ADULTS = 2;

    /**
     * What the form sent, cleaned, without checking it yet.
     *
     * @return array
     */
    public static function read(): array {
        $adults = [];
        for ($i = 0; $i < self::ADULTS; $i++) {
            $adults[] = [
                'firstname' => trim(optional_param("a{$i}first", '', PARAM_TEXT)),
                'lastname' => trim(optional_param("a{$i}last", '', PARAM_TEXT)),
                'email' => trim(optional_param("a{$i}email", '', PARAM_RAW_TRIMMED)),
                'phone' => trim(optional_param("a{$i}phone", '', PARAM_RAW_TRIMMED)),
            ];
        }
        $children = [];
        for ($i = 0; $i < self::CHILDREN; $i++) {
            $children[] = [
                'firstname' => trim(optional_param("c{$i}name", '', PARAM_TEXT)),
                'offerid' => optional_param("c{$i}offer", 0, PARAM_INT),
            ];
        }
        $consents = [];
        foreach (lists::CONSENT_CHANNELS as $channel) {
            $consents[$channel] = optional_param('consent' . $channel, 0, PARAM_BOOL) ? 1 : 0;
        }
        return [
            'adults' => $adults,
            'children' => $children,
            'people' => optional_param('people', 1, PARAM_INT),
            'slot' => optional_param('slot', 0, PARAM_INT),
            'incentre' => optional_param('incentre', '', PARAM_ALPHA),
            'municipality' => optional_param('municipality', '', PARAM_RAW_TRIMMED),
            'municipalityother' => trim(optional_param('municipalityother', '', PARAM_TEXT)),
            'heardfrom' => optional_param('heardfrom', '', PARAM_ALPHA),
            'message' => trim(optional_param('message', '', PARAM_TEXT)),
            'consents' => $consents,
            'altcha' => optional_param('altcha', '', PARAM_RAW_TRIMMED),
        ];
    }

    /**
     * What is wrong in what the family sent, by field.
     *
     * @param array $data From read().
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @return array Field => message, in the language of the family; empty when all is well.
     */
    public static function check(array $data, \stdClass $event, \stdClass $centre, string $lang): array {
        $errors = [];
        $first = $data['adults'][0];
        if ($first['firstname'] === '') {
            $errors['a0first'] = publiclang::str('pub_err_name', $lang);
        }
        foreach ($data['adults'] as $i => $adult) {
            if ($adult['email'] !== '' && households::email($adult['email']) === null) {
                $errors["a{$i}email"] = publiclang::str('pub_err_email', $lang);
            }
            if ($adult['phone'] !== '' && phone::normalise($adult['phone'], $centre->country) === null) {
                $errors["a{$i}phone"] = publiclang::str('pub_err_phone', $lang);
            }
        }
        if ($first['email'] === '' && $first['phone'] === '' && !isset($errors['a0first'])) {
            $errors['a0phone'] = publiclang::str('pub_err_reach', $lang);
        }
        $offers = centres::offers($centre->id, true);
        $any = false;
        foreach ($data['children'] as $i => $child) {
            if ($child['offerid'] && !isset($offers[$child['offerid']])) {
                $errors["c{$i}offer"] = publiclang::str('pub_err_offer', $lang);
            }
            $any = $any || $child['firstname'] !== '';
        }
        // At an event that is not about admissions (a party, a talk), who would study is not asked for.
        if (!$any && $event->kind !== 'other') {
            $errors['c0name'] = publiclang::str('pub_err_child', $lang);
        }
        $slots = events::slots((int) $event->id);
        if ($slots && !isset($slots[$data['slot'] ?? 0])) {
            $errors['slot'] = publiclang::str('pub_err_slot', $lang);
        }
        if ($data['people'] < 1 || $data['people'] > 20) {
            $errors['people'] = publiclang::str('pub_err_people', $lang);
        } else if (!isset($errors['slot']) && !events::has_room($event, $data['people'], 0, (int) ($data['slot'] ?? 0))) {
            $errors[$slots ? 'slot' : 'people'] = publiclang::str($slots ? 'pub_err_slot_full' : 'pub_err_full', $lang);
        }
        if (!$errors && !antispam::verify($data['altcha'])) {
            $errors['altcha'] = publiclang::str('pub_err_robot', $lang);
        }
        return $errors;
    }

    /**
     * Keeps the sign-up: the household (found again or new), its adults and children, the permissions, what it said
     * and its place in the event. The private link of the household is renewed and returned, to show it once.
     *
     * @param array $data From read(), already checked.
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @return array [household, token]
     */
    public static function save(array $data, \stdClass $event, \stdClass $centre, string $lang): array {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $adults = [];
        foreach ($data['adults'] as $adult) {
            if ($adult['firstname'] === '' && $adult['email'] === '' && $adult['phone'] === '') {
                continue;
            }
            $adult['email'] = households::email($adult['email']);
            $adult['phone'] = phone::normalise($adult['phone'], $centre->country);
            $adults[] = $adult;
        }
        $place = self::place($data, $centre);
        $householdid = null;
        foreach ($adults as $adult) {
            $householdid = $householdid ?? households::find($centre->id, $adult['email'], $adult['phone']);
        }
        $channel = events::CHANNEL[$event->kind] ?? 'openday';
        $about = (object) (['lang' => $lang, 'firstchannel' => $channel, 'heardfrom' => $data['heardfrom'],
            'incentre' => ($data['incentre'] ?? '') === 'yes'] + $place);
        if ($householdid === null) {
            $householdid = households::create($centre->id, $about);
        }
        $household = households::get($householdid, $centre->id);
        households::complete($household, $about);
        foreach ($adults as $i => $adult) {
            $adultid = households::add_adult($household, (object) $adult);
            // The permissions are asked of whoever fills the form, the first adult.
            if ($i === 0) {
                foreach ($data['consents'] as $consentchannel => $granted) {
                    households::consent($household, $adultid, $consentchannel, (bool) $granted, 'form', $lang);
                }
            }
        }
        foreach ($data['children'] as $child) {
            if ($child['firstname'] !== '') {
                households::add_child($household, $child['firstname'], (int) $child['offerid']);
            }
        }
        events::sign_up($event, $household->id, $data['people'], (int) ($data['slot'] ?? 0));
        $body = get_string('signedup_note', 'local_oksigeniaeguahe', (object) ['event' => $event->name,
            'people' => $data['people']]);
        if ($data['message'] !== '') {
            $body .= "\n\n" . $data['message'];
        }
        // Whoever writes a question waits for a person to answer it.
        households::received($household, $channel, $body, $data['message'] !== '');
        $token = households::new_link($household);
        $transaction->allow_commit();
        return [$household, $token];
    }

    /**
     * Where the family lives, from the closed list of the centre or as it wrote it.
     *
     * @param array $data
     * @param \stdClass $centre
     * @return array municipality and municipalityname.
     */
    protected static function place(array $data, \stdClass $centre): array {
        $list = centres::municipalities($centre);
        if (isset($list[$data['municipality']])) {
            $code = strpos($data['municipality'], 'name:') === 0 ? null : $data['municipality'];
            return ['municipality' => $code, 'municipalityname' => $list[$data['municipality']]];
        }
        $other = \core_text::substr($data['municipalityother'], 0, 255);
        return ['municipality' => null, 'municipalityname' => $other === '' ? null : $other];
    }
}
