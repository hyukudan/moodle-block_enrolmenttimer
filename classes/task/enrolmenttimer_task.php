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
     * Send completion email if the user meets the completion percentage threshold.
     *
     * Checks both course completion status and grade percentage against the
     * configured threshold. If completionpercentage is 100, only course
     * completion is required. Below 100, the user's course grade must meet
     * or exceed the threshold.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     */
    private function process_completion_email($user, $course) {
        global $CFG, $DB;

        $prefkey = 'block_enrolmenttimer_completion_' . $course->id;
        $already = get_user_preferences($prefkey, null, $user->id);
        if ($already) {
            return;
        }

        $threshold = get_config('enrolmenttimer', 'completionpercentage');
        if ($threshold === false || !is_numeric($threshold)) {
            $threshold = 100;
        }
        $threshold = (float)$threshold;

        if ($threshold >= 100) {
            // Standard mode: require full course completion.
            $completion = $DB->get_record('course_completions', [
                'userid' => $user->id,
                'course' => $course->id,
            ]);

            if (!$completion) {
                return;
            }

            if (empty($completion->timecompleted) && empty($completion->reaggregate)) {
                return;
            }
        } else {
            // Percentage mode: check grade against threshold.
            require_once($CFG->libdir . '/gradelib.php');
            $gradeobj = grade_get_course_grade($user->id, $course->id);

            if (!$gradeobj || !isset($gradeobj->grade) || $gradeobj->grade === null || $gradeobj->grade === '') {
                return;
            }

            if ($gradeobj->grade < 0) {
                return;
            }

            $grademax = (isset($gradeobj->item) && $gradeobj->item->grademax > 0)
                ? (float)$gradeobj->item->grademax : 100.0;
            $percentage = ((float)$gradeobj->grade / $grademax) * 100;

            if ($percentage < $threshold) {
                return;
            }
        }

        $from = \core_user::get_support_user();
        $subject = get_config('enrolmenttimer', 'completionemailsubject');
        $body = get_config('enrolmenttimer', 'completionsmessage');

        if (empty($subject) || empty($body)) {
            return;
        }

        // Calculate actual percentage for the placeholder.
        require_once($CFG->libdir . '/gradelib.php');
        $gradeobj = grade_get_course_grade($user->id, $course->id);
        $pct = 100;
        if ($gradeobj && isset($gradeobj->grade) && $gradeobj->grade !== null) {
            $grademax = (isset($gradeobj->item) && $gradeobj->item->grademax > 0)
                ? (float)$gradeobj->item->grademax : 100.0;
            $pct = round(((float)$gradeobj->grade / $grademax) * 100, 1);
        }

        $body = $this->replace_placeholders($body, $user, $course);
        $body = str_replace('[[percentage]]', $pct . '%', $body);

        $messageid = $this->send_notification($user, $course, $subject, $body, 'completion_notification');

        if ($messageid) {
            set_user_preference($prefkey, time(), $user->id);
            mtrace("- completion email sent to user {$user->id} for course {$course->id} (grade: {$pct}%)");
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

            $body = $bodytemplate;
            $body = $this->replace_placeholders($body, $user, $course);
            $body = str_replace('[[days_to_alert]]',
                get_config('enrolmenttimer', 'daystoalertenrolmentend'), $body);

            // Calculate days remaining for this specific enrolment.
            $endtime = $enrolinstance->timeend;
            if ($endtime == 0 && !empty($enrol->enrolenddate)) {
                $endtime = $enrol->enrolenddate;
            }
            $daysrem = ($endtime > 0) ? max(0, (int)ceil(($endtime - time()) / 86400)) : 0;
            $body = str_replace('[[days_remaining]]', (string)$daysrem, $body);
            if ($endtime > 0) {
                $body = str_replace('[[expiry_date]]',
                    userdate($endtime, get_string('strftimedatetime', 'langconfig')), $body);
            }

            $messageid = $this->send_notification($user, $course, $subject, $body, 'expiry_alert');

            if ($messageid) {
                $alert->sent = 1;
                mtrace("- expiry alert sent to user {$user->id} for course {$course->id}");
            } else {
                mtrace("ERROR: failed to send expiry alert to user {$user->id} (email: {$user->email}) for course {$course->id}");
            }
            $DB->update_record('block_enrolmenttimer', $alert);
        }
    }

    /**
     * Replace common placeholders in an email body.
     *
     * @param string $body The template body.
     * @param \stdClass $user The user object.
     * @param \stdClass $course The course object.
     * @return string The body with placeholders replaced.
     */
    private function replace_placeholders($body, $user, $course) {
        global $CFG;

        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);

        return str_replace(
            [
                '[[user_name]]',
                '[[user_firstname]]',
                '[[course_name]]',
                '[[course_shortname]]',
                '[[course_url]]',
                '[[site_name]]',
            ],
            [
                fullname($user),
                $user->firstname,
                $course->fullname,
                $course->shortname,
                $courseurl->out(false),
                format_string($CFG->fullname),
            ],
            $body
        );
    }

    /**
     * Send a notification using Moodle's Message API.
     *
     * @param \stdClass $user The recipient user.
     * @param \stdClass $course The course context.
     * @param string $subject The message subject.
     * @param string $body The HTML message body.
     * @param string $messagename The message provider name ('expiry_alert' or 'completion_notification').
     * @return int|false The message ID on success, or false on failure.
     */
    private function send_notification($user, $course, $subject, $body, $messagename) {
        $message = new \core\message\message();
        $message->component = 'block_enrolmenttimer';
        $message->name = $messagename;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = strip_tags($body);
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessagehtml = $body;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->courseid = $course->id;
        $message->contexturl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $message->contexturlname = $course->fullname;

        try {
            return message_send($message);
        } catch (\Exception $e) {
            mtrace("ERROR: message_send failed: " . $e->getMessage());
            return false;
        }
    }
}
