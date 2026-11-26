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
 * Households: the unit that decides, with its adults, its children and their way, what was said, and the permissions.
 *
 * Families are not users of Moodle: all of this lives in the tables of the plugin, with the centre on each row. A
 * household is found again by the email or the phone of any of its adults, so it is not created twice.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class households {
    /** @var string The emails and phones of families that erased their data, as keyed hashes. */
    const GONE = 'local_oksigeniaeguahe_gone';

    /** @var string */
    const T = 'local_oksigeniaeguahe_household';

    /** @var string */
    const ADULTS = 'local_oksigeniaeguahe_adult';

    /** @var string */
    const CHILDREN = 'local_oksigeniaeguahe_child';

    /** @var string */
    const CONTACTS = 'local_oksigeniaeguahe_contact';

    /** @var string */
    const CONSENTS = 'local_oksigeniaeguahe_consent';

    /** @var string */
    const SIGNUPS = 'local_oksigeniaeguahe_signup';

    /** @var string */
    const LINKS = 'local_oksigeniaeguahe_link';

    /** @var string */
    const ERASED = 'local_oksigeniaeguahe_erased';

    /**
     * A household of a centre.
     *
     * @param int $id
     * @param int $centreid 0 to skip the check, only where the centre was checked before.
     * @return \stdClass|null
     */
    public static function get(int $id, int $centreid): ?\stdClass {
        global $DB;
        $params = ['id' => $id];
        if ($centreid) {
            $params['centreid'] = $centreid;
        }
        return self::as_family($DB->get_record(self::T, $params) ?: null);
    }

    /** @var int What marks a made-up family (cli/writeby_sample.php), instead of a guest: it is a family for its pages and its
     * mails, and is nowhere else: not in the inbox, in an act, in Numbers, in a report nor in a download. */
    const SAMPLE = 2;

    /** @var string The families that are not made up, in a table with their householdid. */
    const NOTSAMPLE = 'householdid NOT IN (SELECT id FROM {local_oksigeniaeguahe_household} WHERE guest = 2)';

    /**
     * A made-up family as any other, for its pages and its mails.
     *
     * @param \stdClass|null $household
     * @return \stdClass|null
     */
    protected static function as_family(?\stdClass $household): ?\stdClass {
        if ($household && (int) ($household->guest ?? 0) === self::SAMPLE) {
            $household->guest = 0;
        }
        return $household;
    }

    /**
     * The household one of whose adults has this email or this phone, if there is one.
     *
     * @param int $centreid
     * @param string|null $email In lower case.
     * @param string|null $phone E.164.
     * @return int|null
     */
    public static function find(int $centreid, ?string $email, ?string $phone): ?int {
        global $DB;
        foreach (['email' => $email, 'phone' => $phone] as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ids = $DB->get_fieldset_select(
                self::ADULTS,
                'householdid',
                "centreid = :centreid AND $field = :value",
                ['centreid' => $centreid, 'value' => $value]
            );
            if ($ids) {
                return (int) min($ids);
            }
        }
        return null;
    }

    /**
     * Creates a household.
     *
     * @param int $centreid
     * @param \stdClass $data lang, firstchannel and, if known, municipality, municipalityname, heardfrom and arrival
     *     (where it arrived from, measured: arrival::read()).
     * @return int
     */
    public static function create(int $centreid, \stdClass $data): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record(self::T, (object) ([
            'centreid' => $centreid,
            'lang' => in_array($data->lang ?? '', lists::LANGS, true) ? $data->lang : 'en',
            'municipality' => self::short($data->municipality ?? null, 20),
            'municipalityname' => self::short($data->municipalityname ?? null, 255),
            'heardfrom' => in_array($data->heardfrom ?? '', lists::HEARD_FROM, true) ? $data->heardfrom : null,
            'heardwho' => ($data->heardfrom ?? '') === 'centrefamilies' ? self::short($data->heardwho ?? null, 255) : null,
            'heardnet' => in_array($data->heardfrom ?? '', ['social', 'referral'], true) ? self::short($data->heardnet ?? null, 60) :
                null,
            'firstchannel' => in_array($data->firstchannel ?? '', lists::CHANNELS, true) ? $data->firstchannel : 'other',
            'incentre' => empty($data->incentre) ? 0 : 1,
            'moving' => empty($data->moving) ? 0 : 1,
            'self' => empty($data->self) ? 0 : 1,
            'guest' => empty($data->guest) ? 0 : ((int) $data->guest === self::SAMPLE ? self::SAMPLE : 1),
            'waitingsince' => 0,
            'waitingdayonly' => 0,
        ] + arrival::fields($data->arrival ?? null) + [
            'lastcontact' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }

    /**
     * Whoever was only a guest of an open act is from now a family of the admissions: it asked to be told of the centre,
     * wrote by a form, signed up to an open day or a visit, or the team says so.
     *
     * @param int $id
     * @return bool Whether it was a guest.
     */
    public static function admit(int $id): bool {
        global $DB;
        if (!$DB->record_exists(self::T, ['id' => $id, 'guest' => 1])) {
            return false;
        }
        $DB->update_record(self::T, (object) ['id' => $id, 'guest' => 0, 'timemodified' => time()]);
        return true;
    }

    /**
     * Of the families already kept, those that only signed up to open acts (neither an open day nor a visit), asked for
     * nothing else and nobody of the team has answered: they become guests.
     *
     * @return int How many.
     */
    public static function find_guests(): int {
        global $DB;
        $n = 0;
        $homes = $DB->get_records_select(self::T, 'firstchannel = ? AND roster = 0 AND guest = 0', ['event'], '', 'id');
        foreach (array_keys($homes) as $id) {
            $other = $DB->record_exists_sql("SELECT 1 FROM {" . self::SIGNUPS . "} s JOIN {" . events::T . "} e ON e.id = s.eventid
                                              WHERE s.householdid = ? AND e.kind <> 'other'", [$id]) ||
                $DB->record_exists(visits::REQUESTS, ['householdid' => $id]) ||
                $DB->record_exists_select(self::CONTACTS, "householdid = ? AND (entryid > 0 OR userid > 0 OR direction = 'out' OR " .
                    "(direction = 'in' AND channel <> 'event'))", [$id]) ||
                $DB->record_exists_select(self::CHILDREN, "householdid = ? AND stage <> 'inquiry'", [$id]);
            if (!$other && $DB->record_exists(self::SIGNUPS, ['householdid' => $id])) {
                $DB->set_field(self::T, 'guest', 1, ['id' => $id]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * The team sent them how to ask for a place: from now, their page shows it, also at a centre that does not offer it
     * after the visit.
     *
     * @param \stdClass $household
     * @param int $now
     */
    public static function apply_sent(\stdClass $household, int $now = 0): void {
        global $DB;
        $household->applysent = $now ?: time();
        $DB->set_field(self::T, 'applysent', $household->applysent, ['id' => $household->id]);
    }

    /**
     * Whether a family is offered how to ask for a place: when the team sent it, or once it came, at a centre that
     * offers it after the visit.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param bool $came
     * @return bool
     */
    public static function offered_apply(\stdClass $household, \stdClass $centre, bool $came): bool {
        if (!empty($household->guest) && !(int) ($household->applysent ?? 0)) {
            // Whoever only came to a talk asked for no place.
            return false;
        }
        return !empty($household->applysent) || ($came && (int) ($centre->applyafter ?? 1) === 1);
    }

    /**
     * Fills in what a household had not said yet (where it lives, how it heard of the centre), never overwriting it.
     *
     * @param \stdClass $household
     * @param \stdClass $data
     */
    public static function complete(\stdClass $household, \stdClass $data): void {
        global $DB;
        $changes = [];
        foreach (['municipality' => 20, 'municipalityname' => 255] as $field => $length) {
            if (empty($household->$field) && !empty($data->$field)) {
                $changes[$field] = self::short($data->$field, $length);
            }
        }
        if (empty($household->heardfrom) && in_array($data->heardfrom ?? '', lists::HEARD_FROM, true)) {
            $changes['heardfrom'] = $data->heardfrom;
        }
        // Where it arrived from, measured: the first time anything was, never written over; never an ad for a family
        // that was already known.
        if (arrival::of($household)[0] === '') {
            $measured = array_filter(arrival::fields($data->arrival ?? null), fn($v) => $v !== null);
            if ((isset($measured['arrivalsource']) || isset($measured['arrivalref'])) && !arrival::paid($measured)) {
                $changes += $measured;
            }
        }
        // Which family of the centre told them, if they say so: only a name to thank them, never a link.
        if (empty($household->heardwho) && ($data->heardfrom ?? '') === 'centrefamilies' && !empty($data->heardwho)) {
            $changes['heardwho'] = self::short($data->heardwho, 255);
        }
        // Which network, when it was one, or which centre recommended them: as it was written.
        if (
            empty($household->heardnet) && in_array($data->heardfrom ?? $household->heardfrom, ['social', 'referral'], true) &&
            !empty($data->heardnet)
        ) {
            $changes['heardnet'] = self::short($data->heardnet, 60);
        }
        // A family that says it is already of the centre is, from then on.
        if (empty($household->moving) && !empty($data->moving)) {
            $changes['moving'] = 1;
        }
        if (empty($household->incentre) && !empty($data->incentre)) {
            $changes['incentre'] = 1;
        }
        if ($changes) {
            $changes['id'] = $household->id;
            $changes['timemodified'] = time();
            $DB->update_record(self::T, (object) $changes);
        }
    }

    /**
     * Adds an adult to a household, or returns the one it already has with that email or phone.
     *
     * @param \stdClass $household
     * @param \stdClass $adult firstname and, if known, lastname, email and phone (E.164).
     * @param bool $fill Whether what the one it already has did not say yet is taken: only of who can be trusted (the team,
     *     its sheet), never of a form anyone can fill.
     * @return int
     */
    public static function add_adult(\stdClass $household, \stdClass $adult, bool $fill = false): int {
        global $DB;
        $email = self::email($adult->email ?? null);
        $phone = self::short($adult->phone ?? null, 20);
        foreach (['email' => $email, 'phone' => $phone] as $field => $value) {
            if ($value !== null) {
                $id = $DB->get_field(
                    self::ADULTS,
                    'id',
                    ['householdid' => $household->id, $field => $value],
                    IGNORE_MULTIPLE
                );
                if ($id) {
                    if ($fill) {
                        self::fill_adult($household, (int) $id, $adult);
                    }
                    return (int) $id;
                }
            }
        }
        $now = time();
        $record = (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'firstname' => self::short($adult->firstname ?? '', 100) ?? '',
            'lastname' => self::short($adult->lastname ?? null, 100),
            'email' => $email,
            'phone' => $phone,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = (int) $DB->insert_record(self::ADULTS, $record);
        // Whether they are on the list of former students of the centre.
        alumni::match($household, $record);
        return (int) $record->id;
    }

    /**
     * Writes in an adult what it did not say yet, never over what it has.
     *
     * @param \stdClass $household
     * @param int $id
     * @param \stdClass $adult firstname, lastname, email and phone, those known.
     * @return bool Whether something was written.
     */
    public static function fill_adult(\stdClass $household, int $id, \stdClass $adult): bool {
        global $DB;
        $row = $DB->get_record(self::ADULTS, ['id' => $id, 'householdid' => $household->id]);
        if (!$row) {
            return false;
        }
        $said = ['firstname' => self::short($adult->firstname ?? null, 100), 'lastname' => self::short($adult->lastname ?? null, 100),
            'email' => self::email($adult->email ?? null), 'phone' => self::short($adult->phone ?? null, 20)];
        $changes = [];
        foreach ($said as $field => $value) {
            if ($value !== null && trim((string) $row->$field) === '') {
                $changes[$field] = $value;
            }
        }
        if (!$changes) {
            return false;
        }
        $DB->update_record(self::ADULTS, (object) (['id' => $id, 'timemodified' => time()] + $changes));
        alumni::match($household, (object) ($changes + (array) $row));
        return true;
    }

    /**
     * The right address of an adult, said by a person (after a call, because the mail came back).
     *
     * @param \stdClass $household
     * @param int $adultid
     * @param string $email
     * @param int $userid
     * @return bool
     */
    public static function set_adult_email(\stdClass $household, int $adultid, string $email, int $userid): bool {
        global $DB;
        $email = self::email($email);
        $adult = $DB->get_record(self::ADULTS, ['id' => $adultid, 'householdid' => $household->id]);
        if (!$adult || $email === null || $email === \core_text::strtolower(trim((string) $adult->email))) {
            return false;
        }
        // Said by the team, not on its page: the page does not trust it for its codes until a week passes.
        $DB->update_record(self::ADULTS, (object) ['id' => $adultid, 'email' => $email, 'emailpage' => 0, 'timemodified' => time()]);
        alumni::match($household, (object) (['email' => $email] + (array) $adult));
        return true;
    }

    /**
     * An adult a person of the team writes down in a family that was already there: what it did not say yet is written in
     * the one it is; another one with the phone of the home is another one; and what cannot be there twice stays in a note.
     *
     * @param \stdClass $household
     * @param \stdClass $adult firstname, lastname, email and phone, those known.
     * @param int $userid
     * @return int
     */
    public static function write_down(\stdClass $household, \stdClass $adult, int $userid): int {
        global $DB;
        $email = self::email($adult->email ?? null);
        $phone = self::short($adult->phone ?? null, 20);
        $name = fn(\stdClass $a) => trim(trim((string) ($a->firstname ?? '')) . ' ' . trim((string) ($a->lastname ?? '')));
        $match = null;
        foreach ($DB->get_records(self::ADULTS, ['householdid' => $household->id], 'id ASC') as $row) {
            $alike = self::named_alike($row, $adult);
            if ($email !== null && $email === (string) $row->email) {
                $match = $row;
                break;
            }
            if ($phone !== null && $phone === (string) $row->phone && $alike !== false) {
                $match = $match ?? $row;
            }
            // The same name and surname, with what it had not given.
            $whole = $alike && grades::plain($name($row)) === grades::plain($name($adult)) && strpos(trim($name($row)), ' ') !== false;
            if ($whole && ($email === null || trim((string) $row->email) === '') && ($phone === null || trim((string) $row->phone) === '')) {
                $match = $match ?? $row;
            }
        }
        if (!$match) {
            if ($phone !== null && $DB->record_exists(self::ADULTS, ['householdid' => $household->id, 'phone' => $phone])) {
                // The phone of the home, and another name: another person.
                $now = time();
                $record = (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
                    'firstname' => self::short($adult->firstname ?? '', 100) ?? '', 'lastname' => self::short($adult->lastname ?? null, 100),
                    'email' => $email, 'phone' => $phone, 'timecreated' => $now, 'timemodified' => $now];
                $record->id = (int) $DB->insert_record(self::ADULTS, $record);
                alumni::match($household, $record);
                return (int) $record->id;
            }
            return self::add_adult($household, $adult);
        }
        self::fill_adult($household, (int) $match->id, $adult);
        $other = [];
        if (self::named_alike($match, $adult) === false) {
            $other[] = $name($adult);
        }
        if ($email !== null && trim((string) $match->email) !== '' && $email !== (string) $match->email) {
            $other[] = $email;
        }
        if ($phone !== null && trim((string) $match->phone) !== '' && $phone !== (string) $match->phone) {
            $other[] = phone::display($phone);
        }
        if ($other) {
            self::note($household, $userid, get_string('adult_also_noted', 'local_oksigeniaeguahe', (object) ['who' => $name($match) ?: '—',
                'others' => implode(' · ', $other)]));
        }
        return (int) $match->id;
    }

    /**
     * Whether two adults may be the same person by their names: «Ana» and «Ana Pérez», «María José» and «María».
     *
     * @param \stdClass $a
     * @param \stdClass $b
     * @return bool|null Null when one of them has no name.
     */
    public static function named_alike(\stdClass $a, \stdClass $b): ?bool {
        $words = fn(\stdClass $adult) => array_values(array_filter(explode(' ', grades::plain(trim(($adult->firstname ?? '') . ' ' .
            ($adult->lastname ?? ''))))));
        $one = $words($a);
        $two = $words($b);
        if (!$one || !$two) {
            return null;
        }
        // The one with fewer words, whole in the other, and the same first.
        [$short, $long] = count($one) <= count($two) ? [$one, $two] : [$two, $one];
        return $short[0] === $long[0] && !array_diff($short, $long);
    }

    /**
     * Whether two names of a child may be of the same one: the same without accents, or one that starts as the other
     * («Martín» and «Martín Pérez»).
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function same_name(string $a, string $b): bool {
        $a = trim(grades::plain($a));
        $b = trim(grades::plain($b));
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || strpos($b . ' ', $a . ' ') === 0 || strpos($a . ' ', $b . ' ') === 0;
    }

    /** @var array Of the children named again by what a family sent: household id => child id => the offer asked; see received(). */
    protected static $named = [];

    /**
     * The child of a household with a name: the same without accents; else the only one whose name starts as it, or it as its.
     *
     * @param \stdClass[] $children
     * @param string $firstname
     * @return \stdClass|null
     */
    protected static function child_named(array $children, string $firstname): ?\stdClass {
        $plain = trim(grades::plain($firstname));
        if ($plain === '') {
            return null;
        }
        $close = [];
        foreach ($children as $child) {
            if (trim(grades::plain((string) $child->firstname)) === $plain) {
                return $child;
            }
            if (self::same_name((string) $child->firstname, $firstname)) {
                $close[] = $child;
            }
        }
        return count($close) === 1 ? $close[0] : null;
    }

    /**
     * Adds a child to a household, or returns the one of the same name it already has.
     *
     * @param \stdClass $household
     * @param string $firstname
     * @param int $offerid 0 while not known.
     * @param string $tagname The name used at home, for the label; empty for the first name.
     * @param bool $keep Whether what the child already has stays: someone who is not the family for sure (an anonymous form
     *     that matched it) never changes the name used at home, nor the course of a child that is already on its way. What it
     *     says is kept in a note, for a person.
     * @return int
     */
    public static function add_child(
        \stdClass $household,
        string $firstname,
        int $offerid,
        string $tagname = '',
        bool $keep = false
    ): int {
        global $DB;
        $firstname = self::short($firstname, 100) ?? '';
        $tagname = self::short($tagname, 100);
        $child = self::child_named($DB->get_records(self::CHILDREN, ['householdid' => $household->id], 'id ASC'), $firstname);
        if ($child) {
            self::$named[(int) $household->id][(int) $child->id] = $offerid;
            self::asks_for($household, $child, $offerid, $keep);
            if ($tagname !== null && (!$keep || trim((string) $child->tagname) === '')) {
                $DB->set_field(self::CHILDREN, 'tagname', $tagname, ['id' => $child->id]);
            }
            return (int) $child->id;
        }
        // A child without a name (a contact of an ad, a row of a sheet) and the same child named later are one: the
        // one asking for the same course, or for none yet.
        foreach ($DB->get_records(self::CHILDREN, ['householdid' => $household->id], 'id ASC') as $child) {
            $unnamed = trim((string) $child->firstname) === '' || $firstname === '';
            $samecourse = !$offerid || !$child->offerid || (int) $child->offerid === $offerid;
            if ($unnamed && $samecourse) {
                $changes = ['id' => $child->id, 'timemodified' => time()];
                if ($firstname !== '' && trim((string) $child->firstname) === '') {
                    $changes['firstname'] = $firstname;
                }
                if ($offerid && !$child->offerid) {
                    $changes['offerid'] = $offerid;
                }
                if (count($changes) > 2) {
                    $DB->update_record(self::CHILDREN, (object) $changes);
                }
                return (int) $child->id;
            }
        }
        $now = time();
        return (int) $DB->insert_record(self::CHILDREN, (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'firstname' => $firstname,
            'tagname' => $tagname,
            'offerid' => $offerid,
            'stage' => 'inquiry',
            'stagetime' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * A course asked for a child that was already there. An older one than it has changes nothing. Asking for another school
     * year, its way starts again; in the same one, the course changes and its step stays. A child enrolled, or already on its
     * way when whoever asks may not be the family, stays as it is: what it says is kept, for a person.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param int $offerid
     * @param bool $keep
     */
    protected static function asks_for(\stdClass $household, \stdClass $child, int $offerid, bool $keep): void {
        global $DB;
        $has = (int) $child->offerid;
        if (!$offerid || $has === $offerid) {
            return;
        }
        $settled = $child->stage === 'enrolled';
        if (!$has && !$settled) {
            $DB->set_field(self::CHILDREN, 'offerid', $offerid, ['id' => $child->id]);
            return;
        }
        $offers = centres::offers((int) $household->centreid);
        $year = fn(int $id) => (string) ($offers[$id]->schoolyear ?? '');
        $older = $has && $year($offerid) !== '' && $year($offerid) < $year($has);
        $label = fn(int $id) => isset($offers[$id]) ? centres::offer_label($offers[$id]) : get_string('offer_unknown', 'local_oksigeniaeguahe');
        if ($settled || ($keep && !in_array($child->stage, ['inquiry', 'closed'], true))) {
            if ($keep && !$older && !(int) ($child->lefttime ?? 0)) {
                // Someone reads it and says: it is asked for, and it may not be them.
                self::note($household, 0, get_string('child_asks_kept', 'local_oksigeniaeguahe', (object) ['name' => format_string($child->firstname),
                    'asks' => $label($offerid), 'has' => $label($has), 'step' => get_string('stage_' . $child->stage, 'local_oksigeniaeguahe')]));
                self::wait($household);
            }
            return;
        }
        if ($older || ($keep && $child->stage === 'closed')) {
            // One whose way had stopped asks again when what was sent is of after that (received()), never for an old one.
            return;
        }
        $changes = ['id' => $child->id, 'offerid' => $offerid, 'timemodified' => time()];
        if ($child->stage === 'closed' || ($year($offerid) !== '' && $year($offerid) > $year($has))) {
            $changes += ['stage' => 'inquiry', 'closedreason' => null, 'closedstage' => null, 'stagetime' => time()];
        }
        $DB->update_record(self::CHILDREN, (object) $changes);
        self::note($household, 0, get_string('child_asks_now', 'local_oksigeniaeguahe', (object) ['name' => format_string($child->firstname),
            'asks' => $label($offerid), 'had' => $label($has), 'step' => get_string('stage_' . $child->stage, 'local_oksigeniaeguahe')]));
    }

    /**
     * A household waits for a person from now, unless it was already waiting.
     *
     * @param \stdClass $household Changed in place.
     */
    public static function wait(\stdClass $household): void {
        global $DB;
        if (!(int) $DB->get_field(self::T, 'waitingsince', ['id' => $household->id])) {
            $household->waitingsince = time();
            $household->waitingdayonly = 0;
            $DB->update_record(self::T, (object) ['id' => $household->id, 'waitingsince' => $household->waitingsince, 'waitingdayonly' => 0]);
        }
    }

    /**
     * A family asks again for a child whose way had stopped, or that had left: it is open once more, asking for information,
     * and its history keeps why it had stopped.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param int $offerid The course asked for now; 0, the one it had.
     * @param int $userid The person of the team who says it; 0, the family did.
     * @return bool Whether it was stopped.
     */
    public static function ask_again(\stdClass $household, \stdClass $child, int $offerid = 0, int $userid = 0): bool {
        global $DB;
        $left = $child->stage === 'enrolled' && (int) ($child->lefttime ?? 0);
        if ($child->stage !== 'closed' && !$left) {
            return false;
        }
        $c = 'local_oksigeniaeguahe';
        $centre = centres::get((int) $household->centreid);
        $lang = (string) ($centre->lang ?? '') ?: current_language();
        $str = fn(string $id, $a = null) => get_string_manager()->get_string($id, $c, $a, $lang);
        $why = $left ? $str('left_note', $str('left_' . ($child->leftreason ?: 'unknown'))) : ($child->closedreason ?
            $str('closed_' . $child->closedreason) : $str('stage_closed'));
        if ($offerid && $offerid !== (int) $child->offerid) {
            $DB->set_field(self::CHILDREN, 'offerid', $offerid, ['id' => $child->id]);
        }
        self::set_stage($child, 'inquiry');
        if ($left) {
            // Another time: what it started and when is of the one before.
            $DB->update_record(self::CHILDREN, (object) ['id' => $child->id, 'started' => 0, 'startyear' => null]);
        }
        self::note($household, $userid, $str($left ? 'child_back_left' : 'child_back', (object) ['name' => format_string($child->firstname) ?: '—',
            'why' => rtrim($why, '.')]));
        return true;
    }

    /**
     * The children of a family whose ways all stopped ask again: it signs up to see the centre, or sends a form.
     *
     * @param \stdClass $household
     * @param int $time When it did.
     */
    public static function back_on_its_way(\stdClass $household, int $time = 0): void {
        global $DB;
        $children = $DB->get_records(self::CHILDREN, ['householdid' => $household->id], 'id ASC');
        $closed = array_filter($children, fn($k) => $k->stage === 'closed');
        if (!$closed || count($closed) < count($children)) {
            return;
        }
        foreach ($closed as $child) {
            if (!$time || $time > (int) $child->stagetime) {
                self::ask_again($household, $child);
            }
        }
    }

    /**
     * What a child is called at home, which the family can change from their page: the name of the label, the story and
     * its voice, and the birthday. Empty goes back to their first name; official papers keep the first name.
     *
     * @param \stdClass $household
     * @param int $childid
     * @param string $name
     * @return bool Whether it changed.
     */
    public static function set_home_name(\stdClass $household, int $childid, string $name): bool {
        global $DB;
        $name = self::short($name, 100);
        $child = $DB->get_record(self::CHILDREN, ['id' => $childid, 'householdid' => $household->id]);
        if (!$child || trim((string) $child->tagname) === (string) $name) {
            return false;
        }
        $DB->update_record(self::CHILDREN, (object) ['id' => $child->id, 'tagname' => $name, 'timemodified' => time()]);
        return true;
    }

    /**
     * A child's name in Latin letters, when the family writes it in another script: what the voice of the story says.
     * Only Latin letters are kept; empty takes it out.
     *
     * @param \stdClass $household
     * @param int $childid
     * @param string $name
     * @return bool Whether it changed.
     */
    public static function set_latin_name(\stdClass $household, int $childid, string $name): bool {
        global $DB;
        $name = self::short($name, 100);
        if ($name !== null && !namevoices::latin($name)) {
            return false;
        }
        $child = $DB->get_record(self::CHILDREN, ['id' => $childid, 'householdid' => $household->id]);
        if (!$child || trim((string) ($child->latinname ?? '')) === (string) $name) {
            return false;
        }
        $DB->update_record(self::CHILDREN, (object) ['id' => $child->id, 'latinname' => $name, 'timemodified' => time()]);
        return true;
    }

    /**
     * The date of birth of a child, as the family gives it: for their birthday. Empty takes it out; a day that is not
     * real changes nothing.
     *
     * @param \stdClass $household
     * @param int $childid
     * @param string $date YYYY-MM-DD.
     * @return bool Whether it changed.
     */
    public static function set_birthdate(\stdClass $household, int $childid, string $date): bool {
        global $DB;
        $given = trim($date) !== '';
        $date = birthdays::clean($date, time());
        if ($given && $date === null) {
            return false;
        }
        $child = $DB->get_record(self::CHILDREN, ['id' => $childid, 'householdid' => $household->id]);
        if (!$child || (string) ($child->birthdate ?? '') === (string) $date) {
            return false;
        }
        $DB->update_record(self::CHILDREN, (object) ['id' => $child->id, 'birthdate' => $date, 'timemodified' => time()]);
        return true;
    }

    /**
     * Whether a line says only that the team wrote the family down, with no word of the family («Anotada por el equipo.»,
     * in the language of whoever did it).
     *
     * @param \stdClass $contact
     * @return bool
     */
    public static function is_hand_note(\stdClass $contact): bool {
        static $words = null;
        if ($words === null) {
            $words = [];
            foreach (array_keys(get_string_manager()->get_list_of_translations(true)) as $lang) {
                $words[] = get_string_manager()->get_string('noted_by_team', 'local_oksigeniaeguahe', null, $lang);
            }
        }
        return !(int) $contact->entryid && in_array(trim((string) $contact->body), $words, true);
    }

    /**
     * The household wrote or came: it is noted, and if it asked something, it waits for a person to answer.
     *
     * @param \stdClass $household
     * @param string $channel
     * @param string $body What it said, or what happened.
     * @param bool $awaits Whether it asked something a person has to answer.
     * @param int|null $time
     * @param int $entryid The entry of the centre it came through, if any.
     * @param string $detail What the source said of itself: platform, campaign, ad.
     * @param string $address Who wrote on WhatsApp or Telegram, as Marandu gives it.
     * @param bool $theywrote Whether the family itself wrote it: a row of a sheet of the team, or one sent before, is
     *     not, and never lifts a «do not write to us».
     * @param bool $dayonly Whether only the day of it is known (a date without its hour): no hour is ever said of it.
     * @param int $userid The person of the team who noted it for the family; 0, the family itself or what it sent.
     */
    public static function received(
        \stdClass $household,
        string $channel,
        string $body,
        bool $awaits,
        ?int $time = null,
        int $entryid = 0,
        string $detail = '',
        string $address = '',
        bool $theywrote = true,
        bool $dayonly = false,
        int $userid = 0
    ): void {
        global $DB;
        $time = $time ?? time();
        self::contact($household, 'in', $channel, $body, $userid, $time, $entryid, $detail, $address, $dayonly);
        // Writing again after asking not to be written to lifts it, by the way they wrote (never for an old message).
        // What a person of the team notes of them («we called, no answer») is not them writing.
        if ($theywrote && !$userid) {
            writeby::wrote_again($household, $channel, $time);
        }
        if ($theywrote) {
            // Named again, or a form or a sign-up of a family whose ways had all stopped: they ask again. Never for an old one.
            foreach (self::$named[(int) $household->id] ?? [] as $childid => $offerid) {
                $child = $DB->get_record(self::CHILDREN, ['id' => $childid, 'householdid' => $household->id]);
                if ($child && $time > max((int) $child->stagetime, (int) ($child->lefttime ?? 0))) {
                    self::ask_again($household, $child, $offerid, $userid);
                }
            }
            if ($awaits && !$userid && ($channel === 'webform' || in_array($channel, ['visit', 'openday', 'googleforms', 'meta'], true))) {
                self::back_on_its_way($household, $time);
            }
        }
        unset(self::$named[(int) $household->id]);
        // What was sent long ago never makes the last time they were in touch older than it was.
        $changes = (object) ['id' => $household->id, 'lastcontact' => max((int) $DB->get_field(
            self::T,
            'lastcontact',
            ['id' => $household->id]
        ), $time), 'timemodified' => time()];
        if ($awaits && !$household->waitingsince) {
            $changes->waitingsince = $time;
            $changes->waitingdayonly = $dayonly ? 1 : 0;
            $household->waitingsince = $time;
            $household->waitingdayonly = $changes->waitingdayonly;
        }
        $DB->update_record(self::T, $changes);
    }

    /**
     * A row of a sheet the team keeps that is not a contact of an ad: something the team wrote down, never something the
     * family said. It goes to the history as a note, and nobody waits for an answer because of it.
     *
     * @param \stdClass $household
     * @param string $channel The way the sheet says they came.
     * @param string $body
     * @param int $time
     * @param int $entryid The entry of the sheet.
     * @param string $detail
     * @param bool $dayonly Whether only the day of it is known.
     */
    public static function noted(
        \stdClass $household,
        string $channel,
        string $body,
        int $time,
        int $entryid,
        string $detail = '',
        bool $dayonly = false
    ): void {
        global $DB;
        self::contact($household, 'note', $channel, $body, 0, $time, $entryid, $detail, '', $dayonly);
        $DB->update_record(self::T, (object) ['id' => $household->id, 'timemodified' => time(),
            'lastcontact' => max((int) $household->lastcontact, $time)]);
    }

    /**
     * A person of the team answered the household: it no longer waits.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $channel
     * @param string $body What was said, in a few words.
     */
    public static function answered(\stdClass $household, int $userid, string $channel, string $body): void {
        global $DB;
        $time = time();
        attend::answered_by($household, $userid);
        self::contact($household, 'out', $channel, $body, $userid, $time);
        $DB->update_record(self::T, (object) ['id' => $household->id, 'waitingsince' => 0, 'waitingdayonly' => 0,
            'lastcontact' => $time, 'timemodified' => $time]);
        $household->waitingsince = 0;
        $household->waitingdayonly = 0;
    }

    /**
     * The team told the household something it did not ask (a change in its visit): it goes to its history, and the
     * household keeps waiting if it was.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $channel
     * @param string $body
     * @param bool $sent Whether it went to many at once: it does not count as being in touch.
     */
    public static function told(\stdClass $household, int $userid, string $channel, string $body, bool $sent = false): void {
        global $DB;
        $time = time();
        self::contact($household, 'out', $channel, $body, $userid, $time, 0, '', '', false, $sent ? 'many' : 'told');
        // What the centre sends to many at once (an invitation) does not count as being in touch: it does not keep the
        // data of a family that never answers any longer.
        if (!$sent) {
            $DB->update_record(self::T, (object) ['id' => $household->id, 'lastcontact' => $time, 'timemodified' => $time]);
        }
    }

    /**
     * A note of the team about the household, which the household never sees.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $body
     */
    public static function note(\stdClass $household, int $userid, string $body): void {
        self::contact($household, 'note', 'other', $body, $userid, time());
    }

    /**
     * Writes a line of the history of a household.
     *
     * @param \stdClass $household
     * @param string $direction in, out or note.
     * @param string $channel
     * @param string $body
     * @param int $userid
     * @param int $time
     * @param int $entryid
     * @param string $detail
     * @param string $address Who wrote on WhatsApp or Telegram, as Marandu gives it.
     * @param bool $dayonly Whether only its day is known, never its hour.
     * @param string $aim many or told, for what answers nobody; empty for the rest.
     */
    protected static function contact(
        \stdClass $household,
        string $direction,
        string $channel,
        string $body,
        int $userid,
        int $time,
        int $entryid = 0,
        string $detail = '',
        string $address = '',
        bool $dayonly = false,
        string $aim = ''
    ): void {
        global $DB;
        $DB->insert_record(self::CONTACTS, (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'direction' => $direction,
            'channel' => in_array($channel, lists::CHANNELS, true) || $channel === lists::PAGE ? $channel : 'other',
            'body' => trim($body),
            'entryid' => $entryid,
            'detail' => $detail === '' ? null : \core_text::substr($detail, 0, 255),
            'address' => $address === '' ? null : \core_text::substr($address, 0, 64),
            'userid' => $userid,
            'dayonly' => $dayonly ? 1 : 0,
            'aim' => $aim === '' ? null : $aim,
            'timecreated' => $time,
        ]);
    }

    /**
     * What the family itself changes of its contact data, from its page (Enrique): the name, phone and email of each
     * adult, and another person of the family. An adult keeps the permissions it gave; another person gets those of
     * the ways the family is written to by. The team reads it in a line of the history.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param array $rows Adult id => [firstname, lastname, phone, email], as written.
     * @param array $new firstname, lastname, phone, email of another person; empty fields, none.
     * @return bool Whether something changed.
     */
    public static function family_contacts(\stdClass $household, \stdClass $centre, array $rows, array $new): bool {
        global $DB;
        $now = time();
        $changed = false;
        $clean = fn(array $row) => (object) [
            'firstname' => \core_text::substr(trim((string) ($row['firstname'] ?? '')), 0, 100),
            'lastname' => \core_text::substr(trim((string) ($row['lastname'] ?? '')), 0, 100),
            'phone' => phone::normalise((string) ($row['phone'] ?? ''), $centre->country ?? null),
            'email' => self::email((string) ($row['email'] ?? '')),
        ];
        foreach (self::full($household)->adults as $adult) {
            if (!isset($rows[$adult->id])) {
                continue;
            }
            $row = $clean($rows[$adult->id]);
            // A name left empty keeps the one it had; a phone or an email written badly too.
            $fields = ['firstname' => $row->firstname !== '' ? $row->firstname : $adult->firstname,
                'lastname' => $row->lastname, 'phone' => $row->phone ?? ($rows[$adult->id]['phone'] === '' ? null : $adult->phone),
                'email' => $row->email ?? ($rows[$adult->id]['email'] === '' ? null : $adult->email)];
            if ($fields['phone'] === null && $fields['email'] === null) {
                // Never without a way to reach them.
                $fields['phone'] = $adult->phone;
                $fields['email'] = $adult->email;
            }
            $differs = false;
            foreach ($fields as $field => $value) {
                $differs = $differs || (string) $value !== (string) $adult->$field;
            }
            if ($differs) {
                $record = ['id' => $adult->id, 'timemodified' => $now] + $fields;
                // An email written here, by whoever has the link, gets no code until it has been theirs a week.
                if ((string) $fields['email'] !== '' && (string) $fields['email'] !== (string) $adult->email) {
                    $record['emailpage'] = $now;
                }
                $DB->update_record(self::ADULTS, (object) $record);
                $changed = true;
                alumni::match($household, (object) (['id' => $adult->id] + $fields));
                // An email or a phone changed: the address there was hears it, without a link.
                access::changed($household, $centre, $adult, $fields);
            }
        }
        if ($changed) {
            // An address that came back, changed by the family: said right, and what did not arrive goes again.
            bounces::changed($household, 0);
        }
        $lang = (string) ($household->lang ?: ($centre->lang ?? 'es'));
        $teamlang = (string) ($centre->lang ?? '') ?: current_language();
        $other = $clean($new);
        $added = '';
        if ($other->firstname !== '' && ($other->phone || $other->email)) {
            $before = array_keys(self::full($household)->adults);
            $id = self::add_adult($household, $other);
            if ($id && !in_array($id, array_map('intval', $before), true)) {
                // Added here: the page may take them out again, and their email gets no code for a week.
                $DB->update_record(self::ADULTS, (object) ['id' => $id, 'frompage' => $now,
                    'emailpage' => $other->email ? $now : 0]);
                // The addresses the family already had hear it, without a link.
                access::added($household, $centre, $other);
                // Written to as the rest of the family: the same ways, with the permission the family gives for them.
                $ways = writeby::chosen($household);
                $said = self::permissions((int) $household->id);
                $has = fn(string $channel) => (bool) array_filter($said, fn($p) => !empty($p[$channel]));
                if ($other->email && ($ways === null ? $has('email') : in_array('email', $ways, true))) {
                    self::consent($household, $id, 'email', true, 'family', $lang);
                }
                if ($other->phone && ($ways === null ? $has('whatsapp') : in_array('whatsapp', $ways, true))) {
                    self::consent($household, $id, 'whatsapp', true, 'family', $lang);
                }
                $added = $other->firstname;
                $changed = true;
            }
        }
        if ($changed) {
            self::note($household, 0, get_string_manager()->get_string(
                $added !== '' ? 'contacts_noted_added' : 'contacts_noted_changed',
                'local_oksigeniaeguahe',
                $added,
                $teamlang
            ));
        }
        return $changed;
    }

    /**
     * Takes out a person the family added from its own page (a mistake, someone who is not of the family): with their
     * permissions, hobbies and former-student line. Never one the team or the sign-up brought, nor the last adult.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param int $adultid
     * @return bool Whether they were taken out.
     */
    public static function remove_page_adult(\stdClass $household, \stdClass $centre, int $adultid): bool {
        global $DB;
        $adult = $DB->get_record(self::ADULTS, ['id' => $adultid, 'householdid' => $household->id]);
        if (!$adult || !(int) $adult->frompage || $DB->count_records(self::ADULTS, ['householdid' => $household->id]) < 2) {
            return false;
        }
        $DB->delete_records(self::CONSENTS, ['householdid' => $household->id, 'adultid' => $adultid]);
        $DB->delete_records(hobbies::T, ['householdid' => $household->id, 'adultid' => $adultid]);
        $DB->delete_records(alumni::T, ['householdid' => $household->id, 'adultid' => $adultid]);
        $DB->delete_records(self::ADULTS, ['id' => $adultid]);
        self::note($household, 0, get_string_manager()->get_string(
            'contacts_noted_removed',
            'local_oksigeniaeguahe',
            trim($adult->firstname . ' ' . $adult->lastname),
            (string) ($centre->lang ?? '') ?: current_language()
        ));
        return true;
    }

    /**
     * Whom the emails of a household go to: the first adult with an email whose last word on email was yes; else the
     * first adult with an email. Emails only get into a household through the family itself or the team.
     *
     * @param \stdClass $household
     * @return string The address; empty, none.
     */
    public static function recipient(\stdClass $household): string {
        $permissions = self::permissions((int) $household->id);
        $adults = self::full($household)->adults;
        // An address whose mail comes back is the last choice.
        $failing = bounces::failing(array_keys($adults));
        $first = '';
        $last = '';
        foreach ($adults as $adult) {
            $email = trim((string) $adult->email);
            if ($email === '') {
                continue;
            }
            if (isset($failing[(int) $adult->id])) {
                $last = $last ?: $email;
                continue;
            }
            if (!empty($permissions[$adult->id]['email'])) {
                return $email;
            }
            $first = $first ?: $email;
        }
        return $first ?: $last;
    }

    /**
     * The households of a centre whose last word on email, for some adult with an address, was yes.
     *
     * @param int $centreid
     * @return array household id => true
     */
    public static function emailable(int $centreid): array {
        global $DB;
        $last = [];
        $rows = $DB->get_recordset_select(
            self::CONSENTS,
            'centreid = ? AND channel = ?',
            [$centreid, 'email'],
            'timecreated ASC, id ASC',
            'id, householdid, adultid, granted'
        );
        foreach ($rows as $row) {
            $last[(int) $row->adultid] = [(int) $row->householdid, (bool) $row->granted];
        }
        $rows->close();
        $withemail = array_flip(array_map('intval', $DB->get_fieldset_select(
            self::ADULTS,
            'id',
            "centreid = ? AND email IS NOT NULL AND email <> ''",
            [$centreid]
        )));
        $out = [];
        foreach ($last as $adultid => [$householdid, $granted]) {
            if ($granted && isset($withemail[$adultid])) {
                $out[$householdid] = true;
            }
        }
        return $out;
    }

    /**
     * Notes a permission given or withdrawn by an adult. Never changed afterwards: the history is the proof.
     *
     * @param \stdClass $household
     * @param int $adultid
     * @param string $channel One of lists::CONSENT_CHANNELS.
     * @param bool $granted
     * @param string $source form, team or family.
     * @param string $lang The language of the text the adult read.
     * @param int $userid The person of the team who noted it, if one did.
     */
    public static function consent(
        \stdClass $household,
        int $adultid,
        string $channel,
        bool $granted,
        string $source,
        string $lang,
        int $userid = 0
    ): void {
        global $DB;
        if (!in_array($channel, lists::CONSENT_CHANNELS, true)) {
            throw new \coding_exception('Unknown channel ' . $channel);
        }
        $DB->insert_record(self::CONSENTS, (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'adultid' => $adultid,
            'channel' => $channel,
            'granted' => $granted ? 1 : 0,
            // The texts change with the versions of the plugin: the version and the language say which one was read.
            'textversion' => \core_text::substr(get_config('local_oksigeniaeguahe', 'version') . '/' . $lang, 0, 40),
            'source' => $source,
            'userid' => $userid,
            'timecreated' => time(),
        ]);
    }

    /**
     * The permissions each adult has given now: the last word on each channel.
     *
     * @param int $householdid
     * @return array Adult id => [channel => granted].
     */
    public static function permissions(int $householdid): array {
        global $DB;
        $now = [];
        foreach ($DB->get_records(self::CONSENTS, ['householdid' => $householdid], 'timecreated ASC, id ASC') as $row) {
            $now[$row->adultid][$row->channel] = (bool) $row->granted;
        }
        return $now;
    }

    /**
     * Moves the way of a child.
     *
     * @param \stdClass $child
     * @param string $stage
     * @param string|null $reason Why it stopped, when the stage is closed.
     */
    public static function set_stage(\stdClass $child, string $stage, ?string $reason = null): void {
        global $DB;
        if (!in_array($stage, lists::STAGES, true)) {
            throw new \coding_exception('Unknown stage ' . $stage);
        }
        $now = time();
        // As it is now, whatever the page had read.
        $fresh = $DB->get_record(self::CHILDREN, ['id' => $child->id], 'id, stage, lefttime, closedstage');
        $was = (string) ($fresh->stage ?? $child->stage ?? '');
        $left = (int) ($fresh->lefttime ?? 0);
        $DB->update_record(self::CHILDREN, (object) ([
            'id' => $child->id,
            'stage' => $stage,
            'closedreason' => $stage === 'closed' && in_array($reason, lists::CLOSED_REASONS, true) ? $reason : null,
            // The step it had, to open it again there.
            'closedstage' => $stage !== 'closed' ? null : ($was !== 'closed' ? $was : ($fresh->closedstage ?? null)),
            'stagetime' => $now,
            'timemodified' => $now,
            // It had left and it is here, or asking, again: its leaving stays in the history.
        ] + ($left ? ['lefttime' => 0, 'leftreason' => null, 'leftnote' => null, 'familygone' => 0] : [])));
        if (!in_array($stage, ['inquiry', 'closed'], true)) {
            self::admit((int) $child->householdid);
        }
        // Enrolled, the child passes to the common family record of the centre; taken back, it leaves it.
        if ($stage === 'enrolled' && ($was !== 'enrolled' || $left) && ($household = self::get((int) $child->householdid, 0))) {
            // The school year it starts: what is only for new children (the story, the eve, the first day) is for it.
            $year = (string) $DB->get_field(centres::OFFERS, 'schoolyear', ['id' => (int) ($child->offerid ?? 0)]);
            if ($year !== '' && trim((string) ($child->startyear ?? '')) === '') {
                $DB->set_field(self::CHILDREN, 'startyear', $year, ['id' => $child->id]);
            }
            // The common record is another plugin: if it fails, the way of the child is kept all the same.
            try {
                family::enrol($household, $child);
            } catch (\Throwable $e) {
                debugging('Eguahe: Family did not take child ' . $child->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        } else if ($was === 'enrolled' && $stage !== 'enrolled' && !$left) {
            try {
                family::leave($child);
            } catch (\Throwable $e) {
                debugging('Eguahe: Family did not let child ' . $child->id . ' go: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Moves forward the way of the children of a household that have not gone so far yet (attending an open day
     * makes a visit), never back.
     *
     * @param int $householdid
     * @param string $stage
     */
    public static function advance(int $householdid, string $stage): void {
        global $DB;
        foreach ($DB->get_records(self::CHILDREN, ['householdid' => $householdid]) as $child) {
            if (lists::further($child->stage, $stage)) {
                self::set_stage($child, $stage);
            }
        }
    }

    /**
     * Gives the household one more private link; the ones before keep working. Only its hash is kept.
     *
     * @param \stdClass $household
     * @param string $reason signup, invitation or team.
     * @return string The token, to send it once.
     */
    public static function new_link(\stdClass $household, string $reason = 'signup'): string {
        global $DB;
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        // Each message may carry its own link: the ones sent before keep working.
        $DB->insert_record(self::LINKS, (object) ['centreid' => $household->centreid, 'householdid' => $household->id,
            'linkhash' => $hash, 'reason' => \core_text::substr($reason, 0, 20), 'timecreated' => time()]);
        $DB->set_field(self::T, 'linkhash', $hash, ['id' => $household->id]);
        $household->linkhash = $hash;
        return $token;
    }

    /**
     * Makes every link of a family stop working (a link that went where it should not), and gives a new one.
     *
     * @param \stdClass $household
     * @return string The new link.
     */
    public static function renew_links(\stdClass $household): string {
        global $DB;
        $DB->delete_records(self::LINKS, ['householdid' => $household->id]);
        return self::new_link($household, 'team');
    }

    /**
     * The household of a private link.
     *
     * @param string $token
     * @return \stdClass|null
     */
    public static function by_link(string $token): ?\stdClass {
        global $DB;
        if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $token)) {
            return null;
        }
        $householdid = $DB->get_field(self::LINKS, 'householdid', ['linkhash' => hash('sha256', $token)]);
        return self::as_family($householdid ? ($DB->get_record(self::T, ['id' => $householdid]) ?: null) : null);
    }

    /**
     * Everything about a household: its adults, children, history, permissions and events.
     *
     * @param \stdClass $household
     * @return \stdClass
     */
    public static function full(\stdClass $household): \stdClass {
        global $DB;
        $id = ['householdid' => $household->id];
        $full = clone($household);
        $full->adults = $DB->get_records(self::ADULTS, $id, 'id ASC');
        $full->children = $DB->get_records(self::CHILDREN, $id, 'id ASC');
        $full->contacts = $DB->get_records(self::CONTACTS, $id, 'timecreated ASC, id ASC');
        $full->consents = $DB->get_records(self::CONSENTS, $id, 'timecreated ASC, id ASC');
        $full->signups = $DB->get_records_sql('SELECT s.*, e.name AS eventname, COALESCE(t.timestart, e.timestart) AS timestart,
                                                      COALESCE(t.timeend, e.timeend) AS timeend, e.place, e.kind,
                                                      e.hosts, e.open AS eventopen, e.token AS eventtoken,
                                                      e.tailored AS eventtailored, e.householdid AS eventhousehold
                                                 FROM {' . self::SIGNUPS . '} s JOIN {local_oksigeniaeguahe_event} e
                                                      ON e.id = s.eventid
                                            LEFT JOIN {local_oksigeniaeguahe_slot} t ON t.id = s.slotid
                                                WHERE s.householdid = :householdid ORDER BY timestart ASC', $id);
        return $full;
    }

    /**
     * What the household can take with it: its data as it is kept, in plain words and without the notes of the team,
     * which are the centre's work, not data the family gave.
     *
     * @param \stdClass $household
     * @return array
     */
    public static function export(\stdClass $household): array {
        global $DB;
        $full = self::full($household);
        $offers = $DB->get_records_menu(centres::OFFERS, ['centreid' => $household->centreid], '', 'id, name');
        $date = fn(int $time) => $time ? date('c', $time) : null;
        // A family of which only the day it came is known has no hour here either.
        $dayonly = fn(int $time) => userdate($time, '%Y-%m-%d', \core_date::get_server_timezone(), false);
        $born = array_filter($full->contacts, fn($line) => (int) $line->timecreated === (int) $full->timecreated && $line->dayonly);
        $data = [
            'household' => [
                'language' => $full->lang,
                'municipality' => $full->municipalityname,
                'heard_from' => $full->heardfrom,
                'arrived_from' => array_filter(['source' => $full->arrivalsource ?? null, 'medium' => $full->arrivalmedium ?? null,
                    'campaign' => $full->arrivalcampaign ?? null, 'site' => $full->arrivalref ?? null]) ?: null,
                'family_that_told_them' => $full->heardwho ?? null,
                'network' => $full->heardnet ?? null,
                'first_channel' => $full->firstchannel,
                'already_of_the_centre' => (bool) $full->incentre,
                'not_going_on' => outs::is_out($full) ? array_filter(['when' => $date((int) $full->outtime),
                    'why' => get_string('out_' . $full->outreason, 'local_oksigeniaeguahe'), 'note' => trim((string) $full->outnote)]) : null,
                'offered_to_tell_another_family' => $date((int) ($full->volunteer ?? 0)),
                'how_your_invitation_is_signed' => $full->invitesign ?? null,
                'why_you_chose_another_centre' => ($full->whyothertime ?? 0) ? ($full->whyother ?: '') : null,
                'summary_for_the_team' => $full->aisummary ?? null,
                'how_you_want_us_to_write' => ($full->writeby ?? '') !== '' ? ['way' => $full->writeby,
                    'since' => $date((int) $full->writebytime),
                    'last_asked_not_to' => $date((int) ($full->writebynone ?? 0))] : null,
                'no_invitations_to_events_since' => $date((int) ($full->noinvites ?? 0)),
                'phones_being_replaced' => (string) ($full->swapphone ?? ''),
                'created' => $born ? $dayonly((int) $full->timecreated) : $date((int) $full->timecreated),
            ],
            'adults' => [],
            'children' => [],
            'events' => [],
            'messages' => [],
            'permissions' => [],
        ];
        foreach ($full->adults as $adult) {
            $data['adults'][] = ['first_name' => $adult->firstname, 'last_name' => $adult->lastname,
                'email' => $adult->email, 'phone' => $adult->phone];
        }
        foreach ($full->children as $child) {
            $data['children'][] = ['first_name' => $child->firstname, 'name_at_home' => $child->tagname,
                'name_in_latin_letters' => $child->latinname ?? null, 'date_of_birth' => $child->birthdate ?? null,
                'asks_for' => $offers[$child->offerid] ?? null,
                'stage' => $child->stage, 'since' => $date((int) $child->stagetime),
                'started' => $date((int) ($child->started ?? 0)), 'left' => $date((int) ($child->lefttime ?? 0)),
                'left_why' => $child->leftreason ?? null, 'left_line' => $child->leftnote ?? null,
                'drawing' => drawings::file((int) $child->id) ? 'yes' : null,
                'drawing_strokes' => drawings::strokes_file((int) $child->id) ? 'yes' : null];
        }
        foreach ($full->signups as $signup) {
            $data['events'][] = ['event' => $signup->eventname, 'starts' => $date((int) $signup->timestart),
                'people' => (int) $signup->people, 'came' => $date((int) $signup->attended),
                'did_not_come' => $date((int) ($signup->missed ?? 0))];
        }
        // The visits a sheet of the team gives, and what was said of them.
        foreach (appointments::of($household) as $one) {
            if ($one['source'] !== 'eguahe') {
                $data['visits_the_team_wrote_down'][] = ['when' => $one['dayonly'] ? $dayonly((int) $one['start']) : $date((int) $one['start']),
                    'came' => ['came' => 'yes', 'notcame' => 'no'][$one['state']] ?? null];
            }
        }
        foreach ($full->contacts as $contact) {
            // What they wrote and what the centre wrote, with the line the team reads of each message.
            if ($contact->direction !== 'note') {
                $data['messages'][] = ['from' => $contact->direction === 'in' ? 'family' : 'centre',
                    'channel' => $contact->channel, 'text' => $contact->body,
                    'in_a_line' => trim((string) ($contact->asks ?? '')) !== '' ? $contact->asks : null,
                    'when' => empty($contact->dayonly) ? $date((int) $contact->timecreated) :
                        $dayonly((int) $contact->timecreated)];
            }
        }
        // What the centre promised them, with its day.
        foreach ($DB->get_records(attend::PROMISES, ['householdid' => $household->id], 'timecreated ASC') as $promise) {
            $data['promised'][] = ['what' => $promise->body, 'by' => $promise->dueday ?: null,
                'when' => $date((int) $promise->timecreated), 'done' => $date((int) $promise->timedone)];
        }
        // The open acts they were invited to.
        foreach ($DB->get_records(invites::T, ['householdid' => $household->id], 'timecreated ASC') as $invite) {
            $data['invited_to'][] = ['event' => (string) $DB->get_field(events::T, 'name', ['id' => $invite->eventid]),
                'when' => $date((int) $invite->timecreated), 'by' => (string) $invite->channel ?: 'email',
                'opened' => $date((int) ($invite->opened ?? 0)), 'you_said' => (string) ($invite->reply ?? '') ?: null,
                'said_when' => $date((int) ($invite->replied ?? 0))];
        }
        // The notices the centre sent them by itself.
        foreach ($DB->get_records(notices::T, ['householdid' => $household->id], 'timecreated ASC, id ASC') as $notice) {
            $data['notices'][] = ['what' => $notice->kind, 'about' => $notice->what, 'by' => $notice->channel, 'state' => $notice->state,
                'when' => $date((int) $notice->timecreated)];
        }
        // What the lists of students of the centre say of the family.
        foreach ($DB->get_records(listmatch::T, ['householdid' => $household->id], 'timecreated ASC, id ASC') as $row) {
            $data['in_the_lists_of_the_centre'][] = ['school_year' => $row->schoolyear, 'course' => $row->grade, 'as' => $row->kind,
                'since' => $date((int) $row->timein), 'child' => $full->children[(int) $row->childid]->firstname ?? null];
        }
        $names = [];
        foreach ($full->adults as $adult) {
            $names[$adult->id] = trim($adult->firstname . ' ' . $adult->lastname);
        }
        foreach ($full->consents as $consent) {
            $data['permissions'][] = ['adult' => $names[$consent->adultid] ?? null, 'channel' => $consent->channel,
                'granted' => (bool) $consent->granted, 'how' => $consent->source ?? null,
                'text_read' => $consent->textversion ?? null, 'when' => $date((int) $consent->timecreated)];
        }
        // What they answered in the forms of the centre.
        foreach (forms::answers_of((int) $household->id) as $answer) {
            $data['form_answers'][] = ['question' => $answer->label, 'answer' => $answer->value,
                'when' => $date((int) $answer->timecreated)];
        }
        // What they told after each visit: what they were left to ask, and the survey.
        foreach (surveys::of_household($household, (string) $household->lang ?: 'es') as $told) {
            $data['after_visits'][] = ['event' => $told['name'], 'when' => $date($told['timestart']),
                'answers' => array_map(fn($a) => ['question' => $a['label'], 'answer' => $a['value']], $told['answers'])];
        }
        // The brothers and sisters who would come later.
        foreach (siblings::of((int) $household->id) as $sibling) {
            $data['brothers_and_sisters_for_later'][] = ['first_name' => $sibling->firstname,
                'year_of_birth' => (int) $sibling->birthyear, 'told_by' => $sibling->source,
                'asked_to_be_told' => $date((int) $sibling->notify), 'said_yes' => $date((int) $sibling->confirmed)];
        }
        // What each adult likes doing, if they told it.
        $data['likes'] = hobbies::export((int) $household->id);
        // Who studied at the centre, as they or the team said it.
        $data['studied_at_the_centre'] = alumni::export((int) $household->id);
        // What ties them to the centre, as the team marked it.
        $data['relation_with_the_centre'] = ties::export((int) $household->id);
        // What was counted of what they did on their page and with the mails.
        $data['what_was_counted'] = seen::export((int) $household->id);
        // A visit of its own: when they said it suits them.
        foreach ($DB->get_records(visits::REQUESTS, ['householdid' => $household->id], 'timecreated ASC') as $request) {
            $data['visit_requests'][] = ['days' => $request->days ? array_map('intval', explode(',', $request->days)) : [],
                'parts_of_the_day' => $request->periods ? explode(',', $request->periods) : [],
                'people' => (int) $request->people, 'status' => $request->status, 'asked' => $date((int) $request->timecreated)];
        }
        // Whether each child goes on into the next school year, as they or the team said it.
        foreach ($DB->get_records(renewal::T, ['householdid' => $household->id], 'schoolyear ASC, id ASC') as $row) {
            $data['next_school_year'][] = ['child' => $full->children[$row->childid]->firstname ?? null,
                'school_year' => $row->schoolyear, 'answer' => $row->answer, 'why' => $row->reason, 'note' => $row->note,
                'said_by' => (int) $row->userid ? 'team' : 'family', 'when' => $date((int) $row->timemodified)];
        }
        return $data;
    }

    /**
     * Keeps the emails and phones of a family that erases its data as keyed hashes of the centre, nothing else.
     *
     * @param \stdClass $household
     */
    protected static function remember_gone(\stdClass $household): void {
        global $DB;
        $keys = new pseudonym((int) $household->centreid);
        $now = time();
        foreach ($DB->get_records(self::ADULTS, ['householdid' => $household->id], '', 'id, email, phone') as $adult) {
            foreach ([pseudonym::email((string) $adult->email), pseudonym::phone((string) $adult->phone)] as $key) {
                if ($key !== null) {
                    $DB->insert_record(self::GONE, (object) ['centreid' => $household->centreid,
                        'keyhash' => $keys->keyhash('gone', $key), 'timecreated' => $now]);
                }
            }
        }
    }

    /**
     * Whether some of these emails or phones belong to a family that erased its data after a moment: a row sent before
     * it (or without its own day) is from then, and does not come back.
     *
     * @param int $centreid
     * @param array $contacts Emails and phones as written.
     * @param int $time When the row says it was sent; 0, it does not say.
     * @return bool
     */
    public static function erased_before(int $centreid, array $contacts, int $time): bool {
        global $DB;
        $keys = new pseudonym($centreid);
        $hashes = [];
        foreach ($contacts as $value) {
            foreach ([pseudonym::email((string) $value), pseudonym::phone((string) $value)] as $key) {
                if ($key !== null) {
                    $hashes[] = $keys->keyhash('gone', $key);
                }
            }
        }
        if (!$hashes) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal(array_values(array_unique($hashes)), SQL_PARAMS_NAMED);
        $erased = (int) $DB->get_field_select(
            self::GONE,
            'MAX(timecreated)',
            "centreid = :centreid AND keyhash $in",
            ['centreid' => $centreid] + $params
        );
        return $erased > 0 && (!$time || $time <= $erased);
    }

    /**
     * Erases a household and everything about it. What is left, so the numbers of the campaign stay true, says
     * nothing about who it was: how far each child's way went, for which offer, from which channel and in which month.
     *
     * @param \stdClass $household
     */
    public static function erase(\stdClass $household): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $month = date('Y-m', (int) $household->timecreated);
        // Their emails and phones as keyed hashes only: an old row of a sheet sent again never brings them back.
        self::remember_gone($household);
        $children = $DB->get_records(self::CHILDREN, ['householdid' => $household->id]);
        // The drawings of its children go with the family.
        foreach ($children as $child) {
            drawings::delete((int) $child->id);
        }
        foreach ($children ?: [(object) ['offerid' => 0, 'stage' => 'inquiry']] as $child) {
            $DB->insert_record(self::ERASED, (object) ['centreid' => $household->centreid, 'offerid' => $child->offerid,
                'stage' => $child->stage, 'firstchannel' => $household->firstchannel, 'month' => $month,
                'timecreated' => time()]);
        }
        // Brought from the roll of the centre (or in it since it enrolled): never brought back.
        roster::erased($household);
        // Nor named in the answers of always of the centre, nor in what was told to the team of it.
        attend::forget_family($household);
        self::forget_notices($household);
        lookalike::forget_drawings((int) $household->id);
        // Its visits go: from the calendar of the team too, and whoever was to receive it knows that the hour is free.
        $now = time();
        $visits = $DB->get_records(events::T, ['householdid' => $household->id], 'id ASC', 'id, timestart, cancelled');
        calsync::erased((int) $household->centreid, array_map('intval', array_keys($visits)));
        foreach ($visits as $visit) {
            if ((int) $visit->timestart > $now && !(int) $visit->cancelled) {
                self::gone_visit((int) $household->centreid, (int) $visit->timestart);
            }
        }
        foreach ($DB->get_records_select(teamnotes::T, 'householdid = ? AND visit > ? AND replacedby = 0', [$household->id, $now], 'id ASC') as $note) {
            if ((string) $note->state === '') {
                self::gone_visit((int) $household->centreid, (int) $note->visit);
            }
        }
        // What waits for its phones never goes out, and its phones are unjoined in Marandu: nobody answers any more for a
        // family that is gone.
        messaging::withdraw((int) $household->id);
        if (class_exists('\\local_oksigeniamarandu\\api') && method_exists('\\local_oksigeniamarandu\\api', 'unlink')) {
            try {
                \local_oksigeniamarandu\api::unlink('local_oksigeniaeguahe', (int) $household->id);
            } catch (\Throwable $e) {
                debugging('Eguahe: Marandu did not unlink household ' . $household->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        foreach (
            [self::CONSENTS, self::CONTACTS, self::SIGNUPS, self::CHILDREN, self::ADULTS, self::LINKS, visits::REQUESTS,
                forms::ANSWERS, attend::PROMISES, surveys::T, seen::T, hobbies::T, siblings::T, invites::T, renewal::T,
                alumni::T, access::T, appointments::T, notices::T, settle::T, ties::T] as $table
        ) {
            $DB->delete_records($table, ['householdid' => $household->id]);
        }
        // The visits of its own go with the family.
        $DB->delete_records(events::T, ['householdid' => $household->id]);
        // And what a person said of it and another family.
        $DB->delete_records_select(lookalike::T, 'householda = :a OR householdb = :b', ['a' => $household->id,
            'b' => $household->id]);
        $DB->delete_records(lookalike::LOG, ['householdid' => $household->id]);
        $DB->delete_records(lookalike::TWICE, ['householdid' => $household->id]);
        $DB->delete_records(bounces::T, ['householdid' => $household->id]);
        $DB->delete_records(listmatch::T, ['householdid' => $household->id]);
        $DB->delete_records(teamnotes::T, ['householdid' => $household->id]);
        // What came through an entry stays only as a key without the family, so that sending the old answers again does
        // not bring it back.
        $DB->set_field(entries::ARRIVALS, 'householdid', 0, ['householdid' => $household->id]);
        $DB->delete_records(self::T, ['id' => $household->id]);
        $transaction->allow_commit();
    }

    /**
     * A family that erased its data had a visit to come: only when, for the team to know that the hour is free.
     *
     * @param int $centreid
     * @param int $start
     */
    protected static function gone_visit(int $centreid, int $start): void {
        $kept = array_values(array_unique(array_merge(self::gone_visits($centreid, time()), [$start])));
        set_config('gonevisits_' . $centreid, implode(',', array_slice($kept, -50)), 'local_oksigeniaeguahe');
    }

    /**
     * The visits to come of families that erased their data since.
     *
     * @param int $centreid
     * @param int $now
     * @return int[] When each was.
     */
    public static function gone_visits(int $centreid, int $now): array {
        $kept = array_map('intval', array_filter(explode(',', (string) get_config('local_oksigeniaeguahe', 'gonevisits_' . $centreid))));
        $kept = array_values(array_filter($kept, fn($start) => $start > $now));
        sort($kept);
        return $kept;
    }

    /**
     * What the team was told of a family in the notices of the site («New family», with its names and what it wrote) goes when
     * it erases its data. One that tells of several families keeps the others.
     *
     * @param \stdClass $household
     */
    protected static function forget_notices(\stdClass $household): void {
        global $DB;
        $url = (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false);
        $like = $DB->sql_like('fullmessage', ':url');
        $rows = $DB->get_records_select(
            'notifications',
            "component = :component AND $like",
            ['component' => 'local_oksigeniaeguahe', 'url' => '%' . $DB->sql_like_escape($url) . '%'],
            'id ASC',
            'id, fullmessage, fullmessagehtml, contexturl'
        );
        foreach ($rows as $row) {
            // Never of another file whose number starts as this one.
            $mine = '~' . preg_quote($url, '~') . '(?![0-9])~';
            if (!preg_match($mine, (string) $row->fullmessage)) {
                continue;
            }
            $parts = preg_split('/\n{2,}/', (string) $row->fullmessage);
            $kept = array_filter($parts, fn($part) => !preg_match($mine, $part));
            $others = array_filter($kept, fn($part) => strpos($part, '/local/oksigeniaeguahe/household.php') !== false);
            if (!$others) {
                $DB->delete_records('message_popup_notifications', ['notificationid' => $row->id]);
                $DB->delete_records('notifications', ['id' => $row->id]);
                continue;
            }
            $html = preg_replace('~<li><a href="' . preg_quote(s($url), '~') . '">.*?</li>~s', '', (string) $row->fullmessagehtml);
            $DB->update_record('notifications', (object) ['id' => $row->id, 'fullmessage' => implode("\n\n", $kept),
                'fullmessagehtml' => $html]);
        }
    }

    /**
     * The households whose time is over: no contact for the months their centre keeps data, and no child enrolled.
     *
     * @param int $now
     * @return \stdClass[]
     */
    public static function expired(int $now): array {
        global $DB;
        $expired = [];
        foreach ($DB->get_records('local_oksigeniaeguahe_centre', null, '', 'id, retention') as $centre) {
            $limit = (new \DateTimeImmutable('@' . $now))->modify('-' . max(1, (int) $centre->retention) . ' months')
                ->getTimestamp();
            $sql = 'SELECT h.* FROM {' . self::T . '} h
                     WHERE h.centreid = :centreid AND h.lastcontact < :limit AND h.waitingsince = 0
                       AND NOT EXISTS (SELECT 1 FROM {' . self::SIGNUPS . '} s
                                         JOIN {' . events::T . '} e ON e.id = s.eventid
                                        WHERE s.householdid = h.id AND e.cancelled = 0 AND e.timeend > :now)
                       AND NOT EXISTS (SELECT 1 FROM {' . teamnotes::T . '} n
                                        WHERE n.householdid = h.id AND n.replacedby = 0 AND n.visit > :now2)
                       AND NOT EXISTS (SELECT 1 FROM {' . self::CHILDREN . '} c
                                        WHERE c.householdid = h.id AND c.stage = :enrolled
                                              AND (c.lefttime IS NULL OR c.lefttime = 0 OR c.lefttime >= :limit2))';
            // Kept while a child studies at the centre; once the last one left, as long again as any other family. Never one
            // that waits for an answer, nor one with a visit to come.
            $expired += $DB->get_records_sql($sql, ['centreid' => $centre->id, 'limit' => $limit, 'limit2' => $limit,
                'enrolled' => 'enrolled', 'now' => $now, 'now2' => $now]);
        }
        return $expired;
    }

    /**
     * What is only of the admissions, kept no longer than the centre keeps any family, also in a family that stays
     * because its children study at the centre: what was counted of its page, what it answered after a visit, and the
     * summary of the AI. Its messages, its children and its permissions stay.
     *
     * @param int $now
     */
    public static function tidy(int $now): void {
        global $DB;
        foreach ($DB->get_records('local_oksigeniaeguahe_centre', null, '', 'id, retention') as $centre) {
            $limit = (new \DateTimeImmutable('@' . $now))->modify('-' . max(1, (int) $centre->retention) . ' months')
                ->getTimestamp();
            $DB->delete_records_select(seen::T, 'centreid = ? AND timecreated < ?', [$centre->id, $limit]);
            // The keys of families that erased their data last as long as any family of the centre would.
            $DB->delete_records_select(self::GONE, 'centreid = ? AND timecreated < ?', [$centre->id, $limit]);
            $DB->delete_records_select(surveys::T, 'centreid = ? AND timecreated < ?', [$centre->id, $limit]);
            $DB->execute('UPDATE {' . self::T . '} SET aisummary = NULL, aisummarytime = 0
                           WHERE centreid = ? AND aisummarytime > 0 AND aisummarytime < ?', [$centre->id, $limit]);
        }
        // What was kept of files put together, when the file is gone.
        $DB->delete_records_select(lookalike::LOG, 'householdid NOT IN (SELECT id FROM {' . self::T . '})');
        $DB->delete_records_select(lookalike::TWICE, 'householdid NOT IN (SELECT id FROM {' . self::T . '})');
        $DB->delete_records_select(bounces::T, 'householdid NOT IN (SELECT id FROM {' . self::T . '})');
        // The private links opened from the app of Aty (app.php) last two days: whoever wants the page again comes from
        // the app, which makes another.
        $DB->delete_records_select(self::LINKS, 'reason = ? AND timecreated < ?', ['app', $now - 2 * DAYSECS]);
    }

    /**
     * The households of a centre for the inbox: those waiting first, the longest wait at the top; then the rest by their
     * last contact.
     *
     * @param int $centreid
     * @param bool $waitingonly
     * @param int $limit
     * @param bool $roster Also the families brought from the roll of the centre that wait for nobody.
     * @param int $course Only those with a child asking for this offer (its id); 0, any.
     * @param string $stage Only those with a child at this step (of that offer, when there is one); empty, any.
     * @param int[] $also The ones that are there whatever they are: those with something promised and due, or that come today.
     * @return \stdClass[] Households with adults and children (with the name of their offer).
     */
    public static function inbox(
        int $centreid,
        bool $waitingonly,
        int $limit = 200,
        bool $roster = false,
        int $course = 0,
        string $stage = '',
        array $also = []
    ): array {
        global $DB;
        $params = ['centreid' => $centreid];
        $where = 'centreid = :centreid' . self::listed_where($waitingonly, $roster, $also, $params);
        $where .= self::children_where($centreid, $course, $stage, $params);
        // Those that are always there, never left out by the length of the list.
        $first = '';
        if ($also) {
            [$in, $inparams] = $DB->get_in_or_equal(array_map('intval', $also), SQL_PARAMS_NAMED, 'first');
            $first = "CASE WHEN id $in THEN 0 ELSE 1 END, ";
            $params += $inparams;
        }
        $order = $first . 'CASE WHEN waitingsince > 0 THEN 0 ELSE 1 END, waitingsince ASC, lastcontact DESC';
        return self::with_people($centreid, $DB->get_records_sql(
            'SELECT * FROM {' . self::T . "} WHERE $where ORDER BY $order",
            $params,
            0,
            $limit
        ));
    }

    /**
     * Which households of a centre the inbox lists. Those brought from its roll (there may be hundreds) and whoever only came
     * to an act, only when they wait for someone or have a child on its way.
     *
     * @param bool $waitingonly
     * @param bool $roster Also those brought from the roll that wait for nobody.
     * @param int[] $also The ones that are there whatever they are.
     * @param array $params Where its parameters are added.
     * @return string The condition to add after the centre.
     */
    protected static function listed_where(bool $waitingonly, bool $roster, array $also, array &$params): string {
        global $DB;
        $where = $waitingonly ? 'waitingsince > 0' : ($roster ? '1 = 1' : '(roster = 0 AND guest = 0) OR waitingsince > 0 OR (guest = 0
            AND id IN (SELECT w.householdid FROM {' . self::CHILDREN . "} w WHERE w.stage NOT IN ('enrolled', 'closed')))");
        if ($also) {
            [$in, $inparams] = $DB->get_in_or_equal(array_map('intval', $also), SQL_PARAMS_NAMED, 'also');
            $where .= " OR id $in";
            $params += $inparams;
        }
        // Those made up to see how something arrives are in no list.
        return " AND guest <> 2 AND ($where)";
    }

    /**
     * The households of a centre whose people have every word of a search: names of the adults and the children, the
     * name they are called at home, emails and phones; without minding capitals or accents. Every family of the centre,
     * also those brought from its roll and those already answered, never only those on the screen.
     *
     * @param int $centreid
     * @param string $query What the person wrote.
     * @param bool $waitingonly Only those that wait for an answer.
     * @param int $limit
     * @param int $course Only those with a child asking for this offer (its id); 0, any.
     * @param string $stage Only those with a child at this step; empty, any.
     * @return array [households with adults and children, as inbox() gives them; how many there are in all]
     */
    public static function search(
        int $centreid,
        string $query,
        bool $waitingonly,
        int $limit = 200,
        int $course = 0,
        string $stage = ''
    ): array {
        global $DB;
        [$where, $params] = self::search_where($centreid, $query);
        if ($where === '') {
            return [[], 0];
        }
        $where .= ($waitingonly ? ' AND waitingsince > 0' : '') . self::children_where($centreid, $course, $stage, $params);
        $count = $DB->count_records_select(self::T, $where, $params);
        if (!$count) {
            return [[], 0];
        }
        $order = 'CASE WHEN waitingsince > 0 THEN 0 ELSE 1 END, waitingsince ASC, lastcontact DESC';
        return [self::with_people($centreid, $DB->get_records_select(self::T, $where, $params, $order, '*', 0, $limit)),
            $count];
    }

    /**
     * The condition of a search on the households of a centre: every word, somewhere among its people.
     *
     * @param int $centreid
     * @param string $query
     * @return array [where, params]; an empty where when there is nothing to look for
     */
    protected static function search_where(int $centreid, string $query): array {
        global $DB;
        $words = array_slice(array_values(array_filter(
            preg_split('/\s+/u', \core_text::strtolower(trim($query))) ?: [],
            fn($word) => $word !== ''
        )), 0, 6);
        if (!$words) {
            return ['', []];
        }
        $where = 'centreid = :centreid';
        $params = ['centreid' => $centreid];
        foreach ($words as $i => $word) {
            $like = '%' . $DB->sql_like_escape($word) . '%';
            $adults = [];
            foreach (['firstname', 'lastname', 'email', 'phone'] as $field) {
                $adults[] = $DB->sql_like($field, ":a{$field}{$i}", false, false);
                $params["a{$field}{$i}"] = $like;
            }
            $children = [];
            foreach (['firstname', 'tagname', 'latinname'] as $field) {
                $children[] = $DB->sql_like($field, ":c{$field}{$i}", false, false);
                $params["c{$field}{$i}"] = $like;
            }
            $params["ac{$i}"] = $centreid;
            $params["cc{$i}"] = $centreid;
            $where .= " AND (id IN (SELECT householdid FROM {" . self::ADULTS . "} WHERE centreid = :ac{$i} AND (" .
                implode(' OR ', $adults) . ")) OR id IN (SELECT householdid FROM {" . self::CHILDREN .
                "} WHERE centreid = :cc{$i} AND (" . implode(' OR ', $children) . ')))';
        }
        return [$where, $params];
    }

    /**
     * How many households the inbox can list: those that wait, or every family but those brought from the roll of the
     * centre that wait for nobody (they come up when searched).
     *
     * @param int $centreid
     * @param bool $waitingonly
     * @param int $course As in inbox().
     * @param string $stage As in inbox().
     * @param int[] $also As in inbox().
     * @return int
     */
    public static function inbox_count(
        int $centreid,
        bool $waitingonly,
        int $course = 0,
        string $stage = '',
        array $also = []
    ): int {
        global $DB;
        $params = ['centreid' => $centreid];
        $where = 'centreid = :centreid' . self::listed_where($waitingonly, false, $also, $params);
        return $DB->count_records_select(self::T, $where . self::children_where($centreid, $course, $stage, $params), $params);
    }

    /**
     * The condition on the children of a household for the filters of the inbox: a same child with the offer and the
     * step asked, as the page filters them.
     *
     * @param int $centreid
     * @param int $course An offer id; 0, any.
     * @param string $stage A step; empty, any.
     * @param array $params Where its parameters are added.
     * @return string The condition to add after the others, or nothing.
     */
    protected static function children_where(int $centreid, int $course, string $stage, array &$params): string {
        if (!$course && $stage === '') {
            return '';
        }
        $sql = 'centreid = :kidcentre';
        $params['kidcentre'] = $centreid;
        if ($course) {
            $sql .= ' AND offerid = :kidoffer';
            $params['kidoffer'] = $course;
        }
        if ($stage !== '') {
            $sql .= ' AND stage = :kidstage';
            $params['kidstage'] = $stage;
        }
        return ' AND id IN (SELECT householdid FROM {' . self::CHILDREN . '} WHERE ' . $sql . ')';
    }

    /**
     * Some households of a centre, by id, with their adults and children: the families signed up for an act, without
     * loading every family of a big centre.
     *
     * @param int $centreid
     * @param int[] $ids
     * @param int $limit At most so many, in the order of the inbox (those waiting first); 0, all of them.
     * @return \stdClass[]
     */
    public static function some(int $centreid, array $ids, int $limit = 0): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_values(array_unique($ids)), SQL_PARAMS_NAMED);
        return self::with_people($centreid, $DB->get_records_select(
            self::T,
            "centreid = :centreid AND id $in",
            ['centreid' => $centreid] + $params,
            $limit ? 'CASE WHEN waitingsince > 0 THEN 0 ELSE 1 END, waitingsince ASC, lastcontact DESC' : '',
            '*',
            0,
            $limit
        ));
    }

    /**
     * Hangs their adults and children (with their offer) from some households.
     *
     * @param int $centreid
     * @param \stdClass[] $list Households by id.
     * @return \stdClass[]
     */
    protected static function with_people(int $centreid, array $list): array {
        global $DB;
        if (!$list) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($list), SQL_PARAMS_NAMED);
        foreach ($list as $household) {
            $household->adults = [];
            $household->children = [];
        }
        foreach ($DB->get_records_select(self::ADULTS, "householdid $in", $params, 'id ASC') as $adult) {
            $list[$adult->householdid]->adults[] = $adult;
        }
        $offers = $DB->get_records(centres::OFFERS, ['centreid' => $centreid]);
        foreach ($DB->get_records_select(self::CHILDREN, "householdid $in", $params, 'id ASC') as $child) {
            $child->offer = $offers[$child->offerid] ?? null;
            $list[$child->householdid]->children[] = $child;
        }
        return $list;
    }

    /**
     * The latest of each household of a list, for the rows of the inbox: its last contact with the family (a note of the
     * team does not count), the last thing the family said, what it has waited an answer to the longest, the last answer
     * of a person to something it asked, and the last thing a person wrote to it without being asked.
     *
     * @param int[] $ids
     * @return array Household id => ['last' => contact or null, 'said' => contact or null, 'since' => contact or null,
     *     'answer' => contact or null, 'told' => contact or null, 'line' => the last line of all, a note too, or null].
     */
    public static function latest(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        $out = array_fill_keys($ids, ['last' => null, 'said' => null, 'since' => null, 'answer' => null, 'told' => null,
            'line' => null, 'maybe' => null]);
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $contacts = $DB->get_recordset_select(
            self::CONTACTS,
            "householdid $in",
            $params,
            'timecreated DESC, id DESC',
            'id, householdid, direction, channel, body, entryid, detail, userid, dayonly, aim, timecreated, asks'
        );
        foreach ($contacts as $contact) {
            $row = $out[$contact->householdid];
            $row['line'] = $row['line'] ?? $contact;
            if ($row['last'] === null && $contact->direction !== 'note') {
                $row['last'] = $contact;
            }
            if ($row['said'] === null && $contact->direction === 'in' && trim((string) $contact->body) !== '') {
                $row['said'] = $contact;
            }
            // From the last to the first: of what a person wrote after the family did, the first answers it.
            $asks = $contact->direction === 'in' && !self::is_hand_note($contact);
            $kind = $contact->direction === 'out' ? happened::kind($contact) : '';
            if (in_array($kind, ['answered', 'invited'], true)) {
                $row['told'] = $row['told'] ?? $contact;
            }
            if ($kind === 'answered' && $row['answer'] === null) {
                $row['maybe'] = $contact;
            } else if ($asks && $row['answer'] === null && $row['maybe'] !== null) {
                $row['answer'] = $row['maybe'];
            } else if ($asks && $row['maybe'] === null) {
                $row['since'] = $contact;
            }
            $out[$contact->householdid] = $row;
        }
        $contacts->close();
        return $out;
    }

    /**
     * How many households of a centre wait for an answer.
     *
     * @param int $centreid
     * @return int
     */
    public static function waiting_count(int $centreid): int {
        global $DB;
        return $DB->count_records_select(
            self::T,
            'centreid = :centreid AND waitingsince > 0 AND guest <> 2',
            ['centreid' => $centreid]
        );
    }

    /**
     * How each offer of a centre is going: children at each stage of the families of the campaign; enrolled, all who
     * have not left, of any family.
     *
     * @param int $centreid
     * @return array Offer id => [stage => count].
     */
    public static function offer_counts(int $centreid): array {
        global $DB;
        $counts = [];
        // A place is taken by every child enrolled who has not left, wherever the family came from (the campaign, the
        // roll of the centre, a brother or sister); one who left frees it. The rest of the way, only the families of
        // the campaign.
        $rows = $DB->get_recordset_sql('SELECT c.id, c.offerid, c.stage, c.studentkey
                                          FROM {' . self::CHILDREN . '} c JOIN {' . self::T . '} h ON h.id = c.householdid
                                         WHERE c.centreid = :centreid AND h.guest <> 2
                                               AND ((c.stage = :enrolled AND COALESCE(c.lefttime, 0) = 0)
                                                    OR (c.stage <> :enrolled2 AND h.incentre = 0 AND h.guest = 0))
                                      ORDER BY c.id ASC', ['centreid' => $centreid, 'enrolled' => 'enrolled',
            'enrolled2' => 'enrolled']);
        // A student takes one place, though its mother and its father come from the common family record as two families.
        $seats = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row->studentkey ?? ''));
            if ($row->stage === 'enrolled' && $key !== '') {
                if (isset($seats[$key])) {
                    continue;
                }
                $seats[$key] = true;
            }
            $counts[$row->offerid][$row->stage] = ($counts[$row->offerid][$row->stage] ?? 0) + 1;
        }
        $rows->close();
        return $counts;
    }

    /**
     * An email as kept: trimmed, lower case, or null if it is not one.
     *
     * @param string|null $email
     * @return string|null
     */
    public static function email(?string $email): ?string {
        $email = \core_text::strtolower(trim((string) $email));
        return $email !== '' && validate_email($email) ? \core_text::substr($email, 0, 254) : null;
    }

    /**
     * A text cut to the length of its field, or null when empty.
     *
     * @param string|null $text
     * @param int $length
     * @return string|null
     */
    protected static function short(?string $text, int $length): ?string {
        $text = trim((string) $text);
        return $text === '' ? null : \core_text::substr($text, 0, $length);
    }
}
