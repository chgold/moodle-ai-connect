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
 * "AI actions" page — the user confirms or rejects what an AI agent asked to do.
 *
 * Only the logged-in owner of an action can decide it. Decisions are POST +
 * sesskey from the user's own browser session; any request that carries an
 * Authorization header (an agent's Bearer token) is refused.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/bootstrap.php');

use local_aiconnect\local\confirmation;

// An agent holding a token must never be able to confirm its own request.
$authheader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authheader === '' && function_exists('getallheaders')) {
    $hdrs = array_change_key_case((array) getallheaders(), CASE_LOWER);
    $authheader = (string) ($hdrs['authorization'] ?? '');
}
if ($authheader !== '') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'session_required',
        'message' => 'AI actions can only be decided by the signed-in user in a browser, not with a token.']);
    exit;
}

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('Guests cannot decide AI actions');
}

global $USER, $PAGE, $OUTPUT;

$userid = (int) $USER->id;
$id     = optional_param('id', '', PARAM_ALPHANUMEXT);
$do     = optional_param('do', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/aiconnect/aiactions.php', $id !== '' ? ['id' => $id] : []);
$PAGE->set_url($pageurl);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('aiactions_heading', 'local_aiconnect'));
$PAGE->set_heading(get_string('aiactions_heading', 'local_aiconnect'));

