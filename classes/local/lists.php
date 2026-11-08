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
 * The closed lists of Eguahe: the stages of the way, the channels, how a family heard of the centre and the languages
 * of the families. Closed, and with a definition each, because the numbers of one year must compare with the next.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lists {
    /** @var string[] Stages of a child's way, in order. «closed» stops it, «waitlist» waits beside «admitted». */
    const STAGES = ['inquiry', 'visit', 'application', 'admitted', 'waitlist', 'enrolled', 'closed'];

    /** @var array How far each stage goes, to move a way forward and never back by itself. */
    const STAGE_STEP = ['inquiry' => 1, 'visit' => 2, 'application' => 3, 'admitted' => 4, 'waitlist' => 4,
        'enrolled' => 5, 'closed' => 9];

    /** @var string[] Why a way stopped. */
    const CLOSED_REASONS = ['othercentre', 'moved', 'noplace', 'cost', 'noanswer', 'other'];

    /** @var string[] Where a household can come from. */
    const CHANNELS = ['openday', 'visit', 'event', 'webform', 'phone', 'walkin', 'email', 'whatsapp', 'telegram', 'meta',
        'googleforms', 'other'];

    /** @var string What a family writes from its own page after a visit: never a way it came in by. */
    const PAGE = 'page';

    /** @var string[] Channels a person of the team notes by hand. */
    const HAND_CHANNELS = ['phone', 'walkin', 'email', 'whatsapp', 'telegram', 'openday', 'other'];

    /** @var string[] How a household heard of the centre, as it says it: discovery, never the last click. */
    const HEARD_FROM = ['family', 'centrefamilies', 'social', 'search', 'ad', 'press', 'passingby', 'fair', 'other'];

    /** @var string[] Channels a household gives permission for. */
    const CONSENT_CHANNELS = ['email', 'whatsapp', 'phone'];

    /** @var string[] Languages offered to families; each has the strings of the plugin. */
    const LANGS = ['es', 'en', 'pt_br', 'pt'];

    /** @var array Each language of the families in its own words. */
    const LANG_NAMES = ['es' => 'Español', 'en' => 'English', 'pt_br' => 'Português (Brasil)',
        'pt' => 'Português (Portugal)'];

    /**
     * Whether a stage goes further than another, to move a way forward without moving it back.
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function further(string $from, string $to): bool {
        if ($from === 'closed' || $from === 'enrolled') {
            return false;
        }
        return (self::STAGE_STEP[$to] ?? 0) > (self::STAGE_STEP[$from] ?? 0) && $to !== 'closed';
    }

    /**
     * A list as options for a form: value => name in the language of the page.
     *
     * @param string[] $values
     * @param string $prefix Of the language strings.
     * @return array
     */
    public static function options(array $values, string $prefix): array {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = get_string($prefix . $value, 'local_oksigeniaeguahe');
        }
        return $options;
    }
}
