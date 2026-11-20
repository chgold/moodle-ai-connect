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

namespace local_aiconnect\local;

/**
 * Who may connect an AI agent (authorize / generate a token).
 *
 * Teacher, editing teacher and student roles are assigned inside a course,
 * not at site level, so a check of `local/aiconnect:use` in the system
 * context alone refused every teacher and student. A user may connect when
 * they hold the capability at site level OR in at least one course (or a
 * category above it). What the agent may then do is decided per tool by the
 * tool's own Moodle capability checks in the target course (SEC-00) — being
 * allowed to connect grants nothing by itself.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connect_access {
    /** Capability that allows connecting an agent. */
    public const CAPABILITY = 'local/aiconnect:use';

    /**
     * Can this user connect an agent?
     *
     * @param int|null $userid User id (null = current user).
     * @return bool
     */
    public static function can_connect(?int $userid = null): bool {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $userid = $userid ?? (int) $USER->id;
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        $user = \core_user::get_user($userid);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return false;
        }
        if (has_capability(self::CAPABILITY, \context_system::instance(), $userid)) {
            return true;
        }
        // Held in at least one course (role assigned in the course or in a category above it).
        $courses = get_user_capability_course(self::CAPABILITY, $userid, true, '', 'id', 1);
        return !empty($courses);
    }
}
