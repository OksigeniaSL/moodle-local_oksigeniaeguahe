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
 * The documents that leave Moodle whole: the mail to the families and the poster to print. They are documents of
 * their own (HTML of mail, with its tables and attributes that the mail programs need; a page sized to the paper), so
 * they live in documents/ and not among the templates of the pages, and are filled with the same Mustache.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class documents {
    /**
     * A document filled with its context.
     *
     * @param string $name Its file in documents/, without «.mst».
     * @param array $context
     * @return string
     */
    public static function render(string $name, array $context): string {
        global $CFG;
        if (!preg_match('/^[a-z_.]+$/', $name)) {
            throw new \coding_exception('Unknown document ' . $name);
        }
        // Not «.mustache»: they are whole documents, not templates of the pages, and the linter of templates would
        // judge the HTML of mail by the rules of the web.
        $source = (string) file_get_contents($CFG->dirroot . '/local/oksigeniaeguahe/documents/' . $name . '.mst');
        return (new \core\output\mustache_engine())->render($source, $context);
    }
}
