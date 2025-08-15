<?php
// File: local/coursebadge/award_site_badges_batch.php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/coursebadge/locallib.php');
require_once($CFG->libdir . '/badgeslib.php');

echo "Starting CLI script to award site badges for users in course batches...\n";
error_log("CLI script award_site_badges_batch.php started at " . date('Y-m-d H:i:s'));

try {
    global $DB, $CFG, $USER;
    // Check if badges are enabled.
    if (empty($CFG->enablebadges)) {
        throw new Exception("Badges are disabled in Moodle configuration");
    }
    echo "Badges are enabled\n";
    error_log("CLI: Badges are enabled");

    // Get site badge settings.
    $badges_to_check = [
        'bronze' => [
            'id' => get_config('local_coursebadge', 'bronzebadgeid'),
            'count' => get_config('local_coursebadge', 'bronzebadgecount'),
        ],
        'silver' => [
            'id' => get_config('local_coursebadge', 'silverbadgeid'),
            'count' => get_config('local_coursebadge', 'silverbadgecount'),
        ],
        'gold' => [
            'id' => get_config('local_coursebadge', 'goldbadgeid'),
            'count' => get_config('local_coursebadge', 'goldbadgecount'),
        ],
    ];
    echo "Site badge settings loaded: Bronze ID = {$badges_to_check['bronze']['id']}, Count = {$badges_to_check['bronze']['count']}, Silver ID = {$badges_to_check['silver']['id']}, Count = {$badges_to_check['silver']['count']}, Gold ID = {$badges_to_check['gold']['id']}, Count = {$badges_to_check['gold']['count']}\n";
    error_log("CLI: Site badge settings - Bronze ID: {$badges_to_check['bronze']['id']}, Count: {$badges_to_check['bronze']['count']}, Silver ID: {$badges_to_check['silver']['id']}, Count: {$badges_to_check['silver']['count']}, Gold ID: {$badges_to_check['gold']['id']}, Count: {$badges_to_check['gold']['count']}");

    // Batch size for courses.
    $batch_size = 20;
    $offset = 0;
    $processed_courses = 0;
    $processed_users = 0;
    $awarded = 0;

    // Get total courses with completion enabled, excluding front page.
    $total_courses = $DB->count_records_sql(
        'SELECT COUNT(*)
         FROM {course}
         WHERE enablecompletion = :enablecompletion AND id != :frontpage',
        ['enablecompletion' => 1, 'frontpage' => 1]
    );
    echo "Found $total_courses courses with completion enabled\n";
    error_log("CLI: Found $total_courses courses with completion enabled");

    while ($offset < $total_courses) {
        // Get a batch of courses with raw LIMIT and OFFSET.
        $sql = "SELECT id, fullname
                FROM {course}
                WHERE enablecompletion = :enablecompletion AND id != :frontpage
                LIMIT $batch_size OFFSET $offset";
        $courses = $DB->get_records_sql($sql, ['enablecompletion' => 1, 'frontpage' => 1]);
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

            // Get enrolled users for this course (excluding guest user).
            $enrolled_users = $DB->get_records_sql(
                'SELECT DISTINCT u.id, u.username, u.email
                 FROM {user} u
                 JOIN {user_enrolments} ue ON ue.userid = u.id
                 JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                 AND u.deleted = 0 AND u.suspended = 0
                 AND u.id != 1 AND u.username != :guest',
                ['courseid' => $course->id, 'guest' => 'guest']
            );
            $user_count = count($enrolled_users);
            echo "Found $user_count enrolled users for course ID = {$course->id}\n";
            error_log("CLI: Found $user_count enrolled users for course ID = {$course->id}");

            foreach ($enrolled_users as $user) {
                $processed_users++;
                echo "Processing user: ID = {$user->id}, Email = {$user->email}\n";
                error_log("CLI: Processing user ID = {$user->id}, Email = {$user->email}");

                // Count the user’s course badges.
                $course_badge_count = $DB->count_records_sql(
                    'SELECT COUNT(*)
                     FROM {badge_issued} bi
                     JOIN {badge} b ON b.id = bi.badgeid
                     WHERE bi.userid = :userid AND b.type = :badgetype',
                    ['userid' => $user->id, 'badgetype' => BADGE_TYPE_COURSE]
                );
                echo "User ID = {$user->id} has $course_badge_count course badges\n";
                error_log("CLI: User ID = {$user->id} has $course_badge_count course badges");

                // Check and award site badges.
                foreach ($badges_to_check as $badgename => $badgeinfo) {
                    if (empty($badgeinfo['id']) || empty($badgeinfo['count'])) {
                        error_log("CLI: $badgename badge settings not configured (ID: {$badgeinfo['id']}, Count: {$badgeinfo['count']})");
                        continue;
                    }

                    error_log("CLI: Checking $badgename badge (ID = {$badgeinfo['id']}, Required count = {$badgeinfo['count']})");

                    if ($course_badge_count >= $badgeinfo['count']) {
                        // Check if the user already has this badge.
                        if ($DB->record_exists('badge_issued', ['badgeid' => $badgeinfo['id'], 'userid' => $user->id])) {
                            error_log("CLI: User ID = {$user->id} already has $badgename badge (ID = {$badgeinfo['id']})");
                            continue;
                        }

                        // Load the site badge.
                        try {
                            $site_badge = new \core_badges\badge($badgeinfo['id']);
                        } catch (\Exception $e) {
                            error_log("CLI: Failed to load $badgename badge (ID = {$badgeinfo['id']}): " . $e->getMessage());
                            continue;
                        }

                        // Check badge status.
                        if ($site_badge->status != BADGE_STATUS_ACTIVE && $site_badge->status != BADGE_STATUS_ACTIVE_LOCKED) {
                            error_log("CLI: $badgename badge (ID = {$badgeinfo['id']}) is not active (Status = {$site_badge->status})");
                            continue;
                        }

                        // Issue the site badge.
                        try {
                            $site_badge->issue($user->id, false);
                            $awarded++;
                            echo "Awarded $badgename badge (ID = {$badgeinfo['id']}) to user ID = {$user->id}\n";
                            error_log("CLI: Successfully awarded $badgename badge (ID = {$badgeinfo['id']}) to user ID = {$user->id}");
                        } catch (\Exception $e) {
                            error_log("CLI: Failed to award $badgename badge (ID = {$badgeinfo['id']}) to user ID = {$user->id}: " . $e->getMessage());
                        }
                    } else {
                        error_log("CLI: User ID = {$user->id} does not meet $badgename badge requirement (Has $course_badge_count, Needs {$badgeinfo['count']})");
                    }
                }
            }
        }

        $offset += $batch_size;
        $remaining_courses = max(0, $total_courses - $offset);

        // Clear caches after each batch.
        echo "Clearing caches...\n";
        error_log("CLI: Clearing caches");
        purge_all_caches();


        if ($remaining_courses > 0) {
            echo "Processed $processed_courses/$total_courses courses, $processed_users users, awarded $awarded site badges.\n";
            echo "$remaining_courses courses remain. Continue processing next batch? (y/n): ";
            $handle = fopen("php://stdin", "r");
            $response = trim(fgets($handle));
            fclose($handle);

            if (strtolower($response) !== 'y') {
                echo "User chose to stop processing. Exiting.\n";
                error_log("CLI: user chose to stop processing at offset $offset");
                break;
            }
            echo "Continuing with next batch...\n";
            error_log("CLI: User chose to continue at offset $offset");
        }
    }

    echo "Processed $processed_courses/$total_courses courses, $processed_users users, awarded $awarded site badges.\n";
    error_log("CLI: Processed $processed_courses/$total_courses courses, $processed_users users, awarded $awarded site badges at " . date('Y-m-d H:i:s'));
} catch (\Exception $e) {
    echo "Error during site badge awarding: {$e->getMessage()}\n";
    error_log("CLI: Error in award_site_badges_batch: {$e->getMessage()}");
}

echo "Script execution finished.\n";
error_log("CLI script award_site_badges_batch.php finished at " . date('Y-m-d H:i:s'));
?>