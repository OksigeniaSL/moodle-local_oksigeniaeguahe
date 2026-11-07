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
 * Upgrades of local_oksigeniaeguahe.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_oksigeniaeguahe_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026110710) {
        // Events by turns, each with its hours and its capacity.
        $table = new xmldb_table('local_oksigeniaeguahe_slot');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('capacity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('eventid', XMLDB_KEY_FOREIGN, ['eventid'], 'local_oksigeniaeguahe_event', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // The turn a household chose.
        $table = new xmldb_table('local_oksigeniaeguahe_signup');
        $field = new xmldb_field('slotid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'householdid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Families already of the centre, out of the numbers of the campaign.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('incentre', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'firstchannel');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110710, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110720) {
        // What already happened: past campaigns, students and totals, imported pseudonymised; and what each channel cost.
        $table = new xmldb_table('local_oksigeniaeguahe_import');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('label', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('rowcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_pastcontact');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('household', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecontact', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('channel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'other');
        $table->add_field('sourcedetail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'inquiry');
        $table->add_field('graderequested', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('children', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('municipality', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('declaredsource', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('utmsource', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('utmmedium', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('utmcampaign', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('enrolled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enrolledgrade', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('timeenrolled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_oksigeniaeguahe_import', ['id']);
        $table->add_index('centrehousehold', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'household']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_student');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('student', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('household', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('schoolyear', XMLDB_TYPE_CHAR, '9', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('groupname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('timein', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeout', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('outreason', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('municipality', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('locality', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_oksigeniaeguahe_import', ['id']);
        $table->add_index('centreyear', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'schoolyear']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_enrolcount');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('schoolyear', XMLDB_TYPE_CHAR, '9', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('students', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('referencedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('importid', XMLDB_KEY_FOREIGN, ['importid'], 'local_oksigeniaeguahe_import', ['id']);
        $table->add_index('centreyear', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'schoolyear']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_spend');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('label', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('channel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcedetail', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('amount', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110720, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110721) {
        // The name used at home, for the label of what the family gets.
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $field = new xmldb_field('tagname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'firstname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110721, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110730) {
        // The forecast: the age at which each course starts, and the open data of the area.
        $table = new xmldb_table('local_oksigeniaeguahe_gradeage');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('age', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('forecast', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('centregrade', XMLDB_INDEX_UNIQUE, ['centreid', 'grade']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_population');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('municipality', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('year', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('age', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '-1');
        $table->add_field('people', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefetched', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('centrekind', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'kind', 'year']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110730, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110800) {
        // Files imported as they come: the keys that join the same family or student, and what each row says.
        $fields = [
            'local_oksigeniaeguahe_import' => [new xmldb_field(
                'sheet',
                XMLDB_TYPE_CHAR,
                '100',
                null,
                null,
                null,
                null,
                'filename'
            )],
            'local_oksigeniaeguahe_pastcontact' => [
                new xmldb_field('student', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'timeenrolled'),
                new xmldb_field('incentre', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'student'),
                new xmldb_field('birthyear', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'incentre'),
            ],
            'local_oksigeniaeguahe_student' => [
                new xmldb_field('movement', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'roll', 'locality'),
                new xmldb_field('otherschool', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'movement'),
                new xmldb_field('birthyear', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'otherschool'),
            ],
        ];
        foreach ($fields as $tablename => $list) {
            $table = new xmldb_table($tablename);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_importkey');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('keyhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('token', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('centrekey', XMLDB_INDEX_UNIQUE, ['centreid', 'kind', 'keyhash']);
        $table->add_index('centretoken', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'token']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110800, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110900) {
        // The page of the family: how to reach the centre, who receives the families, and families moving to the area.
        $fields = [
            'local_oksigeniaeguahe_centre' => [
                new xmldb_field('address', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'aiprovider'),
                new xmldb_field('phone', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'address'),
                new xmldb_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'phone'),
                new xmldb_field('welcome', XMLDB_TYPE_TEXT, null, null, null, null, null, 'email'),
                new xmldb_field('logoplate', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'welcome'),
            ],
            'local_oksigeniaeguahe_member' => [
                new xmldb_field('public', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'userid'),
                new xmldb_field('rolename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'public'),
                new xmldb_field('says', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rolename'),
                new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'says'),
            ],
            'local_oksigeniaeguahe_event' => [new xmldb_field('hosts', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'open')],
            'local_oksigeniaeguahe_household' => [new xmldb_field(
                'moving',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'municipalityname'
            )],
        ];
        foreach ($fields as $tablename => $list) {
            $table = new xmldb_table($tablename);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026110900, 'local', 'oksigeniaeguahe');
    }

    return true;
}
