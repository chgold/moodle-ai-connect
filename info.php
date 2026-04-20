<?php
require_once('/workspace/platforms/moodle/config.php');
require_once(__DIR__ . '/classes/controller/manifest_controller.php');

$context = context_system::instance();
$base    = (new moodle_url('/local/aiconnect'))->out(false);
$has_use = isloggedin() && has_capability('local/aiconnect:use', $context);

echo $OUTPUT->header();
?>
<div style="max-width:700px;margin:0 auto;font-family:sans-serif">
<h2>🤖 AI Connect for Moodle</h2>
<p>Connect AI agents (Claude, ChatGPT, Gemini, etc.) to this Moodle instance via the WebMCP Protocol.</p>
<h3>Manifest URL</h3>
<pre><code><?php echo $CFG->wwwroot; ?>/local/aiconnect/manifest.php</code></pre>
<h3>OAuth Authorization</h3>
<pre><code><?php echo $CFG->wwwroot; ?>/local/aiconnect/oauth.php?action=authorize</code></pre>
<h3>Available Tools</h3>
<p><strong>Free (6 tools):</strong> getCourses, getCourse, getEnrollments, getAssignments, getGrades, getUserProfile</p>
<p><strong>Pro (6 tools):</strong> enrollUser, createAssignment, submitGrade, sendMessage, updateCourse, searchUsers</p>
<?php if ($has_use): ?>
<h3>Generate Bearer Token</h3>
<form method="POST" action="/local/aiconnect/oauth.php?action=generate_token">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
<button type="submit" style="background:#1a73e8;color:#fff;padding:10px 20px;border:none;border-radius:4px;cursor:pointer">Generate Token</button>
</form>
<?php else: ?>
<p><a href="/login/index.php?wantsurl=/local/aiconnect/info.php">Log in</a> to generate a Bearer token.</p>
<?php endif; ?>
</div>
<?php echo $OUTPUT->footer(); ?>
