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
 * Tests for the user confirmation flow (user-confirm-v1, 2.2.7).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\controller\manifest_controller;
use local_aiconnect\local\confirmation;
use local_aiconnect\module\module_base;
use local_aiconnect\module\module_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Fixture module: one always-gated tool, one conditional tool, one read tool.
 */
final class confirm_fixture_module extends module_base {
    /** @var string Module namespace. */
    protected string $module_name = 'cfix';

    /** @var int How many times the gated tool really ran. */
    public static int $runs = 0;

    /** @var array Arguments of the last real run. */
    public static array $lastargs = [];

    /**
     * Register tools.
     */
    protected function register_tools(): void {
        $this->register_tool('deleteThing', [
            'description'    => 'Delete a thing',
            'input_schema'   => ['type' => 'object', 'required' => ['thingid', 'confirm'], 'properties' => (object) [
                'thingid' => ['type' => 'integer'],
                'confirm' => ['type' => 'boolean'],
            ]],
            'required_scope' => 'write',
            'confirmation'   => [
                'required' => true,
                'risk'     => 'destructive',
                'summary'  => 'Delete thing #{thingid}',
                'preview'  => 'preview_deleteThing',
            ],
        ]);
        $this->register_tool('maybeDelete', [
            'description'    => 'Delete only when hard=true',
            'required_scope' => 'write',
            'confirmation'   => [
                'required' => static fn(array $args): bool => !empty($args['hard']),
                'summary'  => 'Hard delete {thingid}',
            ],
        ]);
        $this->register_tool('readThing', [
            'description'    => 'Read',
            'required_scope' => 'read',
            'confirmation'   => 'required',
        ]);
    }

    /**
     * Preview: validates and returns the user's sentence + details.
     *
     * @param array $args Arguments.
     * @param array $context Context.
     * @return array
     */
    public function preview_deleteThing(array $args, array $context): array {
        $id = $this->clean_required($args, 'thingid', PARAM_INT);
        if ($id === 404) {
            return $this->error('not_found', 'No such thing');
        }
        return ['summary' => 'Permanently delete thing <b>#' . $id . '</b>', 'details' => ['Its 3 files are removed too']];
    }

    /**
     * The destructive tool.
     *
     * @param array $params Arguments.
     * @param array $context Context.
     * @return array
     */
    protected function execute_deleteThing(array $params, array $context = []): array {
        self::$runs++;
        self::$lastargs = $params;
        return ['deleted' => (int) $params['thingid'], 'newpassword' => 'S3cret!'];
    }

    /**
     * Conditional tool.
     *
     * @param array $params Arguments.
     * @param array $context Context.
     * @return array
     */
    protected function execute_maybeDelete(array $params, array $context = []): array {
        self::$runs++;
        return ['ok' => true];
    }

    /**
     * Read tool (never gated).
     *
     * @param array $params Arguments.
     * @param array $context Context.
     * @return array
     */
    protected function execute_readThing(array $params, array $context = []): array {
        return ['read' => true];
    }
}

/**
 * Tests of the confirmation gate, the user decisions and the manifest declaration.
 *
 * @covers \local_aiconnect\local\confirmation
 * @covers \local_aiconnect\module\module_base
 */
final class confirmation_test extends \advanced_testcase {
    /** @var module_registry */
    private module_registry $registry;

