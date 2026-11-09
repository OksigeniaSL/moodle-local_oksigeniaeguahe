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
 * What a family shares, well shared: the story of a child, its drawing and an open act of the centre. Each by a signed
 * link that opens that piece and nothing else of the family; with its preview (Open Graph) in the colour and the name
 * of the centre; at its end the gift of the centre, the invitation to whoever receives it (when the centre has it on)
 * and the seal of Eguahe, of Oksigenia. What it brings is counted for the family that shared it, never scored.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] What a family can share. */
    const PIECES = ['cuento', 'dibujo', 'acto'];

    /** @var string[] What is counted of a piece shared: opened by whoever received it, the invitation followed, sent. */
    const COUNTED = ['opened', 'invite', 'sent'];

    /** @var string The page of Oksigenia for centres; Spanish, and English for every other language. */
    const SEAL = 'https://oksigenia.com/';

    /** @var string[] The readers of links that are not people: their opening is not counted. */
    const ROBOTS = ['whatsapp', 'facebookexternalhit', 'facebot', 'telegrambot', 'twitterbot', 'slackbot', 'linkedinbot',
        'discordbot', 'skypeuripreview', 'googlebot', 'bingbot', 'applebot', 'pinterest', 'embedly', 'preview', 'bot/',
        'crawler', 'spider'];

    /** @var int The preview image: its width and height, as the networks ask. */
    const CARD = [1200, 630];

    /**
     * The key that signs the links of what is shared: one for the site, made the first time it is needed.
     *
     * @return string
     */
    protected static function key(): string {
        $key = (string) get_config(self::C, 'sharekey');
        if ($key === '') {
            $key = random_string(48);
            set_config('sharekey', $key, self::C);
        }
        return $key;
    }

    /**
     * The signature of a piece shared by a family: the piece, the family and what it is (a child, an act).
     *
     * @param string $piece
     * @param int $householdid
     * @param int $id
     * @return string
     */
    public static function signature(string $piece, int $householdid, int $id): string {
        return substr(hash_hmac('sha256', 'share:' . $piece . ':' . $householdid . ':' . $id, self::key()), 0, 32);
    }

    /**
     * Whether a signature is the one of that piece.
     *
     * @param string $piece
     * @param int $householdid
     * @param int $id
     * @param string $signature
     * @return bool
     */
    public static function signed(string $piece, int $householdid, int $id, string $signature): bool {
        return $signature !== '' && hash_equals(self::signature($piece, $householdid, $id), $signature);
    }

    /**
     * The link to send the drawing of a child to someone: it opens that drawing, and nothing else of the family.
     *
     * @param \stdClass $household
     * @param \stdClass $child
     * @param string $lang
     * @return \moodle_url
     */
    public static function drawing_url(\stdClass $household, \stdClass $child, string $lang): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/dibujo.php', ['h' => $household->id, 'n' => $child->id,
            's' => self::signature('dibujo', (int) $household->id, (int) $child->id), 'lang' => $lang]);
    }

    /**
     * The link to send an open act to someone: its page of always, saying which family passed it on.
     *
     * @param \stdClass $household
     * @param \stdClass $event
     * @return \moodle_url
     */
    public static function act_url(\stdClass $household, \stdClass $event): \moodle_url {
        return new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $event->token, 'f' => $household->id,
            'c' => self::signature('acto', (int) $household->id, (int) $event->id)]);
    }

    /**
     * Whether an act can be passed on: open to all, still open to sign up and to come.
     *
     * @param \stdClass $event
     * @param int $now
     * @return bool
     */
    public static function act_open(\stdClass $event, int $now = 0): bool {
        return !empty($event->open) && empty($event->householdid) && empty($event->tailored) &&
            (int) $event->timeend >= ($now ?: time()) && (string) ($event->token ?? '') !== '';
    }

    /**
     * The seal at the end of a piece: «Eguahe, of Oksigenia», to the page of Oksigenia for centres, saying from which
     * piece it comes.
     *
     * @param string $piece
     * @param string $lang
     * @return array url and text.
     */
    public static function seal(string $piece, string $lang): array {
        $path = $lang === 'es' ? 'eguahe' : 'en/eguahe';
        return ['url' => self::SEAL . $path . '?de=' . (in_array($piece, self::PIECES, true) ? $piece : 'eguahe'),
            'text' => publiclang::str('credit_name', $lang)];
    }

    /**
     * The name of the centre inside a sentence: the phrase the centre wrote in its language, or its name with the
     * article the language puts before it («el Colegio Los Arces»).
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function phrase(\stdClass $centre, string $lang): string {
        $own = trim((string) ($centre->namephrase ?? ''));
        return $own !== '' && $lang === ($centre->lang ?? '') ? format_string($own) :
            story::phrase(format_string($centre->name), $lang);
    }

    /**
     * The name of the centre after «from» in a language: «del Colegio Los Arces», «da Escola do Sol», «Riverside School».
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function from_centre(\stdClass $centre, string $lang): string {
        $phrase = self::phrase($centre, $lang);
        $contract = ['es' => ['el ' => 'del ', 'la ' => 'de la ', 'los ' => 'de los ', 'las ' => 'de las '],
            'pt' => ['o ' => 'do ', 'a ' => 'da ', 'os ' => 'dos ', 'as ' => 'das ']];
        $contract['pt_br'] = $contract['pt'];
        if (!isset($contract[$lang])) {
            return $phrase;
        }
        foreach ($contract[$lang] as $article => $joined) {
            if (\core_text::strpos(\core_text::strtolower($phrase), $article) === 0) {
                return $joined . \core_text::substr($phrase, \core_text::strlen($article));
            }
        }
        return 'de ' . $phrase;
    }

    /**
     * The gift of the centre that closes a piece: «Un regalo del Colegio Los Arces».
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return string
     */
    public static function gift(\stdClass $centre, string $lang): string {
        return publiclang::str('share_gift', $lang, self::from_centre($centre, $lang));
    }

    /**
     * Where the invitation leads whoever receives a piece: the form the centre chose, or its first one active, with a
     * place of its own for that piece; without a form, the next open act; without either, nowhere.
     *
     * @param \stdClass $centre
     * @param string $piece
     * @param int $now
     * @return \moodle_url|null
     */
    public static function invite_target(\stdClass $centre, string $piece, int $now = 0): ?\moodle_url {
        global $DB;
        $forms = array_filter(
            entries::for_centre((int) $centre->id),
            fn($e) => $e->kind === 'form' && !empty($e->active)
        );
        $chosen = (int) ($centre->shareentry ?? 0);
        $entry = $forms[$chosen] ?? reset($forms);
        if ($entry) {
            $origins = forms::origins($entry);
            if (!isset($origins[$piece])) {
                // Its own place, named in the language of the centre, so the numbers say what each piece brought.
                $origins[$piece] = publiclang::str('share_origin_' . $piece, (string) $centre->lang);
                $DB->set_field(entries::T, 'origins', json_encode($origins, JSON_UNESCAPED_UNICODE), ['id' => $entry->id]);
            }
            return new \moodle_url(entries::form_url($entry), ['d' => $piece]);
        }
        foreach (events::for_centre((int) $centre->id) as $event) {
            if (self::act_open($event, $now) && $event->kind !== 'visit') {
                return new \moodle_url(events::url($event), ['d' => $piece]);
            }
        }
        return null;
    }

    /**
     * The invitation at the end of a piece, when the centre has it on and has somewhere to lead.
     *
     * @param \stdClass $centre
     * @param string $piece
     * @param string $lang
     * @param \moodle_url $go The address of the piece itself, which counts the invitation followed and goes on.
     * @return array|false title, text, button and url.
     */
    public static function invitation(\stdClass $centre, string $piece, string $lang, \moodle_url $go) {
        if (empty($centre->shareinvite) || !self::invite_target($centre, $piece)) {
            return false;
        }
        return ['title' => publiclang::str('share_invite_title', $lang),
            'text' => publiclang::str('share_invite_text', $lang, self::phrase($centre, $lang)),
            'button' => publiclang::str('share_invite_button', $lang),
            'url' => (new \moodle_url($go, ['action' => 'invitacion']))->out(false)];
    }

    /**
     * Whether whoever opens a link is a person: the readers that build a preview for a chat, and search engines, are not.
     *
     * @param string $agent The User-Agent.
     * @return bool
     */
    public static function person(string $agent): bool {
        $agent = \core_text::strtolower($agent);
        if ($agent === '') {
            return false;
        }
        foreach (self::ROBOTS as $robot) {
            if (strpos($agent, $robot) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Counts what happened with a piece shared, for the family that shared it: opened by a person, the invitation
     * followed, sent again. Never what the team does, and never a robot.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $what opened, invite or sent.
     * @param string $piece
     * @return bool Whether it was counted.
     */
    public static function count(\stdClass $household, \stdClass $centre, string $what, string $piece): bool {
        global $SESSION;
        if (seen::from_team($centre) || !self::person((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            return false;
        }
        // Opened again by the same person, in the same browser: once.
        $key = $what . ':' . $piece . ':' . $household->id;
        if ($what === 'opened' && !empty($SESSION->local_oksigeniaeguahe_shared[$key])) {
            return false;
        }
        $SESSION->local_oksigeniaeguahe_shared[$key] = 1;
        return seen::record($household, 'share', $what, $piece);
    }

    /**
     * The Open Graph and card tags of a piece, for the head of its page: the preview a chat or a network shows.
     *
     * @param string $title
     * @param string $description
     * @param \moodle_url $url
     * @param \moodle_url $image
     * @param string $lang
     * @param string $site The name of the centre.
     * @return string
     */
    public static function meta(
        string $title,
        string $description,
        \moodle_url $url,
        \moodle_url $image,
        string $lang,
        string $site
    ): string {
        $locales = ['es' => 'es_ES', 'en' => 'en_GB', 'pt' => 'pt_PT', 'pt_br' => 'pt_BR', 'ar' => 'ar_AR'];
        $tags = [
            'og:type' => 'website',
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $url->out(false),
            'og:image' => $image->out(false),
            'og:image:width' => (string) self::CARD[0],
            'og:image:height' => (string) self::CARD[1],
            'og:image:alt' => $title,
            'og:site_name' => $site,
            'og:locale' => $locales[$lang] ?? 'en_GB',
        ];
        $html = '';
        foreach ($tags as $property => $content) {
            $html .= '<meta property="' . $property . '" content="' . s($content) . '">' . "\n";
        }
        $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
        $html .= '<meta name="description" content="' . s($description) . '">' . "\n";
        return $html;
    }

    /**
     * The preview image of a piece: the colour of the centre, its logo or its initials, its name, and what is shared
     * («Un cuento para Leo»), drawn with GD. Nothing else of the family.
     *
     * @param \stdClass $centre
     * @param string $kicker The small line above («Tu primer día»).
     * @param string $title The big line («Un cuento para Leo»).
     * @param string|null $picture A picture of the piece (the drawing), as bytes.
     * @return string PNG bytes, or '' without GD.
     */
    public static function card(\stdClass $centre, string $kicker, string $title, ?string $picture = null): string {
        global $CFG;
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
            return '';
        }
        [$w, $h] = self::CARD;
        $im = imagecreatetruecolor($w, $h);
        $vars = publicpage::centre_vars((string) ($centre->colour ?: '#2f5d62'), false);
        $alloc = function (string $hex) use ($im) {
            [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
            return imagecolorallocate($im, $r, $g, $b);
        };
        imagefilledrectangle($im, 0, 0, $w, $h, $alloc($vars['--c-pared']));
        imagefilledrectangle($im, 0, 0, 24, $h, $alloc($vars['--c-acento']));
        $brand = $alloc($vars['--c-acento-texto']);
        $ink = $alloc('#1A1614');
        $soft = $alloc('#5C5650');
        $dir = $CFG->dirroot . '/local/oksigeniaeguahe/assets/';
        $bold = $dir . 'share/font/SchibstedGrotesk-ExtraBold.ttf';
        $medium = $dir . 'share/font/SchibstedGrotesk-Medium.ttf';
        $hand = $dir . 'sticker/font/Caveat-SemiBold.ttf';
        $arabicfont = $dir . 'sticker/' . sticker::ARABIC_FONT;
        $left = 96;
        $box = 380;
        // With a picture, the words stop well before its sheet.
        $right = $picture !== null ? $w - 96 - $box - 72 : $w - 96;
        // The picture of the piece on the right, on a white sheet.
        if ($picture !== null && ($src = @imagecreatefromstring($picture))) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $scale = min($box / $sw, $box / $sh);
            $dw = (int) round($sw * $scale);
            $dh = (int) round($sh * $scale);
            $x = $w - 96 - $box + (int) (($box - $dw) / 2);
            $y = (int) (($h - $dh) / 2);
            imagefilledrectangle($im, $x - 16, $y - 16, $x + $dw + 16, $y + $dh + 16, $alloc('#FFFFFF'));
            imagecopyresampled($im, $src, $x, $y, 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);
        }
        // Each line at the largest size that fits, never narrowed; Arabic, joined and in its own face.
        $write = function (string $font, string $text, int $size, int $y, $colour) use ($im, $left, $right, $arabicfont): void {
            if (arabic::has($text)) {
                $font = $arabicfont;
                $text = arabic::for_gd($text);
                $size = (int) round($size * 0.8);
            }
            for (; $size > 18; $size -= 2) {
                $box = imagettfbbox($size, 0, $font, $text);
                if ($box[2] - $box[0] <= $right - $left) {
                    break;
                }
            }
            imagettftext($im, $size, 0, $left, $y, $colour, $font, $text);
        };
        $write($bold, format_string($centre->name), 34, 132, $brand);
        $write($medium, $kicker, 32, 318, $soft);
        $write($hand, $title, 92, 430, $ink);
        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}
