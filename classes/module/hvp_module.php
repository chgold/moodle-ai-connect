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
 * H5P (interactive content) read module (free). Capability-gated on
 * mod_h5pactivity (Moodle's core H5P activity).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

class hvp_module extends module_base {

    /** @var string Tool namespace prefix. */
    protected string $module_name = 'hvp';

    protected function register_tools(): void {
        $cap = 'mod_h5pactivity';

        $this->register_tool('getContentByCourse', [
            'description'         => 'List H5P interactive-content activities in a course',
            'input_schema'        => ['type' => 'object', 'required' => ['courseid'],
                'properties' => ['courseid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getContent', [
            'description'         => 'Get an H5P activity configuration',
            'input_schema'        => ['type' => 'object', 'required' => ['h5pactivityid'],
                'properties' => ['h5pactivityid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('searchContent', [
            'description'         => 'Search H5P activities by name',
            'input_schema'        => ['type' => 'object', 'required' => ['search'],
                'properties' => ['search' => ['type' => 'string'], 'courseid' => ['type' => 'integer'], 'limit' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getContentByType', [
            'description'         => 'List content-bank H5P items of a given content type',
            'input_schema'        => ['type' => 'object', 'required' => ['contenttype'],
                'properties' => ['contenttype' => ['type' => 'string', 'description' => 'e.g. contenttype_h5p'], 'limit' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('listContentTypes', [
            'description'         => 'List installed runnable H5P content-type libraries',
            'input_schema'        => ['type' => 'object', 'properties' => (object) []],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getContentParameters', [
            'description'         => 'Get the raw H5P parameters (jsoncontent) of a deployed H5P content record',
            'input_schema'        => ['type' => 'object', 'required' => ['h5pid'],
                'properties' => ['h5pid' => ['type' => 'integer', 'description' => 'mdl_h5p content id']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getContentEmbedCode', [
            'description'         => 'Get the view/embed URL of an H5P activity',
            'input_schema'        => ['type' => 'object', 'required' => ['h5pactivityid'],
                'properties' => ['h5pactivityid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getContentResults', [
            'description'         => 'Aggregate attempt results for an H5P activity',
            'input_schema'        => ['type' => 'object', 'required' => ['h5pactivityid'],
                'properties' => ['h5pactivityid' => ['type' => 'integer'], 'limit' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getUserAttempts', [
            'description'         => 'List a user attempts on an H5P activity',
            'input_schema'        => ['type' => 'object', 'required' => ['h5pactivityid', 'userid'],
                'properties' => ['h5pactivityid' => ['type' => 'integer'], 'userid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getAttemptDetails', [
            'description'         => 'Get a single H5P attempt with its per-interaction results',
            'input_schema'        => ['type' => 'object', 'required' => ['attemptid'],
                'properties' => ['attemptid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('getCourseAnalytics', [
            'description'         => 'Aggregate H5P engagement analytics across a course',
            'input_schema'        => ['type' => 'object', 'required' => ['courseid'],
                'properties' => ['courseid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
        $this->register_tool('exportContent', [
            'description'         => 'Get the downloadable .h5p package URL for an H5P activity',
            'input_schema'        => ['type' => 'object', 'required' => ['h5pactivityid'],
                'properties' => ['h5pactivityid' => ['type' => 'integer']]],
            'required_scope' => 'read', 'requires_capability' => $cap,
        ]);
    }

    private function activity_for(int $id, int $userid): \stdClass {
        global $DB;
        $activity = $DB->get_record('h5pactivity', ['id' => $id], '*', MUST_EXIST);
        $this->require_course_access((int) $activity->course, $userid);
        return $activity;
    }

    protected function execute_getContentByCourse(array $params, array $context = []): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $this->require_course_access($courseid, (int) ($context['userid'] ?? 0));
        $rows = $DB->get_records('h5pactivity', ['course' => $courseid], 'name ASC',
            'id,name,grade,enabletracking,grademethod,timemodified');
        return array_values(array_map(static fn($r) => [
            'id' => (int) $r->id, 'name' => $r->name, 'grade' => (int) $r->grade,
            'tracking' => (bool) $r->enabletracking,
        ], $rows));
    }

    protected function execute_getContent(array $params, array $context = []): array {
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        $a = $this->activity_for($id, (int) ($context['userid'] ?? 0));
        $cm = get_coursemodule_from_instance('h5pactivity', $id);
        return [
            'id' => (int) $a->id, 'name' => $a->name, 'course' => (int) $a->course,
            'cmid' => $cm ? (int) $cm->id : null, 'grade' => (int) $a->grade,
            'tracking' => (bool) $a->enabletracking, 'grademethod' => (int) $a->grademethod,
            'reviewmode' => (int) $a->reviewmode,
        ];
    }

    protected function execute_searchContent(array $params, array $context = []): array {
        global $DB;
        $search = $this->clean_required($params, 'search', PARAM_TEXT);
        $courseid = (int) $this->clean_optional($params, 'courseid', PARAM_INT, 0);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 20);
        $userid = (int) ($context['userid'] ?? 0);
        $conds = [$DB->sql_like('name', ':s', false)];
        $args = ['s' => '%' . $DB->sql_like_escape($search) . '%'];
        if ($courseid) {
            $this->require_course_access($courseid, $userid);
            $conds[] = 'course = :c';
            $args['c'] = $courseid;
        }
        $rows = $DB->get_records_select('h5pactivity', implode(' AND ', $conds), $args, 'name ASC',
            'id,name,course', 0, max(1, $limit));
        $out = [];
        foreach ($rows as $r) {
            if (!$courseid && !$this->can_access_course((int) $r->course, $userid)) {
                continue;
            }
            $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'course' => (int) $r->course];
        }
        return $out;
    }

    protected function execute_getContentByType(array $params, array $context = []): array {
        global $DB;
        $type = $this->clean_required($params, 'contenttype', PARAM_TEXT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 50);
        $rows = $DB->get_records('contentbank_content', ['contenttype' => $type], 'name ASC',
            'id,name,contenttype,contextid,timemodified', 0, max(1, $limit));
        return array_values(array_map(static fn($r) => [
            'id' => (int) $r->id, 'name' => $r->name, 'contenttype' => $r->contenttype,
        ], $rows));
    }

    protected function execute_listContentTypes(array $params, array $context = []): array {
        global $DB;
        $rows = $DB->get_records('h5p_libraries', ['runnable' => 1, 'enabled' => 1], 'title ASC',
            'id,machinename,title,majorversion,minorversion');
        return array_values(array_map(static fn($r) => [
            'machinename' => $r->machinename, 'title' => $r->title,
            'version' => $r->majorversion . '.' . $r->minorversion,
        ], $rows));
    }

    protected function execute_getContentParameters(array $params, array $context = []): array {
        global $DB;
        $this->require_admin_or_manager((int) ($context['userid'] ?? 0));
        $h5pid = $this->clean_required($params, 'h5pid', PARAM_INT);
        $r = $DB->get_record('h5p', ['id' => $h5pid], 'id,jsoncontent,mainlibraryid', MUST_EXIST);
        $lib = $DB->get_record('h5p_libraries', ['id' => $r->mainlibraryid], 'machinename,majorversion,minorversion');
        return [
            'h5pid' => (int) $r->id,
            'library' => $lib ? ($lib->machinename . ' ' . $lib->majorversion . '.' . $lib->minorversion) : null,
            'parameters' => json_decode($r->jsoncontent, true),
        ];
    }

    protected function execute_getContentEmbedCode(array $params, array $context = []): array {
        global $CFG;
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        $this->activity_for($id, (int) ($context['userid'] ?? 0));
        $cm = get_coursemodule_from_instance('h5pactivity', $id, 0, false, MUST_EXIST);
        $viewurl = $CFG->wwwroot . '/mod/h5pactivity/view.php?id=' . $cm->id;
        return [
            'h5pactivityid' => $id,
            'view_url' => $viewurl,
            'embed_code' => '<iframe src="' . $viewurl . '&embed=1" width="100%" height="600" frameborder="0" allowfullscreen></iframe>',
        ];
    }

    protected function execute_getContentResults(array $params, array $context = []): array {
        global $DB;
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 100);
        $this->activity_for($id, (int) ($context['userid'] ?? 0));
        $rows = $DB->get_records('h5pactivity_attempts', ['h5pactivityid' => $id],
            'timemodified DESC', 'id,userid,attempt,rawscore,maxscore,scaled,completion,success,duration', 0, max(1, $limit));
        $out = array_values(array_map(static fn($r) => [
            'attemptid' => (int) $r->id, 'userid' => (int) $r->userid, 'attempt' => (int) $r->attempt,
            'rawscore' => (int) $r->rawscore, 'maxscore' => (int) $r->maxscore,
            'scaled' => (float) $r->scaled, 'success' => $r->success === null ? null : (bool) $r->success,
        ], $rows));
        return ['h5pactivityid' => $id, 'attempts' => $out, 'count' => count($out)];
    }

    protected function execute_getUserAttempts(array $params, array $context = []): array {
        global $DB;
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        $targetuser = $this->clean_required($params, 'userid', PARAM_INT);
        $this->activity_for($id, (int) ($context['userid'] ?? 0));
        $rows = $DB->get_records('h5pactivity_attempts', ['h5pactivityid' => $id, 'userid' => $targetuser],
            'attempt ASC', 'id,attempt,rawscore,maxscore,scaled,completion,success,duration,timemodified');
        return array_values(array_map(static fn($r) => [
            'attemptid' => (int) $r->id, 'attempt' => (int) $r->attempt,
            'rawscore' => (int) $r->rawscore, 'maxscore' => (int) $r->maxscore, 'scaled' => (float) $r->scaled,
            'duration' => (int) $r->duration,
        ], $rows));
    }

    protected function execute_getAttemptDetails(array $params, array $context = []): array {
        global $DB;
        $attemptid = $this->clean_required($params, 'attemptid', PARAM_INT);
        $attempt = $DB->get_record('h5pactivity_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $this->activity_for((int) $attempt->h5pactivityid, (int) ($context['userid'] ?? 0));
        $results = $DB->get_records('h5pactivity_attempts_results', ['attemptid' => $attemptid], 'id ASC',
            'id,subcontent,interactiontype,description,rawscore,maxscore,success');
        return [
            'attemptid' => (int) $attempt->id,
            'userid' => (int) $attempt->userid,
            'rawscore' => (int) $attempt->rawscore,
            'maxscore' => (int) $attempt->maxscore,
            'scaled' => (float) $attempt->scaled,
            'results' => array_values(array_map(static fn($r) => [
                'interactiontype' => $r->interactiontype,
                'description' => $r->description,
                'rawscore' => (int) $r->rawscore, 'maxscore' => (int) $r->maxscore,
                'success' => $r->success === null ? null : (bool) $r->success,
            ], $results)),
        ];
    }

    protected function execute_getCourseAnalytics(array $params, array $context = []): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $this->require_course_access($courseid, (int) ($context['userid'] ?? 0));
        $sql = "SELECT COUNT(DISTINCT at.id) AS attempts,
                       COUNT(DISTINCT at.userid) AS learners,
                       COUNT(DISTINCT a.id) AS activities,
                       AVG(at.scaled) AS avgscaled
                  FROM {h5pactivity} a
             LEFT JOIN {h5pactivity_attempts} at ON at.h5pactivityid = a.id
                 WHERE a.course = :course";
        $row = $DB->get_record_sql($sql, ['course' => $courseid]);
        return [
            'courseid' => $courseid,
            'activities' => (int) ($row->activities ?? 0),
            'attempts' => (int) ($row->attempts ?? 0),
            'learners' => (int) ($row->learners ?? 0),
            'avg_scaled' => $row && $row->avgscaled !== null ? round((float) $row->avgscaled, 4) : null,
        ];
    }

    protected function execute_exportContent(array $params, array $context = []): array {
        global $DB, $CFG;
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        $this->activity_for($id, (int) ($context['userid'] ?? 0));
        $cm = get_coursemodule_from_instance('h5pactivity', $id, 0, false, MUST_EXIST);
        $ctx = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($ctx->id, 'mod_h5pactivity', 'package', 0, 'itemid, filepath, filename', false);
        $url = null;
        foreach ($files as $f) {
            $url = \moodle_url::make_pluginfile_url($ctx->id, 'mod_h5pactivity', 'package', 0,
                $f->get_filepath(), $f->get_filename())->out(false);
            break;
        }
        return [
            'h5pactivityid' => $id,
            'package_url' => $url,
            'note' => $url ? null : 'No packaged .h5p file found for this activity.',
        ];
    }

    /**
     * Throw unless the acting user may access the course.
     */
    private function require_course_access(int $courseid, int $userid): void {
        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        if (!is_enrolled($coursecontext, $userid) && !has_capability('moodle/course:view', $coursecontext, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'access this course');
        }
    }

    private function can_access_course(int $courseid, int $userid): bool {
        try {
            $this->require_course_access($courseid, $userid);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function require_admin_or_manager(int $userid): void {
        if (!has_capability('moodle/site:config', \context_system::instance(), $userid)
            && !has_capability('moodle/contentbank:access', \context_system::instance(), $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'read raw H5P parameters');
        }
    }
}