    /** @var \stdClass Token owner. */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        confirm_fixture_module::$runs = 0;
        confirm_fixture_module::$lastargs = [];
        $this->registry = new module_registry();
        $this->registry->register(new confirm_fixture_module());
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
    }

    /**
     * Context of an agent call.
     *
     * @param string $client Client id.
     * @return array
     */
    private function ctx(string $client = 'claude-ai'): array {
        return ['userid' => (int) $this->user->id, 'scope' => 'read write', 'clientid' => $client,
            'tokenprefix' => 'wpc_abcdefghijkl'];
    }

    /**
     * Agent call.
     *
     * @param array  $args Arguments.
     * @param string $tool Tool.
     * @param string $client Client.
     * @return array
     */
    private function call(array $args, string $tool = 'cfix.deleteThing', string $client = 'claude-ai'): array {
        return $this->registry->execute($tool, $args, $this->ctx($client));
    }

    public function test_call_queues_and_does_not_execute(): void {
        global $DB, $CFG;
        $r = $this->call(['thingid' => 7, 'confirm' => true]);
        $this->assertSame('confirmation_required', $r['error']);
        $this->assertSame(202, $r['statusCode']);
        $env = $r['envelope'];
        $this->assertSame('pending_confirmation', $env['status']);
        $this->assertSame('confirmation_required', $env['code']);
        $this->assertSame('user-confirm-v1', $env['flow']);
        $this->assertMatchesRegularExpression('/^conf_[a-f0-9]{32}$/', $env['confirmation_id']);
        $this->assertSame('Permanently delete thing #7', $env['summary']);
        $this->assertStringContainsString('Permanently delete thing #7', $env['user_message']);
        $this->assertSame(['Its 3 files are removed too'], $env['preview']['details']);
        $this->assertSame(parse_url($CFG->wwwroot, PHP_URL_HOST), parse_url($env['confirm_url'], PHP_URL_HOST));
        $this->assertStringContainsString('/local/aiconnect/aiactions.php', $env['confirm_url']);
        $this->assertStringContainsString('/local/aiconnect/confirmations.php?id=', $env['status_url']);
        $this->assertGreaterThan(800, $env['expires_in']);
        $this->assertSame($env['confirmation_id'], $env['data']['confirmation_id']);
        $this->assertSame(0, confirm_fixture_module::$runs);
        $row = $DB->get_record('local_aiconnect_pending', ['confid' => $env['confirmation_id']]);
        $this->assertSame('pending', $row->status);
        $this->assertSame(confirmation::args_hash(['thingid' => 7]), $row->argshash);
    }

    public function test_preview_error_queues_nothing(): void {
        global $DB;
        $r = $this->call(['thingid' => 404]);
        $this->assertSame('not_found', $r['error']);
        $this->assertSame(0, $DB->count_records('local_aiconnect_pending'));
        $r = $this->call([]);
        $this->assertSame('tool_error', $r['error']);
    }

    public function test_same_call_reuses_pending(): void {
        global $DB;
        $a = $this->call(['thingid' => 7])['envelope'];
        $b = $this->call(['thingid' => 7])['envelope'];
        $this->assertSame($a['confirmation_id'], $b['confirmation_id']);
        $this->assertTrue($b['reused']);
        $this->assertSame(1, $DB->count_records('local_aiconnect_pending'));
        // Other arguments or another client → a separate request.
        $c = $this->call(['thingid' => 8])['envelope'];
        $d = $this->call(['thingid' => 7], 'cfix.deleteThing', 'chatgpt')['envelope'];
        $this->assertNotSame($a['confirmation_id'], $c['confirmation_id']);
        $this->assertNotSame($a['confirmation_id'], $d['confirmation_id']);
    }

    public function test_confirm_runs_once_and_replay_is_refused(): void {
        $sink = $this->redirectEvents();
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];

        // Agent resubmits while pending: still 202, nothing runs.
        $r = $this->call(['thingid' => 7, 'confirmation_id' => $id]);
        $this->assertSame('confirmation_required', $r['error']);
        $this->assertSame(0, confirm_fixture_module::$runs);

        // Another user cannot confirm it.
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        $out = confirmation::confirm($id, (int) $other->id, false, $this->registry);
        $this->assertFalse($out['ok']);
        $this->assertSame('not_found', $out['status']);
        $this->assertSame(0, confirm_fixture_module::$runs);

        $this->setUser($this->user);
        $out = confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $this->assertTrue($out['ok']);
        $this->assertSame('executed', $out['status']);
        $this->assertSame(1, confirm_fixture_module::$runs);
        $this->assertSame(['thingid' => 7, 'confirm' => true], confirm_fixture_module::$lastargs);

        // Second confirm of the same id is refused.
        $again = confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $this->assertFalse($again['ok']);
        $this->assertSame('executed', $again['status']);
        $this->assertSame(1, confirm_fixture_module::$runs);

        // Agent resubmits with the id: stored result, never runs again.
        $r = $this->call(['thingid' => 7, 'confirmation_id' => $id]);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame(7, $r['deleted']);
        $this->assertTrue($r['confirmation']['already_executed']);
        $this->assertSame(1, confirm_fixture_module::$runs);

        // After first delivery the secret-looking value is redacted.
        $r = $this->call(['thingid' => 7, 'confirmation_id' => $id]);
        $this->assertSame('[redacted after first delivery]', $r['newpassword']);

        // The id with other arguments → mismatch.
        $r = $this->call(['thingid' => 9, 'confirmation_id' => $id]);
        $this->assertSame('confirmation_mismatch', $r['error']);
        $this->assertSame(409, $r['statusCode']);

        // Unknown id → 404.
        $r = $this->call(['thingid' => 7, 'confirmation_id' => 'conf_' . str_repeat('0', 32)]);
        $this->assertSame('confirmation_not_found', $r['error']);

        $names = array_map(static fn($e) => $e->eventname, $sink->get_events());
        $this->assertContains('\\local_aiconnect\\event\\ai_action_requested', $names);
        $this->assertContains('\\local_aiconnect\\event\\ai_action_confirmed', $names);
        $sink->close();
    }

    public function test_duplicate_after_execution_returns_stored_result(): void {
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $r = $this->call(['thingid' => 7]);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertTrue($r['confirmation']['already_executed']);
        $this->assertSame(1, confirm_fixture_module::$runs);
        // The user asked to do it again: a new request.
        $r = $this->call(['thingid' => 7, 'confirmation_new' => true]);
        $this->assertSame('confirmation_required', $r['error']);
        $this->assertNotSame($id, $r['envelope']['confirmation_id']);
    }

    public function test_reject(): void {
        $sink = $this->redirectEvents();
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        $other = $this->getDataGenerator()->create_user();
        $this->assertFalse(confirmation::reject($id, (int) $other->id));
        $this->assertTrue(confirmation::reject($id, (int) $this->user->id));
        $this->assertFalse(confirmation::reject($id, (int) $this->user->id));
        $out = confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $this->assertFalse($out['ok']);
        $r = $this->call(['thingid' => 7, 'confirmation_id' => $id]);
        $this->assertSame('confirmation_rejected', $r['error']);
        $this->assertSame(410, $r['statusCode']);
        $this->assertSame(0, confirm_fixture_module::$runs);
        $names = array_map(static fn($e) => $e->eventname, $sink->get_events());
        $this->assertContains('\\local_aiconnect\\event\\ai_action_rejected', $names);
        $sink->close();
    }

    public function test_expired(): void {
        global $DB;
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        $DB->set_field('local_aiconnect_pending', 'expiresat', time() - 1, ['confid' => $id]);
        $out = confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $this->assertFalse($out['ok']);
        $r = $this->call(['thingid' => 7, 'confirmation_id' => $id]);
        $this->assertSame('confirmation_expired', $r['error']);
        $this->assertSame('expired', $DB->get_field('local_aiconnect_pending', 'status', ['confid' => $id]));
        $this->assertSame(0, confirm_fixture_module::$runs);
    }

    public function test_read_scope_and_guest_never_reach_the_tool(): void {
        $r = $this->registry->execute(
            'cfix.deleteThing',
            ['thingid' => 7],
            ['userid' => (int) $this->user->id, 'scope' => 'read']
        );
        $this->assertSame('insufficient_scope', $r['error']);
        $r = $this->registry->execute('cfix.deleteThing', ['thingid' => 7], ['userid' => 0, 'scope' => 'read write']);
        $this->assertSame('confirmation_unavailable', $r['error']);
        // A read tool is never gated, even if it declares confirmation.
        $r = $this->call([], 'cfix.readThing');
        $this->assertTrue($r['read']);
        $this->assertSame(0, confirm_fixture_module::$runs);
    }

    public function test_conditional(): void {
        $r = $this->call(['thingid' => 1], 'cfix.maybeDelete');
        $this->assertTrue($r['ok']);
        $r = $this->call(['thingid' => 1, 'hard' => true], 'cfix.maybeDelete');
        $this->assertSame('confirmation_required', $r['error']);
        $this->assertSame('Hard delete 1', $r['envelope']['summary']);
        $this->assertSame(1, confirm_fixture_module::$runs);
    }

    public function test_blocked_user_cannot_execute_after_confirming(): void {
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        \local_aiconnect\service\user_blocking::block_user((int) $this->user->id, 2);
        $out = confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $this->assertFalse($out['ok']);
        $this->assertSame('failed', $out['status']);
        $this->assertSame(0, confirm_fixture_module::$runs);
    }

    public function test_autonomy_grant(): void {
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        confirmation::confirm($id, (int) $this->user->id, true, $this->registry);
        $this->assertSame(1, confirm_fixture_module::$runs);
        // Same client, other arguments: runs without asking.
        $r = $this->call(['thingid' => 8]);
        $this->assertSame(8, $r['deleted']);
        $this->assertSame(2, confirm_fixture_module::$runs);
        // Another client still has to ask.
        $r = $this->call(['thingid' => 8], 'cfix.deleteThing', 'chatgpt');
        $this->assertSame('confirmation_required', $r['error']);
        // Site switch off → asks again.
        set_config('confirmation_autonomy', 0, 'local_aiconnect');
        $r = $this->call(['thingid' => 9]);
        $this->assertSame('confirmation_required', $r['error']);
        set_config('confirmation_autonomy', 1, 'local_aiconnect');
        // Revoked grant → asks again.
        $grants = confirmation::list_grants((int) $this->user->id);
        $this->assertCount(1, $grants);
        $other = $this->getDataGenerator()->create_user();
        $this->assertFalse(confirmation::revoke_grant((int) $grants[0]->id, (int) $other->id));
        $this->assertTrue(confirmation::revoke_grant((int) $grants[0]->id, (int) $this->user->id));
        $r = $this->call(['thingid' => 10]);
        $this->assertSame('confirmation_required', $r['error']);
        $this->assertSame(2, confirm_fixture_module::$runs);
    }

    public function test_hebrew_user_message(): void {
        global $CFG, $DB;
        // Simulate an installed Hebrew language pack (sites with Hebrew users have it).
        make_writable_directory($CFG->langotherroot . '/he');
        file_put_contents(
            $CFG->langotherroot . '/he/langconfig.php',
            "<?php\n\$string['thislanguage'] = 'עברית';\n\$string['thislanguageint'] = 'Hebrew';\n"
            . "\$string['parentlanguage'] = '';\n"
        );
        get_string_manager()->reset_caches();
        $DB->set_field('user', 'lang', 'he', ['id' => $this->user->id]);
        $this->assertSame('he', confirmation::user_lang((int) $this->user->id));

        $env = $this->call(['thingid' => 7])['envelope'];
        $this->assertStringContainsString('עדיין לא בוצע כלום', $env['user_message']);
        $this->assertSame('he', $DB->get_field('local_aiconnect_pending', 'lang', ['confid' => $env['confirmation_id']]));
        // Generic sentence (no preview summary, no template) is in Hebrew too.
        $prev = force_current_language('he');
        $generic = confirmation::resolve_summary(['summary' => ''], 'x.y', [], []);
        force_current_language($prev);
        $this->assertSame('ביצוע הפעולה "x.y"', $generic);
    }

    public function test_manifest_declares_the_flow(): void {
        $m = (new manifest_controller($this->registry))->get();
        $tools = [];
        foreach ($m['tools'] as $t) {
            $tools[$t['name']] = $t;
        }
        $this->assertSame('required', $tools['cfix.deleteThing']['confirmation']);
        $this->assertSame('destructive', $tools['cfix.deleteThing']['risk']);
        $this->assertSame('conditional', $tools['cfix.maybeDelete']['confirmation']);
        $this->assertArrayNotHasKey('confirmation', $tools['cfix.readThing']);
        $props = (array) $tools['cfix.deleteThing']['input_schema']['properties'];
        $this->assertArrayNotHasKey('confirm', $props);
        $this->assertSame(['thingid'], $tools['cfix.deleteThing']['input_schema']['required']);
        $this->assertTrue($m['capabilities']['confirmation']);
        $this->assertSame('user-confirm-v1', $m['confirmation']['flow']);
        $this->assertSame(202, $m['confirmation']['pending_status_code']);
        $this->assertContains('cfix.deleteThing', $m['confirmation']['tools']);
        $this->assertNotEmpty($m['instructions']);
    }

    public function test_status_for_owner_only(): void {
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        $other = $this->getDataGenerator()->create_user();
        $this->assertNull(confirmation::status_for($id, (int) $other->id));
        $s = confirmation::status_for($id, (int) $this->user->id);
        $this->assertSame('pending', $s['status']);
        $this->assertArrayNotHasKey('result', $s);
        confirmation::confirm($id, (int) $this->user->id, false, $this->registry);
        $s = confirmation::status_for($id, (int) $this->user->id);
        $this->assertSame('executed', $s['status']);
        $this->assertSame(7, $s['result']['deleted']);
    }

    public function test_privacy_delete_and_cleanup(): void {
        global $DB;
        $id = $this->call(['thingid' => 7])['envelope']['confirmation_id'];
        confirmation::confirm($id, (int) $this->user->id, true, $this->registry);
        $this->assertGreaterThan(0, $DB->count_records('local_aiconnect_conflog', ['userid' => $this->user->id]));
        $contexts = \local_aiconnect\privacy\provider::get_contexts_for_userid((int) $this->user->id);
        $this->assertContains((int) \context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $list = new \core_privacy\local\request\approved_contextlist(
            $this->user,
            'local_aiconnect',
            [\context_system::instance()->id]
        );
        \local_aiconnect\privacy\provider::delete_data_for_user($list);
        $this->assertSame(0, $DB->count_records('local_aiconnect_pending', ['userid' => $this->user->id]));
        $this->assertSame(0, $DB->count_records('local_aiconnect_autonomy', ['userid' => $this->user->id]));
        $this->assertSame(0, $DB->count_records('local_aiconnect_conflog', ['userid' => $this->user->id]));

        // Cleanup drops decided rows older than 30 days, keeps fresh pending ones.
        $old = $this->call(['thingid' => 1])['envelope']['confirmation_id'];
        confirmation::reject($old, (int) $this->user->id);
        $DB->set_field('local_aiconnect_pending', 'timecreated', time() - 40 * DAYSECS, ['confid' => $old]);
        $fresh = $this->call(['thingid' => 2])['envelope']['confirmation_id'];
        confirmation::cleanup();
        $this->assertFalse($DB->record_exists('local_aiconnect_pending', ['confid' => $old]));
        $this->assertTrue($DB->record_exists('local_aiconnect_pending', ['confid' => $fresh]));
    }
}
