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

    /** @var int Brothers and sisters for later a family can tell when signing up. */
    const SIBLINGS = 3;

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
                'tagname' => trim(optional_param("c{$i}tag", '', PARAM_TEXT)),
                'birthdate' => trim(optional_param("c{$i}birth", '', PARAM_RAW_TRIMMED)),
                'offerid' => optional_param("c{$i}offer", 0, PARAM_INT),
            ];
        }
        // Brothers and sisters at home who would come later: a name and the year of birth.
        $siblings = [];
        for ($i = 0; $i < self::SIBLINGS; $i++) {
            $siblings[] = [
                'firstname' => trim(optional_param("s{$i}name", '', PARAM_TEXT)),
                'birthyear' => optional_param("s{$i}year", 0, PARAM_INT),
            ];
        }
        $consents = [];
        foreach (lists::CONSENT_CHANNELS as $channel) {
            $consents[$channel] = optional_param('consent' . $channel, 0, PARAM_BOOL) ? 1 : 0;
        }
        return [
            'adults' => $adults,
            'children' => $children,
            'siblings' => $siblings,
            'people' => optional_param('people', 2, PARAM_INT),
            'slot' => optional_param('slot', 0, PARAM_INT),
            'incentre' => optional_param('incentre', '', PARAM_ALPHA),
            'municipality' => optional_param('municipality', '', PARAM_RAW_TRIMMED),
            'municipalityother' => trim(optional_param('municipalityother', '', PARAM_TEXT)),
            'heardfrom' => optional_param('heardfrom', '', PARAM_ALPHA),
            'heardwho' => trim(optional_param('heardwho', '', PARAM_TEXT)),
            'heardnet' => trim(optional_param('heardnet', '', PARAM_TEXT)),
            'message' => trim(optional_param('message', '', PARAM_TEXT)),
            'consents' => $consents,
            'altcha' => optional_param('altcha', '', PARAM_RAW_TRIMMED),
            'days' => array_keys(array_filter(optional_param_array('day', [], PARAM_BOOL))),
            'periods' => array_keys(array_filter(optional_param_array('period', [], PARAM_BOOL))),
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
            if (($child['birthdate'] ?? '') !== '' && !birthdays::clean($child['birthdate'], time())) {
                $errors["c{$i}birth"] = publiclang::str('pub_birthdate_bad', $lang);
            }
            $any = $any || $child['firstname'] !== '';
        }
        // At an event that is not about admissions (a party, a talk), who would study is not asked for.
        if (!$any && $event->kind !== 'other') {
            $errors['c0name'] = publiclang::str('pub_err_child', $lang);
        }
        // The questions of the form of the centre, if it has one.
        if (!empty($event->formid)) {
            $errors += forms::check((int) $event->formid, $data['answers'] ?? [], $lang, $centre->lang ?: 'es');
        }
        if ($event->kind === 'form') {
            // A form has no day, turns or room.
            return $errors;
        }
        $slots = empty($event->tailored) ? events::slots((int) $event->id) : [];
        if ($slots && !isset($slots[$data['slot'] ?? 0])) {
            $errors['slot'] = publiclang::str('pub_err_slot', $lang);
        }
        if ($data['people'] < 1 || $data['people'] > 20) {
            $errors['people'] = publiclang::str('pub_err_people', $lang);
        } else if (
            empty($event->tailored) && !isset($errors['slot']) &&
            !events::has_room($event, $data['people'], 0, (int) ($data['slot'] ?? 0))
        ) {
            $errors[$slots ? 'slot' : 'people'] = publiclang::str($slots ? 'pub_err_slot_full' : 'pub_err_full', $lang);
        }
        // How to write to them: at least one way, and one they gave the means for.
        $chosen = array_keys(array_filter($data['consents']));
        $reach = ['email' => $first['email'] !== '', 'whatsapp' => $first['phone'] !== '', 'phone' => $first['phone'] !== ''];
        if (!$chosen || !array_filter(array_intersect_key($reach, array_flip($chosen)))) {
            if (!isset($errors['a0phone'])) {
                $errors['channels'] = publiclang::str('pub_err_channel', $lang);
            }
        }
        if (!$errors && !antispam::verify($data['altcha'])) {
            $errors['altcha'] = publiclang::str('pub_err_robot', $lang);
        }
        return $errors;
    }

    /**
     * Keeps the sign-up: the household (found again or new), its adults and children, the permissions, what it said
     * and its place in the event. A new private link of the household is returned. When the household was there
     * before (the same email or phone), whoever filled the form may not be that family: its page is not shown, and
     * the link only goes to the emails the family had.
     *
     * @param array $data From read(), already checked.
     * @param \stdClass $event An event, or a form as forms::as_event() gives it.
     * @param \stdClass $centre
     * @param string $lang
     * @param \stdClass|null $entry The form, when it is one.
     * @param string $origin Where the form was shared from, by its name.
     * @return array [household, token, the emails it had before (empty for a new household), whether it was there]
     */
    public static function save(
        array $data,
        \stdClass $event,
        \stdClass $centre,
        string $lang,
        ?\stdClass $entry = null,
        string $origin = ''
    ): array {
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
        $channel = $entry ? entries::channel($entry) : (events::CHANNEL[$event->kind] ?? 'openday');
        $about = (object) (['lang' => $lang, 'firstchannel' => $channel, 'heardfrom' => $data['heardfrom'],
            'heardwho' => $data['heardwho'] ?? '',
            'heardnet' => ($data['heardfrom'] ?? '') === 'social' ?
                centres::network($centre, (string) ($data['heardnet'] ?? '')) : '',
            'incentre' => ($data['incentre'] ?? '') === 'yes'] + $place);
        $existed = $householdid !== null;
        $before = [];
        if ($existed) {
            $known = households::full(households::get($householdid, $centre->id));
            $before = array_values(array_filter(array_map(fn($a) => (string) $a->email, $known->adults)));
        }
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
                $childid = households::add_child($household, $child['firstname'], (int) $child['offerid'], $child['tagname'] ?? '');
                if (($child['birthdate'] ?? '') !== '') {
                    households::set_birthdate($household, $childid, $child['birthdate']);
                }
            }
        }
        if (siblings::on($centre)) {
            // Those who would come later: the year before their turn, the team offers them a place.
            $known = array_map(fn($s) => \core_text::strtolower($s->firstname), siblings::of((int) $household->id));
            foreach ($data['siblings'] ?? [] as $sibling) {
                $name = \core_text::strtolower(trim($sibling['firstname']));
                if ($name !== '' && !in_array($name, $known, true)) {
                    siblings::add($household, $sibling['firstname'], (int) $sibling['birthyear'], 'signup', time());
                    $known[] = $name;
                }
            }
        }
        $isform = $event->kind === 'form';
        if (!empty($event->tailored)) {
            // A visit of its own: what suits them waits for the team to propose the day.
            visits::request($household, $event, $data['days'] ?? [], $data['periods'] ?? [], $data['people']);
        } else if (!$isform) {
            events::sign_up($event, $household->id, $data['people'], (int) ($data['slot'] ?? 0));
        }
        $form = !empty($event->formid) ? ($entry ?? entries::get((int) $event->formid, (int) $centre->id)) : null;
        $answered = $form ? forms::save_answers($household, $form, $data['answers'] ?? [], $centre->lang ?: 'es') : [];
        $body = get_string('signedup_note', 'local_oksigeniaeguahe', (object) ['event' => $event->name,
            'people' => $data['people']]);
        if ($data['message'] !== '') {
            $body .= "\n\n" . $data['message'];
        }
        if ($isform) {
            $body = get_string('entry_arrived', 'local_oksigeniaeguahe', format_string($event->name)) .
                ($data['message'] !== '' ? "\n\n" . $data['message'] : '');
        }
        $tailored = !empty($event->tailored);
        if ($tailored) {
            $body = get_string('visit_asked_note', 'local_oksigeniaeguahe', (object) ['people' => $data['people'],
                'when' => visits::suits(visits::pending((int) $household->id), $lang)]) .
                ($data['message'] !== '' ? "\n\n" . $data['message'] : '');
        }
        if ($answered) {
            $body .= "\n\n" . implode("\n", $answered);
        }
        // Whoever writes a question, sends a form or asks for a visit of its own waits for a person to answer.
        households::received(
            $household,
            $channel,
            $body,
            $data['message'] !== '' || $tailored || $isform,
            null,
            $isform && $entry ? (int) $entry->id : 0,
            $origin
        );
        $token = households::new_link($household);
        $transaction->allow_commit();
        return [$household, $token, $before, $existed];
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
            return ['municipality' => $code, 'municipalityname' => $list[$data['municipality']], 'moving' => 0];
        }
        // From another place, or moving to the area from far: where they come from, as they wrote it.
        $other = \core_text::substr($data['municipalityother'], 0, 255);
        return ['municipality' => null, 'municipalityname' => $other === '' ? null : $other,
            'moving' => $data['municipality'] === 'moving' ? 1 : 0];
    }
}
