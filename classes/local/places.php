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
 * Where the centre and the towns its families come from are, for the constellation of the cinematic skin: a town,
 * never a family. Any country: the positions come from a worldwide source the site chooses (local\places_sources), a
 * few at a time in a task, and are kept; when a name is in several places, the one nearest to the centre. The centre
 * can write its own position by hand.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class places {
    /** @var string */
    const T = 'local_oksigeniaeguahe_place';

    /** @var string The key of the centre itself among its places. */
    const CENTRE = '';

    /** @var int A name not found is asked again after this long. */
    const RETRY = 30 * DAYSECS;

    /** @var callable|null In the tests, what answers in place of the source: a function of the address asked. */
    public static $testanswer = null;

    /**
     * Every source of positions Eguahe has, found in its namespace.
     *
     * @return places_sources\source[] Key => source.
     */
    public static function sources(): array {
        $out = [];
        $classes = \core_component::get_component_classes_in_namespace('local_oksigeniaeguahe', 'local\\places_sources');
        foreach (array_keys($classes) as $class) {
            if (is_subclass_of($class, places_sources\source::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $source = new $class();
                $out[$source->key()] = $source;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The source the site chose, when it can be asked; else the first that can.
     *
     * @return places_sources\source|null
     */
    public static function source(): ?places_sources\source {
        $sources = array_filter(self::sources(), fn($s) => $s->ready());
        $chosen = (string) get_config('local_oksigeniaeguahe', 'placessource');
        return $sources[$chosen] ?? (reset($sources) ?: null);
    }

    /**
     * A name as a key: without accents, in lower case.
     *
     * @param string $name
     * @return string
     */
    public static function key(string $name): string {
        return \core_text::substr(grades::plain($name), 0, 255);
    }

    /**
     * The positions kept for a centre: key => [lat, lon]; the centre itself under CENTRE.
     *
     * @param int $centreid
     * @return array
     */
    public static function known(int $centreid): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records_select(self::T, 'centreid = ? AND lat IS NOT NULL', [$centreid], '', 'id, plain, lat, lon');
        foreach ($rows as $r) {
            $out[(string) $r->plain] = [(float) $r->lat, (float) $r->lon];
        }
        return $out;
    }

    /**
     * Who to cite for the positions of a centre, as their rules ask: the sources of what was found.
     *
     * @param int $centreid
     * @return string
     */
    public static function credit(int $centreid): string {
        global $DB;
        $sources = $DB->get_fieldset_select(self::T, 'DISTINCT source', 'centreid = ? AND lat IS NOT NULL', [$centreid]);
        $manager = get_string_manager();
        $out = [];
        foreach ($sources as $source) {
            if ($manager->string_exists('places_credit_' . $source, 'local_oksigeniaeguahe')) {
                $out[] = get_string('places_credit_' . $source, 'local_oksigeniaeguahe');
            }
        }
        return implode(' ', $out);
    }

    /**
     * Keeps where a place of a centre is, or that it was not found.
     *
     * @param int $centreid
     * @param string $name
     * @param array|null $where [lat, lon], or null when not found.
     * @param string $source
     * @param int $now
     */
    public static function keep(int $centreid, string $name, ?array $where, string $source, int $now): void {
        global $DB;
        $key = $name === self::CENTRE ? self::CENTRE : self::key($name);
        $row = (object) ['centreid' => $centreid, 'plain' => $key, 'name' => \core_text::substr($name, 0, 255),
            'lat' => $where ? round($where[0], 6) : null, 'lon' => $where ? round($where[1], 6) : null, 'source' => $source,
            'timefetched' => $now];
        $id = $DB->get_field(self::T, 'id', ['centreid' => $centreid, 'plain' => $key]);
        if ($id) {
            $row->id = $id;
            $DB->update_record(self::T, $row);
        } else {
            $DB->insert_record(self::T, $row);
        }
    }

    /**
     * The towns a centre needs on its maps: where its families say they live and the towns of its area.
     *
     * @param \stdClass $centre
     * @return string[]
     */
    public static function wanted(\stdClass $centre): array {
        global $DB;
        $names = $DB->get_fieldset_select(
            households::T,
            'DISTINCT municipalityname',
            "centreid = ? AND municipalityname IS NOT NULL AND municipalityname <> ''",
            [$centre->id]
        );
        $names = array_merge($names, array_values(centres::municipalities($centre)));
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[self::key($name)] = $name;
            }
        }
        return array_values($out);
    }

    /**
     * Finds, a few at a time, where the centre and its towns are, and keeps it: the centre by its address (else by its
     * first town), each town by its name in the country of the centre, the nearest to the centre when there are several.
     * Names not found are asked again after a month.
     *
     * @param \stdClass $centre
     * @param int $max The questions at most, this time.
     * @param int $now
     * @return int How many were asked.
     */
    public static function locate(\stdClass $centre, int $max, int $now): int {
        global $DB;
        $source = self::source();
        if (!$source || $max < 1) {
            return 0;
        }
        $country = (string) ($centre->country ?? '');
        $kept = $DB->get_records_menu(self::T, ['centreid' => $centre->id], '', 'plain, timefetched');
        $known = self::known((int) $centre->id);
        $due = fn(string $key) => !isset($kept[$key]) || (!isset($known[$key]) && (int) $kept[$key] < $now - self::RETRY);
        $asked = 0;
        $ask = function (string $query) use ($source, $country, &$asked): array {
            if ($asked && self::$testanswer === null) {
                usleep((int) ($source->pause() * 1000000));
            }
            $asked++;
            return $source->find($query, $country);
        };
        try {
            if ($due(self::CENTRE)) {
                // By its address; if it is not found, by the first town of its area.
                $towns = array_values(centres::municipalities($centre));
                $found = [];
                foreach (array_unique(array_filter([trim((string) ($centre->address ?? '')), (string) ($towns[0] ?? '')])) as $q) {
                    $found = $ask($q);
                    if ($found) {
                        break;
                    }
                }
                if ($asked) {
                    self::keep((int) $centre->id, self::CENTRE, $found[0] ?? null, $source->key(), $now);
                    $known = self::known((int) $centre->id);
                }
            }
            $here = $known[self::CENTRE] ?? null;
            foreach (self::wanted($centre) as $name) {
                if ($asked >= $max) {
                    break;
                }
                if (!$due(self::key($name))) {
                    continue;
                }
                $found = $ask($name);
                if ($here && count($found) > 1) {
                    usort($found, fn($a, $b) => self::distance($here, $a) <=> self::distance($here, $b));
                }
                self::keep((int) $centre->id, $name, $found[0] ?? null, $source->key(), $now);
            }
        } catch (\moodle_exception $e) {
            debugging('Eguahe places: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $asked;
    }

    /**
     * The kilometres east and north of a place from another, near enough for the towns around a centre.
     *
     * @param array $from [lat, lon]
     * @param array $to [lat, lon]
     * @return array [east, north]
     */
    public static function offset(array $from, array $to): array {
        $east = ($to[1] - $from[1]) * 111.32 * cos(deg2rad(($from[0] + $to[0]) / 2));
        $north = ($to[0] - $from[0]) * 110.57;
        return [$east, $north];
    }

    /**
     * The distance between two places, in kilometres.
     *
     * @param array $from
     * @param array $to
     * @return float
     */
    public static function distance(array $from, array $to): float {
        [$east, $north] = self::offset($from, $to);
        return hypot($east, $north);
    }
}
