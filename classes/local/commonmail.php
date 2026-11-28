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
 * The letters of Eguahe in the common mail of the Oksigenia plugins (the mould, documents/mould.*.mst): the name, the
 * colours and the logo of the centre that writes, its pictures inside the mail and the same words as plain text.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class commonmail {
    /** A logo this much wider than tall already says the name of the centre (as on its pages). */
    public const WORDMARK = 2.2;

    /** How wide a standing picture (a poster taller than wide) shows inside the letter, in px. */
    public const STANDING = 280;

    /** The most a picture of a letter weighs: heavier, it is slow to open and looks like advertising to the filters. */
    public const MAXBYTES = 200 * 1024;

    /**
     * Whether the mould can be drawn: the plate of the logo and the size of each picture need GD.
     *
     * @return bool
     */
    public static function available(): bool {
        return function_exists('imagecreatefromstring') && function_exists('imagepng') &&
            function_exists('getimagesizefromstring');
    }

    /**
     * The brand of a centre for the mould: its name, its colour first and the rest of its brand, and its logo.
     *
     * @param \stdClass $centre
     * @return array name, colours and, when it has one that can be drawn, logo and wordmark.
     */
    public static function brand(\stdClass $centre): array {
        $main = centres::colour($centre);
        $brand = ['name' => self::plain(format_string($centre->name)),
            'colours' => array_values(array_unique(array_merge([$main], centres::brand($centre))))];
        $logo = !empty($centre->id) ? centres::logo_raster((int) $centre->id) : null;
        $bytes = $logo ? $logo->get_content() : '';
        if ($bytes !== '' && @getimagesizefromstring($bytes)) {
            $brand['logo'] = $bytes;
            $brand['wordmark'] = (centres::logo_aspect((int) $centre->id) ?? 1) >= self::WORDMARK;
        }
        return $brand;
    }

    /**
     * A letter in the mould, for mail::send_rich().
     *
     * @param \stdClass $centre
     * @param string $lang
     * @param array $mail As mould::build() takes it (subject, preheader, heading, hello, blocks, signature, contact,
     *     reason, unsub, hero), without lang and dir.
     * @return array html, text and images (data, cid, name, mime).
     */
    public static function make(\stdClass $centre, string $lang, array $mail): array {
        $brand = self::brand($centre);
        $mail += ['lang' => $lang === 'pt_br' ? 'pt-BR' : $lang, 'dir' => lists::dir($lang)];
        try {
            $built = mould::build($brand, $mail);
        } catch (\InvalidArgumentException $e) {
            // A logo that cannot be drawn: the letter goes without it, with the name of the centre in the band.
            unset($brand['logo'], $brand['wordmark']);
            $built = mould::build($brand, $mail);
        }
        $html = documents::render('mould.html', $built['context']);
        $text = html_entity_decode(documents::render('mould.txt', $built['context']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // The template leaves a blank line after every piece: never more than one in a row, and no line starting with a
        // space (a piece without its words before it).
        $text = preg_replace('/^[ \t]+/m', '', $text);
        $text = trim(preg_replace('/\n(?:[ \t]*\n){2,}/', "\n\n", $text)) . "\n";
        $images = [];
        foreach ($built['images'] as $image) {
            // Named by what it is: the same picture, the same name, in every letter that carries it.
            $cid = sha1($image['bytes']) . '@eguahe';
            $html = str_replace('cid:' . $image['cid'] . '"', 'cid:' . $cid . '"', $html);
            $images[] = ['data' => $image['bytes'], 'cid' => $cid, 'name' => $image['name'], 'mime' => $image['mime']];
        }
        return [$html, $text, $images];
    }

    /**
     * A picture made ready for a letter: a JPEG at twice the width it shows (1200 px for one across the top), on white
     * where it was transparent, as light as it reads well and never above MAXBYTES.
     *
     * @param string $bytes PNG, JPEG, GIF or WebP.
     * @return array|null bytes and wide (wider than tall), or null when it cannot be read.
     */
    public static function picture(string $bytes): ?array {
        $src = $bytes !== '' ? @imagecreatefromstring($bytes) : false;
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $wide = $w > 1.15 * $h;
        $max = 2 * ($wide ? mould::WIDTH : self::STANDING);
        $scale = min(1, $max / max(1, $w));
        $out = '';
        // Smaller and lighter until it weighs what a letter can carry.
        for ($try = 0; $try < 6; $try++) {
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            $im = imagecreatetruecolor($tw, $th);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            imagecopyresampled($im, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            ob_start();
            imagejpeg($im, null, $try < 3 ? 82 - 8 * $try : 62);
            $out = (string) ob_get_clean();
            imagedestroy($im);
            if (strlen($out) <= self::MAXBYTES) {
                break;
            }
            $scale *= $try < 3 ? 1 : 0.8;
        }
        imagedestroy($src);
        return strlen($out) <= self::MAXBYTES ? ['bytes' => $out, 'wide' => $wide] : null;
    }

    /**
     * Paragraphs of a text written with blank lines between them, each as a piece of the mould.
     *
     * @param string $text
     * @return array[]
     */
    public static function paragraphs(string $text): array {
        $out = [];
        foreach (preg_split('/\R{2,}/', trim($text)) as $paragraph) {
            if (trim($paragraph) !== '') {
                $out[] = ['paragraph' => trim($paragraph)];
            }
        }
        return $out;
    }

    /**
     * A text of the centre as plain words: the mould escapes what it is given.
     *
     * @param string $s
     * @return string
     */
    public static function plain(string $s): string {
        return trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
    }
}
