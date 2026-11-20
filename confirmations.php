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
 * Status of a pending AI action (user-confirm-v1 status_url).
 *
 * GET /local/aiconnect/confirmations.php?id=conf_… with the Bearer token of the
 * same Moodle user. Reports the status (and, once, the result); never executes.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);

// Early auth gate — must run before Moodle init to prevent redirect-to-login.
(function () {
    $authheader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (strncmp($authheader, 'Bearer ', 7) !== 0) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('WWW-Authenticate: Bearer realm="AI Connect"');
        echo json_encode(['error' => 'unauthorized', 'message' => 'Bearer token required']);
        exit;
    }
})();

require(__DIR__ . '/bootstrap.php');

use local_aiconnect\lib\http;
use local_aiconnect\local\confirmation;
use local_aiconnect\middleware\auth_middleware;

http::send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    http::json_exit(405, ['error' => 'method_not_allowed']);
}

try {
    $auth = auth_middleware::require_bearer();
    auth_middleware::check_rate_limit($auth['token']);

    $id = optional_param('id', '', PARAM_ALPHANUMEXT);
    $status = $id === '' ? null : confirmation::status_for($id, (int) $auth['userid']);
    if ($status === null) {
        http::json_exit(404, ['error' => 'confirmation_not_found', 'message' => 'Unknown confirmation_id for this user.']);
    }
    echo json_encode(['success' => true, 'data' => $status], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\moodle_exception $e) {
    http::json_exit(400, ['error' => 'invalid_request', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    debugging('local_aiconnect confirmations error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http::json_exit(500, ['error' => 'server_error', 'message' => 'Internal server error']);
}
