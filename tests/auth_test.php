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
 * Tests for bearer authentication via token lookup.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_aiconnect\middleware\auth_middleware
 */
final class auth_test extends \advanced_testcase {

    /**
     * Insert a token row and assert it is fetched back as the active access token.
     */
    public function test_active_token_lookup(): void {
        global $DB;
        $this->resetAfterTest();

        $token = 'mdl_' . bin2hex(random_bytes(8));
        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token'       => $token,
            'tokentype'   => 'access',
            'client_id'   => 'ai-agent-default',
            'userid'      => 42,
            'scope'       => 'read',
            'expiry'      => time() + 3600,
            'revoked'     => 0,
            'timecreated' => time(),
        ]);

        $rec = $DB->get_record('local_aiconnect_tokens',
            ['token' => $token, 'tokentype' => 'access', 'revoked' => 0]);
        $this->assertNotFalse($rec);
        $this->assertSame(42, (int) $rec->userid);
    }

    /**
     * Revoked tokens are not picked up by the lookup query.
     */
    public function test_revoked_token_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();

        $token = 'mdl_' . bin2hex(random_bytes(8));
        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token'       => $token,
            'tokentype'   => 'access',
            'client_id'   => 'ai-agent-default',
            'userid'      => 42,
            'scope'       => 'read',
            'expiry'      => time() + 3600,
            'revoked'     => 1,
            'timecreated' => time(),
        ]);

        $rec = $DB->get_record('local_aiconnect_tokens',
            ['token' => $token, 'tokentype' => 'access', 'revoked' => 0]);
        $this->assertFalse($rec);
    }

    /**
     * Expired tokens fail the time comparison.
     */
    public function test_expired_token_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();

        $token = 'mdl_' . bin2hex(random_bytes(8));
        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token'       => $token,
            'tokentype'   => 'access',
            'client_id'   => 'ai-agent-default',
            'userid'      => 42,
            'scope'       => 'read',
            'expiry'      => time() - 60,
            'revoked'     => 0,
            'timecreated' => time() - 7200,
        ]);

        $rec = $DB->get_record('local_aiconnect_tokens',
            ['token' => $token, 'tokentype' => 'access', 'revoked' => 0]);
        $this->assertTrue(time() > (int) $rec->expiry);
    }
}
