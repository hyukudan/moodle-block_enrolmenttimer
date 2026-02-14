# Enrolment Timer Block for Moodle

**Fork of [learningworks/moodle-block_enrolmenttimer](https://github.com/learningworks/moodle-block_enrolmenttimer)**

Moodle block that displays a countdown timer showing remaining enrolment time, with visual progress indicators, urgency alerts, and configurable email notifications.

## Features

- Visual countdown timer with configurable time units (years, months, weeks, days, hours, minutes, seconds)
- Active JavaScript countdown with automatic stop at zero
- Progress bar showing percentage of enrolment time elapsed (color-coded: green/yellow/red)
- Urgency alerts when enrolment expires within 7 days (Bootstrap alert banners)
- Optional exact expiry date display
- Enrolment expiry email alerts (configurable days before)
- Course completion email notification with grade percentage threshold
- Moodle Message API integration (popup + email, user-configurable)
- Dashboard widget support
- WCAG accessibility (ARIA labels, reduced motion support)
- Responsive design for mobile
- Full GDPR/Privacy API implementation
- Request-level database caching

## Requirements

- Moodle 4.5+ (version 2024100700 or later)
- PHP 8.1+

## Settings

### General
| Setting | Description | Default |
|---|---|---|
| Hide block (No End Date) | Hide block for users without end date | Enabled |
| Display Unit Labels | Show unit names below counters | Disabled |
| Force 2 Digits | Pad single digits with zero | Enabled |
| Display Text Counter | Show text version below visual counter | Enabled |
| Active Countdown | JavaScript live countdown | Enabled |
| Show Progress Bar | Visual progress bar of time elapsed | Disabled |
| Show Expiry Date | Display exact expiry date/time | Disabled |
| Increments Shown | Which time units to display | All |

### Alert Emails
| Setting | Description | Default |
|---|---|---|
| Enable Time Warning | Send alert before expiry | Disabled |
| Days to alert | Days before expiry to send alert | 10 |
| Email Subject | Alert email subject | "Enrolment Expiring" |
| Message Template | HTML template with placeholders | Empty |

### Completion Emails
| Setting | Description | Default |
|---|---|---|
| Enable Completion Email | Send email on completion | Disabled |
| Notification Percentage | Minimum grade % to trigger | 100 |
| Email Subject | Completion email subject | "Course Completed" |
| Message Template | HTML template with placeholders | Empty |

### Email Placeholders

**Alert emails:** `[[user_name]]` `[[user_firstname]]` `[[course_name]]` `[[course_shortname]]` `[[days_to_alert]]` `[[days_remaining]]` `[[expiry_date]]` `[[course_url]]` `[[site_name]]`

**Completion emails:** `[[user_name]]` `[[user_firstname]]` `[[course_name]]` `[[course_shortname]]` `[[course_url]]` `[[site_name]]` `[[percentage]]`

## Changes from original (v5.2.0)

### Security
- Fixed SQL injection in scheduled task (parameterized queries)
- Added XSS escaping in block HTML output with `s()`
- Removed dangerous `require_once config.php` from scheduled task

### Bug Fixes
- Replaced hardcoded role ID 5 with `get_enrolled_users()`
- Fixed multiple enrolments bug (records keyed by userid caused data loss)
- Removed hardcoded `'enrol' => 'self'` filter (all enrolment types now supported)
- Fixed invalid `hour = 24` in task schedule
- Fixed `get_minute()` logic crash (undefined array index)
- Fixed boolean in SQL integer column (`sent = false` -> `sent = 0`)
- Fixed `> $unit` to `>= $unit` (exact unit match edge case)
- Fixed negative/zero time handling (expired enrolments)
- Fixed array bounds crash in `sort_units_to_show()`
- Fixed grade 0 vs null distinction in completion percentage
- Fixed `forceTwoDigits` checked after timer start in JavaScript

### Moodle 5.1+ Compatibility
- Declared dynamic class properties (PHP 8.2+)
- Full Privacy API (GDPR) with export/delete of user preferences
- Added `myaddinstance` capability
- ESLint-compliant AMD JavaScript with JSDoc
- Moodle Message API for notifications (replaces `email_to_user`)

### New Features
- Progress bar showing % of enrolment time elapsed
- Urgency alerts (warning at 7 days, danger at 3 days)
- Optional exact expiry date display
- Completion email triggered by grade percentage threshold
- Additional email placeholders (firstname, course URL, site name, etc.)
- Message providers: users can control notification preferences
- Dashboard widget support
- Request-level database caching

### Performance
- Database indexes on `enrolid` and `sent + alerttime`
- Per-request cache for enrolment data queries
- Orphaned alert records cleaned up automatically

### Accessibility
- ARIA labels on timer elements
- Responsive CSS with mobile breakpoints
- `prefers-reduced-motion` support

## Installation

Copy the plugin to `blocks/enrolmenttimer` in your Moodle installation and run the upgrade.

## License

GPL v3 or later - http://www.gnu.org/copyleft/gpl.html
