<?php
// File: local/coursebadge/delete_all_badges.php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/badgeslib.php');

echo "Starting CLI script to delete all badges...\n";
error_log("CLI script delete_all_badges.php started at " . date('Y-m-d H:i:s'));

try {
    global $DB, $CFG, $USER;

    if (empty($CFG->enablebadges)) {
        throw new Exception("Badges are disabled in Moodle configuration (enablebadges)");
    }

    // Configuration.
    $batch_size = 50; // Process badges in batches to manage memory usage.
    $offset = 0;
    $deleted_badges = 0;
    $failed_deletions = 0;

    // Get total number of badges.
    try {
        $total_badges = $DB->count_records('badge');
        echo "Found $total_badges badges to delete\n";
        error_log("CLI: Found $total_badges badges to delete");
    } catch (dml_exception $e) {
        throw new Exception("Failed to count badges: " . $e->getMessage());
    }

    // Process badges in batches.
    while ($offset < $total_badges) {
        // Fetch a batch of badges.
        try {
            $sql = "SELECT id, name, courseid, status
            FROM {badge}
            ORDER BY id
            LIMIT " . (int)$batch_size . " OFFSET " . (int)$offset;
            $badges = $DB->get_records_sql($sql);
        } catch (dml_exception $e) {
            throw new Exception("Failed to fetch badge batch at offset $offset: " . $e->getMessage());
        }

        $batch_count = count($badges);
        if ($batch_count == 0) {
            break; // No more badges.
        }

        echo "Processing batch of $batch_count badges (offset: $offset)\n";
        error_log("CLI: Processing batch of $batch_count badges (offset: $offset)");

        foreach ($badges as $badge_record) {
            // Load badge instance.
            try {
                $badge = new \core_badges\badge($badge_record->id);
                $badgename = $badge->name;
                $courseid = $badge->courseid ?: 'Site';

                // Delete the badge with full removal (no archiving).
                $badge->delete(false);
                $deleted_badges++;
                echo "Deleted badge: ID = {$badge->id}, Name = $badgename, Course ID: $courseid\n";
                error_log("CLI: Deleted badge: ID = {$badge->id}, Name = $badgename, Course ID: $courseid");
            } catch (Exception $e) {
                $failed_deletions++;
                error_log("CLI: Failed to delete badge ID {$badge_record->id} ($badgename, Course ID: $courseid): {$e->getMessage()}");
            }
        }

        $offset += $batch_size;
        $remaining_badges = max(0, $total_badges - $offset);

        // Clear caches after each batch to ensure consistency.
        echo "Clearing caches...\n";
        error_log("CLI: Clearing caches");
        purge_all_caches();

        // Prompt to continue if badges remain.
        if ($remaining_badges > 0) {
            echo "Processed $offset/$total_badges badges, deleted $deleted_badges badges, $failed_deletions failed.\n";
            echo "$remaining_badges badges remain. Continue? (y/n): ";
            $handle = fopen("php://stdin", "r");
            $response = trim(fgets($handle));
            fclose($handle);

            if (strtolower($response) !== 'y') {
                echo "Exiting as per user request.\n";
                error_log("CLI: User stopped processing at offset $offset");
                break;
            }
            echo "Continuing with next batch...\n";
            error_log("CLI: Continuing at offset $offset");
        }
    }

    // Final summary.
    echo "Completed: Processed $offset/$total_badges badges, deleted $deleted_badges badges, $failed_deletions failed.\n";
    error_log("CLI: Completed: Processed $offset/$total_badges badges, deleted $deleted_badges badges, $failed_deletions failed at " . date('Y-m-d H:i:s'));
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    error_log("CLI: Error in delete_all_badges: {$e->getMessage()}");
}

echo "Script execution finished.\n";
error_log("CLI script delete_all_badges.php finished at " . date('Y-m-d H:i:s'));
?>