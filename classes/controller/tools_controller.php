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
 * Tool dispatcher — runs Moodle queries on behalf of the authenticated agent.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\controller;

defined('MOODLE_INTERNAL') || die();

/**
 * Dispatches WebMCP tool calls against Moodle data.
 *
 * Free tools (read scope): inspect courses, enrollments, grades.
 * Pro tools (write scope): enrol, grade, send messages, etc.
 */
class tools_controller {

    /** @var string[] Tools that require the "write" scope (Pro tier). */
    private static array $protools = [
        'moodle.enrollUser',
        'moodle.createAssignment',
        'moodle.submitGrade',
        'moodle.sendMessage',
        'moodle.updateCourse',
        'moodle.searchUsers',
    ];

    /**
     * Execute a tool by name.
     *
     * @param string $tool   Fully-qualified tool name (e.g. moodle.getCourses).
     * @param array  $params Decoded JSON request body.
     * @param int    $userid Authenticated Moodle user id.
     * @param string $scope  Scopes granted to the bearer token.
     * @return array Tool response. On error, contains an `error` key.
     */
    public function execute(string $tool, array $params, int $userid, string $scope): array {
        if (in_array($tool, self::$protools, true) && !str_contains($scope, 'write')) {
            return [
                'error'   => 'insufficient_scope',
                'message' => 'This tool requires write scope (Pro tier).',
            ];
        }

        try {
            return match ($tool) {
                'moodle.getCourses'       => $this->get_courses($params, $userid),
                'moodle.getCourse'        => $this->get_course($params),
                'moodle.getEnrollments'   => $this->get_enrollments($userid),
                'moodle.getAssignments'   => $this->get_assignments($params),
                'moodle.getGrades'        => $this->get_grades($params, $userid),
                'moodle.getUserProfile'   => $this->get_user_profile($userid),
                'moodle.enrollUser'       => $this->enroll_user($params),
                'moodle.createAssignment' => $this->create_assignment($params),
                'moodle.submitGrade'      => $this->submit_grade($params),
                'moodle.sendMessage'      => $this->send_message($params, $userid),
                'moodle.updateCourse'     => $this->update_course($params),
                'moodle.searchUsers'      => $this->search_users($params),
                default                   => ['error' => 'tool_not_found'],
            };
        } catch (\invalid_parameter_exception $e) {
            return ['error' => 'tool_error', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            debugging('local_aiconnect tool ' . $tool . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['error' => 'tool_error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Require a parameter and validate it via Moodle's PARAM_* cleaner.
     *
     * @param array  $params Raw request body.
     * @param string $key    Parameter name.
     * @param string $type   PARAM_* constant.
     * @return mixed Cleaned value.
     * @throws \invalid_parameter_exception When the parameter is missing.
     */
    private function clean_required(array $params, string $key, string $type) {
        if (!array_key_exists($key, $params)) {
            throw new \invalid_parameter_exception("Missing parameter: $key");
        }
        return clean_param($params[$key], $type);
    }

    /**
     * Cleaner for optional parameters.
     *
     * @param array  $params  Raw request body.
     * @param string $key     Parameter name.
     * @param string $type    PARAM_* constant.
     * @param mixed  $default Default to return when the key is absent.
     * @return mixed Cleaned value or default.
     */
    private function clean_optional(array $params, string $key, string $type, $default = null) {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        return clean_param($params[$key], $type);
    }

    /**
     * moodle.getCourses — list courses visible to the caller.
     *
     * @param array $params Request body.
     * @param int   $userid Authenticated user id.
     * @return array List of course summaries.
     */
    private function get_courses(array $params, int $userid): array {
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
     * moodle.getCourse — single course details.
     *
     * @param array $params Request body.
     * @return array Course details.
     */
    private function get_course(array $params): array {
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
     * moodle.getEnrollments — list the caller's enrolled courses.
     *
     * @param int $userid Authenticated user id.
     * @return array List of enrolled courses.
     */
    private function get_enrollments(int $userid): array {
        $courses = enrol_get_all_users_courses($userid, true,
            ['id', 'fullname', 'shortname']);
        return array_values(array_map(static fn($c) => [
            'id'        => (int) $c->id,
            'fullname'  => $c->fullname,
            'shortname' => $c->shortname,
        ], $courses));
    }

    /**
     * moodle.getAssignments — list assignments in a course.
     *
     * @param array $params Request body.
     * @return array List of assignment summaries.
     */
    private function get_assignments(array $params): array {
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
     * moodle.getGrades — grades for the caller in a course.
     *
     * @param array $params Request body.
     * @param int   $userid Authenticated user id.
     * @return array List of grade items.
     */
    private function get_grades(array $params, int $userid): array {
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
     * moodle.getUserProfile — public profile of the caller.
     *
     * @param int $userid Authenticated user id.
     * @return array Profile summary.
     */
    private function get_user_profile(int $userid): array {
        $u = \core_user::get_user($userid, '*', MUST_EXIST);
        return [
            'id'        => (int) $u->id,
            'username'  => $u->username,
            'fullname'  => fullname($u),
            'email'     => $u->email,
            'lastlogin' => (int) $u->lastlogin,
        ];
    }

    /**
     * moodle.enrollUser — manual enrolment into a course.
     *
     * @param array $params Request body.
     * @return array Enrollment result.
     */
    private function enroll_user(array $params): array {
        global $DB;
        $targetuser = $this->clean_required($params, 'userid', PARAM_INT);
        $courseid   = $this->clean_required($params, 'courseid', PARAM_INT);
        $roleid     = $this->clean_optional($params, 'roleid', PARAM_INT, 5);

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            return ['error' => 'tool_error', 'message' => 'Manual enrolment plugin disabled'];
        }
        $instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $courseid], '*', IGNORE_MISSING);
        if (!$instance) {
            $instanceid = $enrol->add_default_instance(get_course($courseid));
            $instance = $instanceid ? $DB->get_record('enrol', ['id' => $instanceid]) : null;
        }
        if (!$instance) {
            return ['error' => 'tool_error', 'message' => 'Could not get manual enrol instance'];
        }
        $enrol->enrol_user($instance, $targetuser, $roleid);

        return ['enrolled' => true, 'userid' => $targetuser, 'courseid' => $courseid];
    }

    /**
     * moodle.createAssignment — insert a row in mdl_assign.
     *
     * @param array $params Request body.
     * @return array Created assignment summary.
     */
    private function create_assignment(array $params): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $name     = $this->clean_required($params, 'name', PARAM_TEXT);
        $intro    = $this->clean_optional($params, 'intro', PARAM_CLEANHTML, '');

        $id = $DB->insert_record('assign', (object) [
            'course'       => $courseid,
            'name'         => $name,
            'intro'        => $intro,
            'introformat'  => FORMAT_HTML,
            'timemodified' => time(),
        ]);
        return ['id' => (int) $id, 'name' => $name, 'courseid' => $courseid];
    }

    /**
     * moodle.submitGrade — save a grade for an assignment submission.
     *
     * @param array $params Request body.
     * @return array Grade result.
     */
    private function submit_grade(array $params): array {
        global $CFG;
        $assignid = $this->clean_required($params, 'assignid', PARAM_INT);
        $studentid = $this->clean_required($params, 'userid', PARAM_INT);
        $grade   = $this->clean_required($params, 'grade', PARAM_FLOAT);

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cm = get_coursemodule_from_instance('assign', $assignid);
        if (!$cm) {
            return ['error' => 'tool_error', 'message' => 'Assignment not found'];
        }
        $ctx = \context_module::instance($cm->id);
        $assign = new \assign($ctx, $cm, $cm->course);
        $assign->save_grade($studentid, (object) [
            'grade' => $grade,
            'assignfeedbackcomments_editor' => ['text' => '', 'format' => FORMAT_HTML],
        ]);
        return ['graded' => true, 'grade' => $grade, 'userid' => $studentid];
    }

    /**
     * moodle.sendMessage — deliver an instant message via Moodle messaging.
     *
     * @param array $params Request body.
     * @param int   $userid Authenticated user id (sender).
     * @return array Send result.
     */
    private function send_message(array $params, int $userid): array {
        $touserid = $this->clean_required($params, 'touserid', PARAM_INT);
        $text     = $this->clean_required($params, 'message', PARAM_TEXT);

        $msg = new \core\message\message();
        $msg->component         = 'moodle';
        $msg->name              = 'instantmessage';
        $msg->userfrom          = $userid;
        $msg->userto            = $touserid;
        $msg->subject           = 'Message from AI Connect';
        $msg->fullmessage       = $text;
        $msg->smallmessage      = $text;
        $msg->fullmessagehtml   = format_text($text, FORMAT_PLAIN);
        $msg->fullmessageformat = FORMAT_PLAIN;
        $msg->notification      = 0;
        message_send($msg);

        return ['sent' => true, 'touserid' => $touserid];
    }

    /**
     * moodle.updateCourse — change course metadata.
     *
     * @param array $params Request body.
     * @return array Update result.
     */
    private function update_course(array $params): array {
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $course = get_course($courseid);
        if (array_key_exists('fullname', $params)) {
            $course->fullname = clean_param($params['fullname'], PARAM_TEXT);
        }
        if (array_key_exists('summary', $params)) {
            $course->summary = clean_param($params['summary'], PARAM_CLEANHTML);
        }
        update_course($course);
        return ['updated' => true, 'courseid' => $courseid];
    }

    /**
     * moodle.searchUsers — search users by name or email.
     *
     * @param array $params Request body.
     * @return array Matching users (max 20).
     */
    private function search_users(array $params): array {
        global $DB;
        $query = $this->clean_required($params, 'query', PARAM_TEXT);

        $like = $DB->sql_like('LOWER(' . $DB->sql_concat('firstname', "' '", 'lastname', "' '", 'email') . ')',
            ':needle', false, false);
        $rows = $DB->get_records_select('user', "$like AND deleted = 0",
            ['needle' => '%' . strtolower($query) . '%'],
            'lastname ASC',
            'id,username,firstname,lastname,email',
            0, 20);

        return array_values(array_map(static fn($u) => [
            'id'       => (int) $u->id,
            'username' => $u->username,
            'fullname' => fullname($u),
            'email'    => $u->email,
        ], $rows));
    }
}
