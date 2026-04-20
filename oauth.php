<?php
require_once('/workspace/platforms/moodle/config.php');
require_once(__DIR__ . '/classes/controller/oauth_controller.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = required_param('action', PARAM_ALPHA);
$ctrl   = new local_aiconnect\controller\oauth_controller();

match($action) {
    'authorize'      => $ctrl->authorize(),
    'token'          => $ctrl->token(),
    'revoke'         => $ctrl->revoke(),
    'generate_token' => $ctrl->generate_token(),
    default          => (function() { http_response_code(400); echo json_encode(['error' => 'unknown_action']); })(),
};
