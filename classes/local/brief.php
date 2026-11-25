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
 * The form of a sign-up, or of writing to the centre: what is needed to answer and nothing else, all of it in sight. The
 * same inside the web of a centre (w.php and j.php with breve=1: no wall, no drawings and no scroll, for the window the web
 * opens) and on the whole page (joinpage), which also asks the questions the centre wrote. The rest (what they call the
 * child at home, the birthday, where they live, how they heard of the centre) is told later, if they wish, on the page of
 * the family.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class brief {
    /** @var int A form sent sooner than this after its page was given is not of a person, in seconds. */
    const SOONEST = 2;

    /** @var int How many forms the same address of the internet sends in an hour. */
    const PERHOUR = 20;

    /** @var string What a person does not see and a machine fills. */
    const TRAP = 'web2';

    /** @var int More options than this do not fit as buttons. */
    const OPTIONS = 5;

    /**
     * The question of the form shown as buttons, above everything: the first that picks one of a few and depends on no
     * other.
     *
     * @param int $formid
     * @param string $fallback The language of the centre.
     * @return \stdClass|null
     */
    public static function question(int $formid, string $fallback): ?\stdClass {
        foreach (forms::fields($formid) as $field) {
            $n = count(forms::texts($field, $fallback, $fallback)['baseoptions']);
            if ($field->type === 'choice' && !$field->showif && $n > 1 && $n <= self::OPTIONS) {
                return $field;
            }
        }
        return null;
    }

    /**
     * The option of a question that the web asked for by a word of its own («un-dia» is «Un día de clase»).
     *
     * @param \stdClass $field
     * @param string $word
     * @param string $fallback
     * @return string Its value; empty when none is.
     */
    public static function option(\stdClass $field, string $word, string $fallback): string {
        $word = self::slug($word);
        if ($word === '') {
            return '';
        }
        $base = forms::texts($field, $fallback, $fallback)['baseoptions'];
        foreach (self::words($field, $fallback) as $labels) {
            foreach ($labels as $i => $label) {
                // By how it starts, or by any of its words: «videollamada» is «Por videollamada».
                if (strpos('-' . $label . '-', '-' . $word) !== false) {
                    return $base[$i] ?? '';
                }
            }
        }
        return '';
    }

    /**
     * The options of a question as the web may name them, in each language.
     *
     * @param \stdClass $field
     * @param string $fallback
     * @return array[] By language, in the order they are looked at: the option as a slug.
     */
    public static function words(\stdClass $field, string $fallback): array {
        return array_values(array_map(
            fn($lang) => array_map([self::class, 'slug'], array_values(forms::texts($field, $lang, $fallback)['options'])),
            lists::LANGS
        ));
    }

    /**
     * Some words as a slug: «Un día de clase» is «un-dia-de-clase».
     *
     * @param string $words
     * @return string
     */
    protected static function slug(string $words): string {
        return trim(preg_replace('/[^a-z0-9]+/', '-', recognise::plain($words)), '-');
    }

    /**
     * What was sent, as the brief form asks it: nothing of what is not in sight, and no permission that was not asked.
     *
     * @param array $data As signup::read() gives it.
     * @param \stdClass $event
     * @param \stdClass|null $question The only one asked, inside a web; null on the whole page, which asks them all.
     * @param bool $whole Whether it is the whole page.
     * @return array
     */
    public static function data(array $data, \stdClass $event, ?\stdClass $question, bool $whole = false): array {
        $first = $data['adults'][0];
        // At an act, the confirmation goes by what they gave; a form asks for an answer, and the rest is for later.
        $data['consents'] = $event->kind === 'form' ? [] : ['email' => $first['email'] !== '' ? 1 : 0,
            'phone' => $first['phone'] !== '' ? 1 : 0];
        if (!$whole) {
            $data['answers'] = $question ? array_intersect_key($data['answers'] ?? [], [$question->id => 1]) : [];
        }
        return $data;
    }

    /**
     * What is missing, by field: what signup::check() says, without what is not in sight.
     *
     * @param array $errors
     * @param \stdClass|null $question
     * @param array $data As signup::read() gives it: what is missing is all said at once.
     * @param bool $whole Whether it is the whole page, with all the questions of the centre in sight.
     * @return array
     */
    public static function errors(array $errors, ?\stdClass $question, array $data, bool $whole = false): array {
        if ($data['adults'][0]['email'] === '' && $data['adults'][0]['phone'] === '') {
            $errors['a0phone'] = $errors['a0phone'] ?? 'pub_err_reach';
        }
        $seen = ['a0first', 'a0phone', 'a0email', 'slot', 'people', 'form'];
        for ($i = 0; $i < signup::CHILDREN; $i++) {
            $seen[] = 'c' . $i . 'offer';
        }
        if ($question) {
            $seen[] = 'f' . $question->id;
        }
        return array_filter(
            $errors,
            fn($name) => in_array($name, $seen, true) || ($whole && preg_match('/^f\d+$/', $name)),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Whether what was sent is of a person: it waited a moment, left the trap alone and does not come by the dozen.
     *
     * @param string $mark The mark of the page, which says when it was given.
     * @param string $trap
     * @param int $id Of the entry, or the act.
     * @param string $ip
     * @param int $now
     * @param bool $many Whether many come from one address by right: a computer of the centre, or the code of its door.
     * @return bool
     */
    public static function person(string $mark, string $trap, int $id, string $ip, int $now, bool $many = false): bool {
        if ($trap !== '' || $now - (int) $mark < self::SOONEST) {
            return false;
        }
        if ($many) {
            return true;
        }
        $cache = \cache::make('local_oksigeniaeguahe', 'signupsfrom');
        return self::within($cache, sha1($ip . '|breve|' . $id . '|' . get_site_identifier()), self::PERHOUR, $now);
    }

    /**
     * Whether one more fits in an hour: counted from the first one, and one that is refused does not make it longer.
     *
     * @param \cache $cache
     * @param string $key
     * @param int $most
     * @param int $now
     * @return bool
     */
    public static function within($cache, string $key, int $most, int $now): bool {
        $kept = $cache->get($key);
        [$since, $count] = is_array($kept) && $kept[0] > $now - HOURSECS ? $kept : [$now, 0];
        if ($count >= $most) {
            return false;
        }
        $cache->set($key, [$since, $count + 1]);
        return true;
    }

    /**
     * What is said to whoever is not taken for a person: too soon (the browser filled it), it is enough to press again; where
     * what nobody sees was filled (a program that fills forms by itself), pressing again does nothing, so how to reach them.
     *
     * @param string $mark
     * @param string $trap
     * @param int $now
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function refused(string $mark, string $trap, int $now, \stdClass $centre, string $lang): string {
        if ($trap !== '') {
            return trim(publiclang::str('pub_err_trap', $lang) . ' ' . self::reach_words($centre, $lang));
        }
        return publiclang::str($now - (int) $mark < self::SOONEST ? 'pub_err_soon' : 'pub_err_toomany', $lang);
    }

    /**
     * How to reach the centre, in a sentence, with what it has.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return string Empty, it has nothing.
     */
    public static function reach_words(\stdClass $centre, string $lang): string {
        $phone = trim((string) ($centre->phone ?? ''));
        $email = trim((string) ($centre->email ?? ''));
        if ($phone === '' && $email === '') {
            return '';
        }
        return publiclang::str('pub_reach_' . ($phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'phone' : 'email')), $lang, (object) [
            'phone' => $phone, 'email' => $email]);
    }

    /**
     * The context of the form.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @param array $data
     * @param array $errors
     * @param \moodle_url $url
     * @param string $mark
     * @param \stdClass|null $question
     * @param bool $whole Whether it is the whole page, which asks every question of the centre.
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
        ?\stdClass $question,
        bool $whole = false
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $fallback = (string) ($centre->lang ?: 'es');
        $adult = $data['adults'][0];
        $child = $data['children'][0];
        // What is wrong, short: it goes where the name of the field was, so nothing moves.
        $short = fn(string $name, string $word) => isset($errors[$name]) ? $str('pub_breve_err_' . $word) : '';
        $reach = isset($errors['a0phone']) && $adult['phone'] === '' ? $short('a0phone', 'reach') : $short('a0phone', 'phone');
        // Either of the two is enough, so both say it.
        $either = isset($errors['a0phone']) && $adult['phone'] === '' && $adult['email'] === '' ? $short('a0phone', 'reach') : '';
        $field = fn(string $name, string $label, string $value, string $error, array $more = []) => $more + ['name' => $name,
            'label' => $str($label), 'value' => $value, 'error' => $error, 'type' => 'text', 'autocomplete' => 'off',
            'inputmode' => '', 'ltr' => false, 'select' => false, 'need' => '', 'mark' => false, 'peg' => false, 'fold' => false, 'area' => false, 'wide' => false];
        $self = !empty($data['self']);
        $askchild = !$self && $event->kind !== 'other';
        $fields = [$field('a0first', 'pub_breve_you', $adult['firstname'], $short('a0first', 'name'), ['autocomplete' => 'given-name',
            'need' => 'nombre'])];
        if ($askchild) {
            // For whoever wants to say it: the name of the adult and a way to answer are all that is needed.
            $fields[] = $field('c0name', 'pub_breve_child', $child['firstname'], '', ['mark' => true, 'peg' => $whole]);
        }
        $fields[] = $field('a0phone', 'pub_phone', $adult['phone'], $reach, ['type' => 'tel', 'autocomplete' => 'tel',
            'inputmode' => 'tel', 'ltr' => true, 'need' => 'contacto']);
        $fields[] = $field('a0email', 'pub_email', $adult['email'], $short('a0email', 'email') ?: $either, ['type' => 'email',
            'autocomplete' => 'email', 'inputmode' => 'email', 'ltr' => true, 'need' => 'contacto', 'wide' => true]);
        // The courses of the centre, by stage.
        [$grouped, $loose] = grades::grouped(array_values(centres::offers((int) $centre->id, true)), $centre);
        $option = fn($o, bool $year, int $chosen) => ['value' => (int) $o->id, 'name' => publicpage::text($o->name) . ($year ? ' · ' .
            $o->schoolyear : ''), 'selected' => (int) $o->id === $chosen];
        $course = fn(int $i) => $field('c' . $i . 'offer', 'pub_breve_course', '', $short('c' . $i . 'offer', 'course'), ['select' => [
            'none' => $str('pub_breve_course_none'),
            'groups' => array_map(fn($g) => ['label' => $g['label'], 'options' => array_map(
                fn($o) => $option($o, false, (int) ($data['children'][$i]['offerid'] ?? 0)),
                $g['offers']
            )], $grouped),
            'options' => array_map(fn($o) => $option($o, true, (int) ($data['children'][$i]['offerid'] ?? 0)), $loose),
        ]]);
        if (($askchild || $self) && ($grouped || $loose)) {
            $fields[] = $course(0);
        }
        // They may be two or more: each of the others folded, the next one offered once the one before is open.
        $others = [];
        for ($i = 1; $askchild && $i < signup::CHILDREN; $i++) {
            $name = (string) ($data['children'][$i]['firstname'] ?? '');
            $id = fn(array $f) => $f + ['id' => $whole ? $f['name'] : 'h' . $f['name'], 'last' => false];
            $others[] = ['open' => $name !== '' || (int) ($data['children'][$i]['offerid'] ?? 0) > 0,
                'title' => $str('pub_breve_other'),
                'fields' => array_map($id, array_merge(
                    [$field('c' . $i . 'name', 'pub_breve_child', $name, '', ['mark' => true])],
                    $grouped || $loose ? [$course($i)] : []
                ))];
        }
        // An act with turns: its hour.
        $slots = [];
        if ($event->kind !== 'form' && empty($event->tailored)) {
            foreach (events::slots((int) $event->id) as $slot) {
                $full = $slot->capacity && (int) $slot->people >= (int) $slot->capacity;
                if ($slot->timeend >= time() && !$full) {
                    $slots[] = ['value' => (int) $slot->id, 'name' => mail::hours($slot, $lang),
                        'selected' => (int) $slot->id === (int) ($data['slot'] ?? 0)];
                }
            }
        }
        if ($slots) {
            $fields[] = $field('slot', 'pub_breve_slot', '', $short('slot', 'slot'), ['select' => ['none' => '—', 'groups' => [],
                'options' => $slots]]);
        }
        // To write, folded where there is a page for it.
        $fields[] = $field('message', 'pub_breve_message', $data['message'], '', ['fold' => $whole, 'area' => true, 'wide' => true]);
        $seen = count($fields) - ($whole ? 1 : 0);
        // On a phone the email and the message take a whole row: of the others, an odd one out does too, never half a row empty.
        $half = array_keys(array_filter($fields, fn($f) => !$f['wide']));
        if (count($half) % 2) {
            $fields[end($half)]['wide'] = true;
        }
        $ask = false;
        if ($question && !$whole) {
            $texts = forms::texts($question, $lang, $fallback);
            $given = (string) ($data['answers'][$question->id] ?? '');
            $options = [];
            foreach ($texts['options'] as $i => $label) {
                $value = $texts['baseoptions'][$i] ?? $label;
                $options[] = ['id' => 'o' . $i, 'value' => $value, 'label' => $label, 'checked' => $given === $value];
            }
            $ask = ['name' => 'f' . $question->id, 'label' => $texts['label'],
                'error' => isset($errors['f' . $question->id]) ? $str('pub_breve_err_ask') : false, 'options' => $options,
                // Chosen by the web, not by whoever fills it: it is not counted as filled (formulario.js).
                'given' => $given !== '' && data_submitted() === false,
                // How the web may ask for another one without loading the page again (breve.js).
                'words' => json_encode(self::words($question, $fallback))];
        }
        $questions = $whole ? self::questions($event, $centre, $lang, $data, $errors) : [];
        return [
            'action' => $url->out(false),
            'mark' => $mark,
            'trap' => self::TRAP,
            'self' => $self,
            'ask' => $ask,
            'others' => $others,
            'hasothers' => (bool) $others,
            'fields' => array_map(fn($f, $i) => $f + ['id' => $whole ? $f['name'] : 'b' . $i,
                'last' => $i === $seen - 1 && $seen % 2 === 1], $fields, array_keys($fields)),
            'arrival' => array_map(
                fn($k) => ['key' => $k, 'value' => (string) ($data['arrival'][$k] ?? '')],
                array_keys(arrival::FIELDS)
            ),
            // What is wrong with the whole of it goes where the hint was.
            'hint' => $errors['form'] ?? ($errors['team'] ?? ($errors['people'] ?? (!empty($data['full']) && !$whole ? $str('pub_full_lead') :
                $str('pub_breve_hint')))),
            'hinterror' => isset($errors['form']) || isset($errors['people']) || isset($errors['team']),
            // A person of the team: whether it is a test, with one touch.
            'team' => isset($errors['team']) ? ['yes' => $str('pub_team_test'), 'no' => $str('pub_team_real')] : false,
            // Sent without leaving the page: what is said when it cannot be sent now, and what was written stays.
            'unsent' => trim($str('pub_unsent') . ' ' . self::reach_words($centre, $lang)),
            // How many come, at an act; and, at a talk or a party, whether they want to be told of the centre too.
            'people' => $event->kind === 'form' ? false : ['name' => 'people', 'label' => $str('pub_join_people'),
                'value' => max(1, min(events::MAXPEOPLE, (int) $data['people'] ?: 1)), 'min' => 1, 'max' => events::MAXPEOPLE,
                'less' => $str('pub_join_less'), 'more' => $str('pub_join_more')],
            'tellme' => $event->kind === 'other' ? ['name' => 'tellme', 'label' => $str('pub_tellme'),
                'checked' => !empty($data['tellme'])] : false,
            'whole' => $whole,
            'questions' => ['fields' => $questions],
            'hasquestions' => (bool) $questions,
            // A slip in the domain of an email (gmial.com), to point it out as it is written.
            'emailcheck' => $whole ? ['domains' => implode(' ', emailcheck::DOMAINS), 'maybe' => $str('pub_email_maybe'),
                'use' => $str('pub_email_use')] : false,
            'privacyurl' => publicpage::privacy_url($centre, $lang)->out(false),
            'privacy' => $str('pub_breve_privacy'),
            'info' => joinpage::first_layer($centre, $lang, ($event->kind ?? '') === 'other'),
            'send' => $str(self::button($event, $data, $self)),
            'sent' => false,
        ];
    }

    /**
     * What the button of a form says: what it does.
     *
     * @param \stdClass $event
     * @param array $data
     * @param bool $self
     * @return string The string.
     */
    protected static function button(\stdClass $event, array $data, bool $self): string {
        if (!empty($event->tailored)) {
            return 'pub_submit_visit';
        }
        if ($event->kind === 'form') {
            return 'pub_submit_form';
        }
        if (!empty($data['full'])) {
            return 'pub_submit_full';
        }
        return (int) ($event->free ?? 0) === acts::FREE_ASKS ? 'mail_letter_come' : 'pub_submit' . ($self ? '_self' : '');
    }

    /**
     * The questions the whole page asks: those of the form of the centre and, for a visit made to measure, when it suits
     * them, as one more.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $lang
     * @param array $data
     * @param array $errors
     * @return array As forms::context() gives them.
     */
    protected static function questions(\stdClass $event, \stdClass $centre, string $lang, array $data, array $errors): array {
        $form = !empty($event->formid) ? entries::get((int) $event->formid, (int) $centre->id) : null;
        $out = $form ? forms::context($form, $lang, (string) ($centre->lang ?: 'es'), $data['answers'] ?? [], $errors) : [];
        if (!empty($event->tailored)) {
            $blank = ['id' => 0, 'help' => '', 'required' => false, 'value' => '', 'error' => '', 'invalid' => false,
                'hidden' => false, 'showif' => '', 'showvalue' => '', 'issection' => false, 'istext' => false, 'islong' => false,
                'isdate' => false, 'date' => false, 'isnumber' => false, 'ischips' => false, 'isscale' => false, 'ismulti' => true];
            $days = [];
            foreach (visits::day_names($lang) as $day => $name) {
                $days[] = ['value' => $day, 'id' => 'tday-' . $day, 'label' => $name,
                    'checked' => in_array($day, array_map('intval', $data['days'] ?? []), true)];
            }
            $periods = [];
            foreach (visits::PERIODS as $period) {
                $periods[] = ['value' => $period, 'id' => 'tperiod-' . $period, 'label' => publiclang::str('pub_period_' . $period, $lang),
                    'checked' => in_array($period, $data['periods'] ?? [], true)];
            }
            $out[] = ['name' => 'tday', 'label' => publiclang::str('pub_when_suits', $lang), 'options' => $days] + $blank;
            $out[] = ['name' => 'tperiod', 'label' => publiclang::str('pub_when_suits_part', $lang), 'options' => $periods] + $blank;
        }
        return $out;
    }

    /**
     * The context once it was sent: two lines in the same place.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param \moodle_url|null $page The page of the family, to tell more if they wish; null for a family already known.
     * @param \stdClass|null $event The act they signed up to; null for a form.
     * @return array
     */
    public static function sent(\stdClass $centre, string $lang, ?\moodle_url $page, ?\stdClass $event = null): array {
        if ($event) {
            $parts = publicpage::date_parts((int) $event->timestart, $lang);
            return ['sent' => ['title' => publiclang::str('pub_breve_signed', $lang),
                'text' => publiclang::str('pub_breve_signed_text', $lang, (object) ['event' => publicpage::text($event->name),
                    'date' => $parts['long']]),
                'url' => $page ? $page->out(false) : '', 'more' => publiclang::str($event->kind === 'other' &&
                    !events::slots((int) $event->id) ? 'pub_act_change' : 'pub_breve_more', $lang)]];
        }
        $days = workdays::promise($centre, time(), $lang);
        return ['sent' => ['title' => publiclang::str('pub_breve_sent', $lang),
            'text' => publiclang::str('pub_breve_sent_text', $lang, $days),
            'url' => $page ? $page->out(false) : '', 'more' => publiclang::str('pub_breve_more', $lang)]];
    }

    /**
     * Writes the page: light, see-through and as tall as what it holds.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param string $title
     * @param array $context
     * @param string $button The colour of the buttons of the web that shows it, as six hexadecimal digits; '' for that of the
     *     centre.
     */
    public static function output(\stdClass $centre, string $lang, string $title, array $context, string $button = ''): void {
        global $CFG, $OUTPUT;
        $base = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/';
        $version = (int) get_config('local_oksigeniaeguahe', 'version');
        $colour = centres::colour($centre);
        $brand = centres::brand($centre);
        // The web of the centre puts the ground, and it is light: so are the colours here, whatever the phone says.
        $vars = publicpage::centre_vars($colour, false) + ($brand ? publicpage::brand_vars($brand, $colour, false) : []);
        if (preg_match('/^[0-9a-f]{6}$/i', $button)) {
            $colourof = '#' . \core_text::strtoupper($button);
            $on = publicpage::contrast($colourof, '#FFFFFF') >= publicpage::contrast($colourof, '#1A1614') ? '#FFFFFF' : '#1A1614';
            // A light button, with a border of ink: without it, it would get lost on the paper.
            $vars += ['--boton' => $colourof, '--sobre-boton' => $on,
                '--borde-boton' => publicpage::contrast($colourof, '#FFFFFF') < 3 ? '#1A1614' : $colourof];
        }
        $style = implode(' ', array_map(fn($k, $v) => "$k: $v;", array_keys($vars), $vars));
        $body = $OUTPUT->render_from_template('local_oksigeniaeguahe/public_brief', $context);
        publicpage::headers();
        echo '<!DOCTYPE html>
<html lang="' . s($lang === 'pt_br' ? 'pt-BR' : $lang) . '" dir="' . lists::dir($lang) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="only light">
<title>' . s($title) . '</title>
<style>:root { ' . $style . ' }
' . str_replace('url(fonts/', 'url(' . $base . 'fonts/', file_get_contents(dirname(__DIR__, 2) . '/pub/breve.css') .
            file_get_contents(dirname(__DIR__, 2) . '/pub/formulario.css')) . '</style>
<script src="' . $base . 'formulario.js?v=' . $version . '" defer></script>
<script src="' . $base . 'breve.js?v=' . $version . '" defer></script>
</head>
<body' . (!empty($context['sent']) ? ' data-enviado' : '') . '>
' . $body . '
</body>
</html>';
        die();
    }
}
