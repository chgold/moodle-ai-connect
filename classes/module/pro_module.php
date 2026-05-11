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
 * Pro-tier (write scope) Moodle tools.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Pro Moodle tools: enrolment, grading, messaging, course updates.
 */
class pro_module extends module_base {

    /** @var string Tool namespace prefix (shared with core_module). */
    protected string $module_name = 'moodle';

    /**
     * Register the six pro write-scope tools.
     */
    protected function register_tools(): void {
        $this->register_tool('enrollUser', [
            'description'    => 'Enroll a user in a course (Pro)',
            'input_schema'   => ['type' => 'object', 'required' => ['userid', 'courseid'],
                'properties' => [
                    'userid'   => ['type' => 'integer'],
                    'courseid' => ['type' => 'integer'],
                    'roleid'   => ['type' => 'integer'],
                ]],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);

        $this->register_tool('createAssignment', [
            'description'    => 'Create an assignment in a course (Pro)',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid', 'name'],
                'properties' => [
                    'courseid' => ['type' => 'integer'],
                    'name'     => ['type' => 'string'],
                    'intro'    => ['type' => 'string'],
                ]],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);

        $this->register_tool('submitGrade', [
            'description'    => 'Submit a grade for a student (Pro)',
            'input_schema'   => ['type' => 'object', 'required' => ['assignid', 'userid', 'grade'],
                'properties' => [
                    'assignid' => ['type' => 'integer'],
                    'userid'   => ['type' => 'integer'],
                    'grade'    => ['type' => 'number'],
                ]],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);

        $this->register_tool('sendMessage', [
            'description'    => 'Send a message to a user (Pro)',
            'input_schema'   => ['type' => 'object', 'required' => ['touserid', 'message'],
                'properties' => [
                    'touserid' => ['type' => 'integer'],
                    'message'  => ['type' => 'string'],
                ]],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);

        $this->register_tool('updateCourse', [
            'description'    => 'Update course details (Pro)',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid'],
                'properties' => [
                    'courseid' => ['type' => 'integer'],
                    'fullname' => ['type' => 'string'],
                    'summary'  => ['type' => 'string'],
                ]],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);

        $this->register_tool('searchUsers', [
            'description'    => 'Search users by name or email (Pro)',
            'input_schema'   => ['type' => 'object', 'required' => ['query'],
                'properties' => ['query' => ['type' => 'string']]],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);
    }

    /**
     * Enrol a user into a course via the manual enrolment plugin.
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Enrollment result.
     */
    protected function execute_enrollUser(array $params, array $context = []): array {
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
     * Create a new assignment in a course.
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Created assignment summary.
     */
    protected function execute_createAssignment(array $params, array $context = []): array {
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
     * Save a grade for an assignment submission.
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Grade result.
     */
    protected function execute_submitGrade(array $params, array $context = []): array {
        global $CFG;
        $assignid  = $this->clean_required($params, 'assignid', PARAM_INT);
        $studentid = $this->clean_required($params, 'userid', PARAM_INT);
        $grade     = $this->clean_required($params, 'grade', PARAM_FLOAT);

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
     * Deliver an instant message via Moodle messaging.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid` (sender).
     * @return array Send result.
     */
    protected function execute_sendMessage(array $params, array $context = []): array {
        $userid   = (int) ($context['userid'] ?? 0);
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
     * Update course metadata (fullname/summary).
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Update result.
     */
    protected function execute_updateCourse(array $params, array $context = []): array {
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
     * Search users by name or email (max 20 results).
     *
     * @param array $params Request body.
     * @param array $context Request context.
     * @return array Matching users.
     */
    protected function execute_searchUsers(array $params, array $context = []): array {
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
