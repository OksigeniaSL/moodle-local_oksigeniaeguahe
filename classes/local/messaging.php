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
 * Telegram and WhatsApp, with Oksigenia Marandu when the site has it: the family joins from its page, gets the same
 * notices as by email, and what it writes reaches the centre in one of two ways the centre chooses. With «notices
 * only», the bot answers where to talk to the centre and nobody has to read it; with «notices and conversation», it
 * comes into the record of the family and waits in the inbox of the admissions team. Without Marandu, nothing of this
 * shows and nothing fails.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class messaging {
    /** @var string */
    const COMPONENT = 'local_oksigeniaeguahe';

    /** @var string[] What a centre does with Telegram and WhatsApp. */
    const MODES = ['off', 'notices', 'conversation'];

    /** @var array The notice of Marandu for each mail to a family. */
    const NOTICES = ['confirmation' => 'visit_confirmed', 'reminder' => 'visit_reminder', 'invitation' => 'visit_invitation',
        'request' => 'request_received', 'received' => 'message_received', 'reply' => 'answer_waiting',
        'change' => 'visit_changed', 'thanks' => 'visit_thanks'];

    /** @var int[] The families a notice goes to by email alone in this request: on their phones it did not go out. */
    public static $bymail = [];

    /** @var array[] The messages kept in this request, by their id: [householdid, centreid]. */
    protected static $heard = [];

    /**
     * Forgets the messages kept in this request: Marandu hands each one to every join within a single request, so a
     * process that lives longer (the tests, a worker) starts each message afresh.
     */
    public static function forget_heard(): void {
        self::$heard = [];
    }

    /**
     * Whether the site has Marandu.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists('\local_oksigeniamarandu\api') && get_config('local_oksigeniamarandu', 'version');
    }

    /**
     * What a centre does with Telegram and WhatsApp: off without Marandu.
     *
     * @param \stdClass $centre
     * @return string One of MODES.
     */
    public static function mode(\stdClass $centre): string {
        if (!self::available()) {
            return 'off';
        }
        $mode = (string) ($centre->messaging ?? '');
        return in_array($mode, self::MODES, true) ? $mode : 'notices';
    }

    /**
     * The links for a family to join on each channel the site has ready, or the channels it already joined.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return array [links: channel, name, url; joined: whether it already gets them]
     */
    public static function join(\stdClass $household, \stdClass $centre): array {
        if (self::mode($centre) === 'off') {
            return [[], false];
        }
        try {
            if (\local_oksigeniamarandu\api::has_active_contact(self::COMPONENT, (int) $household->id)) {
                return [[], true];
            }
            $links = [];
            $code = null;
            foreach (\local_oksigeniamarandu\api::channels() as $channel => $info) {
                if (empty($info['ready'])) {
                    continue;
                }
                $code = $code ?? \local_oksigeniamarandu\api::invite(self::COMPONENT, (int) $household->id);
                $url = \local_oksigeniamarandu\api::join_url($channel, $code);
                if ($url) {
                    $links[] = ['channel' => $channel, 'name' => (string) $info['name'], 'url' => $url];
                }
            }
            return [$links, false];
        } catch (\Throwable $e) {
            return [[], false];
        }
    }

    /**
     * The word that stops the notices, as the site has it in Marandu.
     *
     * @return string
     */
    public static function optout_word(): string {
        try {
            return class_exists('\local_oksigeniamarandu\local\keywords') ?
                \local_oksigeniamarandu\local\keywords::first('optoutkeywords') : 'BAJA';
        } catch (\Throwable $e) {
            return 'BAJA';
        }
    }

    /**
     * Whether a family gets the notices on its phone.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return bool
     */
    public static function joined(\stdClass $household, \stdClass $centre): bool {
        if (self::mode($centre) === 'off') {
            return false;
        }
        try {
            return \local_oksigeniamarandu\api::has_active_contact(self::COMPONENT, (int) $household->id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether a notice reaches a family on some phone it joined with: Marandu knows whether the notice is on there, whether
     * that phone said no to admissions and whether WhatsApp lets it out (its 24 hours, its template). Before it says so, a
     * phone joined is all there is to know.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $kind A kind of familymail.
     * @return bool
     */
    public static function reaches(\stdClass $household, \stdClass $centre, string $kind): bool {
        if (!isset(self::NOTICES[$kind]) || !self::joined($household, $centre)) {
            return false;
        }
        try {
            if (method_exists('\local_oksigeniamarandu\api', 'item_reaches')) {
                return (bool) \local_oksigeniamarandu\api::item_reaches(
                    self::COMPONENT,
                    self::NOTICES[$kind],
                    (int) $household->id
                );
            }
            // At least, that the notice is on in some channel, and that every phone joined did not say no to admissions.
            $channels = array_keys(array_filter(
                \local_oksigeniamarandu\api::item_channels(self::COMPONENT, (int) $household->id),
                fn($info) => !empty($info['joined'])
            ));
            $on = !method_exists('\local_oksigeniamarandu\api', 'notices_on') || array_filter(
                $channels,
                fn($channel) => \local_oksigeniamarandu\api::notices_on(self::COMPONENT, (string) $channel)
            );
            return $on && array_filter($channels, fn($channel) => !self::said_no((int) $household->id, (string) $channel));
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Whether every phone of a family on a channel still says no to what admissions sends, as Marandu keeps it.
     *
     * @param int $householdid
     * @param string $channel whatsapp or telegram.
     * @return bool
     */
    public static function said_no(int $householdid, string $channel): bool {
        if (!self::available() || !method_exists('\local_oksigeniamarandu\api', 'optout_state')) {
            return false;
        }
        try {
            $rows = array_filter(
                \local_oksigeniamarandu\api::optout_state(self::COMPONENT, $householdid),
                fn($row) => (string) ($row['channel'] ?? '') === $channel
            );
        } catch (\Throwable $e) {
            return false;
        }
        return $rows && !array_filter($rows, fn($row) => empty($row['all']) && empty($row['scopes'][self::COMPONENT]));
    }

    /**
     * Takes back what is still waiting in Marandu for a family and is no longer true: the act called off or moved, the family
     * that does not come or erased its data.
     *
     * @param int $householdid
     * @param string[] $kinds Kinds of familymail; empty, all of them.
     */
    public static function withdraw(int $householdid, array $kinds = []): void {
        if (!self::available() || !method_exists('\local_oksigeniamarandu\api', 'notices_cancel')) {
            return;
        }
        try {
            \local_oksigeniamarandu\api::notices_cancel(self::COMPONENT, $householdid, array_values(array_intersect_key(
                self::NOTICES,
                array_flip($kinds)
            )));
        } catch (\Throwable $e) {
            debugging('Eguahe: a notice in the queue was not taken back: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * For how long a notice is worth sending: never after what it speaks of, and that of the day before only that day.
     *
     * @param string $kind
     * @param \stdClass|null $signup
     * @param int $now
     * @return int Seconds.
     */
    public static function validfor(string $kind, ?\stdClass $signup, int $now): int {
        $until = $now + 3 * DAYSECS;
        if ($signup && !empty($signup->timestart) && in_array($kind, ['confirmation', 'reminder', 'invitation', 'change'], true)) {
            $until = min($until, (int) $signup->timestart);
            if ($kind === 'reminder') {
                $until = min($until, usergetmidnight($now, \core_date::get_server_timezone()) + DAYSECS);
            }
        }
        return max(15 * MINSECS, $until - $now);
    }

    /**
     * The same notice the family gets by email, on the phones it joined with, with the button to its page.
     *
     * @param string $kind A kind of familymail.
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass|null $signup
     * @param string $lang
     * @param string $text What the team wrote, for a reply.
     * @return int How many phones it was queued for.
     */
    public static function notify(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        ?\stdClass $signup,
        string $lang,
        string $text = ''
    ): int {
        if (
            !isset(self::NOTICES[$kind]) || !empty($centre->practice) || !writeby::phone_ok($household, $kind) ||
                !self::reaches($household, $centre, $kind) || (in_array($kind, actmail::KINDS, true) && actmail::is($signup))
        ) {
            // Of an act that is not a visit neither: the words of these notices are of one (actmail, by email).
            return 0;
        }
        // The notice goes whole in one language (Marandu 0.26.0): the family's, if the site has it, else the site's;
        // its values are written in that same one. Their page keeps the language of the family.
        $pagelang = $lang;
        $haslang = method_exists('\local_oksigeniamarandu\api', 'language');
        if ($haslang) {
            $lang = \local_oksigeniamarandu\api::language($lang);
        }
        $name = format_string($centre->name);
        $when = '';
        if ($signup) {
            $parts = publicpage::date_parts((int) $signup->timestart, $lang);
            $when = $parts['long'] . ', ' . $parts['hour'];
        }
        $host = '';
        if ($signup && !empty($signup->hosts)) {
            $hosts = centres::hosts((int) $centre->id, array_filter(array_map('intval', explode(',', (string) $signup->hosts))));
            $host = $hosts[0]->name ?? '';
        }
        if (in_array((int) $household->id, self::$bymail, true)) {
            // It did not go out on any phone: now it goes by email alone.
            return 0;
        }
        $params = [
            'confirmation' => [$name, $when],
            'reminder' => [$name, $when],
            'invitation' => [$name, $when, $host !== '' ? $host : publiclang::str('visit_guide_team', $lang)],
            'request' => [$name],
            'received' => [$name],
            'reply' => [$name, \core_text::substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 900)],
            'change' => [$name, $signup ? panel_eventform::message($household, $centre, $signup, $lang)['when'] : ''],
            'thanks' => [$name, $host !== '' ? $host : publiclang::str('visit_guide_team', $lang)],
        ][$kind];
        try {
            $url = new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => households::new_link($household, 'notice'),
                'idioma' => $pagelang]);
            $args = [self::COMPONENT, self::NOTICES[$kind], $params, $url, (int) $household->id, null, false,
                self::validfor($kind, $signup, time())];
            return \local_oksigeniamarandu\api::notify(...$args, ...($haslang ? ['lang' => $lang] : []));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The conversation of the chat of Marandu already open with the family, when there is one (Marandu 0.52): where to
     * answer it, so it is never answered twice.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return string|null
     */
    public static function conversation_url(\stdClass $household, \stdClass $centre): ?string {
        if (
            self::mode($centre) === 'off' || !empty($centre->practice) ||
                !method_exists('\local_oksigeniamarandu\api', 'conversation_url')
        ) {
            return null;
        }
        try {
            $url = \local_oksigeniamarandu\api::conversation_url(self::COMPONENT, (int) $household->id);
            return $url ? $url->out(false) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var int The letters of an answer that fit inside a notice, when WhatsApp no longer allows a message and Marandu does not say. */
    const INNOTICE = 200;

    /**
     * The letters of an answer that fit inside a notice.
     *
     * @return int
     */
    public static function innotice(): int {
        if (method_exists('\local_oksigeniamarandu\api', 'value_limit')) {
            try {
                return (int) \local_oksigeniamarandu\api::value_limit(self::COMPONENT, self::NOTICES['reply']) ?: self::INNOTICE;
            } catch (\Throwable $e) {
                return self::INNOTICE;
            }
        }
        return self::INNOTICE;
    }

    /** @var string Why the last answer by phone did not go: long (it does not fit in a notice) or empty. */
    public static $why = '';

    /**
     * Whether an answer by WhatsApp goes now as a message, or inside a notice, with the letters that fit in it: after 24 hours
     * without the family writing there.
     *
     * @param \stdClass $household
     * @param int $now
     * @return bool
     */
    public static function window_open(\stdClass $household, int $now): bool {
        global $DB;
        return $DB->record_exists_select(
            households::CONTACTS,
            "householdid = ? AND direction = 'in' AND channel = 'whatsapp' AND timecreated > ?",
            [$household->id, $now - DAYSECS]
        );
    }

    /**
     * What the team answers from the household, on the phones the family joined with: as a message when it can, or
     * as the notice with the answer inside when WhatsApp no longer allows a message. Never cut: an answer that does not fit
     * in the notice does not go, and the team is told.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $text
     * @return string|null The channel it went by, or null if it could not go.
     */
    public static function reply(\stdClass $household, \stdClass $centre, string $text): ?string {
        self::$why = '';
        if (!empty($centre->practice) || !writeby::may_send($household, 'replyphone') || !self::joined($household, $centre)) {
            return null;
        }
        $lang = $household->lang ?: $centre->lang;
        try {
            $url = new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => households::new_link($household, 'reply'),
                'idioma' => $lang]);
            $closed = null;
            // To whoever wrote last, even after a no to admissions (decided); without it, to the whole household, where
            // Marandu skips the chats that said no.
            $to = self::last_writer($household);
            $lines = \local_oksigeniamarandu\api::send_text(self::COMPONENT, (int) $household->id, $text, $url, $to);
            if ($to !== null && !array_filter($lines, fn($l) => in_array($l['status'] ?? '', ['sent', 'closed'], true))) {
                // That person is not joined to this household any more: to the household, where Marandu skips who said no.
                $lines = \local_oksigeniamarandu\api::send_text(self::COMPONENT, (int) $household->id, $text, $url);
            }
            foreach ($lines as $line) {
                if (($line['status'] ?? '') === 'sent') {
                    return (string) $line['channel'];
                }
                if (($line['status'] ?? '') === 'closed') {
                    $closed = (string) $line['channel'];
                }
            }
            if ($closed && \core_text::strlen(trim(preg_replace('/\s+/u', ' ', $text))) > self::innotice()) {
                self::$why = 'long';
                return null;
            }
            $queued = $closed ? self::notify('reply', $household, $centre, null, $lang, $text) : 0;
            if ($queued) {
                notices::log($household, 'reply', null, 'phone', notices::onphone(), notices::pieces($queued));
                return $closed;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Who of the family wrote last on WhatsApp or Telegram, as Marandu gave it, to answer that person.
     *
     * @param \stdClass $household
     * @return string|null
     */
    public static function last_writer(\stdClass $household): ?string {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id, address FROM {' . households::CONTACTS . '}
              WHERE householdid = :h AND direction = :in AND address IS NOT NULL
           ORDER BY timecreated DESC, id DESC',
            ['h' => $household->id, 'in' => 'in'],
            0,
            1
        );
        $row = reset($rows);
        return $row ? (string) $row->address : null;
    }

    /**
     * What a family writes on Telegram or WhatsApp to the code of its household.
     *
     * @param \local_oksigeniamarandu\hook\message_received $hook
     */
    public static function message_received($hook): void {
        if ($hook->get_component() !== self::COMPONENT) {
            return;
        }
        $household = households::get($hook->get_itemid(), 0);
        $centre = $household ? centres::get((int) $household->centreid) : null;
        if (!$household || !$centre) {
            return;
        }
        $lang = $household->lang ?: $centre->lang;
        if (self::mode($centre) !== 'conversation') {
            if (method_exists($hook, 'is_attended') && $hook->is_attended()) {
                // Its team does read that number, in its chat: it is for them, and the bot says nothing.
                return;
            }
            // Notices only: nobody reads it, so the bot says where to talk to the centre.
            $ways = array_filter([(string) ($centre->phone ?? ''), (string) ($centre->email ?? '')]);
            $hook->set_reply(publiclang::str($ways ? 'msg_notices_only' : 'msg_notices_only_page', $lang, (object) [
                'centre' => format_string($centre->name), 'ways' => implode(' · ', $ways)]));
            return;
        }
        $text = trim((string) $hook->get_text());
        if ($text === '') {
            $text = get_string('msg_kind_' . (in_array($hook->get_kind(), ['image', 'audio', 'video', 'document', 'sticker',
                'location', 'contact'], true) ? $hook->get_kind() : 'other'), self::COMPONENT);
        }
        $channel = $hook->get_channel() === 'whatsapp' ? 'whatsapp' : 'telegram';
        // Who wrote, to answer that person (Marandu 0.51.1): «+34600…» or «tg:<chat>».
        $address = method_exists($hook, 'get_address') ? (string) $hook->get_address() : '';
        $time = $hook->get_time() ?: time();
        // A phone joined to two households (a grandmother of two families, maybe of two centres) is heard once for each:
        // Marandu goes from the newest join to the oldest. The message goes only to the first that keeps it; the others
        // get a line that it came, never its words.
        $key = sha1(($hook->get_messageid() !== '' ? $hook->get_messageid() : $address . '|' . $time . '|' . $text));
        if (isset(self::$heard[$key])) {
            [$otherid, $othercentre] = self::$heard[$key];
            $other = $othercentre === (int) $household->centreid ? households::get($otherid, $othercentre) : null;
            $words = get_string('channel_' . $channel, self::COMPONENT);
            // Never the name of the other family: it would stay here when that one erases its data. Where its words are, yes.
            households::note($household, 0, $other ?
                get_string('msg_elsewhere_same', self::COMPONENT, (object) ['channel' => $words,
                    'url' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $other->id]))->out(false)]) :
                get_string('msg_elsewhere', self::COMPONENT, $words));
            $hook->set_handled();
            return;
        }
        households::received($household, $channel, $text, true, $time, 0, '', $address);
        self::$heard[$key] = [(int) $household->id, (int) $household->centreid];
        $hook->set_handled();
    }
}
