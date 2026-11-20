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
function local_aiconnect_extend_navigation_user(
    \navigation_node $parentnode,
    \stdClass $user,
    \context_user $context,
    \stdClass $course,
    \context_course $coursecontext
): void {

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
    if ($issameuser) {
        $parentnode->add(
            get_string('aiactions_heading', 'local_aiconnect'),
            new \moodle_url('/local/aiconnect/aiactions.php'),
            \navigation_node::TYPE_SETTING,
            null,
            'local_aiconnect_aiactions',
            new \pix_icon('i/checked', '')
        );
    }
}

/**
 * Adds a top-level "AI Connect" navigation item to the global navigation tree.
 *
 * This hook is called by Moodle core when building the global navigation, making
 * the link visible from every page for all logged-in (non-guest) users.
 *
 * @param \global_navigation $nav The global navigation tree.
 * @return void
 */
function local_aiconnect_extend_navigation(\global_navigation $nav): void {

    global $USER;

    // Only show for authenticated, non-guest users.
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $url = new \moodle_url('/local/aiconnect/mytokens.php', ['userid' => (int) $USER->id]);
    $nav->add(
        get_string('pluginname', 'local_aiconnect'),
        $url,
        \navigation_node::TYPE_CUSTOM,
        null,
        'local_aiconnect_main',
        new \pix_icon('i/key', '')
    );
}

/**
 * Renders an "AI Connect" link in the page-wide navbar (top-right).
 *
 * Called by Moodle core (Boost theme) to allow plugins to inject top navbar items
 * visible from every page for all authenticated users.
 *
 * @param \renderer_base $renderer
 * @return string HTML output to be placed in the navbar
 */
function local_aiconnect_render_navbar_output(\renderer_base $renderer): string {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $url = new \moodle_url('/local/aiconnect/mytokens.php', ['userid' => (int) $USER->id]);
    $icon = '<i class="fa fa-key" aria-hidden="true"></i>';
    $label = htmlspecialchars(get_string('pluginname', 'local_aiconnect'), ENT_QUOTES, 'UTF-8');

    $html = '<div class="local-aiconnect-navbar nav-item">'
         . '<a class="nav-link" href="' . $url->out() . '" title="' . $label . '">'
         . $icon . ' <span class="d-none d-md-inline">' . $label . '</span>'
         . '</a></div>';

    // Actions an AI agent asked for that wait for this user's confirmation.
    try {
        $waiting = \local_aiconnect\local\confirmation::count_pending((int) $USER->id);
    } catch (\Throwable $e) {
        $waiting = 0;
    }
    if ($waiting > 0) {
        $aurl = new \moodle_url('/local/aiconnect/aiactions.php');
        $alabel = s(get_string('aiactions_waiting_count', 'local_aiconnect', $waiting));
        $html .= '<div class="local-aiconnect-aiactions nav-item">'
            . '<a class="nav-link text-danger" href="' . $aurl->out() . '" title="' . $alabel . '">'
            . '<i class="fa fa-exclamation-circle" aria-hidden="true"></i> '
            . '<span class="badge badge-danger bg-danger">' . (int) $waiting . '</span>'
            . '<span class="sr-only visually-hidden">' . $alabel . '</span></a></div>';
    }
    return $html;
}
