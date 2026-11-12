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

    return true;
}
