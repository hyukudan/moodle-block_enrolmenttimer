# Enrolment Timer Block for Moodle

**Fork of [learningworks/moodle-block_enrolmenttimer](https://github.com/learningworks/moodle-block_enrolmenttimer)**

Moodle block that displays the time a user has left in their enrolment period, with configurable countdown display and email notifications.

## Features

- Visual countdown timer showing remaining enrolment time
- Configurable time units (years, months, weeks, days, hours, minutes, seconds)
- Active JavaScript countdown (optional)
- Email alert before enrolment expires (configurable days before)
- Course completion email notification
- Customisable email templates with placeholders: `[[user_name]]`, `[[course_name]]`, `[[days_to_alert]]`

## Requirements

- Moodle 4.5+ (version 2024100700 or later)
- PHP 8.1+

## Changes from original (v5.1.0)

### Security
- Fixed SQL injection in scheduled task (parameterized queries)
- Added XSS escaping in block HTML output
- Removed dangerous `require_once config.php` from scheduled task

### Bug fixes
- Replaced hardcoded role ID 5 (`get_role_users(5, ...)`) with `get_enrolled_users()`
- Fixed invalid `hour = 24` in task schedule (changed to `hour = 6`)
- Changed task from weekly (Monday only) to daily
- Fixed `get_minute()` logic bug causing undefined array index
- Fixed boolean value in SQL integer column (`sent = false` -> `sent = 0`)
- Replaced fragile timing-window completion email detection with idempotent user preferences
- Added null safety checks on all database queries

### Moodle 5.1 compatibility
- Declared dynamic class properties (PHP 8.2+ deprecation)
- Implemented full Privacy API (GDPR) — metadata, export, and delete support
- Added `myaddinstance` capability

### Performance
- Added database indexes on `enrolid` and `sent + alerttime`
- Orphaned alert records cleaned up automatically

## Installation

Copy the plugin to `blocks/enrolmenttimer` in your Moodle installation and run the upgrade.

## License

GPL v3 or later - http://www.gnu.org/copyleft/gpl.html
