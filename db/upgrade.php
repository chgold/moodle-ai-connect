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
 * Upgrade script for local_aiconnect.
 *
 * Add a new `if ($oldversion < <new_version>)` block whenever the schema
 * changes, then bump `$plugin->version` in version.php. The Moodle upgrade
 * runner replays each block in order on installations that need to catch up.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run the upgrade steps for local_aiconnect.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool Always true on success.
 */
function xmldb_local_aiconnect_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051000) {
        $table = new xmldb_table('local_aiconnect_clients');
        $index = new xmldb_index('client_id_idx', XMLDB_INDEX_UNIQUE, ['client_id']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('local_aiconnect_codes');
        if ($dbman->table_exists($table)) {
            foreach (
                [
                new xmldb_index('code_idx', XMLDB_INDEX_UNIQUE, ['code']),
                new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']),
                new xmldb_index('expiry_idx', XMLDB_INDEX_NOTUNIQUE, ['expiry']),
                ] as $index
            ) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        $table = new xmldb_table('local_aiconnect_tokens');
        if ($dbman->table_exists($table)) {
            foreach (
                [
                new xmldb_index('token_idx', XMLDB_INDEX_UNIQUE, ['token']),
                new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']),
                new xmldb_index('expiry_idx', XMLDB_INDEX_NOTUNIQUE, ['expiry']),
                new xmldb_index('tokentype_revoked_idx', XMLDB_INDEX_NOTUNIQUE, ['tokentype', 'revoked']),
                ] as $index
            ) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        $table = new xmldb_table('local_aiconnect_ratelimits');
        if ($dbman->table_exists($table)) {
            foreach (
                [
                new xmldb_index('token_window_idx', XMLDB_INDEX_UNIQUE, ['token', 'windowtype']),
                new xmldb_index('windowstart_idx', XMLDB_INDEX_NOTUNIQUE, ['windowstart']),
                ] as $index
            ) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026051000, 'local', 'aiconnect');
    }

    if ($oldversion < 2026051100) {
        $table = new xmldb_table('local_aiconnect_token_registry');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('token_prefix', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $table->add_field('user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('client_id', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('issued_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('expires_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('last_used_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('revoked_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('revoked_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'oauth');
            $table->add_field('ip_address', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('token_prefix', XMLDB_INDEX_NOTUNIQUE, ['token_prefix']);
            $table->add_index('user_id', XMLDB_INDEX_NOTUNIQUE, ['user_id']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051100, 'local', 'aiconnect');
    }

    if ($oldversion < 2026051300) {
        // Seed the canonical webmcp-master OAuth client on existing installs.
        $table = new xmldb_table('local_aiconnect_clients');
        if (
            $dbman->table_exists($table)
            && !$DB->record_exists('local_aiconnect_clients', ['client_id' => 'webmcp-master'])
        ) {
            $DB->insert_record('local_aiconnect_clients', (object) [
                'client_id'     => 'webmcp-master',
                'client_name'   => 'WebMCP Master',
                'redirect_uris' => 'urn:ietf:wg:oauth:2.0:oob',
                'scopes'        => 'read write delete manage_users',
                'timecreated'   => time(),
            ]);
        }

        upgrade_plugin_savepoint(true, 2026051300, 'local', 'aiconnect');
    }

    if ($oldversion < 2026052500) {
        $table = new xmldb_table('local_aiconnect_token_registry');

        // Add last_used_ip column.
        $field = new xmldb_field('last_used_ip', XMLDB_TYPE_CHAR, '45', null, null, null, null, 'ip_address');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add last_used_ua column.
        $field = new xmldb_field('last_used_ua', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'last_used_ip');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add refresh_expires_at column.
        $field = new xmldb_field('refresh_expires_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'last_used_ua');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add composite index on revoked_at + expires_at for cleanup queries.
        $index = new xmldb_index('revoked_expires_idx', XMLDB_INDEX_NOTUNIQUE, ['revoked_at', 'expires_at']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add index on last_used_at for inactivity cleanup queries.
        $index = new xmldb_index('last_used_at_idx', XMLDB_INDEX_NOTUNIQUE, ['last_used_at']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026052500, 'local', 'aiconnect');
    }

    if ($oldversion < 2026060700) {
        $table = new xmldb_table('local_aiconnect_blocked');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('blockedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_idx', XMLDB_INDEX_UNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060700, 'local', 'aiconnect');
    }

    if ($oldversion < 2026112100) {
        // User confirmation flow (user-confirm-v1): pending actions, autonomy grants, audit log.
        $table = new xmldb_table('local_aiconnect_pending');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_aiconnect_pending');
        }
        $table = new xmldb_table('local_aiconnect_autonomy');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_aiconnect_autonomy');
        }
        $table = new xmldb_table('local_aiconnect_conflog');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_aiconnect_conflog');
        }

        upgrade_plugin_savepoint(true, 2026112100, 'local', 'aiconnect');
    }

    if ($oldversion < 2026112300) {
        // 2.2.9: students may connect an agent (local/aiconnect:use). New
        // installs get it from the archetype in db/access.php; existing sites
        // get it here — only for student-archetype roles that have no
        // definition for the capability yet, so an admin's choice is kept.
        $syscontext = context_system::instance();
        foreach (get_archetype_roles('student') as $role) {
            $exists = $DB->record_exists('role_capabilities', [
                'roleid' => $role->id,
                'contextid' => $syscontext->id,
                'capability' => 'local/aiconnect:use',
            ]);
            if (!$exists) {
                assign_capability('local/aiconnect:use', CAP_ALLOW, $role->id, $syscontext->id);
            }
        }

        upgrade_plugin_savepoint(true, 2026112300, 'local', 'aiconnect');
    }

    if ($oldversion < 2026112400) {
        // 2.2.10: official callback URLs of Claude and goldnat on the default clients.
        \local_aiconnect\lib\redirect_uri::apply_official();
        upgrade_plugin_savepoint(true, 2026112400, 'local', 'aiconnect');
    }

    return true;
}
