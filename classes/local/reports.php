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
 * The reports of a centre, kept as they were made: «How it goes» each Monday, of the week that ended, and «The year,
 * cold» when a school year of the centre ends; either again when someone asks. Each is worked out once, in the language
 * of the centre, and the AI of the centre is asked once for it, in a task, never when the page opens.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reports {
    /** @var string */
    const T = 'local_oksigeniaeguahe_report';

    /** @var string[] Hot, to correct on the way; cold, to prepare the next year. */
    const KINDS = ['hot', 'cold'];

    /** @var string[] Where the AI is with a report. */
    const AI = ['none', 'waiting', 'done', 'failed'];

    /** @var int The month a school year starts when the centre did not write its first day. */
    const SEPTEMBER = 9;

    /**
     * The week that ended last before a time: from Monday at 00:00 to the next Monday, in the time of the site.
     *
     * @param int $now
     * @return int[] [from, to]
     */
    public static function week(int $now): array {
        $date = new \DateTime('@' . $now);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->setTime(0, 0);
        $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
        $to = $date->getTimestamp();
        $date->modify('-7 days');
        return [$date->getTimestamp(), $to];
    }

    /**
     * The school year of a centre that holds a time, or the one that ended last before it: from the first of the month
     * its first day falls in (September when it did not write one) to the same day a year later.
     *
     * @param \stdClass $centre
     * @param int $now
     * @param bool $ended The last one that ended, not the one under way.
     * @return int[] [from, to]
     */
    public static function school_year(\stdClass $centre, int $now, bool $ended = true): array {
        $tz = \core_date::get_server_timezone_object();
        $month = !empty($centre->firstday) ? (int) (new \DateTime('@' . (int) $centre->firstday))->setTimezone($tz)->format('n') :
            self::SEPTEMBER;
        $date = (new \DateTime('@' . $now))->setTimezone($tz);
        $year = (int) $date->format('Y') - ((int) $date->format('n') < $month ? 1 : 0) - ($ended ? 1 : 0);
        $from = (new \DateTime('now', $tz))->setDate($year, $month, 1)->setTime(0, 0);
        $to = (clone $from)->modify('+1 year');
        return [$from->getTimestamp(), $to->getTimestamp()];
    }

    /**
     * Works out a report and keeps it; if one of the same kind and period is there, it is made again. Asks the AI of the
     * centre for it in a task when the centre has one.
     *
     * @param \stdClass $centre
     * @param string $kind
     * @param int $from
     * @param int $to
     * @param int $now
     * @param int $userid Who asked; 0, the week or the year that ended.
     * @return int The id of the report.
     */
    public static function make(\stdClass $centre, string $kind, int $from, int $to, int $now, int $userid = 0): int {
        global $DB;
        $lang = (string) ($centre->lang ?: 'es');
        $was = force_current_language($lang);
        try {
            $data = report_data::build($centre, $kind, $from, $to, $now);
        } finally {
            force_current_language($was);
        }
        $ready = ai::ready($centre);
        $record = (object) ['centreid' => $centre->id, 'kind' => $kind, 'periodfrom' => $from, 'periodto' => $to,
            'lang' => $lang, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'ai' => null,
            'aistatus' => $ready ? 'waiting' : 'none', 'aiprovider' => $ready ? \core_text::substr(ai::name($centre), 0, 100) :
            '', 'userid' => $userid, 'timemodified' => $now];
        $old = $DB->get_record(self::T, ['centreid' => $centre->id, 'kind' => $kind, 'periodfrom' => $from]);
        if ($old) {
            $record->id = $old->id;
            $DB->update_record(self::T, $record);
        } else {
            $record->timecreated = $now;
            $record->id = $DB->insert_record(self::T, $record);
        }
        if ($ready) {
            $task = new \local_oksigeniaeguahe\task\report_ai();
            $task->set_custom_data(['reportid' => (int) $record->id, 'userid' => $userid]);
            \core\task\manager::queue_adhoc_task($task, true);
        }
        return (int) $record->id;
    }

    /**
     * A report of a centre.
     *
     * @param int $id
     * @param int $centreid
     * @return \stdClass|null With its data and what the AI said, read.
     */
    public static function get(int $id, int $centreid): ?\stdClass {
        global $DB;
        $report = $DB->get_record(self::T, ['id' => $id, 'centreid' => $centreid]);
        return $report ? self::read($report) : null;
    }

    /**
     * The last report of a kind of a centre.
     *
     * @param int $centreid
     * @param string $kind
     * @return \stdClass|null
     */
    public static function latest(int $centreid, string $kind): ?\stdClass {
        global $DB;
        $report = $DB->get_records(self::T, ['centreid' => $centreid, 'kind' => $kind], 'periodfrom DESC', '*', 0, 1);
        return $report ? self::read(reset($report)) : null;
    }

    /**
     * The reports of a centre, the newest first, without their data.
     *
     * @param int $centreid
     * @return \stdClass[]
     */
    public static function for_centre(int $centreid): array {
        global $DB;
        return array_values($DB->get_records(
            self::T,
            ['centreid' => $centreid],
            'periodfrom DESC, kind ASC',
            'id, kind, periodfrom, periodto, aistatus, timemodified'
        ));
    }

    /**
     * A report with its data and what the AI said, decoded.
     *
     * @param \stdClass $report
     * @return \stdClass
     */
    protected static function read(\stdClass $report): \stdClass {
        $report->data = json_decode((string) $report->data, true) ?: [];
        $report->ai = $report->ai ? (json_decode((string) $report->ai, true) ?: null) : null;
        return $report;
    }

    /**
     * Keeps what the AI said of a report, or that it could not.
     *
     * @param int $id
     * @param array|null $said
     * @param string $error
     */
    public static function set_ai(int $id, ?array $said, string $error = ''): void {
        global $DB;
        $DB->update_record(self::T, (object) ['id' => $id, 'ai' => $said ? json_encode($said, JSON_UNESCAPED_UNICODE) : null,
            'aistatus' => $said ? 'done' : 'failed', 'aierror' => \core_text::substr($error, 0, 255)]);
    }

    /**
     * The reports a centre should have by now and does not: the week that ended, and the school year that ended once
     * it has families from that year; also when the one there was made before its period ended (someone asked for it
     * half way). Only for centres with families.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array A list of [kind, from, to].
     */
    public static function due(\stdClass $centre, int $now): array {
        global $DB;
        $first = (int) $DB->get_field_sql('SELECT MIN(timecreated) FROM {' . households::T . '} WHERE centreid = ?', [$centre->id]);
        if (!$first) {
            return [];
        }
        $out = [];
        [$from, $to] = self::week($now);
        $made = $DB->get_field(self::T, 'timemodified', ['centreid' => $centre->id, 'kind' => 'hot', 'periodfrom' => $from]);
        if ($first < $to && ($made === false || (int) $made < $to)) {
            $out[] = ['hot', $from, $to];
        }
        [$from, $to] = self::school_year($centre, $now);
        $made = $DB->get_field(self::T, 'timemodified', ['centreid' => $centre->id, 'kind' => 'cold', 'periodfrom' => $from]);
        if ($first < $to && ($made === false || (int) $made < $to)) {
            $out[] = ['cold', $from, $to];
        }
        return $out;
    }

    /**
     * The period of a report someone asks for now: the week under way, or the school year under way, so far.
     *
     * @param \stdClass $centre
     * @param string $kind
     * @param int $now
     * @return int[] [from, to]
     */
    public static function current(\stdClass $centre, string $kind, int $now): array {
        return $kind === 'cold' ? self::school_year($centre, $now, false) : self::week($now + WEEKSECS);
    }
}
