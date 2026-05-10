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
 * Site administration settings page for local_aiconnect.
 *
 * @package    local_aiconnect
 * @copyright  2026 Goldt <https://github.com/chgold>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aiconnect', get_string('pluginname', 'local_aiconnect'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_aiconnect/public_base_url',
        get_string('public_base_url', 'local_aiconnect'),
        get_string('public_base_url_desc', 'local_aiconnect'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiconnect/allowed_origins',
        get_string('allowed_origins', 'local_aiconnect'),
        get_string('allowed_origins_desc', 'local_aiconnect'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aiconnect/enforce_https',
        get_string('enforce_https', 'local_aiconnect'),
        get_string('enforce_https_desc', 'local_aiconnect'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiconnect/ratelimit_minute',
        get_string('ratelimit_minute', 'local_aiconnect'),
        get_string('ratelimit_minute_desc', 'local_aiconnect'),
        '50',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiconnect/ratelimit_hour',
        get_string('ratelimit_hour', 'local_aiconnect'),
        get_string('ratelimit_hour_desc', 'local_aiconnect'),
        '1000',
        PARAM_INT
    ));
}
