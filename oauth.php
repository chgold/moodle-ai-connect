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
 * OAuth2 authorization, token, revoke and helper endpoints.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/bootstrap.php');

use local_aiconnect\controller\oauth_controller;
use local_aiconnect\lib\http;
use local_aiconnect\middleware\auth_middleware;

http::send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $action = required_param('action', PARAM_ALPHANUMEXT);

    // Per-IP rate limit on OAuth endpoints — token and revoke get a higher
    // budget: clients legitimately retry token exchanges, and RFC 7009 callers
    // (e.g. goldnat) revoke refresh + access from one server IP per disconnect.
    $highbudget = in_array($action, ['token', 'revoke'], true);
    $perminute = $highbudget ? 20 : 10;
    $perhour   = $highbudget ? 200 : 60;
    auth_middleware::check_ip_rate_limit('oauth_' . $action, $perminute, $perhour);

    $ctrl   = new oauth_controller();

    switch ($action) {
        case 'authorize':
            $ctrl->authorize();
            break;
        case 'token':
            header('Content-Type: application/json; charset=utf-8');
            $ctrl->token();
            break;
        case 'revoke':
            header('Content-Type: application/json; charset=utf-8');
            $ctrl->revoke();
            break;
        case 'generate_token':
            header('Content-Type: application/json; charset=utf-8');
            $ctrl->generate_token();
            break;
        default:
            http::oauth_error(400, 'unsupported_action');
    }
} catch (\moodle_exception $e) {
    http::oauth_error(400, 'invalid_request', $e->getMessage());
} catch (\Throwable $e) {
    debugging('local_aiconnect oauth error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http::oauth_error(500, 'server_error', 'Internal server error');
}
