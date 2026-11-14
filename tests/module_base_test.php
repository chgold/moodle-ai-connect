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
 * Tests for the module_base contract.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\module\module_base;

defined('MOODLE_INTERNAL') || die();

/**
 * Test double — minimal in-test subclass exercising the registration contract.
 */
final class module_base_test_fixture extends module_base {

    /** @var string Tool namespace prefix. */
    protected string $module_name = 'test';

    /**
     * Register two tools: one read, one write/pro.
     */
    protected function register_tools(): void {
        $this->register_tool('ping', [
            'description'    => 'Returns pong',
            'input_schema'   => ['type' => 'object', 'properties' => new \stdClass()],
            'required_scope' => 'read',
        ]);
        $this->register_tool('write', [
            'description'    => 'Mutates',
            'input_schema'   => ['type' => 'object', 'properties' => new \stdClass()],
            'required_scope' => 'write',
            'pro_only'       => true,
        ]);
    }

    /**
     * Sample read tool.
     *
     * @param array $params  Request body.
     * @param array $context Request context.
     * @return array Echoes back the context user id.
     */
    protected function execute_ping(array $params, array $context = []): array {
        return ['pong' => true, 'userid' => $context['userid'] ?? null];
    }

    /**
     * Sample write tool that throws — exercises error marshalling.
     *
     * @param array $params  Request body.
     * @param array $context Request context.
     * @return array Never returns successfully.
     */
    protected function execute_write(array $params, array $context = []): array {
        throw new \RuntimeException('boom');
    }
}

/**
 * Test double whose only tool forgets to declare required_scope.
 */
final class module_base_test_undeclared_fixture extends module_base {

    /** @var string Tool namespace prefix. */
    protected string $module_name = 'test';

    /**
     * Register one tool without a scope declaration.
     */
    protected function register_tools(): void {
        $this->register_tool('mutate', [
            'description'  => 'Mutates, but forgot its scope',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        ]);
    }

    /**
     * Must never run for a read-only token.
     *
     * @param array $params  Request body.
     * @param array $context Request context.
     * @return array Marker.
     */
    protected function execute_mutate(array $params, array $context = []): array {
        return ['ran' => true];
    }
}

/**
 * @covers \local_aiconnect\module\module_base
 */
final class module_base_test extends \advanced_testcase {

    /**
     * Unknown tool reports a structured error.
     */
    public function test_unknown_tool_returns_error(): void {
        $this->resetAfterTest();
        $module = new module_base_test_fixture();

        $result = $module->execute_tool('bogus', [], ['userid' => 1, 'scope' => 'read']);

        $this->assertSame('tool_not_found', $result['error']);
    }

    /**
     * Write tool refused without write scope.
     */
    public function test_write_tool_requires_write_scope(): void {
        $this->resetAfterTest();
        $module = new module_base_test_fixture();

        $result = $module->execute_tool('write', [], ['userid' => 1, 'scope' => 'read']);

        $this->assertSame('insufficient_scope', $result['error']);
    }

    /**
     * Read tool executes when scope permits and context is plumbed through.
     */
    public function test_read_tool_executes_with_context(): void {
        $this->resetAfterTest();
        $module = new module_base_test_fixture();

        $result = $module->execute_tool('ping', [], ['userid' => 42, 'scope' => 'read']);

        $this->assertTrue($result['pong']);
        $this->assertSame(42, $result['userid']);
    }

    /**
     * Thrown exceptions are marshalled into a tool_error response.
     */
    public function test_thrown_exception_becomes_tool_error(): void {
        $this->resetAfterTest();
        $module = new module_base_test_fixture();

        $result = $module->execute_tool('write', [], ['userid' => 1, 'scope' => 'read write']);
        $this->assertDebuggingCalled();

        $this->assertSame('tool_error', $result['error']);
        $this->assertStringContainsString('boom', $result['message']);
    }

    /**
     * get_tools filters out write-scoped tools when scope is read-only.
     */
    public function test_get_tools_filters_by_scope(): void {
        $this->resetAfterTest();
        $module = new module_base_test_fixture();

        $readonly = $module->get_tools('read');
        $full     = $module->get_tools('read write');

        $this->assertArrayHasKey('ping', $readonly);
        $this->assertArrayNotHasKey('write', $readonly);
        $this->assertCount(2, $full);
    }

    /**
     * An undeclared scope fails closed (SEC-02): the tool is treated as write.
     */
    public function test_undeclared_scope_fails_closed(): void {
        $this->resetAfterTest();
        $module = new module_base_test_undeclared_fixture();

        $this->assertSame('write', $module->get_tool_scope('mutate'));
        $this->assertSame('write', $module->get_tool_scope('unknown'));
        $this->assertArrayNotHasKey('mutate', $module->get_tools('read'));
        $result = $module->execute_tool('mutate', [], ['userid' => 1, 'scope' => 'read']);
        $this->assertSame('insufficient_scope', $result['error']);
        $result = $module->execute_tool('mutate', [], ['userid' => 1]);
        $this->assertSame('insufficient_scope', $result['error']);
        $result = $module->execute_tool('mutate', [], ['userid' => 1, 'scope' => 'read write']);
        $this->assertTrue($result['ran']);
    }
}