// Decisions: POST + sesskey only.
if ($do !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new moodle_exception('aiactions_post_only', 'local_aiconnect');
    }
    require_sesskey();
    if ($do === 'confirm' && $id !== '') {
        $grant = optional_param('autonomy', 0, PARAM_BOOL);
        $out = confirmation::confirm($id, $userid, (bool) $grant);
        $msg = $out['ok'] ? get_string('aiactions_done', 'local_aiconnect')
            : ($out['status'] === 'failed' ? get_string('aiactions_failed', 'local_aiconnect')
                : get_string('aiactions_cannot', 'local_aiconnect'));
        redirect($pageurl, $msg, null, $out['ok'] ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR);
    }
    if ($do === 'reject' && $id !== '') {
        $ok = confirmation::reject($id, $userid);
        redirect(
            $pageurl,
            $ok ? get_string('aiactions_rejected', 'local_aiconnect')
            : get_string('aiactions_cannot', 'local_aiconnect'),
            null,
            $ok ? \core\output\notification::NOTIFY_INFO : \core\output\notification::NOTIFY_ERROR
        );
    }
    if ($do === 'revokegrant') {
        $grantid = required_param('grantid', PARAM_INT);
        confirmation::revoke_grant($grantid, $userid);
        redirect(
            $pageurl,
            get_string('aiactions_grant_removed', 'local_aiconnect'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    redirect($pageurl);
}

/**
 * A small POST form with sesskey.
 *
 * @param moodle_url $url    Target.
 * @param array      $fields Hidden fields.
 * @param string     $inner  Extra HTML inside the form (checkbox).
 * @param string     $label  Button label.
 * @param string     $class  Button class.
 * @return string
 */
function local_aiconnect_aiactions_form(moodle_url $url, array $fields, string $inner, string $label, string $class): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach ($fields as $k => $v) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    $html .= $inner;
    $html .= html_writer::tag('button', s($label), ['type' => 'submit', 'class' => 'btn ' . $class]);
    return html_writer::tag('form', $html, ['method' => 'post', 'action' => $url->out(false),
        'class' => 'd-inline-block mr-2 me-2 mb-2']);
}

/**
 * Localised status label.
 *
 * @param string $status Status.
 * @return string
 */
function local_aiconnect_aiactions_status(string $status): string {
    $key = 'aiactions_status_' . $status;
    return get_string_manager()->string_exists($key, 'local_aiconnect') ? get_string($key, 'local_aiconnect') : s($status);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aiactions_heading', 'local_aiconnect'));
echo html_writer::tag('p', get_string('aiactions_intro', 'local_aiconnect'));

if ($id !== '') {
    $action = confirmation::get_action($id);
    if (!$action || (int) $action->userid !== $userid) {
        echo $OUTPUT->notification(get_string('aiactions_notfound', 'local_aiconnect'), 'error');
    } else {
        confirmation::sweep();
        $action = confirmation::get_action($id);
        $body = html_writer::tag('h3', s($action->summary));
        $details = json_decode((string) $action->details, true);
        if (is_array($details) && $details) {
            $body .= html_writer::tag('h4', get_string('aiactions_what', 'local_aiconnect'), ['class' => 'h6 mt-3']);
            $items = '';
            foreach ($details as $line) {
                $items .= html_writer::tag('li', s((string) $line));
            }
            $body .= html_writer::tag('ul', $items);
        }
        $rows = [
            [get_string('aiactions_status', 'local_aiconnect'), local_aiconnect_aiactions_status($action->status)],
            [get_string('aiactions_risk', 'local_aiconnect'), s(get_string('aiactions_risk_' . $action->risk, 'local_aiconnect'))],
            [get_string('aiactions_client', 'local_aiconnect'), s($action->clientid !== '' ? $action->clientid : '-')],
            [get_string('aiactions_created', 'local_aiconnect'), userdate((int) $action->timecreated)],
            [get_string('aiactions_expires', 'local_aiconnect'), userdate((int) $action->expiresat)],
        ];
        $table = new html_table();
        $table->attributes['class'] = 'generaltable';
        foreach ($rows as $r) {
            $table->data[] = [html_writer::tag('strong', $r[0]), $r[1]];
        }
        $body .= html_writer::table($table);

        if ($action->status === 'pending') {
            $inner = '';
            if (confirmation::autonomy_allowed()) {
                $cb = html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'autonomy', 'value' => 1,
                    'id' => 'aiconnect-autonomy', 'class' => 'form-check-input']);
                $inner = html_writer::div($cb . ' ' . html_writer::label(
                    get_string('aiactions_autonomy', 'local_aiconnect', (object) [
                        'client' => $action->clientid !== '' ? $action->clientid : '-', 'tool' => $action->tool]),
                    'aiconnect-autonomy',
                    false,
                    ['class' => 'form-check-label']
                ), 'form-check mb-2');
            }
            $body .= local_aiconnect_aiactions_form(
                $pageurl,
                ['id' => $action->confid, 'do' => 'confirm'],
                $inner,
                get_string('aiactions_confirm', 'local_aiconnect'),
                'btn-danger'
            );
            $body .= local_aiconnect_aiactions_form(
                $pageurl,
                ['id' => $action->confid, 'do' => 'reject'],
                '',
                get_string('aiactions_reject', 'local_aiconnect'),
                'btn-secondary'
            );
        } else if (in_array($action->status, ['executed', 'failed'], true)) {
            $result = json_decode((string) $action->resultjson, true);
            if (is_array($result) && isset($result['error'])) {
                $body .= $OUTPUT->notification(s((string) ($result['message'] ?? $result['error'])), 'error');
            }
        }

        $tech = html_writer::tag('p', html_writer::tag('strong', get_string('aiactions_tool', 'local_aiconnect') . ': ')
            . html_writer::tag('code', s($action->tool)));
        $preview = json_decode((string) $action->previewjson, true);
        $tech .= html_writer::tag(
            'pre',
            s(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ['class' => 'bg-light p-2 small']
        );
        $body .= print_collapsible_region(
            $tech,
            '',
            'aiconnect-tech-' . $action->id,
            get_string('aiactions_technical', 'local_aiconnect'),
            '',
            true,
            true
        );

        echo $OUTPUT->box($body, 'generalbox p-3');
    }
}

// Waiting actions.
echo $OUTPUT->heading(get_string('aiactions_waiting', 'local_aiconnect'), 3);
$pending = confirmation::list_actions($userid, 'pending', 50);
if (!$pending) {
    echo html_writer::tag('p', get_string('aiactions_none', 'local_aiconnect'), ['class' => 'text-muted']);
} else {
    $t = new html_table();
    $t->attributes['class'] = 'generaltable';
    $t->head = [get_string('aiactions_summary', 'local_aiconnect'), get_string('aiactions_client', 'local_aiconnect'),
        get_string('aiactions_expires', 'local_aiconnect')];
    foreach ($pending as $a) {
        $t->data[] = [
            html_writer::link(new moodle_url('/local/aiconnect/aiactions.php', ['id' => $a->confid]), s($a->summary)),
            s($a->clientid),
            userdate((int) $a->expiresat),
        ];
    }
    echo html_writer::table($t);
}

// Recent decisions.
$recent = array_filter(confirmation::list_actions($userid, null, 20), static fn($a) => $a->status !== 'pending');
if ($recent) {
    echo $OUTPUT->heading(get_string('aiactions_recent', 'local_aiconnect'), 3);
    $t = new html_table();
    $t->attributes['class'] = 'generaltable';
    $t->head = [get_string('aiactions_summary', 'local_aiconnect'), get_string('aiactions_status', 'local_aiconnect'),
        get_string('aiactions_created', 'local_aiconnect')];
    foreach ($recent as $a) {
        $t->data[] = [
            html_writer::link(new moodle_url('/local/aiconnect/aiactions.php', ['id' => $a->confid]), s($a->summary)),
            local_aiconnect_aiactions_status($a->status),
            userdate((int) $a->timecreated),
        ];
    }
    echo html_writer::table($t);
}

// Autonomy grants.
$grants = confirmation::list_grants($userid);
if ($grants) {
    echo $OUTPUT->heading(get_string('aiactions_grants', 'local_aiconnect'), 3);
    $t = new html_table();
    $t->attributes['class'] = 'generaltable';
    $t->head = [get_string('aiactions_tool', 'local_aiconnect'), get_string('aiactions_client', 'local_aiconnect'),
        get_string('aiactions_created', 'local_aiconnect'), ''];
    foreach ($grants as $g) {
        $t->data[] = [
            html_writer::tag('code', s($g->tool)),
            s($g->clientid),
            userdate((int) $g->timecreated),
            local_aiconnect_aiactions_form(
                new moodle_url('/local/aiconnect/aiactions.php'),
                ['do' => 'revokegrant', 'grantid' => (int) $g->id],
                '',
                get_string('aiactions_grant_remove', 'local_aiconnect'),
                'btn-outline-secondary btn-sm'
            ),
        ];
    }
    echo html_writer::table($t);
}

// Audit log.
$log = confirmation::list_log($userid, 30);
if ($log) {
    $t = new html_table();
    $t->attributes['class'] = 'generaltable small';
    $t->head = [get_string('aiactions_created', 'local_aiconnect'), get_string('aiactions_event', 'local_aiconnect'),
        get_string('aiactions_tool', 'local_aiconnect'), get_string('aiactions_client', 'local_aiconnect')];
    foreach ($log as $l) {
        $t->data[] = [userdate((int) $l->timecreated), s($l->event), s($l->tool), s($l->clientid)];
    }
    echo print_collapsible_region(
        html_writer::table($t),
        '',
        'aiconnect-log',
        get_string('aiactions_log', 'local_aiconnect'),
        '',
        true,
        true
    );
}

echo $OUTPUT->footer();
