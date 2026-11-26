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

    if ($oldversion < 2026110910) {
        // What is sent to the families: the reminder of the day before, and the short link of each sign-up.
        $table = new xmldb_table('local_oksigeniaeguahe_signup');
        $field = new xmldb_field('reminded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'people');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // The sign-ups already past or under a day and a half away are not reminded: they came before reminders.
        $DB->execute(
            'UPDATE {local_oksigeniaeguahe_signup} SET reminded = :now
                       WHERE eventid IN (SELECT id FROM {local_oksigeniaeguahe_event} WHERE timestart < :soon)',
            ['now' => time(), 'soon' => time() + 36 * HOURSECS]
        );
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('shorturl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'hosts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110910, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110920) {
        // Visits of their own: the event of asking, the visit of a family, its request, and several links per family.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $fields = [
            new xmldb_field('tailored', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'shorturl'),
            new xmldb_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'tailored'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_link');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('linkhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'signup');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('linkhash', XMLDB_INDEX_UNIQUE, ['linkhash']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            // The link each family had keeps working.
            $rows = $DB->get_recordset_select(
                'local_oksigeniaeguahe_household',
                'linkhash IS NOT NULL',
                null,
                '',
                'id, centreid, linkhash, timemodified'
            );
            foreach ($rows as $row) {
                $DB->insert_record('local_oksigeniaeguahe_link', (object) ['centreid' => $row->centreid,
                    'householdid' => $row->id, 'linkhash' => $row->linkhash, 'reason' => 'signup',
                    'timecreated' => (int) $row->timemodified]);
            }
            $rows->close();
        }
        $table = new xmldb_table('local_oksigeniaeguahe_request');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('days', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('periods', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('people', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2');
        $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('visitid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centrestatus', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110920, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110930) {
        // The entries of a centre, what came in through them, and where each contact came from.
        $table = new xmldb_table('local_oksigeniaeguahe_contact');
        $fields = [
            new xmldb_field('entryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'body'),
            new xmldb_field('detail', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'entryid'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('token', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('secret', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('lead', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('blocks', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('origins', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('roles', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('questions', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('acknowledge', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('received', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastreceived', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('token', XMLDB_INDEX_UNIQUE, ['token']);
        $table->add_index('centreid', XMLDB_INDEX_NOTUNIQUE, ['centreid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_arrival');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('externalid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('entryid', XMLDB_KEY_FOREIGN, ['entryid'], 'local_oksigeniaeguahe_entry', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('entryexternal', XMLDB_INDEX_UNIQUE, ['entryid', 'externalid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_field');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('texts', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('showif', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('showvalue', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('entryid', XMLDB_KEY_FOREIGN, ['entryid'], 'local_oksigeniaeguahe_entry', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_answer');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('entryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fieldid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('value', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('fieldid', XMLDB_INDEX_NOTUNIQUE, ['fieldid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('formid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'householdid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110930, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110940) {
        // Telegram and WhatsApp with Marandu: what each centre does with them.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('messaging', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'notices', 'logoplate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110940, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110980) {
        // The QR the shortener draws for the short link of an event.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('shortqr', XMLDB_TYPE_TEXT, null, null, null, null, null, 'shorturl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110980, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110992) {
        // The skin of the panel of the team each centre chooses.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('skin', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'messaging');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110992, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110994) {
        // Who attends each family, who is away, what was promised and the answers of always.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'moving');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_member');
        $field = new xmldb_field('awayuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sortorder');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_promise');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('body', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('dueday', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timedone', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('doneby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centredone', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'timedone']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_reply');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('words', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('uses', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110994, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110996) {
        // How families get in on the day, which they read on their page and in the reminder.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('arrive', XMLDB_TYPE_TEXT, null, null, null, null, null, 'skin');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110996, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110997) {
        // An example of what came to each question of a bridge, for the team to say what it is.
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $field = new xmldb_field('examples', XMLDB_TYPE_TEXT, null, null, null, null, null, 'questions');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110997, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110998) {
        // When each campaign started, as the centre writes it: the one under way, and each imported one.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('campaignstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'arrive');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_import');
        $field = new xmldb_field('campaignstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110998, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026110999) {
        // The sheets of labels of each centre: its favourites and how much its printer moves each.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('labelsheets', XMLDB_TYPE_TEXT, null, null, null, null, null, 'campaignstart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110999, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026111100) {
        // After the visit: what the families were left to ask and the survey How it went, the thanks, and how soon
        // the team usually answers.
        $table = new xmldb_table('local_oksigeniaeguahe_survey');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fieldid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('value', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_key('eventid', XMLDB_KEY_FOREIGN, ['eventid'], 'local_oksigeniaeguahe_event', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('answer', XMLDB_INDEX_UNIQUE, ['eventid', 'householdid', 'fieldid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $fields = [
            'local_oksigeniaeguahe_event' => [
                new xmldb_field('survey', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'formid'),
                new xmldb_field('outcome', XMLDB_TYPE_TEXT, null, null, null, null, null, 'survey'),
            ],
            'local_oksigeniaeguahe_signup' => [
                new xmldb_field('thanked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attended'),
            ],
            'local_oksigeniaeguahe_centre' => [
                new xmldb_field('replydays', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'labelsheets'),
            ],
        ];
        foreach ($fields as $name => $list) {
            $table = new xmldb_table($name);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        // The families that came before: no thanks now for a visit of long ago.
        $DB->execute('UPDATE {local_oksigeniaeguahe_signup} SET thanked = ? WHERE attended > 0', [time()]);
        upgrade_plugin_savepoint(true, 2026111100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026111101) {
        // The library of Ayvurenda whose stories to read at home the families get.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('library', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'replydays');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111101, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026111600) {
        // After «Enrolled»: who carries the families from then on, whether they offered to tell another family how it
        // went, and who started on the first day.
        $fields = [
            'local_oksigeniaeguahe_centre' => [
                new xmldb_field('handover', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'library'),
                new xmldb_field('handoverplace', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'handover'),
                new xmldb_field('handoverhow', XMLDB_TYPE_TEXT, null, null, null, null, null, 'handoverplace'),
            ],
            'local_oksigeniaeguahe_offer' => [
                new xmldb_field('handover', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'active'),
            ],
            'local_oksigeniaeguahe_household' => [
                new xmldb_field('volunteer', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastcontact'),
            ],
            'local_oksigeniaeguahe_child' => [
                new xmldb_field('started', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'stagetime'),
            ],
        ];
        foreach ($fields as $name => $list) {
            $table = new xmldb_table($name);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026111600, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026111800) {
        // The brothers and sisters at home who would come later, and the age children enter at.
        $table = new xmldb_table('local_oksigeniaeguahe_sibling');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('firstname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('birthyear', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'signup');
        $table->add_field('notify', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('proposed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('confirmed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centreyear', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'birthyear']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('siblingage', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'handoverhow');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111800, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026111900) {
        // Which family told them about the centre and whether it was thanked; the invitations to open acts.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        foreach (
            [
                new xmldb_field('heardwho', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'volunteer'),
                new xmldb_field('thanked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'heardwho'),
            ] as $field
        ) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_invite');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('eventid', XMLDB_KEY_FOREIGN, ['eventid'], 'local_oksigeniaeguahe_event', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111900, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026112100) {
        // When an enrolled child left, why and a line; and whether those who left are still invited to open acts.
        $fields = [
            'local_oksigeniaeguahe_child' => [
                new xmldb_field('lefttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'started'),
                new xmldb_field('leftreason', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'lefttime'),
                new xmldb_field('leftnote', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'leftreason'),
            ],
            'local_oksigeniaeguahe_centre' => [
                new xmldb_field('invitegone', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'siblingage'),
            ],
        ];
        foreach ($fields as $name => $list) {
            $table = new xmldb_table($name);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026112100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026112200) {
        // What families do with their page and their mails, what the adults like doing, and the network they named.
        $fields = [
            'local_oksigeniaeguahe_centre' => [
                new xmldb_field('networks', XMLDB_TYPE_TEXT, null, null, null, null, null, 'invitegone'),
            ],
            'local_oksigeniaeguahe_household' => [
                new xmldb_field('heardnet', XMLDB_TYPE_CHAR, '60', null, null, null, null, 'thanked'),
            ],
        ];
        foreach ($fields as $name => $list) {
            $table = new xmldb_table($name);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_seen');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('what', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('via', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centrekind', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'kind', 'timecreated']);
        $table->add_index('householdtime', XMLDB_INDEX_NOTUNIQUE, ['householdid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_hobby');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('adultid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('hobby', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('other', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_key('adultid', XMLDB_KEY_FOREIGN, ['adultid'], 'local_oksigeniaeguahe_adult', ['id']);
        $table->add_index('centrehobby', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'hobby']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026112200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026112400) {
        // For the story «Your first day»: the first day of the school year, the name of the centre in a sentence, and
        // the courses that give it.
        $fields = [
            'local_oksigeniaeguahe_centre' => [
                new xmldb_field('firstday', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'networks'),
                new xmldb_field('namephrase', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'firstday'),
            ],
            'local_oksigeniaeguahe_offer' => [
                new xmldb_field('story', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'handover'),
            ],
        ];
        foreach ($fields as $name => $list) {
            $table = new xmldb_table($name);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026112400, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026112700) {
        // How the story of the first day calls whoever receives each course.
        $table = new xmldb_table('local_oksigeniaeguahe_offer');
        $field = new xmldb_field('teacher', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'maestra', 'story');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026112700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026112800) {
        // The reports, hot and cold, kept as they were made.
        $table = new xmldb_table('local_oksigeniaeguahe_report');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodfrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('periodto', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lang', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'es');
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('ai', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('aistatus', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('aiprovider', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('aierror', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('period', XMLDB_INDEX_UNIQUE, ['centreid', 'kind', 'periodfrom']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026112800, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026113000) {
        // The currency and the school system of the centre: nothing in the code says euros nor names its courses.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('currency', XMLDB_TYPE_CHAR, '3', null, null, null, null, 'country');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('system', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'currency');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026113000, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026113100) {
        // Where the centre and its towns are, for the constellation of the cinematic skin.
        $table = new xmldb_table('local_oksigeniaeguahe_place');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('plain', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lat', XMLDB_TYPE_NUMBER, '10, 6', null, null, null, null);
        $table->add_field('lon', XMLDB_TYPE_NUMBER, '10, 6', null, null, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timefetched', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('centreplain', XMLDB_INDEX_UNIQUE, ['centreid', 'plain']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026113100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026113200) {
        // The AI of a centre, on or off; the centres that had a provider, on.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('aion', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'system');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $DB->set_field_select('local_oksigeniaeguahe_centre', 'aion', 1, 'aiprovider > 0');
        }
        upgrade_plugin_savepoint(true, 2026113200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026113300) {
        // What a family shares invites whoever receives it, unless the centre turns it off; and to which form.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('shareinvite', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'aion');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('shareentry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'shareinvite');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026113300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026113400) {
        // The common family record of the centre: the family a person said a household is, and each child in it.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('familylink', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'heardnet');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        foreach (
            [
                new xmldb_field('familychild', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'leftnote'),
                new xmldb_field('familygone', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'familychild'),
            ] as $field
        ) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026113400, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026113500) {
        // The kit of an act for the networks: its links by network and the texts written for them.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('kit', XMLDB_TYPE_TEXT, null, null, null, null, null, 'outcome');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026113500, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026114001) {
        // What the site asked the voice service of Oksigenia for: the reading of the story that says a first name.
        $table = new xmldb_table('local_oksigeniaeguahe_namevoice');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reading', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('namekey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sha256', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recipeversion', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('nextcheck', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('langreadingname', XMLDB_INDEX_UNIQUE, ['lang', 'reading', 'namekey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026114001, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026114200) {
        // The date of birth of each child, when the family gives it: their birthday.
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $field = new xmldb_field('birthdate', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'tagname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026114200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026114300) {
        // Their name in Latin letters, when the family writes it in another script: what the voice says.
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $field = new xmldb_field('latinname', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'tagname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026114300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026114400) {
        // The moments of each open act that go by themselves to the channel of Telegram; null, the announcement only.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('channelauto', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'networks');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026114400, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026114500) {
        // How an ambassador family signs its invitation to other families; empty, a family of the centre.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('invitesign', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'heardnet');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026114500, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026114700) {
        // The first day of each course in its school year, and when a person last said it holds.
        $table = new xmldb_table('local_oksigeniaeguahe_offer');
        $field = new xmldb_field('firstday', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'teacher');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('firstdayok', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'firstday');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026114700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115000) {
        // The AI of the team: a summary of each family, and what each message of a family asks, in a line.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('aisummary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'familylink');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aisummarytime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'aisummary');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_contact');
        $field = new xmldb_field('asks', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026115000, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115200) {
        // The re-enrolment: the school year the families are asked about, from and until when, and what each one said.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $fields = [
            new xmldb_field('renewalyear', XMLDB_TYPE_CHAR, '9', null, null, null, null, 'channelauto'),
            new xmldb_field('renewalfrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'renewalyear'),
            new xmldb_field('renewaluntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'renewalfrom'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_renewal');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('childid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('schoolyear', XMLDB_TYPE_CHAR, '9', null, XMLDB_NOTNULL, null, null);
            $table->add_field('answer', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('reason', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
            $table->add_index('childyear', XMLDB_INDEX_UNIQUE, ['childid', 'schoolyear']);
            $table->add_index('centreyear', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'schoolyear']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026115200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115300) {
        // The families already at the centre, from the common family record: where each course comes from, what each
        // value means, and each family brought.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $fields = [
            new xmldb_field('rostersource', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'renewaluntil'),
            new xmldb_field('rosteryear', XMLDB_TYPE_CHAR, '9', null, null, null, null, 'rostersource'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('roster', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'familylink');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_rostermap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('value', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('grade', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
            $table->add_index('centrevalue', XMLDB_INDEX_UNIQUE, ['centreid', 'value']);
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_roster');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('familyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
            $table->add_index('centrefamily', XMLDB_INDEX_UNIQUE, ['centreid', 'familyid']);
            $table->add_index('householdid', XMLDB_INDEX_NOTUNIQUE, ['householdid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026115300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115500) {
        // The review of 13 November: children of the roll and the school year each child started; acts called off;
        // indexes for big centres; and what Moodle kept of the texts sent to the AI, emptied.
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $fields = [
            new xmldb_field('roster', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'familygone'),
            new xmldb_field('startyear', XMLDB_TYPE_CHAR, '9', null, null, null, null, 'roster'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('familychild', XMLDB_INDEX_NOTUNIQUE, ['familychild']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // The children brought from the roll, and the school year of those enrolled through Eguahe.
        $DB->execute('UPDATE {local_oksigeniaeguahe_child}
                         SET roster = (SELECT h.roster FROM {local_oksigeniaeguahe_household} h WHERE h.id = householdid)
                       WHERE familychild > 0 AND householdid IN (SELECT id FROM {local_oksigeniaeguahe_household}
                                                                  WHERE roster > 0)');
        $DB->execute("UPDATE {local_oksigeniaeguahe_child}
                         SET startyear = (SELECT o.schoolyear FROM {local_oksigeniaeguahe_offer} o WHERE o.id = offerid)
                       WHERE stage = 'enrolled' AND roster = 0 AND offerid > 0");
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('cancelled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'kit');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('centrestart', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'timestart']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_signup');
        $index = new xmldb_index('reminded', XMLDB_INDEX_NOTUNIQUE, ['reminded']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_contact');
        foreach (['centretime' => ['centreid', 'timecreated'], 'centredetail' => ['centreid', 'detail']] as $name => $columns) {
            $index = new xmldb_index($name, XMLDB_INDEX_NOTUNIQUE, $columns);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        \local_oksigeniaeguahe\local\ai::forget_all();
        upgrade_plugin_savepoint(true, 2026115500, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115600) {
        // What «Ha venido» moved, to undo it if it was a mistake.
        $table = new xmldb_table('local_oksigeniaeguahe_signup');
        $field = new xmldb_field('wasstages', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'thanked');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026115600, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115700) {
        // Managing every centre is no longer given to every manager of the site: only to whoever the site gives it to.
        foreach (get_archetype_roles('manager') as $role) {
            unassign_capability('local/oksigeniaeguahe:manage', $role->id, context_system::instance()->id);
        }
        // The course its pupils go on to, by name, when its age does not say it.
        $table = new xmldb_table('local_oksigeniaeguahe_offer');
        $field = new xmldb_field('nextname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'firstdayok');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026115700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026115900) {
        // How a family asks for a place, and when: what its page says after the visit.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $fields = [
            new xmldb_field('applyhow', XMLDB_TYPE_TEXT, null, null, null, null, null, 'namephrase'),
            new xmldb_field('applyurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'applyhow'),
            new xmldb_field('applyfrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'applyurl'),
            new xmldb_field('applyuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'applyfrom'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026115900, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026116000) {
        // When a test last came through each entry («Try the entries»).
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $field = new xmldb_field('testedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastreceived');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026116000, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026116100) {
        // Why a family chose another centre, if it said, and when.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $fields = [
            new xmldb_field('whyother', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'aisummarytime'),
            new xmldb_field('whyothertime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'whyother'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026116100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026116200) {
        // What it all costs, as the centre writes it, and when families see it.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $fields = [
            new xmldb_field('costs', XMLDB_TYPE_TEXT, null, null, null, null, null, 'applyuntil'),
            new xmldb_field('costswhen', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'after', 'costs'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026116200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026116300) {
        // Who puts an enrolled family in the common family record: Eguahe, or the management system of the centre.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('familyfrom', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'eguahe', 'costswhen');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026116300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026116800) {
        // A big act shows on the miniature of the centre where to park.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('parking', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026116800, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026117000) {
        // The guide of each centre, and what it says.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $fields = [
            new xmldb_field('guide', XMLDB_TYPE_CHAR, '20', null, null, null, null),
            new xmldb_field('guidewhere', XMLDB_TYPE_CHAR, '255', null, null, null, null),
            new xmldb_field('guidehello', XMLDB_TYPE_CHAR, '255', null, null, null, null),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026117000, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026117003) {
        // The guide may be null: a required text with an empty default makes XMLDB complain on install.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('guide', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
            $dbman->change_field_default($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026117003, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026117200) {
        // The practice centre of the guided helps.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('practice', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026117200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026117500) {
        // Whoever changes the settings and the team of one centre, for a site shared by several (the hosted Eguahe).
        $table = new xmldb_table('local_oksigeniaeguahe_member');
        $field = new xmldb_field('admin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'awayuntil');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026117500, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026117700) {
        // The student who comes on their own: who signs up at each centre, and the households where the student writes.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('whoapplies', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'family', 'practice');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('self', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'moving');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026117700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026117900) {
        // Where each household arrived from, measured the first time, beside what it says: an AI assistant among them.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $fields = [
            new xmldb_field('arrivalsource', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'whyothertime'),
            new xmldb_field('arrivalmedium', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'arrivalsource'),
            new xmldb_field('arrivalcampaign', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'arrivalmedium'),
            new xmldb_field('arrivalref', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'arrivalcampaign'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026117900, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118000) {
        // How to ask for a place, sent by the team when a family is ready; and whether it is also offered after a visit.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('applyafter', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'applyuntil');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('applysent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'arrivalref');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026118000, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118100) {
        // The short link of each place a form is shared from, with its utm and its QR.
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $field = new xmldb_field('shortlinks', XMLDB_TYPE_TEXT, null, null, null, null, null, 'origins');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026118100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118101) {
        // A word families read beside each stage, such as whether it is publicly funded.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('stagenotes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'costswhen');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026118101, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118200) {
        // Two households a person said are different families, though a child of the same name made them look alike.
        $table = new xmldb_table('local_oksigeniaeguahe_notsame');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householda', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdb', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('pair', XMLDB_INDEX_UNIQUE, ['centreid', 'householda', 'householdb']);
        $table->add_index('householdb', XMLDB_INDEX_NOTUNIQUE, ['householdb']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026118200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118201) {
        // What came from the other household when two were put together, to separate them again.
        $table = new xmldb_table('local_oksigeniaeguahe_mergelog');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026118201, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118301) {
        // A list of a school year that holds only a part of the centre (its nursery, one stage).
        $table = new xmldb_table('local_oksigeniaeguahe_import');
        $field = new xmldb_field('partial', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'campaignstart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026118301, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118400) {
        // Who sees Cifras, the reports and the forecast: the whole team, or only its management.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('metricsfor', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'team');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026118400, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118500) {
        // The hours of the team a centre writes for a campaign or an act, and what an hour is worth.
        $table = new xmldb_table('local_oksigeniaeguahe_hours');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'import');
        $table->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('task', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'other');
        $table->add_field('hours', XMLDB_TYPE_NUMBER, '10, 1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('line', XMLDB_INDEX_UNIQUE, ['centreid', 'scope', 'scopeid', 'task']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('hourvalue', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'metricsfor');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026118500, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026118600) {
        // What the list of the centre said of a family of Eguahe: a child enrolled, a family of the centre, or a question.
        $table = new xmldb_table('local_oksigeniaeguahe_listmatch');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('schoolyear', XMLDB_TYPE_CHAR, '9', null, XMLDB_NOTNULL, null, '-');
        $table->add_field('student', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '-');
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'ask');
        $table->add_field('grade', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('timein', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('childid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('done', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centreyear', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'schoolyear']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026118600, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119000) {
        // What was taken from a sheet the team keeps, so that sending it again adds only what is new.
        $table = new xmldb_table('local_oksigeniaeguahe_teamnote');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mark', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '-');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('householdmark', XMLDB_INDEX_NOTUNIQUE, ['householdid', 'mark']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // The short link of the door of an act, where families sign in when they arrive, and its QR.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('doorurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'shortqr');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('doorqr', XMLDB_TYPE_TEXT, null, null, null, null, null, 'doorurl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026119000, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119100) {
        // The colours of the brand of a centre, for its posters and the pages of its families.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('brandcolours', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'colour');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026119100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119203) {
        // A centre can want the pages and mails of its families always light, whatever their phone says.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('familylight', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'brandcolours');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026119203, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119300) {
        // How each family wants to be written to (email, WhatsApp, Telegram, several, or not at all), kept everywhere.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $fields = [
            new xmldb_field('writeby', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'applysent'),
            new xmldb_field('writebytime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'writeby'),
            new xmldb_field('writebyfrom', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'writebytime'),
            new xmldb_field('writebynone', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'writebyfrom'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026119300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119301) {
        // Several ways at once: the list of what a family marked is longer than one word.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('writeby', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'applysent');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026119301, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119400) {
        // The invitation to an open act by the way each family chose: email, or the phone through Marandu, which waits
        // for its approval when it costs.
        $table = new xmldb_table('local_oksigeniaeguahe_invite');
        $fields = [
            new xmldb_field('channel', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'userid'),
            new xmldb_field('bulkid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'channel'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026119400, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119600) {
        // Who in a family studied at the centre, and the list of former students as keys of their names.
        $table = new xmldb_table('local_oksigeniaeguahe_alumni');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('adultid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('token', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('said', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('source', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('year', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('householdadult', XMLDB_INDEX_NOTUNIQUE, ['householdid', 'adultid']);
        $table->add_index('token', XMLDB_INDEX_NOTUNIQUE, ['token']);
        $table->add_index('importid', XMLDB_INDEX_NOTUNIQUE, ['importid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_alumnus');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('importid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('keyhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('exact', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('year', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('centrekey', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'keyhash']);
        $table->add_index('importid', XMLDB_INDEX_NOTUNIQUE, ['importid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026119600, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119700) {
        // The private links stop working after months unused, and what is delicate asks for a code sent to the family.
        $table = new xmldb_table('local_oksigeniaeguahe_link');
        $field = new xmldb_field('timeused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_code');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('what', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'download');
        $table->add_field('codehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tries', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('householdwhat', XMLDB_INDEX_NOTUNIQUE, ['householdid', 'what']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026119700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119800) {
        // Who wrote on WhatsApp or Telegram, to answer that person even after a no (Marandu 0.51.1).
        $table = new xmldb_table('local_oksigeniaeguahe_contact');
        $field = new xmldb_field('address', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'detail');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // When an email was written on the private page of the family, and whether an adult was added there: a code to
        // download or erase goes only to the addresses that were already theirs (review of 21-11).
        $table = new xmldb_table('local_oksigeniaeguahe_adult');
        foreach (['emailpage' => 'phone', 'frompage' => 'emailpage'] as $name => $after) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Whether an entry is a sheet of the team, said by a person and never deduced from its headings (anyone can fill a
        // public form with a question «Estado…»). The entries that already read columns of the team keep working.
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $field = new xmldb_field('teamsheet', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'acknowledge');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            foreach ($DB->get_records('local_oksigeniaeguahe_entry', null, '', 'id, roles') as $entry) {
                $roles = json_decode((string) $entry->roles, true) ?: [];
                if (array_intersect(['team', 'staff'], array_values($roles))) {
                    $DB->set_field('local_oksigeniaeguahe_entry', 'teamsheet', 1, ['id' => $entry->id]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026119800, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026119900) {
        // The emails and phones of families that erased their data, as keyed hashes: an old row of a sheet sent again,
        // with a phone the team corrected, never brings them back (review of 21-11).
        $table = new xmldb_table('local_oksigeniaeguahe_gone');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('keyhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('centrekey', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'keyhash']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026119900, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026120600) {
        // A second address of the centre, for the families already of it: re-enrolment and what they answer go to the
        // office that looks after them, not to admissions.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('emailsettled', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'email');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026120600, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121200) {
        // Whether what comes in through each entry is still coming: its calls, the refused ones too.
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $fields = [
            new xmldb_field('calledat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'testedat'),
            new xmldb_field('refusedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'calledat'),
            new xmldb_field('unread', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'refusedat'),
            new xmldb_field('opened', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'unread'),
            new xmldb_field('beats', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'opened'),
            new xmldb_field('missing', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'beats'),
            new xmldb_field('silenttold', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'missing'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026121200, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121201) {
        // Since when nothing of what an entry brings is understood, or its script does not find its tabs.
        $table = new xmldb_table('local_oksigeniaeguahe_entry');
        $fields = [
            new xmldb_field('unreadat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'missing'),
            new xmldb_field('missingat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'unreadat'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026121201, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121300) {
        // A day without its hour is kept as a day; a visit of a sheet is followed while the sheet gives it; and a sheet that
        // loses its row of titles says so.
        $int = fn(string $name, string $length, string $after) => new xmldb_field(
            $name,
            XMLDB_TYPE_INTEGER,
            $length,
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            $after
        );
        $tables = [
            'local_oksigeniaeguahe_contact' => [$int('dayonly', '1', 'asks')],
            'local_oksigeniaeguahe_household' => [$int('waitingdayonly', '1', 'waitingsince')],
            'local_oksigeniaeguahe_entry' => [
                $int('lastdayonly', '1', 'lastreceived'),
                $int('headlessat', '10', 'missingat'),
                $int('headlessseen', '10', 'headlessat'),
                $int('wholeat', '10', 'headlessseen'),
            ],
            'local_oksigeniaeguahe_teamnote' => [
                $int('entryid', '10', 'mark'),
                $int('contactid', '10', 'entryid'),
                $int('visit', '10', 'contactid'),
                $int('dayonly', '1', 'visit'),
                $int('seen', '10', 'dayonly'),
                $int('replacedby', '10', 'seen'),
                new xmldb_field('body', XMLDB_TYPE_TEXT, null, null, null, null, null, 'replacedby'),
            ],
        ];
        foreach ($tables as $name => $fields) {
            $table = new xmldb_table($name);
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        // What is already there: the moments nobody wrote, and the values of a row kept as if they were titles.
        \local_oksigeniaeguahe\local\sheetrows::mark_days();
        \local_oksigeniaeguahe\local\sheetrows::clean_titles();
        upgrade_plugin_savepoint(true, 2026121300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121400) {
        // The days a centre closes, from its own calendar: what it promises is counted in its working days.
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $fields = [
            new xmldb_field('closedics', XMLDB_TYPE_CHAR, '500', null, null, null, null, 'replydays'),
            new xmldb_field('closeddays', XMLDB_TYPE_TEXT, null, null, null, null, null, 'closedics'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026121400, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121500) {
        // What the team adds, in its own words, to the letter that invites to an act.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('invitewords', XMLDB_TYPE_TEXT, null, null, null, null, null, 'outcome');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026121500, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121700) {
        // How an act is told: the word for what it is, what it is about, who gives it, for whom, its picture, free entry.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $fields = [
            new xmldb_field('word', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'kind'),
            new xmldb_field('about', XMLDB_TYPE_TEXT, null, null, null, null, null, 'name'),
            new xmldb_field('speakers', XMLDB_TYPE_TEXT, null, null, null, null, null, 'about'),
            new xmldb_field('audience', XMLDB_TYPE_TEXT, null, null, null, null, null, 'speakers'),
            new xmldb_field('posteralt', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'audience'),
            new xmldb_field('free', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'open'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026121700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026121901) {
        // Whoever only signed up to an open act (a talk, a party) is a guest of it, not a family of the admissions.
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('guest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lastcontact');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // An open act is published without its sign-up link, unless the team asks for it.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('linkout', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'free');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $DB->set_field('local_oksigeniaeguahe_event', 'linkout', 0, ['kind' => 'other']);
        }
        // What was sent to many at once (an invitation, who goes on next year) answers nobody: marked, in whatever
        // language it was written.
        $table = new xmldb_table('local_oksigeniaeguahe_contact');
        $field = new xmldb_field('aim', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'dayonly');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $DB->execute(
                "UPDATE {local_oksigeniaeguahe_contact} SET aim = 'many'
                  WHERE direction = 'out' AND " . $DB->sql_like('detail', '?'),
                ['renewal:%']
            );
            foreach (array_keys(get_string_manager()->get_list_of_translations(true)) as $lang) {
                foreach (['invite_note', 'invite_note_phone'] as $key) {
                    $text = get_string_manager()->get_string($key, 'local_oksigeniaeguahe', '|', $lang);
                    $start = core_text::substr($text, 0, (int) core_text::strpos($text, '|'));
                    if ($start !== '') {
                        $DB->execute(
                            "UPDATE {local_oksigeniaeguahe_contact} SET aim = 'many'
                              WHERE direction = 'out' AND aim IS NULL AND " . $DB->sql_like('body', '?'),
                            [$DB->sql_like_escape($start) . '%']
                        );
                    }
                }
            }
        }
        \local_oksigeniaeguahe\local\households::find_guests();
        upgrade_plugin_savepoint(true, 2026121901, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122100) {
        // What an act is about, in a line; and what each family invited to it said.
        $table = new xmldb_table('local_oksigeniaeguahe_event');
        $field = new xmldb_field('topic', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'free');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_invite');
        $fields = [
            new xmldb_field('opened', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'bulkid'),
            new xmldb_field('reply', XMLDB_TYPE_CHAR, '3', null, null, null, null, 'opened'),
            new xmldb_field('replied', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reply'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026122100, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122300) {
        // The appointments: what a person says of each, and how to take it back.
        $fields = [
            'signup' => [new xmldb_field('missed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attended')],
            'teamnote' => [
                new xmldb_field('state', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'body'),
                new xmldb_field('statetime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'state'),
            ],
        ];
        foreach ($fields as $name => $list) {
            $table = new xmldb_table('local_oksigeniaeguahe_' . $name);
            foreach ($list as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_apptmark');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('batch', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('item', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('was', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('state', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('stages', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centrebatch', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'batch']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026122300, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122501) {
        // What the centre sent by itself and how it went; and the families said to be already answered, several at once.
        $table = new xmldb_table('local_oksigeniaeguahe_notice');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('what', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('channel', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('state', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centretime', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'timecreated']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_settle');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('batch', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('waitingsince', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('waitingdayonly', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centrebatch', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'batch']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026122501, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122502) {
        $table = new xmldb_table('local_oksigeniaeguahe_listmatch');
        $field = new xmldb_field('marked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'done');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_student');
        foreach (['centrestudent' => 'student', 'centrehousehold' => 'household'] as $name => $column) {
            $index = new xmldb_index($name, XMLDB_INDEX_NOTUNIQUE, ['centreid', $column]);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        // A list put in every night gave a new key to whoever was only in it, and the same child was said again the next
        // day: of each family, the ones whose key is in no list go when another of the same year and kind stays.
        $sql = "SELECT m.id, m.householdid, m.schoolyear, m.kind, s.student AS living
                  FROM {local_oksigeniaeguahe_listmatch} m
             LEFT JOIN (SELECT DISTINCT centreid, student FROM {local_oksigeniaeguahe_student}) s
                    ON s.centreid = m.centreid AND s.student = m.student
                 WHERE m.kind = :kind";
        $rows = $DB->get_records_sql($sql, ['kind' => 'incentre']);
        $stays = [];
        foreach ($rows as $row) {
            if ($row->living !== null) {
                $stays[$row->householdid . ':' . $row->schoolyear] = true;
            }
        }
        foreach ($rows as $row) {
            if ($row->living === null && isset($stays[$row->householdid . ':' . $row->schoolyear])) {
                $DB->delete_records('local_oksigeniaeguahe_listmatch', ['id' => $row->id]);
            }
        }
        upgrade_plugin_savepoint(true, 2026122502, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122504) {
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $field = new xmldb_field('noinvites', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'writebynone');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026122504, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122700) {
        $table = new xmldb_table('local_oksigeniaeguahe_tie');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('childid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('kind', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
        $table->add_field('made', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('householdid', XMLDB_KEY_FOREIGN, ['householdid'], 'local_oksigeniaeguahe_household', ['id']);
        $table->add_index('centrekind', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'kind']);
        $table->add_index('childid', XMLDB_INDEX_NOTUNIQUE, ['childid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026122700, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122800) {
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('calsync', XMLDB_TYPE_TEXT, null, null, null, null, null, 'closeddays');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_calsync');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ask', XMLDB_TYPE_CHAR, '8', null, null, null, null);
        $table->add_field('askstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('askend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('asktime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('restore', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('eventid', XMLDB_KEY_FOREIGN, ['eventid'], 'local_oksigeniaeguahe_event', ['id']);
        $table->add_index('centreid', XMLDB_INDEX_NOTUNIQUE, ['centreid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_busy');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('calendar', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('allday', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('centrestart', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'timestart']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026122800, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122900) {
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        $after = 'noinvites';
        foreach (
            ['outreason' => [XMLDB_TYPE_CHAR, '16'], 'outtime' => null, 'outuserid' => null, 'outnote' => [XMLDB_TYPE_CHAR, '255'],
                'outstep' => [XMLDB_TYPE_INTEGER, '2'], 'outstops' => [XMLDB_TYPE_CHAR, '8'], 'outwas' => [XMLDB_TYPE_TEXT, null],
                'outasked' => null] as $name => $type
        ) {
            [$kind, $length] = $type ?? [XMLDB_TYPE_INTEGER, '10'];
            $number = $kind === XMLDB_TYPE_INTEGER;
            $field = new xmldb_field($name, $kind, $length, null, $number ? XMLDB_NOTNULL : null, null, $number ? '0' : null, $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $after = $name;
        }
        upgrade_plugin_savepoint(true, 2026122900, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122904) {
        // What each row of a list keeps to work out the families again, and what the list did by itself, to take it back.
        foreach (['student' => 'birthyear', 'pastcontact' => 'birthyear'] as $name => $after) {
            $table = new xmldb_table('local_oksigeniaeguahe_' . $name);
            $field = new xmldb_field('marks', XMLDB_TYPE_TEXT, null, null, null, null, null, $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_import');
        $field = new xmldb_field('info', XMLDB_TYPE_TEXT, null, null, null, null, null, 'partial');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_importkey');
        $field = new xmldb_field('live', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'token');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_listmatch');
        $field = new xmldb_field('was', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'marked');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('said', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'was');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $field = new xmldb_field('studentkey', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'familychild');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // What the lists had already said, put right; the next one that comes in works out the rest.
        // A student whose list said the day it came to the centre, years before that school year, was there from its start.
        $years = $DB->get_fieldset_sql('SELECT DISTINCT schoolyear FROM {local_oksigeniaeguahe_student}');
        foreach ($years as $year) {
            $start = strlen($year) > 4 ? make_timestamp((int) substr($year, 0, 4), 9, 1, 12) :
                make_timestamp((int) substr($year, 0, 4), 3, 1, 12);
            $DB->execute(
                "UPDATE {local_oksigeniaeguahe_student} SET timein = ? WHERE schoolyear = ? AND movement = 'roll' AND timein > 0 AND timein < ?",
                [$start - HOURSECS, $year, $start - 60 * DAYSECS]
            );
        }
        // Before it was kept, which child made a family one of the centre: the one its history says the list found.
        $notes = ['Familia del centro según la lista:', 'A family of the centre according to the list:',
            'Família do centro segundo a lista:', 'Família da escola segundo a lista:'];
        $like = [];
        $params = [];
        foreach ($notes as $i => $note) {
            $like[] = $DB->sql_like('body', ':n' . $i);
            $params['n' . $i] = $DB->sql_like_escape($note) . '%';
        }
        $rows = $DB->get_records('local_oksigeniaeguahe_listmatch', ['kind' => 'incentre', 'marked' => 0], 'id ASC');
        $seen = [];
        foreach ($rows as $row) {
            $made = !isset($seen[$row->householdid]) && !$DB->record_exists(
                'local_oksigeniaeguahe_listmatch',
                ['householdid' => $row->householdid, 'kind' => 'incentre', 'marked' => 1]
            ) && $DB->record_exists_select(
                'local_oksigeniaeguahe_contact',
                "householdid = :h AND direction = 'note' AND (" . implode(' OR ', $like) . ')',
                ['h' => $row->householdid] + $params
            );
            $seen[$row->householdid] = true;
            $DB->set_field('local_oksigeniaeguahe_listmatch', 'marked', $made ? 1 : 2, ['id' => $row->id]);
        }
        // A child the list enrolled was enrolled the day it joined, not the night it was said; and one whose family was put
        // together with another is the child that stayed.
        $rows = $DB->get_records_select('local_oksigeniaeguahe_listmatch', "kind = 'enrolled' AND childid > 0", [], 'id ASC');
        foreach ($rows as $row) {
            $child = $DB->get_record('local_oksigeniaeguahe_child', ['id' => $row->childid]);
            if (!$child) {
                $others = $DB->get_fieldset_select(
                    'local_oksigeniaeguahe_child',
                    'id',
                    "householdid = :h AND stage = 'enrolled' AND id NOT IN (SELECT childid FROM {local_oksigeniaeguahe_listmatch}
                      WHERE householdid = :h2 AND kind = 'enrolled')",
                    ['h' => $row->householdid, 'h2' => $row->householdid]
                );
                $DB->set_field(
                    'local_oksigeniaeguahe_listmatch',
                    'childid',
                    count($others) === 1 ? (int) reset($others) : 0,
                    ['id' => $row->id]
                );
            } else if (
                $child->stage === 'enrolled' && (int) $row->timein > 0 && (int) $row->timein < (int) $child->stagetime &&
                    abs((int) $child->stagetime - (int) $row->done) < HOURSECS
            ) {
                $DB->set_field('local_oksigeniaeguahe_child', 'stagetime', (int) $row->timein, ['id' => $child->id]);
                $DB->set_field('local_oksigeniaeguahe_listmatch', 'was', 'application:0', ['id' => $row->id]);
            }
        }
        // What was said before is not told as news of the last night.
        $last = $DB->get_records_sql('SELECT centreid, MAX(id) AS id FROM {local_oksigeniaeguahe_listmatch} GROUP BY centreid');
        foreach ($last as $row) {
            $known = (string) get_config('local_oksigeniaeguahe', 'momentsknown_' . $row->centreid);
            set_config(
                'momentsknown_' . $row->centreid,
                ($known === '' ? '' : $known . ',') . 'list-' . $row->id,
                'local_oksigeniaeguahe'
            );
        }
        upgrade_plugin_savepoint(true, 2026122904, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122940) {
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $field = new xmldb_field('closedstage', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'closedreason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_mergelog');
        $field = new xmldb_field('goneid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'householdid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('goneid', XMLDB_INDEX_NOTUNIQUE, ['goneid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_calsync');
        $field = new xmldb_field('creating', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'restore');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // What was kept of families put together, of files that are no longer there.
        $DB->delete_records_select(
            'local_oksigeniaeguahe_mergelog',
            'householdid NOT IN (SELECT id FROM {local_oksigeniaeguahe_household})'
        );
        // A visit of its own whose family said on its page that it does not come was left with nobody in it: called off.
        $DB->execute(
            'UPDATE {local_oksigeniaeguahe_event}
                SET cancelled = ?, open = 0
              WHERE householdid > 0 AND cancelled = 0 AND timestart > ?
                    AND id NOT IN (SELECT eventid FROM {local_oksigeniaeguahe_signup})',
            [time(), time()]
        );
        upgrade_plugin_savepoint(true, 2026122940, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122951) {
        // The key of each student of the roll, and when each campaign started.
        $table = new xmldb_table('local_oksigeniaeguahe_child');
        $field = new xmldb_field('studentkey', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'familychild');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_oksigeniaeguahe_centre');
        $field = new xmldb_field('campaigns', XMLDB_TYPE_TEXT, null, null, null, null, null, 'campaignstart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // The act each imported file is of, when a person says it.
        $table = new xmldb_table('local_oksigeniaeguahe_import');
        $field = new xmldb_field('act', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'campaignstart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('acttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'act');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // The made-up families were told by their date: the first of January of 2000.
        // Never one a real family was put together with.
        $DB->set_field_select(
            'local_oksigeniaeguahe_household',
            'guest',
            2,
            "timecreated = ? AND id NOT IN (SELECT householdid FROM {local_oksigeniaeguahe_mergelog})
                AND EXISTS (SELECT 1 FROM {local_oksigeniaeguahe_adult} a
                             WHERE a.householdid = {local_oksigeniaeguahe_household}.id AND a.lastname = ?)",
            [946684800, '(muestra)']
        );
        // One a real family was put together with is from when that family first wrote.
        $rows = $DB->get_records('local_oksigeniaeguahe_household', ['timecreated' => 946684800, 'guest' => 0], '', 'id');
        foreach ($rows as $row) {
            $first = (int) $DB->get_field_sql(
                'SELECT MIN(timecreated) FROM {local_oksigeniaeguahe_contact} WHERE householdid = ? AND timecreated > ?',
                [$row->id, 946684800]
            );
            $DB->set_field('local_oksigeniaeguahe_household', 'timecreated', $first ?: time(), ['id' => $row->id]);
        }
        // What a family had got to when a person said it went is already kept: it goes to its children.
        $rows = $DB->get_recordset_select(
            'local_oksigeniaeguahe_household',
            'outwas IS NOT NULL',
            [],
            '',
            'id, outwas'
        );
        foreach ($rows as $row) {
            $was = json_decode((string) $row->outwas, true);
            foreach (is_array($was) ? (array) ($was['children'] ?? []) : [] as $childid => $before) {
                $DB->set_field_select(
                    'local_oksigeniaeguahe_child',
                    'closedstage',
                    \core_text::substr((string) ($before[0] ?? ''), 0, 20),
                    "id = ? AND householdid = ? AND stage = 'closed'",
                    [(int) $childid, $row->id]
                );
            }
        }
        $rows->close();
        upgrade_plugin_savepoint(true, 2026122951, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122960) {
        $table = new xmldb_table('local_oksigeniaeguahe_household');
        foreach (
            [new xmldb_field('cameback', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'outasked'),
                new xmldb_field('swapphone', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'cameback')] as $field
        ) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniaeguahe_invite');
        $field = new xmldb_field('said', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'bulkid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // A call of the sheet of the team that nobody answered is no answer to the family.
        \local_oksigeniaeguahe\local\teamnotes::mark_tried();
        upgrade_plugin_savepoint(true, 2026122960, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122966) {
        // Two lines of one household that are the same child: put together by a person, and what undoes it.
        $table = new xmldb_table('local_oksigeniaeguahe_samechild');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('childa', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('childb', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('same', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('householdid', XMLDB_INDEX_NOTUNIQUE, ['householdid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026122966, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122967) {
        // Two students of different courses under one key in one list: each with its own.
        foreach ($DB->get_fieldset_select('local_oksigeniaeguahe_centre', 'id', '1 = 1') as $centreid) {
            \local_oksigeniaeguahe\local\liststudents::one_a_year((int) $centreid);
        }
        // The same child written twice in one file before the names were matched as they came in: put together now, each with
        // its line in the history and a month to undo it.
        \local_oksigeniaeguahe\local\lookalike::join_all_twice();
        upgrade_plugin_savepoint(true, 2026122967, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026122970) {
        // The mail that comes back, by address, with why and what a person heard when calling.
        $table = new xmldb_table('local_oksigeniaeguahe_bounce');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('centreid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('householdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('adultid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('emailkey', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'gone');
        $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('diag', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('what', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('sent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timebounced', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('times', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('state', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'open');
        $table->add_field('tries', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('saidby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timesaid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('centreid', XMLDB_KEY_FOREIGN, ['centreid'], 'local_oksigeniaeguahe_centre', ['id']);
        $table->add_index('householdid', XMLDB_INDEX_NOTUNIQUE, ['householdid']);
        $table->add_index('adultid', XMLDB_INDEX_NOTUNIQUE, ['adultid', 'emailkey']);
        $table->add_index('centrestate', XMLDB_INDEX_NOTUNIQUE, ['centreid', 'state']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026122970, 'local', 'oksigeniaeguahe');
    }

    if ($oldversion < 2026123000) {
        // The invitation to an act by more than one way: when it went by mail and by phone, the link the message carries, and
        // the way the family opened it.
        $table = new xmldb_table('local_oksigeniaeguahe_invite');
        foreach (
            [
                new xmldb_field('mailsent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated'),
                new xmldb_field('phonesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'mailsent'),
                new xmldb_field('phonelink', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'phonesent'),
                new xmldb_field('via', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'phonelink'),
            ] as $field
        ) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // What went before, without its time: the time the row was made. A letter answered with no letter before it (opened
        // and answered at the moment it was made) went by no way.
        $DB->execute("UPDATE {local_oksigeniaeguahe_invite} SET mailsent = timecreated WHERE (channel IS NULL OR channel IN ('', 'email', 'both'))
            AND mailsent = 0 AND NOT (opened = timecreated AND replied = timecreated)");
        $DB->execute("UPDATE {local_oksigeniaeguahe_invite} SET phonesent = timecreated WHERE channel IN ('phone', 'both') AND phonesent = 0");
        // Opened before there was more than one way: by the only one it had.
        $DB->execute("UPDATE {local_oksigeniaeguahe_invite} SET via = 'email' WHERE opened > 0 AND (channel IS NULL OR channel IN ('', 'email'))");
        $DB->execute("UPDATE {local_oksigeniaeguahe_invite} SET via = 'phone' WHERE opened > 0 AND channel = 'phone'");
        upgrade_plugin_savepoint(true, 2026123000, 'local', 'oksigeniaeguahe');
    }

    return true;
}
