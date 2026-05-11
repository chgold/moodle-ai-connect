#!/usr/bin/env php
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
 * Build script — packages the plugin into a Moodle-installable ZIP.
 *
 * Output: dist/moodle-ai-connect-<release>.zip with files rooted at
 * `local/aiconnect/...` so it can be unzipped directly into a Moodle
 * codebase or uploaded via Site administration > Plugins > Install plugins.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$source = realpath(__DIR__ . '/..');
$dist   = $source . '/dist';

if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
    fwrite(STDERR, "Failed to create dist directory: $dist\n");
    exit(1);
}

$version = '1.2.0';
$pluginfile = $source . '/version.php';
if (is_readable($pluginfile)) {
    $contents = (string) file_get_contents($pluginfile);
    if (preg_match("/\\\$plugin->release\\s*=\\s*'([^']+)'/", $contents, $m)) {
        $version = $m[1];
    }
}

$outfile = "$dist/moodle-ai-connect-$version.zip";
if (file_exists($outfile)) {
    unlink($outfile);
}

$zip = new ZipArchive();
if ($zip->open($outfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot open zip file for writing: $outfile\n");
    exit(1);
}

$excluded = ['/.git/', '/dist/', '/vendor/', '/node_modules/', '/.idea/', '/.vscode/'];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added = 0;
foreach ($files as $file) {
    if ($file->isDir()) {
        continue;
    }
    $path = $file->getPathname();
    $skip = false;
    foreach ($excluded as $ex) {
        if (strpos($path, $ex) !== false) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }
    $rel = substr($path, strlen($source) + 1);
    $zip->addFile($path, 'local/aiconnect/' . $rel);
    $added++;
}

$zip->close();
echo "Built: $outfile ($added files)\n";
