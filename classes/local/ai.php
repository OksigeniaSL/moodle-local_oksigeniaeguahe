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
 * The artificial intelligence of each centre, through the AI subsystem of Moodle (5.0 and later, where providers are
 * instances): each centre chooses the instance with its own key, and Eguahe asks only that one. Moodle keeps its record
 * of each request and asks the team for its policy, as always. It works for the team, behind: never facing families,
 * never a mark on a household.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai {
    /** @var callable|null In the tests, what answers in place of the provider: a function of the prompt. */
    public static $answer = null;

    /**
     * Whether this Moodle has providers by instance.
     *
     * @return bool
     */
    public static function supported(): bool {
        return class_exists('\core_ai\manager') && method_exists('\core_ai\manager', 'get_sorted_providers');
    }

    /**
     * The provider instances of the site, for a centre to choose its own.
     *
     * @return array Instance id => name; empty where Moodle has no AI.
     */
    public static function providers(): array {
        if (!self::supported()) {
            return [];
        }
        $names = [];
        foreach (\core\di::get(\core_ai\manager::class)->get_sorted_providers() as $id => $provider) {
            $names[(int) $id] = format_string($provider->name);
        }
        return $names;
    }

    /**
     * The manager of Moodle that asks only the provider of a centre.
     *
     * @param \stdClass $centre
     * @return \core_ai\manager|null Null when the centre has none.
     */
    public static function manager(\stdClass $centre): ?\core_ai\manager {
        global $DB;
        if (!self::supported() || empty($centre->aiprovider)) {
            return null;
        }
        return (new ai_manager($DB))->only((int) $centre->aiprovider);
    }

    /**
     * The name of the provider of a centre, as Moodle shows it; empty without one.
     *
     * @param \stdClass $centre
     * @return string
     */
    public static function name(\stdClass $centre): string {
        if (self::$answer) {
            return 'Test';
        }
        return empty($centre->aiprovider) ? '' : (string) (self::providers()[(int) $centre->aiprovider] ?? '');
    }

    /**
     * Asks the provider of a centre and returns what it wrote.
     *
     * @param \stdClass $centre
     * @param string $prompt
     * @param int $userid Who asks: the person, or the site for a task.
     * @return string
     * @throws \moodle_exception When the centre has no provider or it does not answer.
     */
    public static function ask(\stdClass $centre, string $prompt, int $userid): string {
        if (self::$answer) {
            return (string) (self::$answer)($prompt);
        }
        $manager = self::manager($centre);
        if (!$manager) {
            throw new \moodle_exception('report_ai_missing', 'local_oksigeniaeguahe');
        }
        $action = new \core_ai\aiactions\generate_text(\context_system::instance()->id, $userid, $prompt);
        $response = $manager->process_action($action);
        if (!$response->get_success()) {
            throw new \moodle_exception('report_ai_error', 'local_oksigeniaeguahe', '', s($response->get_errormessage()));
        }
        return (string) ($response->get_response_data()['generatedcontent'] ?? '');
    }

    /**
     * The data an answer brings: the first object or list written in JSON in it, with or without a fence around.
     *
     * @param string $text
     * @return array|null
     */
    public static function json(string $text): ?array {
        $start = strcspn($text, '{[');
        if ($start >= strlen($text)) {
            return null;
        }
        $end = strrpos($text, $text[$start] === '{' ? '}' : ']');
        if ($end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Whether a centre can ask: a provider chosen, or the tests answering.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function ready(\stdClass $centre): bool {
        return self::$answer !== null || self::manager($centre) !== null;
    }
}
