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
 * Admin page — manage users blocked from the AI Connect API.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_aiconnect\service\user_blocking;

admin_externalpage_setup('local_aiconnect_blocked');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

// ── Handle actions ──────────────────────────────────────────────────────────

if ($action === 'block' && $userid > 0) {
    require_sesskey();
    global $USER;
    // Validate the target user exists.
    if ($DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
        user_blocking::block_user($userid, (int) $USER->id);
        redirect(
            new moodle_url('/local/aiconnect/admin/blocked.php'),
            get_string('admin_blocked_blocked_ok', 'local_aiconnect'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect(
            new moodle_url('/local/aiconnect/admin/blocked.php'),
            get_string('admin_blocked_user_notfound', 'local_aiconnect'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if ($action === 'unblock' && $userid > 0) {
    require_sesskey();
    user_blocking::unblock_user($userid);
    redirect(
        new moodle_url('/local/aiconnect/admin/blocked.php'),
        get_string('admin_blocked_unblocked_ok', 'local_aiconnect'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Page setup ───────────────────────────────────────────────────────────────

$PAGE->set_url('/local/aiconnect/admin/blocked.php');
$PAGE->set_title(get_string('admin_blocked_heading', 'local_aiconnect'));
$PAGE->set_heading(get_string('admin_blocked_heading', 'local_aiconnect'));

echo $OUTPUT->header();

$sesskey  = sesskey();
$baseurl  = new moodle_url('/local/aiconnect/admin/blocked.php');
$rows     = user_blocking::get_blocked_users();

// ── Heading ──────────────────────────────────────────────────────────────────

echo '<h2>' . get_string('admin_blocked_heading', 'local_aiconnect') . '</h2>';
echo '<p>' . get_string('admin_blocked_desc', 'local_aiconnect') . '</p>';

// ── Block-a-new-user form ────────────────────────────────────────────────────

echo '<form method="POST" action="' . s($baseurl->out(false)) . '" style="margin-bottom:18px">';
echo '<input type="hidden" name="sesskey" value="' . s($sesskey) . '">';
echo '<input type="hidden" name="action"  value="block">';
echo '<label>' . get_string('admin_blocked_userid_label', 'local_aiconnect')
    . ' <input type="number" name="userid" min="1" required style="width:120px"></label> ';
echo '<button type="submit" class="btn btn-sm btn-warning"'
    . ' onclick="return confirm(' . json_encode(get_string('admin_blocked_confirm_block', 'local_aiconnect')) . ')">'
    . get_string('admin_blocked_block_btn', 'local_aiconnect')
    . '</button>';
echo '</form>';

// ── Blocked-users table ──────────────────────────────────────────────────────

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('admin_blocked_none', 'local_aiconnect'), 'info');
} else {
    echo '<table class="generaltable" style="width:100%"><thead><tr>'
        . '<th>' . get_string('admin_blocked_col_user', 'local_aiconnect') . '</th>'
        . '<th>' . get_string('admin_blocked_col_blockedby', 'local_aiconnect') . '</th>'
        . '<th>' . get_string('admin_blocked_col_since', 'local_aiconnect') . '</th>'
        . '<th>' . get_string('admin_blocked_col_action', 'local_aiconnect') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $username = ($row->firstname !== null)
            ? trim($row->firstname . ' ' . $row->lastname) . ' (#' . (int) $row->userid . ')'
            : '#' . (int) $row->userid;

        $blocker = ($row->blocker_firstname !== null)
            ? trim($row->blocker_firstname . ' ' . $row->blocker_lastname) . ' (#' . (int) $row->blockedby . ')'
            : ($row->blockedby ? '#' . (int) $row->blockedby : '—');

        $unblockurl = new moodle_url('/local/aiconnect/admin/blocked.php', [
            'action'  => 'unblock',
            'userid'  => (int) $row->userid,
            'sesskey' => $sesskey,
        ]);

        echo '<tr>'
            . '<td>' . s($username) . '</td>'
            . '<td>' . s($blocker) . '</td>'
            . '<td>' . userdate((int) $row->timecreated) . '</td>'
            . '<td><a href="' . s($unblockurl->out(false)) . '" class="btn btn-xs btn-success"'
            . ' onclick="return confirm(' . json_encode(get_string('admin_blocked_confirm_unblock', 'local_aiconnect')) . ')">'
            . get_string('admin_blocked_unblock_btn', 'local_aiconnect')
            . '</a></td>'
            . '</tr>';
    }

    echo '</tbody></table>';
}

echo $OUTPUT->footer();
