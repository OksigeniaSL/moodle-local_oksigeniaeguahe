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
        return $DB->get_record(self::T, $params) ?: null;
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
     * @param \stdClass $data lang, firstchannel and, if known, municipality, municipalityname and heardfrom.
     * @return int
     */
    public static function create(int $centreid, \stdClass $data): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record(self::T, (object) [
            'centreid' => $centreid,
            'lang' => in_array($data->lang ?? '', lists::LANGS, true) ? $data->lang : 'en',
            'municipality' => self::short($data->municipality ?? null, 20),
            'municipalityname' => self::short($data->municipalityname ?? null, 255),
            'heardfrom' => in_array($data->heardfrom ?? '', lists::HEARD_FROM, true) ? $data->heardfrom : null,
            'heardwho' => ($data->heardfrom ?? '') === 'centrefamilies' ? self::short($data->heardwho ?? null, 255) : null,
            'firstchannel' => in_array($data->firstchannel ?? '', lists::CHANNELS, true) ? $data->firstchannel : 'other',
            'incentre' => empty($data->incentre) ? 0 : 1,
            'moving' => empty($data->moving) ? 0 : 1,
            'waitingsince' => 0,
            'lastcontact' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
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
        // Which family of the centre told them, if they say so: only a name to thank them, never a link.
        if (empty($household->heardwho) && ($data->heardfrom ?? '') === 'centrefamilies' && !empty($data->heardwho)) {
            $changes['heardwho'] = self::short($data->heardwho, 255);
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
     * @return int
     */
    public static function add_adult(\stdClass $household, \stdClass $adult): int {
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
                    return (int) $id;
                }
            }
        }
        $now = time();
        return (int) $DB->insert_record(self::ADULTS, (object) [
            'centreid' => $household->centreid,
            'householdid' => $household->id,
            'firstname' => self::short($adult->firstname ?? '', 100) ?? '',
            'lastname' => self::short($adult->lastname ?? null, 100),
            'email' => $email,
            'phone' => $phone,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Adds a child to a household, or returns the one of the same name it already has.
     *
     * @param \stdClass $household
     * @param string $firstname
     * @param int $offerid 0 while not known.
     * @param string $tagname The name used at home, for the label; empty for the first name.
     * @return int
     */
    public static function add_child(\stdClass $household, string $firstname, int $offerid, string $tagname = ''): int {
        global $DB;
        $firstname = self::short($firstname, 100) ?? '';
        $tagname = self::short($tagname, 100);
        foreach ($DB->get_records(self::CHILDREN, ['householdid' => $household->id]) as $child) {
            if (\core_text::strtolower($child->firstname) === \core_text::strtolower($firstname)) {
                if (!$child->offerid && $offerid) {
                    $DB->set_field(self::CHILDREN, 'offerid', $offerid, ['id' => $child->id]);
                }
                if ($tagname !== null) {
                    $DB->set_field(self::CHILDREN, 'tagname', $tagname, ['id' => $child->id]);
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
     * The household wrote or came: it is noted, and if it asked something, it waits for a person to answer.
     *
     * @param \stdClass $household
     * @param string $channel
     * @param string $body What it said, or what happened.
     * @param bool $awaits Whether it asked something a person has to answer.
     * @param int|null $time
     * @param int $entryid The entry of the centre it came through, if any.
     * @param string $detail What the source said of itself: platform, campaign, ad.
     */
    public static function received(
        \stdClass $household,
        string $channel,
        string $body,
        bool $awaits,
        ?int $time = null,
        int $entryid = 0,
        string $detail = ''
    ): void {
        global $DB;
        $time = $time ?? time();
        self::contact($household, 'in', $channel, $body, 0, $time, $entryid, $detail);
        $changes = (object) ['id' => $household->id, 'lastcontact' => $time, 'timemodified' => $time];
        if ($awaits && !$household->waitingsince) {
            $changes->waitingsince = $time;
            $household->waitingsince = $time;
        }
        $DB->update_record(self::T, $changes);
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
        $DB->update_record(self::T, (object) ['id' => $household->id, 'waitingsince' => 0, 'lastcontact' => $time,
            'timemodified' => $time]);
        $household->waitingsince = 0;
    }

    /**
     * The team told the household something it did not ask (a change in its visit): it goes to its history, and the
     * household keeps waiting if it was.
     *
     * @param \stdClass $household
     * @param int $userid
     * @param string $channel
     * @param string $body
     */
    public static function told(\stdClass $household, int $userid, string $channel, string $body): void {
        global $DB;
        $time = time();
        self::contact($household, 'out', $channel, $body, $userid, $time);
        $DB->update_record(self::T, (object) ['id' => $household->id, 'lastcontact' => $time, 'timemodified' => $time]);
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
     */
    protected static function contact(
        \stdClass $household,
        string $direction,
        string $channel,
        string $body,
        int $userid,
        int $time,
        int $entryid = 0,
        string $detail = ''
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
            'userid' => $userid,
            'timecreated' => $time,
        ]);
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
        $DB->update_record(self::CHILDREN, (object) [
            'id' => $child->id,
            'stage' => $stage,
            'closedreason' => $stage === 'closed' && in_array($reason, lists::CLOSED_REASONS, true) ? $reason : null,
            'stagetime' => $now,
            'timemodified' => $now,
        ]);
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
        return $householdid ? ($DB->get_record(self::T, ['id' => $householdid]) ?: null) : null;
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
                                                      e.hosts
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
        $data = [
            'household' => [
                'language' => $full->lang,
                'municipality' => $full->municipalityname,
                'heard_from' => $full->heardfrom,
                'family_that_told_them' => $full->heardwho ?? null,
                'first_channel' => $full->firstchannel,
                'already_of_the_centre' => (bool) $full->incentre,
                'offered_to_tell_another_family' => $date((int) ($full->volunteer ?? 0)),
                'created' => $date((int) $full->timecreated),
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
            $data['children'][] = ['first_name' => $child->firstname, 'name_on_the_label' => $child->tagname,
                'asks_for' => $offers[$child->offerid] ?? null,
                'stage' => $child->stage, 'since' => $date((int) $child->stagetime),
                'started' => $date((int) ($child->started ?? 0))];
        }
        foreach ($full->signups as $signup) {
            $data['events'][] = ['event' => $signup->eventname, 'starts' => $date((int) $signup->timestart),
                'people' => (int) $signup->people, 'came' => $date((int) $signup->attended)];
        }
        foreach ($full->contacts as $contact) {
            if ($contact->direction !== 'note') {
                $data['messages'][] = ['from' => $contact->direction === 'in' ? 'family' : 'centre',
                    'channel' => $contact->channel, 'text' => $contact->body, 'when' => $date((int) $contact->timecreated)];
            }
        }
        $names = [];
        foreach ($full->adults as $adult) {
            $names[$adult->id] = trim($adult->firstname . ' ' . $adult->lastname);
        }
        foreach ($full->consents as $consent) {
            $data['permissions'][] = ['adult' => $names[$consent->adultid] ?? null, 'channel' => $consent->channel,
                'granted' => (bool) $consent->granted, 'when' => $date((int) $consent->timecreated)];
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
        // A visit of its own: when they said it suits them.
        foreach ($DB->get_records(visits::REQUESTS, ['householdid' => $household->id], 'timecreated ASC') as $request) {
            $data['visit_requests'][] = ['days' => $request->days ? array_map('intval', explode(',', $request->days)) : [],
                'parts_of_the_day' => $request->periods ? explode(',', $request->periods) : [],
                'people' => (int) $request->people, 'status' => $request->status, 'asked' => $date((int) $request->timecreated)];
        }
        return $data;
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
        foreach (
            [self::CONSENTS, self::CONTACTS, self::SIGNUPS, self::CHILDREN, self::ADULTS, self::LINKS,
                visits::REQUESTS, forms::ANSWERS, attend::PROMISES, surveys::T] as $table
        ) {
            $DB->delete_records($table, ['householdid' => $household->id]);
        }
        // The visits of its own go with the family.
        $DB->delete_records(events::T, ['householdid' => $household->id]);
        // What came through an entry stays only as a key without the family, so that sending the old answers again does
        // not bring it back.
        $DB->set_field(entries::ARRIVALS, 'householdid', 0, ['householdid' => $household->id]);
        $DB->delete_records(self::T, ['id' => $household->id]);
        $transaction->allow_commit();
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
                     WHERE h.centreid = :centreid AND h.lastcontact < :limit
                       AND NOT EXISTS (SELECT 1 FROM {' . self::CHILDREN . '} c
                                        WHERE c.householdid = h.id AND c.stage = :enrolled)';
            $expired += $DB->get_records_sql($sql, ['centreid' => $centre->id, 'limit' => $limit, 'enrolled' => 'enrolled']);
        }
        return $expired;
    }

    /**
     * The households of a centre for the inbox: those waiting first, the longest wait at the top; then the rest by their
     * last contact.
     *
     * @param int $centreid
     * @param bool $waitingonly
     * @param int $limit
     * @return \stdClass[] Households with adults and children (with the name of their offer).
     */
    public static function inbox(int $centreid, bool $waitingonly, int $limit = 200): array {
        global $DB;
        $where = 'centreid = :centreid' . ($waitingonly ? ' AND waitingsince > 0' : '');
        $order = 'CASE WHEN waitingsince > 0 THEN 0 ELSE 1 END, waitingsince ASC, lastcontact DESC';
        $list = $DB->get_records_select(self::T, $where, ['centreid' => $centreid], $order, '*', 0, $limit);
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
     * team does not count), the last thing the family said, and the last answer of the team.
     *
     * @param int[] $ids
     * @return array Household id => ['last' => contact or null, 'said' => contact or null, 'answer' => contact or null].
     */
    public static function latest(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        $out = array_fill_keys($ids, ['last' => null, 'said' => null, 'answer' => null]);
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $contacts = $DB->get_recordset_select(
            self::CONTACTS,
            "householdid $in",
            $params,
            'timecreated DESC, id DESC',
            'id, householdid, direction, channel, body, entryid, detail, userid, timecreated'
        );
        foreach ($contacts as $contact) {
            $row = $out[$contact->householdid];
            if ($row['last'] === null && $contact->direction !== 'note') {
                $row['last'] = $contact;
            }
            if ($row['said'] === null && $contact->direction === 'in' && trim((string) $contact->body) !== '') {
                $row['said'] = $contact;
            }
            if ($row['answer'] === null && $contact->direction === 'out') {
                $row['answer'] = $contact;
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
        return $DB->count_records_select(self::T, 'centreid = :centreid AND waitingsince > 0', ['centreid' => $centreid]);
    }

    /**
     * How each offer of a centre is going: children at each stage, without the families already of the centre.
     *
     * @param int $centreid
     * @return array Offer id => [stage => count].
     */
    public static function offer_counts(int $centreid): array {
        global $DB;
        $counts = [];
        // Families already of the centre do not count in the campaign.
        $rows = $DB->get_recordset_sql('SELECT c.offerid, c.stage, COUNT(1) AS n
                                          FROM {' . self::CHILDREN . '} c JOIN {' . self::T . '} h ON h.id = c.householdid
                                         WHERE c.centreid = :centreid AND h.incentre = 0
                                      GROUP BY c.offerid, c.stage', ['centreid' => $centreid]);
        foreach ($rows as $row) {
            $counts[$row->offerid][$row->stage] = (int) $row->n;
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
