<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aiconnect', get_string('pluginname', 'local_aiconnect'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext('local_aiconnect/public_base_url', get_string('public_base_url', 'local_aiconnect'), get_string('public_base_url_desc', 'local_aiconnect'), '', PARAM_URL));
    $settings->add(new admin_setting_configtext('local_aiconnect/ratelimit_minute', get_string('ratelimit_minute', 'local_aiconnect'), '', '50', PARAM_INT));
    $settings->add(new admin_setting_configtext('local_aiconnect/ratelimit_hour', get_string('ratelimit_hour', 'local_aiconnect'), '', '1000', PARAM_INT));
}
