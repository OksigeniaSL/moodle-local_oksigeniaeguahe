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
 * The drawing a child makes while waiting at the visit: someone of the team takes it with the phone at the door, cut to
 * the sheet and straightened, and it goes to the child. One for each child; it goes with the family when the family goes.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class drawings {
    /** @var string The file area, by child. */
    const AREA = 'drawing';

    /** @var string[] The pictures a drawing can be. */
    const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @var int The largest picture taken, in bytes. */
    const MOST = 12 * 1024 * 1024;

    /** @var int The most pixels a picture may have: more would take the memory of the server to open it. */
    const PIXELS = 40 * 1000 * 1000;

    /** @var int The longest side kept: enough for any screen, and a photo from a phone weighs less. */
    const SIDE = 2400;

    /** @var string The strokes of the drawing, found in the browser, for it to draw itself on the page of its family. */
    const STROKES = 'drawingstrokes';

    /** @var int The largest strokes kept, in bytes: a drawing gives from 9 to 44 KB. */
    const STROKESMOST = 200 * 1024;

    /**
     * The file of the drawing of a child.
     *
     * @param int $childid
     * @return \stored_file|null
     */
    public static function file(int $childid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            self::AREA,
            $childid,
            'timemodified DESC',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * The address of the drawing of a child, for the team of its centre; with its time, so a new one is seen at once.
     *
     * @param int $childid
     * @return \moodle_url|null
     */
    public static function url(int $childid): ?\moodle_url {
        $file = self::file($childid);
        if (!$file) {
            return null;
        }
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'local_oksigeniaeguahe',
            self::AREA,
            $childid,
            '/',
            $file->get_filename(),
            false
        );
        $url->param('t', $file->get_timemodified());
        return $url;
    }

    /**
     * Keeps the drawing of a child, instead of the one there was.
     *
     * @param \stdClass $child
     * @param string $bytes A picture: JPEG, PNG or WebP.
     * @param string|null $strokes Its strokes, as dibujo-vivo.js gives them; without them, the photo stays still.
     * @return bool False when it is not a picture of those, or too big.
     */
    public static function save(\stdClass $child, string $bytes, ?string $strokes = null): bool {
        $info = $bytes !== '' && strlen($bytes) <= self::MOST ? @getimagesizefromstring($bytes) : false;
        if (!$info || !isset(self::TYPES[$info['mime']]) || $info[0] * $info[1] > self::PIXELS) {
            return false;
        }
        [$bytes, $info] = self::clean($bytes, $info);
        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_oksigeniaeguahe', self::AREA, (int) $child->id);
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'local_oksigeniaeguahe',
            'filearea' => self::AREA, 'itemid' => (int) $child->id, 'filepath' => '/',
            'filename' => 'dibujo.' . self::TYPES[$info['mime']]], $bytes);
        // The strokes of the new photo, or none: those of the one before would draw another drawing.
        $fs->delete_area_files($context->id, 'local_oksigeniaeguahe', self::STROKES, (int) $child->id);
        if ($strokes !== null && self::valid_strokes($strokes)) {
            $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'local_oksigeniaeguahe',
                'filearea' => self::STROKES, 'itemid' => (int) $child->id, 'filepath' => '/',
                'filename' => 'trazos.json'], $strokes);
        }
        return true;
    }

    /**
     * A photo as it is kept: without what the camera wrote in it (the place, the phone, the hour), turned the way it was
     * taken, and no longer than SIDE. What the browser already cut on a canvas carries none of that and stays as it is.
     *
     * @param string $bytes
     * @param array $info As getimagesizefromstring() gives it.
     * @return array [bytes, info]
     */
    public static function clean(string $bytes, array $info): array {
        $exif = $info['mime'] === 'image/jpeg' && strpos(substr($bytes, 0, 65536), "Exif\0\0") !== false;
        $big = max($info[0], $info[1]) > self::SIDE;
        if ((!$exif && !$big) || !function_exists('imagecreatefromstring')) {
            return [$bytes, $info];
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image) {
            return [$bytes, $info];
        }
        if ($exif) {
            $turn = [3 => 180, 6 => -90, 8 => 90][self::orientation($bytes)] ?? 0;
            if ($turn) {
                $image = imagerotate($image, $turn, 0) ?: $image;
            }
        }
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) > self::SIDE) {
            $scale = self::SIDE / max($w, $h);
            $image = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) ?: $image;
        }
        ob_start();
        if ($info['mime'] === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else if ($info['mime'] === 'image/webp' && function_exists('imagewebp')) {
            imagewebp($image, null, 88);
        } else {
            imagejpeg($image, null, 88);
        }
        $out = (string) ob_get_clean();
        $outinfo = $out !== '' ? @getimagesizefromstring($out) : false;
        return $outinfo ? [$out, $outinfo] : [$bytes, $info];
    }

    /**
     * The orientation the camera wrote in a JPEG (1 when it says nothing), read from its EXIF without any extension.
     *
     * @param string $bytes
     * @return int
     */
    public static function orientation(string $bytes): int {
        $at = strpos(substr($bytes, 0, 65536), "Exif\0\0");
        if ($at === false) {
            return 1;
        }
        $tiff = $at + 6;
        $little = substr($bytes, $tiff, 2) === 'II';
        $short = fn(int $o) => (int) unpack($little ? 'v' : 'n', substr($bytes, $o, 2))[1];
        $long = fn(int $o) => (int) unpack($little ? 'V' : 'N', substr($bytes, $o, 4))[1];
        if (strlen($bytes) < $tiff + 8) {
            return 1;
        }
        $ifd = $tiff + $long($tiff + 4);
        if (strlen($bytes) < $ifd + 2) {
            return 1;
        }
        $count = min(200, $short($ifd));
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if (strlen($bytes) < $entry + 12) {
                break;
            }
            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);
                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }

    /**
     * Whether some strokes are as dibujo-vivo.js gives them (version 1): a size of its own, the paper and the strokes,
     * each with numbers only.
     *
     * @param string $strokes
     * @return bool
     */
    public static function valid_strokes(string $strokes): bool {
        if ($strokes === '' || strlen($strokes) > self::STROKESMOST) {
            return false;
        }
        $data = json_decode($strokes, true);
        $shaped = is_array($data) && ($data['v'] ?? null) === 1 && is_numeric($data['w'] ?? null) &&
            is_numeric($data['h'] ?? null) && is_string($data['paper']['col'] ?? null) && is_array($data['strokes'] ?? null) &&
            $data['w'] > 0 && $data['w'] <= 10000 && $data['h'] > 0 && $data['h'] <= 10000 &&
            strlen($data['paper']['col']) <= 32;
        if (!$shaped) {
            return false;
        }
        foreach ($data['strokes'] as $stroke) {
            if (!is_array($stroke) || !is_array($stroke['p'] ?? null)) {
                return false;
            }
            foreach ($stroke['p'] as $n) {
                if (!is_int($n) && !is_float($n)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The strokes of the drawing of a child, when it was kept with them.
     *
     * @param int $childid
     * @return \stored_file|null
     */
    public static function strokes_file(int $childid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniaeguahe',
            self::STROKES,
            $childid,
            'timemodified DESC',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * Removes the drawing of a child.
     *
     * @param int $childid
     */
    public static function delete(int $childid): void {
        $contextid = \context_system::instance()->id;
        get_file_storage()->delete_area_files($contextid, 'local_oksigeniaeguahe', self::AREA, $childid);
        get_file_storage()->delete_area_files($contextid, 'local_oksigeniaeguahe', self::STROKES, $childid);
    }
}
