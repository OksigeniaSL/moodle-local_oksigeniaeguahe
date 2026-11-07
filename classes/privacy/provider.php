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

namespace local_oksigeniaeguahe\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy of the users of Moodle in Eguahe: the people of the teams of the centres (who is in which team, what they
 * wrote and which permissions they noted).
 *
 * The families who ask for a place are not users of Moodle: their data is exported and erased by themselves from the
 * page of their household (f.php), or by the team on their request (household.php).
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * What is kept about users.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_oksigeniaeguahe_member', [
            'centreid' => 'privacy:metadata:member:centreid',
            'userid' => 'privacy:metadata:member:userid',
            'timecreated' => 'privacy:metadata:member:timecreated',
        ], 'privacy:metadata:member');
        $collection->add_database_table('local_oksigeniaeguahe_contact', [
            'userid' => 'privacy:metadata:contact:userid',
            'body' => 'privacy:metadata:contact:body',
            'timecreated' => 'privacy:metadata:contact:timecreated',
        ], 'privacy:metadata:contact');
        $collection->add_database_table('local_oksigeniaeguahe_consent', [
            'userid' => 'privacy:metadata:consent:userid',
            'timecreated' => 'privacy:metadata:consent:timecreated',
        ], 'privacy:metadata:consent');
        return $collection;
    }

    /**
     * Whether the user is in any of the tables.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_data(int $userid): bool {
        global $DB;
        foreach (['local_oksigeniaeguahe_member', 'local_oksigeniaeguahe_contact', 'local_oksigeniaeguahe_consent'] as $t) {
            if ($DB->record_exists($t, ['userid' => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Everything lives in the system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        if (self::has_data($userid)) {
            $list->add_system_context();
        }
        return $list;
    }

    /**
     * The users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach (['local_oksigeniaeguahe_member', 'local_oksigeniaeguahe_contact', 'local_oksigeniaeguahe_consent'] as $t) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$t}} WHERE userid > 0", []);
        }
    }

    /**
     * Exports the teams the user is in and what they wrote.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $teams = [];
            foreach ($DB->get_records('local_oksigeniaeguahe_member', ['userid' => $userid]) as $member) {
                $teams[] = ['centre' => $DB->get_field('local_oksigeniaeguahe_centre', 'name', ['id' => $member->centreid]),
                    'since' => \core_privacy\local\request\transform::datetime($member->timecreated)];
            }
            $written = [];
            foreach ($DB->get_records('local_oksigeniaeguahe_contact', ['userid' => $userid], 'timecreated ASC') as $row) {
                $written[] = ['kind' => $row->direction, 'channel' => $row->channel, 'text' => $row->body,
                    'when' => \core_privacy\local\request\transform::datetime($row->timecreated)];
            }
            $noted = $DB->count_records('local_oksigeniaeguahe_consent', ['userid' => $userid]);
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_oksigeniaeguahe')],
                (object) ['teams' => $teams, 'written' => $written, 'permissionsnoted' => $noted]
            );
        }
    }

    /**
     * Removes the people of the teams from everything: they leave the teams, and what they wrote stays for the family
     * history without their name.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('local_oksigeniaeguahe_member');
        $DB->set_field_select('local_oksigeniaeguahe_contact', 'userid', 0, 'userid > 0');
        $DB->set_field_select('local_oksigeniaeguahe_consent', 'userid', 0, 'userid > 0');
    }

    /**
     * Removes one person.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                self::forget([(int) $contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Removes several people.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel == CONTEXT_SYSTEM) {
            self::forget(array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Takes people out of the teams and their name out of what they wrote.
     *
     * @param int[] $userids
     */
    protected static function forget(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_oksigeniaeguahe_member', "userid $in", $params);
        $DB->set_field_select('local_oksigeniaeguahe_contact', 'userid', 0, "userid $in", $params);
        $DB->set_field_select('local_oksigeniaeguahe_consent', 'userid', 0, "userid $in", $params);
    }
}
