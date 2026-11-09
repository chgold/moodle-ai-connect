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
 * Shared course / activity access checks for the module tools.
 *
 * These mirror what require_login($course, false, $cm) does on a native
 * Moodle page, without the redirects: the course must be accessible to the
 * acting user (active enrolment, course visibility, guest/viewing rules) and
 * the course module must be "uservisible" for that user (hidden activities,
 * stealth activities and availability restrictions are honoured exactly as
 * course_modinfo computes them), plus the module's own :view capability.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\local;

defined('MOODLE_INTERNAL') || die();

class activity_access {
    /**
     * Throw unless the acting user may enter the course (require_login equivalent).
     *
     * @param int $courseid Course id.
     * @param int $userid Acting user id.
     * @return \stdClass The course record.
     */
    public static function require_course(int $courseid, int $userid): \stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        if (!can_access_course($course, $userid, '', true)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'access this course');
        }
        return $course;
    }

    /**
     * Boolean variant of require_course().
     *
     * @param int $courseid Course id.
     * @param int $userid Acting user id.
     * @return bool
     */
    public static function can_enter_course(int $courseid, int $userid): bool {
        try {
            self::require_course($courseid, $userid);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Resolve an activity instance to a cm_info the acting user can see.
     *
     * A hidden / availability-restricted / missing activity all produce the same
     * "not found" error so the tool does not disclose the existence of activities
     * the user could not see natively.
     *
     * @param string $modname Module name (e.g. h5pactivity).
     * @param int $instanceid Activity instance id.
     * @param int $userid Acting user id.
     * @param string $label Human label for the error message.
     * @return \cm_info
     */
    public static function require_instance(string $modname, int $instanceid, int $userid, string $label): \cm_info {
        $cm = get_coursemodule_from_instance($modname, $instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "{$label} {$instanceid} not found");
        }
        return self::require_cm((int) $cm->course, (int) $cm->id, $modname, $userid, $label, $instanceid);
    }

    /**
     * Resolve a course-module id to a cm_info the acting user can see.
     *
     * @param string $modname Module name.
     * @param int $cmid Course module id.
     * @param int $userid Acting user id.
     * @param string $label Human label for the error message.
     * @return \cm_info
     */
    public static function require_cmid(string $modname, int $cmid, int $userid, string $label): \cm_info {
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "{$label} course module {$cmid} not found");
        }
        return self::require_cm((int) $cm->course, (int) $cm->id, $modname, $userid, $label, $cmid);
    }

    /**
     * Shared tail of require_instance()/require_cmid().
     *
     * @param int $courseid Course id.
     * @param int $cmid Course module id.
     * @param string $modname Module name.
     * @param int $userid Acting user id.
     * @param string $label Human label.
     * @param int $shownid Id echoed in the error message.
     * @return \cm_info
     */
    private static function require_cm(int $courseid, int $cmid, string $modname, int $userid, string $label, int $shownid): \cm_info {
        self::require_course($courseid, $userid);
        $notfound = "{$label} {$shownid} not found";
        try {
            $cminfo = get_fast_modinfo($courseid, $userid)->get_cm($cmid);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $notfound);
        }
        if (!self::cm_viewable($cminfo, $modname, $userid)) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $notfound);
        }
        return $cminfo;
    }

    /**
     * Whether a cm_info is visible to the user and they hold mod/{modname}:view.
     *
     * @param \cm_info $cm Course module (modinfo built for $userid).
     * @param string $modname Module name.
     * @param int $userid Acting user id.
     * @return bool
     */
    public static function cm_viewable(\cm_info $cm, string $modname, int $userid): bool {
        if ($cm->modname !== $modname || !$cm->uservisible) {
            return false;
        }
        return has_capability("mod/{$modname}:view", \context_module::instance($cm->id), $userid);
    }

    /**
     * Activities of a module in one course that the user can see, keyed by instance id.
     *
     * @param string $modname Module name.
     * @param int $courseid Course id (access already verified by the caller or not).
     * @param int $userid Acting user id.
     * @return \cm_info[] instanceid => cm_info
     */
    public static function visible_instances(string $modname, int $courseid, int $userid): array {
        if (!self::can_enter_course($courseid, $userid)) {
            return [];
        }
        $out = [];
        foreach (get_fast_modinfo($courseid, $userid)->get_instances_of($modname) as $instanceid => $cm) {
            if (self::cm_viewable($cm, $modname, $userid)) {
                $out[(int) $instanceid] = $cm;
            }
        }
        return $out;
    }

    /**
     * User ids whose data the viewer may see under the activity's group mode.
     *
     * Returns null when no group restriction applies (no groups, visible groups,
     * or the viewer has moodle/site:accessallgroups) and otherwise the members of
     * the viewer's allowed groups — the same rule groups_user_groups_visible()
     * applies on native reports.
     *
     * @param \cm_info $cm Course module.
     * @param int $userid Viewer id.
     * @return int[]|null
     */
    public static function group_visible_userids(\cm_info $cm, int $userid): ?array {
        $context = \context_module::instance($cm->id);
        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $context, $userid)
        ) {
            return null;
        }
        $ids = [$userid => $userid];
        foreach (groups_get_activity_allowed_groups($cm, $userid) as $group) {
            foreach (groups_get_members($group->id, 'u.id') as $member) {
                $ids[(int) $member->id] = (int) $member->id;
            }
        }
        return array_values($ids);
    }

    /**
     * Whether the viewer may see data of $targetid under the activity's group mode.
     *
     * @param \cm_info $cm Course module.
     * @param int $viewerid Viewer id.
     * @param int $targetid Target user id.
     * @return bool
     */
    public static function user_in_visible_groups(\cm_info $cm, int $viewerid, int $targetid): bool {
        if ($viewerid === $targetid) {
            return true;
        }
        $ids = self::group_visible_userids($cm, $viewerid);
        return $ids === null || in_array($targetid, $ids, true);
    }
}
