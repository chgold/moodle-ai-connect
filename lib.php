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
 * Moodle hook callbacks for local_aiconnect.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds an "AI Connect Tokens" link to the user's profile navigation.
 *
 * Called by Moodle core when building the user-settings navigation tree so
 * that each user can manage (view + revoke) their own tokens directly from
 * their profile page.
 *
 * @param \navigation_node  $parentnode   The user settings navigation node.
 * @param \stdClass         $user         The user whose profile is being viewed.
 * @param \context_user     $context      User context.
 * @param \stdClass         $course       The course context (may be front-page).
 * @param \context_course   $coursecontext Course context object.
 * @return void
 */
function local_aiconnect_extend_navigation_user(\navigation_node $parentnode, \stdClass $user,
        \context_user $context, \stdClass $course, \context_course $coursecontext): void {

    global $USER;

    // Guests and unauthenticated users have no tokens.
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Only the account owner (or a site admin) should see this link.
    $issameuser = ((int) $user->id === (int) $USER->id);
    $isadmin = has_capability('moodle/site:config', \context_system::instance());
    if (!$issameuser && !$isadmin) {
        return;
    }

    $url = new \moodle_url('/local/aiconnect/mytokens.php', ['userid' => (int) $user->id]);
    $parentnode->add(
        get_string('mytokens_heading', 'local_aiconnect'),
        $url,
        \navigation_node::TYPE_SETTING,
        null,
        'local_aiconnect_mytokens',
        new \pix_icon('i/key', '')
    );
}
