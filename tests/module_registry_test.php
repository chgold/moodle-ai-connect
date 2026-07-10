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
 * Tests for the module_registry dispatcher.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\module\module_base;
use local_aiconnect\module\module_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Third-party extension fixture — provides an "extra.echo" tool.
 */
final class extra_module_fixture extends module_base {

    /** @var string Different namespace to prove side-by-side modules work. */
    protected string $module_name = 'extra';

    /**
     * Register a single read tool.
     */
    protected function register_tools(): void {
        $this->register_tool('echo', [
            'description'    => 'Echoes input',
            'input_schema'   => ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]],
            'required_scope' => 'read',
        ]);
    }

    /**
     * Echo tool.
     *
     * @param array $params  Request body.
     * @param array $context Request context.
     * @return array Echoed message.
     */
    protected function execute_echo(array $params, array $context = []): array {
        return ['msg' => $params['msg'] ?? ''];
    }
}

/**
 * @covers \local_aiconnect\module\module_registry
 */
final class module_registry_test extends \advanced_testcase {

    /**
     * Reset extension callbacks between tests.
     */
    protected function tearDown(): void {
        module_registry::reset_callbacks();
        parent::tearDown();
    }

    /**
     * With Pro edition, the registry lists core + pro = 12 tools.
     */
    public function test_pro_edition_registry_lists_twelve_tools(): void {
        $this->resetAfterTest();
        module_registry::reset_callbacks();
        set_config('edition', 'pro', 'local_aiconnect');

        $registry = new module_registry();
        $tools = $registry->get_all_tools('read write');

        $this->assertCount(12, $tools);
        $names = array_column($tools, 'name');
        $this->assertContains('moodle.getCourses', $names);
        $this->assertContains('moodle.enrollUser', $names);
    }

    /**
     * With Free edition, the separate Pro plugin is not loaded — 6 core tools.
     */
    public function test_free_edition_excludes_pro_tools(): void {
        $this->resetAfterTest();
        module_registry::reset_callbacks();
        set_config('edition', 'free', 'local_aiconnect');

        $registry = new module_registry();
        $names = array_column($registry->get_all_tools('read write'), 'name');

        $this->assertContains('moodle.getCourses', $names);
        $this->assertNotContains('moodle.enrollUser', $names);
    }

    /**
     * Read-only scope hides write tools even under Pro edition.
     */
    public function test_read_scope_hides_pro_tools(): void {
        $this->resetAfterTest();
        module_registry::reset_callbacks();
        set_config('edition', 'pro', 'local_aiconnect');

        $registry = new module_registry();
        $names = array_column($registry->get_all_tools('read'), 'name');

        $this->assertContains('moodle.getCourses', $names);
        $this->assertNotContains('moodle.enrollUser', $names);
    }

    /**
     * Invalid tool names yield a structured tool_not_found error.
     */
    public function test_invalid_tool_name_format(): void {
        $this->resetAfterTest();
        module_registry::reset_callbacks();

        $registry = new module_registry();
        $result = $registry->execute('no_dot_here', [], ['userid' => 0, 'scope' => 'read']);

        $this->assertSame('tool_not_found', $result['error']);
    }

    /**
     * Unknown module → tool_not_found.
     */
    public function test_unknown_module(): void {
        $this->resetAfterTest();
        module_registry::reset_callbacks();

        $registry = new module_registry();
        $result = $registry->execute('ghost.boo', [], ['userid' => 0, 'scope' => 'read']);

        $this->assertSame('tool_not_found', $result['error']);
    }

    /**
     * Third-party module registered via on_init hook participates in dispatch.
     */
    public function test_third_party_module_via_on_init(): void {
        $this->resetAfterTest();
        module_registry::reset_callbacks();

        module_registry::on_init(static function (module_registry $r): void {
            $r->register(new extra_module_fixture());
        });

        $registry = new module_registry();
        $names = array_column($registry->get_all_tools('read'), 'name');
        $this->assertContains('extra.echo', $names);

        $result = $registry->execute('extra.echo', ['msg' => 'hi'], ['userid' => 1, 'scope' => 'read']);
        $this->assertSame('hi', $result['msg']);
    }
}
