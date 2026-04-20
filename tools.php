<?php
define('NO_MOODLE_COOKIES', true);
require_once('/workspace/platforms/moodle/config.php');
require_once(__DIR__ . '/classes/middleware/auth_middleware.php');
require_once(__DIR__ . '/classes/controller/tools_controller.php');
require_once(__DIR__ . '/classes/controller/manifest_controller.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); exit; }

$auth   = local_aiconnect\middleware\auth_middleware::require_bearer();
local_aiconnect\middleware\auth_middleware::check_rate_limit($auth['token']);

$tool   = required_param('tool', PARAM_RAW);
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$ctrl   = new local_aiconnect\controller\tools_controller();
$result = $ctrl->execute($tool, $body, $auth['userid'], $auth['scope']);

if (isset($result['error'])) {
    http_response_code($result['error'] === 'tool_not_found' ? 404 : 400);
}
echo json_encode(['success' => !isset($result['error']), 'data' => $result]);
