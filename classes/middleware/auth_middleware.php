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
 * Bearer-token authentication + rate-limit middleware.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\middleware;

use local_aiconnect\lib\http;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP middleware for tool endpoints.
 */
class auth_middleware {

    /** @var int Default per-minute rate limit. */
    private const DEFAULT_MINUTE_LIMIT = 50;

    /** @var int Default per-hour rate limit. */
    private const DEFAULT_HOUR_LIMIT = 1000;

    /**
     * Require a valid `Authorization: Bearer <token>` header.
     *
     * @return array{userid:int,scope:string,token:string} Caller identity.
     */
    public static function require_bearer(): array {
        global $DB;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            header('WWW-Authenticate: Bearer realm="AI Connect"');
            http::json_exit(401, ['error' => 'unauthorized', 'message' => 'Bearer token required']);
        }

        $token = trim(substr($header, 7));
        $rec = $DB->get_record('local_aiconnect_tokens', [
            'token' => $token, 'tokentype' => 'access', 'revoked' => 0,
        ]);

        if (!$rec || time() > (int) $rec->expiry) {
            http::json_exit(401, ['error' => 'invalid_token', 'message' => 'Token expired or revoked']);
        }

        return [
            'userid' => (int) $rec->userid,
            'scope'  => (string) $rec->scope,
            'token'  => $token,
        ];
    }

    /**
     * Enforce per-token rate limits for the current minute and hour windows.
     *
     * @param string $token Bearer token of the caller.
     */
    public static function check_rate_limit(string $token): void {
        global $DB;

        $perminute = (int) (get_config('local_aiconnect', 'ratelimit_minute') ?: self::DEFAULT_MINUTE_LIMIT);
        $perhour   = (int) (get_config('local_aiconnect', 'ratelimit_hour') ?: self::DEFAULT_HOUR_LIMIT);
        $now = time();

        $windows = [
            ['minute', $now - ($now % 60),   $perminute],
            ['hour',   $now - ($now % 3600), $perhour],
        ];

        foreach ($windows as [$windowtype, $windowstart, $limit]) {
            $rec = $DB->get_record('local_aiconnect_ratelimits', [
                'token' => $token, 'windowtype' => $windowtype,
            ]);

            if ($rec && (int) $rec->windowstart === $windowstart) {
                if ((int) $rec->requestcount >= $limit) {
                    http::json_exit(429, [
                        'error'  => 'rate_limit_exceeded',
                        'window' => $windowtype,
                        'limit'  => $limit,
                    ]);
                }
                $DB->set_field('local_aiconnect_ratelimits', 'requestcount',
                    (int) $rec->requestcount + 1, ['id' => $rec->id]);
                continue;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->delete_records('local_aiconnect_ratelimits', [
                    'token' => $token, 'windowtype' => $windowtype,
                ]);
                $DB->insert_record('local_aiconnect_ratelimits', (object) [
                    'token'        => $token,
                    'windowtype'   => $windowtype,
                    'windowstart'  => $windowstart,
                    'requestcount' => 1,
                ]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e instanceof \Exception ? $e : new \Exception($e->getMessage()));
            }
        }
    }
}
