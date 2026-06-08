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
 * Abstract base class for AI Connect tool modules.
 *
 * Subclasses register a set of WebMCP tools and provide an `execute_{toolname}`
 * method for each. Tools are scope-gated (read/write) and may be flagged as
 * Pro-only. The base class handles dispatch, scope filtering and error
 * marshalling so subclasses focus purely on Moodle queries.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Base class for a self-contained group of WebMCP tools.
 */
abstract class module_base {

    /** @var string Module namespace prefix (e.g. "moodle" → "moodle.getCourses"). */
    protected string $module_name = '';

    /** @var array<string, array> Registered tools keyed by short name. */
    protected array $tools = [];

    /**
     * Build the module and register its tools.
     */
    public function __construct() {
        $this->register_tools();
    }

    /**
     * Subclasses must populate $this->tools by calling register_tool().
     */
    abstract protected function register_tools(): void;

    /**
     * Register a single tool with this module.
     *
     * @param string $name   Short name (without module prefix).
     * @param array  $config Tool config: description, input_schema, required_scope, pro_only.
     */
    protected function register_tool(string $name, array $config): void {
        $fullname = $this->module_name . '.' . $name;
        $this->tools[$name] = [
            'name'           => $fullname,
            'description'    => $config['description'] ?? '',
            'input_schema'   => $config['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            'required_scope' => $config['required_scope'] ?? 'read',
            'pro_only'       => $config['pro_only'] ?? false,
        ];
    }

    /**
     * Execute a tool by short name.
     *
     * @param string $toolname Short name (without module prefix).
     * @param array  $params   Decoded request body.
     * @param array  $context  Request context (must contain `userid`, optionally `scope`).
     * @return array Tool result. Errors return ['error' => ..., 'message' => ...].
     */
    public function execute_tool(string $toolname, array $params, array $context = []): array {
        if (!isset($this->tools[$toolname])) {
            return ['error' => 'tool_not_found', 'message' => "Tool {$toolname} not found"];
        }

        $tool = $this->tools[$toolname];
        $scope = (string) ($context['scope'] ?? 'read');
        if ($tool['required_scope'] === 'write' && !str_contains($scope, 'write')) {
            return ['error' => 'insufficient_scope',
                    'message' => 'This tool requires write scope (Pro tier).'];
        }

        $method = 'execute_' . $toolname;
        if (!method_exists($this, $method)) {
            return ['error' => 'tool_not_implemented',
                    'message' => "Tool {$toolname} is registered but has no executor"];
        }

        try {
            return $this->{$method}($params, $context);
        } catch (\invalid_parameter_exception $e) {
            return ['error' => 'tool_error', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            debugging('local_aiconnect module ' . $this->module_name . '.' . $toolname
                . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['error' => 'tool_error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Return the tool list, optionally filtered by scope.
     *
     * @param string $scope Caller scope (`read` or `read write`).
     * @return array<string, array> Tools visible at this scope.
     */
    public function get_tools(string $scope = 'read write'): array {
        return array_filter($this->tools, static function ($tool) use ($scope) {
            if ($tool['required_scope'] === 'read') {
                return true;
            }
            return str_contains($scope, 'write');
        });
    }

    /**
     * Return the required_scope for a single registered tool.
     *
     * Used by the GET endpoint guard to reject write/admin tools
     * before executing them.
     *
     * @param string $shortname Short tool name (without module prefix).
     * @return string 'read' or 'write'; defaults to 'read' for unknown tools.
     */
    public function get_tool_scope(string $shortname): string {
        return $this->tools[$shortname]['required_scope'] ?? 'read';
    }

    /**
     * @return string Module namespace prefix.
     */
    public function get_module_name(): string {
        return $this->module_name;
    }

    /**
     * Validate a required parameter using Moodle's PARAM_* cleaner.
     *
     * @param array  $params Raw input.
     * @param string $key    Parameter name.
     * @param string $type   PARAM_* constant.
     * @return mixed Cleaned value.
     * @throws \invalid_parameter_exception When the parameter is missing.
     */
    protected function clean_required(array $params, string $key, string $type) {
        if (!array_key_exists($key, $params)) {
            throw new \invalid_parameter_exception("Missing parameter: $key");
        }
        return clean_param($params[$key], $type);
    }

    /**
     * Validate an optional parameter using Moodle's PARAM_* cleaner.
     *
     * @param array  $params  Raw input.
     * @param string $key     Parameter name.
     * @param string $type    PARAM_* constant.
     * @param mixed  $default Default to return when the key is absent.
     * @return mixed Cleaned value or default.
     */
    protected function clean_optional(array $params, string $key, string $type, $default = null) {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        return clean_param($params[$key], $type);
    }
}
