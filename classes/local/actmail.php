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
 * What a family gets after saying it comes to an act that is not a visit (a talk, a workshop, a party): letters in the
 * common mail, as the one that invited it, to the adult and about the act. Nothing of a visit to the centre is said there: not the child,
 * nor its backpack, nor who receives it. The confirmation, the reminder, a change, that it will not be held and, only when
 * the team asks how it went, the thanks.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actmail {
    /** @var string[] The mails that are letters of an act, when they are about one. */
    const KINDS = ['confirmation', 'reminder', 'change', 'cancelled', 'thanks'];

    /**
     * Whether a sign-up is to an act that is not a visit.
     *
     * @param \stdClass|null $signup As households::full() gives it.
     * @return bool
     */
    public static function is(?\stdClass $signup): bool {
        return $signup !== null && (string) ($signup->kind ?? '') === 'other';
    }

    /**
     * The act a mail is about, when it goes as a letter of an act.
     *
     * @param string $kind A kind of familymail.
     * @param \stdClass|null $signup
     * @param \stdClass $centre
     * @return \stdClass|null
     */
    public static function act(string $kind, ?\stdClass $signup, \stdClass $centre): ?\stdClass {
        if (!in_array($kind, self::KINDS, true) || !$signup || empty($signup->eventid)) {
            return null;
        }
        $event = events::get((int) $signup->eventid, (int) $centre->id);
        return $event && $event->kind === 'other' ? $event : null;
    }

    /**
     * The end of the string for an act, by its word: talk, workshop or party; own, for any other.
     *
     * @param \stdClass $event
     * @return string
     */
    protected static function key(\stdClass $event): string {
        $word = trim((string) ($event->word ?? ''));
        return in_array($word, acts::WORDS, true) ? $word : 'own';
    }

    /**
     * When it is: the day, and from what time to what time.
     *
     * @param \stdClass $signup
     * @param string $lang
     * @return array [the day and its hours, the day, the hour it starts]
     */
    protected static function when(\stdClass $signup, string $lang): array {
        $parts = publicpage::date_parts((int) $signup->timestart, $lang);
        $hours = self::hours($signup, $lang);
        return [$parts['long'] . (lists::dir($lang) === 'rtl' ? '، ' : ', ') . $hours, $parts['daymonth'], $parts['hour'],
            $parts['long']];
    }

    /**
     * The hours of an act alone: «de 15:00 a 16:30», or «a las 15:00».
     *
     * @param \stdClass $signup
     * @param string $lang
     * @return string
     */
    protected static function hours(\stdClass $signup, string $lang): string {
        $hours = (int) $signup->timeend > (int) $signup->timestart ?
            familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang) :
            publiclang::str('mail_at_hour', $lang, publicpage::date_parts((int) $signup->timestart, $lang)['hour']);
        return \core_text::strtolower(\core_text::substr($hours, 0, 1)) . \core_text::substr($hours, 1);
    }

    /**
     * When and where an act is now, in two lines: what a change leaves, and what the confirmation says.
     *
     * @param \stdClass $event
     * @param \stdClass $signup
     * @param \stdClass $centre
     * @param string $lang
     * @return string[]
     */
    public static function lines(\stdClass $event, \stdClass $signup, \stdClass $centre, string $lang): array {
        $when = self::when($signup, $lang)[0];
        return array_values(array_filter([
            \core_text::strtoupper(\core_text::substr($when, 0, 1)) . \core_text::substr($when, 1) . '.',
            acts::letter_place($event, $centre, $lang),
        ], fn($l) => trim($l) !== ''));
    }

    /**
     * That they have a place in an act, in a sentence.
     *
     * @param \stdClass $event
     * @param string $lang
     * @return string
     */
    public static function saved(\stdClass $event, string $lang): string {
        return publiclang::str('mail_act_saved_' . self::key($event), $lang, trim(html_entity_decode(
            format_string($event->name),
            ENT_QUOTES,
            'UTF-8'
        )));
    }

    /**
     * What changed of an act: its day, its hour (the same day), its place.
     *
     * @param \stdClass $signup As it is now.
     * @param \stdClass|null $was As it was (timestart, timeend, place); null when it is not known.
     * @return string[] Some of day, hour and place.
     */
    public static function what_changed(\stdClass $signup, ?\stdClass $was): array {
        if (!$was) {
            return [];
        }
        $out = [];
        $day = fn($t) => userdate((int) $t, '%Y-%m-%d', 99, false);
        if ((int) $was->timestart !== (int) $signup->timestart || (int) $was->timeend !== (int) $signup->timeend) {
            $out[] = $day($was->timestart) !== $day($signup->timestart) ? 'day' : 'hour';
        }
        if (trim((string) ($was->place ?? '')) !== trim((string) ($signup->place ?? ''))) {
            $out[] = 'place';
        }
        return $out;
    }

    /**
     * The title of a change, by what changed: «Cambia la hora: …», «Cambia el día: …», «Cambia el sitio: …», or «Hay
     * cambios: …» when it is more than one or not known.
     *
     * @param \stdClass $event
     * @param \stdClass $signup
     * @param \stdClass|null $was
     * @param string $lang
     * @return string
     */
    public static function change_heading(\stdClass $event, \stdClass $signup, ?\stdClass $was, string $lang): string {
        $changed = self::what_changed($signup, $was);
        return publiclang::str(
            'mail_act_change_heading_' . (count($changed) === 1 ? $changed[0] : 'many'),
            $lang,
            trim(html_entity_decode(format_string($event->name), ENT_QUOTES, 'UTF-8'))
        );
    }

    /**
     * The data of an act at a glance: when, where and how many people come («places» would read as a place at the
     * centre); under what changed, how it was.
     *
     * @param \stdClass $event
     * @param \stdClass $signup
     * @param \stdClass $centre
     * @param string $lang
     * @param \stdClass|null $was As it was, in a change.
     * @return array[] label and value.
     */
    public static function rows(\stdClass $event, \stdClass $signup, \stdClass $centre, string $lang, ?\stdClass $was = null): array {
        $str = fn(string $id) => publiclang::str($id, $lang);
        $cap = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $changed = self::what_changed($signup, $was);
        $rows = [['label' => $str('mail_act_row_when'), 'value' => $cap(self::when($signup, $lang)[0])]];
        if (array_intersect($changed, ['day', 'hour'])) {
            // Under it, only what changed: the hours of the same day, the day at the same hours, or both.
            $hours = self::hours($was, $lang);
            $day = publicpage::date_parts((int) $was->timestart, $lang)['long'];
            $before = in_array('hour', $changed, true) ? $hours : (self::hours($signup, $lang) === $hours ? $day :
                self::when($was, $lang)[0]);
            $rows[] = ['label' => $str('mail_act_row_before'), 'value' => $cap($before)];
        }
        $where = acts::where($event, $centre);
        if ($where !== '') {
            $rows[] = ['label' => $str('mail_act_row_where'), 'value' => $where];
        }
        if (in_array('place', $changed, true)) {
            $before = acts::where((object) ['place' => (string) ($was->place ?? '')], $centre);
            if ($before !== '' && $before !== $where) {
                $rows[] = ['label' => $str('mail_act_row_before'), 'value' => $before];
            }
        }
        if (empty($signup->invited) && (int) ($signup->people ?? 0) > 0) {
            $rows[] = ['label' => $str('mail_act_row_people'), 'value' => (string) (int) $signup->people];
        }
        return $rows;
    }

    /**
     * Where a family says it does not come after all: the page of its letter, with one touch; its own page, when that one
     * is not there (turns to choose, the sign-up closed).
     *
     * @param \stdClass $event
     * @param string $token
     * @param string $lang
     * @param string $kind The mail it is in.
     * @param bool $no Whether the link says they do not come: its page asks only that, with the act in sight.
     * @return string
     */
    public static function answer_url(
        \stdClass $event,
        string $token,
        string $lang,
        string $kind = '',
        bool $no = false
    ): string {
        if ($token === '') {
            return '';
        }
        $open = !empty($event->open) && empty($event->tailored) && (int) ($event->free ?? 0) !== acts::FREE &&
            !events::slots((int) $event->id);
        return $open ? (new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token, 'k' => $token, 'idioma' => $lang] +
            ($kind !== '' ? ['de' => $kind] : []) + ($no ? ['no' => 1] : [])))->out(false) :
            (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang, 'action' => 'nopodemos',
                'acto' => (int) $event->id]))->out(false);
    }

    /**
     * A letter of an act.
     *
     * @param string $kind One of KINDS.
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $event
     * @param \stdClass $signup
     * @param string $lang
     * @param string $token A link of the family; empty, none.
     * @param string $text What the team writes, for a change or when it is called off.
     * @param string $to The address it goes to, when it is not the usual one.
     * @return array [subject, html, text, images]
     */
    public static function build(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        \stdClass $event,
        \stdClass $signup,
        string $lang,
        string $token,
        string $text = '',
        string $to = ''
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $plain = fn(string $s) => trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
        $name = $plain(format_string($event->name));
        [, $day, $hour, $date] = self::when($signup, $lang);
        $first = familymail::first_name($household, $to);
        $invited = !empty($signup->invited);
        // What the team adds, for a change or when it is called off: its own paragraphs, after the data.
        $words = array_map(fn($p) => ['paragraph' => $p], array_values(array_filter(array_map(
            'trim',
            preg_split('/\R{2,}/', trim($text))
        ))));
        $calendar = $token !== '' && !$invited ? (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token,
            'e' => $event->id, 'de' => $kind]))->out(false) : '';
        $map = trim((string) ($centre->address ?? '')) !== '' ? centres::map_url($centre) : '';
        $survey = $token !== '' ? (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang,
            'de' => $kind]))->out(false) . '#como-fue' : '';
        $subject = self::subject($kind, $event, $lang, ['day' => $day, 'hour' => $hour, 'event' => $name]);

        // The title says the news and the act, without the date, which goes in the data; the first line does not repeat it.
        $blocks = [];
        $button = false;
        $links = [];
        if ($kind === 'confirmation') {
            $heading = $str('mail_act_confirm_heading', $name);
            $blocks[] = ['paragraph' => $str('mail_act_confirm_lead')];
            $blocks[] = ['data' => self::rows($event, $signup, $centre, $lang)];
            $button = $calendar !== '' ? [$str('pub_add_calendar'), $calendar] : false;
            $links[] = $map !== '' ? [$str('pub_how_get'), $map] : null;
        } else if ($kind === 'reminder') {
            $heading = $str('mail_act_remind_heading', $name);
            $blocks[] = ['paragraph' => $str('mail_act_remind_lead')];
            $blocks[] = ['data' => self::rows($event, $signup, $centre, $lang)];
            $button = $map !== '' ? [$str('pub_how_get'), $map] : false;
        } else if ($kind === 'change') {
            $was = isset($signup->was) && is_object($signup->was) ? $signup->was : null;
            $heading = self::change_heading($event, $signup, $was, $lang);
            $blocks[] = ['paragraph' => $str('mail_act_change_lead')];
            $blocks[] = ['data' => self::rows($event, $signup, $centre, $lang, $was)];
            $blocks = array_merge($blocks, $words);
            // The calendar file keeps its UID and a higher SEQUENCE: it changes the date already saved, it does not add one.
            $button = $calendar !== '' ? [$str('mail_act_change_button'), $calendar] : false;
            $links[] = $map !== '' ? [$str('pub_how_get'), $map] : null;
        } else if ($kind === 'cancelled') {
            $heading = $str('mail_act_off_heading', $name);
            $blocks[] = ['paragraph' => $str('mail_act_off_lead', $date)];
            $blocks = array_merge($blocks, $words);
        } else {
            $heading = $subject;
            $blocks[] = ['paragraph' => $str('mail_act_thanks_lead', $name)];
            $button = $survey !== '' ? [$str('mail_act_survey_button'), $survey] : false;
        }
        if ($button) {
            $blocks[] = ['action' => ['actionurl' => $button[1], 'actiontext' => $button[0]]];
        }
        foreach (array_filter($links) as $link) {
            $blocks[] = ['link' => ['linkurl' => $link[1], 'linktext' => $link[0]]];
        }
        // Saying they do not come, in one line with its words; one that only has its letter says there whether it comes.
        $answer = in_array($kind, ['confirmation', 'reminder', 'change'], true) ? self::answer_url(
            $event,
            $token,
            $lang,
            $kind,
            !$invited
        ) : '';
        if ($answer !== '') {
            $blocks[] = ['link' => ['linkbefore' => $str($invited ? 'mail_act_invited_answer' : 'mail_act_cantcome'),
                'linkurl' => $answer, 'linktext' => $str($invited ? 'mail_act_invited_go' : 'mail_cant_come_go')]];
        }
        // How they are written to, as a sentence of the centre: the ways it has besides the email.
        if ($token !== '') {
            $phones = array_values(array_diff(writeby::channels($centre), ['email']));
            $by = array_map(fn($c) => $str('pub_writeby_by_' . $c), $phones);
            $better = count($by) > 1 ? $str('mail_act_or2', (object) ['a' => $by[0], 'b' => $by[1]]) : ($by[0] ?? '');
            $blocks[] = ['link' => ['linkbefore' => $better !== '' ? $str('mail_act_ways_better', $better) : '',
                'linkurl' => writeby::url($token, $lang, '', $kind), 'linktext' => $str('mail_act_ways')]];
        }
        // Why it reaches them: after it, because they came; one that only has its letter, because of it.
        $key = self::key($event);
        $why = $kind === 'thanks' ? $str('mail_act_why_came', $name) : ($invited ? $str('mail_act_why_invited', $name) :
            $str('mail_act_why_' . $key, $name));
        // Signed by who receives them: the one who will open the door. If nobody, the centre.
        $ids = array_filter(array_map('intval', explode(',', (string) ($event->hosts ?? ''))));
        $host = $ids ? (centres::hosts((int) $centre->id, $ids)[0] ?? null) : null;
        $whatsapp = centres::whatsapp($centre, $lang);
        $phone = familymail::ltr(phone::display(trim((string) ($centre->phone ?? ''))), $lang);
        [$html, $text, $images] = commonmail::make($centre, $lang, [
            'subject' => $subject,
            'preheader' => (string) ($blocks[0]['paragraph'] ?? ''),
            'heading' => $heading,
            'hello' => $first !== '' ? $str('mail_letter_hello', $first) : $str('mail_letter_hello_noname'),
            'blocks' => $blocks,
            'signature' => ['signoff' => $str('mail_letter_bye'), 'signer' => $host ? $host->name : $plain(format_string($centre->name)),
                'signrole' => $host ? (string) $host->role : ''],
            'contact' => implode(' · ', array_filter([$phone, $whatsapp && !$whatsapp['same'] ? 'WhatsApp ' .
                familymail::ltr($whatsapp['text'], $lang) : ''])),
            'reason' => $why,
        ]);
        return [$subject, $html, $text, $images];
    }

    /**
     * The subject of a letter: what happens, what the act is and its day.
     *
     * @param string $kind
     * @param \stdClass $event
     * @param string $lang
     * @param array $a day, hour and event.
     * @return string
     */
    protected static function subject(string $kind, \stdClass $event, string $lang, array $a): string {
        if ($kind === 'thanks') {
            return publiclang::str('mail_act_thanks_subject', $lang);
        }
        $word = html_entity_decode(acts::word($event, $lang), ENT_QUOTES, 'UTF-8');
        $lower = preg_match('/^\p{Lu}\p{Ll}/u', $word) ? \core_text::strtolower(\core_text::substr($word, 0, 1)) .
            \core_text::substr($word, 1) : $word;
        $id = ['confirmation' => 'mail_act_confirm_subject', 'reminder' => 'mail_act_remind_subject',
            'change' => 'mail_act_change_subject', 'cancelled' => 'mail_act_off_subject'][$kind];
        return publiclang::str($id . ($word === '' ? '_own' : ''), $lang, (object) ($a + ['word' => $lower,
            'when' => publiclang::str('mail_at_hour', $lang, $a['hour']), 'what' => acts::short_topic($event)]));
    }
}
