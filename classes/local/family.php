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

    /** @var string The API of the common record. */
    const API = '\\local_oksigeniafamily\\api';

    /** @var bool|null Only for the tests: whether the common record is there, whatever the site says. */
    public static $testavailable = null;

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
        $DB->set_field(households::T, 'familylink', $familyid > 0 ? $familyid : -1, ['id' => $household->id]);
        $household->familylink = $familyid > 0 ? $familyid : -1;
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
        // The family a person confirmed: found among the open ones of the record when they said so.
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
     * A child enrolled, or back with the centre: into the common record, with its family.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @return int The child in the common record, 0 without it.
     */
    public static function enrol(\stdClass $household, \stdClass $child): int {
        global $DB;
        if (!self::available()) {
            return 0;
        }
        $api = self::API;
        $id = (int) $api::add_child(self::family_of($household), null, self::key('c', (int) $child->id));
        $DB->update_record(households::CHILDREN, (object) ['id' => $child->id, 'familychild' => $id, 'familygone' => 0]);
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
        $api = self::API;
        try {
            // A child already out of it stays as it is; one the record deleted after its time is no longer there.
            $api::unlink_child($id, 'manual');
        } catch (\dml_missing_record_exception $e) {
            $DB->set_field(households::CHILDREN, 'familychild', 0, ['id' => $child->id]);
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
        $child = $DB->get_record(households::CHILDREN, ['familychild' => (int) $event->objectid]);
        if (!$child || $child->stage !== 'enrolled' || !empty($child->lefttime) || !empty($child->familygone)) {
            return;
        }
        $DB->set_field(households::CHILDREN, 'familygone', $event->timecreated ?: time(), ['id' => $child->id]);
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
