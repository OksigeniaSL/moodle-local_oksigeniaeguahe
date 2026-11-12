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
 * The pages families open without an account: a document of their own, light, with the name, logo and colour of the
 * centre (never the brand of Eguahe nor the look of Moodle), in the language of the family, in light and dark.
 *
 * Not indexed, not passing the address on to other sites, not framed, and without anything from third parties.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publicpage {
    /** @var bool Whether the page goes inside the web of a centre. */
    public static $embedded = false;

    /** @var string More of the head: the preview of a piece shared (share::meta()). */
    public static $head = '';

    /** @var array|null At the foot of a piece shared, its seal instead of the line of who makes Eguahe. */
    public static $seal = null;

    /** @var string Where the line of who makes Eguahe leads. */
    const CREDITURL = 'https://oksigenia.com/';

    /**
     * The line of who makes Eguahe at the foot, unless the site turns it off.
     *
     * @param string $lang
     * @return array|null text and url.
     */
    public static function credit(string $lang): ?array {
        if ((string) get_config('local_oksigeniaeguahe', 'credit') === '0') {
            return null;
        }
        return ['text' => publiclang::str('credit_name', $lang), 'url' => self::CREDITURL];
    }

    /** @var array The small drawings of the pages: stroke icons, the colour of the text. */
    const ICONS = [
        'cal' => '<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
        'map' => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'globe' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.7 2.6 14.3 0 17M12 3.5c-2.6 2.7-2.6 ' .
            '14.3 0 17"/>',
        'chev' => '<path d="M7 10l5 5 5-5"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="M4 7l8 6 8-6"/>',
        'phone' => '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 ' .
            '1 2-2z"/>',
        'down' => '<path d="M12 4v11M7 10.5l5 5 5-5M5 19.5h14"/>',
        'back' => '<path d="M15 6l-6 6 6 6"/>',
        'send' => '<path d="M20.4 3.8 3.7 10.4l6.7 2.9 2.9 6.7 7.1-16.2Z"/><path d="m10.4 13.3 4.4-4.4"/>',
    ];

    /**
     * The icons, as the templates take them.
     *
     * @return array Name => SVG.
     */
    public static function icons(): array {
        $out = [];
        foreach (self::ICONS as $name => $paths) {
            $out[$name] = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' .
                'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
        }
        return $out;
    }

    /**
     * The initials of a centre, for its monogram when it has no logo.
     *
     * @param string $name
     * @return string
     */
    public static function initials(string $name): string {
        // Without the words every school has: «Los Almendros» gives «LA», never «(».
        $clean = preg_replace('/\(.*?\)|[^\p{L}\s]/u', ' ', $name);
        $words = preg_split('/\s+/', trim(preg_replace('/\b(colegio|escuela|academia|instituto|centro|school|college|' .
            'escola|de|del|la|las|el|of|the|da|do|dos|das)\b/iu', '', $clean))) ?: [$name];
        return \core_text::strtoupper(implode('', array_map(
            fn($w) => \core_text::substr($w, 0, 1),
            array_slice(array_filter($words), 0, 2)
        ))) ?: \core_text::strtoupper(\core_text::substr($name, 0, 1));
    }

    /**
     * Prepares Moodle for a page without an account.
     *
     * @param \moodle_url $url
     */
    public static function setup(\moodle_url $url): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('embedded');
        $PAGE->set_cacheable(false);
    }

    /**
     * Strings of the plugin in the language of the family, for a template.
     *
     * @param string $lang
     * @param string[] $ids
     * @param mixed $a The same values for all of them.
     * @return array id => text
     */
    public static function strings(string $lang, array $ids, $a = null): array {
        $strings = [];
        foreach ($ids as $id) {
            $strings[$id] = publiclang::str($id, $lang, $a);
        }
        return $strings;
    }

    /**
     * Prints the whole page and stops: the centre at the top with its language menu, the page, and at the foot the
     * centre and how it treats the data. The colours of the centre become variables here, for light and dark.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param string $title
     * @param string $template Of the plugin, without its component.
     * @param array $context
     * @param \moodle_url $url The page, for the links to the other languages.
     */
    public static function output(
        \stdClass $centre,
        string $lang,
        string $title,
        string $template,
        array $context,
        \moodle_url $url
    ): void {
        global $CFG, $OUTPUT;
        $version = (int) get_config('local_oksigeniaeguahe', 'version');
        $base = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/';
        // The living hall on the pages of the families, when it is the skin of the centre.
        $skin = '';
        if (!empty($centre->id) && vestibulo::on($centre)) {
            $skin = '<link rel="stylesheet" href="' . $base . 'pieles/vestibulo/familia.css?v=' . $version . '">' . "\n";
            $skin .= '<script src="' . $base . 'pieles/vestibulo/escena.js?v=' . $version . '" defer></script>' . "\n";
        }
        $colour = $centre->colour ?: '#2f5d62';
        $logo = !empty($centre->id) ? centres::logo((int) $centre->id) : null;
        $name = format_string($centre->name);
        $initials = self::initials($name);
        $langs = publiclang::switcher($lang, $url);
        $current = '';
        foreach ($langs as $item) {
            $current = $item['current'] ? $item['name'] : $current;
        }
        $body = $OUTPUT->render_from_template('local_oksigeniaeguahe/' . $template, $context + [
            'base' => $base,
            'version' => $version,
            'i' => self::icons(),
        ]);
        $header = $OUTPUT->render_from_template('local_oksigeniaeguahe/public_frame', [
            'i' => self::icons(),
            'centre' => $name,
            'initials' => $initials,
            'logo' => $logo ? $logo->out(false) : '',
            'logoplate' => !empty($centre->logoplate),
            'langs' => $langs,
            'langname' => $current,
            'langlabel' => publiclang::str('pub_language', $lang),
            'address' => !empty($centre->address) ? format_string($centre->address) : '',
            'privacyurl' => (new \moodle_url('/local/oksigeniaeguahe/privacy.php', ['c' => $centre->id ?? 0,
                'lang' => $lang]))->out(false),
            'privacy' => publiclang::str('pub_privacy_link', $lang),
            'credit' => self::$seal ?? self::credit($lang),
        ]);
        [$top, $bottom] = explode('<!-- main -->', $header, 2) + [1 => ''];
        $vars = fn(array $list) => implode(' ', array_map(fn($k, $v) => "$k: $v;", array_keys($list), $list));
        self::headers();
        // The page of a family: the drawing that draws itself, under each child and on the table, before what plays it.
        // And the video of a drawing, to keep it (it loads its MP4 muxer only when asked).
        $vivo = in_array($template, ['public_family', 'public_shared_drawing'], true) ?
            '<script src="' . $base . 'dibujo-vivo.js?v=' . $version . '" defer></script>' . "\n" .
            '<script src="' . $base . 'dibujo-video.js?v=' . $version . '" defer></script>' . "\n" : '';
        // The page of the drawing cuts and straightens the photo in the browser.
        $cropper = $template === 'public_drawing' ?
            '<script src="' . $base . 'dibujo.js?v=' . $version . '" defer></script>' . "\n" : '';
        echo '<!DOCTYPE html>
<html lang="' . s(str_replace('_', '-', $lang === 'pt_br' ? 'pt-BR' : $lang)) . '" dir="' . lists::dir($lang) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="' . s($colour) . '">
<title>' . s($title) . ' · ' . s($name) . '</title>
' . self::$head . '<link rel="preload" href="' . $base . 'fonts/schibsted-grotesk.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="' . $base . 'eguahe.css?v=' . $version . '">
<style>:root { ' . $vars(self::centre_vars($colour, false)) . ' }
@media (prefers-color-scheme: dark) { :root { ' . $vars(self::centre_vars($colour, true)) . ' } }</style>
<noscript><style>.nino[hidden], .adulto[hidden], .hermano[hidden], .percha[hidden], .lejos[hidden], .curso[hidden],
.quien-cuenta[hidden] {
display: grid; }
.anadir-nino, .anadir, .paso button { display: none; }</style></noscript>
' . $vivo . '<script src="' . $base . 'eguahe.js?v=' . $version . '" defer></script>
' . $cropper . $skin . '</head>
<body' . (self::$embedded ? ' class="incrustado"' : '') . '>
' . $top . $body . $bottom . '
</body>
</html>';
        die();
    }

    /**
     * The colour of the centre turned into every variable the pages use, for light or dark, as the DESIGN.md of the
     * page of the family says: text and links always at 4.5:1 on the wall, fills at 3:1 in dark, and white or near
     * black on top, whichever reads better. Nothing is mixed in CSS (Firefox 98 has no color-mix()).
     *
     * @param string $accent #rrggbb
     * @param bool $dark
     * @return array Variable => value.
     */
    public static function centre_vars(string $accent, bool $dark): array {
        $accent = strtoupper($accent);
        $paper = $dark ? '#211D1B' : '#FFFFFF';
        $vivid = $dark ? self::towards($accent, '#FFFFFF', $paper, 3) : $accent;
        $wall = $dark ? self::mix('#25201D', $vivid, 0.05) : self::mix('#F4EADC', $accent, 0.06);
        $text = self::towards($accent, $dark ? '#FFFFFF' : '#000000', $wall, 4.5);
        $on = fn(string $c) => self::contrast($c, '#FFFFFF') >= self::contrast($c, '#1A1614') ? '#FFFFFF' : '#1A1614';
        return [
            '--c-acento' => $accent,
            '--c-sobre-acento' => $on($accent),
            '--c-acento-texto' => $text,
            '--c-acento-vivo' => $vivid,
            '--c-sobre-vivo' => $on($vivid),
            '--c-pared' => $wall,
            '--c-acento-suave' => $dark ? self::mix($paper, $vivid, 0.13) : self::mix($paper, $accent, 0.08),
            '--c-banda-linea' => self::mix($accent, $on($accent), 0.35),
            '--mochila' => $dark ? '#E0846F' : '#CF6450',
            '--mochila-sombra' => $dark ? '#A85C4C' : '#9E4A3B',
            '--mochila-luz' => $dark ? '#F2C2B6' : '#F1C9C0',
        ];
    }

    /**
     * Two colours mixed.
     *
     * @param string $a #rrggbb
     * @param string $b #rrggbb
     * @param float $t 0 is $a, 1 is $b.
     * @return string
     */
    public static function mix(string $a, string $b, float $t): string {
        $x = sscanf(ltrim($a, '#'), '%2x%2x%2x');
        $y = sscanf(ltrim($b, '#'), '%2x%2x%2x');
        $out = '#';
        foreach ([0, 1, 2] as $i) {
            $out .= sprintf('%02X', (int) round(max(0, min(255, $x[$i] + ($y[$i] - $x[$i]) * $t))));
        }
        return $out;
    }

    /**
     * The contrast between two colours (WCAG).
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    public static function contrast(string $a, string $b): float {
        $lum = function (string $hex): float {
            $l = 0;
            $weights = [0.2126, 0.7152, 0.0722];
            foreach (sscanf(ltrim($hex, '#'), '%2x%2x%2x') as $i => $v) {
                $v /= 255;
                $l += $weights[$i] * ($v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4);
            }
            return $l;
        };
        [$x, $y] = [$lum($a), $lum($b)];
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /**
     * A colour moved towards black or white until it reaches a contrast against another.
     *
     * @param string $colour
     * @param string $target
     * @param string $against
     * @param float $min
     * @return string
     */
    public static function towards(string $colour, string $target, string $against, float $min): string {
        for ($t = 0; $t <= 1.001; $t += 0.025) {
            $c = self::mix($colour, $target, $t);
            if (self::contrast($c, $against) >= $min) {
                return $c;
            }
        }
        return $target;
    }

    /**
     * The colour of text on the colour of the centre: white or near black, whichever reads better (WCAG contrast).
     *
     * @param string $colour #rrggbb
     * @return string
     */
    public static function on(string $colour): string {
        return self::contrast($colour, '#FFFFFF') >= self::contrast($colour, '#1A1614') ? '#ffffff' : '#1a1614';
    }

    /**
     * What hangs on the rail of a page of a family: one hook per child, with what hangs for its age (the partial
     * panel_hanging, by the ladder; without a course, the small backpack) and the name written by hand. The first is
     * coral; brothers and sisters, mauve, sage and wood: neighbours, none of them meaning anything. Once they came, the
     * label of the backpacks is stuck down.
     *
     * @param string[] $names The names on the labels, in order; an empty one waits blank.
     * @param bool $arrive Whether they arrive swinging, one after another.
     * @param int|null $shown How many are shown (the others wait hidden, for the form).
     * @param string[] $objects What hangs for each, in the same order (ages::hanging()); the backpack without one.
     * @param bool $stuck Whether the family came: the label stuck down.
     * @return array
     */
    public static function pegs(
        array $names,
        bool $arrive = false,
        ?int $shown = null,
        array $objects = [],
        bool $stuck = false
    ): array {
        $pegs = [];
        $objects = array_values($objects);
        foreach (array_values($names) as $i => $name) {
            $style = '';
            if ($i) {
                $tone = ['#6F5E6E', '#5E7F6A', '#B98256'][($i - 1) % 3];
                $style = '--mochila:' . $tone . ';--mochila-sombra:' . self::mix($tone, '#000000', 0.24) .
                    ';--mochila-luz:' . self::mix($tone, '#FFFFFF', 0.6) . ';';
            }
            // Hung by a child: a little crooked, each its own way, never straight; and they arrive one after another.
            $style .= '--reposo:' . ['-3.5', '3', '-2', '4'][$i % 4] . 'deg;--retraso:' . sprintf('%.2f', 0.15 + $i * 0.14) . 's';
            $pegs[] = ['index' => $i, 'name' => $name, 'style' => $style, 'object' => (string) ($objects[$i] ?? '') ?: 'mochila',
                'arriving' => $arrive, 'stuck' => $stuck, 'hidden' => $shown !== null && $i >= $shown];
        }
        return ['pegs' => $pegs, 'count' => $shown ?? count($pegs)];
    }

    /**
     * The parts of a date for the pages, in the language of the family: the day of the week, the day and month, and
     * both together («sábado», «21 de noviembre», «sábado 21 de noviembre»), and the hour.
     *
     * @param int $time
     * @param string $lang
     * @return array weekday, daymonth, long, hour
     */
    public static function date_parts(int $time, string $lang): array {
        // Arabic with the digits of everywhere (0-9), as on the rest of the page.
        $locale = ['pt_br' => 'pt_BR', 'pt' => 'pt_PT', 'ar' => 'ar@numbers=latn'][$lang] ?? $lang;
        $zone = \core_date::get_server_timezone_object();
        if (class_exists('\IntlDateFormatter') && class_exists('\IntlDatePatternGenerator')) {
            $generator = new \IntlDatePatternGenerator($locale);
            $format = fn(string $skeleton) => (new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $zone,
                null,
                $generator->getBestPattern($skeleton)
            ))->format($time);
            $hour = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $zone);
            return ['weekday' => $format('EEEE'), 'daymonth' => $format('dMMMM'),
                'long' => str_replace(',', '', $format('EEEEdMMMM')), 'hour' => $hour->format($time)];
        }
        return ['weekday' => userdate($time, '%A'), 'daymonth' => userdate($time, '%d %B'),
            'long' => userdate($time, '%A %d %B'), 'hour' => userdate($time, '%H:%M')];
    }

    /**
     * The headers of every public page.
     */
    public static function headers(): void {
        if (self::$embedded) {
            // A form inside the web of the centre: any web may show it.
            header_remove('X-Frame-Options');
        }
        @header('Content-Type: text/html; charset=utf-8');
        @header('Referrer-Policy: no-referrer');
        @header('X-Robots-Tag: noindex, nofollow');
        @header('X-Content-Type-Options: nosniff');
        // The widget against spam works in a worker of its own and puts its styles inline.
        @header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; " .
            "script-src 'self'; worker-src 'self' blob:; media-src 'self' blob:; connect-src 'self'; frame-ancestors " .
            (self::$embedded ? '*' : "'none'") . "; form-action 'self'; " .
            "base-uri 'none'; object-src 'none'");
        @header('Cache-Control: no-store');
    }

    /**
     * A page that only says something: a link that no longer works, an event that is full, data erased. The rail
     * waits with its empty hook, and the family can write or call.
     *
     * @param \stdClass|null $centre Null when not even the centre is known.
     * @param string $lang
     * @param string $title
     * @param string $text
     * @param \moodle_url $url
     * @param bool $hook Whether the empty hook is shown (not after erasing).
     * @param array|null $button A button that opens apart: url and label.
     */
    public static function message(
        ?\stdClass $centre,
        string $lang,
        string $title,
        string $text,
        \moodle_url $url,
        bool $hook = true,
        ?array $button = null
    ): void {
        $centre = $centre ?? (object) ['id' => 0, 'name' => format_string(get_site()->fullname), 'colour' => null];
        self::output($centre, $lang, $title, 'public_message', ['title' => $title, 'text' => $text, 'hook' => $hook,
            'button' => $button] + self::reach($centre, $lang), $url);
    }

    /**
     * The order of day, month and year in the dates of a language and a country, from the international data (ICU):
     * day first in Spain or Brazil, month first in the United States, year first where it goes first. Nothing written
     * for a country; without ICU, day, month and year.
     *
     * @param string $lang A language of the pages (es, en, pt, pt_br, ar).
     * @param string $country The country of the centre (ISO), or empty.
     * @return string[] d, m and y in their order.
     */
    public static function date_order(string $lang, string $country): array {
        $parts = explode('_', $lang);
        $locale = $parts[0] . '_' . strtoupper($parts[1] ?? $country);
        $pattern = class_exists('\IntlDateFormatter') ?
            (string) \IntlDateFormatter::create(rtrim($locale, '_'), \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE)
                ->getPattern() : '';
        // Quoted text of a pattern is not a field.
        $pattern = preg_replace("/'[^']*'/", '', $pattern);
        $at = ['d' => strpos($pattern, 'd'), 'm' => strcspn($pattern, 'ML'), 'y' => strpos($pattern, 'y')];
        if ($at['d'] === false || $at['y'] === false || $at['m'] >= strlen($pattern)) {
            return ['d', 'm', 'y'];
        }
        asort($at);
        return array_keys($at);
    }

    /**
     * A date as three fields, day, month and year, in the order of the country of the centre (public_date): a field of
     * type date writes it as its browser does, mm/dd/yyyy in an English one, whatever the page.
     *
     * @param string $name The name of the date; its parts are <name>d, <name>m and <name>y.
     * @param string $value YYYY-MM-DD, or empty.
     * @param string $lang
     * @param \stdClass|null $centre
     * @param string $id The id of its fields when several dates share a name; the name by default.
     * @return array id, first (the id of its first field), parts (key, id, name, label, value, size), and form, child,
     *     invalid and describedby off, for the page to set.
     */
    public static function date_input(string $name, string $value, string $lang, ?\stdClass $centre, string $id = ''): array {
        $id = $id !== '' ? $id : $name;
        $sent = [];
        foreach (['d', 'm', 'y'] as $key) {
            $sent[$key] = trim(optional_param($name . $key, '', PARAM_RAW_TRIMMED));
        }
        // What they wrote, when it came back with an error; else the date kept.
        $have = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) ? ['y' => $m[1], 'm' => $m[2], 'd' => $m[3]] :
            ['d' => '', 'm' => '', 'y' => ''];
        $values = implode('', $sent) !== '' ? $sent : $have;
        $parts = [];
        foreach (self::date_order($lang, (string) ($centre->country ?? '')) as $key) {
            $parts[] = ['key' => $key, 'id' => $id . $key, 'name' => $name . $key,
                'label' => publiclang::str('pub_date_' . ['d' => 'day', 'm' => 'month', 'y' => 'year'][$key], $lang),
                'value' => $values[$key], 'size' => $key === 'y' ? 4 : 2];
        }
        // Off here, so the template never takes them from the page around.
        return ['id' => $id, 'first' => $parts[0]['id'], 'parts' => $parts, 'form' => false, 'child' => false,
            'invalid' => false, 'describedby' => false];
    }

    /**
     * The date of three fields of date_input(), as YYYY-MM-DD; empty when nothing was written, «invalid» when only some
     * of it or something that is not a number, so whoever checks it says so. A page sent with the old single field
     * still reads.
     *
     * @param string $name
     * @return string
     */
    public static function date_param(string $name): string {
        $d = trim(optional_param($name . 'd', '', PARAM_RAW_TRIMMED));
        $m = trim(optional_param($name . 'm', '', PARAM_RAW_TRIMMED));
        $y = trim(optional_param($name . 'y', '', PARAM_RAW_TRIMMED));
        if ($d === '' && $m === '' && $y === '') {
            return trim(optional_param($name, '', PARAM_RAW_TRIMMED));
        }
        if (!preg_match('/^\d{1,2}$/', $d) || !preg_match('/^\d{1,2}$/', $m) || !preg_match('/^\d{4}$/', $y)) {
            return 'invalid';
        }
        return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
    }

    /**
     * How a family reaches the centre: its email and its phone, with the words of the buttons.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return array
     */
    public static function reach(\stdClass $centre, string $lang): array {
        $phone = trim((string) ($centre->phone ?? ''));
        return [
            'email' => (string) ($centre->email ?? ''),
            'phone' => $phone,
            'tel' => preg_replace('/[^\d+]/', '', $phone),
            'writeus' => publiclang::str('pub_write_us', $lang),
        ];
    }
}
