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
 * Unit tests for the enrolmenttimer scheduled task.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer;

use advanced_testcase;
use block_enrolmenttimer\task\enrolmenttimer_task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the scheduled task.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_enrolmenttimer\task\enrolmenttimer_task
 */
class task_test extends advanced_testcase {

    /**
     * Helper: create a course with the enrolmenttimer block instance.
     *
     * @return \stdClass The course object.
     */
    private function create_course_with_block() {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $blockrecord = new \stdClass();
        $blockrecord->blockname = 'enrolmenttimer';
        $blockrecord->parentcontextid = $coursecontext->id;
        $blockrecord->showinsubcontexts = 0;
        $blockrecord->requiredbytheme = 0;
        $blockrecord->pagetypepattern = 'course-view-*';
        $blockrecord->defaultregion = 'side-pre';
        $blockrecord->defaultweight = 0;
        $blockrecord->configdata = '';
        $blockrecord->timecreated = time();
        $blockrecord->timemodified = time();
        $DB->insert_record('block_instances', $blockrecord);

        return $course;
    }

    /**
     * Helper: execute task suppressing mtrace output.
     *
     * @param enrolmenttimer_task $task
     * @return bool Task result.
     */
    private function execute_task(enrolmenttimer_task $task) {
        ob_start();
        $result = $task->execute();
        ob_end_clean();
        return $result;
    }

