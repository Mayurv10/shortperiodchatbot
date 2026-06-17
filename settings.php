<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_chatbot',
        get_string('pluginname', 'local_chatbot')
    );

    // Backend URL setting (Fix #8)
    $settings->add(new admin_setting_configtext(
        'local_chatbot/backend_url',
        get_string('backend_url', 'local_chatbot'),
        get_string('backend_url_desc', 'local_chatbot'),
        \local_chatbot\config::DEFAULT_BACKEND_URL,  // default value
        PARAM_URL
    ));

    $ADMIN->add('localplugins', $settings);
}
