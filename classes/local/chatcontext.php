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
 * What Eguahe knows of whoever writes to the chat of Marandu, for the reply it proposes with the AI of the site: short
 * lines of plain text that a model reads, only of the centres of the person who asks for the draft, never of a practice
 * centre. Facts the team would need to answer, never a note on the family; no emails, phones or links.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chatcontext {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int At most this many lines: Marandu joins twenty from every plugin. */
    const LINES = 12;

    /** @var int At most this many characters in a line. */
    const LENGTH = 300;

    /** @var int At most this many households with the same phone. */
    const HOUSEHOLDS = 2;

    /**
     * The lines for a phone.
     *
     * @param string $phone Digits only, with the prefix of the country.
     * @param int $userid Who asks for the draft.
     * @param int $now
     * @return string[] Empty when the phone is of no household this person may see.
     */
    public static function lines(string $phone, int $userid, int $now = 0): array {
        global $DB;
        $digits = preg_replace('/\D+/', '', $phone);
        if ($userid <= 0 || strlen((string) $digits) < 6) {
            return [];
        }
        $now = $now ?: time();
        $centres = array_filter(centres::for_user($userid), fn($c) => !centres::is_practice((int) $c->id));
        $found = [];
        foreach ($centres as $centre) {
            $tel = phone::normalise('+' . $digits, (string) ($centre->country ?? ''));
            $adults = $tel ? chatpanel::adults_with_phone($centre, $tel) : [];
            if (!$adults) {
                continue;
            }
            [$in, $params] = $DB->get_in_or_equal($adults, SQL_PARAMS_NAMED);
            $ids = $DB->get_fieldset_sql('SELECT DISTINCT householdid FROM {' . households::ADULTS . '} WHERE id ' . $in .
                ' ORDER BY householdid DESC', $params);
            foreach ($ids as $id) {
                $household = households::get((int) $id, (int) $centre->id);
                if ($household) {
                    $found[] = [$centre, $household];
                }
            }
        }
        $lines = [];
        foreach (array_slice($found, 0, self::HOUSEHOLDS) as [$centre, $household]) {
            $lines = array_merge($lines, self::household($centre, $household, count($centres) > 1, $now));
        }
        return array_slice(array_values(array_filter(array_map([self::class, 'line'], $lines))), 0, self::LINES);
    }

    /**
     * The lines of one household.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param bool $withcentre Whether to say its centre (a person in several).
     * @param int $now
     * @return string[]
     */
    protected static function household(\stdClass $centre, \stdClass $household, bool $withcentre, int $now): array {
        global $DB;
        $c = self::C;
        $full = households::full($household);
        $lines = [];
        $adults = panel::adults_line($full->adults) ?: get_string('chat_family_noname', $c);
        $lines[] = get_string($withcentre ? 'chatctx_who_centre' : 'chatctx_who', $c, (object) ['adults' => $adults,
            'centre' => format_string($centre->name)]);

        // Each child, with the course asked for, its school year, its age and how far it went.
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centre->id], '', 'id, name, schoolyear');
        $incentre = !empty($household->incentre) || !empty($household->roster);
        $asking = false;
        foreach ($full->children as $child) {
            $offer = $offers[$child->offerid] ?? null;
            $left = !empty($child->lefttime);
            if ($child->stage === 'enrolled' && !$left) {
                $incentre = true;
            } else if (!in_array($child->stage, ['enrolled', 'closed'], true)) {
                $asking = true;
            }
            $lines[] = get_string('chatctx_child', $c, (object) [
                'name' => familypage::label_name($child) ?: get_string('chat_child_noname', $c),
                'age' => $child->birthdate ? get_string('chatctx_age', $c, birthdays::age((string) $child->birthdate, $now)) :
                    '',
                'course' => $offer ? format_string($offer->name) . ($offer->schoolyear ? ' (' . $offer->schoolyear . ')' : '') :
                    get_string('panel_nocourse', $c),
                'stage' => $left ? get_string('chat_left', $c) : get_string('stage_' . $child->stage, $c),
            ]);
        }
        $lines[] = get_string($asking && $incentre ? 'chatctx_both' : ($incentre ? 'chatctx_incentre' : 'chatctx_asking'), $c);

        // The visits: the ones they came to and the next one.
        $next = null;
        foreach ($full->signups as $signup) {
            $start = (int) $signup->timestart;
            if (!empty($signup->attended)) {
                $lines[] = get_string('chatctx_came', $c, (object) ['event' => format_string($signup->eventname),
                    'day' => panel::day_hour($start)]);
            } else if ($start > $now && !$next) {
                $next = $signup;
            }
        }
        if ($next) {
            $lines[] = get_string('chatctx_next', $c, (object) ['event' => format_string($next->eventname),
                'day' => panel::day_hour((int) $next->timestart)]);
        }

        // What the team has to know before writing.
        if (writeby::silent($household)) {
            $lines[] = get_string('chatctx_nomessages', $c, userdate(
                (int) $household->writebytime,
                get_string('strftimedateshort', 'langconfig')
            ));
        }
        if ($since = (int) $household->waitingsince) {
            $lines[] = get_string('chatctx_waiting', $c, panel::when_words(
                $since,
                $now,
                !empty($household->waitingdayonly)
            ));
        }
        if ((int) ($household->ownerid ?? 0) && ($owner = $DB->get_record('user', ['id' => $household->ownerid, 'deleted' => 0]))) {
            $lines[] = get_string('chatctx_owner', $c, fullname($owner));
        }
        if ((int) ($household->applysent ?? 0)) {
            $lines[] = get_string('chatctx_applysent', $c, userdate(
                (int) $household->applysent,
                get_string('strftimedateshort', 'langconfig')
            ));
        }
        $promises = $DB->get_records_select(
            'local_oksigeniaeguahe_promise',
            'householdid = ? AND timedone = 0',
            [$household->id],
            'dueday ASC',
            'id, body, dueday',
            0,
            2
        );
        foreach ($promises as $promise) {
            $lines[] = get_string('chatctx_promise', $c, (object) ['body' => report_ai::clean((string) $promise->body),
                'day' => $promise->dueday ? userdate((int) $promise->dueday, get_string('strftimedateshort', 'langconfig')) :
                    '-']);
        }

        // Where they come from and how they heard of the centre.
        if (trim((string) $household->municipalityname) !== '') {
            $lines[] = get_string('chatctx_zone', $c, format_string($household->municipalityname));
        }
        if ($household->heardfrom && in_array($household->heardfrom, lists::HEARD_FROM, true)) {
            $heard = get_string('heard_' . $household->heardfrom, $c);
            if (trim((string) $household->heardnet) !== '') {
                $heard .= ' (' . format_string($household->heardnet) . ')';
            }
            $lines[] = get_string('chatctx_heard', $c, $heard);
        }
        return $lines;
    }

    /**
     * A line as the model reads it: plain text on one line, without emails, phones or links, and cut short.
     *
     * @param string $line
     * @return string
     */
    protected static function line(string $line): string {
        $line = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '', $line);
        $line = preg_replace('#\b(?:https?://|www\.)\S+#iu', '', $line);
        $line = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '', $line);
        $line = trim(preg_replace('/\s+/u', ' ', strip_tags($line)));
        return \core_text::substr($line, 0, self::LENGTH);
    }
}
