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
 * User-blocking service for AI Connect.
 *
 * Admins can block individual Moodle users from accessing any AI Connect
 * API endpoint. A blocked user receives HTTP 403 on every authenticated
 * request regardless of token validity.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helpers for managing the AI Connect user-block list.
 */
class user_blocking {
    /**
     * Block a Moodle user from accessing the AI Connect API.
     *
     * If the user is already blocked this is a no-op (idempotent).
     *
     * @param int $userid    Moodle user id to block.
     * @param int $blockedby Admin user id performing the block.
     * @return void
     */
    public static function block_user(int $userid, int $blockedby): void {
        global $DB;

        if ($DB->record_exists('local_aiconnect_blocked', ['userid' => $userid])) {
            return; // Already blocked — idempotent.
        }

        $DB->insert_record('local_aiconnect_blocked', (object) [
            'userid'      => $userid,
            'blockedby'   => $blockedby,
            'timecreated' => time(),
        ]);
    }

    /**
     * Remove the API block for a Moodle user.
     *
     * If the user was not blocked this is a no-op (idempotent).
     *
     * @param int $userid Moodle user id to unblock.
     * @return void
     */
    public static function unblock_user(int $userid): void {
        global $DB;

        $DB->delete_records('local_aiconnect_blocked', ['userid' => $userid]);
    }

    /**
     * Check whether a user is currently blocked from the API.
     *
     * @param int $userid Moodle user id to check.
     * @return bool True when the user is blocked.
     */
    public static function is_user_blocked(int $userid): bool {
        global $DB;

        return $DB->record_exists('local_aiconnect_blocked', ['userid' => $userid]);
    }

    /**
     * Return all blocked users with their block metadata.
     *
     * @return \stdClass[] Rows from local_aiconnect_blocked joined with {user}.
     */
    public static function get_blocked_users(): array {
        global $DB;

        $sql = "SELECT b.id, b.userid, b.blockedby, b.timecreated,
                       u.firstname, u.lastname, u.email,
                       ab.firstname AS blocker_firstname,
                       ab.lastname  AS blocker_lastname
                  FROM {local_aiconnect_blocked} b
             LEFT JOIN {user} u  ON u.id  = b.userid
             LEFT JOIN {user} ab ON ab.id = b.blockedby
              ORDER BY b.timecreated DESC";

        return array_values($DB->get_records_sql($sql));
    }
}
