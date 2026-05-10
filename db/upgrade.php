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
            foreach ([
                new xmldb_index('code_idx', XMLDB_INDEX_UNIQUE, ['code']),
                new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']),
                new xmldb_index('expiry_idx', XMLDB_INDEX_NOTUNIQUE, ['expiry']),
            ] as $index) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        $table = new xmldb_table('local_aiconnect_tokens');
        if ($dbman->table_exists($table)) {
            foreach ([
                new xmldb_index('token_idx', XMLDB_INDEX_UNIQUE, ['token']),
                new xmldb_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']),
                new xmldb_index('expiry_idx', XMLDB_INDEX_NOTUNIQUE, ['expiry']),
                new xmldb_index('tokentype_revoked_idx', XMLDB_INDEX_NOTUNIQUE, ['tokentype', 'revoked']),
            ] as $index) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        $table = new xmldb_table('local_aiconnect_ratelimits');
        if ($dbman->table_exists($table)) {
            foreach ([
                new xmldb_index('token_window_idx', XMLDB_INDEX_UNIQUE, ['token', 'windowtype']),
                new xmldb_index('windowstart_idx', XMLDB_INDEX_NOTUNIQUE, ['windowstart']),
            ] as $index) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026051000, 'local', 'aiconnect');
    }

    return true;
}
