<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy provider for local_aiconnect.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_system;

defined('MOODLE_INTERNAL') || die();

/**
 * Implements GDPR metadata, export and delete handlers for AI Connect data.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the tables that store personal data.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aiconnect_codes', [
            'userid'        => 'privacy:metadata:local_aiconnect_codes:userid',
            'client_id'     => 'privacy:metadata:local_aiconnect_clients:client_id',
        ], 'privacy:metadata:local_aiconnect_codes');

        $collection->add_database_table('local_aiconnect_tokens', [
            'userid'    => 'privacy:metadata:local_aiconnect_tokens:userid',
            'scope'     => 'privacy:metadata:local_aiconnect_tokens:scope',
            'client_id' => 'privacy:metadata:local_aiconnect_clients:client_id',
        ], 'privacy:metadata:local_aiconnect_tokens');

        return $collection;
    }

    /**
     * Personal data is stored against the system context.
     *
     * @param int $userid Moodle user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        global $DB;
        if ($DB->record_exists('local_aiconnect_tokens', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_codes', ['userid' => $userid])) {
            $list->add_system_context();
        }
        return $list;
    }

    /**
     * Find users in a given context that have stored data here.
     *
     * @param userlist $userlist Userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_aiconnect_tokens}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_aiconnect_codes}', []);
    }

    /**
     * Export personal data for the given user contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::contextlist_has_system($contextlist)) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $tokens = $DB->get_records('local_aiconnect_tokens', ['userid' => $userid],
            'timecreated ASC', 'id,tokentype,client_id,scope,timecreated,expiry,revoked');
        if ($tokens) {
            writer::with_context(context_system::instance())
                ->export_data(['local_aiconnect', 'tokens'], (object) ['tokens' => array_values($tokens)]);
        }
    }

    /**
     * Remove all personal data for everyone in the given context.
     *
     * @param context $context Context to scrub.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if (!$context instanceof context_system) {
            return;
        }
        $DB->delete_records('local_aiconnect_tokens', []);
        $DB->delete_records('local_aiconnect_codes', []);
        $DB->delete_records('local_aiconnect_ratelimits', []);
    }

    /**
     * Remove personal data for a single user in the given contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::contextlist_has_system($contextlist)) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('local_aiconnect_tokens', ['userid' => $userid]);
        $DB->delete_records('local_aiconnect_codes', ['userid' => $userid]);
    }

    /**
     * Remove personal data for the given users in a single context.
     *
     * @param approved_userlist $userlist Users to delete.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_aiconnect_tokens', "userid $insql", $params);
        $DB->delete_records_select('local_aiconnect_codes', "userid $insql", $params);
    }

    /**
     * True when the contextlist includes the system context.
     *
     * @param approved_contextlist $contextlist Contexts to inspect.
     * @return bool
     */
    private static function contextlist_has_system(approved_contextlist $contextlist): bool {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                return true;
            }
        }
        return false;
    }
}
