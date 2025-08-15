<?php
// File: local/coursebadge/settings.php
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/classes/admin_setting_configcoursecount.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coursebadge', get_string('pluginname', 'local_coursebadge'));

    // Checkbox to trigger course badge creation.
    $name = 'local_coursebadge/createbadges';
    $title = get_string('createbadges', 'local_coursebadge');
    $description = get_string('createbadges_desc', 'local_coursebadge');
    $default = 0;
    $settings->add(new admin_setting_configcheckbox($name, $title, $description, $default));

    // Settings for Silver Badge.
    $settings->add(new admin_setting_configtext(
        'local_coursebadge/silverbadgeid',
        get_string('silverbadgeid', 'local_coursebadge'),
        get_string('silverbadgeid_desc', 'local_coursebadge'),
        '',
        PARAM_INT
    ));
    $settings->add(new \local_coursebadge\admin_setting_configcoursecount(
        'local_coursebadge/silverbadgecount',
        get_string('silverbadgecount', 'local_coursebadge'),
        get_string('silverbadgecount_desc', 'local_coursebadge'),
        1
    ));

    // Settings for Gold Badge.
    $settings->add(new admin_setting_configtext(
        'local_coursebadge/goldbadgeid',
        get_string('goldbadgeid', 'local_coursebadge'),
        get_string('goldbadgeid_desc', 'local_coursebadge'),
        '',
        PARAM_INT
    ));
    $settings->add(new \local_coursebadge\admin_setting_configcoursecount(
        'local_coursebadge/goldbadgecount',
        get_string('goldbadgecount', 'local_coursebadge'),
        get_string('goldbadgecount_desc', 'local_coursebadge'),
        1
    ));

    // Settings for Bronze Badge.
    $settings->add(new admin_setting_configtext(
        'local_coursebadge/bronzebadgeid',
        get_string('bronzebadgeid', 'local_coursebadge'),
        get_string('bronzebadgeid_desc', 'local_coursebadge'),
        '',
        PARAM_INT
    ));
    $settings->add(new \local_coursebadge\admin_setting_configcoursecount(
        'local_coursebadge/bronzebadgecount',
        get_string('bronzebadgecount', 'local_coursebadge'),
        get_string('bronzebadgecount_desc', 'local_coursebadge'),
        1
    ));

    $ADMIN->add('localplugins', $settings);

    // Trigger course badge creation if checkbox is checked.
    if ($data = data_submitted() && confirm_sesskey()) {
        $newvalue = optional_param('s_local_coursebadge_createbadges', 0, PARAM_INT);
        $oldvalue = get_config('local_coursebadge', 'createbadges');
        if ($newvalue == 1 && $oldvalue != 1) {
            try {
                create_badges_for_all_courses();
                set_config('createbadges', 0, 'local_coursebadge');
            } catch (Exception $e) {
                debugging('Error creating badges: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}