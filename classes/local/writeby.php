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
 * How a family wants the centre to write to them: by email, on WhatsApp, on Telegram (any of them, several at once), or
 * not at all. One word, whoever changes it (the family from any mail or its page, a person of the team in the file, the
 * family on its phone through Marandu), kept everywhere: mails, invitations, notices of the app and of Marandu. Email is
 * kept here; the phones are the contacts of Marandu, told of each change. What the family writes after asking not to be
 * written to is still answered, and writing again lifts it by the way it wrote.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class writeby {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] The ways a family can mark, in the order they are offered. */
    const CHANNELS = ['email', 'whatsapp', 'telegram'];

    /** @var string[] The channels of the phone, through Marandu. */
    const PHONES = ['whatsapp', 'telegram'];

    /** @var string[] Who changed it: the family, a person of the team, or the family on its phone (Marandu). */
    const SOURCES = ['family', 'team', 'messaging'];

    /** @var string[] What answers something the family did: it goes after «none» if they did it after choosing. */
    const ANSWERS = ['reply', 'confirmation', 'request', 'received'];

    /** @var string[] What goes by email even to a family that took email out while on a phone: the phone has no notice of
     * it, and they need to know (a visit they signed up to, cancelled). */
    const MUSTKNOW = ['cancelled'];

    /** @var int[] The households a person of the team chose to write to anyway, in this request. */
    protected static $anyway = [];

    /** @var array The line icons of the page (Jujol), drawn with the stroke of the text. */
    const ICONS = [
        'email' => '<path d="M3.5 6.5h17v11h-17z"/><path d="M4 7l8 6 8-6"/>',
        'whatsapp' => '<path d="M4.5 19.5l1.2-3.6A7.8 7.8 0 1 1 8.8 19z"/><path d="M9 9.2c.2 2.6 2.2 4.6 4.8 4.8l.9-1.2 ' .
            '1.6.8-.4 1.5c-3.6.4-7.4-3.4-7-7l1.5-.4.8 1.6z"/>',
        'telegram' => '<path d="M20.5 4.5L3.5 11l5.5 2 2 6 3-4 4.5 3.5z"/><path d="M9 13l8-6"/>',
        'none' => '<circle cx="12" cy="12" r="8.5"/><path d="M6.5 17.5l11-11"/>',
        'home' => '<path d="M4 11l8-6.5 8 6.5"/><path d="M6 9.5V19.5h12V9.5"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    ];

    /**
     * A person of the team writes to a family that asked not to be written to, after being told: only this message.
     *
     * @param int $householdid
     */
    public static function anyway(int $householdid): void {
        self::$anyway[$householdid] = true;
    }

    /**
     * What the family marked: null if it never chose (everything goes as it gave its permissions), an empty list if it
     * asked not to be written to, else its ways in their order.
     *
     * @param \stdClass $household
     * @return string[]|null
     */
    public static function chosen(\stdClass $household): ?array {
        $raw = trim((string) ($household->writeby ?? ''));
        if ($raw === '') {
            return null;
        }
        if ($raw === 'none') {
            return [];
        }
        return array_values(array_intersect(self::CHANNELS, explode(',', $raw)));
    }

    /**
     * A list of ways as it is kept: «email,whatsapp», or «none».
     *
     * @param string[] $ways
     * @return string
     */
    public static function raw(array $ways): string {
        $ways = array_values(array_intersect(self::CHANNELS, $ways));
        return $ways ? implode(',', $ways) : 'none';
    }

    /**
     * Whether the family asked not to be written to any more.
     *
     * @param \stdClass $household
     * @return bool
     */
    public static function silent(\stdClass $household): bool {
        return self::chosen($household) === [];
    }

    /**
     * Whether the family wrote to the centre after asking not to be written to: then what it asked is answered.
     *
     * @param \stdClass $household
     * @return bool
     */
    public static function wrote_after(\stdClass $household): bool {
        global $DB;
        return $DB->record_exists_select(
            households::CONTACTS,
            'householdid = ? AND direction = ? AND timecreated >= ?',
            [(int) $household->id, 'in', (int) ($household->writebytime ?? 0)]
        );
    }

    /**
     * Whether something of this kind may go to the family at all: always, unless it asked not to be written to; then
     * only the answer to what it did after asking, or what a person writes anyway.
     *
     * @param \stdClass $household
     * @param string $kind A kind of familymail, replyphone, or '' for anything else (a notice of the app, an invitation).
     * @return bool
     */
    public static function may_send(\stdClass $household, string $kind = ''): bool {
        if (!self::silent($household)) {
            return true;
        }
        if (in_array($kind, ['reply', 'replyphone'], true) && !empty(self::$anyway[(int) $household->id])) {
            return true;
        }
        return in_array($kind, self::ANSWERS, true) && self::wrote_after($household);
    }

    /**
     * Whether a mail of this kind goes by email: if they marked email, or never chose; without email marked, only while
     * none of the phones they marked is joined yet (until then nothing else reaches them), and what they must know.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $kind A kind of familymail.
     * @return bool
     */
    public static function email_ok(\stdClass $household, \stdClass $centre, string $kind): bool {
        if (!self::may_send($household, $kind)) {
            return false;
        }
        $ways = self::chosen($household);
        if ($ways === null || in_array('email', $ways, true) || ($kind === 'reply' && self::silent($household))) {
            return true;
        }
        if (in_array($kind, self::MUSTKNOW, true) || !array_intersect($ways, self::joined_on($household, $centre))) {
            return true;
        }
        return !isset(messaging::NOTICES[$kind]) && $kind !== 'writeby' && $kind !== 'openinvite';
    }

    /**
     * Whether a mail of a kind goes by email now, the one door for the tasks and the acts: by the permission of an adult
     * when the family never chose; by what it chose when it did (email still, while the phone it marked is not joined);
     * and what a family must know (an act called off) at its address when it chose a way, even a phone.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $kind A kind of familymail.
     * @return bool
     */
    public static function email_now(\stdClass $household, \stdClass $centre, string $kind): bool {
        $chosen = self::chosen($household);
        if (in_array($kind, self::MUSTKNOW, true) && $chosen) {
            // A family that chose how to be written to hears what it must know at its address, also if it chose a phone.
            return households::recipient($household) !== '';
        }
        return $chosen === null ? familymail::may_email($household) : self::email_ok($household, $centre, $kind);
    }

    /**
     * Whether the notice of a mail may also go to the phones of the family: not if it marked only email.
     *
     * @param \stdClass $household
     * @param string $kind
     * @return bool
     */
    public static function phone_ok(\stdClass $household, string $kind = ''): bool {
        $ways = self::chosen($household);
        return self::may_send($household, $kind) && ($ways === null || self::silent($household) ||
            (bool) array_intersect($ways, self::PHONES));
    }

    /**
     * When a child of the family was enrolled after it asked, as a family on its way, not to be written to: that word was
     * for the admissions, and is asked again, warmly, rather than carried in silence to the life of the centre.
     *
     * @param \stdClass $household
     * @return int The first enrolment after it, or 0.
     */
    public static function enrolled_since(\stdClass $household): int {
        $none = (int) ($household->writebynone ?? 0);
        if (!$none || !self::silent($household)) {
            return 0;
        }
        return self::enrolled_after($household, $none);
    }

    /**
     * When a child of the family was first enrolled after a moment, whatever they chose since.
     *
     * @param \stdClass $household
     * @param int $after
     * @return int 0 if none was.
     */
    protected static function enrolled_after(\stdClass $household, int $after): int {
        global $DB;
        return (int) $DB->get_field_select(
            households::CHILDREN,
            'MIN(stagetime)',
            'householdid = ? AND stage = ? AND stagetime >= ?',
            [(int) $household->id, 'enrolled', $after]
        );
    }

    /**
     * The ways a family can mark in a centre: email, and the channels of the phone the site has ready.
     *
     * @param \stdClass $centre
     * @return string[] Some of CHANNELS, in their order.
     */
    public static function channels(\stdClass $centre): array {
        $phones = [];
        if (messaging::mode($centre) !== 'off') {
            try {
                foreach (\local_oksigeniamarandu\api::channels() as $channel => $info) {
                    if (!empty($info['ready']) && in_array($channel, self::PHONES, true)) {
                        $phones[] = $channel;
                    }
                }
            } catch (\Throwable $e) {
                $phones = [];
            }
        }
        return array_values(array_filter(self::CHANNELS, fn($c) => $c === 'email' || in_array($c, $phones, true)));
    }

    /**
     * The channels of the phone a family is joined on.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return string[]
     */
    public static function joined_on(\stdClass $household, \stdClass $centre): array {
        if (messaging::mode($centre) === 'off' || !method_exists('\local_oksigeniamarandu\api', 'item_channels')) {
            return [];
        }
        try {
            $out = [];
            foreach (\local_oksigeniamarandu\api::item_channels(messaging::COMPONENT, (int) $household->id) as $ch => $info) {
                if (!empty($info['joined'])) {
                    $out[] = (string) $ch;
                }
            }
            return array_values(array_intersect(self::PHONES, $out));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The links that join each phone they marked and have not joined yet, from Marandu's list of the household: each
     * channel its own, with the code that says which family it is (Telegram needs no number, only the bot).
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string[] $ways The ways marked.
     * @return array channel => url
     */
    public static function join_links(\stdClass $household, \stdClass $centre, array $ways): array {
        $phones = array_values(array_intersect(self::PHONES, $ways));
        if (!$phones || messaging::mode($centre) === 'off' || !method_exists('\local_oksigeniamarandu\api', 'item_channels')) {
            return [];
        }
        $out = [];
        try {
            // Once Marandu takes a greeting, WhatsApp comes written as the family would say it, with its code at the end.
            $greets = (new \ReflectionMethod('\local_oksigeniamarandu\api', 'join_url'))->getNumberOfParameters() >= 3;
            $code = null;
            foreach (\local_oksigeniamarandu\api::item_channels(messaging::COMPONENT, (int) $household->id) as $ch => $info) {
                if (!in_array($ch, $phones, true) || !empty($info['joined']) || empty($info['url'])) {
                    continue;
                }
                $out[$ch] = (string) $info['url'];
                if ($greets && $ch === 'whatsapp') {
                    $code = $code ?? \local_oksigeniamarandu\api::invite(messaging::COMPONENT, (int) $household->id);
                    $out[$ch] = (string) (\local_oksigeniamarandu\api::join_url($ch, $code, self::greeting(
                        $household,
                        $centre
                    )) ?: $out[$ch]);
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        // Marandu lists the channels in its own order: here, in ours.
        return array_filter(array_replace(array_fill_keys($phones, null), $out));
    }

    /**
     * The greeting the family sends to join WhatsApp, in its language: «Hola, somos la familia de Leo.»; Marandu adds the
     * code at the end. Without a name, Marandu's own.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return string
     */
    public static function greeting(\stdClass $household, \stdClass $centre): string {
        $lang = (string) ($household->lang ?: ($centre->lang ?? 'es'));
        $tags = array_values(array_filter(array_map(
            [familypage::class, 'label_name'],
            array_values(households::full($household)->children)
        ), fn($n) => $n !== ''));
        return $tags ? publiclang::str('pub_writeby_greeting', $lang, familypage::join($tags, $lang)) : '';
    }

    /**
     * How they are written to now: what they marked or, if they never chose, their email if it may be written to and the
     * phones they joined.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @return string[] Some of CHANNELS; empty when nothing reaches them.
     */
    public static function now(\stdClass $household, \stdClass $centre): array {
        $ways = self::chosen($household);
        if ($ways !== null) {
            return $ways;
        }
        $phones = self::joined_on($household, $centre);
        // Email only when some adult allows it: having an address is not a yes.
        return array_values(array_merge(familymail::may_email($household) && households::recipient($household) !== '' ?
            ['email'] : [], $phones));
    }

    /**
     * Some ways said in a sentence, with the words of a language: «por correo y por WhatsApp».
     *
     * @param string[] $ways
     * @param string $lang
     * @param string $prefix The words of each way: pub_writeby_by_ or pub_writeby_note_.
     * @param string $join and or or.
     * @return string
     */
    public static function words(array $ways, string $lang, string $prefix = 'pub_writeby_by_', string $join = 'and'): string {
        $words = array_map(fn($c) => publiclang::str($prefix . $c, $lang), array_values($ways));
        if (count($words) < 2) {
            return $words[0] ?? '';
        }
        return publiclang::str('pub_writeby_' . $join . min(3, count($words)), $lang, (object) ['a' => $words[0],
            'b' => $words[1], 'c' => $words[2] ?? '']);
    }

    /**
     * Changes how they are written to: kept in the household, written as the permissions of each adult (the proof), told
     * to the team in a line of its history and to Marandu for each phone that comes in or goes out.
     *
     * @param \stdClass $household Changed in place.
     * @param string[]|string $ways The ways marked, or 'none'.
     * @param string $source One of SOURCES.
     * @param int $userid The person of the team who noted it, if one did.
     * @param int $now
     * @param string $why 'back' when it is lifted because the family wrote again.
     * @return bool Whether it changed.
     */
    public static function set(
        \stdClass $household,
        $ways,
        string $source,
        int $userid = 0,
        int $now = 0,
        string $why = ''
    ): bool {
        global $DB;
        if (!in_array($source, self::SOURCES, true)) {
            return false;
        }
        $raw = self::raw(is_array($ways) ? $ways : ($ways === 'none' ? [] : explode(',', (string) $ways)));
        if (trim((string) ($household->writeby ?? '')) === $raw) {
            return false;
        }
        $now = $now ?: time();
        $centre = centres::get((int) $household->centreid);
        $before = $centre ? self::now($household, $centre) : [];
        $changes = (object) ['id' => $household->id, 'writeby' => $raw, 'writebytime' => $now, 'writebyfrom' => $source,
            'timemodified' => $now];
        if ($raw === 'none') {
            // The last time they asked not to be written to stays, for the trail in the file.
            $changes->writebynone = $now;
        }
        $DB->update_record(households::T, $changes);
        foreach ((array) $changes as $field => $value) {
            $household->$field = $value;
        }
        $after = self::chosen($household);
        $lang = (string) ($household->lang ?: ($centre->lang ?? 'es'));
        // The permissions, adult by adult, only where they change: what each one may be written by now.
        $consent = $source === 'team' ? 'team' : 'family';
        $said = households::permissions((int) $household->id);
        foreach (households::full($household)->adults as $adult) {
            foreach (['email' => trim((string) $adult->email), 'whatsapp' => trim((string) $adult->phone)] as $channel => $has) {
                $granted = in_array($channel, $after, true);
                // A word left on a phone («BAJA») only takes away: it never gives a way the family did not mark.
                if ($granted && $source === 'messaging') {
                    continue;
                }
                if ($has !== '' && (bool) ($said[$adult->id][$channel] ?? !$granted) !== $granted) {
                    households::consent($household, (int) $adult->id, $channel, $granted, $consent, $lang, $userid);
                }
            }
        }
        // A line for the team, in the language of the centre.
        $teamlang = (string) ($centre->lang ?? '') ?: current_language();
        if ($why === 'back') {
            $line = get_string_manager()->get_string('writeby_noted_back', self::C, self::words($after, $teamlang), $teamlang);
        } else if ($after === []) {
            $line = get_string_manager()->get_string('writeby_noted_' . $source . '_none', self::C, null, $teamlang);
        } else {
            $line = get_string_manager()->get_string(
                'writeby_noted_' . $source,
                self::C,
                self::words($after, $teamlang),
                $teamlang
            );
        }
        households::note($household, $userid, $line);
        // Marandu keeps the phones: each phone taken out stops, each one marked may be written to again, and each chat
        // joined hears the change with its card. Never back to Marandu when it was Marandu that said it.
        if ($source !== 'messaging') {
            $tell = $why === 'back' ? 'mail' : $source;
            if ($after === []) {
                self::tell_messaging($household, true, $tell);
            } else {
                foreach (self::PHONES as $phone) {
                    $was = in_array($phone, $before, true) || $before === [];
                    $is = in_array($phone, $after, true);
                    if ($was !== $is || ($before === [] && $is)) {
                        self::tell_messaging($household, !$is, $tell, $phone);
                    }
                }
            }
            if ($why !== 'back') {
                self::confirm_messaging($household, $after, $before);
            }
        }
        return true;
    }

    /**
     * Tells Marandu the family asked not to be written to on a phone (or on all of them), or may be written to there
     * again, when the site has it: for the admissions only (this plugin), never for the rest of the centre.
     *
     * @param \stdClass $household
     * @param bool $out
     * @param string $source family, team or mail.
     * @param string $channel whatsapp or telegram; '' for all of them.
     */
    protected static function tell_messaging(\stdClass $household, bool $out, string $source, string $channel = ''): void {
        if (!messaging::available() || !method_exists('\local_oksigeniamarandu\api', 'set_optout')) {
            return;
        }
        try {
            $method = new \ReflectionMethod('\local_oksigeniamarandu\api', 'set_optout');
            $args = [messaging::COMPONENT, (int) $household->id, messaging::COMPONENT, $out, 'eguahe:' . $source];
            if ($channel !== '') {
                // One phone: only once Marandu can turn off one; before, only what is for all of them is said.
                if ($method->getNumberOfParameters() < 6) {
                    return;
                }
                $args[] = $channel;
            }
            \local_oksigeniamarandu\api::set_optout(...$args);
        } catch (\Throwable $e) {
            debugging('Eguahe: Marandu was not told the choice of a family: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Marandu tells each chat joined to the household what changed («Desde ahora, por aquí», «también por aquí», «Desde
     * ahora, por correo», «La puerta sigue abierta»), with its card; email is said here, by mail.
     *
     * @param \stdClass $household
     * @param string[] $now The ways after the change.
     * @param string[] $before The ways before it.
     */
    protected static function confirm_messaging(\stdClass $household, array $now, array $before): void {
        if (!messaging::available() || !method_exists('\local_oksigeniamarandu\api', 'confirm_channel')) {
            return;
        }
        try {
            \local_oksigeniamarandu\api::confirm_channel(messaging::COMPONENT, (int) $household->id, $now, $before);
        } catch (\Throwable $e) {
            debugging('Eguahe: Marandu did not confirm the change to the family: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The link to choose, on the page of the family, with the way pressed in a mail already changed.
     *
     * @param string $token A link of the family.
     * @param string $lang
     * @param string $pressed A channel, 'none', or '' for none pressed.
     * @param string $from The mail it was pressed in, to count it.
     * @return string
     */
    public static function url(string $token, string $lang, string $pressed = '', string $from = ''): string {
        return (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'action' => 'escribir', 'idioma' => $lang] +
            ($pressed !== '' ? ['por' => $pressed] : []) + ($from !== '' ? ['de' => $from] : [])))->out(false);
    }

    /**
     * The block at sight in every mail (Jujol): its title, how they are written to now and the question, the ways in a
     * row with the ones of now marked, and «Do not write to us any more» apart below, in ink, without alarm.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $token A link of the family.
     * @param string $lang
     * @param string $kind The mail.
     * @return array|null Null in the mail that confirms the choice.
     */
    public static function mail_block(\stdClass $household, \stdClass $centre, string $token, string $lang, string $kind): ?array {
        if ($kind === 'writeby' || $token === '') {
            return null;
        }
        $channels = self::channels($centre);
        $now = self::now($household, $centre);
        $button = function (string $way) use ($token, $lang, $kind, $now): array {
            $url = self::url($token, $lang, $way, $kind);
            return ['texto' => publiclang::str('pub_writeby_' . $way, $lang), 'url' => $url, 'url_texto' => $url,
                'clave' => $way, 'actual' => in_array($way, $now, true), 'esbaja' => $way === 'none'];
        };
        $only = count($channels) === 1;
        $width = $only ? '50%' : round(100 / count($channels)) . '%';
        $all = array_merge($channels, ['none']);
        return [
            'titulo' => publiclang::str('pub_writeby_heading', $lang),
            'frase' => trim(($now ? publiclang::str('pub_writeby_now', $lang, self::words($now, $lang)) . ' ' : '') .
                self::question($channels, $lang, $now)),
            'fila' => array_map(fn($c) => $button($c) + ['ancho' => $width], $only ? $all : $channels),
            'baja' => $only ? false : $button('none'),
            'botones' => array_map($button, $all),
        ];
    }

    /**
     * The question, with the ways this centre has other than the ones of now.
     *
     * @param string[] $channels
     * @param string $lang
     * @param string[] $now How they are written to now.
     * @return string
     */
    public static function question(array $channels, string $lang, array $now = []): string {
        return publiclang::str(count($channels) > 1 ? 'pub_writeby_ask_multi' : 'pub_writeby_ask_only', $lang);
    }

    /**
     * A line icon of the page.
     *
     * @param string $name
     * @return string SVG.
     */
    public static function icon(string $name): string {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
            'stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[$name] . '</svg>';
    }

    /**
     * The page to choose (Jujol). From a mail, the way pressed is asked on the wall («Shall we also write to you on
     * WhatsApp?»); on the paper, a card with how they are written to now, the ways as boxes with the pressed one already
     * changed, and its one big button; beside it, «do not write to us any more». From their page, the boxes as they are.
     * Once chosen, the wall says it, and under the rail their backpacks with a note stuck on with two strips of tape in the
     * colours of the centre, in its hand; for a phone not joined yet, the button that joins it, the message as it goes
     * written and its QR on a computer.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @param string $pressed The way pressed in a mail ('none' too), if any.
     * @param string $done The ways just chosen, as kept («email,whatsapp» or «none»), for the thanks.
     * @return array
     */
    public static function page_context(
        \stdClass $household,
        \stdClass $centre,
        string $lang,
        string $token,
        string $pressed,
        string $done
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $channels = self::channels($centre);
        $now = self::now($household, $centre);
        $full = households::full($household);
        $children = array_values(array_filter($full->children, fn($c) => familypage::label_name($c) !== ''));
        $tags = array_map([familypage::class, 'label_name'], $children);
        $names = familypage::join($tags, $lang);
        // A sentence with the names of the children, or its form without them.
        $named = fn(string $id) => $names !== '' ? $str($id, $names) : $str($id . '_nochild');
        $pageurl = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang]))->out(false);
        $context = [
            'action' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false),
            'sesskey' => sesskey(),
            'lang' => $lang,
            'ante' => $str('pub_writeby_title'),
            'pageurl' => $pageurl,
            'nowpill' => $now ? ['icon' => self::icon($now[0]), 'text' => $str('pub_writeby_pill', self::words(
                $now,
                $lang,
                'pub_writeby_name_'
            ))] : false,
            'choosing' => false,
            'none' => false,
            'done' => false,
        ];
        if ($done !== '') {
            $ways = $done === 'none' ? [] : array_values(array_intersect(self::CHANNELS, explode(',', $done)));
            // The phones marked and not joined yet: one step is left, a button for each.
            $joins = [];
            $joined = self::joined_on($household, $centre);
            foreach (self::join_links($household, $centre, $ways) as $channel => $link) {
                $query = [];
                parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
                $joins[] = ['url' => $link, 'icon' => self::icon($channel), 'label' => $str('pub_writeby_open_' . $channel),
                    'written' => (string) ($query['text'] ?? ''), 'writtenlabel' => $str('pub_writeby_written'),
                    'qr' => events::qr_of($link), 'qrline' => $str('pub_writeby_qr_' . $channel),
                    // Telegram needs no number: the button opens the bot with the code of the family.
                    'small' => $channel === 'telegram' ? $str('pub_writeby_join_small_telegram') : ''];
            }
            $parts = publicpage::date_parts(time(), $lang);
            $first = $joins ? array_values(array_intersect(self::PHONES, array_diff($ways, $joined)))[0] : '';
            if ($ways === []) {
                $context['title'] = $str('pub_writeby_done_none');
                $text = $named('pub_writeby_done_none_text');
                // A visit still to come stays: they are expected all the same, and the page says how to call it off.
                $visit = array_values(array_filter(households::full($household)->signups, fn($v) => (int) $v->timeend >= time()))[0] ??
                    null;
                if ($visit) {
                    $text .= ' ' . $str('pub_writeby_none_visit', publicpage::date_parts((int) $visit->timestart, $lang)['long']);
                }
            } else if (count($joins) > 1) {
                // Both phones to join: one step for each, one under the other.
                $context['title'] = $str('pub_writeby_done_join_many', self::words(array_diff($ways, $joined, ['email']), $lang));
                $text = $str('pub_writeby_done_join_many_text') .
                    (in_array('email', $ways, true) ? '' : ' ' . $str('pub_writeby_until_joined'));
            } else if ($joins) {
                $context['title'] = $str('pub_writeby_done_' . $first . '_join');
                $text = $str('pub_writeby_done_' . $first . '_join_text', share::from_centre($centre, $lang)) .
                    (in_array('email', $ways, true) ? '' : ' ' . $str('pub_writeby_until_joined'));
            } else {
                $context['title'] = $str('pub_writeby_done', self::words($ways, $lang));
                $text = $str(in_array('email', $ways, true) ? 'pub_writeby_done_text_mail' : 'pub_writeby_done_text_phone');
            }
            $context['done'] = [
                'pegs' => publicpage::pegs($tags ?: [''], true, null, array_map(fn($c) => ages::hanging(
                    $c,
                    centres::offers((int) $centre->id),
                    $centre
                ), $children)),
                'note' => $ways ? $str('pub_writeby_note', self::words($ways, $lang, 'pub_writeby_name_')) :
                    $str('pub_writeby_note_none'),
                'pending' => (bool) $joins,
                'sign' => format_string($centre->name) . ' · ' . $parts['daymonth'],
                'text' => $text,
                'joins' => $joins,
                'hasjoins' => (bool) $joins,
                'back' => $joins ? false : ['url' => $pageurl, 'icon' => self::icon('home'),
                    'label' => $str($ways ? 'pub_writeby_back' : 'pub_writeby_go_page')],
                // With a step left, a way out at sight by the title: their page keeps the step as a small reminder.
                'notnow' => $joins ? ['url' => $pageurl, 'label' => $str('pub_writeby_not_now'),
                    'changeurl' => self::url($token, $lang), 'change' => $str('pub_writeby_change_link')] : false,
            ];
            return $context;
        }
        if ($pressed === 'none') {
            // Pressed «Do not write to us any more» in a mail: asked, with its one button; beside it, choosing instead.
            $context['title'] = $str('pub_writeby_q_none');
            $context['none'] = ['means' => $named('pub_writeby_means_none'), 'icon' => self::icon('check'),
                'button' => $str('pub_writeby_yes_none'), 'other' => $str('pub_writeby_other'),
                'pickurl' => self::url($token, $lang), 'pick' => $str('pub_writeby_pick_link')];
            return $context;
        }
        // The boxes: what they have now, with the way pressed in a mail already changed, and what that change means.
        $marked = $now;
        // One way only (the centre writes by email alone): nothing to pick among, it says so.
        $means = $str(count($channels) > 1 ? 'pub_writeby_means_pick' : 'pub_writeby_means_only');
        if (in_array($pressed, $channels, true)) {
            $off = in_array($pressed, $now, true);
            $marked = $off ? array_values(array_diff($now, [$pressed])) : array_values(array_intersect(
                self::CHANNELS,
                array_merge($now, [$pressed])
            ));
            $context['title'] = $off ? $str('pub_writeby_q_off_' . $pressed) :
                $str(($now ? 'pub_writeby_q_also_' : 'pub_writeby_q_') . $pressed);
            if ($off) {
                $means = $marked ? $str('pub_writeby_means_off', (object) ['ways' => self::words($marked, $lang),
                    'gone' => self::words([$pressed], $lang)]) : $str('pub_writeby_means_off_all');
            } else {
                $means = $names !== '' ? $str('pub_writeby_means_add', (object) ['ways' => self::words($marked, $lang),
                    'names' => $names]) : $str('pub_writeby_means_add_nochild', self::words($marked, $lang));
            }
        } else {
            $context['title'] = $str('pub_writeby_pick_title');
        }
        // The centre writes by one way only: nothing to choose among. The page says how and where, and «Yes, like that».
        $only = count($channels) === 1 && !in_array($pressed, $channels, true) ? [
            'value' => $channels[0],
            'address' => $channels[0] === 'email' ? households::recipient($household) : '',
        ] : false;
        if ($only) {
            $context['title'] = $str('pub_writeby_only_title_' . $channels[0]);
            $context['nowpill'] = false;
            $marked = $channels;
        }
        $context['choosing'] = [
            'only' => $only,
            'means' => $means,
            'legend' => $str('pub_writeby_boxes'),
            'boxes' => array_map(
                fn($c) => ['value' => $c, 'icon' => self::icon($c), 'check' => self::icon('check'),
                'label' => $str('pub_writeby_' . $c), 'line' => $str('pub_writeby_line_' . $c),
                'checked' => in_array($c, $marked, true), 'pressed' => $c === $pressed,
                'tag' => $c === $pressed ? $str(in_array($c, $marked, true) ? 'pub_writeby_tag_new' : 'pub_writeby_tag_goes') : ''],
                $channels
            ),
            'icon' => self::icon('check'),
            'button' => $marked ? $str('pub_writeby_save') : $str('pub_writeby_opt_none'),
            'save' => $str('pub_writeby_save'),
            'nobox' => $str('pub_writeby_pick_one'),
            'next' => array_diff(array_intersect($marked, self::PHONES), self::joined_on($household, $centre)) ?
                $str('pub_writeby_next') : '',
        ];
        // Beside the boxes, «do not write to us any more»; not when the boxes already say it (nothing left marked).
        $context['nonebutton'] = $marked ? ['icon' => self::icon('none'), 'label' => $str('pub_writeby_opt_none'),
            'ask' => $str('pub_writeby_or_none'), 'small' => $str('pub_writeby_none_small')] : false;
        return $context;
    }

    /**
     * The thanks by mail, if email is among the ways they marked. On the phones the thanks are Marandu's: its welcome card
     * when they join; with none, only the page.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     */
    public static function confirm(\stdClass $household, \stdClass $centre, string $lang): void {
        // At most one an hour: going back and forth between ways never floods their inbox with mails and new links.
        $cache = \cache::make('local_oksigeniaeguahe', 'writebyconfirm');
        if ((int) $cache->get((int) $household->id) > time() - HOURSECS) {
            return;
        }
        $cache->set((int) $household->id, time());
        if (in_array('email', self::chosen($household) ?? [], true)) {
            familymail::send('writeby', $household, $centre, null, $lang, households::new_link($household, 'writeby'));
        }
    }

    /**
     * What Marandu says: the family wrote the word that stops a phone, someone of the team stopped it from the chat, or
     * the family wrote again on that phone and it was lifted. Only that phone changes; the rest stays.
     *
     * @param int $householdid
     * @param string $channel whatsapp or telegram.
     * @param bool $out
     * @param string $source Where it was asked, as Marandu says it: eguahe:… when another household of admissions chose
     *                       it on its page or file (Marandu never tells back the one that asked).
     */
    public static function from_messaging(int $householdid, string $channel, bool $out, string $source = ''): void {
        global $DB;
        $household = $DB->get_record(households::T, ['id' => $householdid]);
        $centre = $household ? centres::get((int) $household->centreid) : null;
        if (!$household || !$centre || !in_array($channel, self::PHONES, true)) {
            return;
        }
        if (strpos($source, 'eguahe:') === 0) {
            // The same phone is joined to another household too, and that one chose: it is not this family's choice. Its
            // own stays; the team reads that this phone will not take messages of admissions any more.
            if ($out) {
                households::note($household, 0, get_string(
                    'writeby_elsewhere_note',
                    'local_oksigeniaeguahe',
                    get_string('channel_' . $channel, 'local_oksigeniaeguahe')
                ));
            }
            return;
        }
        $now = self::now($household, $centre);
        if ($out) {
            $keep = array_values(array_diff($now, [$channel]));
            if (!$keep && self::chosen($household) === null) {
                // «Not on WhatsApp» from a family that never chose: Marandu keeps it; it is not «no messages at all».
                return;
            }
            self::set($household, $keep, 'messaging');
        } else if (!in_array($channel, $now, true)) {
            self::set($household, array_merge($now, [$channel]), 'messaging', 0, 0, self::silent($household) ? 'back' : '');
        }
    }

    /**
     * The family wrote to the centre after asking not to be written to (from the next day on): it accepts to be written to
     * again by the way it wrote; the team sees it in its history and can put it back with a touch if the family asks.
     *
     * @param \stdClass $household
     * @param string $channel The channel of what it wrote.
     * @param int $time When it wrote.
     */
    public static function wrote_again(\stdClass $household, string $channel, int $time): void {
        global $DB;
        if (!property_exists($household, 'writeby')) {
            $fresh = $DB->get_record(households::T, ['id' => $household->id], 'id, writeby, writebytime');
            $household->writeby = $fresh->writeby ?? null;
            $household->writebytime = $fresh->writebytime ?? 0;
        }
        // From the day after: the «thank you» someone answers to the confirmation does not undo it (as Marandu does).
        if (!self::silent($household) || $time < usergetmidnight((int) $household->writebytime) + DAYSECS) {
            return;
        }
        $record = $DB->get_record(households::T, ['id' => $household->id]);
        if (!$record) {
            return;
        }
        if (in_array($channel, self::PHONES, true)) {
            self::set($record, [$channel], 'family', 0, 0, 'back');
        } else if (households::recipient($record) !== '') {
            self::set($record, ['email'], 'family', 0, 0, 'back');
        }
        foreach (['writeby', 'writebytime', 'writebyfrom'] as $field) {
            $household->$field = $record->$field;
        }
    }

    /**
     * For the file of the team: how they are written to and, if they asked not to be, since when, whether they wrote
     * since, the question again once a child is at the centre, and the trail of days.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $action The form of the file.
     * @return array
     */
    public static function team_context(\stdClass $household, \stdClass $centre, string $action): array {
        $c = self::C;
        $ways = self::chosen($household);
        $now = self::now($household, $centre);
        $silent = $ways === [];
        $lang = current_language();
        $day = fn(int $t) => userdate($t, get_string('strftimedateshort', 'langconfig'));
        $none = (int) ($household->writebynone ?? 0);
        $enrolled = self::enrolled_since($household);
        // The trail, with its days: when they asked not to be written to, when a child was enrolled, what they chose after.
        $trail = [];
        if ($none) {
            $trail[] = ['text' => get_string('panel_writeby_trail_none', $c, $day($none))];
            $firstenrol = $enrolled ?: self::enrolled_after($household, $none);
            if ($firstenrol) {
                $trail[] = ['text' => get_string('panel_writeby_trail_enrolled', $c, $day($firstenrol))];
            }
            if (!$silent && $ways && (int) $household->writebytime > $none) {
                $trail[] = ['text' => get_string('panel_writeby_trail_chose', $c, (object) ['way' =>
                    self::words($ways, $lang, 'pub_writeby_name_'), 'day' => $day((int) $household->writebytime)])];
            }
        }
        // The phones marked and not joined yet: until they join them, what has no other way goes by email.
        $pending = $ways ? array_diff(array_intersect($ways, self::PHONES), self::joined_on($household, $centre)) : [];
        return [
            'now' => $silent ? get_string('panel_writeby_none', $c) : ($now ? get_string(
                'panel_writeby_list',
                $c,
                self::words($now, $lang, 'pub_writeby_name_')
            ) : get_string('panel_writeby_nothing', $c)),
            'chosen' => $ways !== null,
            'pending' => $pending ? get_string('panel_writeby_notjoined', $c, self::words(
                $pending,
                $lang,
                'pub_writeby_name_'
            )) : '',
            'silent' => $silent,
            // Who said it: the family on its page or by message, or the team, who noted what they heard.
            'since' => $silent ? get_string(($household->writebyfrom ?? '') === 'team' ? 'panel_writeby_silent_team' :
                'panel_writeby_silent_since', $c, $day((int) $household->writebytime)) : '',
            'wrote' => $silent && self::wrote_after($household),
            // Asked as a family on its way, and now of the centre: the word was for the admissions; ask it again.
            'askagain' => $enrolled ? get_string('panel_writeby_askagain', $c, (object) ['none' => $day($none),
                'enrolled' => $day($enrolled)]) : '',
            'trail' => count($trail) > 1 ? $trail : [],
            'hastrail' => count($trail) > 1,
            'action' => $action,
            'sesskey' => sesskey(),
            'boxes' => array_map(fn($c2) => ['value' => $c2, 'label' => get_string('panel_writeby_' . $c2, $c),
                'checked' => in_array($c2, $now, true)], $channels = self::channels($centre)),
            // When the centre writes only by email, it says so, and where WhatsApp and Telegram are turned on.
            'help' => get_string(count($channels) > 1 ? 'panel_writeby_change_help' : 'panel_writeby_change_onlymail', $c),
        ];
    }
}
