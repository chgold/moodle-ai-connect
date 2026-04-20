<?php
namespace local_aiconnect\controller;

class tools_controller {

    private static array $pro_tools = ['moodle.enrollUser','moodle.createAssignment','moodle.submitGrade','moodle.sendMessage','moodle.updateCourse','moodle.searchUsers'];

    public function execute(string $tool, array $params, int $userid, string $scope): array {
        if (in_array($tool, self::$pro_tools) && !str_contains($scope, 'write')) {
            return ['error' => 'insufficient_scope', 'message' => 'This tool requires write scope (Pro tier)'];
        }
        return match($tool) {
            'moodle.getCourses'      => $this->get_courses($params, $userid),
            'moodle.getCourse'       => $this->get_course($params, $userid),
            'moodle.getEnrollments'  => $this->get_enrollments($userid),
            'moodle.getAssignments'  => $this->get_assignments($params, $userid),
            'moodle.getGrades'       => $this->get_grades($params, $userid),
            'moodle.getUserProfile'  => $this->get_user_profile($userid),
            'moodle.enrollUser'      => $this->enroll_user($params, $userid),
            'moodle.createAssignment'=> $this->create_assignment($params, $userid),
            'moodle.submitGrade'     => $this->submit_grade($params, $userid),
            'moodle.sendMessage'     => $this->send_message($params, $userid),
            'moodle.updateCourse'    => $this->update_course($params, $userid),
            'moodle.searchUsers'     => $this->search_users($params, $userid),
            default                  => ['error' => 'tool_not_found'],
        };
    }

    private function get_courses(array $p, int $uid): array {
        $courses = enrol_get_all_users_courses($uid, true, ['id','fullname','shortname','summary','startdate','enddate']);
        return array_values(array_map(fn($c) => ['id' => $c->id, 'fullname' => $c->fullname, 'shortname' => $c->shortname, 'summary' => strip_tags($c->summary ?? '')], array_slice($courses, 0, $p['limit'] ?? 20)));
    }

    private function get_course(array $p, int $uid): array {
        $course = get_course($p['courseid']);
        return ['id' => $course->id, 'fullname' => $course->fullname, 'shortname' => $course->shortname, 'summary' => strip_tags($course->summary ?? ''), 'startdate' => $course->startdate];
    }

    private function get_enrollments(int $uid): array {
        $courses = enrol_get_all_users_courses($uid, true, ['id','fullname','shortname']);
        return array_values(array_map(fn($c) => ['id' => $c->id, 'fullname' => $c->fullname, 'shortname' => $c->shortname], $courses));
    }

    private function get_assignments(array $p, int $uid): array {
        global $DB;
        $assigns = $DB->get_records('assign', ['course' => $p['courseid']], 'duedate ASC', 'id,name,intro,duedate,allowsubmissionsfromdate', 0, 20);
        return array_values(array_map(fn($a) => ['id' => $a->id, 'name' => $a->name, 'intro' => strip_tags($a->intro), 'duedate' => $a->duedate], $assigns));
    }

    private function get_grades(array $p, int $uid): array {
        $grades = grade_get_course_grades($p['courseid'], [$uid]);
        return array_values(array_map(fn($g) => ['itemname' => $g->str_long_grade ?? '', 'grade' => $g->grade, 'str_grade' => $g->str_grade ?? ''], $grades[$uid] ?? []));
    }

    private function get_user_profile(int $uid): array {
        global $DB;
        $u = $DB->get_record('user', ['id' => $uid], 'id,username,firstname,lastname,email,lastlogin,timecreated');
        return ['id' => $u->id, 'username' => $u->username, 'fullname' => fullname($u), 'email' => $u->email, 'lastlogin' => $u->lastlogin];
    }

    private function enroll_user(array $p, int $uid): array {
        $enrol = enrol_get_plugin('manual');
        $instance = $enrol->get_instance_by_courseid($p['courseid'] ?? 0) ?? $enrol->add_default_instance(get_course($p['courseid']));
        $enrol->enrol_user($instance, $p['userid'], $p['roleid'] ?? 5);
        return ['enrolled' => true, 'userid' => $p['userid'], 'courseid' => $p['courseid']];
    }

    private function create_assignment(array $p, int $uid): array {
        global $DB;
        $id = $DB->insert_record('assign', ['course' => $p['courseid'], 'name' => $p['name'], 'intro' => $p['intro'] ?? '', 'introformat' => 1, 'timemodified' => time()]);
        return ['id' => $id, 'name' => $p['name'], 'courseid' => $p['courseid']];
    }

    private function submit_grade(array $p, int $uid): array {
        require_once(__DIR__ . '/../../../../mod/assign/locallib.php');
        $cm = get_coursemodule_from_instance('assign', $p['assignid']);
        $ctx = \context_module::instance($cm->id);
        $assign = new \assign($ctx, $cm, $cm->course);
        $assign->save_grade($p['userid'], (object)['grade' => $p['grade'], 'assignfeedbackcomments_editor' => ['text' => '']]);
        return ['graded' => true, 'grade' => $p['grade']];
    }

    private function send_message(array $p, int $uid): array {
        $msg = new \core\message\message();
        $msg->component   = 'moodle';
        $msg->name        = 'instantmessage';
        $msg->userfrom    = $uid;
        $msg->userto      = $p['touserid'];
        $msg->subject     = 'Message from AI Connect';
        $msg->fullmessage = $p['message'];
        $msg->smallmessage= $p['message'];
        $msg->fullmessageformat = FORMAT_PLAIN;
        message_send($msg);
        return ['sent' => true, 'touserid' => $p['touserid']];
    }

    private function update_course(array $p, int $uid): array {
        $course = get_course($p['courseid']);
        if (isset($p['fullname'])) $course->fullname = $p['fullname'];
        if (isset($p['summary'])) $course->summary = $p['summary'];
        update_course($course);
        return ['updated' => true, 'courseid' => $p['courseid']];
    }

    private function search_users(array $p, int $uid): array {
        $users = core_user::search($p['query'] ?? '');
        return array_values(array_map(fn($u) => ['id' => $u->id, 'username' => $u->username, 'fullname' => fullname($u), 'email' => $u->email], array_slice($users, 0, 20)));
    }
}
