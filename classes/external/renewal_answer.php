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

namespace local_oksigeniaeguahe\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniaeguahe\local\atycards;
use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\renewal;

/**
 * An adult of a family says from the app whether a child goes on next school year: the page of Eguahe in the app calls
 * it. Only for a family of theirs (atycards::households_of()), as their page does it (renewal::from_family()).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renewal_answer extends external_api {
    /**
     * What it takes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'householdid' => new external_value(PARAM_INT, 'The family'),
            'childid' => new external_value(PARAM_INT, 'The child it is about'),
            'answer' => new external_value(PARAM_ALPHA, 'stay, leave or unsure'),
            'reason' => new external_value(PARAM_ALPHA, 'Why they leave, if they say', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Keeps the answer, as the family and only for a family of the caller.
     *
     * @param int $householdid
     * @param int $childid
     * @param string $answer
     * @param string $reason
     * @return array saved.
     */
    public static function execute(int $householdid, int $childid, string $answer, string $reason = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['householdid' => $householdid, 'childid' => $childid,
            'answer' => $answer, 'reason' => $reason]);
        self::validate_context(\context_system::instance());
        $mine = atycards::households_of((int) $USER->id)[$params['householdid']] ?? null;
        $centre = $mine ? centres::get((int) $mine->centreid) : null;
        if (!$mine || !$centre) {
            throw new \moodle_exception('apprenewal_notyours', 'local_oksigeniaeguahe');
        }
        $household = households::get((int) $mine->id, (int) $centre->id);
        $saved = renewal::from_family(
            $centre,
            $household,
            $params['childid'],
            $params['answer'],
            $params['reason'],
            null,
            (string) ($household->lang ?: $centre->lang),
            time()
        );
        return ['saved' => $saved];
    }

    /**
     * What it gives back.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether it was kept'),
        ]);
    }
}
