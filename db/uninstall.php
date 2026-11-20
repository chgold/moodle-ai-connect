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
 * Uninstall hook for local_aiconnect.
 *
 * Drops every table owned by the plugin and removes leftover config rows.
 * Moodle calls this automatically when an administrator uninstalls the plugin
 * from Site administration > Plugins > Plugins overview.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Custom uninstall procedure for local_aiconnect.
 *
 * @return bool Always true on success.
 */
function xmldb_local_aiconnect_uninstall(): bool {
    global $DB;

    $dbman = $DB->get_manager();

    $tables = [
        'local_aiconnect_token_registry',
        'local_aiconnect_ratelimits',
        'local_aiconnect_tokens',
        'local_aiconnect_codes',
        'local_aiconnect_clients',
        'local_aiconnect_blocked',
        'local_aiconnect_pending',
        'local_aiconnect_autonomy',
        'local_aiconnect_conflog',
    ];

    foreach ($tables as $tablename) {
        $table = new xmldb_table($tablename);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
    }

    $DB->delete_records('config_plugins', ['plugin' => 'local_aiconnect']);

    return true;
}
