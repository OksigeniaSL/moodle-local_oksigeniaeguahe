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
 * The entries of a centre: where families come in from besides its events. The form for its web, a Google form or
 * sheet, the export of the forms of its Meta ads, or any tool that sends them signed. Whatever the source, a family
 * comes in the same way: a household with its adults and children, what it wrote, the channel it came through and
 * waiting in the inbox; the same email or phone joins the household that was there, and nothing comes in twice.
 *
 * What each question is comes from its words (the same vocabulary as the importer), and the team can correct it.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entries {
    /** @var string */
    const T = 'local_oksigeniaeguahe_entry';

    /** @var string */
    const ARRIVALS = 'local_oksigeniaeguahe_arrival';

    /** @var array Kinds of entry and the channel they count as. */
    const KINDS = ['form' => 'webform', 'google' => 'googleforms', 'meta' => 'meta', 'email' => 'email', 'hook' => 'other'];

    /** @var int Something that comes in later than this after it happened does not wait for an answer. */
    const PASTDAYS = 7;

    /** @var string The email of a test: whatever comes through an entry with it only says the entry works. */
    const TESTMAIL = 'prueba@eguahe.test';

    /** @var string[] What a question may be, for the team to correct it, in this order. */
    const ROLES = ['firstname', 'surname', 'fullname', 'email', 'phone', 'childname', 'grade', 'notes', 'municipality',
        'declared', 'consent', 'incentre', 'alumniflag', 'date', 'hour', 'channel', 'team', 'staff', 'platform', 'campaign',
        'adset', 'ad', 'form', 'ids', 'ignore'];

    /** @var string[] What says who someone is or how to reach them: never kept as an example of a question. */
    const PERSONAL = ['firstname', 'surname', 'fullname', 'guardianname', 'email', 'phone', 'childname', 'notes', 'ids',
        'team', 'staff'];

    /** @var string[] The platforms of Meta, as the column of an export of its ads says them once read. */
    const META_PLATFORMS = ['Instagram', 'Facebook', 'Audience Network', 'Messenger', 'WhatsApp'];

    /** @var string[] The titles of a sheet the ads of Meta fill, as recognise::plain() gives them. */
    const AD_TITLES = ['id', 'created time', 'ad id', 'ad name', 'adset id', 'adset name', 'campaign id', 'campaign name',
        'form id', 'form name', 'is organic', 'platform'];

    /** @var string A column that says the day of a visit, in a sheet with a column for its hour. */
    const VISIT_DAY = '/^(fecha|data|date|dia|day)( (de )?(la )?(cita|visita|visit))?$/';

    /** @var string A column a form writes by itself: the family sent that row. */
    const STAMPED = '/created time|marca temporal|timestamp|carimbo|submitted/';

    /** @var string[] What is not kept: whether a lead was organic, and what the team said to leave. */
    const DROPPED = ['organic', 'ignore'];

    /** @var array What the team wrote, as teamnotes::apply() counted it, for the last row received. */
    public static $noted = [];

    /** @var \stdClass|null What read() made of the last row received. */
    public static $lastread = null;

    /** @var bool Whether the last row received came with its titles lost: the row of titles of its sheet is not there. */
    public static $headless = false;

    /**
     * A new entry of a centre, with its public name and its key.
     *
     * @param int $centreid
     * @param string $kind
     * @param string $name
     * @return int
     */
    public static function create(int $centreid, string $kind, string $name): int {
        global $DB;
        if (!isset(self::KINDS[$kind]) && $kind !== surveys::KIND) {
            throw new \coding_exception('Unknown kind of entry: ' . $kind);
        }
        do {
            $token = strtolower(random_string(20));
        } while ($DB->record_exists(self::T, ['token' => $token]));
        $now = time();
        return (int) $DB->insert_record(self::T, (object) ['centreid' => $centreid, 'kind' => $kind,
            'name' => \core_text::substr(trim($name), 0, 255), 'token' => $token, 'secret' => bin2hex(random_bytes(24)),
            'title' => null, 'blocks' => $kind === 'form' ? implode(',', forms::BLOCKS) : null,
            // The contacts of ads and the emails to the centre are not answered by a machine.
            'acknowledge' => in_array($kind, ['meta', 'email'], true) ? 0 : 1, 'received' => 0, 'lastreceived' => 0, 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now]);
    }

    /**
     * An entry of a centre.
     *
     * @param int $id
     * @param int $centreid
     * @return \stdClass|null
     */
    public static function get(int $id, int $centreid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::T, ['id' => $id, 'centreid' => $centreid]) ?: null;
    }

    /**
     * An entry by its public name, if it is open.
     *
     * @param string $token
     * @return \stdClass|null
     */
    public static function by_token(string $token): ?\stdClass {
        global $DB;
        $token = clean_param($token, PARAM_ALPHANUM);
        $entry = $token === '' ? null : ($DB->get_record(self::T, ['token' => $token, 'active' => 1]) ?: null);
        // A survey is asked on the page of the families who came, never by a link of its own.
        return $entry && $entry->kind !== surveys::KIND ? $entry : null;
    }

    /**
     * The entries of a centre: the doors families come in by, without its surveys.
     *
     * @param int $centreid
     * @return \stdClass[]
     */
    public static function for_centre(int $centreid): array {
        global $DB;
        return $DB->get_records_select(
            self::T,
            'centreid = ? AND kind <> ?',
            [$centreid, surveys::KIND],
            'active DESC, name ASC, id ASC'
        );
    }

    /**
     * The channel an entry counts as in the numbers.
     *
     * @param \stdClass $entry
     * @return string
     */
    public static function channel(\stdClass $entry): string {
        return self::KINDS[$entry->kind] ?? 'other';
    }

    /**
     * Changes the name, the email of receipt or whether it is open.
     *
     * @param \stdClass $entry
     * @param \stdClass $data name, acknowledge, active and, if given, teamsheet.
     */
    public static function update(\stdClass $entry, \stdClass $data): void {
        global $DB;
        $record = ['id' => $entry->id,
            'name' => \core_text::substr(trim((string) ($data->name ?? $entry->name)), 0, 255) ?: $entry->name,
            'acknowledge' => empty($data->acknowledge) ? 0 : 1, 'active' => empty($data->active) ? 0 : 1,
            'timemodified' => time()];
        if (property_exists($data, 'teamsheet')) {
            $record['teamsheet'] = empty($data->teamsheet) ? 0 : 1;
        }
        $DB->update_record(self::T, (object) $record);
    }

    /**
     * A new key: what was pasted with the old one stops working.
     *
     * @param \stdClass $entry
     */
    public static function renew_secret(\stdClass $entry): void {
        global $DB;
        $entry->secret = bin2hex(random_bytes(24));
        $DB->update_record(self::T, (object) ['id' => $entry->id, 'secret' => $entry->secret, 'timemodified' => time()]);
    }

    /**
     * Whether what came is signed with the key of the entry.
     *
     * @param \stdClass $entry
     * @param string $body The body as it came.
     * @param string $signature The header, «sha256=» and the hexadecimal HMAC.
     * @return bool
     */
    public static function signed(\stdClass $entry, string $body, string $signature): bool {
        $expected = 'sha256=' . hash_hmac('sha256', $body, (string) $entry->secret);
        return $signature !== '' && hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * A mark for the page of a form, instead of a session: the web of the centre may not let the form keep one.
     *
     * @param \stdClass $entry
     * @param int|null $time
     * @return string
     */
    public static function mark(\stdClass $entry, ?int $time = null): string {
        $time = $time ?? time();
        return $time . '-' . substr(hash_hmac('sha256', 'form:' . $entry->token . ':' . $time, (string) $entry->secret), 0, 32);
    }

    /**
     * Whether a page of a form was given by this site, less than a day ago.
     *
     * @param \stdClass $entry
     * @param string $mark
     * @return bool
     */
    public static function mark_ok(\stdClass $entry, string $mark): bool {
        if (!preg_match('/^(\d{9,11})-[0-9a-f]{32}$/', $mark, $m)) {
            return false;
        }
        $time = (int) $m[1];
        return $time <= time() + 60 && $time > time() - DAYSECS && hash_equals(self::mark($entry, $time), $mark);
    }

    /**
     * What each question is: what the team said, or what its words say.
     *
     * @param \stdClass $entry
     * @param string[] $questions
     * @return array question => role, or null when it is nothing known.
     */
    public static function roles(\stdClass $entry, array $questions): array {
        $said = json_decode((string) $entry->roles, true) ?: [];
        $out = [];
        foreach ($questions as $question) {
            $key = recognise::plain((string) $question);
            $out[$question] = $said[$key] ?? recognise::header_role((string) $question);
        }
        return $out;
    }

    /**
     * Keeps what the team said a question is.
     *
     * @param \stdClass $entry
     * @param array $roles question => role ('' to go back to what its words say).
     */
    public static function set_roles(\stdClass $entry, array $roles): void {
        global $DB;
        $said = json_decode((string) $entry->roles, true) ?: [];
        foreach ($roles as $question => $role) {
            $key = recognise::plain((string) $question);
            if ($role === '' || !in_array($role, self::ROLES, true)) {
                unset($said[$key]);
            } else {
                $said[$key] = $role;
            }
        }
        $entry->roles = json_encode($said, JSON_UNESCAPED_UNICODE);
        $DB->set_field(self::T, 'roles', $entry->roles, ['id' => $entry->id]);
    }

    /**
     * The questions that have come through an entry, the newest first, at most sixty.
     *
     * @param \stdClass $entry
     * @return string[]
     */
    public static function questions(\stdClass $entry): array {
        return json_decode((string) $entry->questions, true) ?: [];
    }

    /**
     * A family that comes in through an entry: its answers, question by question, as the source gives them.
     *
     * @param \stdClass $entry
     * @param array $answers question => answer, in the order of the form.
     * @param string $externalid The identifier the source gives (a response, a lead), if any.
     * @param int|null $time When it was sent, if the source says so.
     * @param bool $past Whether it is something sent before the entry was connected.
     * @param bool $byphone Whether a phone may find a family the centre already has (not from an email anyone could write).
     * @param bool $dayonly Whether of the moment it was sent only the day is known.
     * @return array [household or null when there is nobody to answer, whether it is new, the token of its page or null]
     */
    public static function receive(
        \stdClass $entry,
        array $answers,
        string $externalid = '',
        ?int $time = null,
        bool $past = false,
        bool $byphone = true,
        bool $dayonly = false
    ): array {
        global $DB;
        self::$headless = false;
        self::$noted = [];
        $centre = centres::get((int) $entry->centreid);
        if (!$centre) {
            return [null, false, null];
        }
        [$answers, self::$headless] = self::headings($entry, $answers);
        if (!self::$headless) {
            // With its titles lost, what comes in their place may say who a family is: it is never kept as a question.
            self::note_questions($entry, array_map('strval', array_keys($answers)));
            self::note_examples($entry, $answers);
        }
        $read = self::read($entry, $centre, $answers);
        self::$lastread = $read;
        if (self::is_test($read->email) || self::is_team($centre, $read)) {
            // A test, or a person of the team trying it: the entry works, and nobody comes in.
            self::tested($entry);
            return [null, false, null];
        }
        // A day given to come is not when they first wrote: never later than now, and before the visit.
        $now = time();
        if (!$time && $read->appointment) {
            // Of a visit already past, nobody wrote when it was noted: its day is all there is.
            $dayonly = $read->appointment <= $now;
            $time = $read->appointmenthour ? min($read->appointment, $now) - MINSECS : min($read->appointment, $now);
        } else if (!$time && $read->time) {
            $dayonly = $read->dayonly;
            $time = min($read->time, $now);
        }
        $time = $time ?: $now;
        $externalid = $externalid !== '' ? $externalid : $read->externalid;
        if ($read->email === null && $read->phone === null && $read->firstname === '') {
            // Nobody to answer: nothing comes in.
            return [null, false, null];
        }
        $key = hash('sha256', $externalid !== '' ? 'id:' . $externalid :
            implode('|', ['who', $read->email, $read->phone, $read->firstname, $read->lastname, $read->child, $read->keytime]));
        // A contact of a Meta ad counts as Meta, with its platform and campaign as where it arrived from, also when it
        // comes through a sheet the ads fill; a sheet of the team says the way they came in its own column.
        $arrival = self::ad_arrival($entry, $read);
        // A contact of an ad is also known by who left it and the very moment, so that it is the same one with its
        // identifier and without it (a sheet that lost the title of that column).
        $keys = array_merge([$key], $arrival !== null ? self::ad_keys($read) : []);
        $arrived = self::arrived($entry, $read, $keys, $arrival !== null);
        // A sheet the team keeps, as a person said in the entry (never from its headings): a phone finds the family and
        // what the team writes is read. A form anyone can fill finds a family only by an email it already has, never
        // brings it a new address, and never changes what the team did.
        $trusted = !empty($entry->teamsheet);
        $byphone = $byphone && $trusted;
        if ($arrived) {
            self::keep_keys($entry, $keys, (int) $arrived->householdid);
            if (!$dayonly && $arrived->householdid) {
                self::give_hour($entry, (int) $arrived->householdid, $time);
            }
            // It came before; if the family erased its data since, it stays erased. What the team wrote since in the
            // same row (a second call, the day of the visit) comes in, once; who they are and their courses stay as the
            // team and the family left them in Eguahe.
            $household = $arrived->householdid ? households::get((int) $arrived->householdid, (int) $entry->centreid) : null;
            if ($household && $trusted && ($read->team || $read->staff || $read->appointment)) {
                $transaction = $DB->start_delegated_transaction();
                self::$noted = teamnotes::apply($household, (array) $read + ['entryid' => (int) $entry->id, 'past' => $past]);
                $transaction->allow_commit();
                $household = households::get((int) $household->id, (int) $entry->centreid);
            }
            return [$household, false, null];
        }

        // A family that erased its data does not come back with an old row (a sheet sent again, a phone corrected):
        // only what it sent after erasing comes in.
        $contacts = [];
        foreach ($read->adults ?: [(object) ['email' => $read->email, 'phone' => $read->phone]] as $adult) {
            $contacts[] = (string) ($adult->email ?? '');
            $contacts[] = (string) ($adult->phone ?? '');
        }
        if (households::erased_before((int) $centre->id, array_filter($contacts), (int) ($read->time ?: 0))) {
            return [null, false, null];
        }
        $channel = $arrival !== null ? 'meta' : ($read->channel ?? ($read->teamsheet ? 'other' : self::channel($entry)));
        $transaction = $DB->start_delegated_transaction();
        $householdid = null;
        foreach ($read->adults ?: [(object) ['email' => $read->email, 'phone' => $read->phone]] as $adult) {
            $householdid = $householdid ?? households::find((int) $centre->id, $adult->email, $byphone ? $adult->phone : null);
        }
        $about = (object) (['lang' => $centre->lang ?: 'es', 'firstchannel' => $channel,
            'heardfrom' => $read->heardfrom, 'incentre' => $read->incentre, 'arrival' => $arrival] + $read->place);
        $existed = $householdid !== null;
        if ($householdid === null) {
            $householdid = households::create((int) $centre->id, $about);
            if ($time < time()) {
                // Something sent before: the family is from when it first wrote, not from today.
                $DB->update_record(households::T, (object) ['id' => $householdid, 'timecreated' => $time, 'lastcontact' => $time]);
            }
        }
        $household = households::get($householdid, (int) $centre->id);
        households::complete($household, $about);
        $adultid = self::people($household, $read, $byphone, $existed && !$trusted);
        foreach ($adultid ? $read->consents : [] as $consent) {
            households::consent($household, $adultid, $consent, true, 'entry', $household->lang);
        }
        $said = $read->lines ? "\n\n" . implode("\n", $read->lines) : '';
        if ($trusted && $arrival === null && !preg_match(self::STAMPED, $read->datecolumn)) {
            // A row the team wrote in its sheet is not something the family said: nobody waits because of it.
            self::note_row($entry, $household, $channel, $said, $time, (string) $read->detail, $dayonly);
        } else {
            $body = trim(get_string('entry_arrived', 'local_oksigeniaeguahe', format_string($entry->name)) . $said);
            // Something from long ago is kept, but does not wait for an answer.
            $awaits = !$past || $time > time() - self::PASTDAYS * DAYSECS;
            households::received(
                $household,
                $channel,
                $body,
                $awaits,
                $time,
                (int) $entry->id,
                $read->detail,
                '',
                !$past && !$trusted,
                $dayonly
            );
        }
        if ($trusted && ($read->team || $read->staff || $read->appointment)) {
            self::$noted = teamnotes::apply($household, (array) $read + ['entryid' => (int) $entry->id, 'past' => $past]);
        }
        self::keep_keys($entry, $keys, (int) $household->id);
        $lastdayonly = $time >= (int) $entry->lastreceived ? ($dayonly ? 1 : 0) : (int) ($entry->lastdayonly ?? 0);
        $entry->lastreceived = max($time, (int) $entry->lastreceived);
        $entry->lastdayonly = $lastdayonly;
        $DB->execute(
            'UPDATE {' . self::T . '} SET received = received + 1, lastreceived = :t, lastdayonly = :d WHERE id = :id',
            ['t' => $entry->lastreceived, 'd' => $lastdayonly, 'id' => $entry->id]
        );
        $token = households::new_link($household, 'entry');
        $transaction->allow_commit();
        return [households::get($householdid, (int) $centre->id), true, $token];
    }

    /**
     * What a contact of an ad is known by besides its identifier: who left it (its email and its phone) and the very
     * moment, when it has one.
     *
     * @param \stdClass $read What read() made of the row.
     * @return string[]
     */
    protected static function ad_keys(\stdClass $read): array {
        if (!$read->time || $read->dayonly) {
            return [];
        }
        $out = [];
        foreach ($read->adults ?: [(object) ['email' => $read->email, 'phone' => $read->phone]] as $adult) {
            foreach (array_filter([(string) $adult->email, (string) $adult->phone]) as $who) {
                $out[] = hash('sha256', 'ad:' . $who . '|' . $read->time);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Whether a row came before, by any of what it is known by; a contact of an ad, also by the line it left in the
     * history of its family at that very moment.
     *
     * @param \stdClass $entry
     * @param \stdClass $read
     * @param string[] $keys
     * @param bool $ad Whether it is a contact of an ad.
     * @return \stdClass|null With its householdid.
     */
    protected static function arrived(\stdClass $entry, \stdClass $read, array $keys, bool $ad): ?\stdClass {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            self::ARRIVALS,
            "entryid = :entry AND externalid $in",
            $params + ['entry' => $entry->id],
            'householdid DESC, id ASC',
            'id, externalid, householdid'
        );
        // By its own key first, whatever else is known of it.
        foreach ($rows as $row) {
            if ($row->externalid === $keys[0]) {
                return $row;
            }
        }
        if ($rows) {
            return reset($rows);
        }
        if (!$ad || !$read->time || $read->dayonly) {
            return null;
        }
        foreach ($read->adults ?: [(object) ['email' => $read->email, 'phone' => $read->phone]] as $adult) {
            foreach (array_filter(['email' => (string) $adult->email, 'phone' => (string) $adult->phone]) as $field => $who) {
                $ids = $DB->get_fieldset_select(
                    households::ADULTS,
                    'DISTINCT householdid',
                    "centreid = ? AND $field = ?",
                    [$entry->centreid, $who]
                );
                foreach ($ids as $id) {
                    $was = $DB->record_exists(households::CONTACTS, ['householdid' => $id, 'entryid' => $entry->id,
                        'direction' => 'in', 'timecreated' => $read->time]);
                    if ($was) {
                        return (object) ['householdid' => (int) $id];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Keeps what a row is known by, so that it never comes in twice.
     *
     * @param \stdClass $entry
     * @param string[] $keys
     * @param int $householdid
     */
    protected static function keep_keys(\stdClass $entry, array $keys, int $householdid): void {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $have = $DB->get_fieldset_select(
            self::ARRIVALS,
            'externalid',
            "entryid = :entry AND externalid $in",
            $params + ['entry' => $entry->id]
        );
        foreach (array_diff(array_unique($keys), $have) as $key) {
            $DB->insert_record(self::ARRIVALS, (object) ['centreid' => $entry->centreid, 'entryid' => $entry->id,
                'externalid' => $key, 'householdid' => $householdid, 'timecreated' => time()]);
        }
    }

    /**
     * A row that came in with only its day, and that says its hour now: its line takes it, and so does since when the
     * family waits.
     *
     * @param \stdClass $entry
     * @param int $householdid
     * @param int $time
     */
    protected static function give_hour(\stdClass $entry, int $householdid, int $time): void {
        global $DB;
        $lines = $DB->get_records(households::CONTACTS, ['householdid' => $householdid, 'entryid' => $entry->id,
            'direction' => 'in'], 'id ASC', 'id, dayonly, timecreated');
        if (array_filter($lines, fn($line) => !$line->dayonly && (int) $line->timecreated === $time)) {
            return;
        }
        $lines = array_filter($lines, fn($line) => (bool) $line->dayonly);
        $zone = \core_date::get_server_timezone_object();
        $day = fn(int $t) => (new \DateTime('@' . $t))->setTimezone($zone)->format('Ymd');
        foreach ($lines as $line) {
            if ($day((int) $line->timecreated) !== $day($time)) {
                continue;
            }
            $DB->update_record(households::CONTACTS, (object) ['id' => $line->id, 'timecreated' => $time, 'dayonly' => 0]);
            $DB->execute(
                'UPDATE {' . households::T . '} SET waitingsince = :t, waitingdayonly = 0
                  WHERE id = :id AND waitingsince = :was AND waitingdayonly = 1',
                ['t' => $time, 'id' => $householdid, 'was' => $line->timecreated]
            );
            return;
        }
    }

    /**
     * A row of a sheet the team keeps, as a note in the history of its family; the same words never twice, though their
     * day changed.
     *
     * @param \stdClass $entry
     * @param \stdClass $household
     * @param string $channel
     * @param string $said The columns of the row that are nothing else.
     * @param int $time
     * @param string $detail
     * @param bool $dayonly
     */
    protected static function note_row(
        \stdClass $entry,
        \stdClass $household,
        string $channel,
        string $said,
        int $time,
        string $detail,
        bool $dayonly
    ): void {
        global $DB;
        $name = format_string($entry->name);
        $body = trim(get_string('entry_noted', 'local_oksigeniaeguahe', $name) . $said);
        $was = trim(get_string('entry_arrived', 'local_oksigeniaeguahe', $name) . $said);
        $lines = $DB->get_fieldset_select(
            households::CONTACTS,
            'body',
            'householdid = ? AND entryid = ?',
            [$household->id, $entry->id]
        );
        if (!array_intersect([$body, $was], array_map(fn($line) => trim((string) $line), $lines))) {
            households::noted($household, $channel, $body, $time, (int) $entry->id, $detail, $dayonly);
        }
    }

    /**
     * The adults and the children of a row, in a household: the ones it has are kept, and a child without a name
     * becomes the one the row names.
     *
     * @param \stdClass $household
     * @param \stdClass $read What read() made of the row.
     * @param bool $byphone
     * @param bool $guarded A form anyone can fill that matched a family the centre already had: no new email or phone
     *     gets into it (they wait in a note for the team), and its children keep what they had.
     * @return int The first adult; 0 when none of the row could be kept.
     */
    protected static function people(\stdClass $household, \stdClass $read, bool $byphone, bool $guarded = false): int {
        $first = 0;
        $full = $guarded ? households::full($household) : null;
        $known = fn(string $field) => $full ? array_filter(array_map(
            fn($a) => \core_text::strtolower((string) $a->$field),
            $full->adults
        )) : [];
        $emails = $known('email');
        $phones = $known('phone');
        $held = [];
        foreach (
            $read->adults ?: [(object) ['firstname' => $read->firstname, 'lastname' => $read->lastname,
                'email' => $read->email, 'phone' => $read->phone]] as $adult
        ) {
            if ($adult->firstname === '' && $adult->email === null && $adult->phone === null) {
                continue;
            }
            if ($guarded) {
                $adult = clone $adult;
                $new = [];
                if ($adult->email !== null && !in_array(\core_text::strtolower((string) $adult->email), $emails, true)) {
                    $new[] = $adult->email;
                    $adult->email = null;
                }
                if ($adult->phone !== null && !in_array(\core_text::strtolower((string) $adult->phone), $phones, true)) {
                    $new[] = phone::display((string) $adult->phone);
                    $adult->phone = null;
                }
                if ($new) {
                    $held[] = trim($adult->firstname . ' ' . ($adult->lastname ?? '')) . ' <' . implode(', ', $new) . '>';
                }
                if ($adult->email === null && $adult->phone === null) {
                    continue;
                }
            }
            $id = households::add_adult($household, $adult);
            $first = $first ?: $id;
        }
        if ($held) {
            households::note($household, 0, get_string('signup_unverified_email', 'local_oksigeniaeguahe', implode('; ', $held)));
        }
        foreach (
            $read->children ?: ($read->child !== '' || $read->offerid ? [[$read->child, $read->offerid]] : []) as [$name, $offerid]
        ) {
            households::add_child($household, $name, (int) $offerid, '', $guarded);
        }
        if ($read->incentre && !$household->incentre) {
            households::complete($household, (object) ['incentre' => 1]);
        }
        if (!empty($read->alumni)) {
            // 1, the family said it in its form; 2, the team wrote it in its sheet.
            alumni::set($household, 0, alumni::YES, (int) $read->alumni === 2 ? 'sheet' : 'family', 0, '', false);
        }
        return $first;
    }

    /**
     * Whether an email is the one of a test.
     *
     * @param string|null $email
     * @return bool
     */
    public static function is_test(?string $email): bool {
        return \core_text::strtolower(trim((string) $email)) === self::TESTMAIL;
    }

    /**
     * Whether whoever writes is a person of the team of the centre, trying a form or a sheet: by the email of their
     * account or the phones of their profile.
     *
     * @param \stdClass $centre
     * @param \stdClass $read What read() made of the answers.
     * @return bool
     */
    public static function is_team(\stdClass $centre, \stdClass $read): bool {
        global $DB;
        static $known = [];
        $id = (int) $centre->id;
        if (!isset($known[$id]) || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $known[$id] = [];
            $members = centres::members($id);
            if ($members) {
                [$in, $params] = $DB->get_in_or_equal(array_keys($members));
                foreach ($DB->get_records_select('user', "id $in", $params, '', 'id, email, phone1, phone2') as $user) {
                    foreach (
                        [households::email($user->email), phone::normalise((string) $user->phone1, $centre->country ?? null),
                            phone::normalise((string) $user->phone2, $centre->country ?? null)] as $key
                    ) {
                        if ($key) {
                            $known[$id][$key] = true;
                        }
                    }
                }
            }
        }
        foreach ($read->adults ?: [(object) ['email' => $read->email, 'phone' => $read->phone]] as $adult) {
            if (($adult->email && isset($known[$id][$adult->email])) || ($adult->phone && isset($known[$id][$adult->phone]))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A test came through an entry: it works. No family is kept and nothing is counted.
     *
     * @param \stdClass $entry
     */
    public static function tested(\stdClass $entry): void {
        global $DB;
        $entry->testedat = time();
        $DB->set_field(self::T, 'testedat', $entry->testedat, ['id' => $entry->id]);
    }

    /**
     * A family that signed up on the form of an entry, already kept: it is counted.
     *
     * @param \stdClass $entry
     */
    public static function counted(\stdClass $entry): void {
        global $DB;
        $DB->execute(
            'UPDATE {' . self::T . '} SET received = received + 1, lastreceived = :t, opened = 0 WHERE id = :id',
            ['t' => time(), 'id' => $entry->id]
        );
    }

    /**
     * Reads the answers into a family: who writes, how to answer them, the children, what it said and where it came
     * from. A sheet the team keeps (with a column of who attended them or what was done) gives also what the team wrote:
     * its observations are the team's, never the family's words.
     *
     * @param \stdClass $entry
     * @param \stdClass $centre
     * @param array $answers
     * @return \stdClass
     */
    public static function read(\stdClass $entry, \stdClass $centre, array $answers): \stdClass {
        $roles = self::roles($entry, array_map('strval', array_keys($answers)));
        $r = (object) ['firstname' => '', 'lastname' => '', 'fullname' => '', 'email' => null, 'phone' => null,
            'child' => '', 'grade' => '', 'offerid' => 0, 'lines' => [], 'detail' => '', 'consents' => [],
            'incentre' => 0, 'alumni' => 0, 'heardfrom' => null, 'place' => [], 'time' => 0, 'externalid' => '', 'ads' => [],
            'adults' => [], 'children' => [], 'team' => [], 'staff' => [], 'hour' => '', 'channel' => null,
            'appointment' => 0, 'appointmenthour' => false, 'monthfirst' => false, 'teamsheet' => false, 'people' => 0,
            'otherschool' => '', 'dayonly' => false, 'keytime' => 0, 'datecolumn' => ''];
        $detail = [];
        $people = [];
        $grades = [];
        $names = [];
        $schoolyear = '';
        $yes = fn(string $v) => (bool) preg_match('/^(s[ií]|yes|sim|true|1|x|acepto|de acuerdo|ok|agree|concordo|✓)/iu', $v);
        // A sheet the team keeps: what it wrote is the team's.
        $teamsheet = (bool) array_intersect(['team', 'staff'], array_values(array_filter($roles)));
        $r->teamsheet = $teamsheet;
        $clean = [];
        foreach ($answers as $question => $value) {
            $value = trim(preg_replace('/\s+/u', ' ', clean_param(is_array($value) ? implode(', ', $value) :
                (string) $value, PARAM_TEXT)));
            if ($value !== '') {
                $clean[$question] = $value;
            }
        }
        $dates = array_filter(array_map(
            fn($q) => ($roles[$q] ?? null) === 'date' ? $clean[$q] : null,
            array_map('strval', array_keys($clean))
        ));
        $r->monthfirst = recognise::monthfirst(
            array_values($dates),
            (string) ($centre->country ?? ''),
            (string) ($centre->lang ?? 'en')
        );
        foreach ($clean as $question => $value) {
            $role = $roles[$question] ?? null;
            $line = \core_text::substr(trim((string) $question), 0, 120) . ': ' . \core_text::substr($value, 0, 1000);
            // The second tutor, the second phone: the number in the name of the column says whose it is; whoever comes
            // with them is the second adult.
            $plainq = recognise::plain((string) $question);
            $who = preg_match('/(\d)\D*$/', $plainq, $m) ? max(1, (int) $m[1]) :
                (preg_match('/acompan|accompan|companion/', $plainq) ? 2 : 1);
            switch ($role) {
                case 'email':
                    $email = households::email($value);
                    if ($email && !isset($people[$who]['email'])) {
                        $people[$who]['email'] = $email;
                    } else if (!$email || $people[$who]['email'] !== $email) {
                        // Not an email, or a second one for the same person: kept as it was written, never lost.
                        $r->lines[] = $line;
                    }
                    break;
                case 'phone':
                    $phone = phone::normalise(preg_replace('/^p:/i', '', $value), $centre->country ?? null);
                    if ($phone && !preg_match('/\p{L}{3,}/u', $value) && !isset($people[$who]['phone'])) {
                        $people[$who]['phone'] = $phone;
                    } else if ($phone && !preg_match('/\p{L}{3,}/u', $value)) {
                        if ($people[$who]['phone'] !== $phone) {
                            $r->lines[] = $line;
                        }
                    } else if (preg_match('/\p{L}/u', $value) && ($teamsheet || preg_match('/whats|wasap/i', $question))) {
                        // «WhatsApp: Enviado» is something the team did, not a number.
                        $r->team[] = [(string) $question, $value, 'team'];
                    } else {
                        $r->lines[] = $line;
                    }
                    break;
                case 'firstname':
                    $people[$who]['firstname'] = $people[$who]['firstname'] ?? \core_text::substr($value, 0, 100);
                    break;
                case 'surname':
                    $people[$who]['lastname'] = $people[$who]['lastname'] ?? \core_text::substr($value, 0, 100);
                    break;
                case 'fullname':
                case 'guardianname':
                    if ($role === 'guardianname' && count(preg_split('/\s+/u', $value)) < 2) {
                        // Who writes («Madre», «Padre»), not a name.
                        $r->lines[] = $line;
                    } else {
                        $people[$who]['fullname'] = $people[$who]['fullname'] ?? $value;
                    }
                    break;
                case 'childname':
                    foreach (preg_split('/\s*(?:,|;|\s+y\s+|\s+e\s+|\s+and\s+)\s*/u', $value) as $name) {
                        if (trim($name) !== '') {
                            $names[] = trim($name);
                        }
                    }
                    break;
                case 'grade':
                    $year = recognise::schoolyear($value) ?? (preg_match('/^\d{4}\s*[-\/]\s*\d{2,4}$/', $value) ?
                        $value : null);
                    if ($year !== null) {
                        $schoolyear = $schoolyear ?: (string) $year;
                        break;
                    }
                    foreach (preg_split('/\s*[,;]\s*/u', $value) as $grade) {
                        if (trim($grade) !== '') {
                            $grades[] = [trim($grade), $line];
                        }
                    }
                    break;
                case 'notes':
                    if ($teamsheet) {
                        $r->team[] = [(string) $question, $value, 'notes'];
                    } else {
                        $r->lines[] = $value;
                    }
                    break;
                case 'team':
                    $r->team[] = [(string) $question, $value, 'team'];
                    break;
                case 'staff':
                    foreach (preg_split('/\s*(?:,|;|\/|\s+y\s+)\s*/u', $value) as $name) {
                        if (trim($name) !== '' && !in_array(trim($name), $r->staff, true)) {
                            $r->staff[] = trim($name);
                        }
                    }
                    break;
                case 'hour':
                    $r->hour = $r->hour ?: $value;
                    break;
                case 'otherschool':
                    // The school or nursery they come from: kept in their words, and the name of who recommended them
                    // when the centre says so.
                    $r->otherschool = $r->otherschool ?: \core_text::substr($value, 0, 60);
                    $r->lines[] = $line;
                    break;
                case 'people':
                    // How many will come to the act, for its sign-up.
                    if (preg_match('/\d+/', $value, $m)) {
                        $r->people = max(1, min(10, (int) $m[0]));
                    } else {
                        $r->lines[] = $line;
                    }
                    break;
                case 'channel':
                    $channel = teamnotes::channel($value);
                    $heard = teamnotes::heard($value);
                    $r->channel = $r->channel ?? $channel;
                    $r->heardfrom = $r->heardfrom ?? $heard;
                    $r->team[] = [(string) $question, $value, $channel || $heard ? 'channelknown' : 'channel'];
                    break;
                case 'municipality':
                    $r->place = self::place($centre, $value);
                    break;
                case 'declared':
                    $r->heardfrom = self::heard($value);
                    if ($r->heardfrom === 'other') {
                        $r->lines[] = $line;
                    }
                    break;
                case 'consent':
                    if ($yes($value)) {
                        $plain = recognise::plain((string) $question);
                        $channels = ['whatsapp' => 'whatsapp', 'email' => 'e ?mail|correo|email',
                            'phone' => 'llam|telef|phone|lig'];
                        foreach ($channels as $c => $re) {
                            if (preg_match('/' . $re . '/', $plain)) {
                                $r->consents[] = $c;
                            }
                        }
                    }
                    break;
                case 'incentre':
                    $r->incentre = $yes($value) ? 1 : 0;
                    break;
                case 'alumniflag':
                    // Whether someone of the family studied at the centre, as the form or the sheet asks it.
                    $r->alumni = $yes($value) ? ($teamsheet ? 2 : 1) : 0;
                    $r->lines[] = $line;
                    break;
                case 'date':
                    // «6/07/2026» is the 6th of July where days go first: never read the American way by mistake. The
                    // export of the ads of Meta writes its own column the American way in any country («11/6/26»).
                    if ($r->time) {
                        break;
                    }
                    $first = $r->monthfirst || recognise::plain((string) $question) === 'created time';
                    // What a row is known by is its day, as it always was: its hour never makes it another row.
                    $r->keytime = (int) (preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}#', $value) ?
                        recognise::date($value, $first) : (strtotime($value) ?: recognise::date($value)));
                    if ($r->keytime && ($moment = recognise::moment($value, $first))) {
                        [$r->time, $r->dayonly] = $moment;
                        $r->datecolumn = recognise::plain((string) $question);
                    }
                    break;
                case 'platform':
                    // Meta writes its short names in its exports; a connector may write the whole ones.
                    $detail['platform'] = ['ig' => 'Instagram', 'fb' => 'Facebook', 'an' => 'Audience Network',
                        'msg' => 'Messenger', 'wa' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook',
                        'audience network' => 'Audience Network', 'audience_network' => 'Audience Network',
                        'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp'][strtolower($value)] ?? $value;
                    break;
                case 'campaign':
                case 'adset':
                case 'ad':
                case 'form':
                    $detail[$role] = $value;
                    break;
                case 'ids':
                    // The identifier of the contact itself, never that of its ad, set, campaign or form: without the
                    // column of its own, every contact of the same ad would be taken for the same one.
                    $plain = recognise::plain((string) $question);
                    if (in_array($plain, ['ad id', 'adset id', 'campaign id', 'form id'], true)) {
                        break;
                    }
                    if ($r->externalid === '' || in_array($plain, ['id', 'lead id'], true)) {
                        $r->externalid = $value;
                    }
                    break;
                default:
                    if ($role !== null && in_array($role, self::DROPPED, true)) {
                        break;
                    }
                    $r->lines[] = $line;
            }
        }
        // The adults, the first one first.
        ksort($people);
        foreach ($people as $person) {
            $first = (string) ($person['firstname'] ?? '');
            $last = (string) ($person['lastname'] ?? '');
            if ($first === '' && !empty($person['fullname'])) {
                $words = preg_split('/\s+/u', $person['fullname']);
                $first = \core_text::substr(array_shift($words), 0, 100);
                $last = $last !== '' ? $last : \core_text::substr(implode(' ', $words), 0, 100);
            }
            if ($first === '' && empty($person['email']) && empty($person['phone'])) {
                continue;
            }
            $r->adults[] = (object) ['firstname' => $first, 'lastname' => $last, 'email' => $person['email'] ?? null,
                'phone' => $person['phone'] ?? null];
        }
        if ($r->adults) {
            $one = $r->adults[0];
            [$r->firstname, $r->lastname, $r->email, $r->phone] = [$one->firstname, $one->lastname, $one->email, $one->phone];
            $r->fullname = trim($one->firstname . ' ' . $one->lastname);
        }
        // The children: each course asked, with the name written in the same place, in order.
        $years = array_values(array_unique(array_map(fn($o) => (string) $o->schoolyear, centres::offers(
            (int) $centre->id,
            true
        ))));
        $year = $schoolyear !== '' ? (recognise::schoolyear($schoolyear) ?? $schoolyear) :
            panel_inbox::campaign_school_year($centre, $years, time());
        foreach ($grades as $i => [$grade, $line]) {
            $offerid = self::offer((int) $centre->id, $grade, $year);
            if (!$offerid) {
                $r->lines[] = $line . ($schoolyear !== '' ? ' (' . $schoolyear . ')' : '');
            }
            $r->children[] = [self::child_name($names[$i] ?? '', $r->lastname), $offerid];
        }
        foreach (array_slice($names, count($grades)) as $name) {
            $r->children[] = [self::child_name($name, $r->lastname), 0];
        }
        $r->children = array_values(array_filter($r->children, fn($c) => $c[0] !== '' || $c[1]));
        if ($r->children) {
            [$r->child, $r->offerid] = $r->children[0];
        }
        $r->detail = implode(' · ', array_filter([$detail['platform'] ?? '', $detail['campaign'] ?? '',
            $detail['adset'] ?? '', $detail['ad'] ?? '', $detail['form'] ?? '']));
        $r->ads = $detail;
        $r->consents = array_values(array_unique($r->consents));
        // A day with its hour, in a sheet the team keeps, is the visit they were given; and the day alone too, where the
        // sheet has a column for the hour that this row leaves empty (a script never sends an empty cell).
        if ($r->hour !== '' && $r->time) {
            $r->appointment = teamnotes::at($r->time, $r->hour);
            $r->appointmenthour = teamnotes::has_hour($r->hour) || !$r->dayonly;
        } else if (
            $r->time && !empty($entry->teamsheet) && preg_match(self::VISIT_DAY, $r->datecolumn) &&
                self::ad_arrival($entry, $r) === null &&
                in_array('hour', self::roles($entry, self::questions($entry)), true)
        ) {
            $r->appointment = $r->time;
            $r->appointmenthour = !$r->dayonly;
        }
        return $r;
    }

    /**
     * Whether the title of a column is not one but a value (an email, a phone, a moment, an identifier of Meta): the row
     * of titles of its sheet was lost, and the first row with data took its place.
     *
     * @param string $title
     * @return bool
     */
    public static function is_value(string $title): bool {
        $title = trim($title);
        return $title !== '' && (validate_email($title) ||
            preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{1,2}:\d{2}/', $title) ||
            preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4},? \d{1,2}:\d{2}#', $title) ||
            preg_match('/^[a-z]{1,2}:\+?\d{6,}$/i', $title) ||
            preg_match('/^\+\d[\d\s().-]{7,}$/', $title) || preg_match('/^\d{9,16}$/', $title));
    }

    /**
     * What can be read of a row whose sheet lost, moved or repeated its row of titles, and whether it did: a title that is
     * a value; the titles of the ads written twice («created_time (2)»); or a contact of the ads without its «id», which
     * this entry always had. What cannot be told is left out, and never kept as a question of the entry.
     *
     * @param \stdClass $entry
     * @param array $answers
     * @return array [answers, whether the titles are lost].
     */
    public static function headings(\stdClass $entry, array $answers): array {
        $titles = array_map('strval', array_keys($answers));
        $known = array_map([recognise::class, 'plain'], self::questions($entry));
        $lost = false;
        if (array_filter($titles, [self::class, 'is_value'])) {
            $lost = true;
            $roles = self::roles($entry, $titles);
            foreach ($titles as $title) {
                if (self::is_value($title) || (!$roles[$title] && !in_array(recognise::plain($title), $known, true))) {
                    unset($answers[$title]);
                }
            }
        }
        // The titles of the ads twice: their second set says the same as the first one, where the first one is empty.
        $twice = [];
        foreach (array_map('strval', array_keys($answers)) as $title) {
            if (preg_match('/^(.+) \(\d+\)$/u', trim($title), $m)) {
                $twice[$title] = $m[1];
            }
        }
        if (array_filter($twice, fn($base) => in_array(recognise::plain($base), self::AD_TITLES, true))) {
            $lost = true;
            foreach ($twice as $title => $base) {
                if (!isset($answers[$base])) {
                    $answers[$base] = $answers[$title];
                } else if ($answers[$base] !== $answers[$title] && !in_array(recognise::plain($base), self::AD_TITLES, true)) {
                    // Another value under the repeated title is of someone else: it stays as it came.
                    continue;
                }
                unset($answers[$title]);
            }
        }
        $plain = array_map([recognise::class, 'plain'], array_map('strval', array_keys($answers)));
        if (
            count(array_intersect(array_diff(self::AD_TITLES, ['id']), $plain)) >= 3 &&
                !array_intersect(['id', 'lead id'], $plain) && array_intersect(['id', 'lead id'], $known)
        ) {
            $lost = true;
        }
        return [$answers, $lost];
    }

    /**
     * The questions of an entry without what is not one: when its sheet lost its row of titles, the values of a contact
     * were taken for them, which say who a family is and how to reach it.
     *
     * @param string[] $questions In the order they came.
     * @param array $examples
     * @return array [questions, examples].
     */
    public static function clean_questions(array $questions, array $examples): array {
        $at = array_keys(array_filter($questions, [self::class, 'is_value']));
        if ($at) {
            // The whole row came in at once: from its first value to its last one, and what is around them and is no
            // title either.
            $odd = fn(int $i) => isset($questions[$i]) && recognise::header_role((string) $questions[$i]) === null;
            $from = min($at);
            $to = max($at);
            while ($odd($from - 1)) {
                $from--;
            }
            while ($odd($to + 1)) {
                $to++;
            }
            array_splice($questions, $from, $to - $from + 1);
        }
        $person = fn(string $value) => validate_email($value) || preg_match('/^p?:?\+?\d[\d\s().-]{7,}$/i', $value);
        $examples = array_filter(
            array_intersect_key($examples, array_flip($questions)),
            fn($value) => !$person(trim((string) $value))
        );
        return [$questions, $examples];
    }

    /**
     * Where a contact of a Meta ad arrived from, measured by Meta itself: its platform as the source and its campaign,
     * whether it comes in the export of the ads or through a sheet they fill. Nothing for anything else.
     *
     * @param \stdClass $entry
     * @param \stdClass $read What read() made of the answers.
     * @return array|null src, med, cmp and ref, as arrival::read() gives them; null when it is not a contact of an ad.
     */
    public static function ad_arrival(\stdClass $entry, \stdClass $read): ?array {
        $platform = (string) ($read->ads['platform'] ?? '');
        if (!in_array($platform, self::META_PLATFORMS, true) && $entry->kind !== 'meta') {
            return null;
        }
        return ['src' => $platform !== '' ? $platform : 'meta', 'med' => 'paid',
            'cmp' => (string) ($read->ads['campaign'] ?? ''), 'ref' => ''];
    }

    /**
     * The first name of a child, without the surname of the family: only what is needed of a minor.
     *
     * @param string $name
     * @param string $lastname
     * @return string
     */
    public static function child_name(string $name, string $lastname): string {
        $surnames = array_filter(array_map([recognise::class, 'plain'], preg_split('/\s+/u', $lastname)));
        $words = array_values(array_filter(preg_split('/\s+/u', trim($name)), fn($w) =>
            $w !== '' && !in_array(recognise::plain($w), $surnames, true)));
        // Two words at most: «María José», not the surnames.
        return \core_text::substr(implode(' ', array_slice($words, 0, count($words) > 2 ? 1 : 2)), 0, 100);
    }

    /**
     * The course of the offer an answer speaks of: the same name, else the one of the same age in the latest school
     * year («1º Primaria» is «1.º de Primaria»), else the one whose name it contains.
     *
     * @param int $centreid
     * @param string $value
     * @param string $schoolyear Only in this school year, when the answer or the campaign says it.
     * @return int 0 if none is clear.
     */
    public static function offer(int $centreid, string $value, string $schoolyear = ''): int {
        $want = grades::plain($value);
        // «infantil_3-6» is a whole stage, not a course: nothing to choose.
        if (preg_match('/(?<!\d)(\d{1,2})\s*(?:-|–|a|to)\s*(\d{1,2})(?!\d)/u', $value, $m) && (int) $m[2] > (int) $m[1] + 1) {
            return 0;
        }
        $centre = centres::get($centreid);
        $age = grades::age($value, $centre);
        $found = [];
        $sameage = [];
        // Latest school year first.
        foreach (centres::offers($centreid, true) as $offer) {
            if ($schoolyear !== '' && (string) $offer->schoolyear !== $schoolyear) {
                continue;
            }
            $name = grades::plain((string) $offer->name);
            if ($name === $want) {
                return (int) $offer->id;
            }
            if ($age !== null && grades::age((string) $offer->name, $centre) === $age) {
                $sameage[(string) $offer->schoolyear][] = (int) $offer->id;
            }
            if ($name !== '' && (strpos($want, $name) !== false || strpos($name, $want) !== false)) {
                $found[] = (int) $offer->id;
            }
        }
        $latest = reset($sameage);
        if ($latest && count($latest) === 1) {
            return $latest[0];
        }
        return count($found) === 1 ? $found[0] : 0;
    }

    /**
     * Where the family lives: a municipality of the centre's list by its name, or as it wrote it.
     *
     * @param \stdClass $centre
     * @param string $value
     * @return array municipality and municipalityname.
     */
    public static function place(\stdClass $centre, string $value): array {
        foreach (centres::municipalities($centre) as $code => $name) {
            if (recognise::plain($name) === recognise::plain($value) && strpos((string) $code, 'name:') !== 0) {
                return ['municipality' => $code, 'municipalityname' => $name];
            }
        }
        return ['municipality' => null, 'municipalityname' => \core_text::substr($value, 0, 255)];
    }

    /**
     * How they heard of the centre, from what they wrote.
     *
     * @param string $value
     * @return string One of lists::HEARD_FROM.
     */
    public static function heard(string $value): string {
        $plain = recognise::plain($value);
        $words = [
            'centremessage' => '(mensaje|circular|comunicado|correo|aviso) del (centro|cole|colegio|escuela)',
            'centrefamilies' => 'familias? del (centro|cole)|ya (somos|estamos)|hermanos? en el',
            'social' => 'redes|instagram|facebook|tiktok|social|youtube',
            'search' => 'google|internet|busca|search|web|pagina',
            'family' => 'amig|famil|recomend|conocid|boca|friend|vecin',
            'ad' => 'anuncio|publicidad|\bads?\b|cartel|folleto|valla',
            'press' => 'prensa|radio|periodico|revista|television|\btv\b|press|news',
            'passingby' => 'pasando|pase|cerca|barrio|calle',
            'fair' => 'feria|fair|jornada|salon',
        ];
        foreach ($words as $key => $re) {
            if (preg_match('/' . $re . '/', $plain)) {
                return $key;
            }
        }
        return 'other';
    }

    /**
     * Notes the questions that came, so the team sees them and can say what each is.
     *
     * @param \stdClass $entry
     * @param string[] $questions
     */
    protected static function note_questions(\stdClass $entry, array $questions): void {
        global $DB;
        $seen = self::questions($entry);
        $new = array_values(array_diff(array_map(fn($q) => \core_text::substr(trim($q), 0, 255), $questions), $seen));
        if (!$new) {
            return;
        }
        $entry->questions = json_encode(array_slice(array_merge($seen, $new), 0, 60), JSON_UNESCAPED_UNICODE);
        $DB->set_field(self::T, 'questions', $entry->questions, ['id' => $entry->id]);
    }

    /**
     * Keeps the last answer to each question, short, for the team to see what each brings; never one that says who
     * someone is or how to reach them.
     *
     * @param \stdClass $entry
     * @param array $answers
     */
    protected static function note_examples(\stdClass $entry, array $answers): void {
        global $DB;
        $roles = self::roles($entry, array_map('strval', array_keys($answers)));
        $examples = self::examples($entry);
        foreach ($answers as $question => $value) {
            $value = trim(preg_replace('/\s+/u', ' ', clean_param(
                is_array($value) ? implode(', ', $value) : (string) $value,
                PARAM_TEXT
            )));
            $key = \core_text::substr(trim((string) $question), 0, 255);
            if (
                $value === '' || in_array($roles[$question] ?? '', self::PERSONAL, true) || !in_array(
                    $key,
                    self::questions($entry),
                    true
                )
            ) {
                continue;
            }
            $examples[$key] = \core_text::substr($value, 0, 80);
        }
        $entry->examples = json_encode($examples, JSON_UNESCAPED_UNICODE);
        $DB->set_field(self::T, 'examples', $entry->examples, ['id' => $entry->id]);
    }

    /**
     * The last answer kept for each question of a bridge.
     *
     * @param \stdClass $entry
     * @return array question => answer
     */
    public static function examples(\stdClass $entry): array {
        return json_decode((string) ($entry->examples ?? ''), true) ?: [];
    }

    /**
     * The address that receives what is signed with the key of the entry.
     *
     * @param \stdClass $entry
     * @return \moodle_url
     */
    public static function hook_url(\stdClass $entry): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/hook.php', ['e' => $entry->token]);
    }

    /**
     * The form of an entry: its own page, or inside the web of the centre.
     *
     * @param \stdClass $entry
     * @param bool $embedded
     * @return \moodle_url
     */
    public static function form_url(\stdClass $entry, bool $embedded = false): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/w.php', ['e' => $entry->token] + ($embedded ? ['embed' => 1] : []));
    }

    /**
     * The link of a form for one place it is shared from, with its utm (so where a family came from is measured) and,
     * where the site has a shortener, short and with the QR it draws, asked once and kept. A place without its own key
     * is the general link. Without a shortener, the long address and a QR drawn here.
     *
     * @param \stdClass $entry
     * @param string $key The key of the place, or '' for the general link.
     * @param bool $ask Whether a short link not made yet may be asked for now.
     * @return array url (the link to share), long (the address behind it), opened (how many times its short link was
     *     opened, or null) and qr (HTML: a picture of the QR).
     */
    public static function site_link(\stdClass $entry, string $key, bool $ask = true): array {
        global $DB;
        $long = (new \moodle_url(self::form_url($entry), ($key !== '' ? ['d' => $key] : []) + [
            'utm_source' => $key !== '' ? $key : 'enlace', 'utm_medium' => 'formulario',
            'utm_campaign' => 'formulario-' . (int) $entry->id]))->out(false);
        $kept = json_decode((string) ($entry->shortlinks ?? ''), true) ?: [];
        $index = $key !== '' ? $key : '_';
        if (!isset($kept[$index]) && $ask && !centres::is_practice((int) $entry->centreid)) {
            $shortener = shortener::of_site();
            $centre = centres::get((int) $entry->centreid);
            $link = $shortener ? events::shorten($shortener, $long, get_string(
                'short_title',
                'local_oksigeniaeguahe',
                (object) ['centre' => $centre ? format_string($centre->name) : '', 'event' => format_string($entry->name) .
                ($key !== '' ? ' · ' . format_string((string) (forms::origins($entry)[$key] ?? $key)) : '')]
            )) : null;
            if ($link) {
                $kept[$index] = ['short' => \core_text::substr($link['short'], 0, 255),
                    'qr' => events::fetch_qr((string) ($link['qrsvg'] ?? ''))];
                $entry->shortlinks = json_encode($kept);
                $DB->set_field(self::T, 'shortlinks', $entry->shortlinks, ['id' => $entry->id]);
            }
        }
        $url = $kept[$index]['short'] ?? $long;
        $svg = (string) ($kept[$index]['qr'] ?? '');
        // How many times its short link was opened, asked at most once a day.
        $opened = null;
        if (isset($kept[$index]['short'])) {
            $before = $kept[$index]['clicks'] ?? null;
            $opened = events::clicks(is_array($before) ? $before : null, $kept[$index]['short'], $ask);
            if ($opened && $opened !== $before) {
                $kept[$index]['clicks'] = $opened;
                $entry->shortlinks = json_encode($kept);
                $DB->set_field(self::T, 'shortlinks', $entry->shortlinks, ['id' => $entry->id]);
            }
        }
        return [
            'url' => $url,
            'long' => $long,
            'opened' => $opened ? (int) $opened['clicks'] : null,
            // A picture: nothing in it runs.
            'qr' => $svg !== '' ? \html_writer::empty_tag('img', ['src' => 'data:image/svg+xml;base64,' . base64_encode($svg),
                'alt' => '']) : events::qr_of($url),
        ];
    }

    /**
     * What the centre pastes in its web to have the form inside it.
     *
     * @param \stdClass $entry
     * @param string $title
     * @return string
     */
    public static function embed_code(\stdClass $entry, string $title): string {
        global $CFG;
        return '<iframe src="' . self::form_url($entry, true)->out(false) . '" title="' . s($title) .
            '" style="width:100%;min-height:900px;border:0" loading="lazy"></iframe>' . "\n" .
            '<script src="' . $CFG->wwwroot . '/local/oksigeniaeguahe/pub/embed.js" async></script>';
    }

    /**
     * The line the centre puts on every page of its web: where each visit first came from (a link with utm, a search
     * engine, an AI assistant) goes on to the links and the forms of Eguahe there (pub/embed.js, arrival).
     *
     * @return string
     */
    public static function site_code(): string {
        global $CFG;
        return '<script src="' . $CFG->wwwroot . '/local/oksigeniaeguahe/pub/embed.js" async></script>';
    }

    /**
     * The script the centre pastes in its Google form or sheet: it sends each answer, signed, as it comes.
     *
     * @param \stdClass $entry
     * @param string $where form or sheet.
     * @return string
     */
    public static function apps_script(\stdClass $entry, string $where): string {
        return documents::render('apps_script.js', [
            'form' => $where === 'form',
            'sheet' => $where !== 'form',
            'url' => json_encode(self::hook_url($entry)->out(false), JSON_UNESCAPED_SLASHES),
            'secret' => json_encode((string) $entry->secret),
            'name' => preg_replace('/[\r\n]+/', ' ', (string) $entry->name),
            // The titles this sheet has had, and those the ads write: its row of titles is found by them.
            'titles' => json_encode(array_values(array_unique(array_merge(
                array_filter(self::questions($entry), fn($q) => !self::is_value((string) $q)),
                self::AD_TITLES
            ))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
        ]);
    }

    /**
     * The rows of a spreadsheet (the export of the forms of Meta ads, a list of contacts) as families that come in.
     *
     * @param \stdClass $entry
     * @param array $rows Each row, header => value.
     * @return array new, already (came before), nobody (without anyone to answer).
     */
    public static function from_rows(\stdClass $entry, array $rows): array {
        $counts = ['new' => 0, 'already' => 0, 'nobody' => 0];
        foreach ($rows as $row) {
            [$household, $new] = self::receive($entry, $row, '', null, true);
            $counts[$household ? ($new ? 'new' : 'already') : 'nobody']++;
        }
        return $counts;
    }

    /**
     * The rows of the sheets of a file, header => value: from the first sheet whose header says who writes.
     *
     * @param array $sheets As sheets::read() gives them.
     * @return array [rows, headers]
     */
    public static function rows_of(array $sheets): array {
        foreach ($sheets as $sheet) {
            foreach (array_slice($sheet['rows'], 0, 10, true) as $at => $row) {
                $roles = array_map(fn($cell) => recognise::header_role((string) $cell), $row);
                if (!array_intersect(['email', 'phone', 'fullname', 'firstname'], $roles)) {
                    continue;
                }
                // A name that comes twice («Teléfono de contacto» of the family and of who comes with them) keeps both:
                // the second one is «… (2)», which says it is of the second person.
                $names = [];
                $seen = [];
                foreach ($row as $i => $header) {
                    $name = trim((string) $header);
                    $seen[$name] = ($seen[$name] ?? 0) + 1;
                    $names[$i] = $name !== '' && $seen[$name] > 1 ? $name . ' (' . $seen[$name] . ')' : $name;
                }
                $out = [];
                foreach (array_slice($sheet['rows'], $at + 1) as $values) {
                    $item = [];
                    foreach ($names as $i => $header) {
                        if ($header !== '' && isset($values[$i]) && trim((string) $values[$i]) !== '') {
                            $item[$header] = (string) $values[$i];
                        }
                    }
                    if ($item) {
                        $out[] = $item;
                    }
                }
                return [$out, array_values(array_filter($names, fn($h) => $h !== ''))];
            }
        }
        return [[], []];
    }

    /**
     * An email that reaches the entry, as it came: who wrote it, the fields of a web form and the rest as text.
     *
     * @param \stdClass $entry
     * @param string $raw The whole message.
     * @param bool $past Whether it was written before the entry was connected.
     * @return array As receive() gives it.
     */
    public static function from_email(\stdClass $entry, string $raw, bool $past = false): array {
        if (stripos($raw, self::TESTMAIL) !== false) {
            // A test sent to the mailbox of the centre: the whole way works, and nobody comes in.
            self::tested($entry);
            return [null, false, null];
        }
        $mail = inbound::parse($raw);
        // Only the team and the address of the centre may forward a family's message, or send the notice of a form.
        $centre = centres::get((int) $entry->centreid);
        $trusted = array_values(array_filter(array_map(
            fn($u) => strtolower(trim((string) $u->email)),
            centres::members((int) $entry->centreid)
        )));
        if ($centre && trim((string) $centre->email) !== '') {
            // Its address, and its own domain (the notices of the forms of its web), unless anyone can have one there.
            $own = strtolower(trim((string) $centre->email));
            $trusted[] = $own;
            $domain = substr($own, (int) strrpos($own, '@'));
            if (!in_array(ltrim($domain, '@'), inbound::OPEN_DOMAINS, true)) {
                $trusted[] = $domain;
            }
        }
        // From anyone else, a phone written in the text never finds a family the centre already has.
        return self::receive(
            $entry,
            inbound::answers($mail, $trusted),
            $mail['messageid'],
            $mail['time'] ?: null,
            $past,
            inbound::vouched($mail, $trusted)
        );
    }

    /**
     * Erases an entry; the families that came through it stay, with their history.
     *
     * @param \stdClass $entry
     */
    public static function delete(\stdClass $entry): void {
        global $DB;
        $DB->delete_records(self::ARRIVALS, ['entryid' => $entry->id]);
        // A survey gone: its events ask only the open question again.
        $DB->set_field(events::T, 'survey', 0, ['survey' => $entry->id]);
        $DB->delete_records(self::T, ['id' => $entry->id]);
    }
}
