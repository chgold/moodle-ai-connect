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
 * Confirm and execute bulk token revocation for the logged-in user.
 *
 * Supports three revoke types:
 *   unused   — tokens with no last_used_at
 *   inactive — tokens not used in the last 30 days
 *   all      — all active tokens (requires double-confirm via ?confirm2=1)
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_aiconnect\service\token_registry;

require_login();

global $USER, $PAGE, $OUTPUT;

$userid   = optional_param('userid', (int) $USER->id, PARAM_INT);
$type     = optional_param('type', 'unused', PARAM_ALPHA);
$filter   = optional_param('filter', 'all', PARAM_ALPHA);
$confirm2 = optional_param('confirm2', 0, PARAM_INT);

// Permission check.
$issameuser = ((int) $userid === (int) $USER->id);
if (!$issameuser) {
    require_capability('moodle/site:config', context_system::instance());
}

// Validate type.
$validtypes = ['unused', 'inactive', 'all'];
if (!in_array($type, $validtypes, true)) {
    $type = 'unused';
}

$PAGE->set_url('/local/aiconnect/mytokens/revoke-all.php', [
    'userid' => $userid, 'type' => $type, 'filter' => $filter,
]);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_title(get_string('mytokens_revoke_all_confirm', 'local_aiconnect'));
$PAGE->set_heading(get_string('mytokens_revoke_all_confirm', 'local_aiconnect'));

// For the dangerous "all" type, require a second confirmation GET param.
$needsdoubleconfirm = ($type === 'all' && !$confirm2);

// POST = confirmed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $count = 0;
    switch ($type) {
        case 'unused':
            // Collect IDs of unused tokens for this user, then revoke them.
            $rows = token_registry::list_tokens_for_user($userid, 'unused');
            $ids = array_map(static fn($r) => (int) $r->id, $rows);
            $count = token_registry::revoke_by_ids($ids, (int) $USER->id);
            break;
        case 'inactive':
            $rows = token_registry::list_tokens_for_user($userid, 'inactive');
            $ids = array_map(static fn($r) => (int) $r->id, $rows);
            $count = token_registry::revoke_by_ids($ids, (int) $USER->id);
            break;
        case 'all':
            $count = token_registry::revoke_all_for_user($userid, (int) $USER->id);
            break;
    }

    $redirecturl = new moodle_url('/local/aiconnect/mytokens.php', [
        'userid' => $userid, 'filter' => $filter,
    ]);
    redirect($redirecturl, get_string('mytokens_revoked_bulk_ok', 'local_aiconnect', $count), null, \core\output\notification::NOTIFY_SUCCESS);
}

// GET = confirmation form.
echo $OUTPUT->header();

$heading = match($type) {
    'unused'   => get_string('mytokens_revoke_all_unused', 'local_aiconnect'),
    'inactive' => get_string('mytokens_revoke_all_inactive', 'local_aiconnect'),
    default    => get_string('mytokens_revoke_all', 'local_aiconnect'),
};
echo $OUTPUT->heading($heading);
echo $OUTPUT->box(html_writer::tag('p', s(get_string('mytokens_revoke_all_confirm', 'local_aiconnect'))));

// For "all" type — show intermediate double-confirm link.
if ($needsdoubleconfirm) {
    $confirmurl = new moodle_url('/local/aiconnect/mytokens/revoke-all.php', [
        'userid' => $userid, 'type' => $type, 'filter' => $filter, 'confirm2' => 1,
    ]);
    echo html_writer::tag('p',
        html_writer::link($confirmurl->out(false),
            s(get_string('mytokens_revoke_all', 'local_aiconnect')),
            ['class' => 'btn btn-danger mr-2'])
    );
} else {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'type', 'value' => $type]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter]);
    echo html_writer::tag('button',
        s($heading),
        ['type' => 'submit', 'class' => 'btn btn-danger mr-2']
    );
    $cancelurl = new moodle_url('/local/aiconnect/mytokens.php', ['userid' => $userid, 'filter' => $filter]);
    echo html_writer::link($cancelurl->out(false), get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
