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
 * Free-tier (read scope) Moodle tools.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Free Moodle tools: list courses, view assignments, grades, profile.
 */
class core_module extends module_base {

    /** @var string Tool namespace prefix. */
    protected string $module_name = 'moodle';

    /**
     * Register the six free read-scope tools.
     */
    protected function register_tools(): void {
        $this->register_tool('getCourses', [
            'description'    => 'List available courses',
            'input_schema'   => ['type' => 'object',
                'properties' => ['limit' => ['type' => 'integer']]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getCourse', [
            'description'    => 'Get details of a specific course',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid'],
                'properties' => ['courseid' => ['type' => 'integer']]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getEnrollments', [
            'description'    => 'Get courses the current user is enrolled in',
            'input_schema'   => ['type' => 'object', 'properties' => new \stdClass()],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getAssignments', [
            'description'    => 'List assignments in a course',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid'],
                'properties' => ['courseid' => ['type' => 'integer']]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getGrades', [
            'description'    => 'Get grades for the current user in a course',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid'],
                'properties' => ['courseid' => ['type' => 'integer']]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getUserProfile', [
            'description'    => 'Get profile of the current user',
            'input_schema'   => ['type' => 'object', 'properties' => new \stdClass()],
            'required_scope' => 'read',
        ]);
    }

    /**
     * List courses visible to the caller.
     *
     * @param array $params  Request body.
     * @param array $context Must contain `userid`.
     * @return array Course summaries.
     */
    protected function execute_getCourses(array $params, array $context = []): array {
        $userid = (int) ($context['userid'] ?? 0);
        $limit = $this->clean_optional($params, 'limit', PARAM_INT, 20);
        $courses = enrol_get_all_users_courses($userid, true,
            ['id', 'fullname', 'shortname', 'summary', 'startdate', 'enddate']);
        return array_values(array_map(static fn($c) => [
            'id'        => (int) $c->id,
            'fullname'  => $c->fullname,
            'shortname' => $c->shortname,
            'summary'   => strip_tags($c->summary ?? ''),
            'startdate' => (int) $c->startdate,
        ], array_slice($courses, 0, max(1, (int) $limit))));
    }

    /**
     * Single course details.
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Course details.
     */
    protected function execute_getCourse(array $params, array $context = []): array {
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $course = get_course($courseid);
        return [
            'id'        => (int) $course->id,
            'fullname'  => $course->fullname,
            'shortname' => $course->shortname,
            'summary'   => strip_tags($course->summary ?? ''),
            'startdate' => (int) $course->startdate,
        ];
    }

    /**
     * List of the caller's enrolled courses.
     *
     * @param array $params Request body (unused).
     * @param array $context Must contain `userid`.
     * @return array Course summaries.
     */
    protected function execute_getEnrollments(array $params, array $context = []): array {
        $userid = (int) ($context['userid'] ?? 0);
        $courses = enrol_get_all_users_courses($userid, true,
            ['id', 'fullname', 'shortname']);
        return array_values(array_map(static fn($c) => [
            'id'        => (int) $c->id,
            'fullname'  => $c->fullname,
            'shortname' => $c->shortname,
        ], $courses));
    }

    /**
     * Assignments inside a course.
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Assignment summaries.
     */
    protected function execute_getAssignments(array $params, array $context = []): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $assigns = $DB->get_records('assign', ['course' => $courseid],
            'duedate ASC', 'id,name,intro,duedate,allowsubmissionsfromdate', 0, 20);
        return array_values(array_map(static fn($a) => [
            'id'      => (int) $a->id,
            'name'    => $a->name,
            'intro'   => strip_tags($a->intro),
            'duedate' => (int) $a->duedate,
        ], $assigns));
    }

    /**
     * Grades for the caller in a course.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array Grade items.
     */
    protected function execute_getGrades(array $params, array $context = []): array {
        $userid = (int) ($context['userid'] ?? 0);
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $grades = grade_get_course_grades($courseid, [$userid]);
        $rows = is_object($grades) && isset($grades->grades) ? $grades->grades : ($grades[$userid] ?? []);
        return array_values(array_map(static fn($g) => [
            'itemname'  => $g->str_long_grade ?? '',
            'grade'     => $g->grade ?? null,
            'str_grade' => $g->str_grade ?? '',
        ], $rows));
    }

    /**
     * Public profile of the caller.
     *
     * @param array $params Request body (unused).
     * @param array $context Must contain `userid`.
     * @return array Profile summary.
     */
    protected function execute_getUserProfile(array $params, array $context = []): array {
        $userid = (int) ($context['userid'] ?? 0);
        $u = \core_user::get_user($userid, '*', MUST_EXIST);
        return [
            'id'        => (int) $u->id,
            'username'  => $u->username,
            'fullname'  => fullname($u),
            'email'     => $u->email,
            'lastlogin' => (int) $u->lastlogin,
        ];
    }
}
