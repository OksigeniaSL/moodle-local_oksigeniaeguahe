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
 * How the letters of a centre to its families go out: by the mail of the site, as everything else of it, or by a service of
 * sending of its own, with its own address and domain, by SMTP (any) or by the API of Mailjet. Never with tracking: the links
 * as they are and no pixel; what is opened, the page counts. A service is used only once a letter of trial went by it; until
 * then, and whenever it is changed, the letters go by the mail of the site. What comes back, also by the events of the
 * service (mailjet.php).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mailer {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The ways: the mail of the site, any SMTP, the API of Mailjet. */
    const KINDS = ['site', 'smtp', 'mailjet'];

    /** @var string The API of Mailjet that sends. */
    const API = 'https://api.mailjet.com/v3.1/send';

    /** @var string[] How an SMTP is spoken to. */
    const SECURE = ['tls', 'ssl', 'none'];

    /**
     * What a centre said of its way, secrets included: never shown again once kept.
     *
     * @param int $centreid
     * @return array kind, from, fromname, host, port, secure, user, pass, key, secret, hook, on, tested, error.
     */
    public static function settings(int $centreid): array {
        $saved = json_decode((string) get_config(self::C, 'mailer_' . $centreid), true);
        return (is_array($saved) ? $saved : []) + ['kind' => 'site', 'from' => '', 'fromname' => '', 'host' => '', 'port' => 587,
            'secure' => 'tls', 'user' => '', 'pass' => '', 'key' => '', 'secret' => '', 'hook' => '', 'on' => 0, 'tested' => 0,
            'error' => ''];
    }

    /**
     * Keeps what a centre said.
     *
     * @param int $centreid
     * @param array $settings
     */
    protected static function keep(int $centreid, array $settings): void {
        set_config('mailer_' . $centreid, json_encode($settings), self::C);
    }

    /**
     * The way the letters of a centre go now: its service when a trial went by it; if not, the mail of the site.
     *
     * @param \stdClass $centre
     * @return string One of KINDS.
     */
    public static function way(\stdClass $centre): string {
        if (!empty($centre->practice) || empty($centre->id)) {
            return 'site';
        }
        $s = self::settings((int) $centre->id);
        return $s['kind'] !== 'site' && !empty($s['on']) && in_array($s['kind'], self::KINDS, true) ? $s['kind'] : 'site';
    }

    /**
     * The address and the name the letters of a service go from.
     *
     * @param \stdClass $centre
     * @return string[] [address, name]
     */
    public static function from(\stdClass $centre): array {
        $s = self::settings((int) $centre->id);
        $name = trim((string) $s['fromname']) !== '' ? trim((string) $s['fromname']) : format_string($centre->name);
        return [(string) $s['from'], str_replace(["\r", "\n", '"'], ' ', $name)];
    }

    /**
     * A mailer of its own for the SMTP of the centre: never the one of the site, which goes on as it was.
     *
     * @param \stdClass $centre
     * @return \moodle_phpmailer
     */
    public static function smtp(\stdClass $centre): \moodle_phpmailer {
        global $CFG;
        require_once($CFG->libdir . '/phpmailer/moodle_phpmailer.php');
        $s = self::settings((int) $centre->id);
        $mail = new \moodle_phpmailer();
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = (string) $s['host'];
        $mail->Port = (int) $s['port'];
        $mail->SMTPAuth = (string) $s['user'] !== '';
        $mail->Username = (string) $s['user'];
        $mail->Password = (string) $s['pass'];
        $mail->SMTPSecure = in_array($s['secure'], ['tls', 'ssl'], true) ? $s['secure'] : '';
        $mail->SMTPAutoTLS = $s['secure'] !== 'none';
        $mail->Timeout = 20;
        // No tracking, said to the services that would add it (Mailjet reads these; any other leaves them).
        $mail->addCustomHeader('X-Mailjet-TrackOpen', '0');
        $mail->addCustomHeader('X-Mailjet-TrackClick', '0');
        return $mail;
    }

    /**
     * A letter as the API of Mailjet takes it: with its pictures inside, the address of the centre to answer to, unsubscribing
     * with one click when it has it, and no tracking.
     *
     * @param \stdClass $centre
     * @param string $email
     * @param string $subject
     * @param string $html
     * @param string $text
     * @param array $images A list of data, cid, name, mime.
     * @param string $unsubscribe
     * @return array
     */
    public static function message(
        \stdClass $centre,
        string $email,
        string $subject,
        string $html,
        string $text,
        array $images = [],
        string $unsubscribe = ''
    ): array {
        [$from, $name] = self::from($centre);
        $message = ['From' => ['Email' => $from, 'Name' => $name], 'To' => [['Email' => $email]],
            'Subject' => str_replace(["\r", "\n"], ' ', $subject), 'TextPart' => $text,
            'TrackOpens' => 'disabled', 'TrackClicks' => 'disabled', 'CustomID' => 'eguahe-' . (int) $centre->id];
        if ($html !== '') {
            $message['HTMLPart'] = $html;
            $inside = [];
            foreach ($images as $image) {
                // Only the pictures the letter shows, as by the mail of the site.
                if (strpos($html, 'cid:' . $image['cid']) !== false) {
                    $inside[] = ['ContentType' => $image['mime'], 'Filename' => $image['name'], 'ContentID' => $image['cid'],
                        'Base64Content' => base64_encode($image['data'])];
                }
            }
            if ($inside) {
                $message['InlinedAttachments'] = $inside;
            }
        }
        if (!empty($centre->email) && validate_email($centre->email)) {
            $message['ReplyTo'] = ['Email' => $centre->email, 'Name' => $name];
        }
        if ($unsubscribe !== '') {
            $message['Headers'] = ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
        } else if (($mailto = mail::mailto($centre)) !== '') {
            // Without it, Mailjet puts its own, and stops the address when it is pressed (mail::mailto()).
            $message['Headers'] = ['List-Unsubscribe' => '<' . $mailto . '>'];
        }
        return $message;
    }

    /**
     * Sends a letter by the API of Mailjet.
     *
     * @param \stdClass $centre
     * @param array $message From message().
     * @return string Empty when it went; what Mailjet said, if not.
     */
    public static function by_api(\stdClass $centre, array $message): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $s = self::settings((int) $centre->id);
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader(['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($s['key'] . ':' . $s['secret'])]);
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        try {
            $raw = (string) $curl->post(self::API, json_encode(['Messages' => [$message]]));
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $answer = json_decode($raw, true);
        $one = is_array($answer) ? ($answer['Messages'][0] ?? []) : [];
        if (($one['Status'] ?? '') === 'success') {
            return '';
        }
        $error = $one['Errors'][0]['ErrorMessage'] ?? ($answer['ErrorMessage'] ?? ($curl->get_errno() ? $curl->error : $raw));
        return \core_text::substr(clean_param((string) $error, PARAM_TEXT), 0, 255) ?: 'error';
    }

    /**
     * A person of the centre chose a way: kept, and in use only once a trial goes by it.
     *
     * @param \stdClass $centre
     * @param string $kind One of KINDS.
     */
    public static function choose(\stdClass $centre, string $kind): void {
        if (!in_array($kind, self::KINDS, true)) {
            return;
        }
        $s = self::settings((int) $centre->id);
        if ($s['kind'] !== $kind) {
            $s['kind'] = $kind;
            $s['on'] = 0;
            $s['error'] = '';
        }
        self::keep((int) $centre->id, $s);
    }

    /**
     * What a person wrote for the way of its centre, kept, and tried with a letter to that person: in use only if it went.
     * Secrets left empty keep the ones before.
     *
     * @param \stdClass $centre
     * @param array $in from, fromname, host, port, secure, user, pass, key, secret.
     * @param \stdClass $user Who tries it: the letter goes to their address.
     * @return string Empty when it works; why not, if not.
     */
    public static function save(\stdClass $centre, array $in, \stdClass $user): string {
        $c = self::C;
        $s = self::settings((int) $centre->id);
        if ($s['kind'] === 'site') {
            return '';
        }
        $s['from'] = trim((string) ($in['from'] ?? $s['from']));
        $s['fromname'] = \core_text::substr(trim((string) ($in['fromname'] ?? $s['fromname'])), 0, 100);
        foreach (['host', 'user', 'key'] as $field) {
            $s[$field] = trim((string) ($in[$field] ?? $s[$field]));
        }
        $s['port'] = max(1, min(65535, (int) ($in['port'] ?? $s['port']) ?: 587));
        $s['secure'] = in_array($in['secure'] ?? '', self::SECURE, true) ? $in['secure'] : $s['secure'];
        foreach (['pass', 'secret'] as $field) {
            if (trim((string) ($in[$field] ?? '')) !== '') {
                $s[$field] = trim((string) $in[$field]);
            }
        }
        $s['hook'] = $s['hook'] ?: random_string(32);
        $s['on'] = 0;
        $missing = !validate_email($s['from']) ? 'mailer_err_from' : ($s['kind'] === 'smtp' && $s['host'] === '' ? 'mailer_err_host' :
            ($s['kind'] === 'mailjet' && ($s['key'] === '' || $s['secret'] === '') ? 'mailer_err_key' : ''));
        if ($missing !== '') {
            $s['error'] = get_string($missing, $c);
            self::keep((int) $centre->id, $s);
            return $s['error'];
        }
        self::keep((int) $centre->id, $s);
        $error = self::trial($centre, $s['kind'], (string) $user->email);
        $s['error'] = $error;
        $s['on'] = $error === '' ? 1 : 0;
        $s['tested'] = $error === '' ? time() : (int) $s['tested'];
        self::keep((int) $centre->id, $s);
        return $error === '' ? '' : get_string('mailer_err_trial', $c, $error);
    }

    /**
     * A letter of trial, by a way, to a person of the centre.
     *
     * @param \stdClass $centre
     * @param string $kind
     * @param string $email
     * @return string Empty when it went; why not, if not.
     */
    protected static function trial(\stdClass $centre, string $kind, string $email): string {
        $c = self::C;
        if (!validate_email($email)) {
            return get_string('mailer_err_noaddress', $c);
        }
        $name = format_string($centre->name);
        $subject = get_string('mailer_trial_subject', $c, $name);
        $text = get_string('mailer_trial_text', $c, $name);
        $html = \html_writer::tag('p', s($text));
        return mail::send_rich($centre, $email, $subject, $html, $text, [], '', $kind) ? '' :
            (self::$last !== '' ? self::$last : get_string('mailer_err_unknown', $c));
    }

    /** @var string What the last letter by a service said when it did not go. */
    public static $last = '';

    /**
     * The address of the events of Mailjet of a centre: what comes back reaches its families.
     *
     * @param \stdClass $centre
     * @return string Empty before the way is kept.
     */
    public static function hook_url(\stdClass $centre): string {
        $s = self::settings((int) $centre->id);
        return $s['hook'] !== '' ? (new \moodle_url('/local/oksigeniaeguahe/mailjet.php', ['c' => $centre->id, 'k' => $s['hook']]))->out(false) : '';
    }

    /**
     * Whether a call to the events is of a centre.
     *
     * @param int $centreid
     * @param string $key
     * @return bool
     */
    public static function hook_ok(int $centreid, string $key): bool {
        $s = self::settings($centreid);
        return $s['hook'] !== '' && $key !== '' && hash_equals((string) $s['hook'], $key);
    }

    /**
     * The events of Mailjet of the letters of a centre: what did not arrive is marked in the file of the family, as the
     * returned mail read from the mailbox; the rest is left.
     *
     * @param int $centreid The centre whose address they came to: only addresses of its families are marked.
     * @param array[] $events As Mailjet posts them: event, email, time, hard_bounce, error, error_related_to, comment.
     * @param int $now
     * @return int How many adults were marked for the first time.
     */
    public static function events(int $centreid, array $events, int $now): int {
        global $DB;
        $new = 0;
        foreach ($events as $event) {
            if (is_array($event) && ($event['event'] ?? '') === 'unsub') {
                $new += self::unsubscribed($centreid, (string) ($event['email'] ?? ''));
                continue;
            }
            $dsn = is_array($event) ? self::as_returned($event) : null;
            if (
                $dsn && $DB->record_exists_select(
                    households::ADULTS,
                    'centreid = :centre AND ' . $DB->sql_equal('email', ':email', false),
                    ['centre' => $centreid, 'email' => $dsn['to'][0]]
                )
            ) {
                $new += bounces::found($dsn, $now);
            }
        }
        return $new;
    }

    /**
     * «Unsubscribe» pressed in the mail program, on the header Mailjet puts on every letter it sends (and puts in place of
     * one of ours): as the one-click way out of Eguahe (baja.php), that family is no longer written to by email, and the
     * other ways stay. Without this, Mailjet would stop the address and the file of the family would not know.
     *
     * @param int $centreid
     * @param string $email
     * @return int How many families of the centre had that address and stopped their email now.
     */
    public static function unsubscribed(int $centreid, string $email): int {
        global $DB;
        $email = \core_text::strtolower(trim($email));
        $centre = centres::get($centreid);
        if (!validate_email($email) || !$centre || !empty($centre->practice)) {
            return 0;
        }
        $ids = $DB->get_fieldset_select(
            households::ADULTS,
            'DISTINCT householdid',
            'centreid = :centre AND ' . $DB->sql_equal('email', ':email', false),
            ['centre' => $centreid, 'email' => $email]
        );
        $stopped = 0;
        foreach ($ids as $id) {
            $household = households::get((int) $id, $centreid);
            if ($household && writeby::stop_email($household, $centre)) {
                $stopped++;
            }
        }
        return $stopped;
    }

    /**
     * One event of Mailjet as a returned mail: a bounce for good (it does not exist), a full mailbox, blocked, or marked as
     * spam; a bounce that may pass, nothing.
     *
     * @param array $event
     * @return array|null As bounces::parse() gives it.
     */
    public static function as_returned(array $event): ?array {
        $email = \core_text::strtolower(trim((string) ($event['email'] ?? '')));
        $what = (string) ($event['event'] ?? '');
        if (!validate_email($email) || !in_array($what, ['bounce', 'blocked', 'spam'], true)) {
            return null;
        }
        $said = trim(implode(' ', array_filter([(string) ($event['error_related_to'] ?? ''), (string) ($event['error'] ?? ''),
            (string) ($event['comment'] ?? '')])));
        $full = (bool) preg_match('/quota|full|storage/i', $said);
        if ($what === 'spam') {
            [$kind, $status, $said] = ['refused', '5.7.1', 'spam'];
        } else if ($full) {
            [$kind, $status] = ['full', '5.2.2'];
        } else if ($what === 'bounce' && empty($event['hard_bounce'])) {
            // It may pass: Mailjet tries again, as any server.
            return null;
        } else if ($what === 'bounce' || preg_match('/preblocked|user unknown|does not exist|invalid|no such/i', $said)) {
            [$kind, $status] = ['gone', '5.1.1'];
        } else {
            [$kind, $status] = ['refused', '5.7.1'];
        }
        return ['to' => [$email], 'status' => $status, 'action' => 'failed', 'diag' => \core_text::substr(clean_param('Mailjet: ' .
            ($said !== '' ? $said : $what), PARAM_TEXT), 0, 255), 'kind' => $kind, 'subject' => '', 'sent' => (int) ($event['time'] ?? 0)];
    }

    /**
     * What the settings say of the way, in a line.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function reading(\stdClass $centre): string {
        $c = self::C;
        $s = self::settings((int) $centre->id);
        if ($s['kind'] === 'site') {
            return get_string('mailer_read_site', $c);
        }
        $name = get_string('mailer_kind_' . $s['kind'], $c);
        return !empty($s['on']) ? get_string('mailer_read_on', $c, (object) ['way' => $name, 'from' => $s['from']]) :
            get_string('mailer_read_off', $c, $name);
    }
}
