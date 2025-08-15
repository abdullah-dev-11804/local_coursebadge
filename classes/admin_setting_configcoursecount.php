<?php
// File: local/coursebadge/classes/admin_setting_configcoursecount.php
namespace local_coursebadge;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

class admin_setting_configcoursecount extends \admin_setting_configtext {
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT);
    }

    public function validate($data) {
        global $DB;

        // Validate that the input is a positive integer.
        if (!is_numeric($data) || $data < 1) {
            return get_string('validationerror_positiveinteger', 'local_coursebadge');
        }

        // Get the total number of course badges.
        $course_badge_count = $DB->count_records('badge', ['type' => BADGE_TYPE_COURSE]);
        if ($course_badge_count == 0) {
            return get_string('validationerror_nocoursebadges', 'local_coursebadge');
        }

        // Ensure the input is not greater than the total course badges.
        if ($data > $course_badge_count) {
            return get_string('validationerror_exceedscoursebadges', 'local_coursebadge', $course_badge_count);
        }

        return true;
    }
}