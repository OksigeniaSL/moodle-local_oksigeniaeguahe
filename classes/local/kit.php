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
 * The kit of an act for the networks of the centre (the third step of the operation in docs/12): a link of its own for
 * each network, so the numbers say where each family came from; when to post; the pictures without a QR (in a network
 * the QR is seen on the same phone that would read it), in the shapes the networks use; the texts, the one for the
 * families to forward among them, and a script to film in twenty minutes with a phone. The texts are written by the
 * AI of the centre when it has it on, and by fixed rules otherwise; a person always reads them and posts them.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class kit {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var array The pictures: their shape, and the size the networks ask for. */
    const SHAPES = ['cuadrada' => [1080, 1080], 'vertical' => [1080, 1920], 'apaisada' => [1200, 630]];

    /** @var array When to post, in days before the act: announce it, tell something of it, the last call. */
    const WHEN = ['announce' => 14, 'tell' => 7, 'last' => 2, 'day' => 0];

    /** @var int The longest text the AI writes for a network. */
    const MOST = 900;

    /**
     * Whether an act has a kit: open to all and still to come.
     *
     * @param \stdClass $event
     * @param int $now
     * @return bool
     */
    public static function has(\stdClass $event, int $now = 0): bool {
        return share::act_open($event, $now);
    }

    /**
     * What is kept of the kit of an act.
     *
     * @param \stdClass $event
     * @return array links (network key => short link) and texts.
     */
    public static function kept(\stdClass $event): array {
        $kit = json_decode((string) ($event->kit ?? ''), true);
        return (is_array($kit) ? $kit : []) + ['links' => [], 'texts' => null];
    }

    /**
     * Keeps the kit of an act.
     *
     * @param \stdClass $event
     * @param array $kit
     */
    protected static function keep(\stdClass $event, array $kit): void {
        global $DB;
        $event->kit = json_encode($kit, JSON_UNESCAPED_UNICODE);
        $DB->set_field(events::T, 'kit', $event->kit, ['id' => $event->id]);
    }

    /**
     * The key of a network in a link: its letters only («Instagram» is instagram, «X / Twitter» is xtwitter).
     *
     * @param string $name
     * @return string
     */
    public static function network_key(string $name): string {
        return substr(preg_replace('/[^a-z]+/', '', grades::plain($name)), 0, 20) ?: 'red';
    }

    /**
     * The network of the centre a key of a link stands for, by its name, or '' when it is none.
     *
     * @param \stdClass $centre
     * @param string $key
     * @return string
     */
    public static function network_of(\stdClass $centre, string $key): string {
        foreach (centres::networks($centre) as $name) {
            if ($key !== '' && self::network_key($name) === $key) {
                return $name;
            }
        }
        return '';
    }

    /**
     * A link for each network of the centre: the page of the act saying where it was shared, made short by the
     * shortener of the site when there is one (once, then kept).
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return array Of name, key and url.
     */
    public static function links(\stdClass $event, \stdClass $centre): array {
        $kit = self::kept($event);
        $shortener = shortener::of_site();
        $changed = false;
        $out = [];
        foreach (centres::networks($centre) as $name) {
            $key = self::network_key($name);
            $long = (new \moodle_url(events::url($event), ['d' => $key]))->out(false);
            if (!isset($kit['links'][$key]) && $shortener) {
                $title = get_string('short_title', self::C, (object) ['centre' => format_string($centre->name),
                    'event' => format_string($event->name) . ' · ' . $name]);
                $link = $shortener->link($long, $title, false, '');
                if ($link) {
                    $kit['links'][$key] = \core_text::substr($link['short'], 0, 255);
                    $changed = true;
                }
            }
            $out[] = ['name' => $name, 'key' => $key, 'url' => $kit['links'][$key] ?? $long];
        }
        if ($changed) {
            self::keep($event, $kit);
        }
        return $out;
    }

    /**
     * When to post, from the day of the act: the moments still to come, each with its day and what to post.
     *
     * @param \stdClass $event
     * @param string $lang
     * @param int $now
     * @return array Of key, day and what.
     */
    public static function when(\stdClass $event, string $lang, int $now): array {
        $out = [];
        $start = (int) $event->timestart;
        foreach (self::WHEN as $key => $days) {
            $day = $start - $days * DAYSECS;
            if ($day < $now - DAYSECS && $key !== 'day') {
                continue;
            }
            $out[] = ['key' => $key, 'day' => userdate($day, get_string('strftimedaydate', 'langconfig')),
                'what' => get_string('kit_when_' . $key, self::C)];
        }
        return $out;
    }

    /**
     * The facts of an act that its texts may say, and nothing else.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return array
     */
    protected static function facts(\stdClass $event, \stdClass $centre): array {
        $lang = (string) $centre->lang;
        $when = publicpage::date_parts((int) $event->timestart, $lang);
        return [
            'centre' => format_string($centre->name),
            'phrase' => share::phrase($centre, $lang),
            'act' => format_string($event->name),
            'day' => $when['long'],
            'hours' => familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
            'place' => trim((string) ($event->place ?? '')) !== '' ? format_string($event->place) :
                (string) ($centre->address ?? ''),
            'room' => !empty($event->capacity),
            'welcome' => trim((string) ($centre->welcome ?? '')),
        ];
    }

    /**
     * The texts written by fixed rules: one for the networks, one for the families to forward, and the script.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return array post, forward, script (of shot, seconds and onscreen) and by.
     */
    public static function rules(\stdClass $event, \stdClass $centre): array {
        $a = (object) self::facts($event, $centre);
        $script = [];
        foreach ([1 => 4, 2 => 6, 3 => 6, 4 => 4] as $n => $seconds) {
            $script[] = ['shot' => get_string('kit_shot_' . $n, self::C, $a), 'seconds' => $seconds,
                'onscreen' => get_string('kit_onscreen_' . $n, self::C, $a)];
        }
        return [
            'post' => get_string($a->room ? 'kit_post_room' : 'kit_post', self::C, $a),
            'forward' => get_string('kit_forward', self::C, $a),
            'script' => $script,
            'by' => 'rules',
        ];
    }

    /**
     * The texts of the kit: the ones the AI of the centre wrote, when it was asked, or else by fixed rules.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return array As rules(), with by «ai» or «rules».
     */
    public static function texts(\stdClass $event, \stdClass $centre): array {
        $kept = self::kept($event)['texts'];
        return is_array($kept) && !empty($kept['post']) ? $kept : self::rules($event, $centre);
    }

    /**
     * Asks the AI of the centre to write the texts of the kit, and keeps them; a person reads them before posting.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $userid
     * @return bool Whether it wrote them.
     */
    public static function write(\stdClass $event, \stdClass $centre, int $userid): bool {
        if (!ai::ready($centre)) {
            return false;
        }
        try {
            $answer = ai::ask($centre, self::prompt($event, $centre), $userid);
        } catch (\moodle_exception $e) {
            return false;
        }
        $data = ai::json($answer);
        if (!$data || trim((string) ($data['post'] ?? '')) === '') {
            return false;
        }
        $cut = fn($text) => \core_text::substr(trim((string) $text), 0, self::MOST);
        $script = [];
        foreach (array_slice(is_array($data['script'] ?? null) ? $data['script'] : [], 0, 5) as $shot) {
            if (!is_array($shot) || trim((string) ($shot['shot'] ?? '')) === '') {
                continue;
            }
            $script[] = ['shot' => $cut($shot['shot']), 'seconds' => max(1, min(15, (int) ($shot['seconds'] ?? 4))),
                'onscreen' => \core_text::substr(trim((string) ($shot['onscreen'] ?? '')), 0, 80)];
        }
        $kit = self::kept($event);
        $kit['texts'] = ['post' => $cut($data['post']), 'forward' => $cut($data['forward'] ?? ''),
            'script' => $script ?: self::rules($event, $centre)['script'], 'by' => 'ai', 'time' => time()];
        self::keep($event, $kit);
        return true;
    }

    /**
     * Back to the texts by rules: what the AI wrote is forgotten.
     *
     * @param \stdClass $event
     */
    public static function forget(\stdClass $event): void {
        $kit = self::kept($event);
        $kit['texts'] = null;
        self::keep($event, $kit);
    }

    /**
     * What the AI of the centre is asked: the facts of the act and nothing of any family, in the language of the
     * centre, and plain.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return string
     */
    public static function prompt(\stdClass $event, \stdClass $centre): string {
        $names = ['es' => 'Spanish', 'en' => 'English', 'pt' => 'European Portuguese', 'pt_br' => 'Brazilian Portuguese',
            'ar' => 'Arabic'];
        $lang = $names[(string) $centre->lang] ?? 'the language of the school';
        return "You help the admissions team of a school or educational centre share one of its open acts on its " .
            "social networks. A person of the team reads what you write and posts it.\n\n" .
            "Rules, all of them:\n" .
            "- Write in $lang, warm and plain, as the school would speak to families it does not know yet. No marketing " .
            "words, no exaggeration, no emojis, no hashtags." .
            (str_starts_with($lang, 'Spanish') ? ' Never «vosotros»: address families with «ustedes».' : '') . "\n" .
            "- Say only the facts below; never invent a figure, a name or an activity.\n" .
            "- The text for the families to forward is written as a family of the school would send it to a friend, " .
            "short, in the first person; not a slogan of the school.\n" .
            "- The script is for a short upright video filmed with a phone in twenty minutes: three or four shots of " .
            "the school, each with what to film, how many seconds and a few words on screen.\n\n" .
            "Answer only with JSON, this shape:\n" .
            '{"post": "the text for the networks, up to 600 characters, with the day, the hours and how to sign up ' .
            '(the link goes apart)", "forward": "the text for families to forward, up to 300 characters", "script": ' .
            '[{"shot": "what to film", "seconds": 5, "onscreen": "a few words"}]}' . "\n\n" .
            "The act:\n" . json_encode(self::facts($event, $centre), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * A picture of the kit, without a QR: the colour and the name of the centre, the day and the act.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $shape cuadrada, vertical or apaisada.
     * @return string PNG bytes, or '' without GD or with an unknown shape.
     */
    public static function picture(\stdClass $event, \stdClass $centre, string $shape): string {
        if (!isset(self::SHAPES[$shape])) {
            return '';
        }
        $when = publicpage::date_parts((int) $event->timestart, (string) $centre->lang);
        return share::card($centre, $when['long'], format_string($event->name), null, self::SHAPES[$shape]);
    }
}
