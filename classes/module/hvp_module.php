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

use local_aiconnect\local\activity_access;

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


    /**
     * Load an H5P activity the acting user can see (require_login equivalent).
     *
     * @param int $id h5pactivity instance id.
     * @param int $userid Acting user.
     * @return array [\stdClass $activity, \cm_info $cm]
     */
    private function activity_for(int $id, int $userid): array {
        global $DB;
        $cm = activity_access::require_instance('h5pactivity', $id, $userid, 'H5P activity');
        $activity = $DB->get_record('h5pactivity', ['id' => $id], '*', IGNORE_MISSING);
        if (!$activity) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "H5P activity {$id} not found");
        }
        return [$activity, $cm];
    }

    /**
     * Native attempt-visibility rule (mod_h5pactivity manager::get_report()).
     *
     * Returns 'all' when the user holds mod/h5pactivity:reviewattempts (tracking on),
     * 'own' when they may only review their own attempts (reviewmode), else throws.
     *
     * @param \cm_info $cm Course module.
     * @param int $userid Acting user.
     * @return array [string $mode, \mod_h5pactivity\local\manager $manager]
     */
    private function attempt_access(\cm_info $cm, int $userid): array {
        $manager = \mod_h5pactivity\local\manager::create_from_coursemodule($cm);
        $user = \core_user::get_user($userid);
        if (!$user || !$manager->is_tracking_enabled()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view attempts (tracking is disabled)');
        }
        if ($manager->can_view_all_attempts($user)) {
            return ['all', $manager];
        }
        if ($manager->can_view_own_attempts($user)) {
            return ['own', $manager];
        }
        throw new \moodle_exception('nopermissions', 'error', '', 'view attempts of this activity');
    }

    /**
     * Throw unless the acting user may see the attempts of $targetid (native get_report()).
     *
     * @param \cm_info $cm Course module.
     * @param int $userid Acting user.
     * @param int $targetid User whose attempts are requested.
     */
    private function require_attempts_of(\cm_info $cm, int $userid, int $targetid): void {
        [$mode, $manager] = $this->attempt_access($cm, $userid);
        if ($mode === 'own' && $targetid !== $userid) {
            throw new \moodle_exception('nopermissions', 'error', '', "view attempts of another user");
        }
        if ($mode === 'all' && !activity_access::user_in_visible_groups($cm, $userid, $targetid)) {
            throw new \moodle_exception('nopermissions', 'error', '', "view attempts of a user outside your groups");
        }
        if (!is_enrolled($manager->get_context(), $targetid, 'mod/h5pactivity:view')) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view attempts of a user not enrolled in this activity');
        }
    }

    protected function execute_getContentByCourse(array $params, array $context = []): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);
        activity_access::require_course($courseid, $userid);
        $visible = activity_access::visible_instances('h5pactivity', $courseid, $userid);
        if (!$visible) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($visible), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            'h5pactivity',
            "id {$insql}",
            $inparams,
            'name ASC',
            'id,name,grade,enabletracking,grademethod,timemodified'
        );
        return array_values(array_map(static fn($r) => [
            'id' => (int) $r->id, 'name' => $r->name, 'grade' => (int) $r->grade,
            'tracking' => (bool) $r->enabletracking,
        ], $rows));
    }

    protected function execute_getContent(array $params, array $context = []): array {
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        [$a, $cm] = $this->activity_for($id, (int) ($context['userid'] ?? 0));
        return [
            'id' => (int) $a->id, 'name' => $a->name, 'course' => (int) $a->course,
            'cmid' => (int) $cm->id, 'grade' => (int) $a->grade,
            'tracking' => (bool) $a->enabletracking, 'grademethod' => (int) $a->grademethod,
            'reviewmode' => (int) $a->reviewmode,
        ];
    }

    protected function execute_searchContent(array $params, array $context = []): array {
        global $DB;
        $search = $this->clean_required($params, 'search', PARAM_TEXT);
        $courseid = (int) $this->clean_optional($params, 'courseid', PARAM_INT, 0);
        $limit = max(1, (int) $this->clean_optional($params, 'limit', PARAM_INT, 20));
        $userid = (int) ($context['userid'] ?? 0);
        $conds = [$DB->sql_like('name', ':s', false)];
        $args = ['s' => '%' . $DB->sql_like_escape($search) . '%'];
        if ($courseid) {
            activity_access::require_course($courseid, $userid);
            $conds[] = 'course = :c';
            $args['c'] = $courseid;
        }
        $rs = $DB->get_recordset_select('h5pactivity', implode(' AND ', $conds), $args, 'name ASC, id ASC', 'id,name,course');
        $out = [];
        $visiblebycourse = [];
        foreach ($rs as $r) {
            $cid = (int) $r->course;
            if (!isset($visiblebycourse[$cid])) {
                $visiblebycourse[$cid] = activity_access::visible_instances('h5pactivity', $cid, $userid);
            }
            if (!isset($visiblebycourse[$cid][(int) $r->id])) {
                continue;
            }
            $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'course' => $cid];
            if (count($out) >= $limit) {
                break;
            }
        }
        $rs->close();
        return $out;
    }

    protected function execute_getContentByType(array $params, array $context = []): array {
        global $DB;
        $type = $this->clean_required($params, 'contenttype', PARAM_TEXT);
        $limit = max(1, (int) $this->clean_optional($params, 'limit', PARAM_INT, 50));
        $userid = (int) ($context['userid'] ?? 0);
        $rs = $DB->get_recordset(
            'contentbank_content',
            ['contenttype' => $type],
            'name ASC, id ASC',
            'id,name,contenttype,contextid,usercreated,visibility,timemodified'
        );
        $out = [];
        $ctxok = [];
        foreach ($rs as $r) {
            $cid = (int) $r->contextid;
            if (!isset($ctxok[$cid])) {
                $ctxok[$cid] = $this->can_browse_contentbank_context($cid, $userid);
            }
            if (!$ctxok[$cid]) {
                continue;
            }
            // Native content::is_view_allowed(): own, public, or viewunlistedcontent.
            $public = (int) ($r->visibility ?? 1) === 1;
            if (
                !$public && (int) $r->usercreated !== $userid
                && !has_capability('moodle/contentbank:viewunlistedcontent', \context::instance_by_id($cid), $userid)
            ) {
                continue;
            }
            $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'contenttype' => $r->contenttype];
            if (count($out) >= $limit) {
                break;
            }
        }
        $rs->close();
        return $out;
    }

    /**
     * Native contentbank/view.php rule: moodle/contentbank:access in the content's
     * context, plus course access when that context is a course.
     *
     * @param int $contextid Content-bank context id.
     * @param int $userid Acting user.
     * @return bool
     */
    private function can_browse_contentbank_context(int $contextid, int $userid): bool {
        $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$ctx || !has_capability('moodle/contentbank:access', $ctx, $userid)) {
            return false;
        }
        if ($ctx->contextlevel == CONTEXT_COURSE) {
            return activity_access::can_enter_course((int) $ctx->instanceid, $userid);
        }
        if ($ctx->contextlevel == CONTEXT_COURSECAT) {
            return \core_course_category::get((int) $ctx->instanceid, IGNORE_MISSING, false, $userid) !== null;
        }
        return $ctx->contextlevel == CONTEXT_SYSTEM;
    }

    protected function execute_listContentTypes(array $params, array $context = []): array {
        global $DB;
        $rows = $DB->get_records(
            'h5p_libraries',
            ['runnable' => 1, 'enabled' => 1],
            'title ASC',
            'id,machinename,title,majorversion,minorversion'
        );
        return array_values(array_map(static fn($r) => [
            'machinename' => $r->machinename, 'title' => $r->title,
            'version' => $r->majorversion . '.' . $r->minorversion,
        ], $rows));
    }

    protected function execute_getContentParameters(array $params, array $context = []): array {
        global $DB;
        $userid = (int) ($context['userid'] ?? 0);
        $h5pid = $this->clean_required($params, 'h5pid', PARAM_INT);
        $r = $DB->get_record('h5p', ['id' => $h5pid], 'id,jsoncontent,mainlibraryid,pathnamehash', IGNORE_MISSING);
        if (!$r) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "H5P content {$h5pid} not found");
        }
        // Raw parameters include the correct answers: only editors of the owning context may read them.
        $this->require_h5p_editor((string) $r->pathnamehash, $userid, $h5pid);
        $lib = $DB->get_record('h5p_libraries', ['id' => $r->mainlibraryid], 'machinename,majorversion,minorversion');
        return [
            'h5pid' => (int) $r->id,
            'library' => $lib ? ($lib->machinename . ' ' . $lib->majorversion . '.' . $lib->minorversion) : null,
            'parameters' => json_decode($r->jsoncontent, true),
        ];
    }

    /**
     * Throw unless the user may edit the context that owns a deployed H5P file.
     *
     * @param string $pathnamehash mdl_h5p.pathnamehash.
     * @param int $userid Acting user.
     * @param int $h5pid Id echoed in errors.
     */
    private function require_h5p_editor(string $pathnamehash, int $userid, int $h5pid): void {
        if (has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            return;
        }
        $file = $pathnamehash !== '' ? get_file_storage()->get_file_by_hash($pathnamehash) : false;
        $ctx = $file ? \context::instance_by_id($file->get_contextid(), IGNORE_MISSING) : null;
        if (!$ctx) {
            throw new \moodle_exception('nopermissions', 'error', '', "read raw parameters of H5P content {$h5pid}");
        }
        if ($ctx->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', (int) $ctx->instanceid, 0, false, IGNORE_MISSING);
            $ok = $cm && activity_access::can_enter_course((int) $cm->course, $userid)
                && get_fast_modinfo((int) $cm->course, $userid)->get_cm((int) $cm->id)->uservisible
                && has_capability('moodle/course:manageactivities', $ctx, $userid);
        } else {
            $ok = has_capability('moodle/contentbank:access', $ctx, $userid)
                && has_capability('moodle/contentbank:manageanycontent', $ctx, $userid);
        }
        if (!$ok) {
            throw new \moodle_exception('nopermissions', 'error', '', "read raw parameters of H5P content {$h5pid}");
        }
    }

    protected function execute_getContentEmbedCode(array $params, array $context = []): array {
        global $CFG;
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        [, $cm] = $this->activity_for($id, (int) ($context['userid'] ?? 0));
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
        $limit = max(1, (int) $this->clean_optional($params, 'limit', PARAM_INT, 100));
        $userid = (int) ($context['userid'] ?? 0);
        [, $cm] = $this->activity_for($id, $userid);
        [$mode] = $this->attempt_access($cm, $userid);
        $where = 'h5pactivityid = :a';
        $args = ['a' => $id];
        if ($mode === 'own') {
            $where .= ' AND userid = :u';
            $args['u'] = $userid;
        } else {
            $allowed = activity_access::group_visible_userids($cm, $userid);
            if ($allowed !== null) {
                [$insql, $inparams] = $DB->get_in_or_equal($allowed, SQL_PARAMS_NAMED, 'gu');
                $where .= " AND userid {$insql}";
                $args += $inparams;
            }
        }
        $rows = $DB->get_records_select(
            'h5pactivity_attempts',
            $where,
            $args,
            'timemodified DESC',
            'id,userid,attempt,rawscore,maxscore,scaled,completion,success,duration',
            0,
            $limit
        );
        $out = array_values(array_map(static fn($r) => [
            'attemptid' => (int) $r->id, 'userid' => (int) $r->userid, 'attempt' => (int) $r->attempt,
            'rawscore' => (int) $r->rawscore, 'maxscore' => (int) $r->maxscore,
            'scaled' => (float) $r->scaled, 'success' => $r->success === null ? null : (bool) $r->success,
        ], $rows));
        return ['h5pactivityid' => $id, 'scope' => $mode, 'attempts' => $out, 'count' => count($out)];
    }

    protected function execute_getUserAttempts(array $params, array $context = []): array {
        global $DB;
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        $targetuser = (int) $this->clean_required($params, 'userid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);
        [, $cm] = $this->activity_for($id, $userid);
        $this->require_attempts_of($cm, $userid, $targetuser);
        $rows = $DB->get_records(
            'h5pactivity_attempts',
            ['h5pactivityid' => $id, 'userid' => $targetuser],
            'attempt ASC',
            'id,attempt,rawscore,maxscore,scaled,completion,success,duration,timemodified'
        );
        return array_values(array_map(static fn($r) => [
            'attemptid' => (int) $r->id, 'attempt' => (int) $r->attempt,
            'rawscore' => (int) $r->rawscore, 'maxscore' => (int) $r->maxscore, 'scaled' => (float) $r->scaled,
            'duration' => (int) $r->duration,
        ], $rows));
    }

    protected function execute_getAttemptDetails(array $params, array $context = []): array {
        global $DB;
        $attemptid = $this->clean_required($params, 'attemptid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);
        $notfound = "H5P attempt {$attemptid} not found";
        $attempt = $DB->get_record('h5pactivity_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
        if (!$attempt) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $notfound);
        }
        try {
            [, $cm] = $this->activity_for((int) $attempt->h5pactivityid, $userid);
            $this->require_attempts_of($cm, $userid, (int) $attempt->userid);
        } catch (\moodle_exception $e) {
            // Same answer for "missing" and "not yours" so attempt ids cannot be probed.
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $notfound);
        }
        $results = $DB->get_records(
            'h5pactivity_attempts_results',
            ['attemptid' => $attemptid],
            'id ASC',
            'id,subcontent,interactiontype,description,rawscore,maxscore,success'
        );
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
        $userid = (int) ($context['userid'] ?? 0);
        activity_access::require_course($courseid, $userid);
        $visible = activity_access::visible_instances('h5pactivity', $courseid, $userid);
        $attempts = 0;
        $learners = [];
        $scaledsum = 0.0;
        $scaledn = 0;
        foreach ($visible as $instanceid => $cm) {
            try {
                [$mode] = $this->attempt_access($cm, $userid);
            } catch (\moodle_exception $e) {
                continue;
            }
            $where = 'h5pactivityid = :a';
            $args = ['a' => $instanceid];
            $allowed = $mode === 'own' ? [$userid] : activity_access::group_visible_userids($cm, $userid);
            if ($allowed !== null) {
                [$insql, $inparams] = $DB->get_in_or_equal($allowed, SQL_PARAMS_NAMED, 'gu');
                $where .= " AND userid {$insql}";
                $args += $inparams;
            }
            foreach ($DB->get_records_select('h5pactivity_attempts', $where, $args, '', 'id,userid,scaled') as $r) {
                $attempts++;
                $learners[(int) $r->userid] = true;
                if ($r->scaled !== null) {
                    $scaledsum += (float) $r->scaled;
                    $scaledn++;
                }
            }
        }
        return [
            'courseid' => $courseid,
            'activities' => count($visible),
            'attempts' => $attempts,
            'learners' => count($learners),
            'avg_scaled' => $scaledn ? round($scaledsum / $scaledn, 4) : null,
        ];
    }

    protected function execute_exportContent(array $params, array $context = []): array {
        $id = $this->clean_required($params, 'h5pactivityid', PARAM_INT);
        [, $cm] = $this->activity_for($id, (int) ($context['userid'] ?? 0));
        $ctx = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($ctx->id, 'mod_h5pactivity', 'package', 0, 'itemid, filepath, filename', false);
        $url = null;
        foreach ($files as $f) {
            $url = \moodle_url::make_pluginfile_url(
                $ctx->id,
                'mod_h5pactivity',
                'package',
                0,
                $f->get_filepath(),
                $f->get_filename()
            )->out(false);
            break;
        }
        return [
            'h5pactivityid' => $id,
            'package_url' => $url,
            'note' => $url ? null : 'No packaged .h5p file found for this activity.',
        ];
    }
}
