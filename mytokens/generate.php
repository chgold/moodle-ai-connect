<?php
/**
 * Generate a new AI Connect access token + AI agent prompt for the current user.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_aiconnect\service\token_registry;

require_login();
require_sesskey();

global $USER, $PAGE, $OUTPUT, $CFG, $SITE;

$userid = optional_param('userid', (int) $USER->id, PARAM_INT);

if ((int) $userid !== (int) $USER->id) {
    require_capability('moodle/site:config', context_system::instance());
}

$PAGE->set_url('/local/aiconnect/mytokens/generate.php', ['userid' => $userid]);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_title(get_string('mytokens_generate_title', 'local_aiconnect'));
$PAGE->set_heading(get_string('mytokens_generate_title', 'local_aiconnect'));

$accesstoken  = bin2hex(random_bytes(32));
$refreshtoken = bin2hex(random_bytes(32));
$now          = time();
$expiresat    = $now + 3600;
$refreshexp   = $now + (30 * 86400);

token_registry::register(
    $accesstoken,
    $userid,
    'moodle-user-ui',
    'read write',
    $expiresat,
    'user-ui',
    $refreshexp
);

global $DB;
$DB->insert_record('local_aiconnect_tokens', (object) [
    'token'        => $accesstoken,
    'tokentype'    => 'access',
    'client_id'    => 'moodle-user-ui',
    'userid'       => $userid,
    'scope'        => 'read write',
    'parenttoken'  => null,
    'expiry'       => $expiresat,
    'revoked'      => 0,
    'timecreated'  => $now,
]);
$DB->insert_record('local_aiconnect_tokens', (object) [
    'token'        => $refreshtoken,
    'tokentype'    => 'refresh',
    'client_id'    => 'moodle-user-ui',
    'userid'       => $userid,
    'scope'        => 'read write',
    'parenttoken'  => $accesstoken,
    'expiry'       => $refreshexp,
    'revoked'      => 0,
    'timecreated'  => $now,
]);

$baseurl     = rtrim($CFG->wwwroot, '/') . '/local/aiconnect';
$manifesturl = rtrim($CFG->wwwroot, '/') . '/api/aiconnect-manifest';
$toolroot    = $baseurl . '/tools.php';
$tokenurl    = $baseurl . '/oauth.php?action=token';
$hostname    = parse_url($CFG->wwwroot, PHP_URL_HOST);
$pathpart    = rtrim(parse_url($CFG->wwwroot, PHP_URL_PATH) ?? '', '/');
$sitenamemcp = $pathpart !== '' ? $hostname . $pathpart : $hostname;
$sitekey     = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sitenamemcp);
$sitename    = format_string($SITE->fullname) ?: 'Moodle Site';

$readtools = [
    ['name' => 'moodle.getCourses',      'params' => 'limit=10',              'hint' => 'List courses the user is enrolled in'],
    ['name' => 'moodle.getCourse',       'params' => 'course_id=COURSE_ID',   'hint' => 'Get course details'],
    ['name' => 'moodle.getAssignments',  'params' => 'course_id=COURSE_ID',   'hint' => 'List assignments in a course'],
    ['name' => 'moodle.getGrades',       'params' => 'course_id=COURSE_ID',   'hint' => 'Get grades for current user'],
    ['name' => 'moodle.getUserProfile',  'params' => '',                      'hint' => 'Get current user profile'],
];
$writetools = [
    ['name' => 'moodle.enrollUser',      'body' => '{"course_id":CID,"user_id":UID}', 'hint' => 'Enroll user (Pro)'],
    ['name' => 'moodle.submitAssignment','body' => '{"assignment_id":AID,"text":"BODY"}', 'hint' => 'Submit assignment (Pro)'],
    ['name' => 'moodle.gradeAssignment', 'body' => '{"submission_id":SID,"grade":85}', 'hint' => 'Grade submission (Pro)'],
];

$lines = [];
$lines[] = "You have access to {$sitename} via AI Connect.";
$lines[] = "";
$lines[] = "## Add site (goldnat.ai recommended)";
$lines[] = "Call servio_addSite (or webmcp_addSite for backward compat) with these parameters:";
$lines[] = "  name:          \"{$sitenamemcp}\"";
$lines[] = "  manifest_url:  \"{$manifesturl}\"";
$lines[] = "  token_url:     \"{$tokenurl}\"";
$lines[] = "  token:         \"Bearer {$accesstoken}\"";
$lines[] = "  refresh_token: \"{$refreshtoken}\"";
$lines[] = "";
$lines[] = "IMPORTANT: Paste BOTH token and refresh_token — otherwise the connection will stop working after 1 hour.";
$lines[] = "";
$lines[] = "After adding the site, call these tools by EXACT name (do not search — use directly):";
foreach (array_merge($readtools, $writetools) as $t) {
    $mcpname = $sitekey . '_' . str_replace('.', '_', $t['name']);
    $lines[] = '  ' . str_pad($mcpname, 50) . '<- ' . $t['hint'];
}
$lines[] = "";
$lines[] = "## Direct URL Access (Fallback — read-only tools)";
$lines[] = "If MCP is not available, read-only tools can be called via GET:";
$lines[] = "";
foreach ($readtools as $t) {
    $url = $toolroot . '?tool=' . $t['name'] . '&token=' . $accesstoken;
    if (!empty($t['params'])) {
        $url .= '&' . $t['params'];
    }
    $lines[] = '  ' . $url;
}
$lines[] = "";
$lines[] = "Replace placeholders: COURSE_ID/CID/AID/SID/UID=numbers";
$lines[] = "";
$lines[] = "## Write Tools (POST only — use MCP when possible)";
$lines[] = "  POST {$toolroot}";
$lines[] = "  Authorization: Bearer {$accesstoken}";
$lines[] = "  Content-Type: application/json";
$lines[] = "";
foreach ($writetools as $t) {
    $lines[] = '  // ' . $t['name'] . ' — ' . $t['hint'];
    $lines[] = '  POST ' . $toolroot . '  body: {"name":"' . $t['name'] . '","arguments":' . $t['body'] . '}';
    $lines[] = '';
}
$lines[] = "## Token Refresh (valid 30 days)";
$lines[] = "When access_token expires (after 1 hour), refresh it:";
$lines[] = "  POST {$tokenurl}";
$lines[] = "  Content-Type: application/json";
$lines[] = '  {"grant_type":"refresh_token","refresh_token":"' . $refreshtoken . '","client_id":"claude-ai"}';
$lines[] = "Response contains a new access_token + new refresh_token (old pair is revoked).";
$lines[] = "";
$lines[] = "IMPORTANT: Do NOT use webmcp tool search — it may return tools from other sites.";
$lines[] = "Call the tools listed above by their EXACT full name.";
$lines[] = "Security note: This token acts on behalf of the user who generated it. Handle it with care.";
$lines[] = "Documentation: https://plugins.goldnat.ai/moodle/guide";

$prompt = implode("\n", $lines);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mytokens_generate_title', 'local_aiconnect'));

echo '<div class="alert alert-success"><strong>✅ Token created.</strong> Copy the prompt below — the tokens will not be shown again.</div>';

echo '<div class="alert alert-warning"><strong>⚠️ Save this prompt now.</strong> '
   . 'Access token valid for 1 hour, refresh token valid for 30 days. '
   . 'Your AI agent will automatically use the refresh tool when access expires.</div>';

echo '<button id="copy-btn" class="btn btn-success mb-3" type="button" '
   . 'onclick="navigator.clipboard.writeText(document.getElementById(\'prompt\').textContent).then(function(){var b=document.getElementById(\'copy-btn\');var o=b.textContent;b.textContent=\'✓ Copied!\';setTimeout(function(){b.textContent=o;},1500);});">'
   . '📋 Copy Full Prompt</button>';

echo '<pre id="prompt" style="background:#0a0a0a;color:#cbd5e1;padding:18px;border-radius:8px;'
   . 'word-break:break-all;white-space:pre-wrap;font-family:monospace;font-size:12px;max-height:600px;overflow-y:auto;">'
   . s($prompt) . '</pre>';

$back = new moodle_url('/local/aiconnect/mytokens.php', ['userid' => $userid]);
echo '<a href="' . s($back->out(false)) . '" class="btn btn-secondary mt-3">← Back to My Tokens</a>';

echo $OUTPUT->footer();
