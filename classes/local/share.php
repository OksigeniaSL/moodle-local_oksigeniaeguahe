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
 * and the seal of Eguahe, of Oksigenia (to eguahe.com). What it brings is counted for the family that shared it, never scored.
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

    /** @var string Where the seal leads: the page of Eguahe for centres, made by Oksigenia (Enrique, 9-11). */
    const SEAL = 'https://eguahe.com/';

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
     * The seal at the end of a piece: «Eguahe, of Oksigenia», to the page of Eguahe for centres, saying from which piece
     * it comes.
     *
     * @param string $piece
     * @param string $lang
     * @return array url, text, and seal (the frame draws the mark of Eguahe before it).
     */
    public static function seal(string $piece, string $lang): array {
        // The language of the piece goes as a parameter, so any language works without a new address (Enrique, 9-11).
        return ['url' => self::SEAL . '?de=' . (in_array($piece, self::PIECES, true) ? $piece : 'eguahe') . '&lang=' .
            rawurlencode(str_replace('_', '-', $lang)),
            'text' => publiclang::str('credit_name', $lang), 'seal' => true];
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
     * The preview image of a piece, drawn with GD on the wall of the centre, as the pages of the families show it. The
     * drawing is stuck on the wall with two strips of paper tape and the label with the name on its corner; the story,
     * as its cover: its title and the big label with the name; an act, as the band of the visit, in the colour of the
     * centre. Under or above each, the logo of the centre (or its initials) and its name. Everything that matters goes in
     * the middle, so it still reads when a chat crops the picture square. Wide (1200 × 630, the preview of a link) by
     * default; square and upright for the kit of an act, with the same pieces placed for the shape. Nothing of the
     * family but the name used at home.
     *
     * @param \stdClass $centre
     * @param string $piece cuento, dibujo or acto.
     * @param array $words cuento: title and name; dibujo: name; acto: weekday, date, hours and title.
     * @param string|null $picture The drawing, as bytes (dibujo).
     * @param array $size Width and height.
     * @return string PNG bytes, or '' without GD.
     */
    public static function card(
        \stdClass $centre,
        string $piece,
        array $words,
        ?string $picture = null,
        array $size = self::CARD
    ): string {
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
            return '';
        }
        [$w, $h] = $size;
        $accent = (string) ($centre->colour ?: '#2f5d62');
        $vars = publicpage::centre_vars($accent, false);
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        self::wall($im, $vars['--c-pared'], $w, $h);
        $words += ['title' => '', 'name' => '', 'weekday' => '', 'date' => '', 'hours' => ''];
        $name = trim((string) $words['name']);
        $cx = (int) ($w / 2);
        // The shape: the wide one is the measure; square and upright grow the pieces with their width, for a phone.
        $wide = $w >= 1.3 * $h;
        $tall = $h >= 1.3 * $w;
        $f = $wide ? $w / 1200 : $w / 1080 * 1.2;
        $px = fn(float $n): int => (int) round($n * $f);
        // Where things go, as a share of the height: [wide, square, upright].
        $at = fn(array $shares): int => (int) round($h * ($wide ? $shares[0] : ($tall ? $shares[2] : $shares[1])));
        if ($piece === 'acto') {
            // The band of the visit, in the colour of the centre, under the centre on its wall.
            $top = $at([0.327, 0.34, 0.3]);
            imagefilledrectangle($im, 0, $top, $w, $h, self::ink($im, $vars['--c-acento']));
            imagefilledrectangle($im, 0, $top, $w, $top + max(4, $px(5)), self::ink($im, $vars['--c-banda-linea'], 0.35));
            self::identity(
                $im,
                $centre,
                $vars,
                $cx,
                (int) ($top / 2) + ($tall ? $px(30) : 0),
                $px($wide ? 64 : ($tall ? 92 : 72)),
                $w - 2 * $px(80)
            );
            $text = $vars['--c-sobre-acento'] === '#FFFFFF' ? '#FFFFFF' : '#1A1614';
            // The Arabic face has no middle dot: there, the Arabic comma.
            $dot = arabic::has($words['weekday'] . $words['hours']) ? '، ' : ' · ';
            $when = trim($words['weekday'] . ($words['hours'] !== '' && $words['weekday'] !== '' ? $dot : '') .
                $words['hours']);
            // The three lines in the middle of the band; upright, the day and the act may take two lines each.
            self::block($im, [
                ['medium', \core_text::strtoupper($when), $px(30), 1, 0.12, 0.84],
                ['bold', $words['date'], $px($tall ? 150 : 104), $tall ? 2 : 1, 0, 1],
                ['bold', $words['title'], $px($tall ? 60 : 46), $tall ? 2 : 1, 0, 0.92],
            ], $cx, (int) (($top + $h) / 2), $w - 2 * $px(70), $text);
        } else if ($piece === 'dibujo' && $picture !== null && ($src = @imagecreatefromstring($picture))) {
            // The drawing, stuck on the wall a little crooked, with the label of its name on the corner.
            [$sw, $sh] = [imagesx($src), imagesy($src)];
            $boxw = $wide ? $px(660) : (int) round($w * ($tall ? 0.84 : 0.78));
            $boxh = $wide ? $px(448) : (int) round($h * ($tall ? 0.52 : 0.62));
            $k = min($boxw / $sw, $boxh / $sh);
            [$dw, $dh] = [(int) round($sw * $k), (int) round($sh * $k)];
            $cy = $at([0.083, 0.08, 0.17]) + (int) ($boxh / 2);
            $angle = -2.2;
            self::shadow($im, $cx, $cy, $dw, $dh, $angle);
            self::rotated($im, $src, $cx, $cy, $dw, $dh, $angle);
            imagedestroy($src);
            // Where the corners went once turned: the tape on the two at the top, the label on the one below.
            $corner = function (int $sx, int $sy) use ($cx, $cy, $dw, $dh, $angle): array {
                $a = deg2rad($angle);
                [$x, $y] = [$sx * $dw / 2, $sy * $dh / 2];
                return [(int) round($cx + $x * cos($a) - $y * sin($a)), (int) round($cy + $x * sin($a) + $y * cos($a))];
            };
            [$tx, $ty] = $corner(-1, -1);
            self::tape($im, $tx + $px(6), $ty + $px(6), $angle - 40, $f);
            [$tx, $ty] = $corner(1, -1);
            self::tape($im, $tx - $px(6), $ty + $px(6), $angle + 40, $f);
            if ($name !== '') {
                [$lx, $ly] = $corner(1, 1);
                self::label($im, $name, $accent, $lx - $px(34), $ly - $px(30), 0.62 * $f, 0);
            }
            self::identity($im, $centre, $vars, $cx, $at([0.917, 0.9, 0.78]), $px($tall ? 64 : 50), $w - 2 * $px(80));
        } else {
            // The story, as its cover: the title and the big label with the name.
            self::write($im, 'bold', $words['title'], $px(76), $cx, $at([0.21, 0.2, 0.3]), '#2B2226', $w - 2 * $px(80));
            self::label($im, $name, $accent, $cx, $at([0.524, 0.5, 0.5]), 0.9 * $f, 0);
            self::identity($im, $centre, $vars, $cx, $at([0.917, 0.86, 0.72]), $px(50), $w - 2 * $px(80));
        }
        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /**
     * A colour for an image, from #rrggbb and how transparent it is (0 solid, 1 invisible).
     *
     * @param \GdImage|resource $im
     * @param string $hex
     * @param float $clear
     * @return int
     */
    protected static function ink($im, string $hex, float $clear = 0.0): int {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%2x%2x%2x');
        return imagecolorallocatealpha($im, (int) $r, (int) $g, (int) $b, (int) round(127 * max(0, min(1, $clear))));
    }

    /**
     * A blurred shape drawn small and laid over the card: the light on the wall, the shadow of the drawing.
     *
     * @param \GdImage|resource $im
     * @param callable $draw Draws on the small image, given it and its scale.
     * @param int $x Where the small image goes, on the card.
     * @param int $y
     * @param int $w Its size on the card.
     * @param int $h
     * @param int $passes How much it is blurred.
     */
    protected static function soft($im, callable $draw, int $x, int $y, int $w, int $h, int $passes): void {
        $scale = 6;
        $sw = max(4, (int) ceil($w / $scale));
        $sh = max(4, (int) ceil($h / $scale));
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagealphablending($small, true);
        $draw($small, 1 / $scale);
        imagealphablending($small, false);
        for ($i = 0; $i < $passes; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopyresampled($im, $small, $x, $y, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($small);
    }

    /**
     * The wall of the centre with a soft light from above, as on the pages: drawn small, blurred and blown up, so the
     * light has no edge.
     *
     * @param \GdImage|resource $im
     * @param string $wall #rrggbb
     * @param int $w
     * @param int $h
     */
    protected static function wall($im, string $wall, int $w, int $h): void {
        $k = 16;
        [$sw, $sh] = [(int) ($w / $k), (int) ($h / $k)];
        $small = imagecreatetruecolor($sw, $sh);
        imagefilledrectangle($small, 0, 0, $sw, $sh, self::ink($small, $wall));
        imagefilledellipse(
            $small,
            (int) ($sw / 2),
            (int) (-$sh * 0.12),
            (int) ($sw * 1.25),
            (int) min($sh * 1.45, $sw * 0.9),
            self::ink($small, publicpage::mix($wall, '#FFFFFF', 0.5))
        );
        for ($i = 0; $i < 40; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopyresampled($im, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($small);
    }

    /**
     * The soft shadow of the drawing on the wall, a little below it and turned with it: a grey shape drawn small,
     * blurred, blown up and multiplied over the wall, so it darkens whatever light is there.
     *
     * @param \GdImage|resource $im
     * @param int $cx The centre of the drawing.
     * @param int $cy
     * @param int $w Its size, before turning.
     * @param int $h
     * @param float $angle
     */
    protected static function shadow($im, int $cx, int $cy, int $w, int $h, float $angle): void {
        $pad = 70;
        $k = 4;
        [$rw, $rh] = [$w + 2 * $pad, $h + 2 * $pad];
        [$sw, $sh] = [(int) ceil($rw / $k), (int) ceil($rh / $k)];
        $small = imagecreatetruecolor($sw, $sh);
        imagefilledrectangle($small, 0, 0, $sw, $sh, self::ink($small, '#FFFFFF'));
        $a = deg2rad($angle);
        $points = [];
        foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$sx, $sy]) {
            [$px, $py] = [$sx * ($w / 2 - 6), $sy * ($h / 2 - 4)];
            $points[] = (int) round(($rw / 2 + $px * cos($a) - $py * sin($a)) / $k);
            $points[] = (int) round(($rh / 2 + 12 + $px * sin($a) + $py * cos($a)) / $k);
        }
        imagefilledpolygon($small, $points, self::ink($small, '#9C8C80'));
        for ($i = 0; $i < 6; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $big = imagecreatetruecolor($rw, $rh);
        imagecopyresampled($big, $small, 0, 0, 0, 0, $rw, $rh, $sw, $sh);
        imagelayereffect($im, IMG_EFFECT_MULTIPLY);
        imagecopy($im, $big, $cx - (int) ($rw / 2), $cy - (int) ($rh / 2), 0, 0, $rw, $rh);
        imagealphablending($im, true);
        imagedestroy($small);
        imagedestroy($big);
    }

    /**
     * A picture laid on the card turned by an angle, drawn twice as large and scaled down so its edges are smooth.
     *
     * @param \GdImage|resource $im
     * @param \GdImage|resource $src
     * @param int $cx Its centre on the card.
     * @param int $cy
     * @param int $w Its size on the card, before turning.
     * @param int $h
     * @param float $angle Degrees, clockwise.
     */
    protected static function rotated($im, $src, int $cx, int $cy, int $w, int $h, float $angle): void {
        $big = imagecreatetruecolor(2 * $w, 2 * $h);
        imagecopyresampled($big, $src, 0, 0, 0, 0, 2 * $w, 2 * $h, imagesx($src), imagesy($src));
        $clear = imagecolorallocatealpha($big, 0, 0, 0, 127);
        $turned = imagerotate($big, -$angle, $clear);
        imagedestroy($big);
        imagealphablending($turned, false);
        imagesavealpha($turned, true);
        $tw = (int) round(imagesx($turned) / 2);
        $th = (int) round(imagesy($turned) / 2);
        imagecopyresampled(
            $im,
            $turned,
            $cx - (int) ($tw / 2),
            $cy - (int) ($th / 2),
            0,
            0,
            $tw,
            $th,
            imagesx($turned),
            imagesy($turned)
        );
        imagedestroy($turned);
    }

    /**
     * A strip of paper tape holding a corner of the drawing to the wall: cream, half seen through, its ends torn.
     *
     * @param \GdImage|resource $im
     * @param int $cx
     * @param int $cy
     * @param float $angle
     * @param float $f How large, against the wide card.
     */
    protected static function tape($im, int $cx, int $cy, float $angle, float $f = 1.0): void {
        [$len, $wide] = [112 * $f, 32 * $f];
        $a = deg2rad($angle);
        $edge = [];
        // Along the top, then back along the bottom; each end with small teeth.
        foreach ([[-1, -1], [1, -1]] as $i => [$sx]) {
            $edge[] = [$sx * $len / 2, -$wide / 2];
        }
        $tooth = 5 * $f;
        for ($t = -$wide / 2; $t <= $wide / 2; $t += $tooth) {
            $edge[] = [$len / 2 + (fmod($t + 10 * $wide, 2 * $tooth) < $tooth ? 3 : -2) * $f, $t];
        }
        $edge[] = [$len / 2, $wide / 2];
        $edge[] = [-$len / 2, $wide / 2];
        for ($t = $wide / 2; $t >= -$wide / 2; $t -= $tooth) {
            $edge[] = [-$len / 2 + (fmod($t + 10 * $wide, 2 * $tooth) < $tooth ? -3 : 2) * $f, $t];
        }
        $points = [];
        foreach ($edge as [$px, $py]) {
            $points[] = (int) round($cx + $px * cos($a) - $py * sin($a));
            $points[] = (int) round($cy + $px * sin($a) + $py * cos($a));
        }
        imagefilledpolygon($im, $points, self::ink($im, '#E8D9B4', 0.1));
        imagepolygon($im, $points, self::ink($im, '#C9B48A', 0.55));
    }

    /**
     * The label of the first day (sticker::png()), with the name written by hand, laid on the card.
     *
     * @param \GdImage|resource $im
     * @param string $name Empty gives the blank label.
     * @param string $accent
     * @param int $cx Its centre on the card.
     * @param int $cy
     * @param float $scale Of the label at 2x (480 × 392).
     * @param float $angle More turn, besides its own.
     */
    protected static function label($im, string $name, string $accent, int $cx, int $cy, float $scale, float $angle): void {
        if (!sticker::available() || !($src = @imagecreatefromstring(sticker::png($name, $accent, 2)))) {
            return;
        }
        if ($angle) {
            imagealphablending($src, false);
            imagesavealpha($src, true);
            $turned = imagerotate($src, -$angle, imagecolorallocatealpha($src, 0, 0, 0, 127));
            imagedestroy($src);
            $src = $turned;
            imagealphablending($src, false);
            imagesavealpha($src, true);
        }
        $lw = (int) round(imagesx($src) * $scale);
        $lh = (int) round(imagesy($src) * $scale);
        imagecopyresampled(
            $im,
            $src,
            $cx - (int) ($lw / 2),
            $cy - (int) ($lh / 2),
            0,
            0,
            $lw,
            $lh,
            imagesx($src),
            imagesy($src)
        );
        imagedestroy($src);
    }

    /**
     * The centre under the piece: its logo, or its initials on its colour, and its name, centred together.
     *
     * @param \GdImage|resource $im
     * @param \stdClass $centre
     * @param array $vars The colours of the centre (publicpage::centre_vars()).
     * @param int $cx
     * @param int $cy The middle of the line.
     * @param int $size The height of the logo; the name goes at about two thirds of it.
     * @param int $room How wide the whole line may be.
     */
    protected static function identity($im, \stdClass $centre, array $vars, int $cx, int $cy, int $size, int $room = 1040): void {
        $name = format_string($centre->name);
        $file = !empty($centre->id) ? centres::logo_file((int) $centre->id) : null;
        $logo = $file ? @imagecreatefromstring($file->get_content()) : false;
        $lw = $logo ? (int) round(imagesx($logo) * $size / max(1, imagesy($logo))) : $size;
        if ($logo && $lw > 4 * $size) {
            $lw = 4 * $size;
        }
        $lh = $logo ? (int) round(imagesy($logo) * $lw / max(1, imagesx($logo))) : $size;
        if ($logo && imagesx($logo) > 2.2 * imagesy($logo)) {
            // A logotype already says the name: it goes alone, a little larger.
            $lh = (int) round($size * 1.2);
            $lw = min((int) round($room * 0.6), (int) round(imagesx($logo) * $lh / imagesy($logo)));
            $lh = (int) round(imagesy($logo) * $lw / imagesx($logo));
            imagecopyresampled(
                $im,
                $logo,
                $cx - (int) ($lw / 2),
                $cy - (int) ($lh / 2),
                0,
                0,
                $lw,
                $lh,
                imagesx($logo),
                imagesy($logo)
            );
            imagedestroy($logo);
            return;
        }
        $fs = (int) round($size * 0.62);
        [$font, $text, $fs] = self::face('bold', $name, $fs);
        $gap = (int) round($size * 0.3);
        for (; $fs > 18; $fs -= 2) {
            $box = imagettfbbox($fs * 0.75, 0, $font, $text);
            if ($box[2] - $box[0] <= $room - $lw - $gap) {
                break;
            }
        }
        $box = imagettfbbox($fs * 0.75, 0, $font, $text);
        $tw = $box[2] - $box[0];
        $left = $cx - (int) (($lw + $gap + $tw) / 2);
        if ($logo) {
            imagecopyresampled($im, $logo, $left, $cy - (int) ($lh / 2), 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        } else {
            self::monogram($im, publicpage::initials($name), $vars, $left, $cy - (int) ($size / 2), $size);
        }
        // The name sits on the middle of the logo: half its capital height below the line.
        $cap = -min($box[5], $box[7]);
        imagettftext($im, $fs * 0.75, 0, $left + $lw + $gap - $box[0], $cy + (int) round($cap * 0.46), self::ink(
            $im,
            $vars['--c-acento-texto']
        ), $font, $text);
    }

    /**
     * The initials of the centre on a disc of its colour, for a centre without a logo (or one GD cannot read).
     *
     * @param \GdImage|resource $im
     * @param string $initials
     * @param array $vars
     * @param int $x
     * @param int $y
     * @param int $size
     */
    protected static function monogram($im, string $initials, array $vars, int $x, int $y, int $size): void {
        $ss = 4;
        $disc = imagecreatetruecolor($size * $ss, $size * $ss);
        imagealphablending($disc, false);
        imagesavealpha($disc, true);
        imagefill($disc, 0, 0, imagecolorallocatealpha($disc, 0, 0, 0, 127));
        imagefilledellipse(
            $disc,
            (int) ($size * $ss / 2),
            (int) ($size * $ss / 2),
            $size * $ss - 2,
            $size * $ss - 2,
            self::ink($disc, $vars['--c-acento'])
        );
        imagecopyresampled($im, $disc, $x, $y, 0, 0, $size, $size, $size * $ss, $size * $ss);
        imagedestroy($disc);
        self::write(
            $im,
            'bold',
            $initials,
            (int) round($size * 0.4),
            $x + (int) ($size / 2),
            $y + (int) round($size * 0.64),
            $vars['--c-sobre-acento'],
            $size - 8
        );
    }

    /**
     * The face and the text GD needs for a line: Arabic, joined, in visual order and in its own face, a little smaller.
     *
     * @param string $weight bold or medium.
     * @param string $text
     * @param int $px
     * @return array [font, text, px]
     */
    protected static function face(string $weight, string $text, int $px): array {
        global $CFG;
        $dir = $CFG->dirroot . '/local/oksigeniaeguahe/assets/';
        if (arabic::has($text)) {
            return [$dir . 'sticker/' . sticker::ARABIC_FONT, arabic::for_gd($text), (int) round($px * 0.86)];
        }
        $file = $weight === 'medium' ? 'SchibstedGrotesk-Medium.ttf' : 'SchibstedGrotesk-ExtraBold.ttf';
        return [$dir . 'share/font/' . $file, $text, $px];
    }

    /**
     * How wide a line is, and where each of its letters starts when they are spaced (small capitals): the width of what
     * goes before each, as the face sets it, and the spacing.
     *
     * @param string $font
     * @param string $text As GD gets it (face()).
     * @param int $px
     * @param float $spacing A share of the size.
     * @return array [starts of each letter, then the whole width]
     */
    protected static function measure(string $font, string $text, int $px, float $spacing): array {
        $chars = $spacing ? preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) : [$text];
        $out = [];
        $before = '';
        foreach ($chars as $i => $c) {
            $box = $before === '' ? [0, 0, 0] : imagettfbbox($px * 0.75, 0, $font, $before);
            $out[] = $box[2] + (int) round($i * $spacing * $px);
            $before .= $c;
        }
        $box = imagettfbbox($px * 0.75, 0, $font, $before);
        $out[] = $box[2] + (int) round((count($chars) - 1) * $spacing * $px);
        return $out;
    }

    /**
     * A text set in one line, or in two when it would get much smaller in one and the room allows it: the largest size
     * that fits, never narrowed, and each line in its face (Arabic joined line by line, so it keeps its order).
     *
     * @param string $weight bold or medium.
     * @param string $text
     * @param int $px The size it would like.
     * @param int $room
     * @param int $most Lines it may take.
     * @param float $spacing
     * @return array [lines as [font, text as GD gets it], size]
     */
    protected static function set(string $weight, string $text, int $px, int $room, int $most, float $spacing): array {
        $fit = function (array $parts) use ($weight, $px, $room, $spacing): array {
            $size = $px;
            $lines = [];
            foreach ($parts as $part) {
                [$font, $gd, $own] = self::face($weight, $part, $size);
                $lines[] = [$font, $gd, $own / max(1, $size)];
            }
            for (; $size > 18; $size -= 2) {
                $widest = 0;
                foreach ($lines as [$font, $gd, $k]) {
                    $at = self::measure($font, $gd, (int) round($size * $k), arabic::has($gd) ? 0 : $spacing);
                    $widest = max($widest, end($at));
                }
                if ($widest <= $room) {
                    break;
                }
            }
            return [array_map(fn($l) => [$l[0], $l[1], (int) round($size * $l[2])], $lines), $size];
        };
        [$one, $size] = $fit([$text]);
        $words = preg_split('/\s+/u', trim($text));
        if ($most < 2 || count($words) < 2 || $size >= $px) {
            return [$one, $size];
        }
        // Two lines, broken where they come out most even; kept only if the letters come out clearly larger.
        $best = null;
        for ($k = 1; $k < count($words); $k++) {
            $a = implode(' ', array_slice($words, 0, $k));
            $b = implode(' ', array_slice($words, $k));
            $diff = abs(\core_text::strlen($a) - \core_text::strlen($b));
            if ($best === null || $diff < $best[0]) {
                $best = [$diff, [$a, $b]];
            }
        }
        [$two, $size2] = $fit($best[1]);
        return $size2 > $size * 1.2 ? [$two, $size2] : [$one, $size];
    }

    /**
     * One line, centred on a point, on its baseline.
     *
     * @param \GdImage|resource $im
     * @param array $line [font, text as GD gets it, size]
     * @param int $cx
     * @param int $y
     * @param int $ink
     * @param float $spacing
     */
    protected static function line($im, array $line, int $cx, int $y, int $ink, float $spacing): void {
        [$font, $text, $px] = $line;
        $spacing = arabic::has($text) ? 0 : $spacing;
        $at = self::measure($font, $text, $px, $spacing);
        $x = $cx - (int) (end($at) / 2);
        $chars = $spacing ? preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) : [$text];
        foreach ($chars as $i => $c) {
            imagettftext($im, $px * 0.75, 0, $x + $at[$i], $y, $ink, $font, $c);
        }
    }

    /**
     * A line centred on the card, at the largest size that fits its room, never narrowed.
     *
     * @param \GdImage|resource $im
     * @param string $weight bold or medium.
     * @param string $text
     * @param int $px Its size in pixels, at most.
     * @param int $cx
     * @param int $y The baseline.
     * @param string $colour #rrggbb
     * @param int $room The widest it may be.
     * @param float $spacing Between letters, as a share of the size (small capitals); 0 for none.
     * @param float $solid How solid the ink is, 1 for all.
     */
    protected static function write(
        $im,
        string $weight,
        string $text,
        int $px,
        int $cx,
        int $y,
        string $colour,
        int $room,
        float $spacing = 0,
        float $solid = 1
    ): void {
        if (trim($text) === '') {
            return;
        }
        [$lines] = self::set($weight, $text, $px, $room, 1, $spacing);
        self::line($im, $lines[0], $cx, $y, self::ink($im, $colour, 1 - $solid), $spacing);
    }

    /**
     * Several texts one under the other, centred on a point: each at the largest size that fits, in one line or two
     * where it may take them, with room between them as the larger one asks.
     *
     * @param \GdImage|resource $im
     * @param array $items Each: weight, text, size, lines it may take, spacing, solidity.
     * @param int $cx
     * @param int $mid The middle of the block.
     * @param int $room
     * @param string $colour
     */
    protected static function block($im, array $items, int $cx, int $mid, int $room, string $colour): void {
        $set = [];
        $height = 0;
        foreach ($items as [$weight, $text, $px, $most, $spacing, $solid]) {
            if (trim($text) === '') {
                continue;
            }
            [$lines, $size] = self::set($weight, $text, $px, $room, $most, $spacing);
            // A line takes its size; between two texts, a third of the larger.
            $gap = $set ? 0.32 * max($size, end($set)[1]) : 0;
            $set[] = [$lines, $size, $spacing, $solid, $gap];
            $height += count($lines) * $size * 0.98 + $gap;
        }
        $y = $mid - $height / 2;
        foreach ($set as [$lines, $size, $spacing, $solid, $gap]) {
            $y += $gap;
            foreach ($lines as $line) {
                // The baseline sits at about three quarters of the line: the capitals and the tails in their room.
                $y += $size * 0.98;
                self::line($im, $line, $cx, (int) round($y - $size * 0.22), self::ink($im, $colour, 1 - $solid), $spacing);
            }
        }
    }
}
