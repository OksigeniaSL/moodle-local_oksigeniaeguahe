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
 * The voice service of Oksigenia, which records once the reading of «Tu primer día» that says a name: the site asks it
 * for the names of the children who will have their story, a few each time, and keeps what it gives in the bank
 * (namevoices). Only the first name goes, with the language and the reading. With no address or key in the settings,
 * nothing is asked and the story is read without the name.
 *
 * The service answers ready (with the address of a ZIP, the folder of the reading), pending (ask again after
 * retry_after), unavailable (that name has no voice: not again for 30 days) or limited (the site asked enough today).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class namevoice_service {
    /** @var string The table of what was asked. */
    const T = 'local_oksigeniaeguahe_namevoice';

    /** @var string The recipe of the service for this story. */
    const RECIPE = 'eguahe-first-day';

    /** @var array[] The readings with the name of each language of the book, as the recipe names them. */
    const READINGS = [
        'es' => ['nombre', 'nombre-maestro'],
        'en' => ['nombre'],
        'pt-PT' => ['nombre', 'nombre-maestro'],
        'pt-BR' => ['nombre', 'nombre-maestro'],
        'ar' => ['nombre-f', 'nombre-m', 'nombre-maestro-f', 'nombre-maestro-m'],
    ];

    /** @var string The recipe of the service for the birthday wish. */
    const BIRTHDAY_RECIPE = 'eguahe-birthday';

    /** @var array[] The readings of the birthday wish of each language: in Arabic, to a boy and to a girl. */
    const BIRTHDAY = [
        'es' => ['cumple'],
        'en' => ['cumple'],
        'pt-PT' => ['cumple'],
        'pt-BR' => ['cumple'],
        'ar' => ['cumple-f', 'cumple-m'],
    ];

    /** @var int How long before a birthday its wish is asked for. */
    const BIRTHDAY_BEFORE = 45 * DAYSECS;

    /** @var int The questions at most in each run. */
    const PER_RUN = 20;

    /** @var int When a name has no voice, or is kept, until it is asked again. */
    const AGAIN = 30 * DAYSECS;

    /** @var int The wait when the service does not answer as it should. */
    const PAUSE = HOURSECS;

    /** @var int Seconds to wait for an answer. */
    const TIMEOUT = 30;

    /** @var bool Between two questions, a second (not in the tests). */
    public static $calm = true;

    /**
     * The address and key of the service, or null when the site has none.
     *
     * @return array|null [address, key]
     */
    public static function settings(): ?array {
        $api = trim((string) get_config('local_oksigeniaeguahe', 'voicesapi'));
        $key = trim((string) get_config('local_oksigeniaeguahe', 'voiceskey'));
        return $api !== '' && $key !== '' ? [rtrim($api, '/') . '/', $key] : null;
    }

    /**
     * The readings a child needs: in the language of the book, with «maestra» or «maestro» as their course, and in
     * Arabic for a boy and for a girl (the family chooses in the page).
     *
     * @param string $code
     * @param string $teacher maestra or maestro.
     * @return string[]
     */
    public static function readings(string $code, string $teacher): array {
        $all = self::READINGS[$code] ?? [];
        $base = $teacher === 'maestro' && in_array('nombre-maestro' . ($code === 'ar' ? '-f' : ''), $all, true) ?
            'nombre-maestro' : 'nombre';
        return array_values(array_filter($all, fn($r) => $r === $base || $r === $base . '-f' || $r === $base . '-m'));
    }

    /**
     * The voices wanted now: of each child enrolled in a course that gives the story, who has not left and whose story
     * has not passed yet, the readings of the language of their family, by their name as the book writes it.
     *
     * @param int $now
     * And the birthday wish of the little ones (birthdays::VIDEO) whose birthday comes in BIRTHDAY_BEFORE.
     *
     * @return array[] Key "code/reading/namekey" => [code, reading, name, recipe].
     */
    public static function wanted(int $now): array {
        global $DB;
        $sql = "SELECT c.*, h.lang AS householdlang, h.centreid AS householdcentre
                  FROM {" . households::CHILDREN . "} c
                  JOIN {" . households::T . "} h ON h.id = c.householdid
                 WHERE c.stage = :stage AND (c.lefttime IS NULL OR c.lefttime = 0)
              ORDER BY c.stagetime DESC, c.id DESC";
        $out = [];
        $centres = [];
        $offers = [];
        foreach ($DB->get_records_sql($sql, ['stage' => 'enrolled']) as $child) {
            $lang = (string) $child->householdlang;
            $centreid = (int) $child->householdcentre;
            if (!isset(story::CODES[$lang])) {
                continue;
            }
            $name = familypage::label_name($child) ?: (string) $child->firstname;
            $code = story::CODES[$lang];
            // Their birthday wish, while they are little, from some weeks before.
            $birth = trim((string) ($child->birthdate ?? ''));
            if ($birth !== '' && namevoices::key($name) !== '') {
                [$day, $age] = birthdays::next($birth, $now);
                if ($age <= birthdays::VIDEO && $day - $now <= self::BIRTHDAY_BEFORE) {
                    foreach (self::BIRTHDAY[$code] ?? [] as $reading) {
                        $out[$code . '/' . $reading . '/' . namevoices::key($name)] = [$code, $reading, $name,
                            self::BIRTHDAY_RECIPE];
                    }
                }
            }
            $centres[$centreid] = $centres[$centreid] ?? centres::get($centreid);
            $centre = $centres[$centreid];
            $first = (int) ($centre->firstday ?? 0);
            if (!$centre || ($first && $now > $first + story::AFTER)) {
                continue;
            }
            $offers[$child->offerid] = $offers[$child->offerid] ??
                $DB->get_record(centres::OFFERS, ['id' => $child->offerid, 'centreid' => $centreid]);
            $offer = $offers[$child->offerid];
            if (!$offer || !story::offer_gives($offer, $centreid)) {
                continue;
            }
            $key = namevoices::key($name);
            if ($key === '') {
                continue;
            }
            $teacher = in_array($offer->teacher ?? '', story::TEACHERS, true) ? $offer->teacher : 'maestra';
            foreach (self::readings($code, $teacher) as $reading) {
                $out[$code . '/' . $reading . '/' . $key] = [$code, $reading, $name, self::RECIPE];
            }
        }
        return $out;
    }

    /**
     * Asks the service for the voices wanted that the bank has not got, at most PER_RUN, and keeps what is ready.
     *
     * @param int $now
     * @return int The questions asked.
     */
    public static function fetch(int $now): int {
        global $DB;
        $settings = self::settings();
        if (!$settings || (int) get_config('local_oksigeniaeguahe', 'voicespause') > $now) {
            return 0;
        }
        $asked = 0;
        foreach (self::wanted($now) as [$code, $reading, $name, $recipe]) {
            if ($asked >= self::PER_RUN) {
                break;
            }
            $key = namevoices::key($name);
            $row = $DB->get_record(self::T, ['lang' => $code, 'reading' => $reading, 'namekey' => $key]);
            if ($row && (int) $row->nextcheck > $now) {
                continue;
            }
            if ($asked && self::$calm) {
                sleep(1);
            }
            $asked++;
            $answer = self::post($settings, ['recipe' => $recipe, 'lang' => $code, 'reading' => $reading, 'name' => $name]);
            $status = (string) ($answer['status'] ?? '');
            $wait = max(60, (int) ($answer['retry_after'] ?? 0));
            if ($status === 'limited' || !in_array($status, ['ready', 'pending', 'unavailable'], true)) {
                // The site asked enough for today, or the service did not answer as it should: everything waits.
                set_config('voicespause', $now + ($status === 'limited' ? $wait : self::PAUSE), 'local_oksigeniaeguahe');
                break;
            }
            $state = ['status' => $status, 'nextcheck' => $now + ($status === 'pending' ? $wait : self::AGAIN)];
            if ($status === 'ready') {
                $sha = strtolower((string) ($answer['sha256'] ?? ''));
                $have = namevoices::find($code, $reading, $name) ?? namevoices::find_birthday($code, $reading, $name);
                if (!$have || !$row || $row->sha256 !== $sha) {
                    if (!self::keep($settings, (string) ($answer['url'] ?? ''), $sha, $code, $reading, $name)) {
                        $state = ['status' => 'pending', 'nextcheck' => $now + self::PAUSE];
                    }
                }
                if ($state['status'] === 'ready') {
                    $state += ['sha256' => $sha, 'recipeversion' => (string) ($answer['recipe_version'] ?? '')];
                }
            }
            self::save($row, $code, $reading, $key, $state, $now);
        }
        return $asked;
    }

    /**
     * Keeps what was asked and what the service said.
     *
     * @param \stdClass|false $row
     * @param string $code
     * @param string $reading
     * @param string $key
     * @param array $state
     * @param int $now
     */
    private static function save($row, string $code, string $reading, string $key, array $state, int $now): void {
        global $DB;
        $record = (object) ($state + ['timemodified' => $now]);
        if ($state['status'] !== 'ready') {
            $record->sha256 = '';
        }
        if ($row) {
            $record->id = $row->id;
            $DB->update_record(self::T, $record);
            return;
        }
        $record->lang = $code;
        $record->reading = $reading;
        $record->namekey = $key;
        $record->sha256 = $record->sha256 ?? '';
        $record->recipeversion = $record->recipeversion ?? '';
        $DB->insert_record(self::T, $record);
    }

    /**
     * Brings the ZIP of a reading, checks it is the one the service said and puts its folder in the bank.
     *
     * @param array $settings [address, key]
     * @param string $url
     * @param string $sha
     * @param string $code
     * @param string $reading
     * @param string $name
     * @return bool Whether the bank has it whole now.
     */
    private static function keep(array $settings, string $url, string $sha, string $code, string $reading, string $name): bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if ($url === '') {
            return false;
        }
        $dir = make_request_directory();
        $zip = $dir . '/voz.zip';
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT * 4, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
        $curl->setHeader(['Authorization: Bearer ' . $settings[1]]);
        try {
            $body = $curl->get($url);
        } catch (\Throwable $error) {
            debugging('Eguahe voices: ' . $error->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
        if ($body === '' || ($sha !== '' && hash('sha256', $body) !== $sha)) {
            return false;
        }
        file_put_contents($zip, $body);
        $out = $dir . '/voz';
        if (!get_file_packer('application/zip')->extract_to_pathname($zip, $out)) {
            return false;
        }
        // The folder as it goes, at the top of the ZIP or inside the only folder it has: the story says where it
        // speaks (habla.json), the birthday wish where the name is (cumple.json).
        $whole = fn(string $dir) => is_file($dir . '/habla.json') || is_file($dir . '/cumple.json');
        $folder = $out;
        $inside = glob($out . '/*', GLOB_ONLYDIR) ?: [];
        if (!$whole($folder) && count($inside) === 1) {
            $folder = $inside[0];
        }
        if (!$whole($folder)) {
            return false;
        }
        namevoices::store($code, $reading, $name, $folder);
        return (namevoices::find($code, $reading, $name) ?? namevoices::find_birthday($code, $reading, $name)) !== null;
    }

    /**
     * One question to the service, as JSON with the key of the site.
     *
     * @param array $settings [address, key]
     * @param array $fields
     * @return array What it answered, or an empty array when it did not answer.
     */
    private static function post(array $settings, array $fields): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json',
            'Authorization: Bearer ' . $settings[1]]);
        try {
            $body = $curl->post($settings[0] . 'names', json_encode($fields));
        } catch (\Throwable $error) {
            debugging('Eguahe voices: ' . $error->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
        $answer = json_decode((string) $body, true);
        return is_array($answer) ? $answer : [];
    }
}
