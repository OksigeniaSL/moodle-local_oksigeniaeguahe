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
 * What an event shows in the panel, whatever it looks like, and its door: how many came of how many, the families by
 * their turn with «came» at hand, someone who arrives without signing up; for the event of asking, what each family
 * asked for; for a visit of a family, whose it is; and what is shared: the QR, the link and the posters.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_event {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * What the door asks every half minute: the families that came, with the hour.
     *
     * @param \stdClass $event
     * @return array came: sign-up id => hour.
     */
    public static function check(\stdClass $event): array {
        global $DB;
        $came = [];
        foreach (
            $DB->get_records_select(
                'local_oksigeniaeguahe_signup',
                'eventid = ? AND attended > 0',
                [$event->id],
                '',
                'id, attended'
            ) as $signup
        ) {
            $came[(string) $signup->id] = panel::hour((int) $signup->attended);
        }
        return ['came' => (object) $came];
    }

    /**
     * The context of the page.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @param bool $fresh Whether it was just made.
     * @param bool $phonecheck Whether sending its letter by phone too is being looked over.
     * @return array
     */
    public static function context(
        \stdClass $event,
        \stdClass $centre,
        bool $canact,
        int $now = 0,
        bool $fresh = false,
        bool $phonecheck = false
    ): array {
        $now = $now ?: time();
        $c = self::C;
        $url = new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]);
        $tailored = !empty($event->tailored);
        $family = $event->householdid ? households::get((int) $event->householdid, (int) $centre->id) : null;

        // The families by their turn: the turn of now open, the others closed; without turns, one list by name.
        $slots = events::slots((int) $event->id);
        $groups = [];
        foreach ($slots as $slot) {
            $groups[$slot->id] = ['title' => get_string('panel_hours', $c, (object) ['from' => panel::hour((int) $slot->timestart),
                'to' => panel::hour((int) $slot->timeend)]), 'now' => $slot->timestart <= $now && $now < $slot->timeend,
                'capacity' => (int) $slot->capacity, 'signups' => [], 'came' => 0];
        }
        $households = 0;
        $topass = 0;
        $came = 0;
        $people = 0;
        $peoplecame = 0;
        foreach (events::signups($event) as $signup) {
            $key = isset($groups[$signup->slotid]) ? (int) $signup->slotid : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = ['title' => $slots ? get_string('event_noslot', $c) : '', 'now' => false, 'capacity' => 0,
                    'signups' => [], 'came' => 0];
            }
            $children = [];
            foreach ($signup->household->children as $child) {
                // Their drawing, asked for while they are young; one already sent is always seen.
                $hasdrawing = (bool) drawings::file((int) $child->id);
                $draws = $hasdrawing || ages::young_course($child->offer ? (string) $child->offer->name : '', $centre);
                $children[] = ['tag' => panel::called($child), 'course' => $child->offer ? format_string($child->offer->name) :
                    get_string('panel_nocourse', $c),
                    'drawingurl' => $canact && $draws ? (new \moodle_url(
                        '/local/oksigeniaeguahe/drawing.php',
                        ['child' => $child->id, 'event' => $event->id]
                    ))->out(false) : '',
                    'hasdrawing' => $hasdrawing];
            }
            $arrived = (int) $signup->attended > 0;
            $groups[$key]['signups'][] = [
                'id' => (int) $signup->id,
                'adults' => panel::adults_line($signup->household->adults),
                'children' => $children,
                'people' => get_string((int) $signup->people === 1 ? 'panel_people_one' : 'panel_people', $c, (int) $signup->people),
                // Apart from the families of the admissions: those of the centre, its team, and whoever only comes to this.
                'aside' => self::aside($signup->household, $centre),
                'came' => $arrived,
                'cametext' => $arrived ? get_string($canact ? 'panel_came_at_undo' : 'panel_came_at', $c, panel::hour((int) $signup->attended)) :
                    get_string('panel_came_button', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $signup->householdid]))->out(false),
                'search' => \core_text::strtolower(panel::adults_line($signup->household->adults) . ' ' .
                    implode(' ', array_column($children, 'tag'))),
            ];
            $topass += !$arrived && empty($signup->missed) && !$tailored && !$family && (int) $event->timeend < $now ? 1 : 0;
            $groups[$key]['came'] += $arrived ? 1 : 0;
            $households++;
            $came += $arrived ? 1 : 0;
            $people += (int) $signup->people;
            $peoplecame += $arrived ? (int) $signup->people : 0;
        }
        $anynow = (bool) array_filter($groups, fn($g) => $g['now']);
        $list = [];
        foreach ($groups as $group) {
            if (!$slots) {
                usort($group['signups'], fn($a, $b) => strcoll($a['adults'], $b['adults']));
            }
            $list[] = $group + [
                'open' => $group['now'] || !$anynow,
                'count' => get_string('panel_came_count', $c, (object) ['came' => $group['came'],
                    'households' => count($group['signups'])]),
                'hassignups' => (bool) $group['signups'],
                // Before the day, how many are coming in it: never how many came.
                'families' => count($group['signups']) . ' ' . get_string(count($group['signups']) === 1 ?
                    'panel_signed_one' : 'panel_signed_households', $c),
            ];
        }

        // The band: at the door on the day; before, how much is left; after, how it went.
        $today = panel::days_between((int) $event->timestart, $now) === 0;
        if ($tailored) {
            $band = get_string('visit_until', $c, userdate((int) $event->timeend, get_string('strftimedate', 'langconfig')));
        } else if ($today) {
            $band = get_string('panel_at_the_door', $c, (object) ['day' => userdate((int) $event->timestart, '%A %d'),
                'now' => panel::hour($now)]);
        } else if ($event->timestart > $now) {
            $days = panel::days_between($now, (int) $event->timestart);
            $band = get_string($days === 1 ? 'panel_days_left_one' : 'panel_days_left', $c, $days);
        } else {
            $band = get_string('panel_it_was', $c, panel::when_words((int) $event->timestart, $now));
        }
        $hosts = $event->hosts ? centres::hosts((int) $centre->id, array_map('intval', explode(',', (string) $event->hosts))) : [];
        $detail = [];
        if (!$tailored) {
            // With no end, or ending as it starts: the hour it starts, alone.
            $detail[] = (int) $event->timeend > (int) $event->timestart ? get_string('panel_hours', $c, (object) [
                'from' => panel::hour((int) $event->timestart), 'to' => panel::hour((int) $event->timeend)]) :
                get_string('panel_hours_at', $c, panel::hour((int) $event->timestart));
        }
        if (trim((string) $event->place) !== '') {
            $detail[] = format_string($event->place);
        }
        if ($hosts) {
            $firsts = array_map(fn($h) => $h->first, $hosts);
            $last = array_pop($firsts);
            $detail[] = get_string(count($hosts) === 1 ? 'panel_receives' : 'panel_receive', $c, $firsts ?
                get_string('andlist', $c, (object) ['list' => implode(', ', $firsts), 'last' => $last]) : $last);
        }

        // The event of asking: what each family asked for, and the visit it was given.
        $requests = [];
        foreach ($tailored ? visits::requests($event) : [] as $request) {
            $visit = $request->visit;
            $guide = $visit && $visit->hosts ? (centres::hosts((int) $centre->id, [(int) $visit->hosts])[0] ?? null) : null;
            $child = reset($request->household->children);
            $requests[] = [
                'adults' => panel::adults_line($request->household->adults),
                'tag' => $child ? panel::tag($child) : '',
                'suits' => get_string('panel_request_suits', $c, visits::suits($request, current_language())),
                'state' => $request->status === 'pending' || !$visit ? get_string('panel_request_waiting', $c) :
                    get_string($visit->timeend < $now ? 'panel_request_done' : 'panel_request_given', $c, (object) [
                        'when' => panel::when_words((int) $visit->timestart, $now),
                        'guide' => $guide ? $guide->name : get_string('visit_guide_none', $c)]),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $request->householdid]))->out(false),
            ];
        }
        $form = $event->formid ? entries::get((int) $event->formid, (int) $centre->id) : null;
        // The wall of the turn of now, on the day: a knob per family and the backpack of those that came.
        $nowturn = null;
        if ($today && !$tailored && !$family) {
            $group = array_values(array_filter($list, fn($g) => $g['now']))[0] ?? ($slots ? null : ($list[0] ?? null));
            if ($group) {
                $wall = [];
                foreach ($group['signups'] as $i => $signup) {
                    $wall[] = ['came' => $signup['came'], 'name' => $signup['children'][0]['tag'] ?? ''] +
                        ['style' => panel::hanging($i, '', '', $centre)['style']];
                }
                $nowturn = $wall ? ['wall' => $wall] : null;
            }
        }
        // The two moments of an act: before its day, who is coming and what to prepare; on the day, the door. «Came» is
        // only offered from the day on (the server refuses it before, see event.php).
        $before = !$tailored && !$family && !$today && (int) $event->timestart > $now;
        return [
            'before' => $before,
            'door' => !$tailored && !$family && !$before && (int) $event->timeend >= $now,
            'opens' => $before ? get_string('panel_door_opens', $c, userdate(
                (int) $event->timestart,
                get_string('strftimedaydate', 'langconfig')
            )) : '',
            'signedsentence' => get_string($households === 1 ? 'panel_signed_one' : 'panel_signed_households', $c, $households),
            'signedpeople' => $event->capacity ? get_string('panel_signed_people_of', $c, (object) ['people' => $people,
                'capacity' => (int) $event->capacity]) : get_string($people === 1 ? 'panel_people_one' : 'panel_people', $c, $people),
            'signedpercent' => $event->capacity ? min(100, (int) round(100 * $people / (int) $event->capacity)) : 0,
            'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]))->out(false),
                'name' => get_string('tab_events', $c)],
            'band' => $band,
            'name' => format_string($event->name),
            'detail' => implode(' · ', $detail),
            'counts' => !$tailored && !$family,
            'came' => $came,
            'households' => $households,
            'camesentence' => get_string('panel_came_of_households', $c, $households),
            'peoplesentence' => get_string('panel_people_of', $c, (object) ['came' => $peoplecame, 'people' => $people]),
            'percent' => $people ? (int) round(100 * $peoplecame / $people) : 0,
            'tailored' => $tailored,
            'requests' => $requests,
            'hasrequests' => (bool) $requests,
            'own' => (bool) $family,
            'invite' => self::invite_card($event, $canact, $now, $phonecheck),
            // Just made: what comes next.
            'fresh' => $fresh && $canact ? [
                'invite' => invites::fits($event, $now) ? (new \moodle_url(
                    '/local/oksigeniaeguahe/event.php',
                    ['id' => $event->id, 'invitar' => 1]
                ))->out(false) : '',
                'edit' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id, 'id' => $event->id]))->out(
                    false
                ),
                'share' => '#compartir',
                'poster' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id, 'poster' => 1]))->out(false),
            ] : false,
            // How it is told, as the team reads it.
            'told' => acts::context($event, (string) ($centre->lang ?: 'es')),
            'family' => $family ? panel::adults_line(households::full($family)->adults) : '',
            'familyurl' => $family ? (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $family->id]))->out(false) :
                '',
            'groups' => $list,
            'hassignups' => (bool) $households,
            'canact' => $canact,
            'addurl' => $canact && !$tailored && !$family ? (new \moodle_url(
                '/local/oksigeniaeguahe/edit.php',
                // Without signing up: at the door, short, and back to it.
                ['c' => $centre->id, 'eventid' => $event->id] + ((int) $event->timeend > $now && panel::days_between((int) $event->timestart, $now) >= 0 ?
                    ['puerta' => 1] : [])
            ))->out(false) : '',
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            // The clock of the site, in seconds of the day: what is marked here says its hour.
            'reloj' => (int) userdate($now, '%H', 99, false) * HOURSECS + (int) userdate($now, '%M', 99, false) * MINSECS +
                (int) userdate($now, '%S', 99, false),
            'nowturn' => $nowturn,
            'checkurl' => !$tailored && !$family ? (new \moodle_url($url, ['check' => 1]))->out(false) : '',
            'checkevery' => 30,
            // The backpacks on the wall are of whoever comes to see the centre.
            'labelsurl' => $households && $event->kind !== 'other' ? (new \moodle_url('/local/oksigeniaeguahe/labels.php', ['c' => $centre->id,
                'event' => $event->id]))->out(false) : '',
            'labelsname' => get_string('panel_labels', $c),
            'doorsound' => (new \moodle_url('/local/oksigeniaeguahe/pub/sonido/puerta'))->out(false),
            'qr' => events::qr_svg($event),
            'qrfile' => format_string($event->name),
            'link' => events::short_url($event) ?? events::url($event)->out(false),
            // An open act is published without its link; offering it is asked for on purpose.
            'linkout' => !empty($event->linkout),
            'linkmay' => $event->kind === 'other' && $canact && !$family ? ['posturl' => $url->out(false), 'sesskey' => sesskey()] :
                false,
            'posterurl' => (new \moodle_url($url, ['poster' => 1]))->out(false),
            'posterurla3' => (new \moodle_url($url, ['poster' => 1, 'paper' => 'A3']))->out(false),
            // For the computers of the centre at the act: the form again after each family, never their page.
            'deskurl' => !$tailored && !$family ? (new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token,
                'pk' => share::signature('puesto', 0, (int) $event->id)]))->out(false) : '',
            // To have the signing up inside a page of the web of the centre.
            'embed' => !$tailored && !$family && !empty($event->open) && !empty($event->linkout) ? events::embed_code($event, format_string($event->name)) :
                '',
            // The poster of the door, for the day: families sign in with their phone when they arrive.
            'doorposter' => !$tailored && !$family ? (new \moodle_url($url, ['poster' => 1, 'door' => 1]))->out(false) : '',
            'doorpostera5' => !$tailored && !$family ? (new \moodle_url($url, ['poster' => 1, 'door' => 1, 'paper' => 'A5',
                'qr' => 'mesa']))->out(false) : '',
            // The kit for the networks, for an act open to all and still to come.
            'kiturl' => kit::has($event, $now) ? (new \moodle_url('/local/oksigeniaeguahe/kit.php', ['id' => $event->id]))
                ->out(false) : false,
            'kind' => $family || $tailored ? get_string($family ? 'visit_own' : 'visit_asking', $c) : acts::team_word($event),
            'capacity' => $event->capacity ? get_string(
                (int) $event->capacity === 1 ? 'panel_people_one' : 'panel_people',
                $c,
                (int) $event->capacity
            ) : '',
            'slots' => $slots ? get_string('panel_slots', $c, count($slots)) : '',
            'questions' => $form ? format_string($form->name) : '',
            'open' => $event->open && $event->timeend >= $now,
            'editurl' => $canact ? (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id,
                'id' => $event->id]))->out(false) : '',
            // Calling it off, before it happens: the families are told and nothing more goes out for it.
            'cancel' => $canact && !(int) ($event->cancelled ?? 0) && (int) $event->timeend >= $now,
            // Those that have its letter and are not signed up, to be told too if it is called off.
            'letters' => $canact && !(int) ($event->cancelled ?? 0) && (int) $event->timeend >= $now &&
                ($letters = count(invites::with_letter($event))) ? get_string(
                    $letters === 1 ? 'event_cancel_letters_one' : 'event_cancel_letters',
                    $c,
                    $letters
                ) : '',
            'cancelled' => (int) ($event->cancelled ?? 0) ? get_string(
                'event_cancelled_on',
                $c,
                userdate((int) $event->cancelled, get_string('strftimedate', 'langconfig'))
            ) : '',
            'howitwent' => self::howitwent($event, $centre, $canact, $now),
            // Over, and nobody said of some whether they came.
            'topass' => $canact && $topass ? ['text' => get_string('agenda_acttopass' . ($topass === 1 ? '_one' : ''), $c, $topass),
                'url' => panel_agenda::passurl((int) $centre->id, (int) $event->id)] : false,
            // Over: on a phone, the band no longer stays on top of the door.
            'past' => (int) $event->timeend < $now,
        ];
    }

    /**
     * How it went, once the event is over: how many of the families who came told, the last one, and by question a
     * sentence, its words in their order in bars of one tone with how many said each, and the texts as quotes with the
     * name used at home; below, in soft, the event before with the same survey. Never percentages; with few answers,
     * only sentences and quotes. And «What we do with it», the line the families who answered read.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array|false
     */
    public static function howitwent(\stdClass $event, \stdClass $centre, bool $canact, int $now) {
        $c = self::C;
        if ((int) $event->timeend > $now || !empty($event->tailored)) {
            return false;
        }
        $lang = current_language();
        $results = surveys::results($event, $centre, $lang);
        if (!$results['came']) {
            return false;
        }
        $previous = surveys::previous($event);
        $before = $previous ? surveys::results($previous, $centre, $lang) : null;
        $beforeby = [];
        foreach ($before ? $before['questions'] : [] as $question) {
            // The one before in lower case after the colon: «La anterior: lo han escrito 5 familias».
            $sentence = self::sentence($question, $before['told']);
            $beforeby[$question['id']] = \core_text::strtolower(\core_text::substr($sentence, 0, 1)) .
                \core_text::substr($sentence, 1);
        }
        $few = $results['told'] < surveys::FEW;
        $questions = [];
        foreach ($results['questions'] as $question) {
            $most = max(1, ...(array_column($question['rows'], 'n') ?: [0]));
            // The label of each quote leads to the file of its family.
            $file = fn(int $id) => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false);
            $quotes = array_map(
                fn($q) => ['text' => $q['text'], 'who' => $q['who'], 'url' => $q['who'] !== '' ? $file($q['householdid']) : ''],
                $question['quotes']
            );
            // The words the sentence of a scale counts: the upper half, as sentence() says.
            $upper = $question['type'] === 'scale' ? (int) ceil(count($question['rows']) / 2) : PHP_INT_MAX;
            $questions[] = [
                'label' => format_string($question['label']),
                'sentence' => self::sentence($question, $results['told']),
                'hasrows' => !$few && (bool) $question['rows'],
                'rows' => array_map(
                    fn($r, $i) => ['label' => $r['label'], 'n' => $r['n'], 'v' => round($r['n'] / $most, 4),
                        'upper' => $i >= $upper],
                    $question['rows'],
                    array_keys($question['rows'])
                ),
                'hasquotes' => (bool) $quotes,
                'quotes' => array_slice($quotes, 0, 6),
                'morequotes' => count($quotes) > 6 ? ['label' => get_string('panel_howitwent_more', $c, count($quotes) - 6),
                    'quotes' => array_slice($quotes, 6)] : false,
                'before' => isset($beforeby[$question['id']]) && $beforeby[$question['id']] !== '' ?
                    get_string('panel_howitwent_before_q', $c, $beforeby[$question['id']]) : '',
            ];
        }
        $survey = surveys::form($event);
        $open = surveys::is_open($event, $now);
        $told = !$results['told'] ? get_string($survey ? 'panel_howitwent_none' : 'panel_howitwent_none_ask', $c) :
            get_string('panel_howitwent_told' . ($survey ? '' : '_ask') . ($results['came'] === 1 ? '_one' : ''), $c, (object) [
                'told' => $results['told'], 'came' => $results['came']]) .
            ($results['last'] ? ' · ' . get_string('panel_howitwent_last', $c, panel::when_words($results['last'], $now)) : '');
        // The families who came, counted by hand: one stroke each in fives, in ink those who told.
        $tally = false;
        if ($results['came'] && $results['came'] <= 60) {
            $strokes = array_map(fn($i) => ['ink' => $i < $results['told']], range(0, $results['came'] - 1));
            $tally = ['groups' => array_map(fn($g) => ['strokes' => $g], array_chunk($strokes, 5))];
        }
        $short = trim(userdate((int) $event->timeend + surveys::DAYS * DAYSECS, '%e %b'));
        return [
            'title' => get_string($survey ? 'panel_howitwent' : 'panel_howitwent_asked', $c),
            'tally' => $tally,
            'stamp' => $survey ? ['word' => get_string($open ? 'panel_howitwent_stamp_open' : 'panel_howitwent_stamp_closed', $c),
                'date' => preg_match('/^(es|pt)/', current_language()) ? $short . '.' : $short] : false,
            'told' => $told,
            'questions' => $questions,
            'hasquestions' => (bool) $questions,
            'few' => $results['told'] && $few ? get_string('panel_howitwent_few', $c) : '',
            'beforeline' => $before ? get_string('panel_howitwent_before', $c, (object) ['name' => format_string($previous->name),
                'date' => userdate((int) $previous->timestart, get_string('strftimedate', 'langconfig')),
                'told' => $before['told'], 'came' => $before['came']]) : '',
            'state' => $survey ? get_string($open ? 'panel_howitwent_open' : 'panel_howitwent_closed', $c, userdate(
                (int) $event->timeend + surveys::DAYS * DAYSECS,
                get_string('strftimedateshort', 'langconfig')
            )) : get_string('panel_howitwent_nosurvey', $c),
            'liveurl' => $open ? (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id, 'howitwent' => 1]))
                ->out(false) : '',
            'every' => 30,
            'canact' => $canact,
            'outcome' => (string) ($event->outcome ?? ''),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The sentence of a question of How it went: «8 of 10 said a lot or quite», the word said most, the yes and the
     * no, or how many wrote.
     *
     * @param array $question From surveys::results().
     * @param int $told How many families told.
     * @return string
     */
    protected static function sentence(array $question, int $told): string {
        $c = self::C;
        if (!$question['rows']) {
            $key = !$question['n'] ? 'panel_howitwent_noanswer' : ($question['n'] === 1 ? 'panel_howitwent_wrote_one' :
                'panel_howitwent_wrote');
            return get_string($key, $c, $question['n']);
        }
        $answered = $question['n'];
        if (!$answered) {
            return get_string('panel_howitwent_noanswer', $c);
        }
        if ($question['type'] === 'yesno') {
            return get_string('panel_howitwent_yesno', $c, (object) ['yes' => $question['rows'][0]['n'],
                'no' => $question['rows'][1]['n']]);
        }
        if ($question['type'] === 'scale') {
            // The upper half of the words, the strongest first: «a lot or quite».
            $upper = array_reverse(array_slice($question['rows'], (int) ceil(count($question['rows']) / 2)));
            $words = array_map(fn($r) => get_string('panel_howitwent_word', $c, \core_text::strtolower($r['label'])), $upper);
            $last = array_pop($words);
            return get_string('panel_howitwent_scale', $c, (object) ['n' => array_sum(array_column($upper, 'n')),
                'of' => $answered, 'words' => $words ? get_string('panel_howitwent_or', $c, (object) [
                'list' => implode(', ', $words), 'last' => $last]) : $last]);
        }
        $top = $question['rows'][0];
        foreach ($question['rows'] as $row) {
            $top = $row['n'] > $top['n'] ? $row : $top;
        }
        return get_string('panel_howitwent_choice', $c, (object) ['word' => $top['label'], 'n' => $top['n'], 'of' => $answered]);
    }

    /**
     * Who is at an act without being a family of the admissions: one of the centre, its team or only a guest.
     *
     * @param \stdClass $household With its adults.
     * @param \stdClass $centre
     * @return string Empty for a family of the admissions.
     */
    protected static function aside(\stdClass $household, \stdClass $centre): string {
        $adults = array_map(fn($a) => (object) ['email' => (string) $a->email, 'phone' => (string) $a->phone], $household->adults);
        if ($adults && entries::is_team($centre, (object) ['adults' => $adults, 'email' => '', 'phone' => ''])) {
            return get_string('panel_aside_team', self::C);
        }
        if (!empty($household->incentre) || !empty($household->roster)) {
            $kind = who::kind((int) $centre->id, (int) $household->id);
            return get_string(in_array($kind, ['nursery', 'staff'], true) ? 'panel_aside_' . $kind : 'panel_aside_centre', self::C);
        }
        return !empty($household->guest) ? get_string('panel_aside_guest', self::C) : '';
    }

    /**
     * What the invitation of an act is doing: what was just done, the letters still to go out and the send by phone.
     *
     * @param \stdClass $event
     * @param bool $canact
     * @param int $now
     * @param bool $phonecheck Whether sending the letter by phone too is being looked over.
     * @return array notice, going, said, withdraw, phonetoo, posturl and sesskey, for panel_invite_state.
     */
    protected static function invite_state(\stdClass $event, bool $canact, int $now, bool $phonecheck = false): array {
        $bulk = invites::last_bulk((int) $event->id);
        $state = invites::state($bulk);
        [$left, $next] = invites::on_the_way((int) $event->id);
        $paused = count(invites::paused((int) $event->id));
        $pausedtext = get_string($paused === 1 ? 'panel_invite_paused_one' : 'panel_invite_paused', self::C, $paused);
        return [
            // What Marandu says of the last send by phone, in its own words: waiting, approved, turned down and why.
            'said' => $state ? (string) ($state['said'] ?? '') : '',
            'withdraw' => $state && ($state['status'] ?? '') === 'pending' && $canact ? ['bulk' => $bulk] : false,
            // Those that have not gone out yet, and until when they will be going.
            // Those that were on their way and did not go, and why.
            'skipped' => invites::skipped((int) $event->id),
            'going' => $left ? ['text' => self::invite_left($left, invites::last_at($left, max($now, $next)), $now),
                'stop' => $canact] : false,
            // Stopped before it reached everyone: it goes on from where it was.
            'paused' => !$left && $paused ? ['text' => $pausedtext, 'resume' => $canact] : false,
            'notice' => invites::said((int) $event->id),
            // The letter by mail, also by phone, to those that have it and have said nothing yet.
            'phonetoo' => $canact && !$left ? self::phone_card($event, $phonecheck) : false,
            'posturl' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * On the page of an open act still to come: how many families can get its invitation, how many got it and the way to
     * prepare it.
     *
     * @param \stdClass $event
     * @param bool $canact
     * @param int $now
     * @param bool $phonecheck Whether sending it by phone too is being looked over.
     * @return array|false
     */
    protected static function invite_card(\stdClass $event, bool $canact, int $now, bool $phonecheck = false) {
        if (!invites::fits($event, $now)) {
            return false;
        }
        $can = count(invites::eligible($event));
        $any = $can || invites::of_centre($event);
        $done = invites::count((int) $event->id);
        $tally = [];
        $counted = $done ? invites::tally((int) $event->id) : [];
        foreach (array_diff_key($counted, array_flip(['phone', 'viaemail', 'viaphone'])) as $key => $n) {
            // The ones back lead to their names, to call them.
            $tally[] = ['key' => $key, 'n' => $n, 'label' => get_string('panel_tally_' . $key, self::C),
                'url' => $key === 'bounced' && $n ? '#devueltas' : ''];
        }
        // By more than one way: a line below the tally, as in Numbers; the tally is the way of the letter.
        $ways = isset($counted['phone']) ? get_string('panel_tally_ways', self::C, (object) ['phone' => $counted['phone'],
            'viaphone' => $counted['viaphone'], 'viaemail' => $counted['viaemail']]) : '';
        return self::invite_state($event, $canact, $now, $phonecheck) + [
            'text' => $can ? get_string($can === 1 ? 'panel_invite_can_one' : 'panel_invite_can', self::C, $can) :
                get_string('panel_invite_noone', self::C),
            'done' => $done ? get_string($done === 1 ? 'panel_invite_done_one' : 'panel_invite_done', self::C, $done) : '',
            'replies' => self::replies($event, $now),
            'tally' => $tally,
            'hastally' => (bool) $tally,
            'tallyways' => $ways,
            'url' => $any && $canact ? (new \moodle_url(
                '/local/oksigeniaeguahe/event.php',
                ['id' => $event->id, 'invitar' => 1]
            ))->out(false) : '',
        ];
    }

    /**
     * What the families invited said, by name: who comes, who does not this time, who opened it and said nothing, and who
     * has not opened it.
     *
     * @param \stdClass $event
     * @param int $now
     * @return array|false
     */
    protected static function replies(\stdClass $event, int $now) {
        global $DB;
        $c = self::C;
        $all = invites::replies((int) $event->id);
        if (!array_filter($all)) {
            return false;
        }
        // Those whose letter came back, to call: by name, first.
        $back = array_fill_keys(bounces::of_act((int) $event->id), 0);
        $all = ['bounced' => $back] + $all;
        $people = households::some((int) $event->centreid, array_keys($back + $all['yes'] + $all['no'] + $all['opened'] + $all['none']));
        // When each letter went, and when the family opened it and answered: to learn at what time it is best to send.
        $times = $DB->get_records_select(
            invites::T,
            'eventid = ? AND ' . households::NOTSAMPLE,
            [$event->id],
            '',
            'householdid, timecreated, opened, reply, replied, channel, via, mailsent, phonesent, bulkid'
        );
        // How the message by phone of each family is doing, as the mail service of the phones says.
        $states = [];
        foreach (array_unique(array_filter(array_map(fn($one) => (int) $one->bulkid, $times))) as $bulkid) {
            $states += invites::phone_states($bulkid);
        }
        // Sent together (within a quarter of an hour), the time is said once, above.
        $sent = array_filter(array_map(fn($one) => (int) $one->timecreated, $times));
        $together = $sent && max($sent) - min($sent) < 900;
        $groups = [];
        foreach ($all as $key => $list) {
            $rows = [];
            foreach ($list as $id => $time) {
                if (isset($people[$id])) {
                    $when = $time ? panel::when_words($time, $now) : '';
                    $phone = isset($times[$id]) && (int) $times[$id]->phonesent ? ($states[$id] ?? []) + ['channel' => invites::phone_channel(
                        $id,
                        $states[$id] ?? null
                    )] : null;
                    $trace = $key !== 'bounced' && isset($times[$id]) ? self::trace($times[$id], $now, $together, $phone) : '';
                    $rows[] = ['name' => panel::adults_line($people[$id]->adults), 'when' => $trace !== '' ? '' : ($key === 'none' &&
                        $when !== '' ? get_string('panel_replies_sent', $c, $when) : $when), 'trace' => $trace,
                        'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id], $key === 'bounced' ?
                            'correo-devuelto' : null))->out(false)];
                }
            }
            if ($rows) {
                $groups[] = ['key' => $key, 'name' => get_string('panel_replies_' . $key, $c), 'n' => count($rows), 'rows' => $rows,
                    'open' => in_array($key, ['bounced', 'yes', 'no'], true), 'anchor' => $key === 'bounced' ? 'devueltas' : ''];
            }
        }
        return $groups ? ['groups' => $groups, 'pace' => self::pace($times),
            'went' => $together ? get_string('panel_replies_went', $c, panel::when_words(min($sent), $now)) : ''] : false;
    }

    /**
     * When the letter of a family went, by mail and by phone, and how long after it opened it and answered, from the way it
     * came by.
     *
     * @param \stdClass $one The invite: timecreated, mailsent, phonesent, opened, via, reply, replied.
     * @param int $now
     * @param bool $together All the letters went at once: the time is said above, not here.
     * @param array|null $phone How its message by phone is doing (invites::phone_states()).
     * @return string
     */
    protected static function trace(\stdClass $one, int $now, bool $together, ?array $phone = null): string {
        $c = self::C;
        if (!letters::went($one)) {
            return '';
        }
        $mail = (int) ($one->mailsent ?? 0) ?: ((string) ($one->channel ?? '') === 'phone' ? 0 : (int) $one->timecreated);
        $sms = (int) ($one->phonesent ?? 0);
        // With two ways, each says by where it went; the second, the same day, only its hour.
        $parts = $together || !$mail ? [] : [get_string($sms ? 'panel_trace_went_email' : 'panel_trace_went', $c, panel::when_words($mail, $now))];
        if ($sms) {
            $state = $phone['status'] ?? '';
            // By WhatsApp or by Telegram when it is known; by either, only when it is not.
            $channel = in_array($phone['channel'] ?? '', ['whatsapp', 'telegram'], true) ? '_' . $phone['channel'] : '';
            $at = $mail && !$together && panel::days_between($mail, $sms) === 0 ? get_string('panel_trace_at', $c, panel::hour($sms)) :
                panel::when_words($sms, $now);
            $parts[] = get_string('panel_trace_phone' . $channel, $c, $at) . (in_array(
                $state,
                ['waiting', 'delivered', 'read', 'failed', 'nowhatsapp', 'skipped'],
                true
            ) ? ' (' . get_string('panel_trace_phone_' . $state, $c) . ')' : '');
        }
        // From the way it came by, and said only when there were two.
        $via = (string) ($one->via ?? '');
        $from = $via === 'phone' && $sms ? $sms : ($mail ?: $sms);
        $by = $mail && $sms && in_array($via, ['email', 'phone'], true) ? '_' . $via : '';
        $by = $by === '_phone' && in_array($phone['channel'] ?? '', ['whatsapp', 'telegram'], true) ? '_' . $phone['channel'] : $by;
        if ((int) $one->opened >= $from && $from) {
            $parts[] = get_string('panel_trace_opened' . $by, $c, letters::lapse((int) $one->opened - $from));
        }
        if ((int) $one->replied >= $from && $from && in_array((string) $one->reply, ['yes', 'no'], true)) {
            $parts[] = get_string('panel_trace_said', $c, letters::lapse((int) $one->replied - $from));
        }
        $text = implode(' · ', $parts);
        return panel::upper_first($text);
    }

    /**
     * The letter of an act, also by phone, to the families that have it by mail and have said nothing yet: for how many, and,
     * when it is looked over, how many will get it, how many have no phone and how many cannot, which always add up; why;
     * what it costs and who approves it.
     *
     * @param \stdClass $event
     * @param bool $check
     * @return array|false
     */
    protected static function phone_card(\stdClass $event, bool $check) {
        $c = self::C;
        $centre = centres::get((int) $event->centreid);
        $too = $centre ? invites::phone_too($event, $centre) : null;
        $total = $too ? count($too['households']) + $too['waiting'] + $too['nophone'] + $too['notphone'] : 0;
        if (!$total) {
            return false;
        }
        $see = new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id, 'telefono' => 1], 'invitar-telefono');
        $out = ['title' => get_string('panel_phonetoo_title', $c),
            'text' => get_string($total === 1 ? 'panel_phonetoo_for_one' : 'panel_phonetoo_for', $c, $total),
            'url' => $check ? '' : $see->out(false), 'check' => false];
        if (!$check) {
            return $out;
        }
        $quote = invites::phone_quote($centre, $too);
        $will = $quote ? (int) ($quote['whatsapp'] ?? 0) + (int) ($quote['whatsappfree'] ?? 0) + (int) ($quote['telegram'] ?? 0) : 0;
        $cannot = $total - $will - $too['nophone'];
        $why = [];
        if ($too['nophone']) {
            $why[] = ['text' => get_string('panel_phonetoo_why_nophone', $c)];
        }
        if ($too['waiting']) {
            $why[] = ['text' => get_string('panel_phonetoo_why_waiting', $c)];
        } else if ($too['households'] && !$will) {
            // Not one of them reached: it is the messaging of the centre, never their numbers.
            $why[] = ['text' => get_string('panel_phonetoo_why_off', $c)];
        } else if ($quote && (int) ($quote['skipped'] ?? 0)) {
            $why[] = ['text' => get_string('panel_phonetoo_why_cannot', $c)];
        }
        if ($too['notphone']) {
            $key = $too['notphone'] === 1 ? 'panel_phonetoo_why_notphone_one' : 'panel_phonetoo_why_notphone';
            $why[] = ['text' => get_string($key, $c, $too['notphone'])];
        }
        $lines = [];
        if ($quote && (int) ($quote['whatsapp'] ?? 0) > 0) {
            $lines[] = ['text' => get_string((int) $quote['whatsapp'] === 1 ? 'panel_invite_cost_one' : 'panel_invite_cost', $c, (object) [
                'n' => (int) $quote['whatsapp'], 'cost' => self::money($centre, (float) ($quote['cost'] ?? 0), (string) ($quote['currency'] ?? ''))])];
        }
        if ($will) {
            $lines[] = ['text' => $quote && !empty($quote['approval']) ? get_string('panel_invite_approval', $c, implode(
                ' · ',
                array_map('strval', (array) ($quote['approvers'] ?? []))
            )) : get_string('panel_phonetoo_now', $c)];
        }
        $out['check'] = [
            'boxes' => [
                ['key' => 'go', 'n' => $will, 'label' => get_string('panel_phonetoo_box_will', $c)],
                ['key' => 'nophone', 'n' => $too['nophone'], 'label' => get_string('panel_phonetoo_box_nophone', $c)],
                ['key' => 'cannot', 'n' => $cannot, 'label' => get_string('panel_phonetoo_box_cannot', $c)],
            ],
            'why' => $why,
            'lines' => $lines,
            'note' => get_string('panel_phonetoo_note', $c),
            'go' => $will ? get_string($will === 1 ? 'panel_phonetoo_go_one' : 'panel_phonetoo_go', $c, $will) : '',
        ];
        return $out;
    }

    /**
     * How long the families took to react to the letter (to open it or to answer, the first), on a ruler from one minute to
     * a week: one dot each, and the time within which half of them did.
     *
     * @param \stdClass[] $times The invites of the act.
     * @return array|false
     */
    protected static function pace(array $times) {
        $c = self::C;
        $dots = [];
        $times = array_filter($times, [letters::class, 'went']);
        foreach ($times as $one) {
            $sent = (int) $one->timecreated;
            $first = array_filter([(int) $one->opened, (int) $one->replied], fn($t) => $t >= $sent && $t > 0);
            if ($sent && $first) {
                $dots[] = ['secs' => min($first) - $sent, 'said' => in_array((string) $one->reply, ['yes', 'no'], true)];
            }
        }
        if (count($dots) < 2) {
            return false;
        }
        usort($dots, fn($a, $b) => $a['secs'] <=> $b['secs']);
        $half = $dots[(int) ceil(count($dots) / 2) - 1]['secs'];
        $mark = "\u{1}";
        $text = s(get_string('panel_pace', $c, (object) ['n' => count($dots), 'of' => count($times), 'half' => $mark]));
        $at = fn(int $secs) => round(100 * log(max(1, $secs / 60)) / log(7 * 24 * 60), 1);
        return ['half' => str_replace($mark, '<b>' . s(letters::lapse($half)) . '</b>', $text), 'halfat' => $at($half),
            'dots' => array_map(fn($d) => ['at' => min(100, $at($d['secs'])), 'said' => $d['said']], $dots),
            'ticks' => [['at' => $at(600), 'label' => letters::lapse(600)], ['at' => $at(3600), 'label' => letters::lapse(3600)],
                ['at' => $at(86400), 'label' => get_string('panel_pace_day', $c)]],
            'legend' => get_string('panel_pace_legend', $c)];
    }

    /**
     * The page where the invitation of an open act still to come is prepared: to whom, the letter and, before it goes, how
     * many and who.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @param string $group One of invites::groups(), chosen; '' for all.
     * @param bool $check Whether it is looked over before it goes: how many and who, to confirm it.
     * @return array|false
     */
    public static function invite_page(
        \stdClass $event,
        \stdClass $centre,
        bool $canact,
        int $now = 0,
        string $group = '',
        bool $check = false
    ) {
        global $USER;
        $now = $now ?: time();
        if (!invites::fits($event, $now)) {
            return false;
        }
        $c = self::C;
        $all = invites::eligible($event);
        $can = count($all);
        $done = invites::count((int) $event->id);
        // The groups they can be told apart by: without script one at a time, and none for all of them.
        $ofcentre = invites::of_centre($event);
        $groups = invites::groups($event, $all, $ofcentre);
        $group = isset($groups[$group]) ? $group : '';
        // Those already of the centre are a list of their own, never mixed with the others.
        $centres = $group === invites::OFCENTRE;
        $here = fn(array $params) => (new \moodle_url(
            '/local/oksigeniaeguahe/event.php',
            ['id' => $event->id, 'invitar' => 1] + $params
        ))->out(false);
        // For whom the act is, already chosen: pressed by the page, so without script nothing is left out of sight.
        $for = $group === '' && !$done ? array_map('format_string', acts::audience($event)) : [];
        $rows = [];
        foreach ($centres ? ['centre'] : ['course', 'act', 'alumni', 'hobby', 'centre'] as $kind) {
            foreach ($groups as $key => $one) {
                if ($one['kind'] === $kind) {
                    $row = $one['row'] ?? get_string('panel_invite_row_' . $kind, $c);
                    $rows[$row]['name'] = $row;
                    $rows[$row]['chips'][] = ['name' => $one['short'] ?? $one['name'], 'n' => count($one['ids']),
                        'ids' => $kind === 'centre' ? '' : implode(',', $one['ids']), 'current' => $group === $key,
                        'cap' => $kind === 'alumni', 'start' => $kind === 'course' && in_array($one['name'], $for, true),
                        'key' => $group === $key ? '' : $key];
                }
            }
        }
        $shown = $group !== '' ? array_intersect_key($all + $ofcentre, array_flip($groups[$group]['ids'])) : $all;
        // Those still ticked, of the ones shown: what is counted is always this.
        $chosen = array_diff_key($shown, array_flip(invites::left_out((int) $event->id)));
        $n = count($chosen);
        // By the way each family chose: how many by email and by phone, what WhatsApp costs, and who approves it.
        $mails = fn(array $list) => count(array_filter($list, fn($h) => $h->reachemail));
        $phones = fn(array $list) => array_keys(array_filter($list, fn($h) => $h->reachphone));
        $quote = $canact ? invites::quote($centre, $phones($chosen)) : null;
        $cost = '';
        if ($quote && (int) ($quote['whatsapp'] ?? 0) > 0) {
            $cost = get_string((int) $quote['whatsapp'] === 1 ? 'panel_invite_cost_one' : 'panel_invite_cost', $c, (object) [
                'n' => (int) $quote['whatsapp'], 'cost' => self::money(
                    $centre,
                    (float) ($quote['cost'] ?? 0),
                    (string) ($quote['currency'] ?? '')
                )]);
        }
        // Who it goes to, by name, before it goes: all of them, never «and N more», and each can be left out.
        $people = households::some((int) $event->centreid, array_keys($shown));
        $whom = [];
        foreach ($shown as $id => $household) {
            if (!isset($people[$id])) {
                continue;
            }
            $courses = array_unique(array_filter(array_map(
                fn($child) => $child->stage !== 'closed' && $child->offer ? format_string($child->offer->name) : '',
                $people[$id]->children
            )));
            $name = panel::adults_line($people[$id]->adults);
            $whom[] = ['id' => (int) $id, 'name' => $name, 'checked' => isset($chosen[$id]), 'courses' => implode(' · ', $courses),
                'email' => (bool) $household->reachemail, 'phone' => (bool) $household->reachphone,
                'search' => \core_text::strtolower(implode(' ', array_merge([$name], array_map(
                    fn($child) => panel::tag($child),
                    array_values($people[$id]->children)
                ))))];
        }
        usort($whom, fn($a, $b) => strcoll(\core_text::strtolower($a['name']), \core_text::strtolower($b['name'])));
        // Looked over before it goes: how each family is greeted, what looks wrong, who gets none and why.
        $review = $check && $canact && $n ? invitecheck::of($event, $centre, $chosen, (int) $USER->id, $now) : false;
        foreach ($review ? $whom : [] as $i => $one) {
            $whom[$i]['greeting'] = $review['greetings'][$one['id']] ?? '';
        }
        $text = [get_string($can === 1 ? 'panel_invite_can_one' : 'panel_invite_can', $c, $can)];
        if ($phones($all)) {
            $text[] = get_string('panel_invite_ways', $c, (object) ['email' => $mails($all), 'phone' => count($phones($all))]);
        }
        if ($done) {
            $text[] = get_string($done === 1 ? 'panel_invite_done_one' : 'panel_invite_done', $c, $done);
        }
        // Those left out only for never saying yes to email: a person who knows they left their contact gives it here.
        $permittable = $canact ? invites::permittable($event, $centre) : [];
        $nopermission = count($permittable);
        $permittext = get_string($nopermission === 1 ? 'invite_permit_one' : 'invite_permit', $c, $nopermission);
        // Whom it is given to, by name, before it is given.
        $permitnames = [];
        foreach ($permittable as $id => [$one]) {
            $permitnames[] = ['name' => panel::adults_line(households::full($one)->adults) ?: get_string('household_noname', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $id]))->out(false)];
        }
        return self::invite_state($event, $canact, $now) + [
            'permit' => $nopermission ? ['text' => $permittext, 'names' => $permitnames] : false,
            'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $event->id]))->out(false),
                'name' => format_string($event->name)],
            'title' => get_string('panel_invite_to', $c, panel::quote(format_string($event->name))),
            'sub' => $can ? implode(' ', $text) : '',
            'none' => $can || $ofcentre ? '' : get_string('panel_invite_noone', $c),
            'rows' => array_values($rows),
            'group' => $group,
            'some' => $centres ? get_string('panel_invite_group_centre', $c, count($shown)) : ($group !== '' ? get_string(
                'panel_invite_group',
                $c,
                (object) ['n' => count($shown), 'of' => $can, 'what' => $groups[$group]['name']]
            ) : ''),
            'whom' => $whom,
            'many' => count($whom) > 12,
            'cost' => $cost,
            'approval' => $quote && !empty($quote['approval']) ? get_string('panel_invite_approval', $c, implode(
                ' · ',
                array_map('strval', (array) ($quote['approvers'] ?? []))
            )) : '',
            'count' => get_string('panel_invite_count', $c, (object) ['n' => $n, 'of' => count($shown)]),
            'goes' => get_string('panel_invite_pick', $c),
            'texts' => json_encode(self::invite_texts(), JSON_UNESCAPED_UNICODE),
            'letter' => $canact && ($can || $ofcentre) ? self::invite_letter($event, $centre, $centres ? $shown : $all, $centres) : false,
            // Before it goes: to how many, by which way and to whom, and that it is once.
            'check' => $check && $canact && $n ? [
                'question' => self::invite_confirm($n, $mails($chosen), count($phones($chosen))),
                'slow' => $mails($chosen) > invites::BATCH ? get_string('panel_invite_slow', $c, (object) [
                    'n' => (int) round(invites::BATCH * HOURSECS / invites::PAUSE), 'from' => invites::DAYTIME[0],
                    'until' => invites::DAYTIME[1]]) : '',
                'review' => $review,
                'going' => array_values(array_filter($whom, fn($w) => $w['checked'])),
                'back' => $here($group !== '' ? ['gusto' => $group] : [])] : false,
        ];
    }

    /**
     * The letter as it goes, in two halves: between them goes what the team adds, written there. The name is a gap, since
     * it is the one of whoever it goes to; the link is its words, as in the mail.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param \stdClass[] $all The families that can get it.
     * @param bool $ofcentre Whether they are already of the centre.
     * @return array subject, before, after, words, notes
     */
    protected static function invite_letter(\stdClass $event, \stdClass $centre, array $all, bool $ofcentre = false): array {
        $c = self::C;
        $lang = (string) ($centre->lang ?: 'es');
        publiclang::$self = false;
        [$subject, $html, $text] = familymail::letter(
            '[[n]]',
            $centre,
            invites::of_letter($event),
            $lang,
            '',
            '[[w]]',
            $ofcentre
        );
        [$before, $after] = explode('[[w]]', $text, 2);
        $gap = '<span class="su-nombre">' . s(get_string('panel_invite_name_here', $c)) . '</span>';
        $after = s(trim($after));
        if (!get_config($c, 'letterplain') && preg_match('~<a href="([^"]+)">(.*?)</a>~', $html, $link)) {
            // What the link says goes once: in the text alone it is said before the address.
            $words = '<span class="va-enlace">' . $link[2] . '</span>';
            $after = str_replace([$link[2] . ":\n" . $link[1], $link[1]], [$words, $words], $after);
        }
        $langs = [];
        foreach ($all as $household) {
            $its = in_array($household->lang, lists::LANGS, true) ? $household->lang : $lang;
            $langs[$its] = ($langs[$its] ?? 0) + 1;
        }
        $notes = [get_string('panel_invite_note_plain', $c)];
        if (count($langs) > 1) {
            arsort($langs);
            $names = array_map(fn($code, $n) => get_string('panel_invite_in_lang', $c, (object) ['n' => $n,
                'lang' => get_string('panel_langname_' . $code, $c)]), array_keys($langs), $langs);
            $notes[] = get_string('panel_invite_note_langs', $c, panel::and_list($names));
        }
        if (array_filter($all, fn($h) => $h->reachphone)) {
            $notes[] = get_string('panel_invite_note_phone', $c);
        }
        return ['subject' => $subject, 'before' => str_replace('[[n]]', $gap, s(trim($before))), 'after' => $after,
            'words' => (string) ($event->invitewords ?? ''), 'notes' => $notes];
    }

    /**
     * The sentences the page says again as the stamps change, with their gaps between braces.
     *
     * @return string[]
     */
    protected static function invite_texts(): array {
        $c = self::C;
        return [
            'count' => get_string('panel_invite_count', $c, (object) ['n' => '{n}', 'of' => '{of}']),
            'goes' => get_string('panel_invite_goes', $c, (object) ['n' => '{n}', 'ways' => '{ways}']),
            'goesone' => get_string('panel_invite_goes_one', $c, (object) ['ways' => '{ways}']),
            'goesnone' => get_string('panel_invite_goes_none', $c),
            'email' => get_string('panel_invite_confirm_email', $c),
            'ways' => get_string('panel_invite_confirm_ways', $c, (object) ['email' => '{email}', 'phone' => '{phone}']),
            'send' => get_string('panel_invite_send', $c, '{n}'),
            'sendone' => get_string('panel_invite_send_one', $c),
            'sendnone' => get_string('panel_invite_send_none', $c),
            'kept' => get_string('panel_invite_kept', $c),
            'notkept' => get_string('panel_invite_notkept', $c),
            'testno' => get_string('panel_invite_tested_no', $c),
        ];
    }

    /**
     * How many letters are still to go out, and when the last ones do.
     *
     * @param int $left
     * @param int $last
     * @param int $now
     * @return string
     */
    protected static function invite_left(int $left, int $last, int $now): string {
        $today = panel::days_between($last, $now) === 0;
        return get_string($today ? 'panel_invite_left' : 'panel_invite_left_day', self::C, (object) ['n' => $left,
            'hour' => panel::hour($last), 'day' => userdate($last, get_string('strftimedaydate', 'langconfig'))]);
    }

    /**
     * The question before an invitation goes: to how many families, by which way, and only once.
     *
     * @param int $n
     * @param int $emails
     * @param int $phones
     * @return string
     */
    protected static function invite_confirm(int $n, int $emails, int $phones): string {
        $c = self::C;
        $ways = $phones ? get_string('panel_invite_confirm_ways', $c, (object) ['email' => $emails, 'phone' => $phones]) :
            get_string('panel_invite_confirm_email', $c);
        return get_string($n === 1 ? 'panel_invite_confirm_one' : 'panel_invite_confirm', $c, (object) ['n' => $n,
            'ways' => $ways]);
    }

    /**
     * An amount in the currency Marandu counts in, as the language of the centre writes it, with its cents.
     *
     * @param \stdClass $centre
     * @param float $amount
     * @param string $currency
     * @return string
     */
    protected static function money(\stdClass $centre, float $amount, string $currency): string {
        if ($currency === '' || !class_exists('\NumberFormatter')) {
            return centres::money($centre, $amount, 2);
        }
        $format = new \NumberFormatter(centres::locale($centre), \NumberFormatter::CURRENCY);
        return (string) $format->formatCurrency($amount, $currency);
    }
}
