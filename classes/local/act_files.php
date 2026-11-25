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
 * The imported files that are of an act: what happened on the open day of last March is that open day, with what is known of
 * it, whichever file it came in. Its day is the one the label writes whole, or the one a person says; when it cannot be known,
 * it is asked and never made up.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class act_files {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /**
     * The files of a centre that are of an act, or may be.
     *
     * @param \stdClass $centre
     * @return array Import id => id, label, name (without its day nor what is in brackets), time (its day, or 0), state (act; ask,
     *     it may be one or its day is not known), rows and imported (when)
     */
    public static function of_centre(\stdClass $centre): array {
        global $DB;
        $out = [];
        $imports = imports::for_centre((int) $centre->id, 'contacts');
        if (!$imports) {
            return $out;
        }
        $doors = $DB->get_records_sql_menu(
            "SELECT importid, COUNT(1)
               FROM {" . metrics::CONTACTS . "}
              WHERE centreid = :centreid AND channel IN ('openday', 'event', 'visit')
           GROUP BY importid",
            ['centreid' => $centre->id]
        );
        foreach ($imports as $import) {
            $said = (string) ($import->act ?? '');
            if ($said === 'no') {
                continue;
            }
            $label = (string) $import->label;
            $time = $said === 'yes' ? (int) $import->acttime : self::day($label);
            $maybe = self::names_act($label) || 2 * (int) ($doors[$import->id] ?? 0) > max(1, (int) $import->rowcount);
            if ($said !== 'yes' && !$maybe) {
                continue;
            }
            $out[(int) $import->id] = ['id' => (int) $import->id, 'label' => $label, 'name' => self::name($label), 'time' => $time,
                'state' => $time ? 'act' : 'ask', 'rows' => (int) $import->rowcount, 'imported' => (int) $import->timecreated];
        }
        return $out;
    }

    /**
     * The acts the files are of, by their day, the newest first.
     *
     * @param \stdClass $centre
     * @return array Y-m-d => time, name and files (import ids)
     */
    public static function days(\stdClass $centre): array {
        $out = [];
        foreach (self::of_centre($centre) as $file) {
            if ($file['state'] !== 'act') {
                continue;
            }
            $day = userdate($file['time'], '%Y-%m-%d', 99, false);
            $out[$day] = $out[$day] ?? ['time' => $file['time'], 'name' => $file['name'], 'files' => []];
            $out[$day]['files'][] = $file['id'];
        }
        krsort($out);
        return $out;
    }

    /**
     * What a person says of a file: that it is of an act of a day, or that it is of none.
     *
     * @param \stdClass $centre
     * @param int $importid
     * @param bool $is
     * @param string $day Y-m-d.
     * @return bool
     */
    public static function say(\stdClass $centre, int $importid, bool $is, string $day = ''): bool {
        global $DB;
        $time = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? panel_eventform::stamp($day, '12:00') : 0;
        if (($is && !$time) || !$DB->record_exists(imports::T, ['id' => $importid, 'centreid' => $centre->id, 'kind' => 'contacts'])) {
            return false;
        }
        $DB->update_record(imports::T, (object) ['id' => $importid, 'act' => $is ? 'yes' : 'no', 'acttime' => $is ? $time : 0]);
        return true;
    }

    /**
     * Whether a label names an act.
     *
     * @param string $label
     * @return bool
     */
    public static function names_act(string $label): bool {
        return (bool) preg_match('~' . str_replace('~', '\~', self::words()['acts']) . '~u', recognise::plain($label));
    }

    /**
     * The day a label writes whole: «14 de marzo de 2026», «March 14, 2026», «14/03/2026», «2026-03-14».
     *
     * @param string $label
     * @return int 0 when it does not.
     */
    public static function day(string $label): int {
        [$found] = self::written($label);
        return $found;
    }

    /**
     * The day a label writes and the words that say it, in plain letters.
     *
     * @param string $label
     * @return array time and the words
     */
    protected static function written(string $label): array {
        $plain = recognise::plain($label);
        $months = self::words()['months'];
        $names = implode('|', array_map('preg_quote', array_keys($months)));
        $at = fn(int $y, int $m, int $d) => checkdate($m, $d, $y) ? make_timestamp($y, $m, $d, 12) : 0;
        if (preg_match('~\b(\d{1,2}) (?:de |of )?(' . $names . ')(?: de| of|,)? (\d{4})\b~u', $plain, $m)) {
            return [$at((int) $m[3], $months[$m[2]], (int) $m[1]), $m[0]];
        }
        if (preg_match('~\b(' . $names . ') (\d{1,2})(?:st|nd|rd|th)?,? (\d{4})\b~u', $plain, $m)) {
            return [$at((int) $m[3], $months[$m[1]], (int) $m[2]), $m[0]];
        }
        if (preg_match('~\b(\d{4})[-/. ](\d{1,2})[-/. ](\d{1,2})\b~', $plain, $m)) {
            return [$at((int) $m[1], (int) $m[2], (int) $m[3]), $m[0]];
        }
        if (preg_match('~\b(\d{1,2})[-/. ](\d{1,2})[-/. ](\d{4})\b~', $plain, $m) && (int) $m[2] <= 12 && (int) $m[1] > 12) {
            return [$at((int) $m[3], (int) $m[2], (int) $m[1]), $m[0]];
        }
        return [0, ''];
    }

    /**
     * The name of the act of a label: without what is in brackets nor its day.
     *
     * @param string $label
     * @return string
     */
    public static function name(string $label): string {
        $name = preg_replace('/\s*\([^)]*\)/u', '', $label);
        $months = implode('|', array_map('preg_quote', array_keys(self::words()['months'])));
        $name = preg_replace('~[\s,·-]*\b(?:\d{1,2}\s*(?:y|e|and|al?|-)\s*)?\d{1,2}\s+(?:de\s+|of\s+)?(?:' . $months . ')(?:\s+de|\s+of|,)?\s+\d{4}\b~iu', '', $name);
        $name = preg_replace('~[\s,·-]*\b(\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{4})\b~', '', $name);
        return trim($name, " \t,·-") ?: $label;
    }

    /**
     * The words of the data file.
     *
     * @return array acts (an expression) and months
     */
    protected static function words(): array {
        static $words = null;
        $words = $words ?? (json_decode((string) file_get_contents(__DIR__ . '/../../data/act-words.json'), true) ?: ['acts' => '$^', 'months' => []]);
        return $words;
    }
}
