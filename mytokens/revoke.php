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
 * Confirm and execute single-token revocation for the logged-in user.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_aiconnect\service\token_registry;

require_login();

global $USER, $PAGE, $OUTPUT, $DB;

$id     = required_param('id', PARAM_INT);
$userid = optional_param('userid', (int) $USER->id, PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);

// Permission check.
$issameuser = ((int) $userid === (int) $USER->id);
if (!$issameuser) {
    require_capability('moodle/site:config', context_system::instance());
}

// Load the registry row and verify ownership.
$rec = $DB->get_record('local_aiconnect_token_registry', ['id' => $id]);
if (!$rec || (int) $rec->user_id !== $userid) {
    throw new moodle_exception('invalidtoken', 'local_aiconnect');
}
if ($rec->revoked_at !== null) {
    // Already revoked — just go back.
    redirect(new moodle_url('/local/aiconnect/mytokens.php', [
        'userid' => $userid, 'filter' => $filter, 'msg' => 'revoked',
    ]));
}

$PAGE->set_url('/local/aiconnect/mytokens/revoke.php', [
    'id' => $id, 'userid' => $userid, 'filter' => $filter,
]);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_title(get_string('mytokens_revoke', 'local_aiconnect'));
$PAGE->set_heading(get_string('mytokens_revoke', 'local_aiconnect'));

// POST = confirmed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    token_registry::revoke_by_id($id, (int) $USER->id);
    redirect(new moodle_url('/local/aiconnect/mytokens.php', [
        'userid' => $userid, 'filter' => $filter, 'msg' => 'revoked',
    ]));
}

// GET = confirmation form.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mytokens_revoke', 'local_aiconnect'));

echo $OUTPUT->box(
    html_writer::tag('p', s(get_string('mytokens_revoke_confirm', 'local_aiconnect')))
    . html_writer::tag('p',
        html_writer::tag('strong', 'Token prefix: ')
        . html_writer::tag('code', s($rec->token_prefix) . '…')
    )
);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => '']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter]);
echo html_writer::tag('button',
    s(get_string('mytokens_revoke', 'local_aiconnect')),
    ['type' => 'submit', 'class' => 'btn btn-danger mr-2']
);
$cancelurl = new moodle_url('/local/aiconnect/mytokens.php', ['userid' => $userid, 'filter' => $filter]);
echo html_writer::link($cancelurl->out(false), get_string('cancel'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
