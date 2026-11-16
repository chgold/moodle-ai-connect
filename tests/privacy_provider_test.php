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

namespace local_aiconnect;

use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_aiconnect\privacy\provider;

/**
 * Privacy provider tests for local_aiconnect.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aiconnect\privacy\provider
 */
final class privacy_provider_test extends provider_testcase {
    /**
     * Seed tokens, a registry row and a block record for a user.
     *
     * @param int $userid Owner.
     * @param int|null $adminid Admin who revoked/blocked.
     */
    private function seed(int $userid, ?int $adminid = null): void {
        global $DB;
        $now = time();
        $token = 'mdc_test_' . $userid . '_' . random_string(20);
        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token' => $token, 'tokentype' => 'access', 'client_id' => 'claude', 'userid' => $userid,
            'scope' => 'read', 'expiry' => $now + 3600, 'revoked' => 0, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_aiconnect_ratelimits', (object) [
            'token' => $token, 'windowtype' => 'minute', 'windowstart' => $now, 'requestcount' => 3,
        ]);
        $DB->insert_record('local_aiconnect_codes', (object) [
            'code' => 'code_' . $userid . random_string(10), 'client_id' => 'claude', 'userid' => $userid,
            'scope' => 'read', 'expiry' => $now + 600, 'used' => 0,
        ]);
        $DB->insert_record('local_aiconnect_token_registry', (object) [
            'token_prefix' => substr($token, 0, 16), 'user_id' => $userid, 'client_id' => 'claude',
            'scope' => 'read', 'issued_at' => $now, 'expires_at' => $now + 3600, 'source' => 'oauth',
            'ip_address' => '203.0.113.7', 'last_used_ip' => '203.0.113.8', 'last_used_ua' => 'TestAgent/1.0',
            'last_used_at' => $now, 'revoked_at' => $adminid ? $now : null, 'revoked_by' => $adminid,
        ]);
        $DB->insert_record('local_aiconnect_blocked', (object) [
            'userid' => $userid, 'blockedby' => $adminid, 'timecreated' => $now,
        ]);
    }

    public function test_get_metadata_declares_registry_and_blocked(): void {
        $items = provider::get_metadata(new collection('local_aiconnect'))->get_collection();
        $names = array_map(fn($i) => $i->get_name(), $items);
        $this->assertContains('local_aiconnect_token_registry', $names);
        $this->assertContains('local_aiconnect_blocked', $names);
        foreach ($items as $item) {
            if ($item->get_name() === 'local_aiconnect_token_registry') {
                $fields = array_keys($item->get_privacy_fields());
                foreach (['user_id', 'ip_address', 'last_used_ip', 'last_used_ua', 'revoked_by'] as $f) {
                    $this->assertContains($f, $fields);
                }
            }
        }
    }

    public function test_contexts_users_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $admin = $this->getDataGenerator()->create_user();
        $this->seed($user->id, $admin->id);
        $this->seed($other->id, $admin->id);
        $sys = context_system::instance();

        // The admin appears only through revoked_by / blockedby.
        $this->assertEquals([$sys->id], provider::get_contexts_for_userid($admin->id)->get_contextids());
        $ul = new userlist($sys, 'local_aiconnect');
        provider::get_users_in_context($ul);
        $ids = $ul->get_userids();
        foreach ([$user->id, $other->id, $admin->id] as $id) {
            $this->assertContains((int) $id, array_map('intval', $ids));
        }

        // Export: registry incl. IP/UA, block status; never the token prefix.
        provider::export_user_data(new approved_contextlist($user, 'local_aiconnect', [$sys->id]));
        $w = writer::with_context($sys);
        $reg = $w->get_data(['local_aiconnect', 'token_registry']);
        $this->assertEquals('203.0.113.8', $reg->tokens[0]->last_used_ip);
        $this->assertEquals('TestAgent/1.0', $reg->tokens[0]->last_used_ua);
        $this->assertObjectNotHasProperty('token_prefix', $reg->tokens[0]);
        $this->assertNotEmpty($w->get_data(['local_aiconnect', 'blocked']));

        // Delete one user: their rows go, the other user's stay.
        provider::delete_data_for_user(new approved_contextlist($user, 'local_aiconnect', [$sys->id]));
        foreach (['local_aiconnect_tokens' => 'userid', 'local_aiconnect_codes' => 'userid',
                  'local_aiconnect_token_registry' => 'user_id', 'local_aiconnect_blocked' => 'userid'] as $t => $col) {
            $this->assertEquals(0, $DB->count_records($t, [$col => $user->id]), $t);
            $this->assertEquals(1, $DB->count_records($t, [$col => $other->id]), $t);
        }
        $this->assertEquals(1, $DB->count_records('local_aiconnect_ratelimits'));

        // Delete the admin via userlist: actions are anonymised, other user's rows kept.
        provider::delete_data_for_users(new approved_userlist($sys, 'local_aiconnect', [$admin->id]));
        $this->assertEquals(0, $DB->count_records('local_aiconnect_token_registry', ['revoked_by' => $admin->id]));
        $this->assertEquals(0, $DB->count_records('local_aiconnect_blocked', ['blockedby' => $admin->id]));
        $this->assertEquals(1, $DB->count_records('local_aiconnect_blocked', ['userid' => $other->id]));

        // Delete everyone.
        provider::delete_data_for_all_users_in_context($sys);
        $this->assertEquals(0, $DB->count_records('local_aiconnect_token_registry'));
        $this->assertEquals(0, $DB->count_records('local_aiconnect_blocked'));
    }
}
