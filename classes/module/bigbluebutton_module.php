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

use local_aiconnect\local\activity_access;

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
     * Load a bigbluebutton instance the acting user can see (require_login equivalent:
     * course access + cm uservisible + mod/bigbluebuttonbn:view).
     *
     * @param int $bbbid bigbluebuttonbn instance id.
     * @param int $userid Acting user.
     * @return \mod_bigbluebuttonbn\instance
     */
    private function instance_for(int $bbbid, int $userid): \mod_bigbluebuttonbn\instance {
        activity_access::require_instance('bigbluebuttonbn', $bbbid, $userid, 'BigBlueButton activity');
        $instance = \mod_bigbluebuttonbn\instance::get_from_instanceid($bbbid);
        if (!$instance) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "BigBlueButton activity {$bbbid} not found");
        }
        return $instance;
    }

    /**
     * Validate a requested group the way mod_bigbluebuttonbn external get_join_url does.
     *
     * groupid=0 in a separate-groups activity without accessallgroups falls back to the
     * user's first allowed group (what groups_get_activity_group() picks on bbb_view.php).
     *
     * @param \mod_bigbluebuttonbn\instance $instance Instance.
     * @param int $groupid Requested group (0 = default).
     * @param int $userid Acting user.
     * @return int Group id to use.
     */
    private function resolve_group(\mod_bigbluebuttonbn\instance $instance, int $groupid, int $userid): int {
        $cm = $instance->get_cm();
        if (groups_get_activity_groupmode($cm) == NOGROUPS) {
            return 0;
        }
        if (
            $groupid === 0 && groups_get_activity_groupmode($cm) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $instance->get_context(), $userid)
        ) {
            $allowed = groups_get_activity_allowed_groups($cm, $userid);
            if (!$allowed) {
                throw new \moodle_exception('nopermissions', 'error', '', 'join: you are not a member of any group of this activity');
            }
            $groupid = (int) reset($allowed)->id;
        }
        if (!groups_group_visible($groupid, $instance->get_course(), $cm, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', "access group {$groupid} of this activity");
        }
        return $groupid;
    }

    protected function execute_listCourseMeetings(array $params, array $context = []): array {
        global $DB;
        $courseid = $this->clean_required($params, 'courseid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);
        activity_access::require_course($courseid, $userid);
        $visible = activity_access::visible_instances('bigbluebuttonbn', $courseid, $userid);
        if (!$visible) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($visible), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            'bigbluebuttonbn',
            "id {$insql}",
            $inparams,
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
        $limit = max(1, (int) $this->clean_optional($params, 'limit', PARAM_INT, 20));
        $userid = (int) ($context['userid'] ?? 0);
        $conds = [$DB->sql_like('name', ':search', false)];
        $args = ['search' => '%' . $DB->sql_like_escape($search) . '%'];
        if ($courseid) {
            activity_access::require_course($courseid, $userid);
            $conds[] = 'course = :course';
            $args['course'] = $courseid;
        }
        $rs = $DB->get_recordset_select('bigbluebuttonbn', implode(' AND ', $conds), $args, 'name ASC, id ASC', 'id,name,course');
        $out = [];
        $visiblebycourse = [];
        foreach ($rs as $r) {
            $cid = (int) $r->course;
            if (!isset($visiblebycourse[$cid])) {
                $visiblebycourse[$cid] = activity_access::visible_instances('bigbluebuttonbn', $cid, $userid);
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
        $userid = (int) ($context['userid'] ?? 0);
        $instance = $this->instance_for($bbbid, $userid);
        $instance->set_group_id($this->resolve_group($instance, $groupid, $userid));
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
        $userid = (int) ($context['userid'] ?? 0);
        $instance = $this->instance_for($bbbid, $userid);
        $instance->set_group_id($this->resolve_group($instance, $groupid, $userid));
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

    /**
     * Join URL, gated exactly like mod_bigbluebuttonbn's external get_join_url +
     * meeting::get_meeting_info(): visible activity, group visible, instance::can_join()
     * (mod/bigbluebuttonbn:join + group membership, never for recording-only) and
     * instance::is_currently_open() (openingtime / closingtime).
     *
     * The URL returned is Moodle's own bbb_view.php?action=join entry point, so the
     * wait-for-moderator rule, user limit, join logging and the BBB checksum are all
     * applied by Moodle at click time; no BBB server secret is exposed to the agent.
     */
    protected function execute_getJoinUrl(array $params, array $context = []): array {
        $cmid = $this->clean_required($params, 'cmid', PARAM_INT);
        $groupid = (int) $this->clean_optional($params, 'groupid', PARAM_INT, 0);
        $userid = (int) ($context['userid'] ?? 0);
        activity_access::require_cmid('bigbluebuttonbn', $cmid, $userid, 'BigBlueButton activity');
        $instance = \mod_bigbluebuttonbn\instance::get_from_cmid($cmid);
        if (!$instance) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "BigBlueButton activity course module {$cmid} not found");
        }
        $groupid = $this->resolve_group($instance, $groupid, $userid);
        $instance->set_group_id($groupid);
        if (!$instance->can_join()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'join this BigBlueButton meeting');
        }
        if ($instance->before_start_time()) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'This BigBlueButton meeting has not opened yet');
        }
        if ($instance->has_ended()) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'This BigBlueButton meeting has closed');
        }
        $url = $instance->get_join_url();
        if ($groupid) {
            $url->param('group', $groupid);
        }
        return ['cmid' => $cmid, 'groupid' => $groupid, 'join_url' => $url->out(false)];
    }

    /**
     * Attendance log of every participant. mod_bigbluebuttonbn has no own "view logs"
     * capability: other users' BBB join/leave events are only visible natively through
     * the course participation / logs reports, so we require the same capability as
     * those (report/participation:view or report/log:view in the course context) and
     * respect separate groups. Everyone else is refused.
     */
    protected function execute_getMeetingAttendanceReport(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $limit = max(1, (int) $this->clean_optional($params, 'limit', PARAM_INT, 100));
        $userid = (int) ($context['userid'] ?? 0);
        $instance = $this->instance_for($bbbid, $userid);
        $coursectx = \context_course::instance((int) $instance->get_course_id());
        $allowed = false;
        foreach (['report/participation:view', 'report/log:view'] as $cap) {
            if (get_capability_info($cap) && has_capability($cap, $coursectx, $userid)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view the attendance report of this meeting');
        }
        $where = 'bigbluebuttonbnid = :b';
        $args = ['b' => $bbbid];
        $userids = activity_access::group_visible_userids($instance->get_cm(), $userid);
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
            $where .= " AND userid {$insql}";
            $args += $inparams;
        }
        $rows = $DB->get_records_select(
            'bigbluebuttonbn_logs',
            $where,
            $args,
            'timecreated DESC, id DESC',
            'id,userid,log,meetingid,timecreated',
            0,
            $limit
        );
        return array_values(array_map(static fn($r) => [
            'id'          => (int) $r->id,
            'userid'      => (int) $r->userid,
            'log'         => $r->log,
            'timecreated' => (int) $r->timecreated,
        ], $rows));
    }

    /**
     * Recording rows the user may list: managers (mod/bigbluebuttonbn:managerecordings)
     * see all; everyone else only processed/notified, non-headless recordings.
     *
     * @param \mod_bigbluebuttonbn\instance $instance Instance.
     * @param int $userid Acting user.
     * @return array [string $where, array $params]
     */
    private function recording_filter(\mod_bigbluebuttonbn\instance $instance, int $userid): array {
        $where = 'bigbluebuttonbnid = :b';
        $args = ['b' => (int) $instance->get_instance_id()];
        if (!has_capability('mod/bigbluebuttonbn:managerecordings', $instance->get_context(), $userid)) {
            $where .= ' AND status IN (:s1, :s2) AND headless = 0';
            $args['s1'] = \mod_bigbluebuttonbn\recording::RECORDING_STATUS_PROCESSED;
            $args['s2'] = \mod_bigbluebuttonbn\recording::RECORDING_STATUS_NOTIFIED;
        }
        return [$where, $args];
    }

    /**
     * Load one recording row the user may see, or throw "not found".
     *
     * @param \mod_bigbluebuttonbn\instance $instance Instance.
     * @param string $recordingid BBB recording id.
     * @param int $userid Acting user.
     * @return \stdClass
     */
    private function recording_for(\mod_bigbluebuttonbn\instance $instance, string $recordingid, int $userid): \stdClass {
        global $DB;
        [$where, $args] = $this->recording_filter($instance, $userid);
        $args['rid'] = $recordingid;
        $r = $DB->get_record_select('bigbluebuttonbn_recordings', $where . ' AND recordingid = :rid', $args);
        if (!$r) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "Recording {$recordingid} not found");
        }
        return $r;
    }

    protected function execute_listRecordings(array $params, array $context = []): array {
        global $DB;
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $userid = (int) ($context['userid'] ?? 0);
        $instance = $this->instance_for($bbbid, $userid);
        [$where, $args] = $this->recording_filter($instance, $userid);
        $rows = $DB->get_records_select(
            'bigbluebuttonbn_recordings',
            $where,
            $args,
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
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $recordingid = $this->clean_required($params, 'recordingid', PARAM_TEXT);
        $userid = (int) ($context['userid'] ?? 0);
        $r = $this->recording_for($this->instance_for($bbbid, $userid), (string) $recordingid, $userid);
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
        $bbbid = $this->clean_required($params, 'bigbluebuttonbnid', PARAM_INT);
        $recordingid = $this->clean_required($params, 'recordingid', PARAM_TEXT);
        $userid = (int) ($context['userid'] ?? 0);
        $r = $this->recording_for($this->instance_for($bbbid, $userid), (string) $recordingid, $userid);
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
}
