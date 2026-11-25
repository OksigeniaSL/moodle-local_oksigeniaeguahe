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
 * The common family record of the centre (local_oksigeniafamily, when the site has it), always through its API.
 *
 * - A family that signs up whose phone (verified there) or email is already in it: a hint for the team, that a person
 *   confirms («It is the same family»); never shown to the family, and nothing of it kept but the answer.
 * - A child enrolled: the household passes to it (its adults and the child, with the key «eguahe:<id>»), so meals,
 *   notices, the library and the app have them from the first day; a brother or sister goes to the same family.
 * - A child who leaves («They are gone»): taken out of it. And when the centre takes a child out of it, the record of
 *   the family proposes «They are gone», for a person to confirm.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family {
    /** @var string Where the families and children made by Eguahe come from, in the common record. */
    const SOURCE = 'eguahe';

    /** @var string[] Who puts an enrolled family in the common record: Eguahe, or the management system of the centre
     *  (records), which loads it; then a person joins the household to it, and nobody is there twice. */
    const FROM = ['eguahe', 'records'];

    /** @var string The API of the common record. */
    const API = '\\local_oksigeniafamily\\api';

    /** @var bool|null Only for the tests: whether the common record is there, whatever the site says. */
    public static $testavailable = null;

    /** @var bool Whether it is Eguahe itself that is taking a child out of the record, or putting it back. */
    protected static $own = false;

    /**
     * Whether the site has the common family record, recent enough for Eguahe, and working.
     *
     * @return bool
     */
    public static function available(): bool {
        if (self::$testavailable !== null) {
            return self::$testavailable;
        }
        $api = self::API;
        return class_exists($api) && method_exists($api, 'family_by_key') && method_exists($api, 'adults_by_email') &&
            $api::is_available();
    }

    /**
     * The key of a household or a child of Eguahe in the common record.
     *
     * @param string $what h (household) or c (child).
     * @param int $id
     * @return string
     */
    public static function key(string $what, int $id): string {
        return self::SOURCE . ':' . ($what === 'h' ? '' : 'c') . $id;
    }

    /**
     * The families of the centre an adult of this household may already be in: by a phone verified there, or by an
     * email. A hint for a person, never an identity.
     *
     * @param \stdClass $full households::full()
     * @return array Family id => the names of the adults found in it, as the household wrote them.
     */
    public static function hints(\stdClass $full): array {
        if (!self::available()) {
            return [];
        }
        $api = self::API;
        $own = $api::family_by_key(self::SOURCE, self::key('h', (int) $full->id));
        $found = [];
        foreach ($full->adults as $adult) {
            $matches = [];
            if (!empty($adult->phone)) {
                $matches += $api::adults_by_phone((string) $adult->phone);
            }
            if (!empty($adult->email)) {
                $matches += $api::adults_by_email((string) $adult->email);
            }
            foreach ($matches as $match) {
                $familyid = (int) $match->familyid;
                if ($familyid === $own) {
                    continue;
                }
                $name = trim($adult->firstname . ' ' . $adult->lastname);
                $found[$familyid][$name] = $name;
            }
        }
        return array_map('array_values', $found);
    }

    /**
     * What a person said: this household is that family of the centre (a family id), or it is not (-1).
     *
     * @param \stdClass $household
     * @param int $familyid
     */
    public static function confirm(\stdClass $household, int $familyid): void {
        global $DB;
        if (centres::is_practice((int) $household->centreid)) {
            return;
        }
        $DB->set_field(households::T, 'familylink', $familyid > 0 ? $familyid : -1, ['id' => $household->id]);
        $household->familylink = $familyid > 0 ? $familyid : -1;
        if ($familyid <= 0) {
            return;
        }
        // Its children found there by their first name: each knows its record, so its leaving is noticed.
        $theirs = roster::families()[$familyid]->children ?? [];
        foreach ($DB->get_records(households::CHILDREN, ['householdid' => $household->id, 'familychild' => 0]) as $mine) {
            foreach ($theirs as $child) {
                $same = questions::normal(trim((string) ($child->firstname ?? ''))) === questions::normal(trim($mine->firstname));
                if ($same && trim($mine->firstname) !== '') {
                    $DB->set_field(households::CHILDREN, 'familychild', (int) $child->childid, ['id' => $mine->id]);
                    break;
                }
            }
        }
    }

    /**
     * The family of the common record a household goes to: the one a person confirmed, the one Eguahe made for it
     * before, or a new one with its adults.
     *
     * @param \stdClass $household
     * @return int
     */
    protected static function family_of(\stdClass $household): int {
        global $DB;
        $api = self::API;
        // The family a person confirmed, still open (open_link()).
        $linked = (int) ($household->familylink ?? 0);
        if ($linked > 0) {
            return $linked;
        }
        $key = self::key('h', (int) $household->id);
        $familyid = $api::family_by_key(self::SOURCE, $key);
        if ($familyid) {
            return $familyid;
        }
        $familyid = $api::create_family(self::SOURCE, $key);
        foreach ($DB->get_records(households::ADULTS, ['householdid' => $household->id], 'id ASC') as $adult) {
            // A phone written in a form identifies nobody: it is not verified.
            $api::add_adult($familyid, ['firstname' => (string) $adult->firstname, 'lastname' => (string) $adult->lastname,
                'email' => (string) ($adult->email ?? ''), 'phone' => (string) ($adult->phone ?? ''), 'phoneverified' => 0,
                'sourcekey' => self::SOURCE . ':a' . $adult->id]);
        }
        return $familyid;
    }

    /**
     * The family a person said this household is, when it is still open in the record: one that closed since is forgotten,
     * and the file asks again.
     *
     * @param \stdClass $household Changed in place.
     */
    protected static function open_link(\stdClass $household): void {
        global $DB;
        $linked = (int) ($household->familylink ?? 0);
        if ($linked <= 0) {
            return;
        }
        $status = $DB->get_field('local_oksigeniafamily_family', 'status', ['id' => $linked]);
        if ($status !== 'active') {
            $DB->set_field(households::T, 'familylink', 0, ['id' => $household->id]);
            $household->familylink = 0;
        }
    }

    /**
     * Two households put together: the family of the record the one that stays has from now on. What a person said it is
     * wins over «it is not that one» of the other; without it, the one Eguahe made for the household that goes, unless
     * the one that stays has its own.
     *
     * @param \stdClass $keep
     * @param \stdClass $gone
     * @return int As familylink keeps it: a family, -1 or 0.
     */
    public static function merged(\stdClass $keep, \stdClass $gone): int {
        $k = (int) ($keep->familylink ?? 0);
        $g = (int) ($gone->familylink ?? 0);
        if ($k > 0 || $g > 0) {
            return $k > 0 ? $k : $g;
        }
        if (self::available() && !centres::is_practice((int) $keep->centreid)) {
            $api = self::API;
            try {
                if (!$api::family_by_key(self::SOURCE, self::key('h', (int) $keep->id))) {
                    $own = (int) $api::family_by_key(self::SOURCE, self::key('h', (int) $gone->id));
                    if ($own) {
                        return $own;
                    }
                }
            } catch (\Throwable $e) {
                debugging('Eguahe: Family did not say the family of household ' . $gone->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return min($k, $g);
    }

    /**
     * The name and the course of a child Eguahe put in the record, as it has them now: the other plugins read them there.
     *
     * @param \stdClass $child
     * @return bool Whether they changed.
     */
    public static function details(\stdClass $child): bool {
        global $DB;
        $api = self::API;
        $id = (int) ($child->familychild ?? 0);
        if (!$id || !self::available() || !method_exists($api, 'set_child_details')) {
            return false;
        }
        $key = (string) $DB->get_field('local_oksigeniafamily_child', 'sourcekey', ['id' => $id]);
        if (!self::ours($key)) {
            return false;
        }
        $course = (int) $child->offerid ? (string) $DB->get_field(centres::OFFERS, 'name', ['id' => $child->offerid]) : '';
        return (bool) $api::set_child_details($key, trim((string) $child->firstname), null, format_string($course));
    }

    /**
     * The names and courses of the children of a centre Eguahe put in the record, brought up to date.
     *
     * @param int $centreid
     * @return int How many changed.
     */
    public static function names(int $centreid): int {
        global $DB;
        if (!self::available() || centres::is_practice($centreid)) {
            return 0;
        }
        $n = 0;
        $rows = $DB->get_records_select(
            households::CHILDREN,
            "centreid = ? AND familychild > 0 AND roster = 0 AND stage = 'enrolled' AND lefttime = 0",
            [$centreid],
            'id ASC',
            'id, firstname, offerid, familychild'
        );
        foreach ($rows as $child) {
            $n += self::details($child) ? 1 : 0;
        }
        return $n;
    }

    /**
     * Whether a key of the record is of a child Eguahe put in it: this one's, or the one of a file put together with its.
     *
     * @param string $key
     * @return bool
     */
    protected static function ours(string $key): bool {
        return (bool) preg_match('/^' . self::SOURCE . ':c\d+$/', $key);
    }

    /**
     * A child enrolled, or back with the centre: into the common record, with its family.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @return int The child in the common record, 0 without it.
     */
    public static function enrol(\stdClass $household, \stdClass $child): int {
        global $DB;
        // The families of the practice centre of the guided helps never reach the shared record.
        if (!self::available() || centres::is_practice((int) $household->centreid)) {
            return 0;
        }
        $centre = centres::get((int) $household->centreid);
        if ($centre && ($centre->familyfrom ?? 'eguahe') === 'records') {
            // The management system of the centre loads the family: Eguahe makes none, and the file proposes joining
            // them once it is there (hints()), so the same child is never in the record twice.
            return 0;
        }
        self::open_link($household);
        if ((int) ($household->familylink ?? 0) === 0 && self::hints(households::full($household))) {
            // The family may already be in the record and nobody said yet: nothing is made, the file asks first.
            return 0;
        }
        $api = self::API;
        $familyid = self::family_of($household);
        $key = self::key('c', (int) $child->id);
        $had = (int) $DB->get_field(households::CHILDREN, 'familychild', ['id' => $child->id]);
        if ($had && ($was = $DB->get_record('local_oksigeniafamily_child', ['id' => $had, 'familyid' => $familyid]))) {
            if (!self::ours((string) $was->sourcekey)) {
                // The management system of the centre has it there already: never a second time.
                return $had;
            }
            // Of a file put together with this one: the same child, with the key it had.
            $key = (string) $was->sourcekey;
        }
        self::$own = true;
        try {
            $id = (int) $api::add_child($familyid, null, $key);
        } finally {
            self::$own = false;
        }
        $DB->update_record(households::CHILDREN, (object) ['id' => $child->id, 'familychild' => $id, 'familygone' => 0]);
        self::details($DB->get_record(households::CHILDREN, ['id' => $child->id], '*', MUST_EXIST));
        return $id;
    }

    /**
     * A child who left, or was taken back from enrolled: out of the common record. Whatever the common record does
     * after (closing the family when it has nobody left) is its own.
     *
     * @param \stdClass $child
     */
    public static function leave(\stdClass $child): void {
        global $DB;
        $id = (int) $DB->get_field(households::CHILDREN, 'familychild', ['id' => $child->id]);
        if (!$id || !self::available()) {
            return;
        }
        // Only a child Eguahe put in the record: one the management system brought leaves by it, never by a word here.
        $key = (string) $DB->get_field('local_oksigeniafamily_child', 'sourcekey', ['id' => $id]);
        if (!self::ours($key)) {
            return;
        }
        $api = self::API;
        self::$own = true;
        try {
            // A child already out of it stays as it is; one the record deleted after its time is no longer there.
            $api::unlink_child($id, 'manual');
        } catch (\dml_missing_record_exception $e) {
            $DB->set_field(households::CHILDREN, 'familychild', 0, ['id' => $child->id]);
        } finally {
            self::$own = false;
        }
    }

    /**
     * The centre took a child out of the common record: its family in Eguahe proposes «They are gone», for a person to
     * confirm. When Eguahe itself did it, or the child is no longer enrolled, nothing.
     *
     * @param \core\event\base $event \local_oksigeniafamily\event\child_unlinked
     */
    public static function unlinked(\core\event\base $event): void {
        global $DB;
        if (self::$own) {
            return;
        }
        $child = $DB->get_record(households::CHILDREN, ['familychild' => (int) $event->objectid], '*', IGNORE_MULTIPLE);
        if (!$child || $child->stage !== 'enrolled' || !empty($child->familygone)) {
            return;
        }
        // Of one a person already said has gone, it is only known, for when it comes back.
        $DB->set_field(households::CHILDREN, 'familygone', $event->timecreated ?: time(), ['id' => $child->id]);
    }

    /**
     * The centre put a child back in the common record, after it had taken it out: if its family had been said to have gone,
     * it is with us again.
     *
     * @param \core\event\base $event \local_oksigeniafamily\event\child_linked
     */
    public static function linked(\core\event\base $event): void {
        global $DB;
        if (self::$own) {
            return;
        }
        $child = $DB->get_record(households::CHILDREN, ['familychild' => (int) $event->objectid], '*', IGNORE_MULTIPLE);
        if ($child && !empty($child->familygone)) {
            self::back($child);
        }
    }

    /**
     * A child the record had let go is in it again: nobody has to say whether it left, and one said to have gone is back.
     *
     * @param \stdClass $child
     */
    public static function back(\stdClass $child): void {
        global $DB;
        $left = !empty($child->lefttime);
        $DB->update_record(households::CHILDREN, (object) (['id' => $child->id, 'familygone' => 0, 'timemodified' => time()] +
            ($left ? ['lefttime' => 0, 'leftreason' => null, 'leftnote' => null] : [])));
        $household = $left ? households::get((int) $child->householdid, (int) $child->centreid) : null;
        if ($household) {
            households::note($household, 0, get_string('family_back_note', 'local_oksigeniaeguahe', panel::tag($child)));
        }
    }

    /**
     * The common record said these children left, and nobody has said yet whether they did.
     *
     * @param \stdClass[] $children
     * @return \stdClass[]
     */
    public static function gone(array $children): array {
        return array_values(array_filter($children, fn($c) => !empty($c->familygone) && $c->stage === 'enrolled' &&
            empty($c->lefttime)));
    }

    /**
     * They are still here, whatever the common record said.
     *
     * @param \stdClass $household
     */
    public static function still_here(\stdClass $household): void {
        global $DB;
        $DB->set_field(households::CHILDREN, 'familygone', 0, ['householdid' => $household->id]);
    }
}
