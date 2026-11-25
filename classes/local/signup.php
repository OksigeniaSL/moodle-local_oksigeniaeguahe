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
     * What the form sent, cleaned, without checking it yet; a student's own sign-up already as the plugin reads it.
     *
     * @param \stdClass|null $centre The centre of the form: who signs up there.
     * @return array
     */
    public static function read(?\stdClass $centre = null): array {
        $adults = [];
        for ($i = 0; $i < self::ADULTS; $i++) {
            $adults[] = [
                'firstname' => trim(optional_param("a{$i}first", '', PARAM_TEXT)),
                'lastname' => trim(optional_param("a{$i}last", '', PARAM_TEXT)),
                'email' => trim(optional_param("a{$i}email", '', PARAM_RAW_TRIMMED)),
                'phone' => trim(optional_param("a{$i}phone", '', PARAM_RAW_TRIMMED)),
                // Whether they studied at the centre themselves, when they say so.
                'alumni' => optional_param("a{$i}alumni", 0, PARAM_BOOL) ? '1' : '',
            ];
        }
        $children = [];
        for ($i = 0; $i < self::CHILDREN; $i++) {
            $children[] = [
                'firstname' => trim(optional_param("c{$i}name", '', PARAM_TEXT)),
                'tagname' => trim(optional_param("c{$i}tag", '', PARAM_TEXT)),
                'birthdate' => publicpage::date_param("c{$i}birth"),
                'latinname' => trim(optional_param("c{$i}latin", '', PARAM_TEXT)),
                'offerid' => optional_param("c{$i}offer", 0, PARAM_INT),
            ];
        }
        $children = self::apart($children);
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
        $data = [
            'adults' => $adults,
            'children' => $children,
            'siblings' => $siblings,
            'people' => optional_param('people', 2, PARAM_INT),
            'slot' => optional_param('slot', 0, PARAM_INT),
            'incentre' => optional_param('incentre', '', PARAM_ALPHA),
            // At an open act: whether they want to be told of the centre too.
            'tellme' => optional_param('tellme', 0, PARAM_BOOL),
            'municipality' => optional_param('municipality', '', PARAM_RAW_TRIMMED),
            'municipalityother' => trim(optional_param('municipalityother', '', PARAM_TEXT)),
            'heardfrom' => optional_param('heardfrom', '', PARAM_ALPHA),
            'heardwho' => trim(optional_param('heardwho', '', PARAM_TEXT)),
            'heardnet' => trim(optional_param('heardnet', '', PARAM_TEXT)),
            'message' => trim(optional_param('message', '', PARAM_TEXT)),
            'consents' => $consents,
            'altcha' => optional_param('altcha', '', PARAM_RAW_TRIMMED),
            'days' => array_merge(array_keys(array_filter(optional_param_array('day', [], PARAM_BOOL))), array_map(
                'intval',
                optional_param_array('tday', [], PARAM_INT)
            )),
            'periods' => array_merge(
                array_keys(array_filter(optional_param_array('period', [], PARAM_BOOL))),
                optional_param_array('tperiod', [], PARAM_ALPHA)
            ),
            'self' => false,
            // Where it arrived from, measured, carried in the form from its first page.
            'arrival' => arrival::read(),
        ];
        // The student who signs up for themselves: they are the adult and the one who would study.
        return $centre && self::for_self($centre, optional_param('para', '', PARAM_ALPHA)) ? self::as_self($data) : $data;
    }

    /**
     * Two or more children written in one name («Ana y Luis»), each in its own row, as long as there are rows for them: the
     * course chosen is of the first one.
     *
     * @param array[] $children As read() has them.
     * @return array[]
     */
    public static function apart(array $children): array {
        $blank = ['firstname' => '', 'tagname' => '', 'birthdate' => '', 'latinname' => '', 'offerid' => 0];
        $out = [];
        foreach ($children as $child) {
            $names = entries::names((string) $child['firstname']);
            if ($names) {
                $child['firstname'] = array_shift($names);
            }
            $out[] = $child;
            foreach ($names as $name) {
                $out[] = ['firstname' => $name] + $blank;
            }
        }
        // The rows left blank make room for them; what is written stays in its order.
        $written = array_values(array_filter($out, fn($c) => array_filter($c)));
        return count($out) === count($children) ? $out :
            array_pad(array_slice($written, 0, self::CHILDREN), self::CHILDREN, $blank);
    }

    /**
     * Whether a sign-up is the student's own, who comes on their own: always at a centre where students sign up, and at
     * one where either may when they say so («Para mí»).
     *
     * @param \stdClass $centre
     * @param string $para What the form said: «yo» for the student, anything else for a family.
     * @return bool
     */
    public static function for_self(\stdClass $centre, string $para): bool {
        $who = (string) ($centre->whoapplies ?? 'family');
        return $who === 'student' || ($who === 'both' && $para === 'yo');
    }

    /**
     * A sign-up of a student who comes on their own, as the rest of the plugin reads a family: they are its adult and
     * its only child, with the course they chose; nobody else comes in it.
     *
     * @param array $data From read().
     * @return array
     */
    public static function as_self(array $data): array {
        $data['adults'][0]['alumni'] = '';
        $me = $data['adults'][0];
        $course = (int) ($data['children'][0]['offerid'] ?? 0);
        foreach ($data['adults'] as $i => $adult) {
            if ($i) {
                $data['adults'][$i] = array_map(fn() => '', $adult);
            }
        }
        foreach ($data['children'] as $i => $child) {
            $data['children'][$i] = ['firstname' => '', 'tagname' => '', 'birthdate' => '', 'latinname' => '',
                'offerid' => 0];
        }
        $data['children'][0]['firstname'] = $me['firstname'];
        $data['children'][0]['offerid'] = $course;
        foreach ($data['siblings'] as $i => $sibling) {
            $data['siblings'][$i] = ['firstname' => '', 'birthyear' => 0];
        }
        $data['self'] = true;
        return $data;
    }

    /**
     * What is wrong in what the family sent, by field.
     *
     * @param array $data From read().
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @param bool $brief In the brief form: the ways to write and the robot are not asked (brief).
     * @return array Field => message, in the language of the family; empty when all is well.
     */
    public static function check(
        array $data,
        \stdClass $event,
        \stdClass $centre,
        string $lang,
        bool $brief = false
    ): array {
        $errors = [];
        $first = $data['adults'][0];
        if ($first['firstname'] === '') {
            $errors['a0first'] = publiclang::str('pub_err_name', $lang);
        }
        foreach ($data['adults'] as $i => $adult) {
            if ($adult['email'] !== '' && households::email($adult['email']) === null) {
                $errors["a{$i}email"] = publiclang::str('pub_err_email', $lang);
            } else if ($adult['email'] !== '' && !entries::is_test($adult['email']) && !emailcheck::reachable($adult['email'])) {
                // A domain that cannot receive mail: what goes after the @ is mistyped.
                $errors["a{$i}email"] = publiclang::str('pub_err_email_domain', $lang);
            }
            if ($adult['phone'] !== '' && phone::normalise($adult['phone'], $centre->country) === null) {
                $errors["a{$i}phone"] = publiclang::str('pub_err_phone', $lang);
            }
        }
        if ($first['email'] === '' && $first['phone'] === '' && !isset($errors['a0first'])) {
            $errors['a0phone'] = publiclang::str('pub_err_reach', $lang);
        }
        $offers = centres::offers($centre->id, true);
        // Who would study is asked for, and never needed: they may be two or more, or not be told yet.
        foreach ($data['children'] as $i => $child) {
            if ($child['offerid'] && !isset($offers[$child['offerid']])) {
                $errors["c{$i}offer"] = publiclang::str('pub_err_offer', $lang);
            }
            if (($child['birthdate'] ?? '') !== '' && !birthdays::clean($child['birthdate'], time())) {
                $errors["c{$i}birth"] = publiclang::str('pub_birthdate_bad', $lang);
            }
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
        // The room is looked at under the lock of the act, with what this family already had (fits()).
        if ($data['people'] < 1 || $data['people'] > events::MAXPEOPLE) {
            $errors['people'] = publiclang::str('pub_err_people', $lang);
        }
        if ($brief) {
            return $errors;
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
     * Whether the sign-up still fits, checked again under the lock of the act just before it is kept: the room (counting
     * what this family had already signed up for), and not a flood from one address.
     *
     * @param array $data From read(), already checked.
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @param bool $desk Whether it is a computer of the centre or the code of its door: many families from one address.
     * @return array Errors by field, as check() gives them.
     */
    public static function fits(array $data, \stdClass $event, \stdClass $centre, string $lang, bool $desk = false): array {
        global $DB;
        if ($event->kind === 'form' || !empty($event->tailored)) {
            return [];
        }
        if (!$desk && !events::allowed_from($event, getremoteaddr())) {
            return ['form' => publiclang::str('pub_err_toomany', $lang)];
        }
        if ($desk && userdate((int) $event->timestart, '%Y%m%d', 99, false) === userdate(time(), '%Y%m%d', 99, false)) {
            // At the act itself, on its day: nobody who is at the door is told that there is no room.
            return [];
        }
        $already = 0;
        foreach ($data['adults'] as $adult) {
            // The same household save() will find: by an email it already has, never a phone alone.
            $id = households::find((int) $centre->id, households::email($adult['email'] ?? ''), null);
            if ($id) {
                $already = (int) $DB->get_field(events::SIGNUPS, 'people', ['eventid' => $event->id, 'householdid' => $id]);
                break;
            }
        }
        $slots = events::slots((int) $event->id);
        if (!events::has_room($event, (int) $data['people'], $already, (int) ($data['slot'] ?? 0))) {
            return [$slots ? 'slot' : 'people' => publiclang::str($slots ? 'pub_err_slot_full' : 'pub_err_full', $lang)];
        }
        return [];
    }

    /** @var int The same family sent again within this, in seconds, is the same one, though it gave no email. */
    const AGAIN = 600;

    /**
     * What a page of a form is known by together with what was written in it: pressed twice, or sent again by going back, it
     * is the same.
     *
     * @param string $mark The mark of the page.
     * @param array $data From read().
     * @return string
     */
    protected static function fingerprint(string $mark, array $data): string {
        return sha1(json_encode([$mark, array_intersect_key($data, array_flip(['adults', 'children', 'siblings', 'people', 'slot',
            'days', 'periods', 'message', 'answers', 'tellme']))]));
    }

    /**
     * Whether that page, with the same written in it, was already saved.
     *
     * @param string $mark
     * @param array $data
     * @param \stdClass $centre
     * @return array|null The household, and whether the centre had it before; null the first time.
     */
    public static function again(string $mark, array $data, \stdClass $centre): ?array {
        $kept = \cache::make('local_oksigeniaeguahe', 'signupmarks')->get(self::fingerprint($mark, $data));
        $household = is_array($kept) ? households::get((int) $kept[0], (int) $centre->id) : null;
        return $household ? [$household, (bool) $kept[1]] : null;
    }

    /**
     * Keeps that a page was saved.
     *
     * @param string $mark
     * @param array $data
     * @param int $householdid
     * @param bool $existed
     */
    public static function remember(string $mark, array $data, int $householdid, bool $existed): void {
        \cache::make('local_oksigeniaeguahe', 'signupmarks')->set(self::fingerprint($mark, $data), [$householdid, $existed]);
    }

    /**
     * The household of somebody who, a moment ago, sent the same with the same phone and the same name: never a second one.
     *
     * @param int $centreid
     * @param array[] $adults Their phones normalised.
     * @param int $now
     * @return int|null
     */
    protected static function just_now(int $centreid, array $adults, int $now): ?int {
        global $DB;
        $first = $adults[0] ?? null;
        if (!$first || empty($first['phone']) || trim((string) $first['firstname']) === '') {
            return null;
        }
        $rows = $DB->get_records_sql(
            'SELECT a.id, a.householdid, a.firstname
               FROM {' . households::ADULTS . '} a
               JOIN {' . households::T . '} h ON h.id = a.householdid
              WHERE h.centreid = :centreid AND a.phone = :phone AND h.timecreated > :since
           ORDER BY a.id ASC',
            ['centreid' => $centreid, 'phone' => $first['phone'], 'since' => $now - self::AGAIN]
        );
        foreach ($rows as $row) {
            if (recognise::plain((string) $row->firstname) === recognise::plain((string) $first['firstname'])) {
                return (int) $row->householdid;
            }
        }
        return null;
    }

    /**
     * The address the mail of a sign-up goes to: to a family just made, its own; to one already known, the one written, when
     * the centre had it, and else the one it lets the centre write to.
     *
     * @param array $data From read().
     * @param string[] $before The addresses the family had.
     * @param \stdClass $household
     * @param bool $existed
     * @return string|null Null, its own; empty, none.
     */
    public static function mail_to(array $data, array $before, \stdClass $household, bool $existed): ?string {
        if (!$existed) {
            return null;
        }
        $known = array_map(fn($e) => \core_text::strtolower($e), $before);
        foreach ($data['adults'] as $adult) {
            $email = households::email($adult['email'] ?? '');
            if ($email !== null && in_array(\core_text::strtolower($email), $known, true)) {
                return $email;
            }
        }
        return households::recipient($household);
    }

    /**
     * Whether whoever signs up is of the team of the centre, by the email of their account or the phones of their profile: it
     * may be a test, and it is asked.
     *
     * @param array $data From read().
     * @param \stdClass $centre
     * @return bool
     */
    public static function is_team(array $data, \stdClass $centre): bool {
        $adults = [];
        foreach ($data['adults'] as $adult) {
            $adults[] = (object) ['email' => households::email($adult['email'] ?? ''),
                'phone' => phone::normalise((string) ($adult['phone'] ?? ''), $centre->country ?? null)];
        }
        return entries::is_team($centre, (object) ['adults' => $adults, 'email' => null, 'phone' => null]);
    }

    /**
     * Each child a form brought with the course it asks for, one a line, for the history of the family.
     *
     * @param array[] $children firstname and offerid of each.
     * @return string
     */
    public static function asked(array $children): string {
        global $DB;
        $out = [];
        foreach ($children as $child) {
            $offer = (int) $child['offerid'] ? $DB->get_record(centres::OFFERS, ['id' => (int) $child['offerid']]) : null;
            if ($offer) {
                $name = trim((string) $child['firstname']);
                $out[] = ($name !== '' ? $name . ' · ' : '') . centres::offer_label($offer);
            }
        }
        return implode("\n", $out);
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
        // Anyone can fill this form: it finds a family only by an email it already has. A phone alone makes another
        // household, and the team sees «It may be the same family» (whoever wrote a phone of another family never gets
        // into it, nor gets its sign-ups).
        foreach ($adults as $adult) {
            $householdid = $householdid ?? households::find($centre->id, $adult['email'], null);
        }
        // The same sent twice in a moment, only with a phone, is one family.
        $householdid = $householdid ?? self::just_now((int) $centre->id, $adults, time());
        $channel = $entry ? entries::channel($entry) : (events::CHANNEL[$event->kind] ?? 'openday');
        $about = (object) (['lang' => $lang, 'firstchannel' => $channel, 'heardfrom' => $data['heardfrom'],
            'heardwho' => $data['heardwho'] ?? '',
            'heardnet' => ($data['heardfrom'] ?? '') === 'social' ?
                centres::network($centre, (string) ($data['heardnet'] ?? '')) : '',
            'incentre' => ($data['incentre'] ?? '') === 'yes', 'self' => !empty($data['self']),
            // Whoever only comes to a talk or a party is a guest of it: not a family of the admissions.
            'guest' => $event->kind === 'other' && empty($data['tellme']),
            'arrival' => $data['arrival'] ?? null] + $place);
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
        // It had gone, or had every way closed, and here it is again.
        $gone = $existed && $event->kind !== 'other' && ((int) ($household->outtime ?? 0) || ($known->children &&
            !array_filter($known->children, fn($c) => $c->stage !== 'closed')));
        outs::back($household, $centre);
        households::complete($household, $about);
        $tellme = $event->kind === 'other' && !empty($data['tellme']);
        $admitted = $existed && ($event->kind !== 'other' || $tellme) && households::admit($householdid);
        if ($admitted || $gone) {
            // The team hears of it as of a new one.
            teamnotice::back($householdid);
        }
        $tellme = $tellme && (!$existed || $admitted);
        // A form anyone can fill matched a family the centre already has: it never brings it an address of its own (the
        // family's mail would go there), never takes back a permission, and never changes what its children already
        // have. A new address, or a new phone, waits in a note for the team, which adds it if it is theirs.
        $emails = array_map(fn($e) => \core_text::strtolower($e), $before);
        $phones = $existed ? array_filter(array_map(fn($a) => (string) $a->phone, $known->adults)) : [];
        $unverified = [];
        foreach ($adults as $i => $adult) {
            $new = [];
            if ($existed && (string) $adult['email'] !== '' && !in_array(\core_text::strtolower($adult['email']), $emails, true)) {
                $new[] = $adult['email'];
                $adult['email'] = null;
            }
            if ($existed && (string) $adult['phone'] !== '' && !in_array((string) $adult['phone'], $phones, true)) {
                $new[] = phone::display((string) $adult['phone']);
                $adult['phone'] = null;
            }
            if ($new) {
                $unverified[] = trim($adult['firstname'] . ' ' . ($adult['lastname'] ?? '')) . ' <' . implode(', ', $new) . '>';
            }
            $adultid = households::add_adult($household, (object) $adult);
            if (!empty($adult['alumni']) && empty($data['self'])) {
                alumni::set($household, $adultid, alumni::YES, 'family');
            }
            // The permissions are asked of whoever fills the form, the first adult.
            if ($i === 0) {
                foreach ($data['consents'] as $consentchannel => $granted) {
                    // A way they gave no means for was not asked: nobody said no to it.
                    $means = $consentchannel === 'email' ? (string) $adult['email'] !== '' : (string) $adult['phone'] !== '';
                    if ($granted ? $means || !$existed : !$existed && $means) {
                        households::consent($household, $adultid, $consentchannel, (bool) $granted, 'form', $lang);
                    }
                }
            }
        }
        if (!$existed && !empty($about->guest) && !$about->incentre && family::hints(households::full($household))) {
            // The centre already has them in its common family record: said at once, not the morning after.
            households::complete($household, (object) ['incentre' => 1]);
        }
        if ($unverified) {
            households::note($household, 0, get_string(
                'signup_unverified_email',
                'local_oksigeniaeguahe',
                implode(', ', $unverified)
            ));
        }
        // A course without a name is a child too, who is named later; and whoever asks for a place or comes to see the centre
        // comes for somebody, though nothing is said of them yet.
        $children = array_filter($data['children'], fn($c) => $c['firstname'] !== '' || (int) $c['offerid']);
        if (!$children && $event->kind !== 'other') {
            $children = [['firstname' => '', 'offerid' => 0]];
        }
        foreach ($children as $child) {
            $childid = households::add_child(
                $household,
                $child['firstname'],
                (int) $child['offerid'],
                $child['tagname'] ?? '',
                $existed
            );
            if (($child['birthdate'] ?? '') !== '' && (!$existed || !self::has_birthdate($childid))) {
                households::set_birthdate($household, $childid, $child['birthdate']);
            }
            if (($child['latinname'] ?? '') !== '') {
                households::set_latin_name($household, $childid, $child['latinname']);
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
        } else if (!$isform && empty($data['full'])) {
            events::sign_up($event, $household->id, $data['people'], (int) ($data['slot'] ?? 0));
        }
        $form = !empty($event->formid) ? ($entry ?? entries::get((int) $event->formid, (int) $centre->id)) : null;
        $answered = $form ? forms::save_answers($household, $form, $data['answers'] ?? [], $centre->lang ?: 'es') : [];
        // With no room left it wanted to come: it asks for it, and a person tells it if there is.
        $body = get_string(empty($data['full']) ? 'signedup_note' : 'signedup_full_note', 'local_oksigeniaeguahe', (object) [
            'event' => $event->name, 'people' => $data['people']]);
        if ($tellme) {
            $body .= ' ' . get_string('signedup_tellme', 'local_oksigeniaeguahe');
        }
        // What they ask for that is not a text of theirs: the turn, and each child with its course.
        $slot = (int) ($data['slot'] ?? 0) && empty($data['full']) ? events::when($event, (int) $data['slot']) : null;
        $asked = implode("\n", array_filter([$slot ? panel::day_hour((int) $slot->timestart) : '', self::asked($data['children'])]));
        $asked = $asked !== '' ? "\n" . $asked : '';
        $body .= $isform ? '' : $asked;
        if ($data['message'] !== '') {
            $body .= "\n\n" . $data['message'];
        }
        if ($isform) {
            $body = get_string('entry_arrived', 'local_oksigeniaeguahe', format_string($event->name)) . $asked .
                ($data['message'] !== '' ? "\n\n" . $data['message'] : '');
        }
        $tailored = !empty($event->tailored);
        if ($tailored) {
            $body = get_string('visit_asked_note', 'local_oksigeniaeguahe', (object) ['people' => $data['people'],
                'when' => visits::suits(visits::pending((int) $household->id), $centre->lang ?: $lang)]) . $asked .
                ($data['message'] !== '' ? "\n\n" . $data['message'] : '');
        }
        if ($answered) {
            $body .= "\n\n" . implode("\n", $answered);
        }
        // Whoever writes a question, sends a form, asks for a visit of its own or to be told of the centre waits for a person.
        households::received(
            $household,
            $channel,
            $body,
            $data['message'] !== '' || $tailored || $isform || $tellme || !empty($data['full']),
            null,
            $isform && $entry ? (int) $entry->id : 0,
            $origin
        );
        $token = households::new_link($household);
        $transaction->allow_commit();
        return [$household, $token, $before, $existed];
    }

    /**
     * Whether a child already has a date of birth.
     *
     * @param int $childid
     * @return bool
     */
    protected static function has_birthdate(int $childid): bool {
        global $DB;
        return trim((string) $DB->get_field(households::CHILDREN, 'birthdate', ['id' => $childid])) !== '';
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
