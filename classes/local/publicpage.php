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
     * Prints the whole page and stops.
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
        $colour = $centre->colour ?: '#2f5d62';
        $logo = centres::logo((int) $centre->id);
        $body = $OUTPUT->render_from_template('local_oksigeniaeguahe/' . $template, $context + [
            'base' => $base,
            'version' => $version,
        ]);
        $header = $OUTPUT->render_from_template('local_oksigeniaeguahe/public_frame', [
            'centre' => format_string($centre->name),
            'logo' => $logo ? $logo->out(false) : '',
            'langs' => publiclang::switcher($lang, $url),
            'langlabel' => publiclang::str('pub_language', $lang),
            'privacyurl' => (new \moodle_url('/local/oksigeniaeguahe/privacy.php', ['c' => $centre->id,
                'lang' => $lang]))->out(false),
            'privacy' => publiclang::str('pub_privacy_link', $lang),
            'year' => date('Y'),
        ]);
        [$top, $bottom] = explode('<!-- main -->', $header, 2) + [1 => ''];
        self::headers();
        echo '<!DOCTYPE html>
<html lang="' . s(str_replace('_', '-', $lang)) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="' . s($colour) . '">
<title>' . s($title) . ' · ' . s(format_string($centre->name)) . '</title>
<link rel="stylesheet" href="' . $base . 'eguahe.css?v=' . $version . '">
<style>:root { --centre: ' . s($colour) . '; --on-centre: ' . self::on($colour) . '; }</style>
</head>
<body class="eguahe-public">
' . $top . $body . $bottom . '
</body>
</html>';
        die();
    }

    /**
     * The colour of text on the colour of the centre: white or near black, whichever reads better (WCAG contrast).
     *
     * @param string $colour #rrggbb
     * @return string
     */
    public static function on(string $colour): string {
        $channel = function (string $hex): float {
            $c = hexdec($hex) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($colour, '#');
        $l = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) +
            0.0722 * $channel(substr($hex, 4, 2));
        // Contrast with white is 1.05 / (l + 0.05); with #1d2421 (luminance about 0.016), (l + 0.05) / 0.066.
        return 1.05 / ($l + 0.05) >= ($l + 0.05) / 0.066 ? '#ffffff' : '#1d2421';
    }

    /**
     * The headers of every public page.
     */
    public static function headers(): void {
        @header('Content-Type: text/html; charset=utf-8');
        @header('Referrer-Policy: no-referrer');
        @header('X-Robots-Tag: noindex, nofollow');
        @header('X-Content-Type-Options: nosniff');
        // The widget against spam works in a worker of its own and puts its styles inline.
        @header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; " .
            "script-src 'self'; worker-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; " .
            "base-uri 'none'; object-src 'none'");
        @header('Cache-Control: no-store');
    }

    /**
     * A page that only says something: a link that no longer works, an event that is full.
     *
     * @param \stdClass|null $centre Null when not even the centre is known.
     * @param string $lang
     * @param string $title
     * @param string $text
     * @param \moodle_url $url
     */
    public static function message(?\stdClass $centre, string $lang, string $title, string $text, \moodle_url $url): void {
        $centre = $centre ?? (object) ['id' => 0, 'name' => format_string(get_site()->fullname), 'colour' => null];
        self::output($centre, $lang, $title, 'public_message', ['title' => $title, 'text' => $text], $url);
    }
}
