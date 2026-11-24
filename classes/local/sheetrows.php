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
 * What was taken from sheets before Eguahe told a day from a moment and the team from the family, put right: the days
 * that were given an hour nobody wrote, the values of a row kept as if they were titles, and the rows of the team that
 * were kept as something the family wrote, for which the family was waiting.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sheetrows {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] What the lines of the sheets say when the team was in touch with a family or gave it its visit. */
    const TOUCHES = ['teamnote_visitbooked', 'teamnote_attended', 'teamnote_absent', 'teamnote_cancelled', 'teamnote_callback',
        'teamnote_closed', 'teamnote_stage_application', 'teamnote_stage_admitted', 'teamnote_stage_waitlist'];

    /**
     * Marks as only a day the lines already kept whose hour nobody wrote: those of an entry at noon sharp, and those of
     * the team in a sheet, at noon or at the very moment of the row they were in, which is of something else.
     *
     * @return int How many lines.
     */
    public static function mark_days(): int {
        global $DB;
        $zone = \core_date::get_server_timezone_object();
        $noon = fn(int $t) => (new \DateTime('@' . $t))->setTimezone($zone)->format('H:i:s') === '12:00:00';
        $ids = [];
        $rows = $DB->get_recordset_select(
            households::CONTACTS,
            'entryid > 0 AND dayonly = 0',
            [],
            '',
            'id, timecreated'
        );
        foreach ($rows as $row) {
            if ($noon((int) $row->timecreated)) {
                $ids[(int) $row->id] = true;
            }
        }
        $rows->close();
        // The lines of the families with something taken from a sheet of the team.
        $homes = [];
        $rows = $DB->get_recordset_sql("SELECT c.id, c.householdid, c.direction, c.entryid, c.timecreated
                                          FROM {" . households::CONTACTS . "} c
                                         WHERE c.dayonly = 0 AND EXISTS (SELECT 1 FROM {" . teamnotes::T . "} n
                                                                          WHERE n.householdid = c.householdid)");
        foreach ($rows as $row) {
            $kind = (int) $row->entryid ? 'rows' : ($row->direction !== 'in' ? 'notes' : '');
            if ($kind !== '') {
                $homes[(int) $row->householdid][$kind][(int) $row->id] = (int) $row->timecreated;
            }
        }
        $rows->close();
        foreach ($homes as $lines) {
            $at = array_flip($lines['rows'] ?? []);
            foreach ($lines['notes'] ?? [] as $id => $time) {
                if ($noon($time) || isset($at[$time]) || isset($at[$time - MINSECS])) {
                    $ids[$id] = true;
                }
            }
            // A row of a visit was kept a minute before it.
            $visits = array_flip($lines['notes'] ?? []);
            foreach ($lines['rows'] ?? [] as $id => $time) {
                if (isset($visits[$time + MINSECS])) {
                    $ids[$id] = true;
                }
            }
        }
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $DB->set_field_select(households::CONTACTS, 'dayonly', 1, "id $in", $params);
        }
        $DB->execute('UPDATE {' . households::T . '} SET waitingdayonly = 1
                       WHERE waitingsince > 0 AND EXISTS (SELECT 1 FROM {' . households::CONTACTS . '} c
                                                           WHERE c.householdid = {' . households::T . '}.id AND c.dayonly = 1
                                                                 AND c.timecreated = {' . households::T . '}.waitingsince)');
        $DB->execute('UPDATE {' . entries::T . '} SET lastdayonly = 1
                       WHERE lastreceived > 0 AND EXISTS (SELECT 1 FROM {' . households::CONTACTS . '} c
                                                           WHERE c.entryid = {' . entries::T . '}.id AND c.dayonly = 1
                                                                 AND c.timecreated = {' . entries::T . '}.lastreceived)');
        return count($ids);
    }

    /**
     * Takes out of the questions of every entry, and of their examples, what a row without its titles left in them.
     *
     * @return int How many entries had something of it.
     */
    public static function clean_titles(): int {
        global $DB;
        $n = 0;
        foreach ($DB->get_records_select(entries::T, 'questions IS NOT NULL', [], '', 'id, questions, examples') as $entry) {
            $questions = array_values(json_decode((string) $entry->questions, true) ?: []);
            $examples = json_decode((string) $entry->examples, true) ?: [];
            [$cleaned, $kept] = entries::clean_questions($questions, $examples);
            if ($cleaned === $questions && $kept === $examples) {
                continue;
            }
            $DB->update_record(entries::T, (object) ['id' => $entry->id,
                'questions' => json_encode(array_values($cleaned), JSON_UNESCAPED_UNICODE),
                'examples' => json_encode((object) $kept, JSON_UNESCAPED_UNICODE)]);
            $n++;
        }
        return $n;
    }

    /**
     * The rows of the sheets of the team of a centre kept as messages of a family become notes of the team, and each
     * family that waited because of one waits for what it truly wrote and nobody answered, or for nothing.
     *
     * @param \stdClass $centre
     * @param bool $run Without it, it only counts.
     * @return array Counts: sheets, unclear (sheets a form fills too, left as they are), lines, families, ads (lines of a
     *     contact of an ad, left as they are), waiting (families waiting before), free (that stop waiting), moved (that wait
     *     since another moment) and after (families waiting then).
     */
    public static function fix(\stdClass $centre, bool $run): array {
        global $DB;
        $counts = ['sheets' => 0, 'unclear' => 0, 'lines' => 0, 'families' => 0, 'ads' => 0,
            'waiting' => households::waiting_count((int) $centre->id), 'free' => 0, 'moved' => 0, 'after' => 0];
        $lines = [];
        $entriesof = [];
        foreach ($DB->get_records(entries::T, ['centreid' => $centre->id, 'teamsheet' => 1]) as $entry) {
            $titles = array_map([recognise::class, 'plain'], entries::questions($entry));
            $stamped = array_filter($titles, fn($t) => $t !== 'created time' && preg_match(entries::STAMPED, $t));
            if ($entry->kind === 'meta' || $stamped) {
                // A form writes in it too: its rows are of the families.
                $counts['unclear'] += $stamped ? 1 : 0;
                continue;
            }
            $counts['sheets']++;
            $rows = $DB->get_records(
                households::CONTACTS,
                ['centreid' => $centre->id, 'entryid' => $entry->id, 'direction' => 'in'],
                'id ASC'
            );
            foreach ($rows as $row) {
                $platform = trim(explode('·', (string) $row->detail)[0]);
                if (in_array($platform, entries::META_PLATFORMS, true)) {
                    $counts['ads']++;
                    continue;
                }
                $lines[(int) $row->householdid][(int) $row->id] = $row;
                $entriesof[(int) $entry->id] = $entry;
                $counts['lines']++;
            }
        }
        $counts['families'] = count($lines);
        foreach ($lines as $householdid => $mine) {
            $household = households::get($householdid, (int) $centre->id);
            if (!$household) {
                continue;
            }
            $transaction = $run ? $DB->start_delegated_transaction() : null;
            foreach ($run ? $mine : [] as $row) {
                $DB->update_record(households::CONTACTS, (object) ['id' => $row->id, 'direction' => 'note', 'asks' => null,
                    'body' => self::as_note((string) $row->body, format_string($entriesof[(int) $row->entryid]->name))]);
            }
            $since = (int) $household->waitingsince;
            $because = array_filter($mine, fn($row) => (int) $row->timecreated === $since);
            if ($since && $because) {
                [$time, $dayonly] = self::truly_waiting($household, array_keys($mine), (string) ($centre->lang ?: 'en'));
                $counts[$time ? 'moved' : 'free']++;
                if ($run) {
                    $DB->update_record(households::T, (object) ['id' => $household->id, 'waitingsince' => $time,
                        'waitingdayonly' => $dayonly]);
                }
            }
            if ($transaction) {
                $transaction->allow_commit();
            }
        }
        $counts['after'] = $run ? households::waiting_count((int) $centre->id) : $counts['waiting'] - $counts['free'];
        return $counts;
    }

    /**
     * The words of a row of the team, as the note it is, in the language it was kept in.
     *
     * @param string $body
     * @param string $name The name of its sheet.
     * @return string
     */
    protected static function as_note(string $body, string $name): string {
        $strings = get_string_manager();
        foreach (array_keys($strings->get_list_of_translations(true)) as $lang) {
            $was = $strings->get_string('entry_arrived', self::C, $name, $lang);
            if ($was !== '' && strpos($body, $was) === 0) {
                return $strings->get_string('entry_noted', self::C, $name, $lang) . \core_text::substr(
                    $body,
                    \core_text::strlen($was)
                );
            }
        }
        return $body;
    }

    /**
     * Since when a family waits by what it truly wrote: its first message, not of a sheet of the team, after the last time
     * the team was in touch; one that was already old when it came in never waited.
     *
     * @param \stdClass $household
     * @param int[] $without The lines that are of the team.
     * @param string $lang
     * @return array [since when, or 0, whether only its day is known].
     */
    protected static function truly_waiting(\stdClass $household, array $without, string $lang): array {
        global $DB;
        $touches = [];
        foreach (self::TOUCHES as $id) {
            if (get_string_manager()->string_exists($id, self::C)) {
                // What goes before the day, or the whole of it.
                $touches[] = trim(explode('{$a', get_string_manager()->get_string($id, self::C, null, $lang))[0]);
            }
        }
        $touches = array_filter($touches);
        $rows = $DB->get_records(households::CONTACTS, ['householdid' => $household->id], 'timecreated ASC, id ASC');
        $arrivals = $DB->get_records(
            entries::ARRIVALS,
            ['householdid' => $household->id],
            'timecreated ASC, id ASC',
            'id, entryid, timecreated'
        );
        $waiting = [0, 0];
        foreach ($rows as $row) {
            $body = trim((string) $row->body);
            $touched = $row->direction === 'out' || ($row->direction === 'note' && !(int) $row->entryid &&
                array_filter($touches, fn($t) => strpos($body, $t) === 0));
            if ($touched) {
                $waiting = [0, 0];
                continue;
            }
            $theirs = $row->direction === 'in' && $row->channel !== lists::PAGE && !in_array((int) $row->id, $without, true);
            if (!$theirs || $waiting[0]) {
                continue;
            }
            $came = 0;
            foreach ($arrivals as $arrival) {
                if ((int) $arrival->entryid === (int) $row->entryid && (int) $arrival->timecreated >= (int) $row->timecreated) {
                    $came = (int) $arrival->timecreated;
                    break;
                }
            }
            if (!$row->entryid || !$came || (int) $row->timecreated > $came - entries::PASTDAYS * DAYSECS) {
                $waiting = [(int) $row->timecreated, (int) $row->dayonly];
            }
        }
        return $waiting;
    }
}
