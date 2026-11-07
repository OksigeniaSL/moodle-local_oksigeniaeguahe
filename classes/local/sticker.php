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
 * The first-day label with the name written by hand, as a PNG made on the server for what is sent to the families:
 * mail programs do not trust web fonts nor SVG. The same label as on the pages: the band in the colour of the centre,
 * the white paper with its line and its peeled corner, the name in Caveat turned with the label. Made from three
 * layers drawn by the art director (assets/sticker), with GD and FreeType. The same name and colour give the same
 * bytes, so they are kept in the cache.
 *
 * And the logo of the centre on its own light plate, so it reads when a mail program darkens the message.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sticker {
    /** @var array|null The measures, read once. */
    protected static $m = null;

    /**
     * Whether this server can draw the label (GD with FreeType).
     *
     * @return bool
     */
    public static function available(): bool {
        return function_exists('imagettftext') && function_exists('imagecreatefrompng');
    }

    /**
     * The folder of the layers, the measures and the font.
     *
     * @return string
     */
    protected static function dir(): string {
        global $CFG;
        return $CFG->dirroot . '/local/oksigeniaeguahe/assets/sticker';
    }

    /**
     * The measures of the label.
     *
     * @return array
     */
    public static function measures(): array {
        if (self::$m === null) {
            self::$m = json_decode((string) file_get_contents(self::dir() . '/measures.json'), true);
        }
        return self::$m;
    }

    /**
     * The size of the label, in pixels at 1x, for the width and height of the image.
     *
     * @return int[] [width, height]
     */
    public static function size(): array {
        return self::measures()['canvas_px']['1x'];
    }

    /**
     * GD and FreeType work at 96 dpi: points are pixels times 72/96.
     *
     * @param float $pixels
     * @return float
     */
    protected static function pt(float $pixels): float {
        return $pixels * 0.75;
    }

    /**
     * The size of the name, in units of the label: 13, as on the pages, smaller when it is wider than the line or
     * when a capital with an accent (Í, Á, Ñ) would climb into the band; never below the minimum.
     *
     * @param string $name
     * @return float
     */
    public static function size_for(string $name): float {
        $n = self::measures()['name'];
        $font = self::dir() . '/' . $n['font'];
        $ref = 40.0;
        $box = imagettfbbox(self::pt($n['size'] * $ref), 0, $font, $name);
        $width = max(1, $box[2] - $box[0]);
        $up = max(1, -min($box[5], $box[7]));
        $k = min(1.0, ($n['room'] * $ref) / $width, ($n['room_up'] * $ref) / $up);
        return max((float) $n['min_size'], round($n['size'] * $k, 2));
    }

    /**
     * The label as PNG bytes.
     *
     * @param string $name The name used at home; empty gives the blank label.
     * @param string $accent The colour of the centre, #rrggbb.
     * @param int $scale 1 or 2 (2 for mail, shown at half its size).
     * @return string
     */
    public static function png(string $name, string $accent, int $scale = 2): string {
        $name = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 40);
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '#2f5d62';
        $scale = $scale === 1 ? 1 : 2;
        $cache = \cache::make('local_oksigeniaeguahe', 'stickers');
        $key = sha1($name . '|' . $accent . '|' . $scale . '|' . filemtime(self::dir() . '/measures.json'));
        $png = $cache->get($key);
        if ($png === false) {
            $png = self::draw($name, $accent, $scale);
            $cache->set($key, $png);
        }
        return $png;
    }

    /**
     * Draws the label: shadow and white rim, the band tinted with the colour of the centre, paper, line and corner,
     * and the name, handwritten on the line and turned with the label.
     *
     * @param string $name
     * @param string $accent
     * @param int $scale
     * @return string
     */
    protected static function draw(string $name, string $accent, int $scale): string {
        $m = self::measures();
        $dir = self::dir();
        $px = $m['px_per_unit'] * $scale;
        $layer = fn(string $key) => imagecreatefrompng($dir . '/' . str_replace('{s}', (string) $scale, $m['layers'][$key]));
        $im = $layer('under');
        imagealphablending($im, true);
        imagesavealpha($im, true);
        $band = $layer('band_mask');
        imagealphablending($band, false);
        [$r, $g, $b] = sscanf(ltrim($accent, '#'), '%2x%2x%2x');
        imagefilter($band, IMG_FILTER_COLORIZE, $r, $g, $b, 0);
        $w = imagesx($im);
        $h = imagesy($im);
        imagecopy($im, $band, 0, 0, 0, 0, $w, $h);
        $over = $layer('over');
        imagecopy($im, $over, 0, 0, 0, 0, $w, $h);
        imagedestroy($band);
        imagedestroy($over);
        if ($name !== '') {
            $n = $m['name'];
            $font = $dir . '/' . $n['font'];
            $pt = self::pt(self::size_for($name) * $px);
            $box = imagettfbbox($pt, 0, $font, $name);
            $sx = $n['centre_x'] * $px - ($box[0] + $box[2]) / 2;
            $sy = $n['baseline_y'] * $px;
            $a = deg2rad($m['angle_deg_svg']);
            [$cx, $cy] = $m['sticker_centre_units'];
            $x = $cx * $px + $sx * cos($a) - $sy * sin($a);
            $y = $cy * $px + $sx * sin($a) + $sy * cos($a);
            [$ir, $ig, $ib] = sscanf(ltrim($n['ink'], '#'), '%2x%2x%2x');
            $ink = imagecolorallocatealpha($im, $ir, $ig, $ib, 0);
            imagettftext($im, $pt, $m['angle_deg_gd'], (int) round($x), (int) round($y), $ink, $font, $name);
        }
        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /**
     * An image on its own light plate with rounded corners, as PNG bytes: the logo of the centre for the mail.
     *
     * @param string $image The bytes of the logo (PNG, JPEG, GIF or WebP).
     * @param int $height Height of the plate in pixels at 1x.
     * @param int $scale
     * @return array [png, width at 1x, height at 1x], or [] when the image cannot be read.
     */
    public static function plate(string $image, int $height = 48, int $scale = 2): array {
        $src = @imagecreatefromstring($image);
        if (!$src) {
            return [];
        }
        $pad = 6 * $scale;
        $radius = 12 * $scale;
        $h = $height * $scale;
        $lh = $h - 2 * $pad;
        $lw = max(1, (int) round(imagesx($src) * $lh / max(1, imagesy($src))));
        $w = $lw + 2 * $pad;
        // The plate is drawn four times larger and scaled down, for smooth corners.
        $ss = 4;
        $big = imagecreatetruecolor($w * $ss, $h * $ss);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        $white = imagecolorallocatealpha($big, 255, 255, 255, 0);
        $rr = $radius * $ss;
        imagefilledrectangle($big, $rr, 0, $w * $ss - $rr - 1, $h * $ss - 1, $white);
        imagefilledrectangle($big, 0, $rr, $w * $ss - 1, $h * $ss - $rr - 1, $white);
        foreach ([[$rr, $rr], [$w * $ss - $rr - 1, $rr], [$rr, $h * $ss - $rr - 1], [$w * $ss - $rr - 1, $h * $ss - $rr - 1]] as [$ex, $ey]) {
            imagefilledellipse($big, $ex, $ey, 2 * $rr, 2 * $rr, $white);
        }
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $big, 0, 0, 0, 0, $w, $h, $w * $ss, $h * $ss);
        imagealphablending($im, true);
        imagecopyresampled($im, $src, $pad, $pad, 0, 0, $lw, $lh, imagesx($src), imagesy($src));
        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);
        imagedestroy($big);
        imagedestroy($src);
        return [(string) ob_get_clean(), (int) round($w / $scale), $height];
    }

    /**
     * A photo cut to a size, as JPEG bytes: the faces of who receives the families, for the mail.
     *
     * @param string $image
     * @param int $width In pixels at 1x.
     * @param int $height In pixels at 1x.
     * @param int $scale
     * @return string Empty when the image cannot be read.
     */
    public static function crop(string $image, int $width, int $height, int $scale = 2): string {
        $src = @imagecreatefromstring($image);
        if (!$src) {
            return '';
        }
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $ratio = $width / $height;
        // The middle across; from the top down, where the face is.
        [$cw, $ch] = $sw / $sh > $ratio ? [(int) round($sh * $ratio), $sh] : [$sw, (int) round($sw / $ratio)];
        $im = imagecreatetruecolor($width * $scale, $height * $scale);
        imagecopyresampled($im, $src, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 6), $width * $scale,
            $height * $scale, $cw, $ch);
        ob_start();
        imagejpeg($im, null, 82);
        imagedestroy($im);
        imagedestroy($src);
        return (string) ob_get_clean();
    }
}
