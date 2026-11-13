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
 * The AI manager of Moodle kept to one provider instance: Moodle asks the providers of an action in their order and
 * the first that answers wins, the same for every plugin; Eguahe asks only the instance of the centre, with its key.
 * Only loaded where Moodle has providers by instance (5.0 and later).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_manager extends \core_ai\manager {
    /** @var int The provider instance asked for. */
    protected int $only = 0;

    /**
     * Ask only one provider.
     *
     * @param int $instanceid
     * @return static
     */
    public function only(int $instanceid): static {
        $this->only = $instanceid;
        return $this;
    }

    /**
     * The providers Moodle asks, in its order: only the one of the centre. Without #[\Override]: Moodle 4.5 has no
     * such method, and the attribute would stop the class from loading there.
     *
     * @return array
     */
    public function get_sorted_providers(): array {
        $all = parent::get_sorted_providers();
        return isset($all[$this->only]) ? [$this->only => $all[$this->only]] : [];
    }
}
