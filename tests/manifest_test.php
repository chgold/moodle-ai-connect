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
 * Tests for the manifest controller.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\controller\manifest_controller;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_aiconnect\controller\manifest_controller
 */
final class manifest_test extends \advanced_testcase {

    /**
     * Manifest returns required top-level WebMCP fields.
     */
    public function test_manifest_has_required_fields(): void {
        $this->resetAfterTest();

        $manifest = (new manifest_controller())->get();

        $this->assertArrayHasKey('schema_version', $manifest);
        $this->assertSame('moodle-plugin', $manifest['name']);
        $this->assertSame('v1', $manifest['api_version']);
        $this->assertSame('oauth2', $manifest['auth']['type']);
        $this->assertTrue($manifest['auth']['pkce_required']);
        $this->assertSame('S256', $manifest['auth']['code_challenge_method']);
    }

    /**
     * Manifest advertises 12 tools (6 free + 6 pro).
     */
    public function test_manifest_lists_twelve_tools(): void {
        $this->resetAfterTest();

        $manifest = (new manifest_controller())->get();

        $this->assertCount(12, $manifest['tools']);
        $names = array_column($manifest['tools'], 'name');
        $this->assertContains('moodle.getCourses', $names);
        $this->assertContains('moodle.enrollUser', $names);
    }

    /**
     * `public_base_url` admin setting overrides the auto-detected URL.
     */
    public function test_manifest_uses_public_base_url_override(): void {
        $this->resetAfterTest();
        set_config('public_base_url', 'https://example.test', 'local_aiconnect');

        $manifest = (new manifest_controller())->get();

        $this->assertStringStartsWith('https://example.test/local/aiconnect', $manifest['server']['url']);
    }
}
