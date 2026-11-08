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

    /** @var string[] What a question may be, for the team to correct it, in this order. */
    const ROLES = ['firstname', 'surname', 'fullname', 'email', 'phone', 'childname', 'grade', 'notes', 'municipality',
        'declared', 'consent', 'incentre', 'date', 'platform', 'campaign', 'adset', 'ad', 'form', 'ids', 'ignore'];

    /** @var string[] What is not kept: the state of a lead in the tool it came from, and what the team said to leave. */
    const DROPPED = ['status', 'organic', 'ignore'];

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
        if (!isset(self::KINDS[$kind])) {
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
        return $token === '' ? null : ($DB->get_record(self::T, ['token' => $token, 'active' => 1]) ?: null);
    }

    /**
     * The entries of a centre.
     *
     * @param int $centreid
     * @return \stdClass[]
     */
    public static function for_centre(int $centreid): array {
        global $DB;
        return $DB->get_records(self::T, ['centreid' => $centreid], 'active DESC, name ASC, id ASC');
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
     * @param \stdClass $data name, acknowledge, active.
     */
    public static function update(\stdClass $entry, \stdClass $data): void {
        global $DB;
        $DB->update_record(self::T, (object) ['id' => $entry->id,
            'name' => \core_text::substr(trim((string) ($data->name ?? $entry->name)), 0, 255) ?: $entry->name,
            'acknowledge' => empty($data->acknowledge) ? 0 : 1, 'active' => empty($data->active) ? 0 : 1,
            'timemodified' => time()]);
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
     * @return array [household or null when there is nobody to answer, whether it is new, the token of its page or null]
     */
    public static function receive(
        \stdClass $entry,
        array $answers,
        string $externalid = '',
        ?int $time = null,
        bool $past = false
    ): array {
        global $DB;
        $centre = centres::get((int) $entry->centreid);
        if (!$centre) {
            return [null, false, null];
        }
        $questions = array_map('strval', array_keys($answers));
        self::note_questions($entry, $questions);
        $read = self::read($entry, $centre, $answers);
        $time = $time ?: ($read->time ?: time());
        $externalid = $externalid !== '' ? $externalid : $read->externalid;
        if ($read->email === null && $read->phone === null && $read->firstname === '') {
            // Nobody to answer: nothing comes in.
            return [null, false, null];
        }
        $key = hash('sha256', $externalid !== '' ? 'id:' . $externalid :
            implode('|', ['who', $read->email, $read->phone, $read->firstname, $read->lastname, $read->child, $read->time]));
        $arrived = $DB->get_record(self::ARRIVALS, ['entryid' => $entry->id, 'externalid' => $key]);
        if ($arrived) {
            // It came before; if the family erased its data since, it stays erased.
            $household = $arrived->householdid ? households::get((int) $arrived->householdid, (int) $entry->centreid) : null;
            return [$household, false, null];
        }

        $transaction = $DB->start_delegated_transaction();
        $householdid = households::find((int) $centre->id, $read->email, $read->phone);
        $about = (object) (['lang' => $centre->lang ?: 'es', 'firstchannel' => self::channel($entry),
            'heardfrom' => $read->heardfrom, 'incentre' => $read->incentre] + $read->place);
        $householdid = $householdid ?? households::create((int) $centre->id, $about);
        $household = households::get($householdid, (int) $centre->id);
        households::complete($household, $about);
        $adultid = households::add_adult($household, (object) ['firstname' => $read->firstname,
            'lastname' => $read->lastname, 'email' => $read->email, 'phone' => $read->phone]);
        foreach ($read->consents as $channel) {
            households::consent($household, $adultid, $channel, true, 'entry', $household->lang);
        }
        if ($read->child !== '' || $read->offerid) {
            households::add_child($household, $read->child, $read->offerid);
        }
        $body = trim(get_string('entry_arrived', 'local_oksigeniaeguahe', format_string($entry->name)) .
            ($read->lines ? "\n\n" . implode("\n", $read->lines) : ''));
        // Something from long ago is kept, but does not wait for an answer.
        $awaits = !$past || $time > time() - self::PASTDAYS * DAYSECS;
        households::received($household, self::channel($entry), $body, $awaits, $time, (int) $entry->id, $read->detail);
        $DB->insert_record(self::ARRIVALS, (object) ['centreid' => $centre->id, 'entryid' => $entry->id,
            'externalid' => $key, 'householdid' => $household->id, 'timecreated' => time()]);
        $DB->execute(
            'UPDATE {' . self::T . '} SET received = received + 1, lastreceived = :t WHERE id = :id',
            ['t' => max($time, (int) $entry->lastreceived), 'id' => $entry->id]
        );
        $token = households::new_link($household, 'entry');
        $transaction->allow_commit();
        return [households::get($householdid, (int) $centre->id), true, $token];
    }

    /**
     * A family that signed up on the form of an entry, already kept: it is counted.
     *
     * @param \stdClass $entry
     */
    public static function counted(\stdClass $entry): void {
        global $DB;
        $DB->execute(
            'UPDATE {' . self::T . '} SET received = received + 1, lastreceived = :t WHERE id = :id',
            ['t' => time(), 'id' => $entry->id]
        );
    }

    /**
     * Reads the answers into a family: who writes, how to answer them, the child, what it said and where it came from.
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
            'incentre' => 0, 'heardfrom' => null, 'place' => [], 'time' => 0, 'externalid' => ''];
        $detail = [];
        $yes = fn(string $v) => (bool) preg_match('/^(s[ií]|yes|sim|true|1|x|acepto|de acuerdo|ok|agree|concordo|✓)/iu', $v);
        foreach ($answers as $question => $value) {
            $value = trim(preg_replace('/\s+/u', ' ', clean_param(is_array($value) ? implode(', ', $value) :
                (string) $value, PARAM_TEXT)));
            if ($value === '') {
                continue;
            }
            $role = $roles[$question] ?? null;
            $line = \core_text::substr(trim((string) $question), 0, 120) . ': ' . \core_text::substr($value, 0, 1000);
            switch ($role) {
                case 'email':
                    $email = households::email($value);
                    if ($email && $r->email === null) {
                        $r->email = $email;
                    } else if (!$email) {
                        $r->lines[] = $line;
                    }
                    break;
                case 'phone':
                    $phone = phone::normalise(preg_replace('/^p:/i', '', $value), $centre->country ?? null);
                    if ($phone && $r->phone === null) {
                        $r->phone = $phone;
                    } else if (!$phone) {
                        $r->lines[] = $line;
                    }
                    break;
                case 'firstname':
                    $r->firstname = $r->firstname ?: \core_text::substr($value, 0, 100);
                    break;
                case 'surname':
                    $r->lastname = $r->lastname ?: \core_text::substr($value, 0, 100);
                    break;
                case 'fullname':
                case 'guardianname':
                    if ($role === 'guardianname' && count(preg_split('/\s+/u', $value)) < 2) {
                        // Who writes («Madre», «Padre»), not a name.
                        $r->lines[] = $line;
                    } else {
                        $r->fullname = $r->fullname ?: $value;
                    }
                    break;
                case 'childname':
                    $r->child = $r->child ?: $value;
                    break;
                case 'grade':
                    $offerid = self::offer((int) $centre->id, $value);
                    if ($offerid && !$r->offerid) {
                        $r->offerid = $offerid;
                    } else {
                        $r->lines[] = $line;
                    }
                    break;
                case 'notes':
                    $r->lines[] = $value;
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
                case 'date':
                    $r->time = $r->time ?: (int) (strtotime($value) ?: recognise::date($value));
                    break;
                case 'platform':
                    $detail['platform'] = ['ig' => 'Instagram', 'fb' => 'Facebook', 'an' => 'Audience Network',
                        'msg' => 'Messenger', 'wa' => 'WhatsApp'][strtolower($value)] ?? $value;
                    break;
                case 'campaign':
                case 'adset':
                case 'ad':
                case 'form':
                    $detail[$role] = $value;
                    break;
                case 'ids':
                    $r->externalid = $r->externalid ?: $value;
                    break;
                default:
                    if ($role !== null && in_array($role, self::DROPPED, true)) {
                        break;
                    }
                    $r->lines[] = $line;
            }
        }
        if ($r->firstname === '' && $r->fullname !== '') {
            $words = preg_split('/\s+/u', $r->fullname);
            $r->firstname = \core_text::substr(array_shift($words), 0, 100);
            $r->lastname = $r->lastname ?: \core_text::substr(implode(' ', $words), 0, 100);
        }
        $r->child = self::child_name($r->child, $r->lastname);
        $r->detail = implode(' · ', array_filter([$detail['platform'] ?? '', $detail['campaign'] ?? '',
            $detail['adset'] ?? '', $detail['ad'] ?? '', $detail['form'] ?? '']));
        $r->consents = array_values(array_unique($r->consents));
        return $r;
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
     * @return int 0 if none is clear.
     */
    public static function offer(int $centreid, string $value): int {
        $want = grades::plain($value);
        $age = grades::age($value);
        $found = [];
        $sameage = [];
        // Latest school year first.
        foreach (centres::offers($centreid, true) as $offer) {
            $name = grades::plain((string) $offer->name);
            if ($name === $want) {
                return (int) $offer->id;
            }
            if ($age !== null && grades::age((string) $offer->name) === $age) {
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
                $out = [];
                foreach (array_slice($sheet['rows'], $at + 1) as $values) {
                    $item = [];
                    foreach ($row as $i => $header) {
                        if (trim((string) $header) !== '' && isset($values[$i]) && trim((string) $values[$i]) !== '') {
                            $item[(string) $header] = (string) $values[$i];
                        }
                    }
                    if ($item) {
                        $out[] = $item;
                    }
                }
                return [$out, array_values(array_filter(array_map('strval', $row), fn($h) => trim($h) !== ''))];
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
        $mail = inbound::parse($raw);
        return self::receive($entry, inbound::answers($mail), $mail['messageid'], $mail['time'] ?: null, $past);
    }

    /**
     * Erases an entry; the families that came through it stay, with their history.
     *
     * @param \stdClass $entry
     */
    public static function delete(\stdClass $entry): void {
        global $DB;
        $DB->delete_records(self::ARRIVALS, ['entryid' => $entry->id]);
        $DB->delete_records(self::T, ['id' => $entry->id]);
    }
}
