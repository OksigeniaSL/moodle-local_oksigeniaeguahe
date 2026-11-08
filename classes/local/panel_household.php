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
 * What the file of a household shows in the panel, whatever it looks like: since when it waits, its children with their
 * way (from the first question to the enrolment), the conversation from the first thing to the last with the answer at
 * the end, who writes and how, what they answered, the visit of their own, their page and their data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_household {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var array The way of each child, in five steps: the waiting list is in the step of the place. */
    const PATH = ['inquiry', 'visit', 'application', 'admitted', 'enrolled'];

    /** @var array The ways to answer from the file: the phone app (Telegram or WhatsApp), email or a call. */
    const ANSWERS = ['mobile', 'email', 'phone'];

    /**
     * The context of the file.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param bool $canact Whether the person may answer and change, or only see.
     * @param string $newlink A link just made for the family, to show once.
     * @param int $now
     * @return array
     */
    public static function context(
        \stdClass $household,
        \stdClass $centre,
        bool $canact,
        string $newlink = '',
        int $now = 0
    ): array {
        global $DB, $USER;
        $now = $now ?: time();
        $c = self::C;
        $full = households::full($household);
        $offers = centres::offers((int) $centre->id);
        $url = new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]);
        $contacts = array_values($full->contacts);
        $userids = array_unique(array_filter(array_map(fn($x) => (int) $x->userid, $contacts)));
        $people = $userids ? $DB->get_records_list('user', 'id', $userids) : [];

        // The head: since when they wait, or who answered; who they are; where they came from and since when.
        $ante = '';
        if ($household->waitingsince > 0) {
            $words = panel::wait_words((int) $household->waitingsince, $now);
            $ante = get_string('panel_waits', $c, $words['since']) .
                (panel::days_between((int) $household->waitingsince, $now) >= 2 ? ' · ' . $words['sub'] : '');
        } else {
            foreach (array_reverse($contacts) as $contact) {
                if ($contact->direction === 'out') {
                    $ante = get_string('panel_answered_by', $c, (object) [
                        'when' => panel::when_words((int) $contact->timecreated, $now),
                        'who' => isset($people[$contact->userid]) ? fullname($people[$contact->userid]) : 'Eguahe',
                    ]);
                    break;
                }
            }
        }
        $first = $contacts[0] ?? null;
        $origin = [];
        if ($first && $first->entryid) {
            $origin[] = format_string((string) $DB->get_field(entries::T, 'name', ['id' => $first->entryid]));
        } else {
            $origin[] = get_string('channel_' . $household->firstchannel, $c);
        }
        if ($first && trim((string) $first->detail) !== '') {
            $origin[] = s($first->detail);
        }
        $origin[] = get_string('panel_since_date', $c, userdate(
            (int) $household->timecreated,
            get_string('strftimedateshort', 'langconfig')
        ));
        if (!empty($household->moving)) {
            $origin[] = get_string('panel_moving', $c);
        }
        if (!empty($household->incentre)) {
            $origin[] = get_string('panel_incentre', $c);
        }

        $joined = messaging::joined($household, $centre);
        $mode = messaging::mode($centre);
        $answers = self::answers((int) $household->id);
        $email = (string) (array_values(array_filter(array_map(fn($a) => (string) $a->email, $full->adults)))[0] ?? '');
        $phone = (string) (array_values(array_filter(array_map(fn($a) => (string) $a->phone, $full->adults)))[0] ?? '');
        return [
            'head' => [
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('tab_inbox', $c)],
                'ante' => $ante,
                'title' => panel::adults_line($full->adults),
                'sub' => implode(' · ', array_filter($origin)),
                'action' => $canact ? ['url' => '#contestar', 'anchor' => true, 'name' => get_string('panel_answer', $c),
                    'icon' => panel::icon('enviado')] : null,
            ],
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'canact' => $canact,
            'children' => self::children($full, $offers, $canact),
            'history' => self::history($full, $contacts, $people, $now),
            'waiting' => $household->waitingsince > 0,
            'me' => panel::person($USER),
            'composer' => self::composer($mode, $joined, $email, $phone),
            'adults' => self::adults($full, $joined),
            'lang' => get_string('panel_speak', $c, lists::LANG_NAMES[$household->lang] ?? $household->lang),
            'editurl' => (new \moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id,
                'id' => $household->id]))->out(false),
            'answers' => $answers,
            'hasanswers' => (bool) $answers,
            'visit' => self::visit($household, $centre),
            'events' => self::events($full),
            'hasevents' => (bool) $full->signups,
            'newlink' => $newlink,
            'erasequestion' => get_string('panel_erase_question', $c, panel::adults_line($full->adults)),
        ];
    }

    /**
     * Each child with the backpack of its label, its course and its way, to move it from the file.
     *
     * @param \stdClass $full
     * @param array $offers
     * @param bool $canact
     * @return array
     */
    protected static function children(\stdClass $full, array $offers, bool $canact): array {
        $c = self::C;
        $out = [];
        foreach (array_values($full->children) as $i => $child) {
            $offer = $offers[$child->offerid] ?? null;
            $at = lists::STAGE_STEP[$child->stage] ?? 1;
            $steps = [];
            foreach (self::PATH as $j => $stage) {
                $here = $child->stage === $stage || ($stage === 'admitted' && $child->stage === 'waitlist');
                $steps[] = [
                    'value' => $stage,
                    'name' => get_string($stage === 'admitted' ? ($child->stage === 'waitlist' ? 'stage_waitlist' :
                        'panel_stage_place') : 'stage_' . $stage, $c),
                    'here' => $here && $child->stage !== 'closed',
                    'done' => $child->stage !== 'closed' && $j + 1 < $at,
                    'last' => $j === count(self::PATH) - 1,
                ];
            }
            $reasons = [];
            foreach (lists::CLOSED_REASONS as $reason) {
                $reasons[] = ['value' => $reason, 'name' => get_string('closed_' . $reason, $c)];
            }
            $tag = panel::tag($child);
            $out[] = [
                'id' => $child->id,
                'hanging' => panel::hanging($i, $tag),
                'name' => trim((string) $child->firstname) !== '' ? $child->firstname : $tag,
                'tagline' => trim((string) $child->tagname) !== '' && $child->tagname !== $child->firstname ?
                    get_string('panel_on_label', $c, $child->tagname) : '',
                'course' => $offer ? get_string('panel_course_year', $c, (object) ['name' => format_string($offer->name),
                    'year' => s($offer->schoolyear)]) : get_string('panel_nocourse', $c),
                'steps' => $steps,
                'closed' => $child->stage === 'closed',
                'reason' => $child->stage === 'closed' && $child->closedreason ?
                    get_string('closed_' . $child->closedreason, $c) : '',
                'waitlist' => $child->stage === 'waitlist',
                'reasons' => $reasons,
                'canact' => $canact,
                'label' => get_string('panel_way_of', $c, $tag),
            ];
        }
        return $out;
    }

    /**
     * The conversation, from the first thing to the last: what the family did, what the team did and what Eguahe did
     * by itself.
     *
     * @param \stdClass $full
     * @param array $contacts In order of time.
     * @param array $people The users of the team who wrote, by id.
     * @param int $now
     * @return array
     */
    protected static function history(\stdClass $full, array $contacts, array $people, int $now): array {
        global $DB;
        $c = self::C;
        $entries = [];
        $out = [];
        foreach ($contacts as $i => $contact) {
            $channel = in_array($contact->channel, lists::CHANNELS, true) ? $contact->channel : 'other';
            $where = '';
            if ($contact->entryid) {
                $entries[$contact->entryid] = $entries[$contact->entryid] ??
                    format_string((string) $DB->get_field(entries::T, 'name', ['id' => $contact->entryid]));
                $detail = trim((string) $contact->detail);
                $where = $entries[$contact->entryid] . ($detail !== '' ? ' (' . s($detail) . ')' : '');
            }
            $person = $people[$contact->userid] ?? null;
            $via = get_string('via_' . $channel, $c);
            $via = \core_text::strtolower(\core_text::substr($via, 0, 1)) . \core_text::substr($via, 1);
            if ($contact->direction === 'in') {
                $kind = 'familia';
                $who = get_string('panel_the_family', $c);
                $how = $i > 0 ? get_string('panel_wrote', $c, $via) :
                    ($where !== '' ? get_string('panel_came_entry', $c, $where) : get_string('panel_came', $c, $via));
            } else if ($contact->direction === 'note') {
                $kind = 'equipo';
                $who = $person ? fullname($person) : get_string('panel_the_team', $c);
                $how = get_string('panel_left_note', $c);
            } else {
                $kind = $person ? 'equipo' : 'sistema';
                $who = $person ? fullname($person) : 'Eguahe';
                $how = get_string('panel_answered_via', $c, $via);
            }
            $body = trim((string) $contact->body);
            $out[] = [
                'family' => $kind === 'familia',
                'team' => $kind === 'equipo',
                'system' => $kind === 'sistema',
                'who' => $who,
                'how' => $how,
                'when' => panel::when_words((int) $contact->timecreated, $now),
                'body' => $body === '' ? '' : ($contact->direction === 'note' ? $body : get_string('panel_quote', $c, $body)),
            ];
        }
        return $out;
    }

    /**
     * How the team answers from the file: the ways there are, the one chosen first, and why the others are not.
     *
     * @param string $mode What the centre does with Telegram and WhatsApp.
     * @param bool $joined Whether the family joined their phone.
     * @param string $email The first email of the family.
     * @param string $phone The first phone of the family.
     * @return array
     */
    protected static function composer(string $mode, bool $joined, string $email, string $phone): array {
        $c = self::C;
        $can = ['mobile' => $joined, 'email' => $email !== '', 'phone' => $phone !== ''];
        $chosen = $joined ? 'mobile' : ($email !== '' ? 'email' : 'phone');
        $ways = [];
        foreach (self::ANSWERS as $way) {
            if ($way === 'mobile' && $mode === 'off') {
                continue;
            }
            $ways[] = [
                'value' => $way,
                'name' => get_string('panel_way_' . $way, $c),
                'icon' => panel::icon(['mobile' => 'movil', 'email' => 'correo', 'phone' => 'telefono'][$way]),
                'button' => get_string('panel_send_' . $way, $c),
                'checked' => $way === $chosen,
                'disabled' => !$can[$way] && $way !== 'phone',
                'why' => !$can[$way] ? get_string($way === 'mobile' ? 'panel_not_joined' : 'panel_none', $c) : '',
            ];
        }
        $notes = [];
        if ($mode !== 'off' && !$joined) {
            $notes[] = get_string('panel_note_notjoined', $c);
        }
        if ($email === '') {
            $notes[] = get_string('panel_note_nomail', $c);
        }
        return ['ways' => $ways, 'notes' => $notes, 'button' => get_string('panel_send_' . $chosen, $c)];
    }

    /**
     * Who writes: each adult and, in sentences, how they are written to.
     *
     * @param \stdClass $full
     * @param bool $joined
     * @return array
     */
    protected static function adults(\stdClass $full, bool $joined): array {
        $c = self::C;
        $permissions = households::permissions((int) $full->id);
        $out = [];
        foreach ($full->adults as $adult) {
            $granted = $permissions[$adult->id] ?? [];
            $ways = [];
            if ((string) $adult->email !== '') {
                $ways[] = ['icon' => panel::icon('correo'), 'text' => get_string(!empty($granted['email']) ? 'panel_by_email' :
                    'panel_email_nopermission', $c), 'value' => $adult->email, 'href' => 'mailto:' . $adult->email];
            }
            if ((string) $adult->phone !== '') {
                $tel = 'tel:' . preg_replace('/[^0-9+]/', '', (string) $adult->phone);
                if (!empty($granted['whatsapp'])) {
                    $ways[] = ['icon' => panel::icon('whatsapp'), 'text' => get_string($joined ? 'panel_by_mobile_joined' :
                        'panel_by_mobile', $c), 'value' => $adult->phone, 'href' => $tel];
                }
                $ways[] = ['icon' => panel::icon('telefono'), 'text' => get_string(!empty($granted['phone']) ? 'panel_calls' :
                    'panel_phone_nopermission', $c), 'value' => $adult->phone, 'href' => $tel];
            }
            $out[] = ['name' => trim($adult->firstname . ' ' . $adult->lastname), 'ways' => $ways, 'hasways' => (bool) $ways];
        }
        return $out;
    }

    /**
     * What they answered in the forms of the centre: the last answer to each question.
     *
     * @param int $householdid
     * @return array
     */
    public static function answers(int $householdid): array {
        $answers = [];
        foreach (forms::answers_of($householdid) as $answer) {
            $answers[$answer->fieldid . ':' . $answer->label] = $answers[$answer->fieldid . ':' . $answer->label] ??
                ['label' => format_string($answer->label), 'value' => (string) $answer->value];
        }
        return array_values($answers);
    }

    /**
     * A visit of their own when they asked for one: what suits them, and what is needed to propose the day.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return array|null
     */
    protected static function visit(\stdClass $household, \stdClass $centre): ?array {
        global $DB;
        $pending = visits::pending((int) $household->id);
        if (!$pending) {
            return null;
        }
        $guides = [];
        foreach (centres::hosts((int) $centre->id) as $i => $host) {
            $guides[] = ['id' => $host->memberid, 'name' => $host->name . ($host->role !== '' ? ' · ' . $host->role : ''),
                'selected' => $i === 0];
        }
        $minutes = [];
        foreach ([30, 45, 60, 90] as $m) {
            $minutes[] = ['value' => $m, 'name' => get_string('panel_minutes', self::C, $m), 'selected' => $m === 45];
        }
        return [
            'asked' => get_string('visit_asked', self::C, (object) ['when' => visits::suits($pending, current_language()),
                'people' => (int) $pending->people]),
            'date' => date('Y-m-d', time() + DAYSECS),
            'place' => $pending->eventid ? format_string((string) $DB->get_field(events::T, 'place', ['id' => $pending->eventid])) :
                '',
            'people' => (int) $pending->people,
            'guides' => $guides,
            'hasguides' => (bool) $guides,
            'minutes' => $minutes,
        ];
    }

    /**
     * The events they signed up for, and whether they came.
     *
     * @param \stdClass $full
     * @return array
     */
    protected static function events(\stdClass $full): array {
        $out = [];
        foreach ($full->signups as $signup) {
            $out[] = panel::leaf((int) $signup->timestart) + [
                'name' => format_string($signup->eventname),
                'hour' => panel::hour((int) $signup->timestart),
                'people' => get_string('panel_people', self::C, (int) $signup->people),
                'came' => (bool) $signup->attended,
            ];
        }
        return $out;
    }
}
