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
 * Post-install hook for local_aiconnect.
 *
 * Schema is now defined declaratively in db/install.xml — this file only
 * seeds the default set of registered AI agent clients (Claude, ChatGPT, etc.)
 * so the OAuth authorize endpoint works out of the box.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Seed default OAuth2 clients (one per known AI agent).
 *
 * @return bool Always true.
 */
function xmldb_local_aiconnect_install(): bool {
    global $DB;

    $agents = [
        'claude', 'chatgpt', 'gemini', 'grok', 'copilot',
        'perplexity', 'metaai', 'deepseek', 'ai-agent-default',
    ];

    $now = time();
    foreach ($agents as $agent) {
        if ($DB->record_exists('local_aiconnect_clients', ['client_id' => $agent])) {
            continue;
        }
        $DB->insert_record('local_aiconnect_clients', (object) [
            'client_id'     => $agent,
            'client_name'   => $agent,
            'redirect_uris' => 'urn:ietf:wg:oauth:2.0:oob',
            'scopes'        => 'read write delete',
            'timecreated'   => $now,
        ]);
    }

    return true;
}
