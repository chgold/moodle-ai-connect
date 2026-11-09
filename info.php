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
 * Public information page — manifest URL, OAuth endpoints, token generator.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/bootstrap.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/aiconnect/info.php');
$PAGE->set_title(get_string('pluginname', 'local_aiconnect'));
$PAGE->set_heading(get_string('pluginname', 'local_aiconnect'));

$isloggedin = isloggedin() && !isguestuser();
$hasuse     = $isloggedin && has_capability('local/aiconnect:use', $context);

$wwwroot     = $CFG->wwwroot;
$manifesturl = $wwwroot . '/local/aiconnect/manifest.php';
$tokenaction = $wwwroot . '/local/aiconnect/oauth.php?action=generate_token';
$mytokens    = $wwwroot . '/local/aiconnect/mytokens.php';
$loginurl    = $wwwroot . '/login/index.php?wantsurl=' . urlencode('/local/aiconnect/info.php');

echo $OUTPUT->header();
?>
<style>
.aic-wrap *{box-sizing:border-box}
.aic-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f1114;color:#e5e7eb;line-height:1.6;margin:-20px -20px 0;padding:40px 20px;min-height:calc(100vh - 100px)}
.aic-container{max-width:760px;margin:0 auto}
.aic-wrap h1{font-size:28px;color:#fff;margin:0 0 8px;font-weight:700}
.aic-badge{display:inline-block;background:#10b981;color:#022c22;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:6px}
.aic-subtitle{color:#9ca3af;margin:0 0 32px;font-size:15px}
.aic-section{background:#1a1d23;border-radius:12px;padding:24px;margin-bottom:20px;border:1px solid #1f2329}
.aic-section h2{font-size:16px;color:#6366f1;margin:0 0 12px;font-weight:600}
.aic-section p{margin:0 0 10px;color:#d1d5db;font-size:14px}
.aic-url-box{background:#111318;border:1px solid #374151;border-radius:6px;padding:10px 14px;font-family:'SF Mono',Monaco,Consolas,monospace;font-size:13px;color:#10b981;word-break:break-all}
.aic-btn{display:inline-block;padding:12px 24px;background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;margin-top:6px;font-family:inherit}
.aic-btn:hover{background:#4f46e5;color:#fff;text-decoration:none}
.aic-btn.copy{background:#10b981;color:#022c22}
.aic-btn.copy:hover{background:#34d399;color:#022c22}
.aic-btn.ghost{background:transparent;border:1px solid #374151;color:#a5b4fc}
.aic-btn.ghost:hover{background:#111318;color:#c7d2fe}
.aic-prompt-result{display:none;margin-top:18px}
.aic-prompt-box{background:#111318;border:1px solid #374151;border-radius:8px;padding:18px;font-family:'SF Mono',Monaco,Consolas,monospace;font-size:12px;color:#cbd5e1;white-space:pre-wrap;word-break:break-all;max-height:520px;overflow-y:auto;margin-top:14px}
.aic-warn{background:#1a0a0a;border:1px solid #ef4444;color:#fca5a5;padding:12px 16px;border-radius:6px;margin:14px 0;font-size:13px}
.aic-error{color:#ef4444;font-size:13px;margin-top:8px;min-height:1em}
.aic-notice{background:#111318;border:1px solid #374151;border-radius:6px;padding:12px 16px;font-size:14px;color:#d1d5db}
.aic-notice a{color:#a5b4fc}
.aic-tool{background:#111318;border:1px solid #1f2329;border-radius:6px;padding:8px 12px;font-size:13px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center}
.aic-tool-name{color:#a5b4fc;font-family:'SF Mono',Monaco,Consolas,monospace}
.aic-tool-type{font-size:10px;padding:2px 8px;border-radius:3px;font-weight:700;letter-spacing:0.5px}
.aic-free{background:#065f46;color:#6ee7b7}
.aic-pro{background:#7c2d12;color:#fdba74}
.aic-docs{text-align:center;padding:24px 0 8px;font-size:14px}
.aic-docs a{color:#a5b4fc;text-decoration:none;font-weight:600}
.aic-docs a:hover{color:#c7d2fe;text-decoration:underline}
.aic-form{margin-top:14px}
</style>
<div class="aic-wrap"><div class="aic-container">

<h1>🎓 Moodle AI Connect <span class="aic-badge">Servio v2.0</span></h1>
<p class="aic-subtitle">Connect AI agents to this Moodle LMS via the Servio Protocol</p>

<div class="aic-section">
<h2>Manifest URL (public)</h2>
<div class="aic-url-box"><?php echo s($manifesturl); ?></div>
</div>

<div class="aic-section">
<h2>🔑 Get Your Connection Prompt</h2>
<?php if (!$isloggedin) : ?>
    <p>Sign in to your Moodle account to generate a Bearer token for your AI agent.</p>
    <a class="aic-btn" href="<?php echo s($loginurl); ?>">🔐 Log in to generate token</a>
<?php elseif (!$hasuse) : ?>
    <div class="aic-notice">
        You are logged in as <strong><?php echo s(fullname($USER)); ?></strong>, but your account
        does not have the <code>local/aiconnect:use</code> capability required to generate tokens.
        Please contact a site administrator.
    </div>
<?php else : ?>
    <p>Click the button below to generate a Bearer token and copy-paste prompt for your AI agent.</p>
    <form id="aic-generate-form" class="aic-form" method="POST" action="<?php echo s($tokenaction); ?>">
        <input type="hidden" name="sesskey" value="<?php echo s(sesskey()); ?>">
        <button class="aic-btn" type="submit" id="aic-submit-btn">⚡ Generate AI Connect Prompt</button>
        <div class="aic-error" id="aic-error-msg"></div>
    </form>
    <div class="aic-prompt-result" id="aic-prompt-result">
        <div class="aic-warn"><strong>⚠️ Save this prompt now.</strong> The token will not be shown again in full. Access valid 1 hour, refresh valid 30 days.</div>
        <button class="aic-btn copy" type="button" id="aic-copy-btn">📋 Copy Full Prompt</button>
        <div class="aic-prompt-box" id="aic-prompt-text"></div>
    </div>
<?php endif; ?>
</div>

<div class="aic-section">
<h2>👤 My Tokens</h2>
<p>Review, rotate, or revoke tokens issued to your account.</p>
<a class="aic-btn ghost" href="<?php echo s($mytokens); ?>">Manage your tokens →</a>
</div>

<div class="aic-section">
<h2>🛠️ Available Tools</h2>
<div class="aic-tool"><span class="aic-tool-name">moodle.getCourses</span><span class="aic-tool-type aic-free">FREE</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.getCourse</span><span class="aic-tool-type aic-free">FREE</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.getEnrollments</span><span class="aic-tool-type aic-free">FREE</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.getAssignments</span><span class="aic-tool-type aic-free">FREE</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.getGrades</span><span class="aic-tool-type aic-free">FREE</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.getUserProfile</span><span class="aic-tool-type aic-free">FREE</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.enrollUser</span><span class="aic-tool-type aic-pro">PRO</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.createAssignment</span><span class="aic-tool-type aic-pro">PRO</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.submitGrade</span><span class="aic-tool-type aic-pro">PRO</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.sendMessage</span><span class="aic-tool-type aic-pro">PRO</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.updateCourse</span><span class="aic-tool-type aic-pro">PRO</span></div>
<div class="aic-tool"><span class="aic-tool-name">moodle.searchUsers</span><span class="aic-tool-type aic-pro">PRO</span></div>
</div>

<div class="aic-docs">
<a href="https://ai-connect.gold-t.co.il/moodle" target="_blank" rel="noopener">Full Documentation →</a>
</div>

</div></div>
<?php if ($hasuse) : ?>
<script>
(function () {
    var form = document.getElementById('aic-generate-form');
    var box = document.getElementById('aic-prompt-result');
    var txt = document.getElementById('aic-prompt-text');
    var copy = document.getElementById('aic-copy-btn');
    var btn = document.getElementById('aic-submit-btn');
    var err = document.getElementById('aic-error-msg');
    if (!form) { return; }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        err.textContent = '';
        btn.disabled = true;
        btn.textContent = 'Generating...';
        var body = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        }).then(function (res) {
            if (res.ok && (res.data.prompt || res.data.access_token)) {
                var content = res.data.prompt;
                if (!content) {
                    var lines = [];
                    lines.push('You have access to this Moodle LMS via AI Connect.');
                    lines.push('');
                    lines.push('Manifest URL: <?php echo addslashes($manifesturl); ?>');
                    lines.push('Access Token: ' + res.data.access_token);
                    if (res.data.refresh_token) {
                        lines.push('Refresh Token: ' + res.data.refresh_token);
                    }
                    if (res.data.expires_in) {
                        lines.push('Expires in: ' + res.data.expires_in + ' seconds');
                    }
                    content = lines.join('\n');
                }
                txt.textContent = content;
                box.style.display = 'block';
                form.style.display = 'none';
                box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                err.textContent = (res.data && (res.data.message || res.data.error)) || 'Failed to generate token';
                btn.disabled = false;
                btn.textContent = '⚡ Generate AI Connect Prompt';
            }
        }).catch(function (e) {
            err.textContent = 'Network error: ' + e.message;
            btn.disabled = false;
            btn.textContent = '⚡ Generate AI Connect Prompt';
        });
    });

    if (copy) {
        copy.addEventListener('click', function () {
            var text = txt.textContent;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    var orig = copy.textContent;
                    copy.textContent = '✓ Copied!';
                    setTimeout(function () { copy.textContent = orig; }, 1500);
                });
            }
        });
    }
})();
</script>
<?php endif; ?>
<?php
echo $OUTPUT->footer();
