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
 * Tests for the OAuth redirect_uri rules (2.2.8).
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\lib\redirect_uri;

/**
 * @covers \local_aiconnect\lib\redirect_uri
 */
final class redirect_uri_test extends \basic_testcase {

    /**
     * Registered lists are read from JSON, plain values and separated lists.
     */
    public function test_registered_list_formats(): void {
        $this->assertSame([redirect_uri::OOB], redirect_uri::registered_list('urn:ietf:wg:oauth:2.0:oob'));
        $this->assertSame(['https://a.example/cb', redirect_uri::OOB],
            redirect_uri::registered_list('["https://a.example/cb","urn:ietf:wg:oauth:2.0:oob"]'));
        $this->assertSame(['https://a.example/cb', 'https://b.example/cb'],
            redirect_uri::registered_list("https://a.example/cb\nhttps://b.example/cb"));
        $this->assertSame([], redirect_uri::registered_list(''));
        $this->assertSame([], redirect_uri::registered_list(null));
    }

    /**
     * Only exact registered URIs are accepted; an attacker address is refused.
     */
    public function test_exact_match_only(): void {
        $reg = ['https://agent.example/cb'];
        $this->assertTrue(redirect_uri::is_registered('https://agent.example/cb', $reg));
        $this->assertFalse(redirect_uri::is_registered('https://evil.example/cb', $reg));
        $this->assertFalse(redirect_uri::is_registered('https://agent.example/cb/', $reg));
        $this->assertFalse(redirect_uri::is_registered('https://agent.example/cb?x=1', $reg));
        $this->assertFalse(redirect_uri::is_registered('https://agent.example.evil.example/cb', $reg));
        $this->assertFalse(redirect_uri::is_registered('HTTPS://agent.example/cb', $reg));
        $this->assertFalse(redirect_uri::is_registered('', $reg), 'empty = OOB, not registered here');
    }

    /**
     * The out-of-band flow works only when OOB is registered (the default clients).
     */
    public function test_oob(): void {
        $reg = [redirect_uri::OOB];
        $this->assertTrue(redirect_uri::is_registered(redirect_uri::OOB, $reg));
        $this->assertTrue(redirect_uri::is_registered('', $reg));
        $this->assertFalse(redirect_uri::is_registered('https://evil.example/cb', $reg));
    }

    /**
     * Loopback (native app) URIs match on any port, nothing else differs.
     */
    public function test_loopback_port_exception(): void {
        $reg = ['http://127.0.0.1/callback', 'http://localhost/cb'];
        $this->assertTrue(redirect_uri::is_registered('http://127.0.0.1:53682/callback', $reg));
        $this->assertTrue(redirect_uri::is_registered('http://localhost:8765/cb', $reg));
        $this->assertFalse(redirect_uri::is_registered('http://127.0.0.1:53682/other', $reg));
        $this->assertFalse(redirect_uri::is_registered('http://127.0.0.2:53682/callback', $reg));
        $this->assertFalse(redirect_uri::is_registered('https://127.0.0.1:53682/callback', $reg));
        $this->assertFalse(redirect_uri::is_registered('http://user@127.0.0.1:1/callback', $reg));
        // No port wildcard for non-loopback hosts.
        $this->assertFalse(redirect_uri::is_registered('https://agent.example:8443/cb', ['https://agent.example/cb']));
    }

    /**
     * The token endpoint requires the same redirect_uri as the authorization request.
     */
    public function test_token_matches(): void {
        $this->assertTrue(redirect_uri::token_matches('https://agent.example/cb', 'https://agent.example/cb'));
        $this->assertFalse(redirect_uri::token_matches('https://agent.example/cb', 'https://evil.example/cb'));
        $this->assertFalse(redirect_uri::token_matches('https://agent.example/cb', null));
        $this->assertFalse(redirect_uri::token_matches('https://agent.example/cb', ''));
        $this->assertTrue(redirect_uri::token_matches('', null), 'OOB code may omit redirect_uri');
        $this->assertTrue(redirect_uri::token_matches('', redirect_uri::OOB));
        $this->assertTrue(redirect_uri::token_matches(redirect_uri::OOB, ''));
        $this->assertFalse(redirect_uri::token_matches(redirect_uri::OOB, 'https://agent.example/cb'));
    }
}
