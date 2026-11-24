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
 * The centres of the site, their offer and their teams, and who may work for which.
 *
 * A site holds one centre (its own Moodle) or many (the hosted service). Every row of every table carries its centre, and
 * every page asks this class first: nobody of one centre sees the families of another.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class centres {
    /** @var string[] The pictures of the miniature of a centre: the way in, the same for phones, and where to park. */
    const MINIATURES = ['miniature', 'miniaturenarrow', 'miniatureparking'];

    /** @var string[] Who signs up at a centre: a family for its children, the student for themselves, or either. */
    const WHOAPPLIES = ['family', 'student', 'both'];

    /** @var string[] Who sees Cifras and the reports: the whole team, or only those who change the settings and those who
     *     only look (its management). */
    const METRICSFOR = ['team', 'heads'];

    /** @var string */
    const T = 'local_oksigeniaeguahe_centre';

    /** @var string */
    const MEMBERS = 'local_oksigeniaeguahe_member';

    /** @var string */
    const OFFERS = 'local_oksigeniaeguahe_offer';

    /**
     * A centre.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        return $DB->get_record(self::T, ['id' => $id]) ?: null;
    }

    /**
     * Creates a centre.
     *
     * @param \stdClass $data name and the rest of the fields of the table.
     * @return int
     */
    public static function create(\stdClass $data): int {
        global $CFG, $DB;
        $now = time();
        if (!property_exists($data, 'country') && !empty($CFG->country)) {
            $data = clone $data;
            $data->country = $CFG->country;
        }
        $row = self::clean($data);
        $row->timecreated = $now;
        $row->timemodified = $now;
        return (int) $DB->insert_record(self::T, $row);
    }

    /**
     * Changes a centre.
     *
     * @param int $id
     * @param \stdClass $data
     */
    public static function update(int $id, \stdClass $data): void {
        global $DB;
        // What is not passed keeps what the centre had: a change of a few fields never empties the rest.
        $data = clone $data;
        foreach ((array) ($DB->get_record(self::T, ['id' => $id]) ?: []) as $field => $value) {
            if (!property_exists($data, $field)) {
                $data->$field = $value;
            }
        }
        $row = self::clean($data);
        $row->id = $id;
        $row->timemodified = time();
        $DB->update_record(self::T, $row);
    }

    /**
     * The fields of a centre as the table takes them.
     *
     * @param \stdClass $data
     * @return \stdClass
     */
    protected static function clean(\stdClass $data): \stdClass {
        $row = new \stdClass();
        $row->name = trim((string) ($data->name ?? ''));
        $row->lang = in_array($data->lang ?? '', lists::LANGS, true) ? $data->lang : 'en';
        $country = strtoupper(trim((string) ($data->country ?? '')));
        $row->country = preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
        $currency = strtoupper(trim((string) ($data->currency ?? '')));
        $row->currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null;
        $system = (string) ($data->system ?? '');
        $row->system = $system === 'none' || isset(grades::data()['systems'][$system]) ? $system : null;
        $colour = strtolower(trim((string) ($data->colour ?? '')));
        $row->colour = preg_match('/^#[0-9a-f]{6}$/', $colour) ? $colour : null;
        if (property_exists($data, 'brandcolours')) {
            $row->brandcolours = implode(' ', self::brand_colours((string) $data->brandcolours)) ?: null;
        }
        foreach (['controller', 'rightscontact', 'dpocontact'] as $field) {
            $value = trim((string) ($data->$field ?? ''));
            $row->$field = $value === '' ? null : \core_text::substr($value, 0, 255);
        }
        $row->retention = max(1, min(120, (int) ($data->retention ?? 24)));
        $row->municipalities = trim((string) ($data->municipalities ?? '')) ?: null;
        $row->aiprovider = (int) ($data->aiprovider ?? 0);
        $row->aion = empty($data->aion) ? 0 : 1;
        // What families share invites whoever receives it (on unless the centre says no), and to which form.
        if (property_exists($data, 'shareinvite')) {
            $row->shareinvite = empty($data->shareinvite) ? 0 : 1;
        }
        if (property_exists($data, 'shareentry')) {
            $row->shareentry = max(0, (int) $data->shareentry);
        }
        // How families reach the centre, and a line about what a visit is like.
        foreach (['address' => 255, 'phone' => 50, 'email' => 255, 'emailsettled' => 255] as $field => $length) {
            $value = trim((string) ($data->$field ?? ''));
            $row->$field = $value === '' ? null : \core_text::substr($value, 0, $length);
        }
        $row->email = $row->email !== null && validate_email($row->email) ? $row->email : null;
        $row->emailsettled = $row->emailsettled !== null && validate_email($row->emailsettled) ? $row->emailsettled : null;
        $row->welcome = trim((string) ($data->welcome ?? '')) ?: null;
        $row->arrive = trim((string) ($data->arrive ?? '')) ?: null;
        if (isset($data->library)) {
            // The library of Ayvurenda for the stories to read at home, among those open.
            $slug = trim((string) $data->library);
            $row->library = $slug !== '' && in_array($slug, array_column(tales::libraries(), 'slug'), true) ? $slug : null;
        }
        if (isset($data->replydays)) {
            // How soon the team usually answers, as the families read it after their visit.
            $row->replydays = in_array((int) $data->replydays, panel_centre::REPLYDAYS, true) ? (int) $data->replydays : 2;
        }
        if (property_exists($data, 'closedics')) {
            // The calendar of the days it closes.
            $row->closedics = \core_text::substr(workdays::address((string) $data->closedics), 0, 500) ?: null;
        }
        $row->logoplate = empty($data->logoplate) ? 0 : 1;
        if (isset($data->familylight)) {
            // The pages and mails of its families always light, whatever the phone of each family says.
            $row->familylight = empty($data->familylight) ? 0 : 1;
        }
        if (isset($data->messaging) && in_array($data->messaging, messaging::MODES, true)) {
            $row->messaging = $data->messaging;
        }
        if (isset($data->skin)) {
            $row->skin = in_array($data->skin, panel::SKINS, true) ? $data->skin : null;
        }
        if (isset($data->hourvalue)) {
            // What an hour of the team is worth for the numbers; 0 shows them only in hours.
            $row->hourvalue = max(0, min(10000, round((float) $data->hourvalue, 2)));
        }
        if (isset($data->metricsfor)) {
            // Who sees Cifras and the reports.
            $row->metricsfor = in_array($data->metricsfor, self::METRICSFOR, true) ? $data->metricsfor : 'team';
        }
        if (isset($data->whoapplies)) {
            // Who signs up: a family for its children, the student for themselves, or either.
            $row->whoapplies = in_array($data->whoapplies, self::WHOAPPLIES, true) ? $data->whoapplies : 'family';
        }
        if (isset($data->handover)) {
            // Who carries the families once enrolled: a person of the team, else a place; and how the centre writes
            // to them from then on.
            $row->handover = max(0, (int) $data->handover);
            $place = trim((string) ($data->handoverplace ?? ''));
            $row->handoverplace = $place === '' ? null : \core_text::substr($place, 0, 255);
            $row->handoverhow = trim((string) ($data->handoverhow ?? '')) ?: null;
        }
        if (isset($data->invitegone)) {
            // Whether those who left are still invited to open acts; by default they are left in peace.
            $row->invitegone = empty($data->invitegone) ? 0 : 1;
        }
        if (isset($data->siblingage)) {
            // The age children enter at, for the brothers and sisters; 0, children do not enter by age here.
            $row->siblingage = max(0, min(18, (int) $data->siblingage));
        }
        if (isset($data->firstday)) {
            // The first day of the next school year, for the story of those who start; 0, not written.
            $row->firstday = max(0, (int) $data->firstday);
        }
        if (isset($data->namephrase)) {
            // The name as it is said in a sentence («el Colegio Los Arces»), for the story.
            $phrase = trim((string) $data->namephrase);
            $row->namephrase = $phrase === '' ? null : \core_text::substr($phrase, 0, 255);
        }
        if (isset($data->applyhow)) {
            // How a family asks for a place, where and when: what its page says after the visit.
            $row->applyhow = trim((string) $data->applyhow) ?: null;
            $url = trim((string) ($data->applyurl ?? ''));
            $row->applyurl = $url !== '' && preg_match('#^https?://#i', $url) ? \core_text::substr($url, 0, 255) : null;
            $row->applyfrom = max(0, (int) ($data->applyfrom ?? 0));
            $row->applyuntil = max(0, (int) ($data->applyuntil ?? 0));
            if ($row->applyfrom && $row->applyuntil && $row->applyuntil < $row->applyfrom) {
                [$row->applyfrom, $row->applyuntil] = [$row->applyuntil, $row->applyfrom];
            }
        }
        if (isset($data->stagenotes) && is_array($data->stagenotes)) {
            // A word beside each stage, by the age it starts at, as families read it («concertada», «privada»).
            $notes = [];
            foreach ($data->stagenotes as $from => $note) {
                $note = \core_text::substr(trim(clean_param((string) $note, PARAM_TEXT)), 0, 60);
                if ($note !== '' && is_numeric($from)) {
                    $notes[(int) $from] = $note;
                }
            }
            $row->stagenotes = $notes ? json_encode($notes, JSON_UNESCAPED_UNICODE) : null;
        }
        if (isset($data->applyafter)) {
            // Whether how to ask for a place is also offered once a family came, or only when the team sends it.
            $row->applyafter = empty($data->applyafter) ? 0 : 1;
        }
        if (isset($data->guide)) {
            // The guide on the pages of the families, and what it says, as the centre writes it.
            $row->guide = in_array($data->guide, guide::KINDS, true) ? $data->guide : null;
        }
        foreach (['guidewhere', 'guidehello'] as $said) {
            if (isset($data->$said)) {
                $text = \core_text::substr(trim((string) $data->$said), 0, 255);
                $row->$said = $text !== '' ? $text : null;
            }
        }
        if (isset($data->familyfrom)) {
            // Who puts an enrolled family in the common record: Eguahe, or the management system of the centre.
            $row->familyfrom = in_array($data->familyfrom, family::FROM, true) ? $data->familyfrom : 'eguahe';
        }
        if (isset($data->costs)) {
            // What it all costs, and when families see it: from the start, after their visit, or never.
            $row->costs = trim((string) $data->costs) !== '' ? \core_text::substr(trim((string) $data->costs), 0, 2000) : null;
            $row->costswhen = in_array($data->costswhen ?? '', costs::WHEN, true) ? $data->costswhen : 'after';
        }
        if (isset($data->channelauto)) {
            // The moments of each open act that go by themselves to the channel of Telegram.
            $row->channelauto = channel::auto_value(is_array($data->channelauto) ? $data->channelauto :
                explode(',', (string) $data->channelauto));
        }
        if (isset($data->networks)) {
            // The networks a family may name, one per line, as the centre writes them; empty, the usual ones.
            $names = array_slice(array_unique(array_filter(array_map(
                fn($n) => \core_text::substr(trim($n), 0, 60),
                preg_split('/[\r\n,]+/', (string) $data->networks)
            ), fn($n) => $n !== '')), 0, 30);
            $row->networks = $names ? implode("\n", $names) : null;
        }
        return $row;
    }

    /**
     * The networks a family may name when it heard of the centre on one: those the centre wrote or, without them, the
     * usual ones. None is written in Eguahe for good: whichever the families use, the centre adds it.
     *
     * @param \stdClass $centre
     * @return string[]
     */
    public static function networks(\stdClass $centre): array {
        $names = array_values(array_filter(array_map('trim', explode("\n", (string) ($centre->networks ?? '')))));
        return $names ?: lists::NETWORKS;
    }

    /**
     * A network as a family named it, written as the centre writes it when it is one of its own.
     *
     * @param \stdClass $centre
     * @param string $named
     * @return string
     */
    public static function network(\stdClass $centre, string $named): string {
        $named = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $named)), 0, 60);
        foreach (self::networks($centre) as $name) {
            if (\core_text::strtolower($name) === \core_text::strtolower($named)) {
                return $name;
            }
        }
        return $named;
    }

    /**
     * Every centre, by name.
     *
     * @return \stdClass[]
     */
    public static function all(): array {
        global $DB;
        // The practice centre of the guided helps is in no list and in no task.
        return $DB->get_records(self::T, ['practice' => 0], 'name ASC, id ASC');
    }

    /**
     * Whether a centre is the practice centre of the guided helps: in no list, and nothing it does leaves the site.
     *
     * @param int $centreid
     * @return bool
     */
    public static function is_practice(int $centreid): bool {
        global $DB;
        // Asked each time, never kept: a number kept from a centre that was deleted could answer for a new one.
        return (bool) $DB->get_field(self::T, 'practice', ['id' => $centreid]);
    }

    /**
     * The centres a person may work for: all of them for whoever sets them up, else those of their teams.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    public static function for_user(int $userid): array {
        global $DB;
        $context = \context_system::instance();
        if (has_capability('local/oksigeniaeguahe:manage', $context, $userid)) {
            return self::all();
        }
        if (!has_any_capability(['local/oksigeniaeguahe:work', 'local/oksigeniaeguahe:view'], $context, $userid)) {
            return [];
        }
        return $DB->get_records_sql('SELECT c.* FROM {' . self::T . '} c JOIN {' . self::MEMBERS . '} m ON m.centreid = c.id
                                      WHERE m.userid = :userid ORDER BY c.name ASC, c.id ASC', ['userid' => $userid]);
    }

    /**
     * Whether a person may work for a centre.
     *
     * @param int $centreid
     * @param int $userid
     * @return bool
     */
    public static function can_work(int $centreid, int $userid): bool {
        global $DB;
        $mine = self::for_user($userid);
        // The practice centre of the helps, for whoever works for any centre: it is where the guided helps happen. Never
        // the centre of a visitor, which is theirs alone, and visitors practise in their own.
        return array_key_exists($centreid, $mine) || ($mine && !demo::is_visitor($userid) &&
            (int) $DB->get_field(self::T, 'practice', ['id' => $centreid]) === 1);
    }

    /**
     * Whether a person may act for the families (answer, note, change), not only see them.
     *
     * @param int $userid
     * @return bool
     */
    public static function can_act(int $userid): bool {
        return has_any_capability(
            ['local/oksigeniaeguahe:work', 'local/oksigeniaeguahe:manage'],
            \context_system::instance(),
            $userid
        );
    }

    /**
     * Stops the page unless the person logged in may act for the families of the centre; returns it.
     *
     * @param int $centreid
     * @return \stdClass
     */
    public static function require_act(int $centreid): \stdClass {
        global $USER;
        $centre = self::require_work($centreid);
        if (!self::can_act((int) $USER->id)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/oksigeniaeguahe:work',
                'nopermissions',
                ''
            );
        }
        return $centre;
    }

    /**
     * Stops the page unless the person logged in may work for the centre, or see it; returns it.
     *
     * @param int $centreid
     * @return \stdClass
     */
    public static function require_work(int $centreid): \stdClass {
        global $USER;
        require_login(null, false);
        $centre = self::get($centreid);
        if (!$centre || !self::can_work($centreid, (int) $USER->id)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/oksigeniaeguahe:work',
                'nopermissions',
                ''
            );
        }
        return $centre;
    }

    /**
     * The centre a page is about: the one asked for, or the only one the person has.
     *
     * @param int $asked 0 when the page does not say.
     * @return \stdClass|null Null when the person has several and did not choose.
     */
    public static function pick(int $asked): ?\stdClass {
        global $USER;
        if ($asked) {
            return self::require_work($asked);
        }
        require_login(null, false);
        $mine = self::for_user((int) $USER->id);
        if (count($mine) === 1) {
            return reset($mine);
        }
        if (!$mine && !has_capability('local/oksigeniaeguahe:view', \context_system::instance())) {
            require_capability('local/oksigeniaeguahe:work', \context_system::instance());
        }
        return null;
    }

    /**
     * The team of a centre.
     *
     * @param int $centreid
     * @return \stdClass[] Users, with the id of their membership as memberid.
     */
    public static function members(int $centreid): array {
        global $DB;
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        return $DB->get_records_sql("SELECT u.id, m.id AS memberid, m.admin, u.email, $fields
                                       FROM {" . self::MEMBERS . "} m JOIN {user} u ON u.id = m.userid
                                      WHERE m.centreid = :centreid AND u.deleted = 0
                                   ORDER BY u.lastname, u.firstname", ['centreid' => $centreid]);
    }

    /**
     * Adds a person to the team of a centre.
     *
     * @param int $centreid
     * @param int $userid
     * @return bool Whether they work for the families (false: on this site they only look).
     */
    public static function add_member(int $centreid, int $userid): bool {
        global $DB;
        if (!$DB->record_exists(self::MEMBERS, ['centreid' => $centreid, 'userid' => $userid])) {
            $DB->insert_record(self::MEMBERS, (object) ['centreid' => $centreid, 'userid' => $userid,
                'timecreated' => time()]);
        }
        return onlyeguahe::join($userid);
    }

    /**
     * Says whether a member of the team changes the settings and the team of the centre.
     *
     * @param int $centreid
     * @param int $userid
     * @param bool $admin
     */
    public static function set_admin(int $centreid, int $userid, bool $admin): void {
        global $DB;
        $DB->set_field(self::MEMBERS, 'admin', $admin ? 1 : 0, ['centreid' => $centreid, 'userid' => $userid]);
    }

    /**
     * Whether a person changes the settings and the team of a centre: whoever manages Eguahe on the site, or a member of
     * its team the centre made so. They see only their own centres, also in a site shared by several.
     *
     * @param int $centreid
     * @param int $userid 0, the person logged in.
     * @return bool
     */
    public static function can_manage(int $centreid, int $userid = 0): bool {
        global $DB, $USER;
        $userid = $userid ?: (int) $USER->id;
        if (!$userid) {
            return false;
        }
        if (has_capability('local/oksigeniaeguahe:manage', \context_system::instance(), $userid)) {
            return true;
        }
        return $centreid && self::can_act($userid) &&
            $DB->record_exists(self::MEMBERS, ['centreid' => $centreid, 'userid' => $userid, 'admin' => 1]);
    }

    /**
     * Whether a person sees Cifras, the reports and the forecast of a centre: the whole team, or, when the centre says so,
     * only those who change its settings and those who only look (its management).
     *
     * @param \stdClass $centre
     * @param int $userid
     * @return bool
     */
    public static function can_see_metrics(\stdClass $centre, int $userid = 0): bool {
        global $USER;
        $userid = $userid ?: (int) $USER->id;
        if (!self::can_work((int) $centre->id, $userid)) {
            return false;
        }
        if (($centre->metricsfor ?? 'team') !== 'heads' || self::can_manage((int) $centre->id, $userid)) {
            return true;
        }
        return !self::can_act($userid) &&
            has_capability('local/oksigeniaeguahe:view', \context_system::instance(), $userid);
    }

    /**
     * Stops the page unless the person logged in sees Cifras of the centre; returns it.
     *
     * @param int $centreid
     * @return \stdClass
     */
    public static function require_metrics(int $centreid): \stdClass {
        global $USER;
        $centre = self::require_work($centreid);
        if (!self::can_see_metrics($centre, (int) $USER->id)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/oksigeniaeguahe:manage',
                'nopermissions',
                ''
            );
        }
        return $centre;
    }

    /**
     * Stops the page unless the person logged in changes the settings of the centre; returns it.
     *
     * @param int $centreid
     * @return \stdClass
     */
    public static function require_manage(int $centreid): \stdClass {
        $centre = self::require_work($centreid);
        if (!self::can_manage($centreid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/oksigeniaeguahe:manage',
                'nopermissions',
                ''
            );
        }
        return $centre;
    }

    /**
     * Who receives the families: the members of the team the centre shows them, with their role, a line of theirs and
     * their photo; all of them, or those of a list in its order.
     *
     * @param int $centreid
     * @param int[]|null $memberids
     * @return array A list of objects: memberid, userid, name, first, initials, role, says, photo (URL or null).
     */
    public static function hosts(int $centreid, ?array $memberids = null): array {
        global $DB;
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $rows = $DB->get_records_sql("SELECT m.id AS memberid, m.rolename, m.says, m.sortorder, u.id AS userid, $fields
                                        FROM {" . self::MEMBERS . "} m JOIN {user} u ON u.id = m.userid
                                       WHERE m.centreid = :centreid AND m.public = 1 AND u.deleted = 0
                                    ORDER BY m.sortorder, u.firstname, u.lastname", ['centreid' => $centreid]);
        if ($memberids !== null) {
            $rows = array_filter(array_map(fn($id) => $rows[$id] ?? null, $memberids));
        }
        $out = [];
        foreach ($rows as $row) {
            $first = trim((string) $row->firstname);
            $initials = \core_text::strtoupper(\core_text::substr($first, 0, 1) .
                \core_text::substr(trim((string) $row->lastname), 0, 1));
            // To families, the name as it is said, «Lucía Medina», whatever order the lists of the site use.
            $out[] = (object) ['memberid' => (int) $row->memberid, 'userid' => (int) $row->userid,
                'name' => trim($first . ' ' . trim((string) $row->lastname)),
                'first' => $first, 'initials' => $initials, 'role' => (string) $row->rolename, 'says' => (string) $row->says,
                'photo' => self::host_photo((int) $row->memberid)];
        }
        return array_values($out);
    }

    /**
     * Who will answer what a family writes, as families see them (hosts()): the person who attends that family; if
     * nobody does, those who have been answering the families of the centre lately, the most first; with no answers
     * yet, the only person the centre shows, if it shows one. Nobody away; never a guess among many.
     *
     * @param \stdClass $centre
     * @param \stdClass|null $household
     * @param int $now
     * @return array As hosts(), up to three.
     */
    public static function answerers(\stdClass $centre, ?\stdClass $household = null, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $hosts = self::hosts((int) $centre->id);
        $away = $DB->get_records_select_menu(
            self::MEMBERS,
            'centreid = :centreid AND awayuntil > :now',
            ['centreid' => $centre->id, 'now' => $now],
            '',
            'userid, awayuntil'
        );
        $hosts = array_values(array_filter($hosts, fn($h) => empty($away[$h->userid])));
        $byuser = [];
        foreach ($hosts as $h) {
            $byuser[$h->userid] = $h;
        }
        $owner = (int) ($household->ownerid ?? 0);
        if ($owner && isset($byuser[$owner])) {
            return [$byuser[$owner]];
        }
        $lately = $DB->get_records_sql_menu(
            "SELECT userid, COUNT(1) AS n
               FROM {" . households::CONTACTS . "}
              WHERE centreid = :centreid AND direction = 'out' AND aim IS NULL AND userid > 0 AND timecreated > :since
           GROUP BY userid
           ORDER BY COUNT(1) DESC, userid ASC",
            ['centreid' => $centre->id, 'since' => $now - 90 * DAYSECS]
        );
        $answered = array_values(array_filter(array_map(fn($u) => $byuser[$u] ?? null, array_keys($lately))));
        if ($answered) {
            return array_slice($answered, 0, 3);
        }
        return count($hosts) === 1 && !$lately ? $hosts : [];
    }

    /**
     * The file of the logo of the centre, for the mail and the poster.
     *
     * @param int $centreid
     * @param string $area logo, or logodark for its version for dark backgrounds.
     * @return \stored_file|null
     */
    public static function logo_file(int $centreid, string $area = 'logo'): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            $area,
            $centreid,
            'filename',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * The file of the photo of someone who receives the families.
     *
     * @param int $memberid
     * @return \stored_file|null
     */
    public static function host_photo_file(int $memberid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            'hostphoto',
            $memberid,
            'filename',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * The photo of someone who receives the families, if the centre gave one.
     *
     * @param int $memberid
     * @return \moodle_url|null
     */
    public static function host_photo(int $memberid): ?\moodle_url {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            'hostphoto',
            $memberid,
            'filename',
            false
        );
        $file = reset($files);
        return $file ? \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'local_oksigeniaeguahe',
            'hostphoto',
            $memberid,
            $file->get_filepath(),
            $file->get_filename()
        ) : null;
    }

    /**
     * Takes a person out of the team of a centre.
     *
     * @param int $centreid
     * @param int $userid
     */
    public static function remove_member(int $centreid, int $userid): void {
        global $DB;
        // Its photo for the families goes with it.
        foreach ($DB->get_fieldset_select(self::MEMBERS, 'id', 'centreid = ? AND userid = ?', [$centreid, $userid]) as $id) {
            get_file_storage()->delete_area_files(\context_system::instance()->id, 'local_oksigeniaeguahe', 'hostphoto', $id);
        }
        $DB->delete_records(self::MEMBERS, ['centreid' => $centreid, 'userid' => $userid]);
        onlyeguahe::leave($userid);
    }

    /**
     * The area of the centre: the municipalities its families come from, for the closed list of the forms.
     *
     * @param \stdClass $centre
     * @return array Official code => name, in the order the centre wrote them.
     */
    public static function municipalities(\stdClass $centre): array {
        $list = [];
        foreach (preg_split('/\R/', (string) $centre->municipalities) as $line) {
            $parts = array_map('trim', explode(';', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $list[\core_text::substr($parts[0], 0, 20)] = $parts[1];
            } else if ($parts[0] !== '') {
                // A name without its code still counts: the code is filled in later.
                $list['name:' . \core_text::substr(md5($parts[0]), 0, 12)] = $parts[0];
            }
        }
        return $list;
    }

    /**
     * The word families read beside each stage of a centre, by the age it starts at.
     *
     * @param \stdClass|null $centre
     * @return array age => word
     */
    public static function stage_notes(?\stdClass $centre): array {
        $notes = json_decode((string) ($centre->stagenotes ?? ''), true);
        return is_array($notes) ? $notes : [];
    }

    /**
     * The miniature of the centre, if it has one: its plan drawn, with the way from the entrance to where families are
     * received (miniature; miniaturenarrow, the same cut for phones); or the whole with where to park lit, for a big act
     * (miniatureparking). Families see it on their page.
     *
     * @param int $centreid
     * @param string $area One of MINIATURES.
     * @return \moodle_url|null
     */
    public static function miniature(int $centreid, string $area = 'miniature'): ?\moodle_url {
        if (!in_array($area, self::MINIATURES, true)) {
            return null;
        }
        $context = \context_system::instance()->id;
        $files = get_file_storage()->get_area_files($context, 'local_oksigeniaeguahe', $area, $centreid, 'filename', false);
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return \moodle_url::make_pluginfile_url(
            $context,
            'local_oksigeniaeguahe',
            $area,
            $centreid,
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * How wide the logo of a centre is for its height, from the picture itself: the viewBox or the size of an SVG, or
     * the pixels of a PNG, JPEG or WebP.
     *
     * @param int $centreid
     * @param string $area logo, or logodark.
     * @return float|null Width divided by height; null without a logo or when it cannot be read.
     */
    public static function logo_aspect(int $centreid, string $area = 'logo'): ?float {
        $file = self::logo_file($centreid, $area);
        if (!$file) {
            return null;
        }
        $content = $file->get_content();
        if (preg_match('/<svg\b[^>]*>/i', $content, $tag)) {
            if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $tag[0], $m) && (float) $m[2] > 0) {
                return (float) $m[1] / (float) $m[2];
            }
            if (
                preg_match('/\bwidth\s*=\s*["\']([\d.]+)/i', $tag[0], $w) && preg_match('/\bheight\s*=\s*["\']([\d.]+)/i', $tag[0], $h) &&
                    (float) $h[1] > 0
            ) {
                return (float) $w[1] / (float) $h[1];
            }
            return null;
        }
        $size = @getimagesizefromstring($content);
        return $size && $size[1] > 0 ? $size[0] / $size[1] : null;
    }

    /**
     * The logo of a centre as a picture the server can draw (PNG, JPEG, WebP): the logo itself, or the PNG copy the
     * browser made of it when it is an SVG. For the images that are shared and the emails.
     *
     * @param int $centreid
     * @param bool $dark The logo for dark backgrounds.
     * @return \stored_file|null
     */
    public static function logo_raster(int $centreid, bool $dark = false): ?\stored_file {
        $file = self::logo_file($centreid, $dark ? 'logodark' : 'logo');
        if (!$file) {
            return null;
        }
        return strpos((string) $file->get_mimetype(), 'svg') === false ? $file :
            self::logo_file($centreid, $dark ? 'logodarkpng' : 'logopng');
    }

    /**
     * The logo of a centre for dark backgrounds, when it gave one.
     *
     * @param int $centreid
     * @return \moodle_url|null
     */
    public static function logo_dark(int $centreid): ?\moodle_url {
        $file = self::logo_file($centreid, 'logodark');
        return $file ? \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'local_oksigeniaeguahe',
            'logodark',
            $centreid,
            '/',
            $file->get_filename(),
            false
        ) : null;
    }

    /**
     * Whether the logo of a centre gets lost on a dark page: when more than a third of what it shows does not
     * stand out 3 to 1 against the paper of the night. Then, at night, it goes on its light plate.
     *
     * @param int $centreid
     * @return bool
     */
    public static function logo_needs_plate(int $centreid): bool {
        static $known = [];
        $file = self::logo_file($centreid);
        if (!$file) {
            return false;
        }
        $hash = $file->get_contenthash();
        if (isset($known[$hash])) {
            return $known[$hash];
        }
        $light = function (int $r, int $g, int $b): float {
            $c = array_map(fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, [$r, $g, $b]);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        // The paper of the pages at night, #171413.
        $ground = $light(0x17, 0x14, 0x13);
        $lost = fn(int $r, int $g, int $b) => ($light($r, $g, $b) + 0.05) / ($ground + 0.05) < 3;
        $content = $file->get_content();
        $seen = 0;
        $dark = 0;
        if (preg_match('/<svg\b/i', $content)) {
            // Each fill or stroke written in the drawing counts once.
            preg_match_all('/(?:fill|stroke)\s*[:=]\s*["\']?\s*#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $content, $m);
            foreach ($m[1] as $hex) {
                $hex = strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
                $seen++;
                $dark += $lost(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))) ? 1 : 0;
            }
        } else if (function_exists('imagecreatefromstring') && ($image = @imagecreatefromstring($content))) {
            // A sample of the pixels that show (not transparent).
            $w = imagesx($image);
            $h = imagesy($image);
            for ($y = 0; $y < $h; $y += max(1, intdiv($h, 40))) {
                for ($x = 0; $x < $w; $x += max(1, intdiv($w, 40))) {
                    $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    if ($c['alpha'] > 100) {
                        continue;
                    }
                    $seen++;
                    $dark += $lost($c['red'], $c['green'], $c['blue']) ? 1 : 0;
                }
            }
        }
        return $known[$hash] = $seen > 0 && $dark / $seen > 0.3;
    }

    /**
     * The logo of the centre, if it has one.
     *
     * @param int $centreid
     * @return \moodle_url|null
     */
    public static function logo(int $centreid): ?\moodle_url {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            'logo',
            $centreid,
            'filename',
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'local_oksigeniaeguahe',
            'logo',
            $centreid,
            '/',
            $file->get_filename(),
            false
        );
    }

    /**
     * The offer of a centre, in its order.
     *
     * @param int $centreid
     * @param bool $activeonly Only what families see in the forms.
     * @return \stdClass[]
     */
    public static function offers(int $centreid, bool $activeonly = false): array {
        global $DB;
        $params = ['centreid' => $centreid];
        if ($activeonly) {
            $params['active'] = 1;
        }
        return $DB->get_records(self::OFFERS, $params, 'schoolyear DESC, sortorder ASC, id ASC');
    }

    /**
     * The colours of the brand of a centre, as it wrote them: up to four codes («#164281 #067e36», «164281, 067e36»),
     * the same one once.
     *
     * @param string $value
     * @return string[] Codes such as #164281.
     */
    public static function brand_colours(string $value): array {
        preg_match_all('/#?\b([0-9a-f]{6}|[0-9a-f]{3})\b/i', $value, $m);
        $out = [];
        foreach ($m[1] as $code) {
            $code = strtolower(strlen($code) === 3 ? $code[0] . $code[0] . $code[1] . $code[1] . $code[2] . $code[2] : $code);
            $out['#' . $code] = true;
        }
        return array_slice(array_keys($out), 0, 4);
    }

    /**
     * The brand of a centre for its posters and the pages of its families: its colours, in the order it gave them.
     *
     * @param \stdClass $centre
     * @return string[] One to four codes, or none when the centre has not written its brand.
     */
    public static function brand(\stdClass $centre): array {
        return self::brand_colours((string) ($centre->brandcolours ?? ''));
    }

    /**
     * The colour of the centre on what its families see (its pages, its emails, its images): the one it chose, unless it
     * wrote its brand and that colour is not in it; then the first of its brand that reads on white, or else the first
     * (a teal centre with a burgundy brand would show teal buttons under burgundy titles).
     *
     * @param \stdClass $centre
     * @return string #rrggbb
     */
    public static function colour(\stdClass $centre): string {
        $chosen = strtolower((string) ($centre->colour ?? '')) ?: '#2f5d62';
        $brand = self::brand($centre);
        if (!$brand || in_array($chosen, $brand, true)) {
            return $chosen;
        }
        foreach ($brand as $code) {
            if (publicpage::contrast($code, '#ffffff') >= 4.5) {
                return $code;
            }
        }
        return $brand[0];
    }

    /**
     * Saves an offer: new when it has no id.
     *
     * @param int $centreid
     * @param \stdClass $data schoolyear, name, places, sortorder, active and, to change one, id.
     * @return int
     */
    public static function save_offer(int $centreid, \stdClass $data): int {
        global $DB;
        // The names of the grades are read again with the offer as it is now.
        grades::reset();
        $row = (object) [
            'centreid' => $centreid,
            'schoolyear' => \core_text::substr(trim((string) $data->schoolyear), 0, 9),
            'name' => \core_text::substr(trim((string) $data->name), 0, 255),
            'places' => max(0, (int) ($data->places ?? 0)),
            'sortorder' => (int) ($data->sortorder ?? 0),
            'active' => empty($data->active) ? 0 : 1,
            'timemodified' => time(),
        ];
        if (isset($data->handover)) {
            // Who carries the families of this course once enrolled (its tutor); 0, the one of the centre.
            $row->handover = max(0, (int) $data->handover);
        }
        if (isset($data->story)) {
            // Whether its children get the story of the first day.
            $row->story = empty($data->story) ? 0 : 1;
        }
        if (isset($data->teacher)) {
            // How the story calls whoever receives the course.
            $row->teacher = in_array($data->teacher, story::TEACHERS, true) ? $data->teacher : 'maestra';
        }
        if (!empty($data->id)) {
            $DB->get_record(self::OFFERS, ['id' => $data->id, 'centreid' => $centreid], 'id', MUST_EXIST);
            $row->id = (int) $data->id;
            $DB->update_record(self::OFFERS, $row);
            return $row->id;
        }
        $row->timecreated = $row->timemodified;
        return (int) $DB->insert_record(self::OFFERS, $row);
    }

    /**
     * Adds the courses of a stage of the school system of a centre to a school year, those it does not have yet (by
     * name), active and without places, in the order of their age.
     *
     * @param int $centreid
     * @param int $key The stage, as grades::stages() gives it.
     * @param string $year The school year (2027-28).
     * @return int How many were added.
     */
    public static function add_stage(int $centreid, int $key, string $year): int {
        $centre = self::get($centreid);
        $stage = current(array_filter(grades::stages($centre), fn($s) => $s['key'] === $key));
        if (!$stage) {
            return 0;
        }
        $have = [];
        foreach (self::offers($centreid) as $offer) {
            if ((string) $offer->schoolyear === trim($year)) {
                $have[grades::plain((string) $offer->name)] = true;
            }
        }
        $added = 0;
        foreach ($stage['courses'] as $course) {
            if (isset($have[grades::plain($course['name'])])) {
                continue;
            }
            self::save_offer($centreid, (object) ['schoolyear' => $year, 'name' => $course['name'], 'places' => 0,
                'active' => 1, 'sortorder' => ($course['age'] + 1) * 10]);
            $added++;
        }
        return $added;
    }

    /**
     * The name of an offer with its school year, as the team and families read it: «Infantil 3 años · 2027-28».
     *
     * @param \stdClass $offer
     * @return string
     */
    public static function offer_label(\stdClass $offer): string {
        return format_string($offer->name) . ' · ' . s($offer->schoolyear);
    }

    /**
     * The currency of a centre: the one it chose, or else the one of its country as the international data has it
     * (ICU); empty when neither is known. No currency is written in the code.
     *
     * @param \stdClass $centre
     * @return string ISO 4217, or ''.
     */
    public static function currency(\stdClass $centre): string {
        if (!empty($centre->currency)) {
            return (string) $centre->currency;
        }
        if (empty($centre->country) || !class_exists('\NumberFormatter')) {
            return '';
        }
        try {
            $code = (new \NumberFormatter('en_' . $centre->country, \NumberFormatter::CURRENCY))->getTextAttribute(
                \NumberFormatter::CURRENCY_CODE
            );
        } catch (\Throwable $e) {
            return '';
        }
        return preg_match('/^[A-Z]{3}$/', (string) $code) && $code !== 'XXX' ? $code : '';
    }

    /**
     * An amount of a centre with its currency, as the language of the page writes it: «1.234 €», «A$1,234»; without a
     * currency, the number alone.
     *
     * @param \stdClass $centre
     * @param float $amount
     * @param int $decimals
     * @return string
     */
    public static function money(\stdClass $centre, float $amount, int $decimals = 0): string {
        $currency = self::currency($centre);
        if ($currency === '' || !class_exists('\NumberFormatter')) {
            return format_float($amount, $decimals, true, true);
        }
        $format = new \NumberFormatter(self::locale($centre), \NumberFormatter::CURRENCY);
        $format->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $format->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        return (string) $format->formatCurrency($amount, $currency);
    }

    /**
     * The symbol of the currency of a centre in the language of the page («€», «R$», «A$»), or '' without one.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function currency_symbol(\stdClass $centre): string {
        $currency = self::currency($centre);
        if ($currency === '' || !class_exists('\NumberFormatter')) {
            return $currency;
        }
        $format = new \NumberFormatter(self::locale($centre), \NumberFormatter::CURRENCY);
        $format->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);
        return (string) $format->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
    }

    /**
     * The locale of an amount: the language of the page with its own region (pt_br is pt_BR), or else with the country of
     * the centre (English in Spain is en_ES): nothing written per country.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function locale(\stdClass $centre): string {
        $parts = explode('_', current_language());
        $region = strtoupper($parts[1] ?? (string) ($centre->country ?? ''));
        return $parts[0] . ($region !== '' ? '_' . $region : '');
    }

    /**
     * The centre as a family sees it. A family already of the centre (brought from its roll, marked as of the centre, or
     * with a child enrolled), and the question of who goes on next year, get the address of the centre for its families
     * when it has one: what they answer goes to the office that looks after them, not to admissions.
     *
     * @param \stdClass $centre
     * @param \stdClass $household
     * @param string $kind The mail, if it is one.
     * @return \stdClass The same centre, or a copy with that address as its email.
     */
    public static function for_family(\stdClass $centre, \stdClass $household, string $kind = ''): \stdClass {
        global $DB;
        $other = trim((string) ($centre->emailsettled ?? ''));
        if ($other === '') {
            return $centre;
        }
        $ofcentre = $kind === 'renewal' || !empty($household->incentre) || !empty($household->roster) ||
            $DB->record_exists_select(
                households::CHILDREN,
                "householdid = ? AND stage = 'enrolled' AND (lefttime IS NULL OR lefttime = 0)",
                [(int) $household->id]
            );
        if (!$ofcentre) {
            return $centre;
        }
        $copy = clone $centre;
        $copy->email = $other;
        return $copy;
    }
}
