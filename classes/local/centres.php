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
        global $DB;
        $now = time();
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
        $colour = strtolower(trim((string) ($data->colour ?? '')));
        $row->colour = preg_match('/^#[0-9a-f]{6}$/', $colour) ? $colour : null;
        foreach (['controller', 'rightscontact', 'dpocontact'] as $field) {
            $value = trim((string) ($data->$field ?? ''));
            $row->$field = $value === '' ? null : \core_text::substr($value, 0, 255);
        }
        $row->retention = max(1, min(120, (int) ($data->retention ?? 24)));
        $row->municipalities = trim((string) ($data->municipalities ?? '')) ?: null;
        $row->aiprovider = (int) ($data->aiprovider ?? 0);
        // How families reach the centre, and a line about what a visit is like.
        foreach (['address' => 255, 'phone' => 50, 'email' => 255] as $field => $length) {
            $value = trim((string) ($data->$field ?? ''));
            $row->$field = $value === '' ? null : \core_text::substr($value, 0, $length);
        }
        $row->email = $row->email !== null && validate_email($row->email) ? $row->email : null;
        $row->welcome = trim((string) ($data->welcome ?? '')) ?: null;
        $row->logoplate = empty($data->logoplate) ? 0 : 1;
        return $row;
    }

    /**
     * Every centre, by name.
     *
     * @return \stdClass[]
     */
    public static function all(): array {
        global $DB;
        return $DB->get_records(self::T, null, 'name ASC, id ASC');
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
        return array_key_exists($centreid, self::for_user($userid));
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
        return $DB->get_records_sql("SELECT u.id, m.id AS memberid, u.email, $fields
                                       FROM {" . self::MEMBERS . "} m JOIN {user} u ON u.id = m.userid
                                      WHERE m.centreid = :centreid AND u.deleted = 0
                                   ORDER BY u.lastname, u.firstname", ['centreid' => $centreid]);
    }

    /**
     * Adds a person to the team of a centre.
     *
     * @param int $centreid
     * @param int $userid
     */
    public static function add_member(int $centreid, int $userid): void {
        global $DB;
        if (!$DB->record_exists(self::MEMBERS, ['centreid' => $centreid, 'userid' => $userid])) {
            $DB->insert_record(self::MEMBERS, (object) ['centreid' => $centreid, 'userid' => $userid,
                'timecreated' => time()]);
        }
    }

    /**
     * Who receives the families: the members of the team the centre shows them, with their role, a line of theirs and
     * their photo; all of them, or those of a list in its order.
     *
     * @param int $centreid
     * @param int[]|null $memberids
     * @return array A list of objects: memberid, name, first, initials, role, says, photo (URL or null).
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
            $out[] = (object) ['memberid' => (int) $row->memberid, 'name' => fullname($row), 'first' => $first,
                'initials' => $initials, 'role' => (string) $row->rolename, 'says' => (string) $row->says,
                'photo' => self::host_photo((int) $row->memberid)];
        }
        return array_values($out);
    }

    /**
     * The file of the logo of the centre, for the mail and the poster.
     *
     * @param int $centreid
     * @return \stored_file|null
     */
    public static function logo_file(int $centreid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            'logo',
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
        $DB->delete_records(self::MEMBERS, ['centreid' => $centreid, 'userid' => $userid]);
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
     * Saves an offer: new when it has no id.
     *
     * @param int $centreid
     * @param \stdClass $data schoolyear, name, places, sortorder, active and, to change one, id.
     * @return int
     */
    public static function save_offer(int $centreid, \stdClass $data): int {
        global $DB;
        $row = (object) [
            'centreid' => $centreid,
            'schoolyear' => \core_text::substr(trim((string) $data->schoolyear), 0, 9),
            'name' => \core_text::substr(trim((string) $data->name), 0, 255),
            'places' => max(0, (int) ($data->places ?? 0)),
            'sortorder' => (int) ($data->sortorder ?? 0),
            'active' => empty($data->active) ? 0 : 1,
            'timemodified' => time(),
        ];
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
     * The name of an offer with its school year, as the team and families read it: «Infantil 3 años · 2027-28».
     *
     * @param \stdClass $offer
     * @return string
     */
    public static function offer_label(\stdClass $offer): string {
        return format_string($offer->name) . ' · ' . s($offer->schoolyear);
    }
}
