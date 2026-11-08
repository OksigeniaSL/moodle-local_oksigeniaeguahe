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
        if (!isset(self::NOTICES[$kind]) || !self::joined($household, $centre)) {
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
                'lang' => $pagelang]);
            $args = [self::COMPONENT, self::NOTICES[$kind], $params, $url, (int) $household->id, null, false, 3 * DAYSECS];
            return \local_oksigeniamarandu\api::notify(...$args, ...($haslang ? ['lang' => $lang] : []));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * What the team answers from the household, on the phones the family joined with: as a message when it can, or
     * as the notice with the answer inside when WhatsApp no longer allows a message.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $text
     * @return string|null The channel it went by, or null if it could not go.
     */
    public static function reply(\stdClass $household, \stdClass $centre, string $text): ?string {
        if (!self::joined($household, $centre)) {
            return null;
        }
        $lang = $household->lang ?: $centre->lang;
        try {
            $url = new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => households::new_link($household, 'reply'),
                'lang' => $lang]);
            $closed = null;
            foreach (\local_oksigeniamarandu\api::send_text(self::COMPONENT, (int) $household->id, $text, $url) as $line) {
                if (($line['status'] ?? '') === 'sent') {
                    return (string) $line['channel'];
                }
                if (($line['status'] ?? '') === 'closed') {
                    $closed = (string) $line['channel'];
                }
            }
            if ($closed && self::notify('reply', $household, $centre, null, $lang, $text)) {
                return $closed;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
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
        households::received($household, $channel, $text, true, $hook->get_time() ?: time());
        $hook->set_handled();
    }
}
