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
 * The page of a sign-up, all of it on one screen: on one side what it is (the poster of the act, or the centre), its name,
 * the day, the hour and the place, once; on the other, the form (brief). Whoever comes by the letter of an invitation is
 * already known: they only say whether they come.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class joinpage {
    /**
     * The context of the template public_join.
     *
     * @param \stdClass $event An act, or a form as forms::as_event() gives it.
     * @param \stdClass $centre
     * @param string $lang
     * @param array $data
     * @param array $errors
     * @param \moodle_url $url
     * @param string $mark
     * @param array|false $known self::known(), for whoever comes by a letter.
     * @return array
     */
    public static function context(
        \stdClass $event,
        \stdClass $centre,
        string $lang,
        array $data,
        array $errors,
        \moodle_url $url,
        string $mark,
        $known = false
    ): array {
        $isform = $event->kind === 'form';
        $form = $isform ? entries::get((int) $event->formid, (int) $centre->id) : null;
        $act = $isform ? ['poster' => false, 'about' => '', 'speakers' => [], 'hasspeakers' => false, 'speakerstitle' => '',
            'audience' => '', 'free' => false, 'freetext' => '', 'asks' => false] : acts::context($event, $lang);
        $name = format_string($event->name);
        $own = $event->kind === 'other';
        $headline = $isform ? (trim((string) ($form->title ?? '')) !== '' ? format_string($form->title) :
            publiclang::str('pub_form_title', $lang)) : ($own ? $name : publiclang::str('pub_come_meet', $lang));
        $ids = array_filter(array_map('intval', explode(',', (string) ($event->hosts ?? ''))));
        $hosts = $isform ? centres::answerers($centre, null) : ($ids ? centres::hosts((int) $centre->id, $ids) : []);
        $firsts = array_map(fn($h) => s($h->first), $hosts);
        $when = false;
        if (!$isform && empty($event->tailored)) {
            $parts = publicpage::date_parts((int) $event->timestart, $lang);
            $when = ['weekday' => $parts['weekday'], 'daymonth' => $parts['daymonth'], 'long' => $parts['long'],
                'hours' => familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
                // The leaf of the calendar.
                'day' => (string) (int) userdate((int) $event->timestart, '%d', 99, false),
                'month' => publicpage::month_names($lang, (string) ($centre->country ?? ''))[(int) userdate((int) $event->timestart, '%m', 99, false)] ?? ''];
        }
        $child = $data['children'][0] ?? ['firstname' => ''];
        // A child hangs on the rail, with the name written as it is typed; nothing hangs of a talk or a party, nor
        // beside a poster.
        $pegs = !$own && !$act['poster'] && empty($data['self']) && !$known ? publicpage::pegs([familypage::label_name((object) [
            'firstname' => (string) $child['firstname'], 'tagname' => ''])], false, 1) + ['live' => true] : false;
        $leaf = $when && !$pegs && !$act['poster'];
        return publicpage::strings($lang, ['pub_if_you_wish', 'pub_drawing_close']) + [
            'kicker' => $isform ? '' : ($own ? acts::word($event, $lang) : $name),
            'headline' => $headline,
            'longname' => \core_text::strlen($headline) > 38,
            'lead' => $isform ? (trim((string) ($form->lead ?? '')) !== '' ? format_string($form->lead) :
                publiclang::str('pub_form_lead', $lang)) : (empty($event->tailored) ? '' : publiclang::str('pub_when_suits_hint', $lang)),
            'when' => $when,
            'where' => $isform ? '' : implode(', ', array_filter([$event->place ? format_string($event->place) : '',
                !empty($centre->address) ? format_string($centre->address) : ''])),
            'poster' => $act['poster'],
            'about' => $act['about'],
            'speakers' => $act['speakers'],
            'hasspeakers' => $act['hasspeakers'],
            'speakerstitle' => $act['speakerstitle'],
            'audience' => $act['audience'],
            'free' => $act['free'],
            'asks' => $act['asks'],
            'freetext' => $act['freetext'],
            'hosts' => familypage::hosts($hosts),
            'hashosts' => (bool) $hosts,
            'willreceive' => $hosts ? publiclang::str(
                ($isform ? 'pub_will_answer' : 'pub_will_receive') . (count($hosts) > 1 ? '_many' : '_one'),
                $lang,
                familypage::join($firsts, $lang)
            ) : '',
            'hasmore' => $act['about'] !== '' || $act['hasspeakers'] || $act['audience'] !== '' || $hosts,
            'pegs' => $pegs,
            // Without a poster and with nothing on the rail, the leaf of the day, on the same band.
            'leaf' => $leaf,
            'band' => $leaf || $act['poster'],
            'bare' => false,
            'readmore' => publiclang::str('pub_join_readmore', $lang),
            'known' => $known,
            // Who signs up, at a centre where either the family or the student may: each a link, the form in its own voice.
            'choose' => !$own && !$known && ($centre->whoapplies ?? 'family') === 'both' ? [
                'label' => publiclang::str('pub_for_whom', $lang), 'self' => !empty($data['self']),
                'child' => publiclang::str('pub_for_child', $lang), 'me' => publiclang::str('pub_for_me', $lang),
                'childurl' => (new \moodle_url($url, ['para' => 'familia', 'idioma' => $lang]))->out(false),
                'meurl' => (new \moodle_url($url, ['para' => 'yo', 'idioma' => $lang]))->out(false),
            ] : false,
            'form' => brief::context($event, $centre, $lang, $data, $errors, new \moodle_url($url, ['idioma' => $lang]), $mark, null, true),
        ];
    }

    /**
     * What whoever comes by the letter of an invitation sees: their name and whether they come, with what they said last.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param string $lang
     * @param \moodle_url $url
     * @param string $mark
     * @return array
     */
    public static function known(
        \stdClass $event,
        \stdClass $centre,
        \stdClass $household,
        string $lang,
        \moodle_url $url,
        string $mark
    ): array {
        global $DB;
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $first = s(familymail::first_name($household));
        $answer = invites::answer((int) $event->id, (int) $household->id);
        $people = (int) $DB->get_field(events::SIGNUPS, 'people', ['eventid' => $event->id, 'householdid' => $household->id]);
        // Signed up before by another way: they come.
        $answer = $answer === '' && $people ? 'yes' : $answer;
        $now = writeby::now($household, $centre);
        $admission = self::in_admission($household);
        $lastway = $str($admission ? 'pub_known_lastway' : 'pub_known_lastway_other');
        $ways = [];
        foreach (writeby::channels($centre) as $way) {
            $ways[] = ['name' => 'by[]', 'value' => $way, 'label' => $str('pub_writeby_' . $way), 'id' => 'by-' . $way,
                'checked' => in_array($way, $now, true), 'icon' => writeby::icon($way)];
        }
        return [
            'first' => $first,
            'hello' => $first !== '' ? $str('pub_known_hello', $first) : $str('pub_known_hello_noname'),
            'ask' => $str('pub_known_ask'),
            'answer' => $answer,
            'yes' => ['name' => 'reply', 'value' => 'yes', 'label' => $str('pub_known_yes'), 'current' => $answer === 'yes'],
            'no' => ['name' => 'reply', 'value' => 'no', 'label' => $str('pub_known_no'), 'current' => $answer === 'no'],
            'coming' => $answer === 'yes',
            // Whether they come or not, how they are written to; how many, only if they come.
            'answered' => $answer !== '',
            'now' => $now,
            'done' => $answer === '' ? '' : $str('pub_known_done_' . $answer),
            'people' => ['name' => 'people', 'label' => $str('pub_join_people'), 'value' => $people ?: 1, 'min' => 1,
                'max' => events::MAXPEOPLE, 'less' => $str('pub_join_less'), 'more' => $str('pub_join_more')],
            'ways' => $ways,
            'hasways' => count($ways) > 1,
            // What the ways are for: the admission, for whoever is in it (the letter they come from was of an event).
            'waystitle' => $str($admission ? 'pub_known_ways_admission' : 'pub_known_ways'),
            'several' => $str('pub_known_several'),
            'lastway' => $lastway,
            'told' => optional_param('una', 0, PARAM_BOOL) ? $lastway : '',
            'noinvites' => (bool) (int) $household->noinvites,
            'noinvitestext' => $str('pub_noinvites'),
            'missing' => self::missing($household, $centre, $lang, $now),
            'ask' => writeby::askable($household, $centre),
            'save' => $str('pub_known_save'),
            'saved' => $str('pub_home_saved'),
            'notsaved' => $str('pub_known_notsaved'),
            'full' => '',
            'other' => $first !== '' ? $str('pub_known_other', $first) : $str('pub_known_other_noname'),
            'otherurl' => (new \moodle_url($url, ['otra' => 1, 'idioma' => $lang]))->out(false),
            'action' => (new \moodle_url($url, ['idioma' => $lang]))->out(false),
            'mark' => $mark,
        ];
    }

    /**
     * The phones they marked and have not joined yet: each with its button (at a computer, its code for the phone), and
     * that until then they are written to by email. Saying «kept» without it would promise what does not reach them.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string[] $now The ways they are written to by now.
     * @return array
     */
    protected static function missing(\stdClass $household, \stdClass $centre, string $lang, array $now): array {
        $rows = [];
        // On WhatsApp, one touch: being asked right now (formulario.js waits for it) or asked already; its button is then for
        // whoever does not get the message.
        $state = in_array('whatsapp', $now, true) ? writeby::whatsapp($household, $centre) : ['asked' => 0, 'ends' => ''];
        $asking = optional_param('ajax', 0, PARAM_BOOL) && !optional_param('opened', 0, PARAM_BOOL) &&
            !optional_param('ask', 0, PARAM_BOOL) && writeby::askable($household, $centre);
        foreach (writeby::join_links($household, $centre, $now) as $channel => $link) {
            if ($channel === 'whatsapp' && $asking) {
                $rows[] = ['asking' => publiclang::str('pub_known_asking', $lang)];
                continue;
            }
            $asked = $channel === 'whatsapp' && $state['asked'];
            $rows[] = [
                'asking' => '',
                'asked' => $asked,
                // What to do and what for, in words: «join» alone says neither.
                'label' => $asked ? publiclang::str('pub_known_asked', $lang) :
                    publiclang::str('pub_known_step', $lang, publiclang::str('pub_writeby_' . $channel, $lang)),
                'text' => $asked ? publiclang::str('pub_known_asked_text', $lang, $state['ends']) :
                    publiclang::str('pub_known_step_' . $channel, $lang),
                'planb' => $asked ? publiclang::str('pub_known_asked_planb', $lang) : '',
                'url' => $link,
                'icon' => writeby::icon($channel),
                'open' => publiclang::str('pub_writeby_open_' . $channel, $lang),
                'qr' => events::qr_of($link),
                'qrline' => publiclang::str('pub_writeby_qr_' . $channel, $lang),
            ];
        }
        return ['rows' => $rows, 'hasrows' => (bool) $rows, 'until' => $rows && !in_array('email', $now, true) ?
            publiclang::str('pub_writeby_until_joined', $lang) : ''];
    }

    /**
     * What whoever comes by a letter pressed: whether they come, how many and how to write to them, each kept by itself.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param \stdClass $household
     * @return bool Whether it was kept; false when there is no room left.
     */
    public static function said(\stdClass $event, \stdClass $centre, \stdClass $household): bool {
        $reply = optional_param('reply', '', PARAM_ALPHA);
        $answer = invites::answer((int) $event->id, (int) $household->id);
        $people = optional_param('people', 0, PARAM_INT);
        $kept = true;
        if (in_array($reply, ['yes', 'no'], true)) {
            // The touch is enough: how many is asked afterwards, and it is not of the button.
            $kept = invites::reply($event, $household, $reply, $reply === 'yes' && $answer === 'yes' ? $people : 0);
        } else if ($people && $answer === 'yes') {
            $kept = invites::reply($event, $household, 'yes', $people);
        }
        // How they want to be written to, for everything: never «none» by leaving it empty here (last_way()).
        $ways = array_values(array_intersect(writeby::channels($centre), optional_param_array('by', [], PARAM_ALPHA)));
        if ($ways && optional_param('ways', 0, PARAM_BOOL) && $ways !== writeby::now($household, $centre)) {
            writeby::set($household, $ways, 'family', 0, 0);
        }
        if (optional_param('evt', 0, PARAM_BOOL)) {
            invites::wanted($household, !optional_param('noinvites', 0, PARAM_BOOL), 'family');
        }
        return $kept;
    }

    /**
     * Whether a family is asking for a place: not one that is only of the centre, nor only of an act.
     *
     * @param \stdClass $household
     * @return bool
     */
    protected static function in_admission(\stdClass $household): bool {
        global $DB;
        if (!empty($household->roster) || !empty($household->guest)) {
            return false;
        }
        $stages = $DB->get_fieldset_select(households::CHILDREN, 'stage', 'householdid = ?', [$household->id]);
        return !$stages || (bool) array_diff($stages, ['enrolled', 'closed']);
    }

    /**
     * Whether they took out the last way they are written to by: it stays, and the page says so, never that it was kept.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @return bool
     */
    public static function last_way(\stdClass $centre, \stdClass $household): bool {
        return optional_param('ways', 0, PARAM_BOOL) && !optional_param_array('by', [], PARAM_ALPHA) &&
            writeby::now($household, $centre);
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
