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

/** @var string Fields to select from the user table in get_enrolled_users. */
define('BLOCK_ENROLMENTTIMER_USER_FIELDS',
    'u.id, u.username, u.auth, u.confirmed, u.email, u.firstname, u.lastname, ' .
    'u.mailformat, u.maildisplay, u.emailstop, u.deleted, u.suspended, ' .
    'u.lang, u.timezone, u.mnethostid, u.firstnamephonetic, u.lastnamephonetic, ' .
    'u.middlename, u.alternatename, u.imagealt, u.picture'
);

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

        // Purge orphaned alert records whose enrolment no longer exists.
        $this->cleanup_orphaned_alerts();

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

        // Collect unique course IDs from all block instances to avoid processing duplicates.
        $instances = $DB->get_records('block_instances', ['blockname' => 'enrolmenttimer']);
        $courseids = [];

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

            $courseids[$blockcontext->instanceid] = true;
        }

        foreach (array_keys($courseids) as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course || !$course->visible) {
                continue;
            }

            $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$coursecontext) {
                continue;
            }

            // Select only needed fields; exclude deleted and suspended users.
            $users = get_enrolled_users($coursecontext, '', 0, BLOCK_ENROLMENTTIMER_USER_FIELDS);

            foreach ($users as $user) {
                if (!empty($user->deleted) || !empty($user->suspended)) {
                    continue;
                }

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
            $endtime = block_enrolmenttimer_resolve_end_time($record);

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
            } catch (\dml_write_exception $e) {
                // UNIQUE index violation means alert already exists — safe to ignore.
                continue;
            } catch (\dml_exception $e) {
                mtrace("WARNING: could not insert alert for enrolment {$record->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send completion email if the user meets the completion percentage threshold.
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

        // Pre-fetch grade once — reused for both threshold check and placeholder.
        $gradeobj = null;
        $pct = 100;
        if ($threshold < 100) {
            require_once($CFG->libdir . '/gradelib.php');
            $gradeobj = grade_get_course_grade($user->id, $course->id);
        }

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
            if (!$gradeobj || !isset($gradeobj->grade) || $gradeobj->grade === null || $gradeobj->grade === '') {
                return;
            }

            if ($gradeobj->grade < 0) {
                return;
            }

            $grademax = (isset($gradeobj->item) && $gradeobj->item->grademax > 0)
                ? (float)$gradeobj->item->grademax : 100.0;
            $pct = round(((float)$gradeobj->grade / $grademax) * 100, 1);

            if ($pct < $threshold) {
                return;
            }
        }

        $subject = get_config('enrolmenttimer', 'completionemailsubject');
        $body = get_config('enrolmenttimer', 'completionsmessage');

        if (empty($subject) || empty($body)) {
            return;
        }

        // Compute percentage for placeholder if not already calculated.
        if ($threshold >= 100 && $gradeobj === null) {
            require_once($CFG->libdir . '/gradelib.php');
            $gradeobj = grade_get_course_grade($user->id, $course->id);
            if ($gradeobj && isset($gradeobj->grade) && $gradeobj->grade !== null) {
                $grademax = (isset($gradeobj->item) && $gradeobj->item->grademax > 0)
                    ? (float)$gradeobj->item->grademax : 100.0;
                $pct = round(((float)$gradeobj->grade / $grademax) * 100, 1);
            }
        }

        $body = $this->replace_placeholders($body, $user, $course);
        $body = str_replace('[[percentage]]', $pct . '%', $body);

        $messageid = $this->send_notification($user, $course, $subject, $body, 'completion_notification');

        if ($messageid) {
            set_user_preference($prefkey, time(), $user->id);
            mtrace("- completion email sent to user {$user->id} for course {$course->id} (grade: {$pct}%)");

            $event = \block_enrolmenttimer\event\completion_email_sent::create([
                'context' => \context_course::instance($course->id),
                'courseid' => $course->id,
                'relateduserid' => $user->id,
            ]);
            $event->trigger();
        } else {
            mtrace("ERROR: failed to send completion email to user {$user->id} for course {$course->id}");
        }
    }

    /**
     * Send all pending alert emails whose alert time has passed.
     */
    private function send_pending_alerts() {
        global $DB;

        $time = time();
        // Batch-load all needed data in a single JOIN query (avoids 4N+1 queries).
        $sql = "SELECT bt.id as alertid, bt.enrolid, bt.alerttime,
                       ue.id as ueid, ue.userid, ue.enrolid as ue_enrolid, ue.timeend,
                       e.id as enrolid2, e.courseid, e.enrolenddate,
                       u.id as uid, u.username, u.auth, u.confirmed, u.email,
                       u.firstname, u.lastname, u.mailformat, u.maildisplay,
                       u.emailstop, u.deleted, u.suspended, u.lang, u.timezone,
                       u.mnethostid, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.imagealt, u.picture,
                       c.id as courseid2, c.fullname, c.shortname, c.visible
                  FROM {block_enrolmenttimer} bt
             LEFT JOIN {user_enrolments} ue ON ue.id = bt.enrolid
             LEFT JOIN {enrol} e ON e.id = ue.enrolid
             LEFT JOIN {user} u ON u.id = ue.userid
             LEFT JOIN {course} c ON c.id = e.courseid
                 WHERE bt.sent = 0 AND bt.alerttime < :time";
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

        foreach ($emailstosend as $row) {
            // Reconstruct alert object for update_record.
            $alert = new \stdClass();
            $alert->id = $row->alertid;
            $alert->enrolid = $row->enrolid;
            $alert->alerttime = $row->alerttime;
            $alert->sent = 0;

            if (empty($row->ueid)) {
                mtrace("WARNING: orphaned alert record {$alert->id} (enrolid {$alert->enrolid} not found), marking handled");
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            if (empty($row->uid) || empty($row->enrolid2)) {
                mtrace("WARNING: missing user or enrol for alert {$alert->id}, marking handled");
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            if (!empty($row->deleted) || !empty($row->suspended)) {
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            if (empty($row->courseid2)) {
                $alert->sent = 1;
                $DB->update_record('block_enrolmenttimer', $alert);
                continue;
            }

            // Build user object from joined row.
            $user = new \stdClass();
            foreach (['username', 'auth', 'confirmed', 'email', 'firstname', 'lastname',
                       'mailformat', 'maildisplay', 'emailstop', 'deleted', 'suspended',
                       'lang', 'timezone', 'mnethostid', 'firstnamephonetic',
                       'lastnamephonetic', 'middlename', 'alternatename', 'imagealt', 'picture'] as $f) {
                $user->$f = $row->$f;
            }
            $user->id = $row->uid;

            // Build course object from joined row.
            $course = new \stdClass();
            $course->id = $row->courseid2;
            $course->fullname = $row->fullname;
            $course->shortname = $row->shortname;
            $course->visible = $row->visible;

            // Build enrolment data for time calculations.
            $enrolinstance = new \stdClass();
            $enrolinstance->timeend = $row->timeend;
            $enrol = new \stdClass();
            $enrol->enrolenddate = $row->enrolenddate;

            $body = $bodytemplate;
            $body = $this->replace_placeholders($body, $user, $course);
            $body = str_replace('[[days_to_alert]]',
                get_config('enrolmenttimer', 'daystoalertenrolmentend'), $body);

            // Calculate days remaining for this specific enrolment.
            $endtime = $enrolinstance->timeend ?? 0;
            if ($endtime == 0 && !empty($enrol->enrolenddate)) {
                $endtime = (int) $enrol->enrolenddate;
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

                $event = \block_enrolmenttimer\event\alert_sent::create([
                    'context' => \context_course::instance($course->id),
                    'courseid' => $course->id,
                    'relateduserid' => $user->id,
                ]);
                $event->trigger();
            } else {
                mtrace("ERROR: failed to send expiry alert to user {$user->id} for course {$course->id}");
            }
            $DB->update_record('block_enrolmenttimer', $alert);
        }
    }

    /**
     * Delete alert records whose enrolment no longer exists in user_enrolments.
     */
    private function cleanup_orphaned_alerts() {
        global $DB;

        $sql = "SELECT bt.id
                  FROM {block_enrolmenttimer} bt
             LEFT JOIN {user_enrolments} ue ON ue.id = bt.enrolid
                 WHERE ue.id IS NULL";
        $orphans = $DB->get_fieldset_sql($sql);

        if (!empty($orphans)) {
            list($insql, $params) = $DB->get_in_or_equal($orphans);
            $DB->delete_records_select('block_enrolmenttimer', "id $insql", $params);
            mtrace('- purged ' . count($orphans) . ' orphaned alert record(s)');
        }
    }

    /**
     * Replace common placeholders in an email body.
     * Values are escaped for safe HTML insertion.
     *
     * @param string $body The template body.
     * @param \stdClass $user The user object.
     * @param \stdClass $course The course object.
     * @return string The body with placeholders replaced.
     */
    private function replace_placeholders($body, $user, $course) {
        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $site = get_site();

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
                s(fullname($user)),
                s($user->firstname),
                format_string($course->fullname),
                format_string($course->shortname),
                $courseurl->out(false),
                format_string($site->fullname),
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
        $message->contexturlname = format_string($course->fullname);

        try {
            return message_send($message);
        } catch (\Exception $e) {
            mtrace("ERROR: message_send failed: " . $e->getMessage());
            return false;
        }
    }
}
