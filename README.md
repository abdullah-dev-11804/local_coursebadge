
# Moodle Course Badge Plugin

The **Course Badge** plugin for Moodle enhances the badge system by automatically creating badges for courses with completion enabled and awarding site-level badges (Silver, Gold, Bronze) based on the number of course badges a user earns. It provides a flexible configuration interface for administrators and includes a CLI script for manual badge awarding.

The plugin is created to automate the badge awarding and creation on Moodle for large orgnaizations and websites. You can upload an image for the badge plugin in the pix plugin which will be used as a template for all the course badges.

## Features

 
-  **Automatic Course Badge Creation**: Creates badges for all courses with completion tracking enabled, either on-demand or when a new course is created.

-  **Site Badge Awarding**: Awards Silver, Gold, and Bronze site badges to users based on the number of course badges they’ve earned.

-  **Configurable Settings**: Allows administrators to specify site badge IDs and the required number of course badges for each site badge via a settings page.

-  **Event-Driven**: Listens for course creation (`\core\event\course_created`) and badge awarding (`\core\event\badge_awarded`) events to automate badge management.

-  **Input Validation**: Ensures course badge count settings are positive integers within the range of available course badges.

  

## Requirements

  

-  **Moodle Version**: 3.10 or later

-  **PHP**: 7.3 or later

-  **Badges Enabled**: Ensure badges are enabled in Moodle (`$CFG->enablebadges`) and course badges are allowed (`$CFG->badges_allowcoursebadges`).

  

## Installation

  

1.  **Download the Plugin**:

- Clone or download the plugin to your Moodle installation’s `local` directory:

```bash

git clone <repository-url> /var/www/html/moodle_new/local/coursebadge

```

- Or copy the plugin files to `/var/www/html/moodle_new/local/coursebadge`.

  

2.  **Install the Plugin**:

- Run the Moodle upgrade process:

```bash

php /var/www/html/moodle_new/admin/cli/upgrade.php

```

- Or navigate to **Site administration > Notifications** in the Moodle web interface.

  

3.  **Clear Cache**:

```bash

php /var/www/html/moodle_new/admin/cli/purge_caches.php

```

  

## Configuration

  

1.  **Create Site Badges**:

- Go to **Site administration > Badges > Add a new badge**.

- Create three site badges: Silver, Gold, and Bronze (type = Site, `type = 1`).

- Set each badge to **Available** (status = Active).

- Note the badge IDs from the URL (`badges/edit.php?id=X`) or query:

```sql

SELECT id, name  FROM mdl_badge WHERE  type  =  1;

```

  

2.  **Configure Plugin Settings**:

- Navigate to **Site administration > Plugins > Local plugins > Course Badge**.

- Configure the following:

-  **Create Badges for All Courses**: Check this box and save to create badges for all courses with completion enabled. The box auto-unchecks after execution.

-  **Silver/Gold/Bronze Badge ID**: Enter the badge ID for each site badge (e.g., 1, 2, 3).

-  **Silver/Gold/Bronze Badge Course Count**: Enter the number of course badges required to earn each site badge (e.g., 3 for Bronze, 5 for Silver, 7 for Gold). Must be between 1 and the total number of course badges.

- Save changes to apply the settings.

  

```

  

## Usage

  

### Automatic Course Badge Creation

- When the **Create Badges for All Courses** checkbox is enabled and saved, the plugin creates badges for all courses with completion tracking.

- New courses with completion enabled automatically receive a badge upon creation (via `\core\event\course_created`).

- Verify course badges:

```sql

SELECT id, name, status  FROM mdl_badge WHERE  type  =  2;

```

  

### Site Badge Awarding

- When a user earns a course badge (e.g., via course completion or manual award), the plugin checks their total course badge count.

- If the count meets or exceeds the configured threshold for Silver, Gold, or Bronze badges, the corresponding site badge is awarded (via `\core\event\badge_awarded`).

- Verify site badges:

```sql

SELECT  bi.userid, bi.badgeid, b.name

FROM mdl_badge_issued bi

JOIN mdl_badge b ON  b.id  =  bi.badgeid

WHERE  b.type  =  1;

```

 
## Debugging

  

-  **Settings Issues**:

- Ensure badge IDs match site badges and course counts are valid:

```sql

SELECT id, name, status  FROM mdl_badge WHERE  type  =  1;

SELECT  COUNT(*) FROM mdl_badge WHERE  type  =  2;

```


## Files

  

-  `settings.php`: Defines the admin settings page for badge IDs and course counts.

-  `lang/en/local_coursebadge.php`: Language strings for settings and validation errors.

-  `classes/observer.php`: Handles course creation and badge awarding events.

-  `classes/admin_setting_configcoursecount.php`: Custom setting class for validating course badge counts.

-  `db/events.php`: Registers event listeners.

-  `locallib.php`: Contains logic for creating course badges.

-  `version.php`: Plugin version and requirements.

  ## Additional Files
  Incase you have a large site with the courses already created and you decided to use this plugin which will be most common usecases.
The plugin will only create badges for the courses created after the installation of plugin.
And which is actually feasible in case you have hundreds of courses with thousand of users and you decide to install the plugin could result in hang and database overload.
So I have added scripts in the plugin directory which will help you to do the process manually and in batches to avoid those issues.
-  `create_badges_batch.php`: Will create the badges for all the courses and award them to the users fulfilling the criteria which  is set to be the completion of the courses by default. For changing tha you have to edit that in this file and the **classes/observer.php**:

    `
// Add course completion criterion.
$coursecrit = \award_criteria::build([
'badgeid'  =>  $badge->id,
'criteriatype'  =>  BADGE_CRITERIA_TYPE_COURSE,
]);
$coursecrit->save(['course_'  .  $course->id  =>  $course->id, 'agg'  =>  BADGE_CRITERIA_AGGREGATION_ALL]);`
-  `award_site_badges.php`: Will award the badges to the users meeting the site badge criteria set up in the settings of the plugin.

-  `delete_all_badges.php`: **💀 BE CAREFULL! This will delete any kind of badge on your site.**

## Known Issues

  

- If course badges were awarded before the plugin was installed, use the `award_site_badges.php` script to retroactively award site badges.

- Ensure site badges are active (`status = 1` or `3`) to prevent awarding failures.

- The `badge_awarded` event may not trigger for manual awards in some Moodle configurations; use the CLI script as a workaround.

  

## Support

  

For issues, feature requests, or contributions, please submit a pull request to the repository. 

  

---