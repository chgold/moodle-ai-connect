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
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_system;

/**
 * Implements GDPR metadata, export and delete handlers for AI Connect data.
 *
 * All data lives in the system context. Tables with personal data:
 *  - local_aiconnect_codes          (userid)
 *  - local_aiconnect_tokens         (userid)
 *  - local_aiconnect_ratelimits     (keyed by token, removed with the user's tokens)
 *  - local_aiconnect_token_registry (user_id, ip_address, last_used_ip, last_used_ua, revoked_by)
 *  - local_aiconnect_blocked        (userid, blockedby)
 *
 * Fields that record an admin acting on another user (registry.revoked_by,
 * blocked.blockedby) belong to that admin too: they are exported as actions
 * (without the other user's details) and anonymised (set to NULL) on delete,
 * so the other user's record is kept.
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

        $collection->add_database_table('local_aiconnect_ratelimits', [
            'token'        => 'privacy:metadata:local_aiconnect_ratelimits:token',
            'requestcount' => 'privacy:metadata:local_aiconnect_ratelimits:requestcount',
        ], 'privacy:metadata:local_aiconnect_ratelimits');

        $collection->add_database_table('local_aiconnect_token_registry', [
            'user_id'      => 'privacy:metadata:local_aiconnect_token_registry:user_id',
            'client_id'    => 'privacy:metadata:local_aiconnect_clients:client_id',
            'scope'        => 'privacy:metadata:local_aiconnect_tokens:scope',
            'issued_at'    => 'privacy:metadata:local_aiconnect_token_registry:issued_at',
            'last_used_at' => 'privacy:metadata:local_aiconnect_token_registry:last_used_at',
            'ip_address'   => 'privacy:metadata:local_aiconnect_token_registry:ip_address',
            'last_used_ip' => 'privacy:metadata:local_aiconnect_token_registry:last_used_ip',
            'last_used_ua' => 'privacy:metadata:local_aiconnect_token_registry:last_used_ua',
            'revoked_by'   => 'privacy:metadata:local_aiconnect_token_registry:revoked_by',
        ], 'privacy:metadata:local_aiconnect_token_registry');

        $collection->add_database_table('local_aiconnect_blocked', [
            'userid'      => 'privacy:metadata:local_aiconnect_blocked:userid',
            'blockedby'   => 'privacy:metadata:local_aiconnect_blocked:blockedby',
            'timecreated' => 'privacy:metadata:local_aiconnect_blocked:timecreated',
        ], 'privacy:metadata:local_aiconnect_blocked');

        $collection->add_database_table('local_aiconnect_pending', [
            'userid'      => 'privacy:metadata:local_aiconnect_pending:userid',
            'tool'        => 'privacy:metadata:local_aiconnect_pending:tool',
            'argsjson'    => 'privacy:metadata:local_aiconnect_pending:argsjson',
            'summary'     => 'privacy:metadata:local_aiconnect_pending:summary',
            'clientid'    => 'privacy:metadata:local_aiconnect_clients:client_id',
            'status'      => 'privacy:metadata:local_aiconnect_pending:status',
            'resultjson'  => 'privacy:metadata:local_aiconnect_pending:resultjson',
            'timecreated' => 'privacy:metadata:local_aiconnect_pending:timecreated',
            'timedecided' => 'privacy:metadata:local_aiconnect_pending:timedecided',
        ], 'privacy:metadata:local_aiconnect_pending');

        $collection->add_database_table('local_aiconnect_autonomy', [
            'userid'      => 'privacy:metadata:local_aiconnect_autonomy:userid',
            'tool'        => 'privacy:metadata:local_aiconnect_pending:tool',
            'clientid'    => 'privacy:metadata:local_aiconnect_clients:client_id',
            'timecreated' => 'privacy:metadata:local_aiconnect_pending:timecreated',
        ], 'privacy:metadata:local_aiconnect_autonomy');

        $collection->add_database_table('local_aiconnect_conflog', [
            'userid'      => 'privacy:metadata:local_aiconnect_conflog:userid',
            'actorid'     => 'privacy:metadata:local_aiconnect_conflog:actorid',
            'event'       => 'privacy:metadata:local_aiconnect_conflog:event',
            'ip'          => 'privacy:metadata:local_aiconnect_conflog:ip',
            'timecreated' => 'privacy:metadata:local_aiconnect_pending:timecreated',
        ], 'privacy:metadata:local_aiconnect_conflog');

        return $collection;
    }

    /**
     * Personal data is stored against the system context.
     *
     * @param int $userid Moodle user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $list = new contextlist();
        if (
            $DB->record_exists('local_aiconnect_tokens', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_codes', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_token_registry', ['user_id' => $userid])
            || $DB->record_exists('local_aiconnect_token_registry', ['revoked_by' => $userid])
            || $DB->record_exists('local_aiconnect_blocked', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_blocked', ['blockedby' => $userid])
            || $DB->record_exists('local_aiconnect_pending', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_autonomy', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_conflog', ['userid' => $userid])
            || $DB->record_exists('local_aiconnect_conflog', ['actorid' => $userid])
        ) {
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
        $userlist->add_from_sql('user_id', 'SELECT DISTINCT user_id FROM {local_aiconnect_token_registry}', []);
        $userlist->add_from_sql(
            'revoked_by',
            'SELECT DISTINCT revoked_by FROM {local_aiconnect_token_registry} WHERE revoked_by IS NOT NULL',
            []
        );
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_aiconnect_blocked}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_aiconnect_pending}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_aiconnect_autonomy}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_aiconnect_conflog}', []);
        $userlist->add_from_sql(
            'actorid',
            'SELECT DISTINCT actorid FROM {local_aiconnect_conflog} WHERE actorid IS NOT NULL AND actorid > 0',
            []
        );
        $userlist->add_from_sql(
            'blockedby',
            'SELECT DISTINCT blockedby FROM {local_aiconnect_blocked} WHERE blockedby IS NOT NULL',
            []
        );
    }

    /**
     * Export personal data for the given user contexts.
     *
     * Token values and token prefixes are never exported (they are credentials).
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::contextlist_has_system($contextlist)) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $writer = writer::with_context(context_system::instance());

        $tokens = $DB->get_records(
            'local_aiconnect_tokens',
            ['userid' => $userid],
            'timecreated ASC',
            'id,tokentype,client_id,scope,timecreated,expiry,revoked'
        );
        if ($tokens) {
            $out = [];
            foreach ($tokens as $t) {
                $out[] = (object) [
                    'tokentype'   => $t->tokentype,
                    'client_id'   => $t->client_id,
                    'scope'       => $t->scope,
                    'timecreated' => transform::datetime($t->timecreated),
                    'expiry'      => transform::datetime($t->expiry),
                    'revoked'     => transform::yesno($t->revoked),
                ];
            }
            $writer->export_data(['local_aiconnect', 'tokens'], (object) ['tokens' => $out]);
        }

        $registry = $DB->get_records(
            'local_aiconnect_token_registry',
            ['user_id' => $userid],
            'issued_at ASC',
            'id,client_id,scope,source,issued_at,expires_at,last_used_at,revoked_at,ip_address,last_used_ip,last_used_ua'
        );
        if ($registry) {
            $out = [];
            foreach ($registry as $r) {
                $out[] = (object) [
                    'client_id'    => $r->client_id,
                    'scope'        => $r->scope,
                    'source'       => $r->source,
                    'issued_at'    => transform::datetime($r->issued_at),
                    'expires_at'   => transform::datetime($r->expires_at),
                    'last_used_at' => $r->last_used_at ? transform::datetime($r->last_used_at) : null,
                    'revoked_at'   => $r->revoked_at ? transform::datetime($r->revoked_at) : null,
                    'ip_address'   => $r->ip_address,
                    'last_used_ip' => $r->last_used_ip,
                    'last_used_ua' => $r->last_used_ua,
                ];
            }
            $writer->export_data(['local_aiconnect', 'token_registry'], (object) ['tokens' => $out]);
        }

        $revokedcount = $DB->count_records('local_aiconnect_token_registry', ['revoked_by' => $userid]);
        if ($revokedcount) {
            $writer->export_data(
                ['local_aiconnect', 'tokens_revoked_by_you'],
                (object) ['count' => $revokedcount]
            );
        }

        $blocked = $DB->get_record('local_aiconnect_blocked', ['userid' => $userid], 'id,timecreated', IGNORE_MULTIPLE);
        if ($blocked) {
            $writer->export_data(
                ['local_aiconnect', 'blocked'],
                (object) ['blocked' => transform::yesno(1), 'timecreated' => transform::datetime($blocked->timecreated)]
            );
        }

        $blocks = $DB->get_records('local_aiconnect_blocked', ['blockedby' => $userid], 'timecreated ASC', 'id,timecreated');
        if ($blocks) {
            $out = [];
            foreach ($blocks as $b) {
                $out[] = (object) ['timecreated' => transform::datetime($b->timecreated)];
            }
            $writer->export_data(['local_aiconnect', 'blocks_by_you'], (object) ['blocks' => $out]);
        }

        $actions = $DB->get_records('local_aiconnect_pending', ['userid' => $userid], 'timecreated ASC');
        if ($actions) {
            $out = [];
            foreach ($actions as $a) {
                $out[] = (object) [
                    'tool'        => $a->tool,
                    'summary'     => $a->summary,
                    'arguments'   => $a->argsjson,
                    'client_id'   => $a->clientid,
                    'status'      => $a->status,
                    'result'      => $a->resultjson,
                    'timecreated' => transform::datetime($a->timecreated),
                    'timedecided' => $a->timedecided ? transform::datetime($a->timedecided) : null,
                ];
            }
            $writer->export_data(['local_aiconnect', 'ai_actions'], (object) ['actions' => $out]);
        }

        $grants = $DB->get_records('local_aiconnect_autonomy', ['userid' => $userid], 'timecreated ASC');
        if ($grants) {
            $out = [];
            foreach ($grants as $g) {
                $out[] = (object) ['tool' => $g->tool, 'client_id' => $g->clientid,
                    'timecreated' => transform::datetime($g->timecreated)];
            }
            $writer->export_data(['local_aiconnect', 'ai_autonomy'], (object) ['grants' => $out]);
        }

        $log = $DB->get_records_select(
            'local_aiconnect_conflog',
            'userid = ? OR actorid = ?',
            [$userid, $userid],
            'timecreated ASC, id ASC'
        );
        if ($log) {
            $out = [];
            foreach ($log as $l) {
                $out[] = (object) ['event' => $l->event, 'tool' => $l->tool, 'client_id' => $l->clientid, 'ip' => $l->ip,
                    'timecreated' => transform::datetime($l->timecreated)];
            }
            $writer->export_data(['local_aiconnect', 'ai_actions_log'], (object) ['events' => $out]);
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
        $DB->delete_records('local_aiconnect_token_registry', []);
        $DB->delete_records('local_aiconnect_blocked', []);
        $DB->delete_records('local_aiconnect_pending', []);
        $DB->delete_records('local_aiconnect_autonomy', []);
        $DB->delete_records('local_aiconnect_conflog', []);
    }

    /**
     * Remove personal data for a single user in the given contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!self::contextlist_has_system($contextlist)) {
            return;
        }
        self::delete_for_userids([(int) $contextlist->get_user()->id]);
    }

    /**
     * Remove personal data for the given users in a single context.
     *
     * @param approved_userlist $userlist Users to delete.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        self::delete_for_userids($userids);
    }

    /**
     * Delete the data of the given users; anonymise their actions on other users.
     *
     * @param int[] $userids Moodle user ids.
     */
    private static function delete_for_userids(array $userids): void {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Rate-limit counters are keyed by token value: remove them before the tokens.
        $DB->delete_records_select(
            'local_aiconnect_ratelimits',
            "token IN (SELECT token FROM {local_aiconnect_tokens} WHERE userid $insql)",
            $params
        );
        $DB->delete_records_select('local_aiconnect_tokens', "userid $insql", $params);
        $DB->delete_records_select('local_aiconnect_codes', "userid $insql", $params);
        $DB->delete_records_select('local_aiconnect_token_registry', "user_id $insql", $params);
        $DB->delete_records_select('local_aiconnect_blocked', "userid $insql", $params);
        $DB->delete_records_select('local_aiconnect_pending', "userid $insql", $params);
        $DB->delete_records_select('local_aiconnect_autonomy', "userid $insql", $params);
        $DB->delete_records_select('local_aiconnect_conflog', "userid $insql", $params);
        $DB->set_field_select('local_aiconnect_conflog', 'actorid', null, "actorid $insql", $params);

        $DB->set_field_select('local_aiconnect_token_registry', 'revoked_by', null, "revoked_by $insql", $params);
        $DB->set_field_select('local_aiconnect_blocked', 'blockedby', null, "blockedby $insql", $params);
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
