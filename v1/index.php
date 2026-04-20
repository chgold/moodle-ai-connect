<?php
$tool = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$tool = preg_replace('#.*/v1/tools/#', '', $tool);
if ($tool) {
    $_GET['tool'] = $tool;
    $_REQUEST['tool'] = $tool;
}
require_once '/workspace/plugins/moodle/moodle-ai-connect/tools.php';
