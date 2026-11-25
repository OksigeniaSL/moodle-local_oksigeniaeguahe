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
 * What a family gets after saying it comes to an act that is not a visit (a talk, a workshop, a party): plain letters, as
 * the one that invited it, to the adult and about the act. Nothing of a visit to the centre is said there: not the child,
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
        $hours = (int) $signup->timeend > (int) $signup->timestart ?
            familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang) :
            publiclang::str('mail_at_hour', $lang, $parts['hour']);
        $hours = \core_text::strtolower(\core_text::substr($hours, 0, 1)) . \core_text::substr($hours, 1);
        return [$parts['long'] . (lists::dir($lang) === 'rtl' ? '، ' : ', ') . $hours, $parts['daymonth'], $parts['hour'],
            $parts['long']];
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
     * That an act changes, in a sentence.
     *
     * @param \stdClass $event
     * @param string $lang
     * @return string
     */
    public static function changed(\stdClass $event, string $lang): string {
        return publiclang::str('mail_act_change_' . self::key($event), $lang, trim(html_entity_decode(
            format_string($event->name),
            ENT_QUOTES,
            'UTF-8'
        )));
    }

    /**
     * Where a family says it does not come after all: the page of its letter, with one touch; its own page, when that one
     * is not there (turns to choose, the sign-up closed).
     *
     * @param \stdClass $event
     * @param string $token
     * @param string $lang
     * @param string $kind The mail it is in.
     * @return string
     */
    public static function answer_url(\stdClass $event, string $token, string $lang, string $kind = ''): string {
        if ($token === '') {
            return '';
        }
        $open = !empty($event->open) && empty($event->tailored) && (int) ($event->free ?? 0) !== acts::FREE &&
            !events::slots((int) $event->id);
        return $open ? (new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token, 'k' => $token, 'idioma' => $lang] +
            ($kind !== '' ? ['de' => $kind] : [])))->out(false) :
            (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false) . '#no-podemos-' .
            (int) $event->id;
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
        string $text = ''
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $plain = fn(string $s) => trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
        $key = self::key($event);
        $name = $plain(format_string($event->name));
        [$when, $day, $hour, $date] = self::when($signup, $lang);
        // Each is a paragraph: lines of words, and links [words, address].
        $first = familymail::first_name($household);
        $hello = $first !== '' ? $str('mail_letter_hello', $first) : $str('mail_letter_hello_noname');
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', trim($text)))));
        $links = [];
        $calendar = $token !== '' ? (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token, 'e' => $event->id,
            'de' => $kind]))->out(false) : '';
        if ($calendar !== '') {
            $links[] = [$str('pub_add_calendar'), $calendar];
        }
        $address = trim((string) ($centre->address ?? ''));
        if ($address !== '') {
            $links[] = [$str('pub_how_get'), centres::map_url($centre)];
        }
        $answer = self::answer_url($event, $token, $lang, $kind);
        $cant = $answer !== '' ? [[$str('mail_act_cantcome'), [[$str('mail_cant_come_go'), $answer]]]] : [];
        $body = [];
        if ($kind === 'confirmation') {
            $body[] = [$hello, []];
            $body[] = [self::saved($event, $lang), []];
            $body[] = [implode("\n", array_merge(self::lines($event, $signup, $centre, $lang), [publiclang::count(
                'pub_people_one',
                'pub_people_count',
                max(1, (int) $signup->people),
                $lang
            ) . '.'])), []];
            $body[] = ['', $links];
            $body = array_merge($body, $cant);
        } else if ($kind === 'reminder') {
            $body[] = [$hello, []];
            $body[] = [implode(' ', array_filter([$str('mail_act_tomorrow_' . $key, (object) ['when' => $when, 'event' => $name]),
                acts::letter_place($event, $centre, $lang)])), []];
            $body[] = ['', array_slice($links, 1)];
            $body = array_merge($body, $cant);
        } else if ($kind === 'change') {
            // As the team read it before sending it (panel_eventform::message()); whole here when there is none.
            $lines = implode("\n", self::lines($event, $signup, $centre, $lang));
            foreach ($paragraphs ?: [$hello, self::changed($event, $lang), $lines] as $paragraph) {
                $body[] = [$paragraph, []];
            }
            $body[] = ['', $links];
            $body = array_merge($body, $cant);
        } else if ($kind === 'cancelled') {
            $body[] = [$hello, []];
            $body[] = [$str('mail_act_off_' . $key, (object) ['event' => $name, 'date' => $date]), []];
            foreach ($paragraphs as $paragraph) {
                $body[] = [$paragraph, []];
            }
        } else {
            $body[] = [$hello, []];
            $body[] = [$str('mail_act_thanks_' . $key, $name), []];
            if ($token !== '') {
                $body[] = [$str('mail_act_survey'), [[$str('mail_act_survey_go'), (new \moodle_url(
                    '/local/oksigeniaeguahe/f.php',
                    ['k' => $token, 'idioma' => $lang, 'de' => $kind]
                ))->out(false) . '#como-fue']]];
            }
        }
        $body[] = [implode("\n", array_filter([$str('mail_letter_bye'), $plain(format_string($centre->name)),
            trim((string) ($centre->phone ?? ''))], fn($l) => trim($l) !== '')), []];
        // Why it reaches them, how to change the ways and what is done with their data: small, at the end.
        $foot = [];
        if ($token !== '') {
            $foot[] = [$str('mail_act_ways'), writeby::url($token, $lang, '', $kind)];
        }
        $foot[] = [$str('pub_privacy_link'), publicpage::privacy_url($centre, $lang)->out(false)];
        $why = $str('mail_act_why_' . $key, $name);

        $lines = [];
        $html = '';
        $p = fn(string $inside, string $style = '') => '<p style="margin:0 0 1em' . $style . '">' . $inside . '</p>';
        $a = fn(array $link) => '<a href="' . s($link[1]) . '">' . s($link[0]) . '</a>';
        foreach ($body as [$words, $under]) {
            if ($words === '' && !$under) {
                continue;
            }
            $lines[] = trim($words . ($under ? ($words !== '' ? "\n" : '') . implode("\n", array_map(
                fn($l) => $l[0] . ': ' . $l[1],
                $under
            )) : ''));
            $html .= $p(trim(nl2br(s($words), false) . ' ' . implode(' · ', array_map($a, $under))));
        }
        $lines[] = $why . "\n" . implode("\n", array_map(fn($l) => $l[0] . ': ' . $l[1], $foot));
        $html .= $p(s($why) . '<br>' . implode(' · ', array_map($a, $foot)), ';font-size:12.5px;color:#5f5459');
        $subject = self::subject($kind, $event, $lang, ['day' => $day, 'hour' => $hour, 'event' => $name]);
        return [$subject, '<!DOCTYPE html><html lang="' . ($lang === 'pt_br' ? 'pt-BR' : s($lang)) . '" dir="' . lists::dir($lang) .
            '"><head><meta charset="utf-8"><title>' . s($subject) . '</title></head><body><div dir="' . lists::dir($lang) .
            '" style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5">' . $html . '</div></body></html>',
            implode("\n\n", $lines) . "\n", []];
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
