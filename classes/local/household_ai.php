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
 * The AI of the team for each family (the fourth part of the plan of the first version, 7-11): behind the team, never
 * speaking to families. A draft of the answer, which a person reads, changes and sends; where the family is, in two
 * sentences of facts; and what each message of a family asks, in a line for the inbox. Never a mark, a guess or a
 * prediction about a family: the red line of Eguahe. It goes through the AI of the centre (core_ai), only when the
 * centre has it on; without it, nothing changes. What it reads carries no emails, phones or links (report_ai::clean()).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class household_ai {
    /** @var int The last messages of a family the AI reads. */
    const THREAD = 12;

    /** @var int The characters of each message it reads. */
    const CUT = 1200;

    /** @var int The messages read in a line at most each run of the task, for the whole site. */
    const LINES = 20;

    /** @var int How old a message can be to be read in a line, when the AI of its centre is on. */
    const RECENT = 7 * DAYSECS;

    /** @var string[] The languages, as the AI is told them. */
    const LANGS = ['es' => 'Spanish', 'en' => 'English', 'pt' => 'European Portuguese', 'pt_br' => 'Brazilian Portuguese',
        'ar' => 'Arabic'];

    /**
     * What the AI is told of a family and of its centre: only facts, without contact data.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return array
     */
    public static function facts(\stdClass $household, \stdClass $centre, int $now): array {
        global $DB;
        $full = households::full($household);
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id]);
        $children = [];
        foreach ($full->children as $child) {
            $offer = $offers[$child->offerid] ?? null;
            $birth = trim((string) ($child->birthdate ?? ''));
            $children[] = array_filter([
                'name' => familypage::label_name($child),
                'course' => $offer ? format_string($offer->name) . ' ' . $offer->schoolyear : '',
                'age' => $birth !== '' ? birthdays::age($birth, $now) : null,
                'stage' => get_string('stage_' . $child->stage, 'local_oksigeniaeguahe'),
            ], fn($v) => $v !== '' && $v !== null);
        }
        $visits = [];
        foreach ($full->signups as $signup) {
            $visits[] = ['act' => format_string($signup->eventname), 'when' => userdate(
                (int) $signup->timestart,
                '%Y-%m-%d %H:%M'
            ), 'came' => !empty($signup->attended) ? 'yes' : ((int) $signup->timestart < $now ? 'no' :
                'not yet')];
        }
        $thread = [];
        $contacts = $DB->get_records(
            households::CONTACTS,
            ['householdid' => $household->id],
            'timecreated DESC, id DESC',
            'id, direction, channel, body, timecreated',
            0,
            self::THREAD
        );
        foreach (array_reverse($contacts) as $contact) {
            $body = \core_text::substr(report_ai::clean((string) $contact->body), 0, self::CUT);
            if ($body === '') {
                continue;
            }
            $thread[] = ['from' => ['in' => 'family', 'out' => 'centre', 'note' => 'note of the team'][$contact->direction] ??
                'centre', 'when' => userdate((int) $contact->timecreated, '%Y-%m-%d'), 'text' => $body];
        }
        $acts = [];
        foreach (events::for_centre((int) $centre->id) as $event) {
            if ((int) $event->timestart > $now && !empty($event->open) && empty($event->tailored)) {
                $acts[] = ['act' => format_string($event->name), 'when' => userdate((int) $event->timestart, '%Y-%m-%d %H:%M'),
                    'where' => format_string((string) $event->place)];
            }
        }
        return [
            'centre' => ['name' => format_string($centre->name), 'answers within days' => (int) ($centre->replydays ?? 2),
                'courses offered' => array_values(array_unique(array_map(
                    fn($o) => format_string($o->name),
                    array_filter($offers, fn($o) => !empty($o->active))
                ))),
                'next open acts' => array_slice($acts, 0, 4)],
            'family' => ['adults' => array_values(array_map(fn($a) => trim((string) $a->firstname), $full->adults)),
                'children' => $children, 'visits' => $visits,
                'heard of the centre' => (string) ($household->heardfrom ?? '')],
            'messages' => $thread,
        ];
    }

    /**
     * What is asked for a draft and a summary.
     *
     * @param array $facts self::facts().
     * @param string $lang The language of the family.
     * @param string $started What the person of the team began to write, if anything.
     * @return string
     */
    public static function prompt(array $facts, string $lang, string $started): string {
        $language = self::LANGS[$lang] ?? 'the language of the family';
        return "You help a person of the admissions team of a school or educational centre answer a family. The person " .
            "reads what you write, changes it and sends it; families always speak with people.\n\n" .
            "Rules, all of them:\n" .
            "- Write the draft in $language, warm and plain, as a person of the school writes to a family; short." .
            (str_starts_with($language, 'Spanish') ? ' Address the family with «ustedes», never «vosotros» nor «usted».' :
            '') . "\n" .
            "- Answer what the family asked in its last messages. Say only the facts below; never invent a date, a price, a " .
            "place, a name or a figure. When they ask something the facts do not say, say a person of the centre will tell " .
            "them, without guessing.\n" .
            "- If an open act fits, invite them to it with its day.\n" .
            "- No greeting, no farewell or closing line («Un abrazo», «Saludos», «Kind regards») and no signature: the " .
            "person adds them.\n" .
            ($started !== '' ? "- The person began to write this; keep its meaning and finish it: «" . $started . "»\n" : '') .
            "- The summary, in two sentences in $language, says only facts: who comes and for which course, what they " .
            "asked, where they are (a visit booked, they came) and what is pending. Never judge the family, never guess " .
            "what they think or whether they will enrol, never a mark of any kind.\n\n" .
            "Answer only with JSON, this shape:\n" .
            '{"draft": "the answer, up to 900 characters", "summary": "two sentences"}' . "\n\n" .
            "The facts:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * A draft of the answer to a family and where it is, from the AI of the centre; the summary is kept for its file.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $userid Who asks.
     * @param string $started What the person began to write.
     * @param int $now
     * @return array|null draft and summary; null without the AI of the centre, or when it does not answer.
     */
    public static function draft(\stdClass $household, \stdClass $centre, int $userid, string $started, int $now): ?array {
        global $DB;
        if (!ai::ready($centre)) {
            return null;
        }
        $started = \core_text::substr(report_ai::clean($started), 0, self::CUT);
        try {
            $answer = ai::ask($centre, self::prompt(
                self::facts($household, $centre, $now),
                (string) $household->lang,
                $started
            ), $userid);
        } catch (\moodle_exception $e) {
            return null;
        }
        $data = ai::json($answer);
        $cut = fn($v, int $n) => \core_text::substr(trim(clean_param((string) (is_scalar($v) ? $v : ''), PARAM_TEXT)), 0, $n);
        $draft = $cut($data['draft'] ?? '', 1500);
        $summary = $cut($data['summary'] ?? '', 600);
        if ($summary !== '') {
            $DB->update_record(households::T, (object) ['id' => $household->id, 'aisummary' => $summary,
                'aisummarytime' => $now]);
        }
        return $draft !== '' || $summary !== '' ? ['draft' => $draft, 'summary' => $summary] : null;
    }

    /**
     * What a message of a family asks or tells, in a line, for the inbox.
     *
     * @param string $body
     * @param string $lang The language of the centre: the team reads the line.
     * @return string
     */
    public static function line_prompt(string $body, string $lang): string {
        $language = self::LANGS[$lang] ?? 'the language of the school';
        return "A family wrote this to the admissions team of a school. In $language and in at most eight words, say what " .
            "it asks or tells, as a line for the team, for example «Asks about places for three-year-olds» (in $language). " .
            "Facts only: never judge the family or guess anything about it. If it asks or tells nothing, answer «-». " .
            "Answer only with the line.\n\nThe message:\n" . $body . "\n";
    }

    /**
     * Reads in a line the recent messages of families that are not read yet, in the centres with their AI on.
     *
     * @param int $now
     * @return int How many were read.
     */
    public static function read_messages(int $now): int {
        global $DB;
        $done = 0;
        foreach (centres::all() as $centre) {
            if ($done >= self::LINES || !ai::ready($centre)) {
                continue;
            }
            $waiting = $DB->get_records_select(
                households::CONTACTS,
                "centreid = :centreid AND direction = 'in' AND asks IS NULL AND timecreated >= :since",
                ['centreid' => $centre->id, 'since' => $now - self::RECENT],
                'timecreated ASC',
                'id, body',
                0,
                self::LINES - $done
            );
            foreach ($waiting as $contact) {
                $body = \core_text::substr(report_ai::clean((string) $contact->body), 0, self::CUT);
                $line = '';
                if ($body !== '') {
                    try {
                        $line = trim(clean_param(
                            ai::ask($centre, self::line_prompt($body, (string) $centre->lang), 0),
                            PARAM_TEXT
                        ), " \t\n\r\"«»");
                    } catch (\moodle_exception $e) {
                        // Not answered now: it is read on the next run.
                        continue;
                    }
                }
                $DB->set_field(
                    households::CONTACTS,
                    'asks',
                    $line === '-' ? '' : \core_text::substr($line, 0, 255),
                    ['id' => $contact->id]
                );
                $done++;
            }
        }
        return $done;
    }
}
