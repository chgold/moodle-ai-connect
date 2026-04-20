<?php
define('NO_MOODLE_COOKIES', true);
require_once('/workspace/platforms/moodle/config.php');
require_once('/workspace/platforms/moodle/local/aiconnect/classes/controller/manifest_controller.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$controller = new local_aiconnect\controller\manifest_controller();
echo json_encode($controller->get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
