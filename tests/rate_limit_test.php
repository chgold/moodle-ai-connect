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
 * Tests for the rate limiter and cleanup task.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect;

use local_aiconnect\task\cleanup_expired_tokens;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_aiconnect\middleware\auth_middleware
 * @covers \local_aiconnect\task\cleanup_expired_tokens
 */
final class rate_limit_test extends \advanced_testcase {

    /**
     * Rate-limit rows are stored with a unique (token, windowtype) pair.
     */
    public function test_rate_limit_row_unique_per_window(): void {
        global $DB;
        $this->resetAfterTest();

        $token = 'mdl_rate_' . bin2hex(random_bytes(4));
        $now = time();
        $DB->insert_record('local_aiconnect_ratelimits', (object) [
            'token' => $token, 'windowtype' => 'minute',
            'windowstart' => $now - ($now % 60), 'requestcount' => 5,
        ]);
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_aiconnect_ratelimits', (object) [
            'token' => $token, 'windowtype' => 'minute',
            'windowstart' => $now - ($now % 60), 'requestcount' => 1,
        ]);
    }

    /**
     * Cleanup task removes tokens older than the grace window.
     */
    public function test_cleanup_removes_expired_tokens(): void {
        global $DB;
        $this->resetAfterTest();

        $stale = time() - 30 * DAYSECS;
        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token' => 'mdl_old_' . bin2hex(random_bytes(4)),
            'tokentype' => 'access', 'client_id' => 'c', 'userid' => 1,
            'scope' => 'read', 'expiry' => $stale, 'revoked' => 0, 'timecreated' => $stale,
        ]);
        $DB->insert_record('local_aiconnect_tokens', (object) [
            'token' => 'mdl_fresh_' . bin2hex(random_bytes(4)),
            'tokentype' => 'access', 'client_id' => 'c', 'userid' => 1,
            'scope' => 'read', 'expiry' => time() + HOURSECS, 'revoked' => 0, 'timecreated' => time(),
        ]);

        $before = $DB->count_records('local_aiconnect_tokens');
        (new cleanup_expired_tokens())->execute();
        $after = $DB->count_records('local_aiconnect_tokens');

        $this->assertLessThan($before, $after);
    }

    /**
     * Cleanup also removes used or expired authorization codes.
     */
    public function test_cleanup_removes_used_codes(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->insert_record('local_aiconnect_codes', (object) [
            'code' => 'used-code-' . bin2hex(random_bytes(4)),
            'client_id' => 'c', 'userid' => 1, 'scope' => 'read',
            'code_challenge' => '', 'redirect_uri' => '',
            'expiry' => time() + 600, 'used' => 1,
        ]);

        (new cleanup_expired_tokens())->execute();

        $this->assertSame(0, $DB->count_records('local_aiconnect_codes', ['used' => 1]));
    }
}
