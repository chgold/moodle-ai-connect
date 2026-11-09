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

use local_aiconnect\module\module_registry;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Produces the JSON manifest published at /local/aiconnect/manifest.php.
 */
class manifest_controller {

    /** @var module_registry Source of truth for the tool catalogue. */
    private module_registry $registry;

    /**
     * @param module_registry|null $registry Override (test seam); built fresh when null.
     */
    public function __construct(?module_registry $registry = null) {
        $this->registry = $registry ?? new module_registry();
    }

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
     * Fetch registered OAuth clients keyed by client_id with the display name as value.
     *
     * Used to populate `auth.registered_clients` so AI agents can discover
     * accepted client identifiers directly from the manifest.
     *
     * @return array<string, string> Map of client_id => client_name (empty when DB unavailable).
     */
    private function registered_clients(): array {
        global $DB;

        try {
            $records = $DB->get_records_sql(
                'SELECT client_id, client_name FROM {local_aiconnect_clients} ORDER BY id ASC'
            );
        } catch (\Throwable $e) {
            return [];
        }

        $clients = [];
        foreach ($records as $record) {
            $clientid = (string) ($record->client_id ?? '');
            if ($clientid === '') {
                continue;
            }
            $clients[$clientid] = (string) ($record->client_name ?? $clientid);
        }
        return $clients;
    }

    /**
     * Build the manifest payload.
     *
     * @return array Manifest array ready to be JSON-encoded.
     */
    public function get(): array {
        $base = $this->base_url() . '/local/aiconnect';

        $auth = [
            'type'                  => 'oauth2',
            'flow'                  => 'authorization_code',
            'pkce_required'         => true,
            'code_challenge_method' => 'S256',
            'authorization_url'     => "$base/oauth.php?action=authorize",
            'token_url'             => "$base/oauth.php?action=token",
            'revocation_url'        => "$base/oauth.php?action=revoke",
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            'scopes'                => [
                'read'   => 'Read courses, grades, assignments',
                'write'  => 'Manage enrollments, grades (Pro)',
                'delete' => 'Delete operations (Pro)',
            ],
        ];

        $clients = $this->registered_clients();
        if (!empty($clients)) {
            $auth['registered_clients'] = $clients;
        }

        return [
            'schema_version' => '1.0',
            'name'           => 'moodle-plugin',
            'version'        => '2.2.1',
            'description'    => 'AI Connect for Moodle — access courses, grades, assignments and manage learning via AI agents',
            'api_version'    => 'v1',
            'capabilities'   => ['tools' => true, 'resources' => false, 'prompts' => false],
            'server'         => [
                'url'         => "$base/v1/tools",
                'description' => 'Base URL — POST to {url}/{tool_name} with Bearer token',
            ],
            'auth'  => $auth,
            'tools' => $this->registry->get_all_tools('read write'),
        ];
    }
}
