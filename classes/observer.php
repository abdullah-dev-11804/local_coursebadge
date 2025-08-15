<?php
defined('MOODLE_INTERNAL') || die();

use core_badges\badge;
use context_course;

class local_coursebadge_observer {

/**
 * Handles the course_created event to create a course completion badge.
 *
 * @param \core\event\course_created $event The course created event.
 */
public static function course_created(\core\event\course_created $event) {
    global $DB, $CFG, $USER;

    require_once($CFG->libdir . '/badgeslib.php');
    require_once($CFG->libdir . '/filelib.php');

    // Log start of event handler.
    error_log("course_created: Processing course_created event for course ID = {$event->objectid}");

    // Check if badges, course badges, and completion are enabled.
    if (empty($CFG->enablebadges)) {
        error_log("course_created: Badges are disabled in Moodle configuration (enablebadges)");
        return;
    }
    if (empty($CFG->badges_allowcoursebadges)) {
        error_log("course_created: Course badges are disabled in Moodle configuration (badges_allowcoursebadges)");
        return;
    }
    if (empty($CFG->enablecompletion)) {
        error_log("course_created: Completion tracking is disabled in Moodle configuration (enablecompletion)");
        return;
    }

    // Fetch course details.
    $courseid = $event->objectid;
    $course = $DB->get_record('course', ['id' => $courseid, 'enablecompletion' => 1], 'id, fullname');
    if (!$course) {
        error_log("course_created: Course $courseid does not have completion enabled, skipping badge creation");
        return;
    }

    // Check for existing badge.
    $badgename = 'Course Completed - ' . clean_filename($course->fullname);
    if ($DB->record_exists('badge', ['courseid' => $course->id, 'name' => $badgename])) {
        error_log("course_created: Badge already exists for course $course->id: $badgename");
        return;
    }

    // Set course context and check permissions.
    $context = context_course::instance($course->id, IGNORE_MISSING);
    if (!$context) {
        error_log("course_created: Failed to get context for course $course->id");
        return;
    }
    // if (!has_capability('moodle/badges:configurecriteria', $context, $USER)) {
    //     error_log("course_created: User lacks moodle/badges:configurecriteria capability for course: $course->id");
    //     return;
    // }
    // error_log("course_created: Permission check passed for course $course->id");

    // Prepare badge data.
    $now = time();
    $badge_data = (object)[
        'name' => $badgename,
        'description' => "Awarded upon completion of the {$course->fullname} course and all its components.",
        'issuername' => get_config('badges', 'defaultissuername') ?: 'Moodle',
        'issuerurl' => get_config('badges', 'defaultissuerurl') ?: $CFG->wwwroot,
        'issuercontact' => get_config('badges', 'defaultissuercontact') ?: '',
        'version' => '2.0',
        'language' => current_language(),
        'imageauthorname' => '',
        'imageauthoremail' => '',
        'imageauthorurl' => '',
        'imagecaption' => '',
        'expiry' => 0,
    ];

    // Create badge record.
    $fordb = new stdClass();
    $fordb->id = null;
    $fordb->courseid = $course->id;
    $fordb->type = BADGE_TYPE_COURSE;
    $fordb->name = trim($badge_data->name);
    $fordb->version = $badge_data->version;
    $fordb->language = $badge_data->language;
    $fordb->description = $badge_data->description;
    $fordb->imageauthorname = $badge_data->imageauthorname;
    $fordb->imageauthoremail = $badge_data->imageauthoremail;
    $fordb->imageauthorurl = $badge_data->imageauthorurl;
    $fordb->imagecaption = $badge_data->imagecaption;
    $fordb->timecreated = $now;
    $fordb->timemodified = $now;
    $fordb->usercreated = $USER->id;
    $fordb->usermodified = $USER->id;
    $fordb->issuername = $badge_data->issuername;
    $fordb->issuerurl = $badge_data->issuerurl;
    $fordb->issuercontact = $badge_data->issuercontact;
    $fordb->expiredate = null;
    $fordb->expireperiod = null;
    $fordb->messagesubject = get_string('messagesubject', 'badges');
    $fordb->message = get_string('messagebody', 'badges', html_writer::link(
        $CFG->wwwroot . '/badges/mybadges.php',
        get_string('managebadges', 'badges')
    ));
    $fordb->attachment = 1;
    $fordb->notification = BADGE_MESSAGE_NEVER;
    $fordb->status = BADGE_STATUS_INACTIVE;

    $badgeid = $DB->insert_record('badge', $fordb, true);
    if (!$badgeid) {
        error_log("course_created: Failed to create badge $badgename for course $course->id");
        return;
    }
    error_log("course_created: Badge created: $badgename, ID = $badgeid");

    // Trigger badge_created event.
    $eventparams = ['objectid' => $badgeid, 'context' => $context];
    $event = \core\event\badge_created::create($eventparams);
    $event->trigger();
    error_log("course_created: Badge created event triggered for badge ID = $badgeid");

    // Load badge instance.
    $badge = new \core_badges\badge($badgeid);

    // Process badge image.
    $badgeimage = $CFG->dirroot . '/local/coursebadge/pix/badge.png';
    if (file_exists($badgeimage)) {
        $tempfile = tempnam(sys_get_temp_dir(), 'badge_');
        if (copy($badgeimage, $tempfile)) {
            try {
                badges_process_badge_image($badge, $tempfile);
                error_log("course_created: Badge image processed for $badgename");
            } catch (\Exception $e) {
                error_log("course_created: Failed to process badge image for $badgename: {$e->getMessage()}");
            }
            unlink($tempfile);
            error_log("course_created: Temp file deleted: $tempfile");
        } else {
            error_log("course_created: Failed to copy badge image to $tempfile for $badgename");
        }
    } else {
        error_log("course_created: Badge image not found at $badgeimage");
    }

    // Add overall criterion.
    $overallcrit = \award_criteria::build([
        'badgeid' => $badge->id,
        'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
    ]);
    $overallcrit->save(['agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
    error_log("course_created: Overall criterion saved for badge: $badgename");

    // Add course completion criterion.
    $coursecrit = \award_criteria::build([
        'badgeid' => $badge->id,
        'criteriatype' => BADGE_CRITERIA_TYPE_COURSE,
    ]);
    $coursecrit->save(['course_' . $course->id => $course->id, 'agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
    error_log("course_created: Course criterion saved for badge: $badgename");

    // Reload badge to ensure criteria are loaded.
    $badge = new \core_badges\badge($badge->id);

    // Verify criteria.
    $overall_exists = $DB->record_exists('badge_criteria', ['badgeid' => $badge->id, 'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL]);
    $course_exists = $DB->record_exists('badge_criteria', ['badgeid' => $badge->id, 'criteriatype' => BADGE_CRITERIA_TYPE_COURSE]);
    error_log("course_created: Criteria check for $badgename: Overall exists = " . ($overall_exists ? 'true' : 'false') . ", Course exists = " . ($course_exists ? 'true' : 'false') . ", API has_criteria = " . ($badge->has_criteria() ? 'true' : 'false'));

    // Activate badge.
    if ($badge->has_criteria()) {
        $status = ($badge->status == BADGE_STATUS_INACTIVE) ? BADGE_STATUS_ACTIVE : BADGE_STATUS_ACTIVE_LOCKED;
        $badge->set_status($status);
        error_log("course_created: Badge status set to $status for $badgename");
    } else {
        error_log("course_created: Cannot activate badge $badgename: No criteria defined");
    }
}
    public static function badge_awarded(\core\event\badge_awarded $event) {
        global $DB, $CFG, $USER;
        require_once($CFG->libdir . '/badgeslib.php');

        // Start output buffering.
        ob_start();
        error_log("badge_awarded: Event triggered at " . date('Y-m-d H:i:s') . " for badge ID = " . $event->objectid . ", user ID = " . $event->relateduserid);

        // Log event details.
        error_log("badge_awarded: Event details - Context ID: {$event->contextid}, Object ID: {$event->objectid}, Related User ID: {$event->relateduserid}, Other: " . json_encode($event->other));

        // Check if badges are enabled.
        if (empty($CFG->enablebadges)) {
            error_log("badge_awarded: Badges are disabled in Moodle configuration");
            ob_end_clean();
            return;
        }

        // Check if the awarded badge is a course badge (type = 2).
        $badge = $DB->get_record('badge', ['id' => $event->objectid]);
        if (!$badge) {
            error_log("badge_awarded: Badge ID = $event->objectid does not exist");
            ob_end_clean();
            return;
        }
        if ($badge->type != BADGE_TYPE_COURSE) {
            error_log("badge_awarded: Badge ID = $event->objectid is not a course badge (Type = {$badge->type}, Name = {$badge->name})");
            ob_end_clean();
            return;
        }
        error_log("badge_awarded: Confirmed course badge: ID = {$badge->id}, Name = {$badge->name}");

        $userid = $event->relateduserid;

        // Verify user exists.
        if (!$DB->record_exists('user', ['id' => $userid])) {
            error_log("badge_awarded: User ID = $userid does not exist");
            ob_end_clean();
            return;
        }

        // Count the user’s course badges.
        $course_badge_count = $DB->count_records_sql(
            'SELECT COUNT(*)
             FROM {badge_issued} bi
             JOIN {badge} b ON b.id = bi.badgeid
             WHERE bi.userid = :userid AND b.type = :badgetype',
            ['userid' => $userid, 'badgetype' => BADGE_TYPE_COURSE]
        );
        error_log("badge_awarded: User ID = $userid has $course_badge_count course badges");

        // Get site badge settings.
        $badges_to_check = [
            'silver' => [
                'id' => get_config('local_coursebadge', 'silverbadgeid'),
                'count' => get_config('local_coursebadge', 'silverbadgecount'),
            ],
            'gold' => [
                'id' => get_config('local_coursebadge', 'goldbadgeid'),
                'count' => get_config('local_coursebadge', 'goldbadgecount'),
            ],
            'bronze' => [
                'id' => get_config('local_coursebadge', 'bronzebadgeid'),
                'count' => get_config('local_coursebadge', 'bronzebadgecount'),
            ],
        ];
        error_log("badge_awarded: Settings - Silver ID: {$badges_to_check['silver']['id']}, Count: {$badges_to_check['silver']['count']}, Gold ID: {$badges_to_check['gold']['id']}, Count: {$badges_to_check['gold']['count']}, Bronze ID: {$badges_to_check['bronze']['id']}, Count: {$badges_to_check['bronze']['count']}");
        // Check and award site badges.
        foreach ($badges_to_check as $badgename => $badgeinfo) {
            if (empty($badgeinfo['id']) || empty($badgeinfo['count'])) {
                error_log("badge_awarded: $badgename badge settings not configured (ID: {$badgeinfo['id']}, Count: {$badgeinfo['count']})");
                continue;
            }
            error_log("badge_awarded: Checking $badgename badge (ID = {$badgeinfo['id']}, Required count = {$badgeinfo['count']})");
            if ($course_badge_count >= $badgeinfo['count']) {
                // Check if the user already has this badge.
                if ($DB->record_exists('badge_issued', ['badgeid' => $badgeinfo['id'], 'userid' => $userid])) {
                    error_log("badge_awarded: User ID = $userid already has $badgename badge (ID = {$badgeinfo['id']})");
                    continue;
                }
                // Load the site badge.
                try {
                    $site_badge = new \core_badges\badge($badgeinfo['id']);
                } catch (\Exception $e) {
                    error_log("badge_awarded: Failed to load $badgename badge (ID = {$badgeinfo['id']}): " . $e->getMessage());
                    continue;
                }
                // Check badge status.
                if ($site_badge->status != BADGE_STATUS_ACTIVE && $site_badge->status != BADGE_STATUS_ACTIVE_LOCKED) {
                    error_log("badge_awarded: $badgename badge (ID = {$badgeinfo['id']}) is not active (Status = {$site_badge->status})");
                    continue;
                }
        try {
            $site_badge->issue($userid, false);
            error_log("badge_awarded: Successfully awarded $name badge (ID = {$badgeinfo['id']}) to user ID = $userid");
        } catch (\Exception $e) {
            error_log("badge_awarded: Failed to award $name badge to user ID = $userid: " . $e->getMessage());
        }
        }
     }
    }
}