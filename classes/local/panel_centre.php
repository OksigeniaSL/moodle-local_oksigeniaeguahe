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
 * The settings of a centre in the panel, whatever they look like: how families see it (with its colour in light and
 * dark, and what the server does so that it reads), who receives them, Telegram and WhatsApp, the data, the area, the
 * offer with its places, the team, the skin of the panel and the AI. All of it is saved at once, with the logo and the
 * photos of who receives.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_centre {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string The colour of a centre that has none yet. */
    const COLOUR = '#2f5d62';

    /** @var int[] How many working days the team may say it usually takes to answer. */
    const REPLYDAYS = [1, 2, 3, 5];

    /** @var string[] The pictures a logo can be. */
    const LOGOS = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

    /** @var string[] The pictures a photo can be. */
    const PHOTOS = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var int The largest picture taken, in bytes. */
    const MOST = 5 * 1024 * 1024;

    /**
     * The context of the page.
     *
     * @param \stdClass $centre
     * @return array
     */
    public static function context(\stdClass $centre): array {
        $c = self::C;
        $colour = $centre->colour ?: self::COLOUR;
        $style = fn(array $vars) => implode(';', array_map(fn($k, $v) => $k . ':' . $v, array_keys($vars), $vars));
        $lang = [];
        foreach (lists::LANG_NAMES as $value => $name) {
            $lang[] = ['value' => $value, 'name' => $name, 'selected' => $value === $centre->lang];
        }
        $logo = centres::logo((int) $centre->id);
        $name = format_string($centre->name);
        $libraries = tales::libraries();
        $index = ['aspecto' => 'panel_how_families_see', 'reciben' => 'panel_who_receives'];
        $messaging = [];
        if (messaging::available()) {
            $index['mensajes'] = 'centre_messaging';
            $mode = messaging::mode($centre);
            foreach (messaging::MODES as $value) {
                $messaging[] = ['value' => $value, 'name' => get_string('centre_messaging_' . $value, $c),
                    'help' => get_string('panel_messaging_' . $value . '_help', $c), 'checked' => $value === $mode];
            }
        }
        $index['despues'] = 'panel_after_enrolment';
        $index += ['datos' => 'centre_data', 'zona' => 'panel_the_area', 'redes' => 'panel_networks',
            'oferta' => 'panel_offer_places', 'equipo' => 'centre_members', 'panel' => 'centre_skin', 'ia' => 'centre_ai'];
        // Without a provider of its own, the one the site already uses for Oksigenia, or Moodle's own.
        $providers = [['value' => 0, 'name' => get_string('centre_ai_site', $c), 'selected' => empty($centre->aiprovider)]];
        foreach (ai::providers() as $id => $provider) {
            $providers[] = ['value' => $id, 'name' => $provider, 'selected' => (int) $centre->aiprovider === $id];
        }
        return [
            'head' => panel::head(['title' => get_string('centre_settings', $c),
                'sub' => get_string('panel_settings_sub', $c)]),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/centre.php', ['id' => $centre->id]))->out(false),
            'sesskey' => sesskey(),
            'index' => array_map(
                fn($key, $string) => ['key' => $key, 'name' => get_string($string, $c)],
                array_keys($index),
                $index
            ),
            'name' => $name,
            'colour' => $colour,
            'lightvars' => $style(publicpage::centre_vars($colour, false)),
            'darkvars' => $style(publicpage::centre_vars($colour, true)),
            'colourguard' => self::guard($colour),
            'lang' => $lang,
            'countries' => self::countries((string) $centre->country),
            'currencies' => self::currencies($centre),
            'systems' => self::systems($centre),
            'address' => (string) $centre->address,
            'phone' => (string) $centre->phone,
            'email' => (string) $centre->email,
            'welcome' => (string) $centre->welcome,
            'arrive' => (string) ($centre->arrive ?? ''),
            'replydays' => array_map(fn($d) => ['value' => $d, 'name' => get_string($d === 1 ? 'panel_replydays_one' :
                'panel_replydays_n', self::C, $d), 'selected' => $d === (int) ($centre->replydays ?? 2)], self::REPLYDAYS),
            'hastales' => (bool) $libraries,
            'libraries' => array_merge([['value' => '', 'name' => get_string('panel_tales_none', self::C),
                'selected' => trim((string) ($centre->library ?? '')) === '']], array_map(fn($l) => ['value' => $l['slug'],
                'name' => $l['tales'] ? $l['name'] : get_string('panel_tales_empty', self::C, $l['name']),
                'selected' => $l['slug'] === (string) ($centre->library ?? '')], $libraries)),
            'logo' => $logo ? $logo->out(false) : '',
            'logoplate' => !empty($centre->logoplate),
            'initial' => \core_text::strtoupper(\core_text::substr($name, 0, 1)),
            'hanging' => panel::hanging(0, get_string('panel_sample_tag', $c), '', $centre),
            'hosts' => self::hosts($centre),
            'handover' => self::handovers($centre, (int) ($centre->handover ?? 0), 'panel_handover_place_option'),
            'handoverplace' => (string) ($centre->handoverplace ?? ''),
            'handoverhow' => (string) ($centre->handoverhow ?? ''),
            'invitegone' => !empty($centre->invitegone),
            // For the story «Your first day»: when the school year starts, and the name of the centre as it is said in a sentence.
            'firstday' => !empty($centre->firstday) ? userdate((int) $centre->firstday, '%Y-%m-%d') : '',
            'namephrase' => (string) ($centre->namephrase ?? ''),
            'namephraseexample' => get_string('panel_namephrase_example', $c, format_string($centre->name)),
            'siblingages' => array_map(fn($n) => ['value' => $n, 'name' => $n ? get_string('panel_siblingage_n', $c, $n) :
                get_string('panel_siblingage_none', $c), 'selected' => $n === (int) ($centre->siblingage ?? 0)], range(0, 12)),
            'hasmessaging' => (bool) $messaging,
            'messaging' => $messaging,
            'controller' => (string) $centre->controller,
            'rights' => (string) $centre->rightscontact,
            'dpo' => (string) $centre->dpocontact,
            'retention' => (int) $centre->retention,
            'municipalities' => (string) $centre->municipalities,
            'networks' => implode("\n", centres::networks($centre)),
            'offers' => self::offers($centre),
            'members' => self::members($centre),
            'skins' => self::skins($centre),
            'skinproposal' => self::proposal((int) $centre->id),
            'ai' => ['providers' => $providers, 'note' => get_string(ai::supported() ? 'centre_aiprovider_help' :
                'panel_ai_later', $c), 'on' => !empty($centre->aion), 'several' => count($providers) > 2,
                // Only who manages Eguahe on the site turns it on or off (Enrique, 9-11).
                'locked' => !has_capability('local/oksigeniaeguahe:manage', \context_system::instance()),
                'lockednote' => get_string('centre_ai_locked', $c)],
        ];
    }

    /**
     * What the server does with a colour so that everything reads, in a sentence: the same sum panel.js makes as it
     * is chosen.
     *
     * @param string $colour
     * @return string
     */
    public static function guard(string $colour): string {
        $accent = strtoupper($colour);
        $light = publicpage::centre_vars($accent, false);
        $dark = publicpage::centre_vars($accent, true);
        $rgb = sscanf(ltrim($accent, '#'), '%2x%2x%2x');
        if (max($rgb) - min($rgb) < 24) {
            $key = 'panel_js_colour_grey';
        } else if ($light['--c-acento-texto'] !== $accent) {
            $key = 'panel_js_colour_light';
        } else if ($dark['--c-acento-vivo'] !== $accent || $dark['--c-acento-texto'] !== $accent) {
            $key = 'panel_js_colour_dark';
        } else {
            $key = 'panel_js_colour_ok';
        }
        return get_string($key, self::C);
    }

    /**
     * Everyone of the team, with their face, what they do and the sentence they say to the families, and whether the
     * families see them.
     *
     * @param \stdClass $centre
     * @return array
     */
    protected static function hosts(\stdClass $centre): array {
        global $DB;
        $rows = $DB->get_records(
            centres::MEMBERS,
            ['centreid' => $centre->id],
            '',
            'id, userid, public, rolename, says, sortorder'
        );
        $users = centres::members((int) $centre->id);
        $out = [];
        foreach ($users as $user) {
            $member = $rows[$user->memberid] ?? null;
            if (!$member) {
                continue;
            }
            $photo = centres::host_photo((int) $member->id);
            $person = panel::person($user);
            $out[] = ['id' => (int) $member->id, 'name' => $person['name'], 'first' => $person['first'],
                'picture' => $photo ? $photo->out(false) : $person['picture'], 'initials' => $person['initials'],
                'role' => (string) $member->rolename, 'shown' => !empty($member->public), 'phrase' => (string) $member->says,
                'sortorder' => (int) $member->sortorder];
        }
        usort($out, fn($a, $b) => [$a['sortorder'], $a['name']] <=> [$b['sortorder'], $b['name']]);
        return $out;
    }

    /**
     * The offer, the latest school year first, with its places on the rail, and a row more for a new course.
     *
     * @param \stdClass $centre
     * @return array
     */
    protected static function offers(\stdClass $centre): array {
        $counts = households::offer_counts((int) $centre->id);
        $out = [];
        $year = '';
        foreach (array_values(centres::offers((int) $centre->id)) as $i => $offer) {
            $year = $year ?: (string) $offer->schoolyear;
            $enrolled = (int) ($counts[$offer->id]['enrolled'] ?? 0);
            $out[] = ['i' => $i, 'id' => (int) $offer->id, 'name' => (string) $offer->name,
                'year' => (string) $offer->schoolyear, 'places' => (int) $offer->places, 'active' => !empty($offer->active),
                'handovers' => self::handovers($centre, (int) ($offer->handover ?? 0), 'offer_handover_centre'),
                'story' => story::offer_gives($offer, (int) $centre->id),
                'teachers' => self::teachers((string) ($offer->teacher ?? 'maestra')),
                'empty' => false, 'rail' => $offer->places > 0 && $offer->active ? panel::rail(
                    (int) $offer->places,
                    $enrolled,
                    object: panel::hanging_object((string) $offer->name, $centre)
                ) : ''];
        }
        $out[] = ['i' => count($out), 'id' => 0, 'name' => '', 'year' => $year ?: self::next_year(), 'places' => '',
            'active' => true, 'handovers' => self::handovers($centre, 0, 'offer_handover_centre'), 'empty' => true,
            'story' => false, 'teachers' => self::teachers('maestra'),
            'rail' => ''];
        return $out;
    }

    /**
     * Who can carry the families once enrolled, as options: nobody in particular first, then the team.
     *
     * @param \stdClass $centre
     * @param int $chosen The member chosen; 0, nobody in particular.
     * @param string $none The string of the first option.
     * @return array
     */
    protected static function handovers(\stdClass $centre, int $chosen, string $none): array {
        $out = [['value' => 0, 'name' => get_string($none, self::C), 'selected' => !$chosen]];
        foreach (self::hosts($centre) as $host) {
            $out[] = ['value' => $host['id'], 'name' => $host['role'] !== '' ? $host['name'] . ' · ' . $host['role'] :
                $host['name'], 'selected' => $host['id'] === $chosen];
        }
        return $out;
    }

    /**
     * How the story may call whoever receives a course, as options.
     *
     * @param string $chosen
     * @return array
     */
    protected static function teachers(string $chosen): array {
        return array_map(fn($t) => ['value' => $t, 'name' => get_string('offer_teacher_' . $t, self::C),
            'selected' => $t === $chosen], story::TEACHERS);
    }

    /**
     * A day written in a date field, at its start where the centre lives; 0 without one.
     *
     * @param string $value Y-m-d.
     * @return int
     */
    protected static function date(string $value): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return 0;
        }
        return make_timestamp((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * The school year that admissions are usually for: the next one.
     *
     * @return string 2027-28
     */
    protected static function next_year(): string {
        $year = (int) date('Y') + ((int) date('n') >= 9 ? 1 : 0);
        return $year . '-' . substr((string) ($year + 1), -2);
    }

    /**
     * The team, with their faces.
     *
     * @param \stdClass $centre
     * @return array
     */
    protected static function members(\stdClass $centre): array {
        global $DB, $USER;
        $roles = $DB->get_records_menu(centres::MEMBERS, ['centreid' => $centre->id], '', 'userid, rolename');
        $out = [];
        foreach (centres::members((int) $centre->id) as $user) {
            $out[] = panel::person($user) + ['id' => (int) $user->id, 'role' => (string) ($roles[$user->id] ?? ''),
                'me' => (int) $user->id === (int) $USER->id];
        }
        return $out;
    }

    /**
     * The skins, with their picture in light and dark when they have one; the one the offer suggests first.
     *
     * @param \stdClass $centre
     * @return array
     */
    protected static function skins(\stdClass $centre): array {
        global $CFG;
        $c = self::C;
        $proposed = panel::proposed_skin((int) $centre->id);
        $chosen = in_array((string) $centre->skin, panel::SKINS, true) ? (string) $centre->skin : '';
        $word = get_string('skin_' . $proposed, $c);
        $word = \core_text::strtolower(\core_text::substr($word, 0, 1)) . \core_text::substr($word, 1);
        $out = [['value' => '', 'name' => get_string('centre_skin_auto', $c, $word),
            'help' => get_string('panel_skin_auto_help', $c), 'checked' => $chosen === '', 'auto' => true, 'img' => '',
            'imgdark' => '', 'id' => 'piel-auto', 'soon' => false]];
        foreach (panel::SKINS as $skin) {
            $dir = '/local/oksigeniaeguahe/pub/pieles/' . $skin . '/';
            $ready = is_readable($CFG->dirroot . $dir . 'piel.css');
            $img = is_readable($CFG->dirroot . $dir . 'muestra.webp') ? (new \moodle_url($dir . 'muestra.webp'))->out(false) : '';
            $dark = is_readable($CFG->dirroot . $dir . 'muestra-noche.webp') ?
                (new \moodle_url($dir . 'muestra-noche.webp'))->out(false) : '';
            $out[] = ['value' => $skin, 'name' => get_string('skin_' . $skin, $c),
                'help' => get_string($ready ? 'panel_skin_' . $skin . '_help' : 'panel_skin_soon', $c),
                'checked' => $chosen === $skin, 'auto' => false, 'img' => $img, 'imgdark' => $dark, 'id' => 'piel-' . $skin,
                'soon' => !$ready];
        }
        return $out;
    }

    /**
     * The skin the offer suggests, in a sentence with the ages of its courses.
     *
     * @param int $centreid
     * @return string
     */
    public static function proposal(int $centreid): string {
        $c = self::C;
        $skin = get_string('skin_' . panel::proposed_skin($centreid), $c);
        $offers = centres::offers($centreid, true);
        $centre = centres::get($centreid);
        $ages = array_filter(array_map(fn($o) => grades::age((string) $o->name, $centre), $offers), fn($a) => $a !== null);
        if (!$offers) {
            return get_string('panel_skin_proposal_nooffer', $c, $skin);
        }
        if (!$ages) {
            return get_string('panel_skin_proposal_noages', $c, $skin);
        }
        return get_string('panel_skin_proposal', $c, (object) ['from' => min($ages), 'to' => max($ages), 'skin' => $skin]);
    }

    /**
     * Saves everything that was posted, with the logo and the photos.
     *
     * @param \stdClass $centre
     * @return string[] What could not be done, in words.
     */
    public static function save(\stdClass $centre): array {
        global $DB;
        $c = self::C;
        $problems = [];
        $picked = strtolower(optional_param('colour', '', PARAM_RAW_TRIMMED));
        $typed = strtolower(optional_param('colourhex', '', PARAM_RAW_TRIMMED));
        $typed = $typed !== '' && $typed[0] !== '#' ? '#' . $typed : $typed;
        // The code written wins when it was changed by hand; else the colour picked.
        $colour = preg_match('/^#[0-9a-f]{6}$/', $typed) && $typed !== strtolower((string) $centre->colour) ? $typed : $picked;
        $name = trim(optional_param('name', '', PARAM_TEXT));
        $manage = has_capability('local/oksigeniaeguahe:manage', \context_system::instance());
        $data = (object) [
            'name' => $name !== '' ? $name : $centre->name,
            'lang' => optional_param('lang', $centre->lang, PARAM_ALPHAEXT),
            'country' => optional_param('country', '', PARAM_ALPHA),
            'currency' => optional_param('currency', '', PARAM_ALPHA),
            'system' => optional_param('system', '', PARAM_ALPHANUMEXT),
            'colour' => $colour,
            'address' => optional_param('address', '', PARAM_TEXT),
            'phone' => optional_param('phone', '', PARAM_TEXT),
            'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
            'welcome' => optional_param('welcome', '', PARAM_TEXT),
            'arrive' => optional_param('arrive', '', PARAM_TEXT),
            'replydays' => optional_param('replydays', (int) ($centre->replydays ?? 2), PARAM_INT),
            'library' => optional_param('library', (string) ($centre->library ?? ''), PARAM_ALPHANUMEXT),
            'logoplate' => optional_param('logoplate', 0, PARAM_BOOL),
            'controller' => optional_param('controller', '', PARAM_TEXT),
            'rightscontact' => optional_param('rightscontact', '', PARAM_TEXT),
            'dpocontact' => optional_param('dpocontact', '', PARAM_TEXT),
            'retention' => optional_param('retention', 24, PARAM_INT),
            'municipalities' => optional_param('municipalities', '', PARAM_TEXT),
            'networks' => optional_param('networks', '', PARAM_TEXT),
            'aiprovider' => $manage ? optional_param('aiprovider', 0, PARAM_INT) : (int) $centre->aiprovider,
            'aion' => $manage ? optional_param('aion', 0, PARAM_BOOL) : (int) ($centre->aion ?? 0),
            'skin' => optional_param('skin', '', PARAM_ALPHA),
            'handover' => self::member(optional_param('handover', 0, PARAM_INT), (int) $centre->id),
            'handoverplace' => optional_param('handoverplace', '', PARAM_TEXT),
            'handoverhow' => optional_param('handoverhow', '', PARAM_TEXT),
            'siblingage' => optional_param('siblingage', (int) ($centre->siblingage ?? 0), PARAM_INT),
            'invitegone' => optional_param('invitegone', 0, PARAM_BOOL),
            'firstday' => self::date(optional_param('firstday', '', PARAM_RAW_TRIMMED)),
            'namephrase' => optional_param('namephrase', '', PARAM_TEXT),
        ];
        if (messaging::available()) {
            $data->messaging = optional_param('messaging', messaging::mode($centre), PARAM_ALPHA);
        }
        $transaction = $DB->start_delegated_transaction();
        centres::update((int) $centre->id, $data);
        if (!self::keep_picture('logo', null, 'logo', (int) $centre->id, self::LOGOS)) {
            $problems[] = get_string('panel_logo_not_taken', $c);
        }

        // Who receives: whether families see them, what they do, their sentence and their photo.
        $shown = optional_param_array('shown', [], PARAM_BOOL);
        $roles = optional_param_array('role', [], PARAM_TEXT);
        $phrases = optional_param_array('phrase', [], PARAM_TEXT);
        foreach ($DB->get_records(centres::MEMBERS, ['centreid' => $centre->id]) as $member) {
            if (!array_key_exists($member->id, $phrases) && !array_key_exists($member->id, $roles)) {
                continue;
            }
            $DB->update_record(centres::MEMBERS, (object) ['id' => $member->id, 'public' => empty($shown[$member->id]) ? 0 : 1,
                'rolename' => \core_text::substr(trim((string) ($roles[$member->id] ?? '')), 0, 255) ?: null,
                'says' => trim((string) ($phrases[$member->id] ?? '')) ?: null]);
            if (!self::keep_picture('photo', (int) $member->id, 'hostphoto', (int) $member->id, self::PHOTOS)) {
                $problems[] = get_string('panel_photo_not_taken', $c);
            }
        }

        // The offer: a row without a name goes out of the campaign; a new one comes in.
        $ids = optional_param_array('offerid', [], PARAM_INT);
        $names = optional_param_array('offername', [], PARAM_TEXT);
        $years = optional_param_array('offeryear', [], PARAM_TEXT);
        $places = optional_param_array('offerplaces', [], PARAM_INT);
        $active = optional_param_array('offeractive', [], PARAM_BOOL);
        $handovers = optional_param_array('offerhandover', [], PARAM_INT);
        $stories = optional_param_array('offerstory', [], PARAM_BOOL);
        $teachers = optional_param_array('offerteacher', [], PARAM_ALPHA);
        foreach ($names as $i => $offername) {
            $offerid = (int) ($ids[$i] ?? 0);
            if (trim((string) $offername) === '') {
                if ($offerid) {
                    $DB->set_field(centres::OFFERS, 'active', 0, ['id' => $offerid, 'centreid' => $centre->id]);
                }
                continue;
            }
            $offer = ['id' => $offerid, 'name' => $offername, 'schoolyear' => $years[$i] ?? self::next_year(),
                'places' => $places[$i] ?? 0, 'active' => !empty($active[$i]), 'sortorder' => $i,
                'handover' => self::member((int) ($handovers[$i] ?? 0), (int) $centre->id),
                'teacher' => $teachers[$i] ?? 'maestra'];
            // A new course without the box gives the story by its age; once saved, as the box says.
            if ($offerid || !empty($stories[$i])) {
                $offer['story'] = !empty($stories[$i]);
            }
            centres::save_offer((int) $centre->id, (object) $offer);
        }

        // The team: who goes out, and someone more by their name or their email in Moodle.
        foreach (array_keys(array_filter(optional_param_array('removemember', [], PARAM_BOOL))) as $userid) {
            centres::remove_member((int) $centre->id, (int) $userid);
        }
        $add = trim(optional_param('addmember', '', PARAM_TEXT));
        if ($add !== '') {
            $user = self::find_user($add);
            if ($user) {
                centres::add_member((int) $centre->id, (int) $user->id);
            } else {
                $problems[] = get_string('panel_person_not_found', $c, s($add));
            }
        }
        $transaction->allow_commit();
        return $problems;
    }

    /**
     * A member of the team of a centre, or 0.
     *
     * @param int $memberid
     * @param int $centreid
     * @return int
     */
    protected static function member(int $memberid, int $centreid): int {
        global $DB;
        return $memberid > 0 && $DB->record_exists(centres::MEMBERS, ['id' => $memberid, 'centreid' => $centreid]) ?
            $memberid : 0;
    }

    /**
     * Someone of Moodle by their email, their username or their whole name, when only one is.
     *
     * @param string $what
     * @return \stdClass|null
     */
    public static function find_user(string $what): ?\stdClass {
        global $DB, $CFG;
        $base = 'deleted = 0 AND suspended = 0 AND confirmed = 1 AND id <> :guest';
        $params = ['guest' => $CFG->siteguest];
        $found = $DB->get_records_select('user', $base . ' AND (' . $DB->sql_equal('email', ':email', false) . ' OR ' .
            $DB->sql_equal('username', ':username', false) . ')', $params + ['email' => $what,
            'username' => \core_text::strtolower($what)], '', 'id', 0, 2);
        if (!$found) {
            $whole = $DB->sql_concat('firstname', "' '", 'lastname');
            $found = $DB->get_records_select('user', $base . ' AND ' . $DB->sql_like($whole, ':name', false, false), $params +
                ['name' => $DB->sql_like_escape($what)], '', 'id', 0, 2);
        }
        return count($found) === 1 ? reset($found) : null;
    }

    /**
     * Keeps a picture sent with the form in its place, instead of the one there was.
     *
     * @param string $field The name of the field: logo, or photo[memberid].
     * @param int|null $key The key in the field, for a list of them.
     * @param string $area
     * @param int $itemid
     * @param string[] $types
     * @return bool False when a file came and it was not a picture of those types.
     */
    protected static function keep_picture(string $field, ?int $key, string $area, int $itemid, array $types): bool {
        $sent = $_FILES[$field] ?? null;
        if (!$sent) {
            return true;
        }
        $get = fn(string $what) => $key === null ? ($sent[$what] ?? null) : ($sent[$what][$key] ?? null);
        if ((int) $get('error') === UPLOAD_ERR_NO_FILE || $get('error') === null) {
            return true;
        }
        $path = (string) $get('tmp_name');
        $filename = clean_param((string) $get('name'), PARAM_FILE);
        $fs = get_file_storage();
        if (
            (int) $get('error') !== UPLOAD_ERR_OK || !is_uploaded_file($path) || filesize($path) > self::MOST ||
                $filename === '' || !in_array($fs::mimetype($path, $filename), $types, true)
        ) {
            return false;
        }
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, self::C, $area, $itemid);
        $fs->create_file_from_pathname(['contextid' => $context->id, 'component' => self::C, 'filearea' => $area,
            'itemid' => $itemid, 'filepath' => '/', 'filename' => $filename], $path);
        return true;
    }

    /**
     * Every country, as Moodle names them in the language of whoever looks: none is written in the code.
     *
     * @param string $current ISO 3166-1 alpha-2, or ''.
     * @return array value, name, selected; first, none.
     */
    protected static function countries(string $current): array {
        $out = [['value' => '', 'name' => '—', 'selected' => $current === '']];
        foreach (get_string_manager()->get_list_of_countries() as $code => $name) {
            $out[] = ['value' => $code, 'name' => $name, 'selected' => $code === $current];
        }
        return $out;
    }

    /**
     * Every currency, as Moodle names them; first, the one of the country of the centre, which goes when none is chosen.
     *
     * @param \stdClass $centre
     * @return array value, name, selected.
     */
    protected static function currencies(\stdClass $centre): array {
        $names = get_string_manager()->get_list_of_currencies();
        $own = centres::currency((object) ['country' => $centre->country]);
        $out = [['value' => '', 'name' => $own !== '' ? get_string('centre_currency_country', self::C, $names[$own] ?? $own) :
            get_string('centre_currency_none', self::C), 'selected' => empty($centre->currency)]];
        foreach ($names as $code => $name) {
            $out[] = ['value' => $code, 'name' => $name . ' (' . $code . ')', 'selected' => $code === $centre->currency];
        }
        return $out;
    }

    /**
     * The school systems Eguahe knows, each named by its countries; first, the one of the country of the centre.
     *
     * @param \stdClass $centre
     * @return array value, name, selected.
     */
    protected static function systems(\stdClass $centre): array {
        $countries = get_string_manager()->get_list_of_countries();
        $name = fn(array $system) => implode(', ', array_map(fn($c) => $countries[$c] ?? $c, $system['countries'] ?? []));
        $systems = grades::data()['systems'] ?? [];
        $own = grades::system((object) ['country' => $centre->country]);
        $current = (string) ($centre->system ?? '');
        $out = [['value' => '', 'name' => $own !== '' ? get_string('centre_system_country', self::C, $name($systems[$own])) :
            get_string('centre_system_none', self::C), 'selected' => $current === '']];
        foreach ($systems as $key => $system) {
            $out[] = ['value' => $key, 'name' => $name($system), 'selected' => $current === $key];
        }
        $out[] = ['value' => 'none', 'name' => get_string('centre_system_none', self::C), 'selected' => $current === 'none'];
        return $out;
    }
}
