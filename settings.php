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
 * Where the centres are set up, in the administration of the site, and the settings of the site.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_oksigeniaeguahe_centres',
        get_string('centres', 'local_oksigeniaeguahe'),
        new moodle_url('/local/oksigeniaeguahe/centres.php'),
        'local/oksigeniaeguahe:manage'
    ));
    $settings = new admin_settingpage('local_oksigeniaeguahe', get_string('pluginname', 'local_oksigeniaeguahe'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_oksigeniaeguahe/credit',
            get_string('credit', 'local_oksigeniaeguahe'),
            get_string('credit_desc', 'local_oksigeniaeguahe'),
            1
        ));
        // The shortener of the links, set once for every plugin of Oksigenia (shortener::of_site()): empty here, the one of
        // another plugin that has it.
        $settings->add(new admin_setting_heading(
            'local_oksigeniaeguahe/shortenerhdr',
            get_string('shortenerhdr', 'local_oksigeniaeguahe'),
            get_string('shortenerhdr_desc', 'local_oksigeniaeguahe')
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniaeguahe/shortenerapi',
            get_string('shortenerapi', 'local_oksigeniaeguahe'),
            get_string('shortenerapi_desc', 'local_oksigeniaeguahe'),
            '',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configpasswordunmask(
            'local_oksigeniaeguahe/shortenerkey',
            get_string('shortenerkey', 'local_oksigeniaeguahe'),
            get_string('shortenerkey_desc', 'local_oksigeniaeguahe'),
            ''
        ));
        // The voice that says each child's name in «Tu primer día», from the voice service of Oksigenia (namevoice_service):
        // empty, the story is read without the name.
        $settings->add(new admin_setting_heading(
            'local_oksigeniaeguahe/voiceshdr',
            get_string('voiceshdr', 'local_oksigeniaeguahe'),
            get_string('voiceshdr_desc', 'local_oksigeniaeguahe')
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniaeguahe/voicesapi',
            get_string('voicesapi', 'local_oksigeniaeguahe'),
            get_string('voicesapi_desc', 'local_oksigeniaeguahe'),
            '',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configpasswordunmask(
            'local_oksigeniaeguahe/voiceskey',
            get_string('voiceskey', 'local_oksigeniaeguahe'),
            get_string('voiceskey_desc', 'local_oksigeniaeguahe'),
            ''
        ));
        // Where the towns are, for the maps of the centres: a worldwide source, the site chooses.
        $sources = [];
        foreach (\local_oksigeniaeguahe\local\places::sources() as $key => $source) {
            $sources[$key] = get_string('places_source_' . $key, 'local_oksigeniaeguahe');
        }
        $settings->add(new admin_setting_configselect(
            'local_oksigeniaeguahe/placessource',
            get_string('places_source', 'local_oksigeniaeguahe'),
            get_string('places_source_desc', 'local_oksigeniaeguahe'),
            'nominatim',
            $sources
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniaeguahe/geonamesuser',
            get_string('places_geonamesuser', 'local_oksigeniaeguahe'),
            get_string('places_geonamesuser_desc', 'local_oksigeniaeguahe'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniaeguahe/nominatimurl',
            get_string('places_nominatimurl', 'local_oksigeniaeguahe'),
            get_string('places_nominatimurl_desc', 'local_oksigeniaeguahe'),
            '',
            PARAM_URL
        ));
    }
    $ADMIN->add('localplugins', $settings);
}
