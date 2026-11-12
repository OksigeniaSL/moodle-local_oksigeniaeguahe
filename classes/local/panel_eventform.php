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
 * Creating or changing an event in the panel, whatever it looks like: the values as they are written (from the event,
 * or from what was posted), the turns on a strip of the day, who receives, the questions, what the families will see,
 * and, when a change reaches families already signed up, what changes and the message each one would get, before
 * anything is saved or sent.
 *
 * The hours are those of the site, the same the families read on their page and in their mail.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_eventform {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int The minutes of a new turn when there is none before it. */
    const MINUTES = 45;

    /**
     * The values of an event as the form writes them, or those of a new one: an open day in two weeks, from ten to one.
     *
     * @param \stdClass|null $event
     * @param int $now
     * @return array
     */
    public static function values(?\stdClass $event, int $now = 0): array {
        if (!$event) {
            $day = self::day(($now ?: time()) + 14 * DAYSECS);
            return ['id' => 0, 'kind' => 'openday', 'name' => get_string('event_kind_openday', self::C), 'tailored' => false,
                'day' => $day, 'start' => '10:00', 'end' => '13:00', 'place' => '', 'capacity' => '', 'open' => true,
                'hosts' => [], 'formid' => 0, 'survey' => 0, 'slots' => []];
        }
        $slots = [];
        foreach (events::slots((int) $event->id) as $slot) {
            $slots[] = ['id' => (int) $slot->id, 'start' => self::hour((int) $slot->timestart),
                'minutes' => (int) round(($slot->timeend - $slot->timestart) / MINSECS),
                'capacity' => (int) $slot->capacity ? (string) (int) $slot->capacity : ''];
        }
        return [
            'id' => (int) $event->id,
            'kind' => (string) $event->kind,
            'name' => (string) $event->name,
            'tailored' => !empty($event->tailored),
            'day' => self::day((int) $event->timestart),
            'start' => self::hour((int) $event->timestart),
            'end' => self::hour((int) $event->timeend),
            'place' => (string) $event->place,
            'capacity' => (int) $event->capacity ? (string) (int) $event->capacity : '',
            'open' => !empty($event->open),
            'hosts' => array_values(array_filter(array_map('intval', explode(',', (string) $event->hosts)))),
            'formid' => (int) $event->formid,
            'survey' => (int) ($event->survey ?? 0),
            'slots' => $slots,
        ];
    }

    /**
     * The values as they were posted, with a turn more or less when that was asked: the page comes back with them.
     *
     * @param array $old The values of the event, for the turns that keep their families.
     * @return array
     */
    public static function posted(array $old): array {
        // An hour as the team writes it, by the clock of the centre (9, 9:30, 21h); a day in three fields.
        $hour = fn(string $v) => panel::clock($v);
        $kind = optional_param('kind', 'openday', PARAM_ALPHA);
        $day = publicpage::date_param('day');
        $capacity = (int) optional_param('capacity', '', PARAM_RAW_TRIMMED);
        $values = [
            'id' => $old['id'],
            'kind' => in_array($kind, events::KINDS, true) ? $kind : 'openday',
            'name' => trim(optional_param('name', '', PARAM_TEXT)),
            'tailored' => optional_param('tailored', 0, PARAM_BOOL),
            'day' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : '',
            'start' => $hour(optional_param('start', '', PARAM_RAW_TRIMMED)),
            'end' => $hour(optional_param('end', '', PARAM_RAW_TRIMMED)),
            'place' => trim(optional_param('place', '', PARAM_TEXT)),
            'capacity' => $capacity > 0 ? (string) $capacity : '',
            'open' => optional_param('open', 0, PARAM_BOOL),
            'hosts' => array_values(array_filter(array_map('intval', optional_param_array('hosts', [], PARAM_INT)))),
            'formid' => optional_param('formid', 0, PARAM_INT),
            'survey' => optional_param('survey', 0, PARAM_INT),
            'slots' => [],
        ];
        $ids = optional_param_array('slotid', [], PARAM_INT);
        $starts = optional_param_array('slotstart', [], PARAM_RAW_TRIMMED);
        $minutes = optional_param_array('slotminutes', [], PARAM_INT);
        $capacities = optional_param_array('slotcapacity', [], PARAM_RAW_TRIMMED);
        $remove = optional_param('removeslot', -1, PARAM_INT);
        $known = array_column($old['slots'], 'id');
        foreach ($ids as $i => $id) {
            $id = in_array((int) $id, $known, true) ? (int) $id : 0;
            if ((int) $i === $remove && !self::signed_in($id)) {
                continue;
            }
            $values['slots'][] = ['id' => $id, 'start' => $hour((string) ($starts[$i] ?? '')),
                'minutes' => max(5, (int) ($minutes[$i] ?? self::MINUTES)),
                'capacity' => isset($capacities[$i]) && (int) $capacities[$i] > 0 ? (string) (int) $capacities[$i] : ''];
        }
        if (optional_param('addslot', 0, PARAM_BOOL)) {
            $values['slots'][] = self::next_slot($values);
        }
        return $values;
    }

    /**
     * A turn more: after the last one, as long as it, or at the start of the event.
     *
     * @param array $values
     * @return array
     */
    protected static function next_slot(array $values): array {
        $last = end($values['slots']) ?: null;
        if ($last && $last['start'] !== '') {
            [$h, $m] = array_map('intval', explode(':', $last['start']));
            $at = min(23 * 60 + 45, $h * 60 + $m + $last['minutes']);
            return ['id' => 0, 'start' => sprintf('%02d:%02d', intdiv($at, 60), $at % 60), 'minutes' => $last['minutes'],
                'capacity' => $last['capacity']];
        }
        return ['id' => 0, 'start' => $values['start'], 'minutes' => self::MINUTES, 'capacity' => ''];
    }

    /**
     * What is wrong in the values, by field.
     *
     * @param array $values
     * @return array name and when.
     */
    public static function errors(array $values): array {
        $errors = [];
        if ($values['name'] === '') {
            $errors['name'] = get_string('panel_err_name', self::C);
        }
        if ($values['day'] === '' || $values['start'] === '' || $values['end'] === '') {
            $errors['when'] = get_string('panel_err_when', self::C);
        } else if ($values['end'] < $values['start']) {
            $errors['when'] = get_string('event_err_end', self::C);
        }
        return $errors;
    }

    /**
     * The values as events::save() takes them; the turns left out go, if nobody signed up in them.
     *
     * @param array $values
     * @param array $old The values of the event before.
     * @return \stdClass
     */
    public static function data(array $values, array $old): \stdClass {
        $slots = [];
        $kept = [];
        foreach ($values['slots'] as $slot) {
            $start = $slot['start'] !== '' ? self::stamp($values['day'], $slot['start']) : 0;
            $slots[] = ['id' => $slot['id'], 'timestart' => $start, 'timeend' => $start + $slot['minutes'] * MINSECS,
                'capacity' => (int) $slot['capacity']];
            $kept[] = $slot['id'];
        }
        foreach ($old['slots'] as $slot) {
            if (!in_array($slot['id'], $kept, true)) {
                $slots[] = ['id' => $slot['id'], 'timestart' => 0];
            }
        }
        return (object) ['id' => $values['id'], 'kind' => $values['kind'], 'name' => $values['name'],
            'place' => $values['place'], 'timestart' => self::stamp($values['day'], $values['start']),
            'timeend' => self::stamp($values['day'], $values['end']), 'capacity' => (int) $values['capacity'],
            'open' => $values['open'] ? 1 : 0, 'tailored' => $values['tailored'] ? 1 : 0, 'formid' => $values['formid'],
            'survey' => $values['survey'], 'hosts' => $values['hosts'], 'slots' => $slots];
    }

    /**
     * What a change does to families already signed up: the hour, the place, who receives, the hour of a turn.
     *
     * @param \stdClass $centre
     * @param array $old
     * @param array $values
     * @return array [changes (what, from, to), the households it reaches by id]
     */
    public static function changes(\stdClass $centre, array $old, array $values): array {
        global $DB;
        if (!$old['id']) {
            return [[], []];
        }
        $signups = $DB->get_records(events::SIGNUPS, ['eventid' => $old['id']], 'timecreated ASC', 'id, householdid, slotid');
        if (!$signups) {
            return [[], []];
        }
        $c = self::C;
        $changes = [];
        $all = false;
        $slotted = [];
        if ($old['day'] !== $values['day']) {
            $changes[] = ['what' => get_string('panel_notice_day', $c), 'from' => self::day_words($old['day']),
                'to' => self::day_words($values['day'])];
            $all = true;
        }
        if (!$old['slots'] && ($old['start'] !== $values['start'] || $old['end'] !== $values['end'])) {
            $changes[] = ['what' => get_string('panel_notice_hour', $c), 'from' => $old['start'] . '–' . $old['end'],
                'to' => $values['start'] . '–' . $values['end']];
            $all = true;
        }
        $new = array_column($values['slots'], null, 'id');
        foreach ($old['slots'] as $n => $slot) {
            $now = $new[$slot['id']] ?? null;
            if (!$now || !self::signed_in($slot['id'])) {
                continue;
            }
            if ($now['start'] !== $slot['start'] || $now['minutes'] !== $slot['minutes']) {
                $changes[] = ['what' => get_string('panel_notice_turn', $c, $n + 1),
                    'from' => $slot['start'] . '–' . self::plus($slot['start'], $slot['minutes']),
                    'to' => $now['start'] . '–' . self::plus($now['start'], $now['minutes'])];
                $slotted[] = $slot['id'];
            }
        }
        if (trim($old['place']) !== trim($values['place'])) {
            $nowhere = get_string('panel_notice_noplace', $c);
            $changes[] = ['what' => get_string('panel_notice_place', $c), 'from' => trim($old['place']) ?: $nowhere,
                'to' => trim($values['place']) ?: $nowhere];
            $all = true;
        }
        $public = array_column(centres::hosts((int) $centre->id), null, 'memberid');
        $before = array_values(array_filter($old['hosts'], fn($id) => isset($public[$id])));
        $after = array_values(array_filter($values['hosts'], fn($id) => isset($public[$id])));
        if (array_diff($before, $after) || array_diff($after, $before)) {
            $names = fn(array $ids) => implode(', ', array_map(fn($id) => $public[$id]->name, $ids)) ?:
                get_string('panel_notice_nobody', $c);
            $changes[] = ['what' => get_string('panel_notice_hosts', $c), 'from' => $names($before), 'to' => $names($after)];
            $all = true;
        }
        $reached = [];
        foreach ($signups as $signup) {
            if ($all || in_array((int) $signup->slotid, $slotted, true)) {
                $reached[(int) $signup->householdid] = (int) $signup->householdid;
            }
        }
        return [$reached ? $changes : [], array_values($reached)];
    }

    /**
     * The step before saving: what changes, by where it reaches how many, and the message as the first family will
     * read it, with its names and its turn.
     *
     * @param \stdClass $centre
     * @param array $changes
     * @param int[] $householdids
     * @param array $values
     * @return array
     */
    public static function notice(\stdClass $centre, array $changes, array $householdids, array $values): array {
        global $USER;
        $c = self::C;
        $count = count($householdids);
        $email = 0;
        $mobile = 0;
        $neither = 0;
        $first = null;
        foreach ($householdids as $id) {
            $household = households::get($id, (int) $centre->id);
            if (!$household) {
                continue;
            }
            $first = $first ?? $household;
            $byemail = (bool) array_filter(households::full($household)->adults, fn($a) => trim((string) $a->email) !== '');
            $bymobile = messaging::joined($household, $centre);
            $email += $byemail ? 1 : 0;
            $mobile += $bymobile ? 1 : 0;
            $neither += !$byemail && !$bymobile ? 1 : 0;
        }
        $ways = [];
        if ($email) {
            $ways[] = get_string('panel_notice_by_email', $c, $email);
        }
        if ($mobile) {
            $ways[] = get_string('panel_notice_by_mobile', $c, $mobile);
        }
        $who = $ways ? get_string('panel_notice_who', $c, implode(get_string('panel_notice_who_and', $c), $ways)) : '';
        if ($neither) {
            $who = trim($who . ' ' . get_string(
                $neither === 1 ? 'panel_notice_neither_one' : 'panel_notice_neither',
                $c,
                $neither
            ));
        }
        // In the language of that family, as it will read it.
        $message = $first ? self::message(
            $first,
            $centre,
            self::preview_signup($first, $centre, $values),
            $first->lang ?: (string) $centre->lang
        ) : ['greeting' => '', 'text' => ''];
        $names = $first ? array_values(array_filter(array_map(
            fn($a) => trim((string) $a->firstname),
            households::full($first)->adults
        ))) : [];
        return [
            'title' => get_string($count === 1 ? 'panel_notice_title_one' : 'panel_notice_title', $c, $count),
            'changes' => $changes,
            'households' => $count,
            'tell' => get_string($count === 1 ? 'panel_save_and_tell_one' : 'panel_save_and_tell', $c, $count),
            'who' => $who,
            'greeting' => $message['greeting'],
            'message' => $message['text'],
            'sign' => get_string('panel_notice_sign', $c, (object) ['who' => trim((string) $USER->firstname),
                'centre' => format_string($centre->name)]),
            'example' => $names ? get_string(
                count($names) === 1 ? 'panel_notice_example_one' : 'panel_notice_example',
                $c,
                familypage::join($names, current_language())
            ) :
                get_string('panel_notice_example_nonames', $c),
        ];
    }

    /**
     * How the family of a sign-up will read it before it is saved: with the new day, hour, place and hosts.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param array $values
     * @return \stdClass
     */
    protected static function preview_signup(\stdClass $household, \stdClass $centre, array $values): \stdClass {
        $signup = familymail::signup($household, (int) $values['id']) ?? (object) ['slotid' => 0, 'people' => 0];
        $start = self::stamp($values['day'], $values['start']);
        $end = self::stamp($values['day'], $values['end']);
        foreach ($values['slots'] as $slot) {
            if ($slot['id'] && $slot['id'] === (int) ($signup->slotid ?? 0) && $slot['start'] !== '') {
                $start = self::stamp($values['day'], $slot['start']);
                $end = $start + $slot['minutes'] * MINSECS;
            }
        }
        return (object) ['eventid' => $values['id'], 'eventname' => $values['name'], 'timestart' => $start,
            'timeend' => $end, 'place' => $values['place'], 'hosts' => implode(',', $values['hosts']),
            'people' => (int) ($signup->people ?? 0)];
    }

    /**
     * The message of a change, in the language of the family: the greeting, and the day, the hour, the place and who
     * receives them now.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $signup As households::full() gives them, or as it will be.
     * @param string $lang
     * @return array greeting and text.
     */
    public static function message(\stdClass $household, \stdClass $centre, \stdClass $signup, string $lang): array {
        $names = array_values(array_filter(array_map(
            fn($a) => trim((string) $a->firstname),
            households::full($household)->adults
        )));
        $parts = publicpage::date_parts((int) $signup->timestart, $lang);
        $hours = familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang);
        $when = $parts['long'] . ', ' . \core_text::strtolower(\core_text::substr($hours, 0, 1)) .
            \core_text::substr($hours, 1);
        $place = trim((string) $signup->place);
        $text = publiclang::str('mail_change_lead', $lang, (object) ['name' => format_string($signup->eventname),
            'when' => $when, 'place' => $place !== '' ? publiclang::str('mail_change_place', $lang, $place) : '']);
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $hosts = array_map(fn($h) => $h->first ?: $h->name, $ids ? centres::hosts((int) $centre->id, $ids) : []);
        if ($hosts) {
            $text .= ' ' . publiclang::str(
                count($hosts) === 1 ? 'mail_change_hosts_one' : 'mail_change_hosts',
                $lang,
                familypage::join($hosts, $lang)
            );
        }
        $text .= ' ' . publiclang::str('mail_change_after', $lang);
        return ['greeting' => $names ? publiclang::str('panel_greeting', $lang, familypage::join($names, $lang)) : '',
            'text' => $text, 'when' => $when . ($place !== '' ? publiclang::str('mail_change_place', $lang, $place) : '')];
    }

    /**
     * Tells each household reached what changed, by mail and on the phones it joined with, and notes it in its history.
     *
     * @param \stdClass $centre
     * @param \stdClass $event As it is now.
     * @param int[] $householdids
     * @param int $userid
     * @return int How many were told.
     */
    public static function tell(\stdClass $centre, \stdClass $event, array $householdids, int $userid): int {
        $told = 0;
        foreach ($householdids as $id) {
            $household = households::get((int) $id, (int) $centre->id);
            $signup = $household ? familymail::signup($household, (int) $event->id) : null;
            if (!$signup) {
                continue;
            }
            $lang = $household->lang ?: $centre->lang;
            $message = self::message($household, $centre, $signup, $lang);
            $text = trim($message['greeting'] . "\n\n" . $message['text']);
            $sent = false;
            ob_start();
            try {
                $sent = familymail::send(
                    'change',
                    $household,
                    $centre,
                    $signup,
                    $lang,
                    households::new_link($household, 'change'),
                    null,
                    $text
                );
            } catch (\Throwable $e) {
                $sent = false;
            } finally {
                ob_end_clean();
            }
            if ($sent || messaging::joined($household, $centre)) {
                households::told($household, $userid, $sent ? 'email' : 'other', $text);
                $told++;
            }
        }
        return $told;
    }

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @param array $values
     * @param array $errors
     * @param array|null $notice
     * @return array
     */
    public static function context(\stdClass $centre, array $values, array $errors = [], ?array $notice = null): array {
        global $DB;
        $c = self::C;
        $isnew = !$values['id'];
        $kinds = [];
        foreach (events::KINDS as $kind) {
            $kinds[] = ['value' => $kind, 'name' => get_string('event_kind_' . $kind, $c),
                'help' => get_string('event_kind_' . $kind . '_help', $c), 'checked' => $values['kind'] === $kind,
                'id' => 'tipo-' . $kind];
        }
        // The turns, with who signed up in each; one with families is not taken out.
        $people = $values['id'] ? $DB->get_records_sql('SELECT slotid, COUNT(1) AS households, SUM(people) AS people
                                                         FROM {' . events::SIGNUPS . '} WHERE eventid = ?
                                                     GROUP BY slotid', [$values['id']]) : [];
        // The strip goes from the start to the end of the event, wide enough for a turn written outside them.
        $from = self::minutes($values['start']);
        $to = self::minutes($values['end']);
        foreach ($values['slots'] as $slot) {
            $at = self::minutes($slot['start']);
            if ($at !== null && $from !== null && $to !== null) {
                $from = min($from, $at);
                $to = max($to, min(24 * 60, $at + $slot['minutes']));
            }
        }
        $span = $from !== null && $to !== null && $to > $from ? $to - $from : 0;
        $slots = [];
        foreach ($values['slots'] as $i => $slot) {
            $here = $slot['id'] ? ($people[$slot['id']] ?? null) : null;
            $at = self::minutes($slot['start']);
            $slots[] = [
                'i' => $i, 'n' => $i + 1, 'id' => $slot['id'], 'start' => $slot['start'], 'minutes' => $slot['minutes'],
                'capacity' => $slot['capacity'],
                'signed' => $here ? get_string('panel_load_open', $c, (object) ['households' => (int) $here->households,
                    'people' => (int) $here->people]) : ($slot['id'] ? get_string('panel_turn_nobody', $c) :
                    get_string('panel_turn_new', $c)),
                'canremove' => !$here,
                'keepwhy' => $here ? get_string('panel_turn_keep', $c) : '',
                'left' => $span && $at !== null ? round(100 * max(0, $at - $from) / $span, 2) : 0,
                'width' => $span && $at !== null ? round(100 * min($slot['minutes'], max(0, $to - $at)) / $span, 2) : 0,
            ];
        }
        // Its first and last hour, and the whole hours between them.
        $ticks = [];
        if ($span) {
            $marks = [$from];
            for ($m = intdiv($from, 60) * 60 + 60; $m < $to; $m += 60) {
                // A whole hour too close to the ends would write over them.
                if (min($m - $from, $to - $m) >= 20) {
                    $marks[] = $m;
                }
            }
            $marks[] = $to;
            foreach ($marks as $m) {
                $ticks[] = ['left' => round(100 * ($m - $from) / $span, 2), 'text' => sprintf(
                    '%d:%02d',
                    intdiv($m, 60),
                    $m % 60
                )];
            }
        }
        // Who receives, with their faces; and what the families will see beside it.
        $hosts = [];
        $faces = [];
        $firsts = [];
        foreach (centres::hosts((int) $centre->id) as $host) {
            $face = ['id' => $host->memberid, 'name' => $host->name, 'first' => $host->first,
                'picture' => $host->photo ? $host->photo->out(false) : '', 'initials' => $host->initials,
                'role' => $host->role, 'checked' => in_array($host->memberid, $values['hosts'], true)];
            $hosts[] = $face;
            if ($face['checked']) {
                $faces[] = $face;
                $firsts[] = $host->first ?: $host->name;
            }
        }
        $forms = [['value' => 0, 'name' => get_string('event_form_none', $c), 'selected' => !$values['formid']]];
        $chosen = null;
        foreach (entries::for_centre((int) $centre->id) as $entry) {
            if ($entry->kind === 'form') {
                $forms[] = ['value' => (int) $entry->id, 'name' => format_string($entry->name),
                    'selected' => (int) $entry->id === $values['formid']];
                $chosen = (int) $entry->id === $values['formid'] ? $entry : $chosen;
            }
        }
        // After the visit: the one open question, or a survey of the centre; the first one made from «How it went».
        $surveys = [['value' => 0, 'name' => get_string('event_survey_none', $c), 'selected' => !$values['survey']]];
        $surveyentry = null;
        foreach (surveys::for_centre((int) $centre->id) as $entry) {
            $surveys[] = ['value' => (int) $entry->id, 'name' => format_string($entry->name),
                'selected' => (int) $entry->id === $values['survey']];
            $surveyentry = (int) $entry->id === $values['survey'] ? $entry : $surveyentry;
        }
        if (count($surveys) === 1) {
            $surveys[] = ['value' => -1, 'name' => get_string('event_survey_new', $c), 'selected' => $values['survey'] === -1];
        }
        $questions = [];
        foreach ($chosen ? forms::fields((int) $chosen->id) : [] as $field) {
            $label = forms::texts($field, current_language(), (string) $centre->lang)['label'];
            if ($label !== '' && $field->type !== 'title') {
                $questions[] = ['q' => format_string($label)];
            }
        }
        $load = null;
        if ($values['id']) {
            $households = (int) $DB->count_records(events::SIGNUPS, ['eventid' => $values['id']]);
            $count = events::people($values['id']);
            $capacity = (int) $values['capacity'];
            $load = $households ? ['text' => get_string($capacity ? 'panel_load' : 'panel_load_open', $c, (object) [
                'households' => $households, 'people' => $count, 'capacity' => $capacity]),
                'percent' => $capacity ? min(100, round(100 * $count / $capacity)) : 0] : null;
        }
        $start = self::stamp($values['day'], $values['start']);
        $listurl = new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id]);
        $back = $values['id'] ? ['url' => (new \moodle_url('/local/oksigeniaeguahe/event.php', ['id' => $values['id']]))->out(
            false
        ), 'name' => format_string($values['name'])] : ['url' => $listurl->out(false),
            'name' => get_string('tab_events', $c)];
            $signed = $load ? (int) $DB->count_records(events::SIGNUPS, ['eventid' => $values['id']]) : 0;
            return [
            'head' => panel::head([
                'title' => get_string($isnew ? 'panel_event_new' : 'panel_event_edit', $c),
                'sub' => $isnew ? get_string('panel_eventform_sub_new', $c) : ($signed ? get_string($signed === 1 ?
                    'panel_eventform_sub_one' : 'panel_eventform_sub', $c, $signed) : get_string(
                        'panel_eventform_sub_none',
                        $c
                    )),
                'back' => $back,
            ]),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/events.php', ['c' => $centre->id] +
                ($isnew ? ['new' => 1] : ['id' => $values['id']])))->out(false),
            'sesskey' => sesskey(),
            'id' => $values['id'],
            'isnew' => $isnew,
            'kinds' => $kinds,
            'name' => ['value' => $values['name'], 'error' => $errors['name'] ?? ''],
            'tailored' => $values['tailored'],
            'day' => $values['day'],
            // The day in three fields in the order of the centre, with a copy for the preview beside it.
            'daydate' => ['mirror' => ['vivo' => 'dia']] + panel::date_input('day', (string) $values['day'], $centre, 'acto-dia'),
            'start' => $values['start'],
            'end' => $values['end'],
            'daytext' => $start ? userdate($start, get_string('strftimedaydate', 'langconfig')) : '',
            'whenerror' => $errors['when'] ?? '',
            'place' => ['value' => $values['place'], 'error' => ''],
            'placehelp' => get_string('panel_place_help', $c),
            'capacity' => $values['capacity'],
            'open' => $values['open'],
            'load' => $load,
            'hasslots' => (bool) $slots && $span,
            'slots' => $slots,
            'emptyslot' => ['i' => count($slots), 'n' => count($slots) + 1, 'start' => '', 'minutes' => self::MINUTES,
                'capacity' => ''],
            'strip' => ['from' => $span ? sprintf('%02d:%02d', intdiv($from, 60), $from % 60) : '',
                'to' => $span ? sprintf('%02d:%02d', intdiv($to, 60), $to % 60) : '', 'ticks' => array_values($ticks)],
            'hashosts' => (bool) $hosts,
            'volunteers' => self::volunteers($centre),
            'hosts' => $hosts,
            'nohosts' => $hosts ? '' : get_string('event_hosts_none', $c),
            'forms' => $forms,
            'hasformquestions' => (bool) $questions,
            'formquestions' => $questions,
            'formurl' => $chosen ? (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $chosen->id]))->out(false) : '',
            'surveys' => $surveys,
            'surveyurl' => $surveyentry ? (new \moodle_url('/local/oksigeniaeguahe/entry.php', ['id' => $surveyentry->id]))
                ->out(false) : '',
            'preview' => [
                'kind' => get_string('event_kind_' . $values['kind'], $c),
                'name' => $values['name'] !== '' ? $values['name'] : get_string('panel_preview_name', $c),
                'when' => $start ? self::when_words($start, $values['start'], $values['end']) :
                    get_string('panel_preview_when', $c),
                'place' => $values['place'],
                'faces' => $faces,
                'hosts' => !$firsts ? '' : get_string(
                    count($firsts) === 1 ? 'panel_js_hosts_one' : 'panel_js_hosts_many',
                    $c,
                    familypage::join($firsts, current_language())
                ),
            ],
            'notice' => $notice,
            ];
    }

    /**
     * Whether someone signed up in a turn.
     *
     * @param int $slotid
     * @return bool
     */
    protected static function signed_in(int $slotid): bool {
        global $DB;
        return $slotid && $DB->record_exists(events::SIGNUPS, ['slotid' => $slotid]);
    }

    /**
     * The day of a time, in the zone of the site: 2026-11-21.
     *
     * @param int $time
     * @return string
     */
    public static function day(int $time): string {
        return (new \DateTime('@' . $time))->setTimezone(\core_date::get_server_timezone_object())->format('Y-m-d');
    }

    /**
     * The hour of a time, in the zone of the site: 10:30.
     *
     * @param int $time
     * @return string
     */
    public static function hour(int $time): string {
        return (new \DateTime('@' . $time))->setTimezone(\core_date::get_server_timezone_object())->format('H:i');
    }

    /**
     * The time of a day and an hour, in the zone of the site.
     *
     * @param string $day 2026-11-21
     * @param string $hour 10:30
     * @return int 0 when either is missing.
     */
    public static function stamp(string $day, string $hour): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !preg_match('/^\d{2}:\d{2}$/', $hour)) {
            return 0;
        }
        $time = \DateTime::createFromFormat('!Y-m-d H:i', $day . ' ' . $hour, \core_date::get_server_timezone_object());
        return $time ? $time->getTimestamp() : 0;
    }

    /**
     * The minutes of an hour from midnight.
     *
     * @param string $hour
     * @return int|null
     */
    protected static function minutes(string $hour): ?int {
        return preg_match('/^(\d{2}):(\d{2})$/', $hour, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    /**
     * An hour some minutes later: 10:00 and 45, 10:45.
     *
     * @param string $hour
     * @param int $minutes
     * @return string
     */
    protected static function plus(string $hour, int $minutes): string {
        $at = (self::minutes($hour) ?? 0) + $minutes;
        return sprintf('%02d:%02d', intdiv($at, 60) % 24, $at % 60);
    }

    /**
     * A day in words, in the language of the page.
     *
     * @param string $day
     * @return string
     */
    protected static function day_words(string $day): string {
        $time = self::stamp($day, '12:00');
        return $time ? publicpage::date_parts($time, current_language())['long'] : '';
    }

    /**
     * When an event is, as the poster says it: «Sábado 21 de noviembre · 10:00 a 13:00».
     *
     * @param int $start
     * @param string $from
     * @param string $to
     * @return string
     */
    protected static function when_words(int $start, string $from, string $to): string {
        $day = publicpage::date_parts($start, current_language())['long'];
        return \core_text::strtoupper(\core_text::substr($day, 0, 1)) . \core_text::substr($day, 1) . ' · ' .
            get_string('panel_from_to', self::C, (object) ['from' => $from, 'to' => $to]);
    }

    /**
     * The families of the centre that offered to tell another family how it went, to invite them to receive.
     *
     * @param \stdClass $centre
     * @return array|false text, url.
     */
    protected static function volunteers(\stdClass $centre) {
        $n = settled::volunteers((int) $centre->id);
        if (!$n) {
            return false;
        }
        $url = new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id, 'voluntarias' => 1]);
        return ['text' => get_string($n === 1 ? 'panel_volunteers_one' : 'panel_volunteers', self::C, $n),
            'url' => $url->out(false)];
    }
}
