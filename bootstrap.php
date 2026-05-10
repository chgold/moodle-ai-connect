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
 * Locate Moodle's config.php and bootstrap the runtime.
 *
 * Supports two installation modes:
 *  1. Copied into local/aiconnect — `__DIR__/../../config.php` works.
 *  2. Symlinked from a development checkout — `__DIR__` then points outside
 *     the Moodle tree, so we fall back to `$_SERVER['DOCUMENT_ROOT']`.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$localconfig = dirname(__DIR__, 2) . '/config.php';
if (is_file($localconfig)) {
    require_once($localconfig);
    return;
}

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($docroot !== '' && is_file($docroot . '/config.php')) {
    require_once($docroot . '/config.php');
    return;
}

if (php_sapi_name() === 'cli') {
    $cliroot = getenv('MOODLE_ROOT');
    if ($cliroot && is_file($cliroot . '/config.php')) {
        require_once($cliroot . '/config.php');
        return;
    }
}

http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'bootstrap_failure', 'message' => 'Cannot locate Moodle config.php']);
exit;
