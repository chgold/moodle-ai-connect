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
 * Tests for the 2.2.6 fixes: 424 for a capability-missing tool (#803) and
 * license slug matching against the PAID product slug (BUNDLES-LICENSE-SPEC).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\module\license_client;
use local_aiconnect\module\module_base;
use local_aiconnect\module\module_registry;
use local_aiconnect\module\pro_loader;

defined('MOODLE_INTERNAL') || die();

/**
 * Fixture module: one tool that needs a Moodle plugin that does not exist, one core tool.
 */
final class capability_fixture_module extends module_base {

    /** @var string Module namespace. */
    protected string $module_name = 'capfixture';

    /**
     * Register the two tools.
     */
    protected function register_tools(): void {
        $this->register_tool('needsMissingPlugin', [
            'description'         => 'Needs mod_doesnotexist',
            'required_scope'      => 'read',
            'requires_capability' => 'mod_doesnotexist',
        ]);
        $this->register_tool('core', [
            'description'    => 'No dependency',
            'required_scope' => 'read',
        ]);
    }

    /**
     * Must never run: the capability is missing.
     *
     * @return array
     */
    protected function execute_needsMissingPlugin(array $params, array $context = []): array {
        return ['ran' => true];
    }

    /**
     * Core tool.
     *
     * @return array
     */
    protected function execute_core(array $params, array $context = []): array {
        return ['ran' => true];
    }
}

/**
 * @covers \local_aiconnect\module\module_registry
 * @covers \local_aiconnect\module\module_base
 * @covers \local_aiconnect\module\pro_loader
 * @covers \local_aiconnect\module\license_client
 */
final class capability_license_test extends \advanced_testcase {

    /**
     * Reset extension callbacks between tests.
     */
    protected function tearDown(): void {
        module_registry::reset_callbacks();
        parent::tearDown();
    }

    /**
     * Registry with the fixture module added through the extension hook.
     *
     * @return module_registry
     */
    private function registry_with_fixture(): module_registry {
        module_registry::on_init(static function (module_registry $r): void {
            $r->register(new capability_fixture_module());
        });
        return new module_registry();
    }

    /**
     * A direct call to a tool whose capability is missing answers 424, not 404 (#803).
     */
    public function test_capability_missing_tool_returns_failed_dependency(): void {
        $this->resetAfterTest();
        $result = $this->registry_with_fixture()->execute('capfixture.needsMissingPlugin', [],
            ['userid' => 2, 'scope' => 'read write']);
        $this->assertSame('failed_dependency', $result['error'] ?? null);
        $this->assertSame(424, $result['statusCode'] ?? null);
        $this->assertArrayNotHasKey('ran', $result);
    }

    /**
     * The manifest list still hides the capability-missing tool.
     */
    public function test_capability_missing_tool_is_absent_from_manifest(): void {
        $this->resetAfterTest();
        $names = array_column($this->registry_with_fixture()->get_all_tools('read write'), 'name');
        $this->assertNotContains('capfixture.needsMissingPlugin', $names);
        $this->assertContains('capfixture.core', $names);
    }

    /**
     * Its scope is still known to the GET guard, and unknown tools stay 404.
     */
    public function test_scope_known_and_unknown_tool_still_not_found(): void {
        $this->resetAfterTest();
        $registry = $this->registry_with_fixture();
        $this->assertSame('read', $registry->get_tool_scope('capfixture.needsMissingPlugin'));
        $this->assertSame('tool_not_found', $registry->execute('capfixture.noSuchTool', [],
            ['userid' => 2, 'scope' => 'read write'])['error'] ?? null);
        $this->assertSame(['ran' => true], $registry->execute('capfixture.core', [],
            ['userid' => 2, 'scope' => 'read']));
    }

    /**
     * Pro module descriptors (private constant) via reflection.
     *
     * @return array<int, array{class:string,component:string,slug:string}>
     */
    private static function pro_modules(): array {
        return (new \ReflectionClass(pro_loader::class))->getConstant('PRO_MODULES');
    }

    /**
     * Every Pro module expects the slug of the product that is SOLD.
     */
    public function test_pro_modules_expect_the_paid_product_slugs(): void {
        $slugs = array_column(self::pro_modules(), 'slug', 'component');
        $this->assertSame([
            'local_aiconnectteacher' => 'moodle-teacher-ai-connect',
            'local_aiconnectstudent' => 'moodle-student-ai-connect',
            'local_aiconnectadmin'   => 'moodle-admin-ai-connect',
            'local_aiconnectbbb'     => 'moodle-bigbluebutton-ai-connect-pro',
            'local_aiconnecthvp'     => 'moodle-hvp-ai-connect-pro',
        ], $slugs);
    }

    /**
     * Seed a fresh cached license verdict for a component (no network call).
     *
     * @param string $component Moodle component.
     * @param string $status    Verdict status.
     * @param string|null $slug Slug the server answered with.
     */
    private static function seed_license(string $component, string $status, ?string $slug): void {
        set_config('license_key', 'TEST-KEY-NOT-REAL', $component);
        $cache = ['status' => $status, 'checked_at' => time()];
        if ($slug !== null) {
            $cache['plugin_slug'] = $slug;
        }
        set_config('license_status', json_encode($cache), $component);
    }

    /**
     * Per Pro: a license for that Pro's own slug unlocks it; the free slug, another Pro's
     * slug, an invalid status or no key do not.
     */
    public function test_license_slug_matching_per_pro(): void {
        $this->resetAfterTest();
        if (getenv('AICONNECT_LICENSE_KEY')) {
            $this->markTestSkipped('AICONNECT_LICENSE_KEY is set in the environment');
        }
        $modules = self::pro_modules();
        foreach ($modules as $i => $mod) {
            $component = $mod['component'];
            $slug      = $mod['slug'];
            $other     = $modules[($i + 1) % count($modules)]['slug'];

            self::seed_license($component, 'valid', $slug);
            $this->assertTrue(license_client::is_valid_for($component, $slug), "{$component}: own slug");

            self::seed_license($component, 'valid_no_updates', $slug);
            $this->assertTrue(license_client::is_valid_for($component, $slug), "{$component}: valid_no_updates");

            self::seed_license($component, 'valid', $other);
            $this->assertFalse(license_client::is_valid_for($component, $slug), "{$component}: other Pro's slug");

            $free = preg_replace('/-pro$/', '', $slug);
            if ($free !== $slug) {
                self::seed_license($component, 'valid', $free);
                $this->assertFalse(license_client::is_valid_for($component, $slug), "{$component}: free slug");
            }

            self::seed_license($component, 'expired', $slug);
            $this->assertFalse(license_client::is_valid_for($component, $slug), "{$component}: expired");

            self::seed_license($component, 'error_cached', null);
            $this->assertTrue(license_client::is_valid_for($component, $slug), "{$component}: fail-open");

            unset_config('license_key', $component);
            $this->assertFalse(license_client::is_valid_for($component, $slug), "{$component}: no key");
        }
    }
}
