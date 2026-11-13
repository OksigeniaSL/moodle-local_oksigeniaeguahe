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

/**
 * Caches: the anti-spam challenges already used, so that a solved one is not sent twice; and the sign-ups from each
 * address in the last hour.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'usedchallenges' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        // A challenge expires after 20 minutes (antispam::LIFETIME): after that it is refused anyway.
        'ttl' => 1500,
    ],
    // Who is writing to a family now: a beat of the composer every 20 seconds, gone after 45.
    'typing' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 45,
    ],
    // Sign-ups sent from each address of the internet to each act in the last hour, only as a hash: a flood of them
    // never fills an act.
    'signupsfrom' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 3600,
    ],
    // Whether the domain of an email receives mail (emailcheck::reachable()), asked to the DNS once a day per domain.
    'maildomains' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 86400,
    ],
    // The first-day labels as PNG, by name, colour and scale: the same always give the same bytes.
    'stickers' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 20,
    ],
];
