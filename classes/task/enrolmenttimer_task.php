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
 * Scheduled task for enrolment timer alerts and completion emails.
 *
 * @package    block_enrolmenttimer
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Class enrolmenttimer_task
 *
 * @package    block_enrolmenttimer
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolmenttimer_task extends \core\task\scheduled_task {

    /**
     * Return the name of the scheduled task.
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'block_enrolmenttimer');
    }

    /**
     * Execute the scheduled task.
     * @return bool
     */
    public function execute() {
        global $CFG, $DB;

        mtrace('block/enrolmenttimer - Cron is beginning');
        require_once($CFG->dirroot . '/blocks/enrolmenttimer/locallib.php');

        $alertenabled = get_config('enrolmenttimer', 'timeleftmessagechk');
        $completionenabled = get_config('enrolmenttimer', 'completionsmessagechk');

        if (!$alertenabled && !$completionenabled) {
            mtrace('- no mail features enabled');
            return true;
        }

        // Warn early if email templates are not configured.
        if ($alertenabled) {
            $subject = get_config('enrolmenttimer', 'enrolmentemailsubject');
            $body = get_config('enrolmenttimer', 'timeleftmessage');
            if (empty($subject) || empty($body)) {
                mtrace('WARNING: Alert emails enabled but subject or body is empty. Configure in Site Admin > Plugins > Blocks > Enrolment Timer.');
            }
        }
        if ($completionenabled) {
            $subject = get_config('enrolmenttimer', 'completionemailsubject');
            $body = get_config('enrolmenttimer', 'completionsmessage');
            if (empty($subject) || empty($body)) {
                mtrace('WARNING: Completion emails enabled but subject or body is empty. Configure in Site Admin > Plugins > Blocks > Enrolment Timer.');
            }
        }

        // Get all instances of the block.
        $instances = $DB->get_records('block_instances', ['blockname' => 'enrolmenttimer']);

        foreach ($instances as $instance) {
            $block = block_instance('enrolmenttimer', $instance);
            if (!$block || empty($block->instance->parentcontextid)) {
                mtrace("WARNING: skipping block instance {$instance->id} - could not instantiate");
                continue;
            }

            $blockcontext = \context::instance_by_id($block->instance->parentcontextid, IGNORE_MISSING);
            if (!$blockcontext || $blockcontext->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $blockcontext->instanceid;
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course || !$course->visible) {
                continue;
            }

            $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$coursecontext) {
                continue;
            }

            $users = get_enrolled_users($coursecontext, '', 0, 'u.*');

            foreach ($users as $user) {
                try {
                    if ($alertenabled) {
                        $this->process_expiry_alert($user, $course);
                    }
                    if ($completionenabled) {
                        $this->process_completion_email($user, $course);
                    }
                } catch (\Exception $e) {
                    mtrace("ERROR: processing user {$user->id} in course {$course->id}: " . $e->getMessage());
                }
            }
        }

        // Send all pending alert emails.
        if ($alertenabled) {
            $this->send_pending_alerts();
        }

        mtrace('block/enrolmenttimer - Cron finished');
        return true;
    }

    /**
     * Create alert records for users whose enrolment is ending.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     */
    private function process_expiry_alert($user, $course) {
        global $DB;

        $records = block_enrolmenttimer_get_enrolment_records($user->id, $course->id);
        if (empty($records)) {
            return;
        }

        $daystoalert = get_config('enrolmenttimer', 'daystoalertenrolmentend');
        if ($daystoalert === false || !is_numeric($daystoalert)) {
            $daystoalert = 10;
        }
        $daystoalert = (int)$daystoalert;

        foreach ($records as $record) {
            // Already tracked.
            if ($DB->record_exists('block_enrolmenttimer', ['enrolid' => $record->id])) {
                continue;
            }

            $endtime = 0;
            if ($record->timeend != 0) {
                $endtime = (int)$record->timeend;
            } else {
                // Check enrol method end date (any enrolment type).
                $enrol = $DB->get_record('enrol', ['id' => $record->enrolid], 'enrolenddate');
                if ($enrol && !empty($enrol->enrolenddate) && (int)$enrol->enrolenddate > 0) {
                    $endtime = (int)$enrol->enrolenddate;
                }
            }

            if ($endtime <= 0) {
                continue;
            }

            $alerttime = $endtime - ($daystoalert * 86400);

            try {
                $object = new \stdClass();
                $object->enrolid = $record->id;
                $object->alerttime = $alerttime;
                $object->sent = 0;
                $DB->insert_record('block_enrolmenttimer', $object);
            } catch (\dml_exception $e) {
                mtrace("WARNING: could not insert alert for enrolment {$record->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send completion email if the user has completed the course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     */
    private function process_completion_email($user, $course) {
        global $DB;

        $prefkey = 'block_enrolmenttimer_completion_' . $course->id;
        $already = get_user_preferences($prefkey, null, $user->id);
        if ($already) {
            return;
        }

        $completion = $DB->get_record('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]);

        if (!$completion) {
            return;
        }

        $completedtime = null;
        if (!empty($completion->timecompleted)) {
            $completedtime = $completion->timecompleted;
        } else if (!empty($completion->reaggregate)) {
            $completedtime = $completion->reaggregate;
        }

        if (!$completedtime) {
            return;
        }

        $from = \core_user::get_support_user();
        $subject = get_config('enrolmenttimer', 'completionemailsubject');
        $body = get_config('enrolmenttimer', 'completionsmessage');

        if (empty($subject) || empty($body)) {
            return;
        }

        $body = str_replace('[[user_name]]', fullname($user), $body);
        $body = str_replace('[[course_name]]', $course->fullname, $body);

        $textonlybody = strip_tags($body);
        $result = email_to_user($user, $from, $subject, $textonlybody, $body);

        if ($result) {
            set_user_preference($prefkey, time(), $user->id);
            mtrace("- completion email sent to user {$user->id} for course {$course->id}");
        } else {
            mtrace("ERROR: failed to send completion email to user {$user->id} (email: {$user->email}) for course {$course->id}");
        }
    }

    /**
     * Send all pending alert emails whose alert time has passed.
     */
    private function send_pending_alerts() {
        global $DB;

        $time = time();
        $sql = "SELECT * FROM {block_enrolmenttimer} WHERE sent = 0 AND alerttime < :time";
        $emailstosend = $DB->get_records_sql($sql, ['time' => $time]);

        if (empty($emailstosend)) {
            mtrace('- no pending alerts to send');
            return;
        }

        $subject = get_config('enrolmenttimer', 'enrolmentemailsubject');
        $bodytemplate = get_config('enrolmenttimer', 'timeleftmessage');

        if (empty($subject) || empty($bodytemplate)) {
            mtrace('ERROR: alert emails enabled but subject or body not configured. Skipping all pending alerts.');
            return;
        }

        foreach ($emailstosend as $alert) {
            $enrolinstance = $DB->get_record('user_enrolments', ['id' => $alert->enrolid]);
            if (!$enrolinstance) {
                mtrace("WARNING: orphaned alert record {$alert->id} (enrolid {$alert->enrolid} not found), marking handled");
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            $user = $DB->get_record('user', ['id' => $enrolinstance->userid]);
            $enrol = $DB->get_record('enrol', ['id' => $enrolinstance->enrolid]);
            if (!$user || !$enrol) {
                mtrace("WARNING: missing user or enrol for alert {$alert->id}, marking handled");
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            $course = $DB->get_record('course', ['id' => $enrol->courseid]);
            if (!$course) {
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            $from = \core_user::get_support_user();
            $body = $bodytemplate;
            $body = str_replace('[[user_name]]', fullname($user), $body);
            $body = str_replace('[[course_name]]', $course->fullname, $body);
            $body = str_replace('[[days_to_alert]]',
                get_config('enrolmenttimer', 'daystoalertenrolmentend'), $body);

            $textonlybody = strip_tags($body);
            $result = email_to_user($user, $from, $subject, $textonlybody, $body);

            if ($result) {
                $alert->sent = 1;
                mtrace("- expiry alert sent to user {$user->id} for course {$course->id}");
            } else {
                mtrace("ERROR: failed to send expiry alert to user {$user->id} (email: {$user->email}) for course {$course->id}");
                // Leave sent=0 to retry next run, but don't loop forever.
            }
            $DB->update_record('block_enrolmenttimer', $alert);
        }
    }
}
