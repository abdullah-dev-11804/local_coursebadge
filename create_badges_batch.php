<?php
// File: local/coursebadge/create_badges_batch.php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/coursebadge/locallib.php');
require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->libdir . '/filelib.php');

// Configuration constants.
define('BATCH_SIZE', 20); // Number of courses to process per batch.
define('PURGE_CACHES', true); // Set to false to disable cache purging during testing.

echo "Starting CLI script to create course badges in batches...\n";
error_log("CLI script create_badges_batch.php started at " . date('Y-m-d H:i:s'));

try {
    global $DB, $CFG, $USER;

    require_once($CFG->libdir . '/clilib.php');
    
    $guest = guest_user();

    // Set up the user session
    \core\session\manager::init_empty_session();
    \core\session\manager::set_user($guest);

    global $USER;

    if (empty($CFG->enablebadges)) {
        throw new Exception("Badges are disabled in Moodle configuration (enablebadges)");
    }
    if (empty($CFG->badges_allowcoursebadges)) {
        throw new Exception("Course badges are disabled in Moodle configuration (badges_allowcoursebadges)");
    }
    if (empty($CFG->enablecompletion)) {
        throw new Exception("Completion tracking is disabled in Moodle configuration (enablecompletion)");
    }
    echo "All required Moodle settings are enabled\n";
    error_log("CLI: All required Moodle settings are enabled");

    // Initialize counters.
    $processed_courses = 0;
    $created_badges = 0;
    $awarded_badges = 0;
    $offset = 0;
    
    // Get total courses with completion enabled, excluding front page.
    $total_courses = $DB->count_records_sql(
        'SELECT COUNT(*)
         FROM {course}
         WHERE enablecompletion = :enablecompletion AND id != :frontpage',
        ['enablecompletion' => 1, 'frontpage' => 1]
    );
    echo "Found $total_courses courses with completion enabled\n";
    error_log("CLI: Found $total_courses courses with completion enabled");

    // Process courses in batches.
    while ($offset < $total_courses) {
        try {
            $sql = "SELECT id, fullname
                    FROM {course}
                    WHERE enablecompletion = :enablecompletion AND id != :frontpage
                    LIMIT " . BATCH_SIZE . " OFFSET " . $offset;
            $courses = $DB->get_records_sql($sql, ['enablecompletion' => 1, 'frontpage' => 1]);
        } catch (dml_exception $e) {
            throw new Exception("Failed to fetch course batch at offset $offset: " . $e->getMessage());
        }
        $batch_count = count($courses);
        if ($batch_count == 0) {
            break; // No more courses.
        }
        echo "Processing batch of $batch_count courses (offset: $offset)\n";
        error_log("CLI: Processing batch of $batch_count courses (offset: $offset)");

        foreach ($courses as $course) {
            $processed_courses++;
            echo "Processing course $processed_courses/$total_courses: ID = {$course->id}, Name = {$course->fullname}\n";
            error_log("CLI: Processing course ID = {$course->id}, Name = {$course->fullname}");

            // Check for existing active badge.
            $badgename = 'Course Completed - ' . clean_filename($course->fullname);
            $sql = "SELECT 1 FROM {badge} WHERE courseid = :courseid AND name = :name AND status IN (:status1, :status2)";
            $params = [
                'courseid' => $course->id,
                'name' => $badgename,
                'status1' => BADGE_STATUS_ACTIVE,
                'status2' => BADGE_STATUS_ACTIVE_LOCKED, // Replace with the actual constant
            ];
            
            if ($DB->record_exists_sql($sql, $params)) {
                error_log("CLI: Active or locked badge already exists for course $course->id: $badgename");
                continue;
            }

            // Set course context.
            $context = context_course::instance($course->id, IGNORE_MISSING);
            if (!$context) {
                error_log("CLI: Failed to get context for course $course->id");
                continue;
            }
            // Delete inactive or locked badges.
            foreach ($existing_badges as $old_badge) {
                if ($old_badge->status != 1 && $old_badge->status != 2) {  // Not active or active_locked
                    $badge_instance = new badge($old_badge->id);
                    $badge_instance->delete(false); // Delete without user confirmation
                    echo "Deleted inactive badge ID {$old_badge->id} for course $course->id\n";
                    error_log("CLI: Deleted inactive badge ID {$old_badge->id} for course $course->id");
                }
            }
            
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
                error_log("CLI: Failed to create badge $badgename for course $course->id");
                continue;
            }
            $created_badges++;
            echo "Badge created: $badgename, ID = $badgeid\n";
            error_log("CLI: Badge created: $badgename, ID = $badgeid");

            // Trigger badge_created event.
            $event = \core\event\badge_created::create(['objectid' => $badgeid, 'context' => $context]);
            $event->trigger();
            error_log("CLI: Badge created event triggered for badge ID = $badgeid");
            echo 'after this i guess';
            // Load badge instance.
            $badge = new \core_badges\badge($badgeid);
            echo 'after this i guess';
            // Process badge image.
            $badgeimage = $CFG->dirroot . '/local/coursebadge/pix/badge.png';
            if (file_exists($badgeimage)) {
                $tempfile = tempnam(sys_get_temp_dir(), 'badge_');
                if (copy($badgeimage, $tempfile)) {
                    
                    badges_process_badge_image($badge, $tempfile);
                    echo 'after this i guess';
                    error_log("CLI: Badge image processed for $badgename");
                    unlink($tempfile);
                } else {
                    error_log("CLI: Failed to copy badge image to $tempfile for $badgename");
                }
            } else {
                error_log("CLI: Badge image not found at $badgeimage");
            }
            echo 'after this i guess';
            // Add overall criterion.
            $overallcrit = \award_criteria::build([
                'badgeid' => $badge->id,
                'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
            ]);
            $overallcrit->save(['agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
            error_log("CLI: Overall criterion saved for badge: $badgename");

            // Add course completion criterion.
            $coursecrit = \award_criteria::build([
                'badgeid' => $badge->id,
                'criteriatype' => BADGE_CRITERIA_TYPE_COURSE,
            ]);
            $coursecrit->save(['course_' . $course->id => $course->id, 'agg' => BADGE_CRITERIA_AGGREGATION_ALL]);
            error_log("CLI: Course criterion saved for badge: $badgename");

            // Reload badge to ensure criteria are loaded.
            $badge = new \core_badges\badge($badge->id);
             
            // Activate badge and award to eligible enrolled users.
            if ($badge->has_criteria()) {
                $badge->set_status(BADGE_STATUS_ACTIVE);
                echo "Badge activated: $badgename\n";
                error_log("CLI: Badge status set to active for $badgename");

                // Review all criteria and award badges to eligible users.
                $awards = $badge->review_all_criteria();
                $awarded_badges += $awards;
                echo "Badge $badgename awarded to $awards users\n";
                error_log("CLI: Badge $badgename awarded to $awards users");
            } else {
                error_log("CLI: Cannot activate badge $badgename: No criteria defined");
            }
        }

        $offset += BATCH_SIZE;
        $remaining_courses = max(0, $total_courses - $offset);

        // Clear caches if enabled.
        if (PURGE_CACHES) {
            echo "Clearing caches...\n";
            error_log("CLI: Clearing caches");
            purge_all_caches();
        }

        // Prompt to continue if courses remain.
        if ($remaining_courses > 0) {
            echo "Processed $processed_courses/$total_courses courses, created $created_badges badges, awarded $awarded_badges badges.\n";
            echo "$remaining_courses courses remain. Continue? (y/n): ";
            $handle = fopen("php://stdin", "r");
            $response = trim(fgets($handle));
            fclose($handle);

            if (strtolower($response) !== 'y') {
                echo "Exiting as per admin request.\n";
                error_log("CLI: Admin stopped processing at offset $offset");
                break;
            }
            echo "Continuing with next batch...\n";
            error_log("CLI: Continuing at offset $offset");
        }
    }

    // Final summary.
    echo "Completed: Processed $processed_courses/$total_courses courses, created $created_badges badges, awarded $awarded_badges badges.\n";
    error_log("CLI: Completed: Processed $processed_courses/$total_courses courses, created $created_badges badges, awarded $awarded_badges badges");
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    error_log("CLI: Error: {$e->getMessage()}");
}

echo "Script execution finished.\n";
error_log("CLI script create_badges_batch.php finished at " . date('Y-m-d H:i:s'));