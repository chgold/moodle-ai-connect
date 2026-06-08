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
 * Admin emergency-disconnect page.
 *
 * Revokes ALL active (non-revoked) tokens for ALL users in a single
 * atomic database operation. Intended for incident response only.
 *
 * Requires `moodle/site:config` capability and a POST with a valid
 * sesskey to prevent CSRF.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_aiconnect\service\token_registry;

admin_externalpage_setup('local_aiconnect_emergency');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

// ── Execute on confirmed POST ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    global $USER;
    $count = token_registry::revoke_all(isset($USER->id) ? (int) $USER->id : null);

    $PAGE->set_url('/local/aiconnect/admin/emergency-revoke.php');
    $PAGE->set_title(get_string('admin_emergency_heading', 'local_aiconnect'));
    $PAGE->set_heading(get_string('admin_emergency_heading', 'local_aiconnect'));

    echo $OUTPUT->header();
    echo '<h2>' . get_string('admin_emergency_heading', 'local_aiconnect') . '</h2>';

    if ($count > 0) {
        echo $OUTPUT->notification(
            get_string('admin_emergency_revoked', 'local_aiconnect', $count),
            'success'
        );
    } else {
        echo $OUTPUT->notification(
            get_string('admin_emergency_none', 'local_aiconnect'),
            'info'
        );
    }

    $backurl = new moodle_url('/local/aiconnect/admin/tokens.php');
    echo '<p><a href="' . s($backurl->out(false)) . '" class="btn btn-secondary">'
        . get_string('admin_emergency_back', 'local_aiconnect') . '</a></p>';

    echo $OUTPUT->footer();
    exit;
}

// ── GET: show confirmation form ───────────────────────────────────────────

$PAGE->set_url('/local/aiconnect/admin/emergency-revoke.php');
$PAGE->set_title(get_string('admin_emergency_heading', 'local_aiconnect'));
$PAGE->set_heading(get_string('admin_emergency_heading', 'local_aiconnect'));

// Count active tokens so we can show the user what will be affected.
$activecount = (int) $DB->count_records_select(
    'local_aiconnect_token_registry',
    'revoked_at IS NULL'
);

echo $OUTPUT->header();

echo '<h2>' . get_string('admin_emergency_heading', 'local_aiconnect') . '</h2>';

// Danger warning box.
echo '<div class="alert alert-danger" role="alert" style="max-width:700px">'
    . '<strong>⚠️ ' . get_string('admin_emergency_warning_title', 'local_aiconnect') . '</strong><br>'
    . get_string('admin_emergency_desc', 'local_aiconnect')
    . '</div>';

echo '<p>' . get_string('admin_emergency_active_count', 'local_aiconnect', $activecount) . '</p>';

if ($activecount === 0) {
    echo $OUTPUT->notification(get_string('admin_emergency_none', 'local_aiconnect'), 'info');
} else {
    // Confirmation form — requires sesskey (CSRF protection).
    $sesskey = sesskey();
    $formurl = new moodle_url('/local/aiconnect/admin/emergency-revoke.php');

    echo '<form method="POST" action="' . s($formurl->out(false)) . '">';
    echo '<input type="hidden" name="sesskey" value="' . s($sesskey) . '">';
    echo '<button type="submit" class="btn btn-danger"'
        . ' onclick="return confirm(' . json_encode(get_string('admin_emergency_confirm_js', 'local_aiconnect')) . ')'
        . ' && confirm(' . json_encode(get_string('admin_emergency_confirm_js2', 'local_aiconnect')) . ')">'
        . get_string('admin_emergency_btn', 'local_aiconnect')
        . '</button>';
    echo '</form>';
}

$backurl = new moodle_url('/local/aiconnect/admin/tokens.php');
echo '<p style="margin-top:16px"><a href="' . s($backurl->out(false)) . '">'
    . get_string('admin_emergency_back', 'local_aiconnect') . '</a></p>';

echo $OUTPUT->footer();
