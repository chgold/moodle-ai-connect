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
 * HTTP helpers — CORS, JSON responses, OAuth error envelopes.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiconnect\lib;

defined('MOODLE_INTERNAL') || die();

/**
 * Stateless HTTP helpers for the JSON endpoints.
 */
class http {
    /**
     * Apply CORS headers based on the configured whitelist.
     *
     * The Origin from the current request is matched (case-insensitive) against
     * the comma-separated list stored in `local_aiconnect/allowed_origins`. The
     * literal `*` allows any origin (use only for development).
     */
    public static function send_cors_headers(): void {
        $raw = (string) get_config('local_aiconnect', 'allowed_origins');
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array('*', $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
        } else if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Emit a JSON body and exit with the given status code.
     *
     * @param int   $status  HTTP status to send.
     * @param array $payload Body payload (will be JSON encoded).
     */
    public static function json_exit(int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Convenience helper for OAuth2 RFC 6749 error envelopes.
     *
     * @param int    $status      HTTP status code.
     * @param string $error       OAuth `error` field (e.g. `invalid_grant`).
     * @param string $description Optional human readable description.
     */
    public static function oauth_error(int $status, string $error, string $description = ''): void {
        $body = ['error' => $error];
        if ($description !== '') {
            $body['error_description'] = $description;
        }
        self::json_exit($status, $body);
    }

    /**
     * Validate that a redirect_uri uses an acceptable scheme.
     *
     * Allows http:// when Moodle itself runs on http (development), or when
     * the value is the canonical OOB (`urn:ietf:wg:oauth:2.0:oob`) placeholder.
     * Otherwise requires https://. The admin setting `enforce_https` always
     * enforces https://, even on http:// installations.
     *
     * @param string $uri Redirect URI to validate.
     * @return bool True when the URI is acceptable.
     */
    public static function is_redirect_uri_allowed(string $uri): bool {
        global $CFG;

        if ($uri === '' || $uri === 'urn:ietf:wg:oauth:2.0:oob') {
            return true;
        }

        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }

        $force = (int) get_config('local_aiconnect', 'enforce_https') === 1;
        if ($force) {
            return false;
        }

        return $scheme === 'http' && str_starts_with((string) $CFG->wwwroot, 'http://');
    }
}
