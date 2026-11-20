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
 * What the list of the centre says of a family of Eguahe: a family that asked for a place becomes, from one day to the
 * next, a family of the centre when it enrols its child. Each time the list of the year comes in (by hand or every night
 * from the management program), the families of Eguahe are crossed with it by the keys of their emails and phones,
 * never by a name. A new student of the year in a family enrols its child: the one that asked for that course, or the
 * only one open; with doubt, a person says which. A child already at the centre makes it a family of the centre.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listmatch {
    /** @var string What the list said of each family, once per student and year. */
    const T = 'local_oksigeniaeguahe_listmatch';

    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * Crosses the families of Eguahe of a centre with the list of its last school year.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return array [enrolled, of the centre, asked]
     */
    public static function detect(\stdClass $centre, int $now): array {
        global $DB;
        $centreid = (int) $centre->id;
        $years = metrics::school_years($centreid);
        if (!$years || centres::is_practice($centreid)) {
            return [0, 0, 0];
        }
        $year = (string) reset($years);
        // The students of the year by the key of their family: who is new this year, and who was already there.
        $byhome = [];
        $rows = $DB->get_recordset_select(
            metrics::STUDENTS,
            "centreid = :centreid AND schoolyear = :year AND movement <> 'leave' AND household IS NOT NULL",
            ['centreid' => $centreid, 'year' => $year],
            '',
            'id, student, household, movement, grade, timein'
        );
        foreach ($rows as $row) {
            $s = $byhome[$row->household][$row->student] ?? ['student' => $row->student, 'new' => false, 'grade' => '',
                'timein' => 0];
            $s['new'] = $s['new'] || $row->movement === 'join';
            $s['grade'] = $s['grade'] !== '' ? $s['grade'] : (string) $row->grade;
            $s['timein'] = max($s['timein'], (int) $row->timein);
            $byhome[$row->household][$row->student] = $s;
        }
        $rows->close();
        if (!$byhome) {
            return [0, 0, 0];
        }
        $keys = new pseudonym($centreid);
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centreid], '', 'id, name');
        $seen = [];
        foreach ($DB->get_records(self::T, ['centreid' => $centreid, 'schoolyear' => $year], '', 'id, householdid, student') as $r) {
            $seen[$r->householdid . ':' . $r->student] = true;
        }
        $counts = [0, 0, 0];
        $homes = $DB->get_records_select(households::T, 'centreid = :centreid AND roster = 0', ['centreid' => $centreid]);
        foreach ($homes as $household) {
            $adults = $DB->get_records(households::ADULTS, ['householdid' => $household->id], '', 'id, email, phone');
            $list = [];
            foreach ($adults as $adult) {
                $list[] = $adult->email ? pseudonym::email((string) $adult->email) : null;
                $list[] = $adult->phone ? pseudonym::phone((string) $adult->phone) : null;
            }
            $students = [];
            foreach ($keys->find('household', $list) as $token) {
                $students += $byhome[$token] ?? [];
            }
            if (!$students) {
                continue;
            }
            $older = [];
            foreach ($students as $s) {
                if (isset($seen[$household->id . ':' . $s['student']])) {
                    continue;
                }
                if (!$s['new']) {
                    $older[] = $s;
                    continue;
                }
                $counts[self::enrol($centre, $household, $s, $offers, $year) === 'enrolled' ? 0 : 2]++;
            }
            if ($older) {
                $grades = array_values(array_unique(array_map(fn($s) => grades::label($centreid, $s['grade']), $older)));
                foreach ($older as $s) {
                    $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'householdid' => $household->id,
                        'schoolyear' => $year, 'student' => $s['student'], 'kind' => 'incentre',
                        'grade' => \core_text::substr(grades::label($centreid, $s['grade']), 0, 100), 'timein' => $s['timein'],
                        'childid' => 0, 'done' => $now, 'timecreated' => $now]);
                }
                if (empty($household->incentre)) {
                    // A family with a child already at the centre: out of the numbers of who comes, as one of the centre.
                    $DB->set_field(households::T, 'incentre', 1, ['id' => $household->id]);
                    households::note($household, 0, get_string('listmatch_incentre_note', self::C, implode(', ', $grades)));
                    $counts[1]++;
                }
            }
        }
        return $counts;
    }

    /**
     * A new student of the year in a family of Eguahe: its child that asked for that course, or the only one open, is
     * enrolled; with doubt, a person is asked which.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param array $s student, grade, timein.
     * @param array $offers The offers of the centre.
     * @param string $year
     * @return string enrolled or ask.
     */
    protected static function enrol(\stdClass $centre, \stdClass $household, array $s, array $offers, string $year): string {
        global $DB;
        $centreid = (int) $centre->id;
        $age = grades::age((string) $s['grade'], $centre);
        $open = array_values(array_filter(
            $DB->get_records(households::CHILDREN, ['householdid' => $household->id], 'id ASC'),
            fn($k) => !in_array($k->stage, ['enrolled', 'closed'], true)
        ));
        $fits = array_values(array_filter($open, fn($k) => $age !== null && (int) $k->offerid && isset($offers[$k->offerid]) &&
            grades::age((string) $offers[$k->offerid]->name, $centre) === $age));
        $child = count($fits) === 1 ? $fits[0] : (!$fits && count($open) === 1 ? $open[0] : null);
        $label = grades::label($centreid, (string) $s['grade']);
        $row = (object) ['centreid' => $centreid, 'householdid' => $household->id, 'schoolyear' => $year,
            'student' => $s['student'], 'kind' => $child ? 'enrolled' : 'ask', 'grade' => \core_text::substr($label, 0, 100),
            'timein' => (int) $s['timein'], 'childid' => $child ? (int) $child->id : 0, 'done' => $child ? time() : 0,
            'timecreated' => time()];
        $DB->insert_record(self::T, $row);
        if ($child) {
            households::set_stage($child, 'enrolled');
            households::note($household, 0, get_string('listmatch_enrolled_note', self::C, (object) [
                'child' => format_string($child->firstname), 'grade' => $label,
                'date' => userdate((int) $s['timein'], get_string('strftimedate', 'langconfig'))]));
            return 'enrolled';
        }
        return 'ask';
    }

    /**
     * A person says which child the list enrolled, or that none.
     *
     * @param \stdClass $household
     * @param int $rowid
     * @param int $childid 0, none of them.
     */
    public static function choose(\stdClass $household, int $rowid, int $childid): void {
        global $DB;
        $row = $DB->get_record(self::T, ['id' => $rowid, 'householdid' => $household->id, 'kind' => 'ask', 'done' => 0]);
        if (!$row) {
            return;
        }
        $child = $childid ? $DB->get_record(households::CHILDREN, ['id' => $childid, 'householdid' => $household->id]) : null;
        $DB->update_record(self::T, (object) ['id' => $row->id, 'done' => time(), 'childid' => $child ? (int) $child->id : 0,
            'kind' => $child ? 'enrolled' : 'none']);
        if ($child) {
            households::set_stage($child, 'enrolled');
            households::note($household, 0, get_string('listmatch_enrolled_note', self::C, (object) [
                'child' => format_string($child->firstname), 'grade' => $row->grade,
                'date' => userdate((int) $row->timein, get_string('strftimedate', 'langconfig'))]));
        }
    }

    /**
     * What the file of a household shows of the list of the centre: its children already there, those enrolled by the
     * list, and a question when it was not clear which.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param bool $canact
     * @return array|false
     */
    public static function context(\stdClass $household, \stdClass $full, bool $canact) {
        global $DB;
        $rows = $DB->get_records(self::T, ['householdid' => $household->id], 'timecreated ASC, id ASC');
        if (!$rows) {
            return false;
        }
        $c = self::C;
        $centregrades = [];
        $lines = [];
        $asks = [];
        foreach ($rows as $row) {
            $date = userdate((int) $row->timein, get_string('strftimedate', 'langconfig'));
            if ($row->kind === 'incentre') {
                $centregrades[$row->grade] = true;
            } else if ($row->kind === 'enrolled') {
                $child = $full->children[(int) $row->childid] ?? null;
                $lines[] = get_string('panel_fromlist_enrolled', $c, (object) ['child' => $child ?
                    format_string($child->firstname) : '—', 'grade' => $row->grade, 'date' => $date]);
            } else if ($row->kind === 'ask' && $canact) {
                $asks[] = ['id' => (int) $row->id, 'text' => get_string('panel_fromlist_ask', $c, (object) ['grade' => $row->grade,
                    'date' => $date]), 'children' => array_values(array_map(fn($k) => ['id' => (int) $k->id,
                    'name' => get_string('panel_fromlist_this', $c, format_string($k->firstname))], array_filter(
                        $full->children,
                        fn($k) => !in_array($k->stage, ['enrolled', 'closed'], true)
                    )))];
            }
        }
        if ($centregrades) {
            array_unshift($lines, get_string('panel_fromlist_incentre', $c, implode(', ', array_keys($centregrades))));
        }
        if (!$lines && !$asks) {
            return false;
        }
        return ['lines' => array_map(fn($l) => ['text' => $l], $lines), 'asks' => $asks, 'hasasks' => (bool) $asks,
            'action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey()];
    }
}
