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
 * User-facing token list page.
 *
 * Allows a logged-in user to view and revoke their own AI Connect access tokens.
 * Site admins may view any user's tokens by passing ?userid=N.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/bootstrap.php');

use local_aiconnect\service\token_registry;

require_login();

global $USER, $PAGE, $OUTPUT, $CFG;

$userid = optional_param('userid', (int) $USER->id, PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$msg    = optional_param('msg', '', PARAM_ALPHA);

// Permission check: users can only see their own tokens; admins can see all.
$issameuser = ((int) $userid === (int) $USER->id);
if (!$issameuser) {
    require_capability('moodle/site:config', context_system::instance());
}

$PAGE->set_url('/local/aiconnect/mytokens.php', ['userid' => $userid, 'filter' => $filter]);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_title(get_string('mytokens_heading', 'local_aiconnect'));
$PAGE->set_heading(get_string('mytokens_heading', 'local_aiconnect'));

$validfilters = ['all', 'active', 'unused', 'inactive', 'expired', 'revoked'];
if (!in_array($filter, $validfilters, true)) {
    $filter = 'all';
}

$tokens = token_registry::list_tokens_for_user($userid, $filter);
$now    = time();
$sesskey = sesskey();
$baseurl = new moodle_url('/local/aiconnect/mytokens.php', ['userid' => $userid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mytokens_heading', 'local_aiconnect'));

// Success message.
if ($msg === 'revoked') {
    echo $OUTPUT->notification(get_string('mytokens_revoked_ok', 'local_aiconnect'), 'success');
}
if ($msg === 'generated') {
    echo $OUTPUT->notification(get_string('mytokens_generated_ok', 'local_aiconnect'), 'success');
}

$manifesturl = (new moodle_url('/local/aiconnect/manifest.php'))->out(false);
$generateurl = new moodle_url('/local/aiconnect/mytokens/generate.php', [
    'userid'  => $userid,
    'sesskey' => $sesskey,
]);
echo '<div class="card mb-4" style="background:#f0f4ff;border:1px solid #6366f1">';
echo '<div class="card-body">';
echo '<h4 class="card-title">🔑 ' . s(get_string('mytokens_generate_title', 'local_aiconnect')) . '</h4>';
echo '<p class="card-text">' . s(get_string('mytokens_generate_desc', 'local_aiconnect')) . '</p>';
echo '<p class="mb-2"><strong>' . s(get_string('mytokens_manifest_url', 'local_aiconnect'))
    . ':</strong> <code>' . s($manifesturl) . '</code></p>';
echo '<a href="' . s($generateurl->out(false)) . '" class="btn btn-primary">'
    . s(get_string('mytokens_generate_button', 'local_aiconnect')) . '</a>';
echo '</div></div>';

// Filter tabs.
echo '<div class="mb-3">';
foreach ($validfilters as $f) {
    $active = ($f === $filter) ? 'btn-primary' : 'btn-outline-secondary';
    $href = new moodle_url($baseurl, ['filter' => $f]);
    $label = get_string('mytokens_filter_' . $f, 'local_aiconnect');
    echo '<a href="' . s($href->out(false)) . '" class="btn btn-sm ' . $active . ' mr-1">' . s($label) . '</a>';
}
echo '</div>';

// Bulk action buttons (only relevant when showing active / unused / inactive).
$showbulk = in_array($filter, ['all', 'active', 'unused', 'inactive'], true) && !empty($tokens);
if ($showbulk) {
    $revokeunusedurl = new moodle_url('/local/aiconnect/mytokens/revoke-all.php', [
        'userid' => $userid, 'type' => 'unused', 'filter' => $filter,
    ]);
    $revokeinactiveurl = new moodle_url('/local/aiconnect/mytokens/revoke-all.php', [
        'userid' => $userid, 'type' => 'inactive', 'filter' => $filter,
    ]);
    $revokeallurl = new moodle_url('/local/aiconnect/mytokens/revoke-all.php', [
        'userid' => $userid, 'type' => 'all', 'filter' => $filter,
    ]);
    echo '<div class="mb-3">';
    echo '<a href="' . s($revokeunusedurl->out(false)) . '" class="btn btn-sm btn-warning mr-1">'
        . s(get_string('mytokens_revoke_all_unused', 'local_aiconnect')) . '</a>';
    echo '<a href="' . s($revokeinactiveurl->out(false)) . '" class="btn btn-sm btn-warning mr-1">'
        . s(get_string('mytokens_revoke_all_inactive', 'local_aiconnect')) . '</a>';
    echo '<a href="' . s($revokeallurl->out(false)) . '" class="btn btn-sm btn-danger mr-1">'
        . s(get_string('mytokens_revoke_all', 'local_aiconnect')) . '</a>';
    echo '</div>';
}

if (empty($tokens)) {
    echo $OUTPUT->notification(get_string('mytokens_none', 'local_aiconnect'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Token table.
$table = new html_table();
$table->head = [
    get_string('mytokens_col_prefix', 'local_aiconnect'),
    get_string('mytokens_col_source', 'local_aiconnect'),
    get_string('mytokens_col_scope', 'local_aiconnect'),
    get_string('mytokens_col_issued', 'local_aiconnect'),
    get_string('mytokens_col_lastused', 'local_aiconnect'),
    get_string('mytokens_col_lastip', 'local_aiconnect'),
    get_string('mytokens_col_lastua', 'local_aiconnect'),
    get_string('mytokens_col_state', 'local_aiconnect'),
    get_string('mytokens_col_actions', 'local_aiconnect'),
];
$table->attributes['class'] = 'generaltable table-sm';
$table->data = [];

$inactivecutoff = $now - (30 * DAYSECS);

foreach ($tokens as $row) {
    // Compute token state.
    if ($row->revoked_at !== null) {
        $state = get_string('mytokens_state_revoked', 'local_aiconnect');
    } else if ((int) $row->expires_at <= $now) {
        $state = get_string('mytokens_state_expired', 'local_aiconnect');
    } else if ($row->last_used_at !== null && (int) $row->last_used_at < $inactivecutoff) {
        $state = get_string('mytokens_state_inactive', 'local_aiconnect');
    } else if ($row->last_used_at === null) {
        $state = get_string('mytokens_state_unused', 'local_aiconnect');
    } else {
        $state = get_string('mytokens_state_active', 'local_aiconnect');
    }

    // Last used display.
    $lastused = $row->last_used_at
        ? userdate((int) $row->last_used_at)
        : get_string('mytokens_never', 'local_aiconnect');

    // Last UA — truncate for display.
    $ua = $row->last_used_ua ?? '';
    if (strlen($ua) > 40) {
        $ua = substr($ua, 0, 37) . '…';
    }

    // Action button.
    $isrevokable = ($row->revoked_at === null && (int) $row->expires_at > $now);
    if ($isrevokable) {
        $revokeurl = new moodle_url('/local/aiconnect/mytokens/revoke.php', [
            'id'     => (int) $row->id,
            'userid' => $userid,
            'filter' => $filter,
        ]);
        $action = html_writer::link(
            $revokeurl->out(false),
            s(get_string('mytokens_revoke', 'local_aiconnect')),
            ['class' => 'btn btn-sm btn-danger']
        );
    } else {
        $action = '—';
    }

    $table->data[] = [
        html_writer::tag('code', s($row->token_prefix) . '…'),
        s($row->source),
        s($row->scope),
        userdate((int) $row->issued_at),
        $lastused,
        s((string) ($row->last_used_ip ?? '—')),
        s($ua ?: '—'),
        $state,
        $action,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
