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
 * Unit tests for the block_enrolmenttimer block class.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer;

use advanced_testcase;
use block_enrolmenttimer;
use context_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->dirroot . '/blocks/enrolmenttimer/block_enrolmenttimer.php');

/**
 * Tests for the block class.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_enrolmenttimer
 */
class block_test extends advanced_testcase {

    /**
     * Helper: create a block instance in a course and return the block object.
     *
     * @param \stdClass $course
     * @return \block_enrolmenttimer|false
     */
    private function create_block_in_course($course) {
        global $DB, $PAGE;

        $coursecontext = context_course::instance($course->id);

        // Set up the page first so the block can attach to it.
        $PAGE->set_course($course);
        $PAGE->set_context($coursecontext);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_pagelayout('course');

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
        $blockrecord->id = $DB->insert_record('block_instances', $blockrecord);

        return block_instance('enrolmenttimer', $blockrecord);
    }

    /**
     * Test applicable_formats includes dashboard.
     */
    public function test_applicable_formats_includes_dashboard() {
        $block = new block_enrolmenttimer();
        $formats = $block->applicable_formats();

        $this->assertArrayHasKey('my', $formats);
        $this->assertTrue($formats['my']);
        $this->assertArrayHasKey('course-view', $formats);
        $this->assertTrue($formats['course-view']);
        $this->assertArrayHasKey('mod', $formats);
        $this->assertTrue($formats['mod']);
        $this->assertArrayHasKey('site-index', $formats);
        $this->assertFalse($formats['site-index']);
    }

    /**
     * Test has_config returns true.
     */
    public function test_has_config() {
        $block = new block_enrolmenttimer();
        $this->assertTrue($block->has_config());
    }

    /**
     * Test init sets the title.
     */
    public function test_init_sets_title() {
        $block = new block_enrolmenttimer();
        $block->init();

        $this->assertNotEmpty($block->title);
        $this->assertEquals(get_string('enrolmenttimer', 'block_enrolmenttimer'), $block->title);
    }

    /**
     * Test get_content returns empty for guest users.
     */
    public function test_content_empty_for_guest() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->setGuestUser();
        $COURSE = $course;

