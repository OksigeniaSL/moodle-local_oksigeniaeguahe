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
 * The guide of a centre: a little dog of paper and felt (the art director; Enrique chose it on 13 November 2026) that
 * welcomes the family, shows the way on the miniature of the centre and introduces whoever receives them. What it says
 * is written by the centre, or the words of Eguahe; it is not an AI. Each centre has it or not.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guide {
    /** @var string[] The guides there are. */
    const KINDS = ['perro'];

    /** @var float How wide the guide stands on a miniature, as a part of its width (.guia-mapa in pub/eguahe.css). */
    const WIDE = 0.14;

    /**
     * The guide of a centre, or '' without one.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function of(\stdClass $centre): string {
        $kind = (string) ($centre->guide ?? '');
        return in_array($kind, self::KINDS, true) ? $kind : '';
    }

    /**
     * A gesture of a guide as a picture: at 256 px, and at 512 for screens of two pixels per point.
     *
     * @param string $kind
     * @param string $gesture saluda, senala or presenta.
     * @return array src and srcset.
     */
    public static function picture(string $kind, string $gesture): array {
        $base = (new \moodle_url('/local/oksigeniaeguahe/pub/guia/'))->out(false) . $kind . '-' . $gesture;
        return ['src' => $base . '-256.webp', 'srcset' => $base . '-256.webp 1x, ' . $base . '-512.webp 2x'];
    }

    /**
     * Where the way drawn on a miniature starts and ends, as parts of its width and height: the first and last point of
     * its path of class «ruta» (the recipe of the miniatures keeps it, and it always ends at the door of the place).
     *
     * @param string $svg
     * @return array x, y, endx, endy, up (whether its first stretch goes up), ratio (width over height) and marks (the
     *     labels and the pin, which the bubble never covers: x, y, w, h); empty when the picture has no way.
     */
    public static function read_way(string $svg): array {
        if (!preg_match('/viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/', $svg, $box)) {
            return [];
        }
        if (!preg_match('/<path\b[^>]*\bclass="ruta"[^>]*>/', $svg, $tag) || !preg_match('/\sd="([^"]*)"/', $tag[0], $d)) {
            return [];
        }
        preg_match_all('/-?\d+(?:\.\d+)?/', $d[1], $n);
        $n = $n[0];
        [$w, $h] = [(float) $box[1], (float) $box[2]];
        if (count($n) < 4 || $w <= 0 || $h <= 0) {
            return [];
        }
        $part = fn($v, $of) => round(min(1, max(0, (float) $v / $of)), 4);
        return ['x' => $part($n[0], $w), 'y' => $part($n[1], $h), 'endx' => $part($n[count($n) - 2], $w),
            'endy' => $part($n[count($n) - 1], $h), 'up' => (float) $n[3] < (float) $n[1], 'ratio' => round($w / $h, 4),
            'marks' => self::marks($svg, $w, $h)];
    }

    /**
     * The labels of a miniature (its rectangles) and its pin (a group moved and scaled, as the recipe draws it), as
     * boxes in parts of the picture, a little bigger than they are.
     *
     * @param string $svg
     * @param float $w Width of the picture.
     * @param float $h Height of the picture.
     * @return array[] x, y, w, h.
     */
    protected static function marks(string $svg, float $w, float $h): array {
        $boxes = [];
        $pad = 6;
        preg_match_all('/<rect\b[^>]*>/', $svg, $rects);
        foreach ($rects[0] as $rect) {
            $at = [];
            foreach (['x', 'y', 'width', 'height'] as $k) {
                $at[$k] = preg_match('/\s' . $k . '="(-?[\d.]+)"/', $rect, $m) ? (float) $m[1] : null;
            }
            if ($at['x'] !== null && $at['y'] !== null && $at['width'] && $at['height'] && $at['width'] < $w * 0.6) {
                $boxes[] = [$at['x'] - $pad, $at['y'] - $pad, $at['width'] + 2 * $pad, $at['height'] + 2 * $pad];
            }
        }
        $pin = '/<g\b[^>]*transform="translate\((-?[\d.]+)[ ,]+(-?[\d.]+)\)\s*scale\(([\d.]+)\)"/';
        preg_match_all($pin, $svg, $pins, PREG_SET_ORDER);
        foreach ($pins as [, $x, $y, $s]) {
            $boxes[] = [(float) $x - 7 * $s, (float) $y - 18 * $s, 14 * $s, 20 * $s];
        }
        $part = fn($b) => [round($b[0] / $w, 4), round($b[1] / $h, 4), round($b[2] / $w, 4), round($b[3] / $h, 4)];
        return array_map($part, $boxes);
    }

    /**
     * The way of a picture of the miniature of a centre, read once for each version of the picture.
     *
     * @param int $centreid
     * @param string $area One of centres::MINIATURES.
     * @return array|null read_way(), or null without a way.
     */
    public static function way(int $centreid, string $area): ?array {
        $context = \context_system::instance()->id;
        $files = get_file_storage()->get_area_files($context, 'local_oksigeniaeguahe', $area, $centreid, 'filename', false);
        $file = reset($files);
        if (!$file || $file->get_mimetype() !== 'image/svg+xml') {
            return null;
        }
        $cache = \cache::make('local_oksigeniaeguahe', 'miniatureways');
        $way = $cache->get($file->get_contenthash());
        if ($way === false) {
            $way = self::read_way($file->get_content());
            $cache->set($file->get_contenthash(), $way);
        }
        return $way ?: null;
    }

    /**
     * The guide on the miniature, at the entrance: it walks the way the way goes and says where it takes them.
     *
     * @param \stdClass $centre
     * @param string $area The picture shown: miniature or miniatureparking.
     * @param bool $narrow Whether phones see the cut for phones.
     * @param string $lang
     * @return array|false img, bubble and style (where it stands, and which way it looks).
     */
    public static function on_map(\stdClass $centre, string $area, bool $narrow, string $lang) {
        $kind = self::of($centre);
        $way = $kind ? self::way((int) $centre->id, $area) : null;
        if (!$way) {
            return false;
        }
        $style = self::spot($way, '');
        $phone = $narrow ? self::way((int) $centre->id, 'miniaturenarrow') : null;
        $style .= $phone ? self::spot($phone, 'n') : self::spot($way, 'n');
        $where = trim((string) ($centre->guidewhere ?? ''));
        // What the bubble never covers, for the page to choose its side; without script, behind the guide.
        $avoid = implode(';', array_map(fn($b) => implode(',', $b), $way['marks'] ?? []));
        return ['img' => self::picture($kind, 'senala'), 'style' => $style, 'avoid' => $avoid,
            'bubble' => $where !== '' ? publiclang::str('pub_guide_to', $lang, format_string($where)) :
                publiclang::str('pub_guide_from', $lang)];
    }

    /**
     * Where the guide stands on a picture and which way it looks, as custom properties of CSS: where families arrive,
     * just before the way starts and without covering it (below the entrance when the way goes up into the centre, at
     * it when it goes down), looking where the way goes.
     *
     * @param array $way read_way()
     * @param string $suffix '' for the picture of wide screens, n for that of phones.
     * @return string
     */
    protected static function spot(array $way, string $suffix): string {
        // It walks to the right; when the way goes to the left, it turns round.
        $flip = $way['endx'] < $way['x'] ? -1 : 1;
        // Its height as a part of the picture: it is square and 14 % as wide as the picture (.guia-mapa).
        $tall = self::WIDE * (float) ($way['ratio'] ?? 1.6);
        $top = !empty($way['up']) ? $way['y'] - 0.04 * $tall : $way['y'] - $tall;
        $x = $way['x'];
        if ($top + $tall > 0.98) {
            // Below the entrance it would go out of the picture: it goes up, aside from where the way goes.
            $top = 0.98 - $tall;
            $x -= $flip * self::WIDE * 0.75;
        }
        $top = max(0, $top);
        $x = min(1 - self::WIDE / 2, max(self::WIDE / 2, $x));
        $rise = round(($top - $way['y']) / $tall * 100, 1);
        return '--x' . $suffix . ':' . round($x * 100, 2) . '%;--y' . $suffix . ':' . round($way['y'] * 100, 2) .
            '%;--giro' . $suffix . ':' . $flip . ';--alto' . $suffix . ':' . $rise . '%;';
    }

    /**
     * The guide by the greeting of the page of a family with a visit to come: it waves and says what the centre wrote.
     *
     * @param \stdClass $centre
     * @param string $lang
     * @return array|false img and bubble.
     */
    public static function hello(\stdClass $centre, string $lang) {
        $kind = self::of($centre);
        if (!$kind) {
            return false;
        }
        $said = trim((string) ($centre->guidehello ?? ''));
        return ['img' => self::picture($kind, 'saluda'),
            'bubble' => $said !== '' ? format_string($said) : publiclang::str('pub_guide_hello', $lang)];
    }

    /**
     * The guide by those who receive the family: it holds out a paw and says what the cards do not, where the first of
     * them waits for them (Jujol).
     *
     * @param \stdClass $centre
     * @param array $hosts familypage::hosts()
     * @param string $lang
     * @return array|false img and bubble.
     */
    public static function presents(\stdClass $centre, array $hosts, string $lang) {
        $kind = self::of($centre);
        $names = array_values(array_filter(array_map(fn($h) => trim((string) ($h['first'] ?: $h['name'])), $hosts)));
        if (!$kind || !$names) {
            return false;
        }
        $where = trim((string) ($centre->guidewhere ?? ''));
        return ['img' => self::picture($kind, 'presenta'), 'bubble' => $where !== '' ?
            publiclang::str('pub_guide_waits', $lang, (object) ['name' => $names[0], 'where' => format_string($where)]) :
            publiclang::str('pub_guide_opens', $lang, $names[0])];
    }
}
