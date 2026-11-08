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
    }
    $ADMIN->add('localplugins', $settings);
}