    /**
     * Test task get_name returns a non-empty string.
     */
    public function test_task_name() {
        $task = new enrolmenttimer_task();
        $name = $task->get_name();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    /**
     * Test task execute with no features enabled.
     */
    public function test_execute_no_features_enabled() {
        $this->resetAfterTest(true);

        set_config('timeleftmessagechk', 0, 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $task = new enrolmenttimer_task();
        $result = $this->execute_task($task);

        $this->assertTrue($result);
    }

    /**
     * Test that process_expiry_alert creates alert records.
     */
    public function test_alert_records_created() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        $end = time() + (15 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('daystoalertenrolmentend', 10, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Test Subject', 'enrolmenttimer');
        set_config('timeleftmessage', 'Test body [[user_name]]', 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $ue = $DB->get_record('user_enrolments', [
            'userid' => $user->id,
            'enrolid' => $manualenrol->id,
        ]);
        $alert = $DB->get_record('block_enrolmenttimer', ['enrolid' => $ue->id]);

        $this->assertNotFalse($alert);
        $this->assertEquals(0, $alert->sent);
        $expectedalert = $end - (10 * 86400);
        $this->assertEquals($expectedalert, $alert->alerttime);
    }

    /**
     * Test that alert records are not duplicated on second run.
     */
    public function test_alert_records_not_duplicated() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (15 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('daystoalertenrolmentend', 10, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Test Subject', 'enrolmenttimer');
        set_config('timeleftmessage', 'Test body', 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $task = new enrolmenttimer_task();
        $this->execute_task($task);
        $this->execute_task($task); // Second run.

        $ue = $DB->get_record('user_enrolments', [
            'userid' => $user->id,
            'enrolid' => $manualenrol->id,
        ]);

        $count = $DB->count_records('block_enrolmenttimer', ['enrolid' => $ue->id]);
        $this->assertEquals(1, $count);
    }

    /**
     * Test that users without end date get no alert.
     */
    public function test_no_alert_for_no_end_date() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('daystoalertenrolmentend', 10, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Test', 'enrolmenttimer');
        set_config('timeleftmessage', 'Test', 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $this->assertEquals(0, $DB->count_records('block_enrolmenttimer'));
    }

    /**
     * Test send_pending_alerts sends alerts whose time has passed.
     */
    public function test_send_pending_alerts() {
        global $DB;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (2 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        $ue = $DB->get_record('user_enrolments', [
            'userid' => $user->id,
            'enrolid' => $manualenrol->id,
        ]);

        $DB->insert_record('block_enrolmenttimer', (object)[
            'enrolid' => $ue->id,
            'alerttime' => time() - 3600,
            'sent' => 0,
        ]);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Your enrolment expires', 'enrolmenttimer');
        set_config('timeleftmessage', 'Dear [[user_name]], your course [[course_name]] expires.', 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $sink = $this->redirectMessages();

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThanOrEqual(1, count($messages));

        $alert = $DB->get_record('block_enrolmenttimer', ['enrolid' => $ue->id]);
        $this->assertEquals(1, $alert->sent);
    }

    /**
     * Test send_pending_alerts handles orphaned records.
     */
    public function test_orphaned_alert_handled() {
        global $DB;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $this->create_course_with_block();

        $alertid = $DB->insert_record('block_enrolmenttimer', (object)[
            'enrolid' => 999999,
            'alerttime' => time() - 3600,
            'sent' => 0,
        ]);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Subject', 'enrolmenttimer');
        set_config('timeleftmessage', 'Body', 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $sink = $this->redirectMessages();

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $sink->close();

        $alert = $DB->get_record('block_enrolmenttimer', ['id' => $alertid]);
        $this->assertEquals(1, $alert->sent);
    }

    /**
     * Test replace_placeholders method via alert emails.
     */
    public function test_placeholders_replaced() {
        global $DB;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'TestFirst',
            'lastname' => 'TestLast',
        ]);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (5 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        $ue = $DB->get_record('user_enrolments', [
            'userid' => $user->id,
            'enrolid' => $manualenrol->id,
        ]);

        $DB->insert_record('block_enrolmenttimer', (object)[
            'enrolid' => $ue->id,
            'alerttime' => time() - 3600,
            'sent' => 0,
        ]);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('daystoalertenrolmentend', 10, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Alert', 'enrolmenttimer');
        set_config('timeleftmessage',
            'Hello [[user_firstname]] [[user_name]], course: [[course_name]], short: [[course_shortname]]',
            'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $sink = $this->redirectMessages();

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThanOrEqual(1, count($messages));

        $body = $messages[0]->fullmessagehtml;
        $this->assertStringContainsString('TestFirst', $body);
        $this->assertStringContainsString($course->fullname, $body);
        $this->assertStringContainsString($course->shortname, $body);
        $this->assertStringNotContainsString('[[user_name]]', $body);
        $this->assertStringNotContainsString('[[user_firstname]]', $body);
        $this->assertStringNotContainsString('[[course_name]]', $body);
    }

    /**
     * Test task skips invisible courses.
     */
    public function test_skips_invisible_courses() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->create_course_with_block();
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);

        $user = $this->getDataGenerator()->create_user();
        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (15 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('daystoalertenrolmentend', 10, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Test', 'enrolmenttimer');
        set_config('timeleftmessage', 'Test', 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $this->assertEquals(0, $DB->count_records('block_enrolmenttimer'));
    }

    /**
     * Test completion email is sent when course is completed (threshold 100).
     */
    public function test_completion_email_on_course_completion() {
        global $DB;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id,
            'course' => $course->id,
            'timecompleted' => time(),
            'timestarted' => time() - 86400,
        ]);

        set_config('timeleftmessagechk', 0, 'enrolmenttimer');
        set_config('completionsmessagechk', 1, 'enrolmenttimer');
        set_config('completionpercentage', 100, 'enrolmenttimer');
        set_config('completionemailsubject', 'Congratulations!', 'enrolmenttimer');
        set_config('completionsmessage', 'Well done [[user_name]]! You completed [[course_name]].', 'enrolmenttimer');

        $sink = $this->redirectMessages();

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertGreaterThanOrEqual(1, count($messages));

        $prefkey = 'block_enrolmenttimer_completion_' . $course->id;
        $pref = get_user_preferences($prefkey, null, $user->id);
        $this->assertNotNull($pref);
    }

    /**
     * Test completion email is NOT sent twice (preference prevents).
     */
    public function test_completion_email_not_sent_twice() {
        global $DB;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id,
            'course' => $course->id,
            'timecompleted' => time(),
            'timestarted' => time() - 86400,
        ]);

        set_config('timeleftmessagechk', 0, 'enrolmenttimer');
        set_config('completionsmessagechk', 1, 'enrolmenttimer');
        set_config('completionpercentage', 100, 'enrolmenttimer');
        set_config('completionemailsubject', 'Congrats', 'enrolmenttimer');
        set_config('completionsmessage', 'Done', 'enrolmenttimer');

        $sink = $this->redirectMessages();

        $task = new enrolmenttimer_task();
        $this->execute_task($task);
        $this->execute_task($task); // Second run.

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertEquals(1, count($messages));
    }

    /**
     * Test completion email skipped when course not completed.
     */
    public function test_completion_email_not_sent_without_completion() {
        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $course = $this->create_course_with_block();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        set_config('timeleftmessagechk', 0, 'enrolmenttimer');
        set_config('completionsmessagechk', 1, 'enrolmenttimer');
        set_config('completionpercentage', 100, 'enrolmenttimer');
        set_config('completionemailsubject', 'Congrats', 'enrolmenttimer');
        set_config('completionsmessage', 'Done', 'enrolmenttimer');

        $sink = $this->redirectMessages();

        $task = new enrolmenttimer_task();
        $this->execute_task($task);

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(0, $messages);
    }

    /**
     * Test task returns true (success) even when no block instances exist.
     */
    public function test_execute_no_block_instances() {
        $this->resetAfterTest(true);

        set_config('timeleftmessagechk', 1, 'enrolmenttimer');
        set_config('completionsmessagechk', 0, 'enrolmenttimer');
        set_config('enrolmentemailsubject', 'Test', 'enrolmenttimer');
        set_config('timeleftmessage', 'Test', 'enrolmenttimer');

        $task = new enrolmenttimer_task();
        $result = $this->execute_task($task);

        $this->assertTrue($result);
    }
}
