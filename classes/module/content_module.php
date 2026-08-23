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
 * Free-tier (read scope) course content and forum tools.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Course content tools: modules, files, forum discussions and posts.
 *
 * Every tool re-checks course access for the calling user before returning
 * anything, so an agent can never read material from a course its account is
 * not enrolled in.
 */
class content_module extends module_base {

    /** @var string Tool namespace prefix. */
    protected string $module_name = 'moodle';

    /** @var string[] File extensions that may be returned as text. */
    private const TEXT_EXTENSIONS = [
        'txt', 'csv', 'tsv', 'json', 'xml', 'md', 'markdown',
        'log', 'ini', 'yml', 'yaml', 'html', 'htm', 'css', 'sql',
    ];

    /** @var int Default byte cap for downloadCourseFile. */
    private const DEFAULT_MAX_BYTES = 100000;

    /** @var int Hard byte cap for downloadCourseFile. */
    private const MAX_BYTES_LIMIT = 1000000;

    /**
     * Register the seven free read-scope content tools.
     */
    protected function register_tools(): void {
        $this->register_tool('getCourseModules', [
            'description'    => 'List the activities and resources inside a course',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid'],
                'properties' => [
                    'courseid' => ['type' => 'integer', 'description' => 'Course ID'],
                ]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getModuleContent', [
            'description'    => 'Get the content of a single course module (page, label, resource)',
            'input_schema'   => ['type' => 'object', 'required' => ['cmid'],
                'properties' => [
                    'cmid' => ['type' => 'integer', 'description' => 'Course module ID'],
                ]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getCourseFiles', [
            'description'    => 'List the files attached to a course, with names, sizes and URLs',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid'],
                'properties' => [
                    'courseid' => ['type' => 'integer', 'description' => 'Course ID'],
                    'limit'    => ['type' => 'integer', 'description' => 'Maximum files (default 50)'],
                ]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('downloadCourseFile', [
            'description'    => 'Read the text content of a course file (txt, csv, json, xml, md, log). '
                . 'Binary files such as images, archives and PDFs are refused — use getCourseFiles for their URLs.',
            'input_schema'   => ['type' => 'object', 'required' => ['fileid'],
                'properties' => [
                    'fileid'    => ['type' => 'integer', 'description' => 'File ID from getCourseFiles'],
                    'max_bytes' => ['type' => 'integer', 'description' => 'Max bytes (default 100000, cap 1000000)'],
                ]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getForumDiscussions', [
            'description'    => 'List discussion threads in a course forum',
            'input_schema'   => ['type' => 'object', 'required' => ['forumid'],
                'properties' => [
                    'forumid' => ['type' => 'integer', 'description' => 'Forum instance ID'],
                    'limit'   => ['type' => 'integer', 'description' => 'Maximum discussions (default 20)'],
                ]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('getForumPosts', [
            'description'    => 'Get the posts inside a forum discussion',
            'input_schema'   => ['type' => 'object', 'required' => ['discussionid'],
                'properties' => [
                    'discussionid' => ['type' => 'integer', 'description' => 'Discussion ID'],
                    'limit'        => ['type' => 'integer', 'description' => 'Maximum posts (default 50)'],
                ]],
            'required_scope' => 'read',
        ]);

        $this->register_tool('searchForumPosts', [
            'description'    => 'Search forum posts across a course by keyword',
            'input_schema'   => ['type' => 'object', 'required' => ['courseid', 'search'],
                'properties' => [
                    'courseid' => ['type' => 'integer', 'description' => 'Course ID to search within'],
                    'search'   => ['type' => 'string', 'description' => 'Search term'],
                    'limit'    => ['type' => 'integer', 'description' => 'Maximum posts (default 20)'],
                ]],
            'required_scope' => 'read',
        ]);
    }

    /**
     * List the modules of a course.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array Module summaries.
     */
    protected function execute_getCourseModules(array $params, array $context = []): array {
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);
        $this->require_course_access($courseid, $userid);

        $modinfo = get_fast_modinfo($courseid, $userid);

        $rows = [];
        foreach ($modinfo->get_cms() as $cm) {
            // uservisible honours availability restrictions and group access,
            // so hidden or restricted activities never leak.
            if (!$cm->uservisible) {
                continue;
            }
            $rows[] = [
                'cmid'       => (int) $cm->id,
                'name'       => $cm->name,
                'modname'    => $cm->modname,
                'instance'   => (int) $cm->instance,
                'section'    => (int) $cm->sectionnum,
                'visible'    => (bool) $cm->visible,
                'url'        => $cm->url ? $cm->url->out(false) : null,
            ];
        }

        return $rows;
    }

    /**
     * Get the content of a single module.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array Module content.
     */
    protected function execute_getModuleContent(array $params, array $context = []): array {
        global $DB;

        $cmid = $this->clean_required($params, 'cmid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $this->require_course_access((int) $cm->course, $userid);

        $modinfo = get_fast_modinfo((int) $cm->course, $userid);
        $cminfo = $modinfo->get_cm($cmid);
        if (!$cminfo->uservisible) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view this activity');
        }

        // The text of an activity lives in its own module table, and the column
        // differs per module type, so read defensively rather than assuming.
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);

        $content = '';
        foreach (['content', 'intro'] as $field) {
            if (!empty($record->{$field})) {
                $content = strip_tags($record->{$field});
                break;
            }
        }

        return [
            'cmid'    => (int) $cm->id,
            'name'    => $cminfo->name,
            'modname' => $cm->modname,
            'course'  => (int) $cm->course,
            'content' => $content,
            'url'     => $cminfo->url ? $cminfo->url->out(false) : null,
        ];
    }

    /**
     * List files attached to a course.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array File summaries.
     */
    protected function execute_getCourseFiles(array $params, array $context = []): array {
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 50);
        $userid = (int) ($context['userid'] ?? 0);
        $this->require_course_access($courseid, $userid);

        $modinfo = get_fast_modinfo($courseid, $userid);
        $fs = get_file_storage();

        $rows = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }

            $component = 'mod_' . $cm->modname;
            $files = $fs->get_area_files($cm->context->id, $component, 'content', false, 'filename', false);
            if (!$files) {
                $files = $fs->get_area_files($cm->context->id, $component, 'intro', false, 'filename', false);
            }

            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $rows[] = $this->format_file($file, (int) $cm->id, $cm->name);
                if (count($rows) >= max(1, $limit)) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Read a text file belonging to a course.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array File content.
     */
    protected function execute_downloadCourseFile(array $params, array $context = []): array {
        $fileid = $this->clean_required($params, 'fileid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);

        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file || $file->is_directory()) {
            throw new \moodle_exception('filenotfound', 'error');
        }

        // Resolve the course from the file's context and re-check access, so a
        // raw file id cannot be used to read material from another course.
        $filecontext = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
        if (!$filecontext) {
            throw new \moodle_exception('filenotfound', 'error');
        }
        $coursecontext = $filecontext->get_course_context(false);
        if (!$coursecontext) {
            throw new \moodle_exception('nopermissions', 'error', '', 'read this file');
        }
        $this->require_course_access((int) $coursecontext->instanceid, $userid);

        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new \moodle_exception(
                'invalidargument',
                'error',
                '',
                null,
                'File is not a text file (.' . $extension . '). Use getCourseFiles for its URL.'
            );
        }

        $maxbytes = (int) $this->clean_optional($params, 'max_bytes', PARAM_INT, self::DEFAULT_MAX_BYTES);
        $maxbytes = max(1, min($maxbytes, self::MAX_BYTES_LIMIT));

        $content = (string) $file->get_content();
        $truncated = strlen($content) > $maxbytes;
        if ($truncated) {
            $content = substr($content, 0, $maxbytes);
        }

        // Guarantee valid UTF-8 so a file in another encoding cannot break the
        // JSON response.
        if (!preg_match('//u', $content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        return [
            'fileid'         => (int) $file->get_id(),
            'filename'       => $file->get_filename(),
            'extension'      => $extension,
            'mimetype'       => $file->get_mimetype(),
            'filesize'       => (int) $file->get_filesize(),
            'returned_bytes' => strlen($content),
            'truncated'      => $truncated,
            'content'        => $content,
        ];
    }

    /**
     * List discussions in a forum.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array Discussion summaries.
     */
    protected function execute_getForumDiscussions(array $params, array $context = []): array {
        global $DB;

        $forumid = $this->clean_required($params, 'forumid', PARAM_INT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 20);
        $userid = (int) ($context['userid'] ?? 0);

        $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
        $this->require_course_access((int) $forum->course, $userid);

        $discussions = $DB->get_records('forum_discussions', ['forum' => $forumid],
            'timemodified DESC', 'id,name,userid,timemodified,timestart,pinned', 0, max(1, $limit));

        return array_values(array_map(static fn($d) => [
            'id'           => (int) $d->id,
            'name'         => $d->name,
            'userid'       => (int) $d->userid,
            'timemodified' => (int) $d->timemodified,
            'pinned'       => (bool) $d->pinned,
        ], $discussions));
    }

    /**
     * List posts inside a discussion.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array Post summaries.
     */
    protected function execute_getForumPosts(array $params, array $context = []): array {
        global $DB;

        $discussionid = $this->clean_required($params, 'discussionid', PARAM_INT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 50);
        $userid = (int) ($context['userid'] ?? 0);

        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
        $this->require_course_access((int) $discussion->course, $userid);

        $posts = $DB->get_records('forum_posts', ['discussion' => $discussionid],
            'created ASC', 'id,parent,userid,created,modified,subject,message', 0, max(1, $limit));

        return array_values(array_map(static fn($p) => [
            'id'       => (int) $p->id,
            'parent'   => (int) $p->parent,
            'userid'   => (int) $p->userid,
            'subject'  => $p->subject,
            'message'  => strip_tags($p->message),
            'created'  => (int) $p->created,
            'modified' => (int) $p->modified,
        ], $posts));
    }

    /**
     * Search forum posts within a course.
     *
     * @param array $params Request body.
     * @param array $context Must contain `userid`.
     * @return array Matching posts.
     */
    protected function execute_searchForumPosts(array $params, array $context = []): array {
        global $DB;

        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $search = $this->clean_required($params, 'search', PARAM_TEXT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 20);
        $userid = (int) ($context['userid'] ?? 0);
        $this->require_course_access($courseid, $userid);

        // Scoped to the course by joining through discussions, so the search
        // can never reach posts in a course the caller cannot access.
        $like = $DB->sql_like('p.message', ':search', false);
        $sql = "SELECT p.id, p.discussion, p.userid, p.subject, p.message, p.created
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE d.course = :courseid AND {$like}
              ORDER BY p.created DESC";

        $posts = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'search'   => '%' . $DB->sql_like_escape($search) . '%',
        ], 0, max(1, $limit));

        return array_values(array_map(static fn($p) => [
            'id'           => (int) $p->id,
            'discussionid' => (int) $p->discussion,
            'userid'       => (int) $p->userid,
            'subject'      => $p->subject,
            'message'      => strip_tags($p->message),
            'created'      => (int) $p->created,
        ], $posts));
    }

    /**
     * Throw unless the user may access the course.
     *
     * @param int $courseid Course ID.
     * @param int $userid   Acting user ID.
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

    /**
     * Shape a stored file for output.
     *
     * @param \stored_file $file The file.
     * @param int          $cmid Owning course module ID.
     * @param string       $cmname Owning course module name.
     * @return array File summary.
     */
    private function format_file(\stored_file $file, int $cmid, string $cmname): array {
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );

        return [
            'fileid'            => (int) $file->get_id(),
            'filename'          => $file->get_filename(),
            'extension'         => $extension,
            'mimetype'          => $file->get_mimetype(),
            'filesize'          => (int) $file->get_filesize(),
            'readable_as_text'  => in_array($extension, self::TEXT_EXTENSIONS, true),
            'cmid'              => $cmid,
            'activity'          => $cmname,
            'url'               => $url->out(false),
        ];
    }
}
