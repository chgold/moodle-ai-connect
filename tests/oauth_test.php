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
 * Tests for the OAuth2 + PKCE controller.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\lib\http;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_aiconnect\controller\oauth_controller
 * @covers \local_aiconnect\lib\http
 */
final class oauth_test extends \advanced_testcase {

    /**
     * Default install seeds the canonical agent clients.
     */
    public function test_default_clients_seeded(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertTrue($DB->record_exists('local_aiconnect_clients', ['client_id' => 'claude']));
        $this->assertTrue($DB->record_exists('local_aiconnect_clients', ['client_id' => 'ai-agent-default']));
    }

    /**
     * Tokens table accepts new access tokens and enforces uniqueness on `token`.
     */
    public function test_token_storage_is_unique(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        $shared = [
            'tokentype'   => 'access',
            'client_id'   => 'ai-agent-default',
            'userid'      => 2,
            'scope'       => 'read',
            'expiry'      => $now + 3600,
            'revoked'     => 0,
            'timecreated' => $now,
        ];

        $DB->insert_record('local_aiconnect_tokens', (object) ($shared + ['token' => 'mdl_abc']));
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_aiconnect_tokens', (object) ($shared + ['token' => 'mdl_abc']));
    }

    /**
     * is_redirect_uri_allowed honours scheme rules.
     */
    public function test_redirect_uri_validation(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->assertTrue(http::is_redirect_uri_allowed('urn:ietf:wg:oauth:2.0:oob'));
        $this->assertTrue(http::is_redirect_uri_allowed('https://agent.example/cb'));

        set_config('enforce_https', 0, 'local_aiconnect');
        $CFG->wwwroot = 'http://localhost:8088';
        $this->assertTrue(http::is_redirect_uri_allowed('http://localhost:8088/cb'));

        set_config('enforce_https', 1, 'local_aiconnect');
        $this->assertFalse(http::is_redirect_uri_allowed('http://localhost:8088/cb'));
    }

    /**
     * PKCE: a correct S256 verifier verifies; a wrong one does not.
     */
    public function test_pkce_s256_round_trip(): void {
        $this->resetAfterTest();

        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $reflection = new \ReflectionClass(\local_aiconnect\controller\oauth_controller::class);
        $controller = $reflection->newInstance();
        $method = $reflection->getMethod('verify_pkce');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, $verifier, $challenge));
        $this->assertFalse($method->invoke($controller, $verifier . 'x', $challenge));
    }
}
