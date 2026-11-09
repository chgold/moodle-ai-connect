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
 * BigBlueButton read module (free). Capability-gated on mod_bigbluebuttonbn.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

class bigbluebutton_module extends module_base {
    /** @var string Tool namespace prefix. */
    protected string $module_name = 'bigbluebutton';

    protected function register_tools(): void {
        $cap = 'mod_bigbluebuttonbn';

        $this->register_tool('listCourseMeetings', [
            'description'         => 'List the BigBlueButton meeting activities in a course',
            'input_schema'        => ['type' => 'object', 'required' => ['courseid'],
                'properties' => ['courseid' => ['type' => 'integer', 'description' => 'Course ID']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('searchMeetings', [
            'description'         => 'Search BigBlueButton meetings by name (optionally within a course)',
            'input_schema'        => ['type' => 'object', 'required' => ['search'],
                'properties' => [
                    'search'   => ['type' => 'string', 'description' => 'Name search term'],
                    'courseid' => ['type' => 'integer', 'description' => 'Optional course filter'],
                    'limit'    => ['type' => 'integer', 'description' => 'Max results (default 20)'],
                ]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getMeetingDetails', [
            'description'         => 'Get the configuration of a BigBlueButton meeting activity',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer', 'description' => 'bigbluebuttonbn instance ID']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getMeetingStatus', [
            'description'         => 'Get the live running status of a BigBlueButton meeting (requires a configured BBB server)',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer'], 'groupid' => ['type' => 'integer']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getMeetingParticipants', [
            'description'         => 'List current participants of a live BigBlueButton meeting',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer'], 'groupid' => ['type' => 'integer']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getJoinUrl', [
            'description'         => 'Get the join URL for a BigBlueButton meeting for the current user',
            'input_schema'        => ['type' => 'object', 'required' => ['cmid'],
                'properties' => ['cmid' => ['type' => 'integer', 'description' => 'Course module ID'], 'groupid' => ['type' => 'integer']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getMeetingAttendanceReport', [
            'description'         => 'Attendance / activity log for a BigBlueButton meeting',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer'], 'limit' => ['type' => 'integer']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('listRecordings', [
            'description'         => 'List recordings of a BigBlueButton meeting',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getRecordingDetails', [
            'description'         => 'Get metadata for a single BigBlueButton recording',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid', 'recordingid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer'], 'recordingid' => ['type' => 'string']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);

        $this->register_tool('getRecordingPlaybackUrl', [
            'description'         => 'Get the playback URL(s) for a BigBlueButton recording',
            'input_schema'        => ['type' => 'object', 'required' => ['bigbluebuttonbnid', 'recordingid'],
                'properties' => ['bigbluebuttonbnid' => ['type' => 'integer'], 'recordingid' => ['type' => 'string']]],
            'required_scope'      => 'read',
            'requires_capability' => $cap,
        ]);
    }

    /**
     * Load a bigbluebutton instance + verify the caller may access its course.
     *
     * @param int $bbbid bigbluebuttonbn instance id.
     * @param int $userid Acting user.
     * @return \mod_bigbluebuttonbn\instance
     */
    private function instance_for(int $bbbid, int $userid): \mod_bigbluebuttonbn\instance {
        $instance = \mod_bigbluebuttonbn\instance::get_from_instanceid($bbbid);
        if (!$instance) {
            throw new \moodle_exception('invalidinstance', 'mod_bigbluebuttonbn');
        }
        $this->require_course_access((int) $instance->get_course_id(), $userid);
        return $instance;
    }

    protected function execute_listCourseMeetings(array $params, array $context = []): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $this->require_course_access($courseid, (int) ($context['userid'] ?? 0));
        $rows = $DB->get_records(
            'bigbluebuttonbn',
            ['course' => $courseid],
            'name ASC',
            'id,name,type,meetingid,openingtime,closingtime,timemodified'
        );
        return array_values(array_map(static fn($r) => [
            'id'          => (int) $r->id,
            'name'        => $r->name,
            'type'        => (int) $r->type,
            'openingtime' => (int) $r->openingtime,
            'closingtime' => (int) $r->closingtime,
        ], $rows));
    }

    protected function execute_searchMeetings(array $params, array $context = []): array {
        global $DB;
        $search = $this->clean_required($params, 'search', PARAM_TEXT);
        $courseid = (int) $this->clean_optional($params, 'courseid', PARAM_INT, 0);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 20);
        $userid = (int) ($context['userid'] ?? 0);
        $like = $DB->sql_like('name', ':search', false);
        $conds = [$like];
        $args = ['search' => '%' . $DB->sql_like_escape($search) . '%'];
        if ($courseid) {
            $this->require_course_access($courseid, $userid);
            $conds[] = 'course = :course';
            $args['course'] = $courseid;
        }
        $rows = $DB->get_records_select(
            'bigbluebuttonbn',
            implode(' AND ', $conds),
            $args,
            'name ASC',
            'id,name,course,type,openingtime,closingtime',
            0,
            max(1, $limit)
        );
        $out = [];
        foreach ($rows as $r) {
            if (!$courseid && !$this->can_access_course((int) $r->course, $userid)) {
                continue;
            }
            $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'course' => (int) $r->course];
        }
        return $out;
    }

    protected function execute_getMeetingDetails(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $instance = $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $r = $DB->get_record('bigbluebuttonbn', ['id' => $bbbid], '*', MUST_EXIST);
        return [
            'id'          => (int) $r->id,
            'name'        => $r->name,
            'course'      => (int) $r->course,
            'cmid'        => (int) $instance->get_cm_id(),
            'type'        => (int) $r->type,
            'record'      => (bool) $r->record,
            'wait'        => (bool) $r->wait,
            'openingtime' => (int) $r->openingtime,
            'closingtime' => (int) $r->closingtime,
            'welcome'     => $r->welcome,
        ];
    }

    protected function execute_getMeetingStatus(array $params, array $context = []): array {
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $groupid = (int) $this->clean_optional($params, 'groupid', PARAM_INT, 0);
        $instance = $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $instance->set_group_id($groupid);
        try {
            $meeting = new \mod_bigbluebuttonbn\meeting($instance);
            return [
                'bigbluebuttonbnid' => $bbbid,
                'running'           => (bool) $meeting->is_running(),
                'server_available'  => true,
            ];
        } catch (\Throwable $e) {
            return ['bigbluebuttonbnid' => $bbbid, 'running' => false, 'server_available' => false,
                'note' => 'Live status needs a configured BigBlueButton server: ' . $e->getMessage()];
        }
    }

    protected function execute_getMeetingParticipants(array $params, array $context = []): array {
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $groupid = (int) $this->clean_optional($params, 'groupid', PARAM_INT, 0);
        $instance = $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $instance->set_group_id($groupid);
        try {
            $meeting = new \mod_bigbluebuttonbn\meeting($instance);
            $info = $meeting->get_meeting_info();
            return [
                'bigbluebuttonbnid' => $bbbid,
                'participant_count' => (int) ($info->participantcount ?? 0),
                'moderator_count'   => (int) ($info->moderatorcount ?? 0),
                'server_available'  => true,
            ];
        } catch (\Throwable $e) {
            return ['bigbluebuttonbnid' => $bbbid, 'participant_count' => 0, 'server_available' => false,
                'note' => 'Live participants need a configured BigBlueButton server'];
        }
    }

    protected function execute_getJoinUrl(array $params, array $context = []): array {
        $cmid = $this->clean_required($params, 'cmid', PARAM_INT);
        $groupid = (int) $this->clean_optional($params, 'groupid', PARAM_INT, 0);
        $userid = (int) ($context['userid'] ?? 0);
        $instance = \mod_bigbluebuttonbn\instance::get_from_cmid($cmid);
        if (!$instance) {
            throw new \moodle_exception('invalidinstance', 'mod_bigbluebuttonbn');
        }
        $this->require_course_access((int) $instance->get_course_id(), $userid);
        $instance->set_group_id($groupid);
        try {
            $url = \mod_bigbluebuttonbn\local\proxy\bigbluebutton_proxy::get_join_url($instance);
            return ['cmid' => $cmid, 'join_url' => (string) $url, 'server_available' => true];
        } catch (\Throwable $e) {
            return ['cmid' => $cmid, 'join_url' => null, 'server_available' => false,
                'note' => 'Join URL needs a configured BigBlueButton server'];
        }
    }

    protected function execute_getMeetingAttendanceReport(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $limit = (int) $this->clean_optional($params, 'limit', PARAM_INT, 100);
        $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $rows = $DB->get_records(
            'bigbluebuttonbn_logs',
            ['bigbluebuttonbnid' => $bbbid],
            'timecreated DESC',
            'id,userid,log,meetingid,timecreated',
            0,
            max(1, $limit)
        );
        return array_values(array_map(static fn($r) => [
            'id'          => (int) $r->id,
            'userid'      => (int) $r->userid,
            'log'         => $r->log,
            'timecreated' => (int) $r->timecreated,
        ], $rows));
    }

    protected function execute_listRecordings(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $rows = $DB->get_records(
            'bigbluebuttonbn_recordings',
            ['bigbluebuttonbnid' => $bbbid],
            'timecreated DESC',
            'id,recordingid,status,imported,headless,timecreated'
        );
        return array_values(array_map(static fn($r) => [
            'recordingid' => (string) $r->recordingid,
            'status'      => (int) $r->status,
            'imported'    => (bool) $r->imported,
            'timecreated' => (int) $r->timecreated,
        ], $rows));
    }

    protected function execute_getRecordingDetails(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $recordingid = $this->clean_required($params, 'recordingid', PARAM_TEXT);
        $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $r = $DB->get_record('bigbluebuttonbn_recordings', ['bigbluebuttonbnid' => $bbbid, 'recordingid' => $recordingid]);
        if (!$r) {
            throw new \moodle_exception('recordingnotfound', 'mod_bigbluebuttonbn');
        }
        return [
            'recordingid' => (string) $r->recordingid,
            'status'      => (int) $r->status,
            'imported'    => (bool) $r->imported,
            'headless'    => (bool) $r->headless,
            'timecreated' => (int) $r->timecreated,
            'note'        => 'Recording title/playback metadata is served live from the BigBlueButton server; use getRecordingPlaybackUrl.',
        ];
    }

    protected function execute_getRecordingPlaybackUrl(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $recordingid = $this->clean_required($params, 'recordingid', PARAM_TEXT);
        $this->instance_for($bbbid, (int) ($context['userid'] ?? 0));
        $r = $DB->get_record('bigbluebuttonbn_recordings', ['bigbluebuttonbnid' => $bbbid, 'recordingid' => $recordingid]);
        if (!$r) {
            throw new \moodle_exception('recordingnotfound', 'mod_bigbluebuttonbn');
        }
        $imported = ! empty($r->imported) && ! empty($r->importeddata);
        $playbacks = [];
        if ($imported) {
            $data = @json_decode($r->importeddata, true);
            foreach ((array) ($data['playbacks'] ?? []) as $pb) {
                $playbacks[] = ['type' => $pb['type'] ?? '', 'url' => $pb['url'] ?? ''];
            }
        }
        return [
            'recordingid' => (string) $r->recordingid,
            'playbacks'   => $playbacks,
            'server_available' => (bool) $imported ? true : null,
            'note' => $imported ? null : 'Live playback URLs require a configured BigBlueButton server.',
        ];
    }

    /**
     * Throw unless the acting user may access the course.
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
     * Boolean course-access check (no throw).
     *
     * @param int $courseid Course ID.
     * @param int $userid   Acting user ID.
     * @return bool
     */
    private function can_access_course(int $courseid, int $userid): bool {
        try {
            $this->require_course_access($courseid, $userid);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
