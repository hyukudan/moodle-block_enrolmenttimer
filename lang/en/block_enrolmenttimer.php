<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Lang File
 *
 * @package    block_enrolmenttimer
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Enrolment Timer';
$string['enrolmenttimer'] = 'Enrolment Timer';
$string['enrolmenttimer:addinstance'] = 'Add a new Enrolment Timer block';
$string['enrolmenttimer:myaddinstance'] = 'Add Enrolment Timer to Dashboard';
$string['expirytext'] = 'until your enrollment expires';
$string['noDateSet'] = 'Your enrolment does not expire';
$string['instance_title'] = 'Set the title of this block instance';
$string['instance_custommessage'] = 'Custom message';
$string['settings_notifications_alert'] = 'Alert Email Notifications Settings';
$string['settings_notifications_completion'] = 'Completion Email Notifications Settings';
$string['settings_notifications_defaults'] = 'Set defaults for instance settings';
$string['settings_general'] = 'General Settings';
$string['displayUnitLabels'] = 'Display Unit Labels';
$string['displayUnitLabels_help'] = 'Displays each unit below the main counter';
$string['displayTextCounter'] = 'Display Text Counter';
$string['displayTextCounter_help'] = 'Displays the text counter that sits below the main counter';
$string['forceTwoDigits'] = 'Force 2 Digits';
$string['forceTwoDigits_help'] = 'Forces the main countdown timer to always show at least 2 digits (eg 01 hours left)';
$string['displayNothingNoDateSet'] = 'Hide block (No End Date Set)';
$string['displayNothingNoDateSet_help'] = 'Hides the block for users that have no end date set. If disabled, a message will be shown to those students';

$string['forceDefaults'] = 'Force Default Values';
$string['forceDefaults_help'] = 'Disables the ability for teachers to change the settings for each block instance';
$string['activecountdown'] = 'Actively count down';
$string['activecountdown_help'] = 'Actively count down the remaining time the student has to access the course using javascript';
$string['viewoptions'] = 'Increments Shown';
$string['viewoptions_desc'] = 'Select the increments to show in the block';

$string['daystoalertenrolmentend'] = 'Days to alert on';
$string['daystoalertenrolmentend_help'] = 'The amount of days before the enrollment ends at which to send the alert email';
$string['timeleftmessagechk'] = 'Enable Time Warning Email';
$string['timeleftmessagechk_help'] = 'Enables/Disables alert email';
$string['timeleftmessage'] = 'Time Remaining Warning Message';
$string['timeleftmessage_help'] = 'Email sent to students before their enrolment expires. Placeholders: [[user_name]] [[user_firstname]] [[course_name]] [[course_shortname]] [[days_to_alert]] [[days_remaining]] [[expiry_date]] [[course_url]] [[site_name]]';
$string['emailsubject'] = 'Email Subject';
$string['emailsubject_help'] = 'Subject of the email that will be sent to the user';
$string['emailsubject_expiring_default'] = 'Enrolment Expiring';
$string['emailsubject_completion_default'] = 'Course Completed';

$string['completionsmessagechk'] = 'Enable Completion Email';
$string['completionsmessagechk_help'] = 'Enables/Disables the completion email';
$string['completionsmessage'] = 'Course Completion Email';
$string['completionsmessage_help'] = 'Email that will be sent congratulating the student on completing the course. Placeholders: [[user_name]] [[course_name]] [[percentage]]';
$string['completionpercentage'] = 'Notification percentage';
$string['completionpercentage_help'] = 'The minimum percentage in the Course Total grade for the completion email to be sent. Set to 100 to only send on full course completion. Values below 100 trigger based on grade percentage instead.';

$string['key_years'] = 'years';
$string['key_months'] = 'months';
$string['key_weeks'] = 'weeks';
$string['key_days'] = 'days';
$string['key_hours'] = 'hours';
$string['key_minutes'] = 'minutes';
$string['key_seconds'] = 'seconds';

$string['showprogressbar'] = 'Show progress bar';
$string['showprogressbar_help'] = 'Display a visual progress bar showing the percentage of enrolment time that has elapsed.';
$string['showexpirydate'] = 'Show exact expiry date';
$string['showexpirydate_help'] = 'Display the exact enrolment expiry date and time below the countdown timer.';
$string['expiring_soon'] = 'Your access to this course expires very soon!';
$string['expiring_warning'] = 'Your course access is expiring in the next few days.';
$string['expired'] = 'Your enrolment has expired.';
$string['expirydate'] = 'Expires: {$a}';
$string['progress_elapsed'] = '{$a}% of enrolment time elapsed';

$string['urgency_danger_days'] = 'Danger alert threshold (days)';
$string['urgency_danger_days_help'] = 'Show a danger alert when enrolment expires within this many days. Default: 3.';
$string['urgency_warning_days'] = 'Warning alert threshold (days)';
$string['urgency_warning_days_help'] = 'Show a warning alert when enrolment expires within this many days. Default: 7.';
$string['progress_warning_pct'] = 'Progress bar warning threshold (%)';
$string['progress_warning_pct_help'] = 'Progress bar turns yellow when elapsed percentage reaches this value. Default: 50.';
$string['progress_danger_pct'] = 'Progress bar danger threshold (%)';
$string['progress_danger_pct_help'] = 'Progress bar turns red when elapsed percentage reaches this value. Default: 80.';

$string['event_alert_sent'] = 'Enrolment expiry alert sent';
$string['event_completion_email_sent'] = 'Completion email sent';

$string['messageprovider:expiry_alert'] = 'Enrolment expiry alerts';
$string['messageprovider:completion_notification'] = 'Course completion notifications';

$string['privacy:metadata'] = 'The Enrolment Timer block stores enrolment alert data linked to user enrolments.';
$string['privacy:metadata:block_enrolmenttimer'] = 'Records of enrolment expiry alerts scheduled or sent to users.';
$string['privacy:metadata:enrolid'] = 'The ID of the user enrolment record this alert relates to.';
$string['privacy:metadata:alerttime'] = 'The timestamp at which the alert email should be sent.';
$string['privacy:metadata:sent'] = 'Whether the alert email has been sent.';
$string['privacy:metadata:completion_preference'] = 'Timestamp when the course completion email was sent to the user.';
