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
 * Stories to read at home, with Oksigenia Ayvurenda in the same Moodle: the centre chooses its library, and the page of
 * each family carries a link per child to the stories the library marked by hand to read together at home, by the age
 * of the course the child is coming to. Without Ayvurenda, or an Ayvurenda without its api, nothing shows.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tales {
    /** @var int Stories to read at home are for children up to this age. */
    const MAXAGE = 12;

    /**
     * Whether the site has an Ayvurenda with stories to read at home.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists('\local_oksigeniaayvurenda\api') && method_exists('\local_oksigeniaayvurenda\api', 'tales_url') &&
            method_exists('\local_oksigeniaayvurenda\api', 'libraries');
    }

    /**
     * The libraries of the site open to anyone, with whether they have stories marked already.
     *
     * @return array A list of slug, name and tales.
     */
    public static function libraries(): array {
        if (!self::available()) {
            return [];
        }
        try {
            $out = [];
            foreach (\local_oksigeniaayvurenda\api::libraries() as $library) {
                $library = (array) $library;
                if (!empty($library['open']) && (string) ($library['slug'] ?? '') !== '') {
                    $out[] = ['slug' => (string) $library['slug'], 'name' => (string) ($library['name'] ?? $library['slug']),
                        'tales' => !empty($library['tales'])];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The address of the stories for a child, by the age of the course it is coming to; null when the centre chose no
     * library, the child is older than the stories, or the library is closed.
     *
     * @param \stdClass $centre
     * @param string $course The name of the course of the child, or '' when not known yet.
     * @return string|null
     */
    public static function url(\stdClass $centre, string $course): ?string {
        $slug = trim((string) ($centre->library ?? ''));
        if ($slug === '' || !self::available()) {
            return null;
        }
        $age = $course !== '' ? grades::age($course, $centre) : null;
        if ($age !== null && $age > self::MAXAGE) {
            return null;
        }
        try {
            $url = \local_oksigeniaayvurenda\api::tales_url($slug, $age);
            return $url ? $url->out(false) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
