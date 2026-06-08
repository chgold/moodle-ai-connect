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
 * WebMCP tool execution endpoint.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);

// Early auth gate — must run before Moodle init to prevent redirect-to-login
(function () {
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (strncmp($auth_header, 'Bearer ', 7) !== 0) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('WWW-Authenticate: Bearer realm="AI Connect"');
        echo json_encode(['error' => 'unauthorized', 'message' => 'Bearer token required']);
        exit;
    }
})();

require(__DIR__ . '/bootstrap.php');

use local_aiconnect\controller\tools_controller;
use local_aiconnect\lib\http;
use local_aiconnect\middleware\auth_middleware;

http::send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST' && $method !== 'GET') {
    http::json_exit(405, ['error' => 'method_not_allowed']);
}

try {
    $auth = auth_middleware::require_bearer();
    auth_middleware::check_rate_limit($auth['token']);

    $tool = required_param('tool', PARAM_RAW_TRIMMED);
    $controller = new tools_controller();

    if ($method === 'GET') {
        // Parse arguments from the query string.
        // Priority: ?arguments={"key":"value"} (JSON blob) → individual ?key=value params.
        $rawargs = isset($_GET['arguments']) ? (string) $_GET['arguments'] : null;
        if ($rawargs !== null && $rawargs !== '') {
            $body = json_decode($rawargs, true);
            if (!is_array($body)) {
                http::json_exit(400, ['error' => 'invalid_body',
                    'message' => 'The "arguments" query parameter must be a JSON object']);
            }
        } else {
            // Fall back to individual GET params, excluding reserved keys.
            $body = array_diff_key($_GET, array_flip(['tool', 'arguments']));
        }

        // Only read-scope tools are accessible via GET; reject anything wider.
        $toolscope = $controller->get_tool_scope($tool);
        if ($toolscope !== null && $toolscope !== 'read') {
            http::json_exit(405, ['error' => 'method_not_allowed',
                'message' => 'Write/admin tools must be called via POST']);
        }
    } else {
        // POST: parse from request body JSON.
        $rawbody = file_get_contents('php://input') ?: '';
        $body = $rawbody === '' ? [] : (json_decode($rawbody, true) ?? []);
        if (!is_array($body)) {
            http::json_exit(400, ['error' => 'invalid_body', 'message' => 'Request body must be a JSON object']);
        }
    }

    $result = $controller->execute($tool, $body, $auth['userid'], $auth['scope']);

    if (isset($result['error'])) {
        $status = match ($result['error']) {
            'tool_not_found'      => 404,
            'insufficient_scope'  => 403,
            'missing_param'       => 400,
            default               => 400,
        };
        http_response_code($status);
    }

    echo json_encode(['success' => !isset($result['error']), 'data' => $result], JSON_UNESCAPED_SLASHES);
} catch (\moodle_exception $e) {
    http::json_exit(400, ['error' => 'invalid_request', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    debugging('local_aiconnect tools error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http::json_exit(500, ['error' => 'server_error', 'message' => 'Internal server error']);
}
