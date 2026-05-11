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
 * Admin token-registry list page.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_aiconnect\admin\token_list;

admin_externalpage_setup('local_aiconnect_tokens');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action   = optional_param('action', '', PARAM_ALPHA);
$id       = optional_param('id', 0, PARAM_INT);
$userid   = optional_param('userid', 0, PARAM_INT);
$clientid = optional_param('clientid', '', PARAM_ALPHANUMEXT);
$status   = optional_param('status', '', PARAM_ALPHA);
$page     = optional_param('page', 0, PARAM_INT);

if ($action === 'revoke' && $id > 0) {
    require_sesskey();
    token_list::revoke_by_id($id);
    redirect(new moodle_url('/local/aiconnect/admin/tokens.php', [
        'userid' => $userid ?: '', 'clientid' => $clientid, 'status' => $status, 'page' => $page,
    ]));
}

$PAGE->set_url('/local/aiconnect/admin/tokens.php');
$PAGE->set_title(get_string('admin_tokens_heading', 'local_aiconnect'));
$PAGE->set_heading(get_string('admin_tokens_heading', 'local_aiconnect'));

echo $OUTPUT->header();
echo token_list::render($userid ?: null, $clientid !== '' ? $clientid : null, $status !== '' ? $status : null, $page);
echo $OUTPUT->footer();
