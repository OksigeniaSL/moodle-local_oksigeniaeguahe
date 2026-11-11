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
     * @param string $prefill What the answer starts with, when an answer of always was chosen without the script.
     * @return array
     */
    public static function context(
        \stdClass $household,
        \stdClass $centre,
        bool $canact,
        string $newlink = '',
        int $now = 0,
        string $prefill = ''
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
        $answered = null;
        if ($household->waitingsince > 0) {
            $words = panel::wait_words((int) $household->waitingsince, $now);
            $ante = get_string('panel_waits', $c, $words['since']) .
                (panel::days_between((int) $household->waitingsince, $now) >= 2 ? ' · ' . $words['sub'] : '');
        } else {
            foreach (array_reverse($contacts) as $contact) {
                if ($contact->direction === 'out') {
                    $who = isset($people[$contact->userid]) ? fullname($people[$contact->userid]) : 'Eguahe';
                    $when = panel::when_words((int) $contact->timecreated, $now);
                    $ante = get_string('panel_answered_by', $c, (object) ['when' => $when, 'who' => $who]);
                    $answered = ['who' => $who, 'when' => $when, 'stamp' => ['top' => get_string('panel_answered', $c),
                        'day' => rtrim(userdate((int) $contact->timecreated, '%a %d'), '.'),
                        'hour' => panel::hour((int) $contact->timecreated)]];
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
        // The conversation: the two last things at sight, the ones before folded in a line.
        $history = self::history($full, $contacts, $people, $now);
        $old = array_slice($history, 0, max(0, count($history) - 2));
        $suggestions = attend::suggest($full);
        $composer = self::composer($mode, $joined, $email, $phone) + ['greeting' => $prefill !== '' ? $prefill :
            self::greeting($full), 'draft' => null, 'hassuggestions' => (bool) $suggestions, 'suggestions' => $suggestions,
            'next' => ['value' => '', 'day' => ''], 'typingurl' => (new \moodle_url($url, ['typing' => 1]))->out(false)];
        $owner = attend::owner($household, (int) $USER->id);
        $open = attend::open_promise((int) $household->id);
        $untold = self::untold($full, $offers);
        $pending = visits::pending((int) $household->id);
        return [
            'head' => panel::head([
                'back' => ['url' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
                    'name' => get_string('tab_inbox', $c)],
                'ante' => $ante,
                'title' => panel::adults_line($full->adults),
                'sub' => implode(' · ', array_filter($origin)),
                'action' => $canact ? ['url' => '#contestar', 'anchor' => true, 'name' => get_string('panel_answer', $c),
                    'icon' => panel::icon('enviado')] : null,
            ]),
            'url' => $url->out(false),
            'inboxurl' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'canact' => $canact,
            'answered' => $answered,
            'relay' => true,
            'owner' => $owner,
            'takeurl' => $url->out(false),
            'ownedbyme' => $owner && $owner['mine'],
            'promise' => $open ? attend::promise_context($open, $now, (int) $USER->id) : null,
            'typing' => attend::typing((int) $household->id, (int) $USER->id),
            'children' => self::children($full, $offers, $canact),
            'historyold' => $old,
            'history' => array_slice($history, count($old)),
            'hasold' => (bool) $old,
            'oldlabel' => get_string(count($old) === 1 ? 'panel_older_one' : 'panel_older', $c, count($old)),
            'waiting' => $household->waitingsince > 0,
            'me' => panel::person($USER),
            'composer' => $composer,
            // A brother or sister whose turn it is: who speaks is the proposal, with its why and the message written.
            'proposal' => $canact ? self::proposal($household, $full, $centre, $now) : false,
            'family' => self::family($household, $full, $canact),
            'leave' => self::leave($household, $full, $canact),
            'siblings' => self::siblings($household, $centre, $canact, $now),
            'thanks' => $canact ? self::thanks($household, $full) : false,
            'labelsurl' => $full->children ? (new \moodle_url('/local/oksigeniaeguahe/labels.php', ['c' => $centre->id,
                'household' => $household->id]))->out(false) : '',
            'noproposal' => $pending ? get_string('panel_noproposal_diary', $c) : '',
            'untold' => $untold,
            'hasuntold' => (bool) $untold,
            'untoldtext' => self::list_words($untold),
            'hasabout' => (bool) $answers || (bool) $untold,
            'mergeurl' => '',
            'adults' => self::adults($full, $joined),
            'lang' => get_string('panel_speak', $c, lists::LANG_NAMES[$household->lang] ?? $household->lang),
            'editurl' => (new \moodle_url('/local/oksigeniaeguahe/edit.php', ['c' => $centre->id,
                'id' => $household->id]))->out(false),
            'answers' => $answers,
            'hasanswers' => (bool) $answers,
            // What they told after each visit, in the survey of the event (what they were left to ask is in the thread).
            'aftervisits' => array_map(fn($told) => ['title' => get_string('panel_told_after', $c, $told['name']),
                'answers' => $told['answers']], surveys::of_household($household, current_language())),
            'visit' => self::visit($household, $centre),
            'seen' => self::seen($household),
            'ambassador' => $canact && settled::in_centre(array_values($full->children)) ? self::ambassador($household) : false,
            'events' => self::events($full),
            'hasevents' => (bool) $full->signups,
            'newlink' => $newlink,
            'erasequestion' => get_string('panel_erase_question', $c, panel::adults_line($full->adults)),
        ];
    }

    /**
     * The brothers and sisters for later of a household, where children enter by age: their year of birth, when it
     * would be their turn, whether the family asked to be told, and noting one more or taking one out.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param bool $canact
     * @param int $now
     * @return array|false
     */
    protected static function siblings(\stdClass $household, \stdClass $centre, bool $canact, int $now) {
        if (!siblings::on($centre)) {
            return false;
        }
        $c = self::C;
        $rows = [];
        $day = get_string('strftimedate', 'langconfig');
        foreach (siblings::of((int) $household->id) as $sibling) {
            $label = siblings::year_label($centre, siblings::turn_year($centre, $sibling));
            $a = (object) ['name' => $sibling->firstname, 'year' => $label, 'born' => (int) $sibling->birthyear];
            $state = '';
            if ((int) $sibling->confirmed) {
                $state = get_string('panel_sibling_confirmed', $c);
            } else if ((int) $sibling->proposed) {
                $state = get_string('panel_sibling_proposed', $c, userdate((int) $sibling->proposed, $day));
            } else if ((int) $sibling->notify) {
                $state = get_string('panel_sibling_notify', $c);
            }
            $rows[] = ['id' => (int) $sibling->id, 'name' => $sibling->firstname,
                'line' => get_string('panel_sibling_line', $c, $a), 'state' => $state,
                'turn' => siblings::in_turn($centre, $sibling, $now)];
        }
        $year = (int) date('Y', $now);
        return [
            'rows' => $rows,
            'hasrows' => (bool) $rows,
            'canact' => $canact,
            'years' => array_map(fn($y) => ['value' => $y], range($year, $year - 12)),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * An enrolled child in the file: in the centre since when, when it started, or when and why it left.
     *
     * @param \stdClass $child
     * @param string $day The format of a date.
     * @return string
     */
    protected static function incentre(\stdClass $child, string $day): string {
        $c = self::C;
        if ($child->stage !== 'enrolled') {
            return '';
        }
        if ((int) ($child->lefttime ?? 0)) {
            $why = get_string('left_' . ($child->leftreason ?: 'unknown'), $c);
            return get_string('panel_left_on', $c, (object) ['day' => userdate((int) $child->lefttime, $day), 'why' => $why]);
        }
        if ((int) ($child->started ?? 0)) {
            return get_string('panel_started_on', $c, userdate((int) $child->started, $day));
        }
        return get_string('panel_incentre_since', $c, userdate((int) $child->stagetime, $day));
    }

    /**
     * The line of a family that left: when and why, and the line of whoever heard it.
     *
     * @param array $gone From settled::farewell().
     * @return string
     */
    protected static function gone_line(array $gone): string {
        $c = self::C;
        $a = (object) ['day' => userdate($gone['time'], get_string('strftimedate', 'langconfig')),
            'why' => get_string('left_' . $gone['reason'], $c)];
        return get_string('panel_gone_line', $c, $a);
    }

    /**
     * «They are not with us any more», for a family in the centre: why, from the short list, and a line of whoever
     * heard it; once they left, when and why, and «They are still with us» to undo it.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param bool $canact
     * @return array|false
     */
    protected static function leave(\stdClass $household, \stdClass $full, bool $canact) {
        $c = self::C;
        $children = array_values($full->children);
        $gone = settled::farewell($household, $children);
        if (!$gone && !settled::in_centre($children)) {
            return false;
        }
        $reasons = [];
        foreach (settled::LEFT_REASONS as $reason) {
            $reasons[] = ['value' => $reason, 'name' => panel::upper_first(get_string('left_' . $reason, $c))];
        }
        // The common family record of the centre says some left: a person says whether they did.
        $said = $gone ? [] : family::gone($children);
        $saidnames = self::list_words(array_map(fn($k) => panel::tag($k), $said));
        return [
            'gone' => (bool) $gone,
            'familysays' => $said ? get_string('panel_family_gone' . (count($said) > 1 ? '_many' : ''), $c, $saidnames) : '',
            'goneline' => $gone ? self::gone_line($gone) . '.' . ($gone['note'] !== '' ? ' ' . $gone['note'] : '') : '',
            'reasons' => $reasons,
            'canact' => $canact,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The common family record of the centre, in the file: a hint that the family is already one of the centre (by a
     * phone verified there or an email), for a person to confirm; or that it is, as a person said; and which children
     * are in it since they enrolled. Nothing without the record.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param bool $canact
     * @return array|false
     */
    protected static function family(\stdClass $household, \stdClass $full, bool $canact) {
        if (!family::available()) {
            return false;
        }
        $c = self::C;
        $linked = (int) ($household->familylink ?? 0);
        $hints = $linked === 0 ? family::hints($full) : [];
        $in = array_values(array_filter($full->children, fn($k) => !empty($k->familychild) && empty($k->lefttime)));
        if (!$hints && $linked <= 0 && !$in) {
            return false;
        }
        $action = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false);
        $found = [];
        foreach ($hints as $familyid => $names) {
            $found[] = ['familyid' => $familyid, 'line' => get_string('panel_family_hint', $c, self::list_words($names))];
        }
        return [
            'hints' => $found,
            'hashints' => (bool) $found,
            'linked' => $linked > 0 ? get_string('panel_family_linked', $c) : '',
            'in' => $in ? get_string('panel_family_in', $c, self::list_words(array_map(fn($k) => panel::tag($k), $in))) : '',
            'canact' => $canact,
            'action' => $action,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * What the proposal says: the farewell to a family that left, else a brother or sister whose turn it is.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param \stdClass $centre
     * @param int $now
     * @return array|false
     */
    protected static function proposal(\stdClass $household, \stdClass $full, \stdClass $centre, int $now) {
        return self::farewell_proposal($household, $full)
            ?: self::firstday_proposal($household, $full, $centre, $now)
            ?: self::birthday_proposal($household, $full, $centre, $now)
            ?: self::sibling_proposal($household, $full, $centre, $now);
    }

    /**
     * An age in words: «1 año», «4 años».
     *
     * @param int $age
     * @return string
     */
    public static function years(int $age): string {
        return $age === 1 ? get_string('panel_age_one', self::C) : get_string('panel_age_many', self::C, $age);
    }

    /**
     * The course a child is of by its date of birth, when it is not the one it asks for or is in: «By their age, their
     * course is …» without a course, «would be» with another one. Empty when it fits or no course of the centre fits.
     *
     * @param string $birthdate
     * @param \stdClass|null $offer
     * @param \stdClass $centre
     * @return string
     */
    protected static function course_hint(string $birthdate, ?\stdClass $offer, \stdClass $centre): string {
        $fits = birthdays::courses($birthdate, $centre, time());
        if (!$fits || ($offer && in_array((int) $offer->id, array_map(fn($o) => (int) $o->id, $fits), true))) {
            return '';
        }
        $names = array_map(fn($o) => format_string($o->name) . ' (' . s($o->schoolyear) . ')', $fits);
        return get_string($offer ? 'panel_birthday_course_other' : 'panel_birthday_course', self::C, self::list_words($names));
    }

    /**
     * The birthday of a child: the day before and on the day, what a person writes to the family, with its why and the
     * message written in the tone of the age it turns and of where the child is (birthdays::moment). Sent by a person,
     * never by itself.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param \stdClass $centre
     * @param int $now
     * @return array|false
     */
    public static function birthday_proposal(\stdClass $household, \stdClass $full, \stdClass $centre, int $now) {
        $step = birthdays::step($household, $centre, $now);
        if (!$step) {
            return false;
        }
        $c = self::C;
        $a = (object) ['names' => self::list_words(array_map(fn($k) => panel::tag($k), $step['children'])),
            'day' => userdate($step['day'], get_string('strftimedateshort', 'langconfig'))];
        $lines = [];
        $gifted = [];
        foreach ($step['children'] as $child) {
            $age = $step['ages'][(int) $child->id];
            $moment = $step['moments'][(int) $child->id];
            $band = $age <= 5 ? 'small' : ($age <= 11 ? 'child' : 'older');
            // In class, from its class; before the first day, «they are waiting for you»; still deciding, the centre.
            $id = $moment === 'deciding' ? 'panel_birthday_deciding' :
                ($moment === 'waiting' ? 'panel_birthday_wait_' . $band : 'panel_birthday_' . $band);
            $lines[] = get_string($id, $c, (object) ['name' => panel::tag($child), 'age' => self::years($age), 'n' => $age,
                'when' => get_string('panel_birthday_when_' . $step['step'], $c)]);
            if ($moment !== 'deciding') {
                $gifted[] = $child;
            }
        }
        // The piece of their age waits on their page, from the day itself; a family still deciding has none.
        if ($gifted) {
            $names = self::list_words(array_map(fn($k) => panel::tag($k), $gifted));
            $lines[] = get_string('panel_birthday_gift_' . $step['step'], $c, $names);
        }
        return [
            'sentence' => get_string('panel_birthday_' . $step['step'], $c),
            'why' => get_string('panel_birthday_' . $step['step'] . '_why', $c, $a),
            'text' => self::greeting($full) . implode(' ', $lines),
            'button' => get_string('panel_sibling_write', $c),
            'url' => (new \moodle_url(
                '/local/oksigeniaeguahe/household.php',
                ['id' => $household->id, 'cumple' => 1],
                'borrador'
            ))->out(false),
            'visit' => false,
            'help' => get_string('panel_proposal_help_written', $c),
        ];
    }

    /**
     * The farewell to a family that left, until someone writes to them: one message, sent by a person, with thanks,
     * their page that stays, and one question if they wish.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @return array|false
     */
    protected static function farewell_proposal(\stdClass $household, \stdClass $full) {
        $gone = settled::farewell($household, array_values($full->children));
        if (!$gone || $gone['written']) {
            return false;
        }
        $c = self::C;
        $when = (object) ['day' => userdate($gone['time'], get_string('strftimedate', 'langconfig')),
            'why' => get_string('left_' . $gone['reason'], $c)];
        return [
            'sentence' => get_string('panel_farewell', $c),
            'why' => get_string('panel_farewell_why', $c, $when),
            'text' => self::greeting($full) . get_string('panel_farewell_message', $c),
            'button' => get_string('panel_sibling_write', $c),
            'url' => (new \moodle_url(
                '/local/oksigeniaeguahe/household.php',
                ['id' => $household->id, 'despedida' => 1],
                'borrador'
            ))->out(false),
            'visit' => false,
            // Written by Eguahe from what it knows, not from the agenda of a visit.
            'help' => get_string('panel_proposal_help_written', self::C),
        ];
    }

    /**
     * Around the first day, what a person writes to the family, with its why and the message written: their story in
     * the days before, the eve, and «How was their first day?» after it. Sent by a person, never by itself.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param \stdClass $centre
     * @param int $now
     * @return array|false
     */
    public static function firstday_proposal(\stdClass $household, \stdClass $full, \stdClass $centre, int $now) {
        $step = story::step($household, $centre, $now);
        if (!$step) {
            return false;
        }
        $c = self::C;
        $names = fn(array $kids) => self::list_words(array_map(fn($k) => panel::tag($k), $kids));
        $a = (object) ['names' => $names($step['children']), 'stories' => $names($step['stories']),
            'day' => $step['first'] ? userdate($step['first'], get_string('strftimedate', 'langconfig')) : ''];
        $why = 'panel_story_' . $step['step'] . ($step['step'] === 'story' && !$step['first'] ? '_why_nodate' : '_why');
        $text = $step['step'] === 'story' ? get_string('panel_story_story_message', $c, $a) :
            self::firstday_text($step, $centre, $names);
        return [
            'sentence' => get_string('panel_story_' . $step['step'], $c),
            'why' => get_string($why, $c, $a),
            'text' => self::greeting($full) . $text,
            'button' => get_string('panel_sibling_write', $c),
            'url' => (new \moodle_url(
                '/local/oksigeniaeguahe/household.php',
                ['id' => $household->id, 'primerdia' => 1],
                'borrador'
            ))->out(false),
            'visit' => false,
            // Written by Eguahe from what it knows, not from the agenda of a visit.
            'help' => get_string('panel_proposal_help_written', self::C),
        ];
    }

    /**
     * The eve and the day after, written for each child in the tone of its age (ages::step()): the little ones by their
     * name on the door and their peg, the older ones by their classroom and their timetable; and after it, from how
     * they came home to whatever they would change. The eldest is never spoken of as a little one. The eve opens with
     * «the big day» while there is a young one, and with the course starting when they are all older.
     *
     * @param array $step story::step()
     * @param \stdClass $centre
     * @param callable $names From some children to their names, in a list.
     * @return string
     */
    protected static function firstday_text(array $step, \stdClass $centre, callable $names): string {
        $c = self::C;
        $offers = centres::offers((int) $centre->id);
        $sm = get_string_manager();
        $say = function (string $key, array $kids) use ($c, $names, $sm): string {
            $key .= count($kids) > 1 ? '_many' : '';
            return $sm->string_exists($key, $c) ? get_string($key, $c, $names($kids)) : '';
        };
        $parts = [];
        if ($step['step'] === 'eve') {
            // Three tones on the eve: the youngest, the children, and the older ones (or a course of no known age).
            $band = fn(string $s) => match (true) {
                $s === ages::NOCOURSE || $s === 'r1' => 'small',
                in_array($s, ['r2', 'r3', 'r4'], true) => 'child',
                default => 'older',
            };
            $groups = ages::groups($step['children'], $offers, $centre, $band);
            $allolder = !array_filter($groups, fn($g) => $g[0] !== 'older');
            $parts[] = get_string($allolder ? 'panel_story_eve_open_older' : 'panel_story_eve_open', $c);
            foreach ($groups as [$key, $kids]) {
                $parts[] = $say('panel_story_eve_' . $key, $kids);
            }
            if ($step['stories']) {
                $parts[] = $say('panel_story_eve_story', $step['stories']);
            }
            $parts[] = get_string('panel_story_eve_close', $c);
        } else {
            // The day after, each step its own; without a course, as the youngest; of no known age, the plain tone.
            $band = fn(string $s) => match ($s) {
                ages::NOCOURSE => 'r1',
                ages::UNKNOWN => 'r5',
                default => $s,
            };
            $parts[] = get_string('panel_story_after_open', $c);
            foreach (ages::groups($step['children'], $offers, $centre, $band) as [$key, $kids]) {
                $line = $say('panel_story_after_' . $key, $kids);
                $parts[] = $line !== '' ? $line : $say('panel_story_after_r5', $kids);
            }
            $parts[] = get_string('panel_story_after_close', $c);
        }
        return implode(' ', array_filter($parts, fn($p) => $p !== ''));
    }

    /**
     * The thanks to the family of the centre that told them about it, once a child of theirs is enrolled: a sentence
     * and a text written, for the person to decide; the two households are never linked.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @return array|false
     */
    protected static function thanks(\stdClass $household, \stdClass $full) {
        $who = trim((string) ($household->heardwho ?? ''));
        $told = $who !== '' && ($household->heardfrom ?? '') === 'centrefamilies' && !(int) ($household->thanked ?? 0);
        if (!$told || !settled::in_centre(array_values($full->children))) {
            return false;
        }
        $c = self::C;
        $them = panel::adults_line($full->adults);
        return [
            'sentence' => get_string('panel_thanks_sentence', $c, s($who)),
            'why' => get_string(count($full->adults) === 1 ? 'panel_thanks_why_one' : 'panel_thanks_why', $c, s($them)),
            'text' => get_string('panel_thanks_text', $c, $them),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The proposal of a brother or sister whose turn it is and nobody wrote to yet: «A Mateo le toca el curso que
     * viene», why (they asked when enrolling Leo, or they told it when signing up), and the message, written.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param \stdClass $centre
     * @param int $now
     * @return array|false
     */
    public static function sibling_proposal(\stdClass $household, \stdClass $full, \stdClass $centre, int $now) {
        $c = self::C;
        foreach (siblings::of((int) $household->id) as $sibling) {
            if ((int) $sibling->proposed || !siblings::in_turn($centre, $sibling, $now)) {
                continue;
            }
            $label = siblings::year_label($centre, siblings::turn_year($centre, $sibling));
            $a = (object) ['name' => $sibling->firstname, 'year' => $label];
            $enrolled = settled::enrolled(array_values($full->children));
            if ((int) $sibling->notify) {
                $why = get_string($enrolled ? 'panel_sibling_why_asked_enrolled' : 'panel_sibling_why_asked', $c, (object) [
                    'who' => $enrolled ? panel::tag($enrolled[0]) : '',
                    'day' => userdate((int) $sibling->notify, get_string('strftimedate', 'langconfig'))]);
            } else {
                $why = get_string($sibling->source === 'team' ? 'panel_sibling_why_team' : 'panel_sibling_why_signup', $c);
            }
            $text = self::greeting($full) . get_string('panel_sibling_message', $c, $a);
            return [
                'sentence' => get_string('panel_sibling_proposal', $c, $a),
                'why' => $why,
                'text' => $text,
                'button' => get_string('panel_sibling_write', $c),
                'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id,
                    'hermano' => $sibling->id], 'borrador'))->out(false),
                'visit' => false,
                'help' => get_string('panel_proposal_help_written', $c),
            ];
        }
        return false;
    }

    /**
     * How an answer starts: «Hola, Marta y Daniel:», with the first names of the adults.
     *
     * @param \stdClass $full
     * @return string
     */
    public static function greeting(\stdClass $full): string {
        $names = array_values(array_filter(array_map(fn($a) => trim((string) $a->firstname), $full->adults)));
        return $names ? get_string('panel_greeting', self::C, self::list_words($names)) . ' ' : '';
    }

    /**
     * Some words in a list: «a, b y c».
     *
     * @param string[] $words
     * @return string
     */
    protected static function list_words(array $words): string {
        $words = array_values($words);
        $last = array_pop($words);
        return $words ? get_string('andlist', self::C, (object) ['list' => implode(', ', $words), 'last' => $last]) :
            (string) $last;
    }

    /**
     * What they have not told us yet, to ask when answering: where they live, how they heard of the centre, and the
     * course of a child.
     *
     * @param \stdClass $full
     * @param array $offers
     * @return string[]
     */
    protected static function untold(\stdClass $full, array $offers): array {
        $c = self::C;
        $out = [];
        if (trim((string) $full->municipalityname) === '' && trim((string) $full->municipality) === '') {
            $out[] = get_string('panel_untold_where', $c);
        }
        if (empty($full->heardfrom)) {
            $out[] = get_string('panel_untold_heard', $c);
        }
        foreach ($full->children as $child) {
            if (empty($offers[$child->offerid])) {
                $out[] = get_string('panel_untold_course', $c, panel::tag($child));
            }
        }
        return $out;
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
        $centre = centres::get((int) $full->centreid);
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
            $birth = trim((string) ($child->birthdate ?? ''));
            $day = get_string('strftimedate', 'langconfig');
            $drawing = drawings::url((int) $child->id);
            $out[] = [
                'id' => $child->id,
                'hanging' => panel::hanging($i, $tag, $offer ? (string) $offer->name : '', $centre),
                'drawing' => $drawing ? $drawing->out(false) : '',
                'drawingalt' => get_string('panel_drawing_alt', $c, $tag),
                // Their drawing, asked for while they are young; one already sent is always seen and can change.
                'drawingpage' => $canact && ($drawing || ages::young($child, $offers, $centre)) ?
                    (new \moodle_url('/local/oksigeniaeguahe/drawing.php', ['child' => $child->id]))->out(false) : '',
                'name' => trim((string) $child->firstname) !== '' ? $child->firstname : $tag,
                'tagline' => trim((string) $child->tagname) !== '' && $child->tagname !== $child->firstname ?
                    get_string('panel_on_label', $c, $child->tagname) : '',
                // Their birthday and age, when the family gave the date; and the course it is of by its age, when
                // that is not the one asked for.
                'birthline' => $birth !== '' ? get_string('panel_birthday_line', $c, (object) [
                    'day' => userdate(birthdays::next($birth, time())[0], get_string('strftimedateshort', 'langconfig')),
                    'age' => self::years(birthdays::age($birth, time()))]) : '',
                'coursehint' => $birth !== '' ? self::course_hint($birth, $offer, $centre) : '',
                'course' => $offer ? get_string('panel_course_year', $c, (object) ['name' => format_string($offer->name),
                    'year' => s($offer->schoolyear)]) : get_string('panel_nocourse', $c),
                'steps' => $steps,
                'closed' => $child->stage === 'closed',
                'reason' => $child->stage === 'closed' && $child->closedreason ?
                    get_string('closed_' . $child->closedreason, $c) : '',
                'waitlist' => $child->stage === 'waitlist',
                // Enrolled: in the centre, and on the first day whether they started.
                'incentre' => self::incentre($child, $day),
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
            $channel = in_array($contact->channel, lists::CHANNELS, true) || $contact->channel === lists::PAGE ?
                $contact->channel : 'other';
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
        $likes = hobbies::of((int) $full->id);
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
            // What they like doing, as they told it on their page.
            $words = isset($likes[$adult->id]) ? hobbies::words($likes[$adult->id]) : '';
            $out[] = ['name' => trim($adult->firstname . ' ' . $adult->lastname), 'ways' => $ways, 'hasways' => (bool) $ways,
                'likes' => $words !== '' ? get_string('panel_adult_likes', $c, $words) : false];
        }
        return $out;
    }

    /**
     * Whether the family is an ambassador of the centre, and what its invitation brought, as numbers; and the button to
     * make it one (they said so in person) or to stop.
     *
     * @param \stdClass $household
     * @return array is, text, do and label.
     */
    protected static function ambassador(\stdClass $household): array {
        $c = self::C;
        $is = (int) ($household->volunteer ?? 0) > 0;
        $counts = share::ambassador_counts($household);
        $since = userdate((int) $household->volunteer, get_string('strftimedate', 'langconfig'));
        return [
            'is' => $is,
            'text' => $is ? get_string('panel_ambassador_is', $c, (object) ['since' => $since, 'opened' => $counts['opened'],
                'arrived' => $counts['arrived']]) : get_string('panel_ambassador_not', $c),
            'do' => $is ? 'notambassador' : 'ambassador',
            'label' => get_string($is ? 'panel_ambassador_stop' : 'panel_ambassador_make', $c),
        ];
    }

    /**
     * What they do on their page and with the mails, as facts: how many times they opened it and on how many days, the
     * last time, how far down they go, what they touch most and what they pressed in the mails. Never a score.
     *
     * @param \stdClass $household
     * @return array
     */
    protected static function seen(\stdClass $household): array {
        $c = self::C;
        $s = seen::summary((int) $household->id);
        if (!$s['opens']) {
            return ['facts' => [get_string('panel_facts_none', $c)]];
        }
        $when = userdate($s['last'], get_string('strftimedaydatetime', 'langconfig'));
        $facts = [get_string($s['opens'] === 1 ? 'panel_facts_opened_one' : 'panel_facts_opened', $c, (object) [
            'n' => $s['opens'], 'last' => $when])];
        if ($s['days'] > 1) {
            $facts[] = get_string('panel_facts_days', $c, $s['days']);
        }
        // Names inside a sentence: the first letter small.
        $small = fn(string $s) => \core_text::strtolower(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        if ($s['deepest'] !== '') {
            $facts[] = get_string('panel_facts_deepest', $c, $small(get_string('panel_likes_part_' . $s['deepest'], $c)));
        }
        $top = array_slice($s['touches'], 0, 3, true);
        if ($top) {
            $facts[] = get_string('panel_facts_touched', $c, implode(', ', array_map(
                fn($what, $n) => $small(get_string('panel_likes_touch_' . $what, $c)) . ' (' . $n . ')',
                array_keys($top),
                $top
            )));
        }
        if ($s['mails']) {
            $facts[] = get_string('panel_facts_mails', $c, implode(', ', array_map(
                fn($kind, $n) => $small(get_string('mail_name_' . $kind, $c)) . ' (' . $n . ')',
                array_keys($s['mails']),
                $s['mails']
            )));
        }
        return ['facts' => $facts];
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
