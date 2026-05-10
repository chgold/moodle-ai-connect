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
 * Rewrite shim for `/local/aiconnect/v1/tools/{tool}` URLs.
 *
 * Apache rewrites them here; we forward to ../tools.php with the tool name
 * extracted from the path so the JSON contract stays the same.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#/v1/tools/([A-Za-z0-9_.\-]+)#', $path, $m)) {
    $_GET['tool'] = $m[1];
    $_POST['tool'] = $m[1];
    $_REQUEST['tool'] = $m[1];
}

require __DIR__ . '/../tools.php';
