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
 * Tool module registry — the single dispatcher for AI Connect tools.
 *
 * Registers {@see core_module} always, and loads the separate Pro plugin's
 * module via {@see pro_loader} only when the site edition grants Pro.
 * Third-party plugins can register additional {@see module_base} subclasses
 * via {@see module_registry::on_init()}; the callback fires when the registry
 * is instantiated.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\module;

defined('MOODLE_INTERNAL') || die();

/**
 * Central registry that owns every {@see module_base} instance and routes
 * incoming tool calls to the correct module.
 */
class module_registry {

    /** @var array<int, module_base> Registered modules, in registration order. */
    private array $modules = [];

    /** @var array<string, module_base> Map of full tool name → owning module. */
    private array $tool_index = [];

    /** @var array<int, callable> Init hooks registered before construction. */
    private static array $init_callbacks = [];

    /**
     * Build the default registry (core + pro) and fire any extension hooks.
     */
    public function __construct() {
        $this->register(new core_module());
        $this->register(new content_module());
        $this->register(new bigbluebutton_module());
        $this->register(new hvp_module());
        pro_loader::load($this);

        self::run_init_callbacks($this);
    }

    /**
     * Add a module to the registry and index its tools.
     *
     * @param module_base $module Module instance.
     */
    public function register(module_base $module): void {
        $this->modules[] = $module;
        foreach ($module->get_tools('read write') as $shortname => $tool) {
            $this->tool_index[$tool['name']] = $module;
        }
    }

    /**
     * Collect tools from every module, filtered by scope.
     *
     * @param string $scope Caller scope (`read` or `read write`).
     * @return array<int, array> Tool definitions ready for the manifest.
     */
    public function get_all_tools(string $scope = 'read write'): array {
        $out = [];
        foreach ($this->modules as $module) {
            foreach ($module->get_tools($scope) as $tool) {
                $out[] = [
                    'name'         => $tool['name'],
                    'description'  => $tool['description'],
                    'input_schema' => $tool['input_schema'],
                ];
            }
        }
        return $out;
    }

    /**
     * Dispatch a tool call to its module.
     *
     * @param string $toolname Fully-qualified tool name (e.g. moodle.getCourses).
     * @param array  $params   Decoded request body.
     * @param array  $context  Request context (must contain `userid`, optionally `scope`).
     * @return array Tool result.
     */
    public function execute(string $toolname, array $params, array $context = []): array {
        if (!str_contains($toolname, '.')) {
            return ['error' => 'tool_not_found', 'message' => "Invalid tool name: {$toolname}"];
        }
        if (!isset($this->tool_index[$toolname])) {
            return ['error' => 'tool_not_found', 'message' => "Unknown tool: {$toolname}"];
        }
        [, $shortname] = explode('.', $toolname, 2);
        return $this->tool_index[$toolname]->execute_tool($shortname, $params, $context);
    }

    /**
     * Return the required_scope for a single tool.
     *
     * Used by the GET endpoint guard to enforce read-only access via HTTP GET.
     *
     * @param string $toolname Fully-qualified tool name (e.g. moodle.getCourses).
     * @return string|null 'read'|'write', or null when the tool is not found.
     */
    public function get_tool_scope(string $toolname): ?string {
        if (!str_contains($toolname, '.') || !isset($this->tool_index[$toolname])) {
            return null;
        }
        [, $shortname] = explode('.', $toolname, 2);
        return $this->tool_index[$toolname]->get_tool_scope($shortname);
    }

    /**
     * @return array<int, module_base> Currently registered modules.
     */
    public function get_modules(): array {
        return $this->modules;
    }

    /**
     * Register an extension callback executed when a new registry is built.
     *
     * Third-party plugins can call this from their {@code lib.php} or a
     * service-init hook to add modules without modifying core code.
     *
     * @param callable $callback Receives the registry instance.
     */
    public static function on_init(callable $callback): void {
        self::$init_callbacks[] = $callback;
    }

    /**
     * Reset registered callbacks. Test-only helper.
     */
    public static function reset_callbacks(): void {
        self::$init_callbacks = [];
    }

    /**
     * Fire all registered init callbacks against the freshly built registry.
     *
     * @param self $registry The registry being initialised.
     */
    private static function run_init_callbacks(self $registry): void {
        foreach (self::$init_callbacks as $callback) {
            $callback($registry);
        }
    }
}