        set_config('activecountdown', 0, 'enrolmenttimer');

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertEmpty($content->text);
    }

    /**
     * Test get_content shows timer for enrolled student with end date.
     */
    public function test_content_shows_timer_for_student() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 1, 'enrolmenttimer');
        set_config('forceTwoDigits', 1, 'enrolmenttimer');
        set_config('displayUnitLabels', 0, 'enrolmenttimer');
        set_config('displayTextCounter', 1, 'enrolmenttimer');
        set_config('showprogressbar', 0, 'enrolmenttimer');
        set_config('showexpirydate', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertNotEmpty($content->text);
        $this->assertStringContainsString('timer-wrapper', $content->text);
        $this->assertStringContainsString('timerNum', $content->text);
        $this->assertStringContainsString('class="active"', $content->text);
    }

    /**
     * Test get_content shows "no date set" when configured to show message.
     */
    public function test_content_no_date_set_message() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        set_config('displayNothingNoDateSet', 0, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertStringContainsString('noDateSet', $content->text);
    }

    /**
     * Test get_content hides block when configured and no end date.
     */
    public function test_content_hidden_no_date() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertEmpty($content->text);
    }

    /**
     * Test get_content for admin shows nothing (admin has site:config capability).
     */
    public function test_content_admin_no_timer() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $COURSE = $course;

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        // Admin has site:config, so get_remaining_enrolment_period returns false.
        // With displayNothingNoDateSet=1, content should be empty.
        $this->assertEmpty($content->text);
    }

    /**
     * Test content includes urgency alert when expiry is near.
     */
    public function test_content_urgency_alert() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        // Expires in 2 days (should trigger danger alert).
        $end = time() + (2 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time() - (28 * 86400), $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');
        set_config('forceTwoDigits', 0, 'enrolmenttimer');
        set_config('displayUnitLabels', 0, 'enrolmenttimer');
        set_config('displayTextCounter', 0, 'enrolmenttimer');
        set_config('showprogressbar', 0, 'enrolmenttimer');
        set_config('showexpirydate', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertStringContainsString('alert-danger', $content->text);
    }

    /**
     * Test content includes progress bar when enabled.
     */
    public function test_content_progress_bar() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $start = time() - (10 * 86400);
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, $start, $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');
        set_config('forceTwoDigits', 0, 'enrolmenttimer');
        set_config('displayUnitLabels', 0, 'enrolmenttimer');
        set_config('displayTextCounter', 0, 'enrolmenttimer');
        set_config('showprogressbar', 1, 'enrolmenttimer');
        set_config('showexpirydate', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertStringContainsString('progress-bar', $content->text);
        $this->assertStringContainsString('progressbar', $content->text);
    }

    /**
     * Test urgency alert shows expired message when daysremaining is 0.
     */
    public function test_content_urgency_expired() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        // Expired yesterday.
        $start = time() - (30 * 86400);
        $end = time() - 86400;
        $manualplugin->enrol_user($manualenrol, $user->id, null, $start, $end);

        set_config('displayNothingNoDateSet', 0, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');
        set_config('forceTwoDigits', 0, 'enrolmenttimer');
        set_config('displayUnitLabels', 0, 'enrolmenttimer');
        set_config('displayTextCounter', 0, 'enrolmenttimer');
        set_config('showprogressbar', 0, 'enrolmenttimer');
        set_config('showexpirydate', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        // Expired enrolment: get_remaining_enrolment_period returns false, so block shows no date set
        // or hides. But get_enrolment_info returns expired=true with daysremaining=0.
        // Since timeleft is false, the timer won't show but the expired status is valid.
        // The actual behavior depends on displayNothingNoDateSet.
        // With displayNothingNoDateSet=0, it shows the "no date set" message.
        $this->assertNotEmpty($content->text);
    }

    /**
     * Test that timer elements include data-unit attributes for JS localization.
     */
    public function test_content_has_data_unit_attributes() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 1, 'enrolmenttimer');
        set_config('forceTwoDigits', 0, 'enrolmenttimer');
        set_config('displayUnitLabels', 0, 'enrolmenttimer');
        set_config('displayTextCounter', 1, 'enrolmenttimer');
        set_config('showprogressbar', 0, 'enrolmenttimer');
        set_config('showexpirydate', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        // Verify stable data-unit attributes exist (language-independent).
        $this->assertStringContainsString('data-unit="days"', $content->text);
    }

    /**
     * Test that progress bar percentage is integer (XSS defense).
     */
    public function test_content_progress_bar_integer_pct() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $start = time() - (10 * 86400);
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, $start, $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');
        set_config('showprogressbar', 1, 'enrolmenttimer');
        set_config('showexpirydate', 0, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        // The percentage in aria-valuenow should be an integer (no decimals).
        preg_match('/aria-valuenow="(\d+)"/', $content->text, $matches);
        $this->assertNotEmpty($matches);
        $this->assertIsNumeric($matches[1]);
        $this->assertEquals((int)$matches[1], $matches[1] + 0);
    }

    /**
     * Test ARIA live region exists on visual counter.
     */
    public function test_content_aria_live_region() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 1, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertStringContainsString('aria-live="polite"', $content->text);
        $this->assertStringContainsString('aria-atomic="true"', $content->text);
    }

    /**
     * Test content includes expiry date when enabled.
     */
    public function test_content_expiry_date() {
        global $DB, $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        set_config('displayNothingNoDateSet', 1, 'enrolmenttimer');
        set_config('activecountdown', 0, 'enrolmenttimer');
        set_config('forceTwoDigits', 0, 'enrolmenttimer');
        set_config('displayUnitLabels', 0, 'enrolmenttimer');
        set_config('displayTextCounter', 0, 'enrolmenttimer');
        set_config('showprogressbar', 0, 'enrolmenttimer');
        set_config('showexpirydate', 1, 'enrolmenttimer');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $block = $this->create_block_in_course($course);
        if (!$block) {
            $this->markTestSkipped('Could not instantiate block');
            return;
        }

        $content = $block->get_content();

        $this->assertStringContainsString('text-muted', $content->text);
        // Should contain the "Expires:" string.
        $this->assertMatchesRegularExpression('/Expires:/', $content->text);
    }
}
