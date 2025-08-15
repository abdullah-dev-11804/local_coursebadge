<?php
// File: local/coursebadge/db/events.php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\course_created',
        'callback'    => 'local_coursebadge_observer::course_created',
        'includefile' => '/local/coursebadge/classes/observer.php',
    ],
    [
        'eventname'   => '\core\event\badge_awarded',
        'callback'    => 'local_coursebadge_observer::badge_awarded',
        'includefile' => '/local/coursebadge/classes/observer.php',
    ],
];