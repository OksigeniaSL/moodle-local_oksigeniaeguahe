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
 * After «Enrolled». Eguahe keeps the household and its relation with the door of the centre (coming in, coming back with
 * a brother or a sister, going); the management program of the centre keeps the student, and Eguahe never asks it for
 * anything. The household stays «in the centre», out of the inbox until something happens (they write, a promise falls
 * due); its page says «That is it» once, with whoever carries them from now on, and stays still; on the first day the
 * team marks who started, the first honest number after the campaign.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settled {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string The mark of what a family asked from their page once enrolled, in the history. */
    const ASKED = 'settled';

    /**
     * The enrolled children of a household.
     *
     * @param \stdClass[] $children
     * @return \stdClass[]
     */
    public static function enrolled(array $children): array {
        return array_values(array_filter($children, fn($child) => $child->stage === 'enrolled'));
    }

    /**
     * Whether a household is in the centre: a child of it is enrolled.
     *
     * @param \stdClass[] $children
     * @return bool
     */
    public static function in_centre(array $children): bool {
        return (bool) self::enrolled($children);
    }

    /**
     * Who carries the family from now on: the person of the course of its first enrolled child (its tutor), else the
     * one of the centre, as long as families see them; else the place the centre named (the office of students and
     * its phone); else nobody, and the page says the team.
     *
     * @param \stdClass $centre
     * @param \stdClass[] $children
     * @return array|null ['person' => a host of centres::hosts()] or ['place' => its name].
     */
    public static function handover(\stdClass $centre, array $children): ?array {
        $offers = centres::offers((int) $centre->id);
        $ids = [];
        foreach (self::enrolled($children) as $child) {
            $ids[] = (int) ($offers[$child->offerid]->handover ?? 0);
        }
        $ids[] = (int) ($centre->handover ?? 0);
        foreach (array_unique(array_filter($ids)) as $id) {
            $hosts = centres::hosts((int) $centre->id, [$id]);
            if ($hosts) {
                return ['person' => $hosts[0]];
            }
        }
        $place = trim((string) ($centre->handoverplace ?? ''));
        return $place !== '' ? ['place' => $place] : null;
    }

    /**
     * The family says yes to telling another family how it went, next year: kept in the household, a line in its
     * history and a promise for the start of the next campaign, so the team invites them to an open day.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $now
     * @return bool False when they had already said yes.
     */
    public static function volunteer(\stdClass $household, \stdClass $centre, int $now): bool {
        global $DB;
        if ((int) $DB->get_field(households::T, 'volunteer', ['id' => $household->id])) {
            return false;
        }
        $DB->set_field(households::T, 'volunteer', $now, ['id' => $household->id]);
        $household->volunteer = $now;
        $lang = in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        households::received($household, lists::PAGE, publiclang::str('pub_volunteer_note', $lang), false, $now);
        attend::promise($household, 0, publiclang::str('promise_volunteer', $lang), self::next_campaign($centre, $now));
        return true;
    }

    /**
     * What an enrolled family was left to ask before the first day: it waits for an answer, with whoever carries them.
     *
     * @param \stdClass $household
     * @param string $question
     * @return bool
     */
    public static function ask(\stdClass $household, string $question): bool {
        $question = trim($question);
        if ($question === '') {
            return false;
        }
        households::received($household, lists::PAGE, \core_text::substr($question, 0, 4000), true, null, 0, self::ASKED);
        return true;
    }

    /**
     * Whether an enrolled family already asked from their page.
     *
     * @param int $householdid
     * @return bool
     */
    public static function asked(int $householdid): bool {
        global $DB;
        return $DB->record_exists(households::CONTACTS, ['householdid' => $householdid, 'detail' => self::ASKED]);
    }

    /**
     * About when the next campaign starts: a year after the one under way began, as the centre wrote it; else in eleven
     * months.
     *
     * @param \stdClass $centre
     * @param int $now
     * @return int
     */
    public static function next_campaign(\stdClass $centre, int $now): int {
        $start = (int) ($centre->campaignstart ?? 0);
        if ($start <= 0) {
            return $now + 330 * DAYSECS;
        }
        $date = (new \DateTime('@' . $start))->setTimezone(\core_date::get_user_timezone_object());
        while ($date->getTimestamp() <= $now) {
            $date->modify('+1 year');
        }
        return $date->getTimestamp();
    }

    /**
     * How many families of the centre offered to tell another family how it went, for whoever plans an open day.
     *
     * @param int $centreid
     * @return int
     */
    public static function volunteers(int $centreid): int {
        global $DB;
        return $DB->count_records_select(households::T, 'centreid = ? AND volunteer > 0', [$centreid]);
    }

    /**
     * The school year of the first day: the latest one with a child enrolled.
     *
     * @param int $centreid
     * @return string '' when nobody is enrolled.
     */
    public static function year(int $centreid): string {
        global $DB;
        return (string) $DB->get_field_sql(
            'SELECT MAX(o.schoolyear)
               FROM {' . households::CHILDREN . '} c
               JOIN {' . centres::OFFERS . '} o ON o.id = c.offerid AND o.centreid = c.centreid
              WHERE c.centreid = :centreid AND c.stage = :stage',
            ['centreid' => $centreid, 'stage' => 'enrolled']
        );
    }

    /**
     * The enrolled children of a school year, by course, with their family and whether they started.
     *
     * @param int $centreid
     * @param string $year
     * @return array Offer id => ['offer' => offer, 'children' => [child with household, adults]].
     */
    public static function first_day(int $centreid, string $year): array {
        global $DB;
        $offers = array_filter(centres::offers($centreid), fn($o) => (string) $o->schoolyear === $year);
        if (!$offers) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($offers), SQL_PARAMS_NAMED);
        $children = $DB->get_records_select(
            households::CHILDREN,
            "centreid = :centreid AND stage = :stage AND offerid $in",
            $params + ['centreid' => $centreid, 'stage' => 'enrolled'],
            'firstname ASC, id ASC'
        );
        $adults = [];
        if ($children) {
            [$hin, $hparams] = $DB->get_in_or_equal(array_unique(array_column($children, 'householdid')), SQL_PARAMS_NAMED);
            foreach ($DB->get_records_select(households::ADULTS, "householdid $hin", $hparams, 'id ASC') as $adult) {
                $adults[$adult->householdid][] = $adult;
            }
        }
        $out = [];
        foreach ($offers as $id => $offer) {
            $out[$id] = ['offer' => $offer, 'children' => []];
        }
        foreach ($children as $child) {
            $child->adults = $adults[$child->householdid] ?? [];
            $out[$child->offerid]['children'][] = $child;
        }
        return array_filter($out, fn($course) => (bool) $course['children']);
    }

    /**
     * Marks that an enrolled child started on the first day, or takes the mark back.
     *
     * @param int $centreid
     * @param int $childid
     * @param bool $started
     * @param int $now
     * @return bool False when it is no enrolled child of the centre.
     */
    public static function start(int $centreid, int $childid, bool $started, int $now): bool {
        global $DB;
        $child = $DB->get_record(households::CHILDREN, ['id' => $childid, 'centreid' => $centreid, 'stage' => 'enrolled']);
        if (!$child) {
            return false;
        }
        $DB->update_record(households::CHILDREN, (object) ['id' => $child->id, 'started' => $started ? $now : 0,
            'timemodified' => $now]);
        return true;
    }

    /**
     * Of the children enrolled in a school year, how many there are and how many started, once anyone was marked.
     *
     * @param int $centreid
     * @param string $year
     * @return array ['enrolled' => n, 'started' => n] or [] while nobody is marked.
     */
    public static function started(int $centreid, string $year): array {
        global $DB;
        $row = $DB->get_record_sql(
            'SELECT COUNT(1) AS enrolled, SUM(CASE WHEN c.started > 0 THEN 1 ELSE 0 END) AS started
               FROM {' . households::CHILDREN . '} c
               JOIN {' . centres::OFFERS . '} o ON o.id = c.offerid AND o.centreid = c.centreid
              WHERE c.centreid = :centreid AND c.stage = :stage AND o.schoolyear = :year',
            ['centreid' => $centreid, 'stage' => 'enrolled', 'year' => $year]
        );
        if (!$row || !(int) $row->started) {
            return [];
        }
        return ['enrolled' => (int) $row->enrolled, 'started' => (int) $row->started];
    }
}
