<?php
// File: local/coursebadge/locallib.php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->libdir . '/filelib.php');

function create_badges_for_all_courses() {
    global $DB, $CFG, $USER;

    // Check global Moodle settings.
    if (empty($CFG->enablebadges)) {
        error_log("create_badges_for_all_courses: Badges are disabled in Moodle configuration (enablebadges)");
        return;
    }
    if (empty($CFG->badges_allowcoursebadges)) {
        error_log("create_badges_for_all_courses: Course badges are disabled in Moodle configuration (badges_allowcoursebadges)");
        return;
    }
    if (empty($CFG->enablecompletion)) {
        error_log("create_badges_for_all_courses: Completion tracking is disabled in Moodle configuration (enablecompletion)");
        return;
    }
    error_log("create_badges_for_all_courses: All required Moodle settings are enabled");

    // Get all courses with completion enabled, excluding front page.
    $courses = $DB->get_records_sql('SELECT id, fullname FROM {course} WHERE enablecompletion = :enablecompletion AND id != :frontpage', [
        'enablecompletion' => 1,
        'frontpage' => 1,
    ]);
    error_log("create_badges_for_all_courses: Found " . count($courses) . " courses with completion enabled");

    if (empty($courses)) {
        error_log("create_badges_for_all_courses: No courses found with enablecompletion = 1");
        return;
    }

    // Set PHP limits for large datasets.
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '512M');

    foreach ($courses as $course) {
        error_log("create_badges_for_all_courses: Processing course: ID = $course->id, Name = $course->fullname");

        // Check if badge already exists and is active.
        $badgename = 'Course Completed - ' . clean_filename($course->fullname);
        if ($DB->record_exists('badge', ['courseid' => $course->id, 'name' => $badgename, 'status' => 1])) {
            error_log("create_badges_for_all_courses: Active badge already exists for course $course->id: $badgename");
            continue;
        }

        // Set course context for permissions.
        try {
            $context = context_course::instance($course->id);
        } catch (\Exception $e) {
            error_log("create_badges_for_all_courses: Failed to get context for course $course->id: {$e->getMessage()}");
            continue;
        }

        if (!has_capability('moodle/badges:configurecriteria', $context, $USER)) {
            error_log("create_badges_for_all_courses: User lacks moodle/badges:configurecriteria capability for course: $course->id");
            continue;
        }
        error_log("create_badges_for_all_courses: Permission check passed for course $course->id");

        // Delete any inactive or locked badges for this course to recreate them.
        $existing_badges = $DB->get_records('badge', ['courseid' => $course->id, 'name' => $badgename]);
        foreach ($existing_badges as $old_badge) {
            if ($old_badge->status != 1) {
                $DB->delete_records('badge_criteria', ['badgeid' => $old_badge->id]);
                $DB->delete_records('badge', ['id' => $old_badge->id]);
                error_log("create_badges_for_all_courses: Deleted inactive badge ID {$old_badge->id} for course $course->id");
            }
        }

        // Prepare badge data.
        $now = time();
        $data = (object)[
            'name' => $badgename,
            'description' => "This course completion badge is awarded upon completion of the {$course->fullname} course and all the components therein.",
            'issuername' => get_config('badges', 'defaultissuername'),
            'issuerurl' => get_config('badges', 'defaultissuerurl'),
            'issuercontact' => get_config('badges', 'defaultissuercontact'),
            'version' => '2.0',
            'language' => current_language(),
            'imageauthorname' => '',
            'imageauthoremail' => '',
            'imageauthorurl' => '',
            'imagecaption' => '',
            'expiry' => 0,
            'tags' => [],
        ];

        // Create badge manually.
        $fordb = new stdClass();
        $fordb->id = null;
        $fordb->courseid = $course->id;
        $fordb->type = BADGE_TYPE_COURSE;
        $fordb->name = trim($data->name);
        $fordb->version = $data->version;
        $fordb->language = $data->language;
        $fordb->description = $data->description;
        $fordb->imageauthorname = $data->imageauthorname;
        $fordb->imageauthoremail = $data->imageauthoremail;
        $fordb->imageauthorurl = $data->imageauthorurl;
        $fordb->imagecaption = $data->imagecaption;
        $fordb->timecreated = $now;
        $fordb->timemodified = $now;
        $fordb->usercreated = $USER->id;
        $fordb->usermodified = $USER->id;
        $fordb->issuername = $data->issuername;
        $fordb->issuerurl = $data->issuerurl;
        $fordb->issuercontact = $data->issuercontact;
        $fordb->expiredate = null;
        $fordb->expireperiod = null;
        $fordb->messagesubject = get_string('messagesubject', 'badges');
        $fordb->message = get_string('messagebody', 'badges',
            html_writer::link($CFG->wwwroot . '/badges/mybadges.php', get_string('managebadges', 'badges')));
        $fordb->attachment = 1;
        $fordb->notification = BADGE_MESSAGE_NEVER;
        $fordb->status = 0; // Start as inactive.

        try {
            $badgeid = $DB->insert_record('badge', $fordb, true);
            error_log("create_badges_for_all_courses: Badge created: $badgename, ID = $badgeid");
        } catch (\Exception $e) {
            error_log("create_badges_for_all_courses: Failed to create badge $badgename: {$e->getMessage()}");
            continue;
        }

        // Trigger badge_created event.
        try {
            $eventparams = ['objectid' => $badgeid, 'context' => $context];
            $event = \core\event\badge_created::create($eventparams);
            $event->trigger();
            error_log("create_badges_for_all_courses: Badge created event triggered for badge ID = $badgeid");
        } catch (\Exception $e) {
            error_log("create_badges_for_all_courses: Failed to trigger badge_created event for $badgename: {$e->getMessage()}");
        }

        // Load badge instance.
        $badge = new \core_badges\badge($badgeid);

        // Process badge image.
        $badgeimage = $CFG->dirroot . '/local/coursebadge/pix/badge.png';
        if (file_exists($badgeimage)) {
            $tempfile = tempnam(sys_get_temp_dir(), 'badge_');
            error_log("create_badges_for_all_courses: Creating temp file: $tempfile for badge: $badgename");
            if (copy($badgeimage, $tempfile)) {
                error_log("create_badges_for_all_courses: Temp file copied successfully: $tempfile");
                try {
                    badges_process_badge_image($badge, $tempfile);
                    error_log("create_badges_for_all_courses: Badge image processed for $badgename");
                } catch (\Exception $e) {
                    error_log("create_badges_for_all_courses: Failed to process badge image for $badgename: {$e->getMessage()}");
                }
                if (file_exists($tempfile)) {
                    unlink($tempfile);
                    error_log("create_badges_for_all_courses: Temp file deleted: $tempfile");
                }
            } else {
                error_log("create_badges_for_all_courses: Failed to copy badge image to temp file: $tempfile for badge: $badgename");
            }
        } else {
            error_log("create_badges_for_all_courses: Badge image not found at $badgeimage");
        }

        // Add overall criterion.
        try {
            if (empty($badge->criteria)) {
                $overallcrit = \award_criteria::build([
                    'badgeid' => $badge->id,
                    'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
                ]);
                $overallcrit->save(['agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
                error_log("create_badges_for_all_courses: Overall criterion saved for badge: $badgename");
            }
        } catch (\Exception $e) {
            error_log("create_badges_for_all_courses: Failed to save overall criterion for badge $badgename: {$e->getMessage()}");
            continue;
        }

        // Add course completion criterion.
        try {
            $coursecrit = \award_criteria::build([
                'badgeid' => $badge->id,
                'criteriatype' => BADGE_CRITERIA_TYPE_COURSE,
            ]);
            $coursecrit->save(['course_' . $course->id => $course->id, 'agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
            error_log("create_badges_for_all_courses: Course criterion saved for badge: $badgename");
        } catch (\Exception $e) {
            error_log("create_badges_for_all_courses: Failed to save course criterion for badge $badgename: {$e->getMessage()}");
            continue;
        }

        // Reload badge to ensure criteria are loaded.
        $badge = new \core_badges\badge($badge->id);

        // Verify criteria.
        $overall_exists = $DB->record_exists('badge_criteria', ['badgeid' => $badge->id, 'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL]);
        $course_exists = $DB->record_exists('badge_criteria', ['badgeid' => $badge->id, 'criteriatype' => BADGE_CRITERIA_TYPE_COURSE]);
        error_log("create_badges_for_all_courses: Criteria check for $badgename: Overall exists = " . ($overall_exists ? 'true' : 'false') . ", Course exists = " . ($course_exists ? 'true' : 'false') . ", API has_criteria = " . ($badge->has_criteria() ? 'true' : 'false'));

        // Log criteria details.
        $criteria = $DB->get_records('badge_criteria', ['badgeid' => $badge->id]);
        foreach ($criteria as $crit) {
            $params = $DB->get_records('badge_criteria_param', ['critid' => $crit->id]);
            error_log("create_badges_for_all_courses: Criteria for $badgename: critid = $crit->id, type = $crit->criteriatype, params = " . json_encode($params));
        }

        // Activate badge and award to eligible users.
        try {
            if ($badge->has_criteria()) {
                $badge->set_status(1); // Set to active (status = 1).
                error_log("create_badges_for_all_courses: Badge status set to 1 (active) for $badgename");

                // Review criteria and award to eligible users.
                $awards = [];
                $users = $DB->get_records('user', ['deleted' => 0, 'suspended' => 0]);
                foreach ($users as $user) {
                    if ($badge->review_all_criteria($user->id)) {
                        $badge->issue($user->id, false);
                        $awards[] = $user->id;
                        error_log("create_badges_for_all_courses: Badge $badgename awarded to user ID = $user->id during activation");
                    }
                }
                error_log("create_badges_for_all_courses: Badge $badgename activated with " . count($awards) . " awards");
            } else {
                error_log("create_badges_for_all_courses: Cannot activate badge $badgename: No criteria defined");
            }
        } catch (\Exception $e) {
            error_log("create_badges_for_all_courses: Failed to activate or award badge $badgename: {$e->getMessage()}");
        }
    }
}
?>