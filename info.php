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

$hasuse = isloggedin() && has_capability('local/aiconnect:use', $context);

echo $OUTPUT->header();
?>
<div style="max-width:700px;margin:0 auto;font-family:sans-serif">
<h2>🤖 AI Connect for Moodle</h2>
<p>Connect AI agents (Claude, ChatGPT, Gemini, etc.) to this Moodle instance via the WebMCP Protocol.</p>
<h3>Manifest URL</h3>
<pre><code><?php echo s($CFG->wwwroot); ?>/local/aiconnect/manifest.php</code></pre>
<h3>OAuth Authorization</h3>
<pre><code><?php echo s($CFG->wwwroot); ?>/local/aiconnect/oauth.php?action=authorize</code></pre>
<h3>Available Tools</h3>
<p><strong>Free (6 tools):</strong> getCourses, getCourse, getEnrollments, getAssignments, getGrades, getUserProfile</p>
<p><strong>Pro (6 tools):</strong> enrollUser, createAssignment, submitGrade, sendMessage, updateCourse, searchUsers</p>
<?php if ($hasuse): ?>
<h3>Generate Bearer Token</h3>
<form method="POST" action="<?php echo s($CFG->wwwroot); ?>/local/aiconnect/oauth.php?action=generate_token">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
<button type="submit" style="background:#1a73e8;color:#fff;padding:10px 20px;border:none;border-radius:4px;cursor:pointer">Generate Token</button>
</form>
<?php else: ?>
<p><a href="<?php echo s($CFG->wwwroot); ?>/login/index.php?wantsurl=<?php echo urlencode('/local/aiconnect/info.php'); ?>">Log in</a> to generate a Bearer token.</p>
<?php endif; ?>
</div>
<?php
echo $OUTPUT->footer();
