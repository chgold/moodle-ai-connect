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
 * WebMCP manifest builder.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\controller;

use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Produces the JSON manifest published at /local/aiconnect/manifest.php.
 */
class manifest_controller {

    /**
     * Resolve the public base URL, honouring the `public_base_url` setting.
     *
     * @return string Base URL without trailing slash.
     */
    private function base_url(): string {
        $cfg = (string) get_config('local_aiconnect', 'public_base_url');
        if ($cfg !== '') {
            return rtrim($cfg, '/');
        }
        return rtrim((new moodle_url('/'))->out(false), '/');
    }

    /**
     * Build the manifest payload.
     *
     * @return array Manifest array ready to be JSON-encoded.
     */
    public function get(): array {
        $base = $this->base_url() . '/local/aiconnect';
        return [
            'schema_version' => '1.0',
            'name'           => 'moodle-plugin',
            'version'        => '1.1.0',
            'description'    => 'AI Connect for Moodle — access courses, grades, assignments and manage learning via AI agents',
            'api_version'    => 'v1',
            'capabilities'   => ['tools' => true, 'resources' => false, 'prompts' => false],
            'server'         => [
                'url'         => "$base/v1",
                'description' => 'Base URL — tool endpoints at /v1/tools/{tool_name}',
            ],
            'auth' => [
                'type'                  => 'oauth2',
                'flow'                  => 'authorization_code',
                'pkce_required'         => true,
                'code_challenge_method' => 'S256',
                'authorization_url'     => "$base/oauth.php?action=authorize",
                'token_url'             => "$base/oauth.php?action=token",
                'revocation_url'        => "$base/oauth.php?action=revoke",
                'scopes'                => [
                    'read'   => 'Read courses, grades, assignments',
                    'write'  => 'Manage enrollments, grades (Pro)',
                    'delete' => 'Delete operations (Pro)',
                ],
            ],
            'tools' => $this->tool_definitions(),
        ];
    }

    /**
     * Static tool catalogue advertised in the manifest.
     *
     * @return array List of tool definitions.
     */
    private function tool_definitions(): array {
        return [
            ['name' => 'moodle.getCourses',
                'description' => 'List available courses',
                'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]]],
            ['name' => 'moodle.getCourse',
                'description' => 'Get details of a specific course',
                'input_schema' => ['type' => 'object', 'required' => ['courseid'],
                    'properties' => ['courseid' => ['type' => 'integer']]]],
            ['name' => 'moodle.getEnrollments',
                'description' => 'Get courses the current user is enrolled in',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'moodle.getAssignments',
                'description' => 'List assignments in a course',
                'input_schema' => ['type' => 'object', 'required' => ['courseid'],
                    'properties' => ['courseid' => ['type' => 'integer']]]],
            ['name' => 'moodle.getGrades',
                'description' => 'Get grades for the current user in a course',
                'input_schema' => ['type' => 'object', 'required' => ['courseid'],
                    'properties' => ['courseid' => ['type' => 'integer']]]],
            ['name' => 'moodle.getUserProfile',
                'description' => 'Get profile of the current user',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'moodle.enrollUser',
                'description' => 'Enroll a user in a course (Pro)',
                'input_schema' => ['type' => 'object', 'required' => ['userid', 'courseid'],
                    'properties' => [
                        'userid'   => ['type' => 'integer'],
                        'courseid' => ['type' => 'integer'],
                        'roleid'   => ['type' => 'integer'],
                    ]]],
            ['name' => 'moodle.createAssignment',
                'description' => 'Create an assignment in a course (Pro)',
                'input_schema' => ['type' => 'object', 'required' => ['courseid', 'name'],
                    'properties' => [
                        'courseid' => ['type' => 'integer'],
                        'name'     => ['type' => 'string'],
                        'intro'    => ['type' => 'string'],
                    ]]],
            ['name' => 'moodle.submitGrade',
                'description' => 'Submit a grade for a student (Pro)',
                'input_schema' => ['type' => 'object', 'required' => ['assignid', 'userid', 'grade'],
                    'properties' => [
                        'assignid' => ['type' => 'integer'],
                        'userid'   => ['type' => 'integer'],
                        'grade'    => ['type' => 'number'],
                    ]]],
            ['name' => 'moodle.sendMessage',
                'description' => 'Send a message to a user (Pro)',
                'input_schema' => ['type' => 'object', 'required' => ['touserid', 'message'],
                    'properties' => [
                        'touserid' => ['type' => 'integer'],
                        'message'  => ['type' => 'string'],
                    ]]],
            ['name' => 'moodle.updateCourse',
                'description' => 'Update course details (Pro)',
                'input_schema' => ['type' => 'object', 'required' => ['courseid'],
                    'properties' => [
                        'courseid' => ['type' => 'integer'],
                        'fullname' => ['type' => 'string'],
                        'summary'  => ['type' => 'string'],
                    ]]],
            ['name' => 'moodle.searchUsers',
                'description' => 'Search users by name or email (Pro)',
                'input_schema' => ['type' => 'object', 'required' => ['query'],
                    'properties' => ['query' => ['type' => 'string']]]],
        ];
    }
}
