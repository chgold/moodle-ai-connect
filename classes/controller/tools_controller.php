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

use local_aiconnect\module\module_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin façade in front of {@see module_registry}; kept for backwards
 * compatibility with existing endpoint code that constructs a
 * tools_controller directly.
 */
class tools_controller {
    /** @var module_registry Tool dispatcher. */
    private module_registry $registry;

    /**
     * @param module_registry|null $registry Override (test seam); built fresh when null.
     */
    public function __construct(?module_registry $registry = null) {
        $this->registry = $registry ?? new module_registry();
    }

    /**
     * Execute a tool by name.
     *
     * @param string $tool   Fully-qualified tool name (e.g. moodle.getCourses).
     * @param array  $params Decoded JSON request body.
     * @param int    $userid Authenticated Moodle user id.
     * @param string $scope  Scopes granted to the bearer token.
     * @param array  $extra  clientid + tokenprefix of the bearer token (confirmation binding).
     * @return array Tool response. On error, contains an `error` key.
     */
    public function execute(string $tool, array $params, int $userid, string $scope, array $extra = []): array {
        return $this->registry->execute($tool, $params, [
            'userid'      => $userid,
            'scope'       => $scope,
            'clientid'    => (string) ($extra['clientid'] ?? ''),
            'tokenprefix' => (string) ($extra['tokenprefix'] ?? ''),
        ]);
    }

    /**
     * Return the required_scope for a tool without executing it.
     *
     * Used by the GET endpoint guard to reject non-read tools before running them.
     *
     * @param string $tool Fully-qualified tool name.
     * @return string|null 'read'|'write', or null when the tool is not registered.
     */
    public function get_tool_scope(string $tool): ?string {
        return $this->registry->get_tool_scope($tool);
    }
}
