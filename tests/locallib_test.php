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
 * Unit tests for locallib.php functions.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/enrolmenttimer/locallib.php');

/**
 * Tests for locallib.php helper functions.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::block_enrolmenttimer_get_units
 * @covers ::block_enrolmenttimer_sort_units_to_show
 * @covers ::block_enrolmenttimer_get_enrolment_records
 * @covers ::block_enrolmenttimer_get_best_enrolment_record
 * @covers ::block_enrolmenttimer_get_enrolment_info
 * @covers ::block_enrolmenttimer_get_remaining_enrolment_period
 */
class locallib_test extends advanced_testcase {

    /**
     * Test that get_units returns all 7 time units in correct order.
     */
    public function test_get_units_returns_all_seven() {
        $units = block_enrolmenttimer_get_units();

        $this->assertCount(7, $units);

        $values = array_values($units);
        // Verify descending order: years > months > weeks > days > hours > minutes > seconds.
        $this->assertEquals(31536000, $values[0]); // Years.
        $this->assertEquals(2592000, $values[1]);  // Months.
        $this->assertEquals(604800, $values[2]);   // Weeks.
        $this->assertEquals(86400, $values[3]);    // Days.
        $this->assertEquals(3600, $values[4]);     // Hours.
        $this->assertEquals(60, $values[5]);       // Minutes.
        $this->assertEquals(1, $values[6]);        // Seconds.
    }

    /**
     * Test sort_units_to_show with valid comma-separated indices.
     */
    public function test_sort_units_valid_indices() {
        // "3,4" = days, hours.
        $result = block_enrolmenttimer_sort_units_to_show('3,4');

        $this->assertCount(2, $result);
        $values = array_values($result);
        $this->assertEquals(86400, $values[0]); // Days.
        $this->assertEquals(3600, $values[1]);  // Hours.
    }

    /**
     * Test sort_units_to_show with out-of-bounds indices.
     */
    public function test_sort_units_out_of_bounds() {
        // Index 99 doesn't exist, should be skipped.
        $result = block_enrolmenttimer_sort_units_to_show('3,99');

        $this->assertCount(1, $result);
        $values = array_values($result);
        $this->assertEquals(86400, $values[0]); // Only days.
    }

    /**
     * Test sort_units_to_show with all invalid indices falls back to full set.
     */
    public function test_sort_units_all_invalid_falls_back() {
        $result = block_enrolmenttimer_sort_units_to_show('99,100,-1');

        // Should fall back to full units set.
        $this->assertCount(7, $result);
    }

    /**
     * Test sort_units_to_show with empty string falls back to full set.
     */
    public function test_sort_units_empty_string() {
        $result = block_enrolmenttimer_sort_units_to_show('');

        $this->assertCount(7, $result);
    }

    /**
     * Test sort_units_to_show with non-numeric values.
     */
    public function test_sort_units_non_numeric() {
        $result = block_enrolmenttimer_sort_units_to_show('abc,def');

        // Falls back to all units.
        $this->assertCount(7, $result);
    }

    /**
     * Test sort_units handles spaces in values.
     */
    public function test_sort_units_with_spaces() {
        $result = block_enrolmenttimer_sort_units_to_show(' 0 , 6 ');

        $this->assertCount(2, $result);
        $values = array_values($result);
        $this->assertEquals(31536000, $values[0]); // Years.
        $this->assertEquals(1, $values[1]);        // Seconds.
    }

    /**
     * Test get_enrolment_records returns records keyed by ue.id.
     */
    public function test_enrolment_records_keyed_by_ueid() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Enrol via manual method.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $records = block_enrolmenttimer_get_enrolment_records($user->id, $course->id);

        $this->assertNotEmpty($records);

        // Keys should be ue.id (user_enrolments.id), not userid.
        foreach ($records as $key => $record) {
            $this->assertEquals($key, $record->id);
            $this->assertEquals($user->id, $record->userid);
        }
    }

    /**
     * Test get_enrolment_records with multiple enrolment methods.
     */
    public function test_enrolment_records_multiple_methods() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Enrol via manual.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');

        // Create a second enrolment method (self) and enrol user.
        $selfplugin = enrol_get_plugin('self');
        $selfinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self']);
        if (!$selfinstance) {
            $selfplugin->add_instance($course);
            $selfinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self']);
        }
        $selfplugin->enrol_user($selfinstance, $user->id);

        // Clear cache to force fresh query.
        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $records = block_enrolmenttimer_get_enrolment_records($user->id, $course->id);

        // Should have 2 records (one per enrolment method), not 1.
        $this->assertCount(2, $records);
    }

    /**
     * Test get_enrolment_records returns empty for non-enrolled user.
     */
    public function test_enrolment_records_not_enrolled() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $records = block_enrolmenttimer_get_enrolment_records($user->id, $course->id);

        $this->assertEmpty($records);
    }

    /**
     * Test get_best_enrolment_record selects soonest non-zero end date.
     */
    public function test_best_record_soonest_end_date() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        $farend = time() + (30 * 86400);
        $nearend = time() + (5 * 86400);

        // First enrolment with far end date.
        $manualplugin->enrol_user($manualenrol, $user->id, null, 0, $farend);

        // Create a second manual enrolment instance with closer end date.
        // We directly insert a second user_enrolments record for the same user.
        $ue = $DB->get_record('user_enrolments', [
            'userid' => $user->id,
            'enrolid' => $manualenrol->id,
        ]);

        // Add a second enrol method (guest type, just to have a different enrolid).
        $enrolid2 = $DB->insert_record('enrol', (object)[
            'enrol' => 'manual',
            'courseid' => $course->id,
            'status' => 0,
        ]);
        $DB->insert_record('user_enrolments', (object)[
            'enrolid' => $enrolid2,
            'userid' => $user->id,
            'timestart' => 0,
            'timeend' => $nearend,
            'status' => 0,
            'modifierid' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Clear cache.
        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $best = block_enrolmenttimer_get_best_enrolment_record($user->id, $course->id);

        $this->assertNotFalse($best);
        // Should pick the nearer end date (5 days).
        $this->assertEquals($nearend, $best->timeend);
    }

    /**
     * Test get_best_enrolment_record falls back to first record when all have timeend=0.
     */
    public function test_best_record_all_zero_timeend() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Enrol with no end date (timeend=0).
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $best = block_enrolmenttimer_get_best_enrolment_record($user->id, $course->id);

        $this->assertNotFalse($best);
        $this->assertEquals(0, $best->timeend);
    }

    /**
     * Test get_best_enrolment_record returns false for non-enrolled user.
     */
    public function test_best_record_not_enrolled() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $result = block_enrolmenttimer_get_best_enrolment_record($user->id, $course->id);

        $this->assertFalse($result);
    }

    /**
     * Test get_enrolment_info returns progress data.
     */
    public function test_enrolment_info_returns_progress() {
        global $COURSE, $USER, $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        $start = time() - (10 * 86400);
        $end = time() + (10 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, $start, $end);

        // Set global COURSE.
        $COURSE = $course;

        // Clear cache.
        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $info = block_enrolmenttimer_get_enrolment_info();

        $this->assertIsArray($info);
        $this->assertEquals($end, $info['endtime']);
        $this->assertEquals($start, $info['starttime']);
        $this->assertFalse($info['expired']);
        $this->assertGreaterThan(0, $info['daysremaining']);
        // Progress should be roughly 50% (10 days passed out of 20 total).
        $this->assertGreaterThan(40, $info['progress']);
        $this->assertLessThan(60, $info['progress']);
    }

    /**
     * Test get_enrolment_info returns false for admins.
     */
    public function test_enrolment_info_admin_returns_false() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $COURSE = $course;

        $info = block_enrolmenttimer_get_enrolment_info();

        $this->assertFalse($info);
    }

    /**
     * Test get_enrolment_info returns expired status for past end date.
     */
    public function test_enrolment_info_expired() {
        global $COURSE, $USER, $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        $start = time() - (20 * 86400);
        $end = time() - (1 * 86400); // Ended yesterday.
        $manualplugin->enrol_user($manualenrol, $user->id, null, $start, $end);

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $info = block_enrolmenttimer_get_enrolment_info();

        $this->assertIsArray($info);
        $this->assertTrue($info['expired']);
        $this->assertEquals(100.0, $info['progress']);
        $this->assertEquals(0, $info['daysremaining']);
    }

    /**
     * Test get_enrolment_info returns false when no end date.
     */
    public function test_enrolment_info_no_end_date() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $info = block_enrolmenttimer_get_enrolment_info();

        $this->assertFalse($info);
    }

    /**
     * Test get_remaining_enrolment_period returns false for admins.
     */
    public function test_remaining_period_admin_returns_false() {
        global $COURSE;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $COURSE = $course;

        $result = block_enrolmenttimer_get_remaining_enrolment_period('');

        $this->assertFalse($result);
    }

    /**
     * Test get_remaining_enrolment_period returns time breakdown.
     */
    public function test_remaining_period_returns_units() {
        global $COURSE, $USER, $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        // Enrol with end date 5 days from now.
        $end = time() + (5 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, 0, $end);

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $result = block_enrolmenttimer_get_remaining_enrolment_period('');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Sum of all unit values * counts should approximate 5 days.
        $units = block_enrolmenttimer_get_units();
        $totalseconds = 0;
        foreach ($result as $unitname => $count) {
            $totalseconds += $count * $units[$unitname];
        }
        // Should be within a few seconds of 5 days.
        $expected = 5 * 86400;
        $this->assertLessThan(10, abs($totalseconds - $expected), 'Total seconds should be close to 5 days');
    }

    /**
     * Test get_remaining_enrolment_period returns false for expired enrolment.
     */
    public function test_remaining_period_expired_returns_false() {
        global $COURSE, $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        // Enrol with end date in the past.
        $end = time() - 86400;
        $manualplugin->enrol_user($manualenrol, $user->id, null, 0, $end);

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        $result = block_enrolmenttimer_get_remaining_enrolment_period('');

        $this->assertFalse($result);
    }

    /**
     * Test get_remaining_enrolment_period respects viewoptions filter.
     */
    public function test_remaining_period_with_viewoptions() {
        global $COURSE, $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');

        // Enrol with end date 5 days + 3 hours from now (non-even to ensure hours appear).
        $end = time() + (5 * 86400) + (3 * 3600);
        $manualplugin->enrol_user($manualenrol, $user->id, null, 0, $end);

        $COURSE = $course;

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        // Only show days and hours (indices 3, 4).
        $result = block_enrolmenttimer_get_remaining_enrolment_period('3,4');

        $this->assertIsArray($result);
        // Should only have days and hours keys.
        $this->assertCount(2, $result);
        $keys = array_keys($result);
        $unitkeys = array_keys(block_enrolmenttimer_get_units());
        $this->assertEquals($unitkeys[3], $keys[0]); // Days.
        $this->assertEquals($unitkeys[4], $keys[1]); // Hours.
    }

    /**
     * Test enrolment records cache is used on repeated calls.
     */
    public function test_enrolment_records_cache() {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $cache = \cache::make('block_enrolmenttimer', 'enrolmentdata');
        $cache->purge();

        // First call populates cache.
        $records1 = block_enrolmenttimer_get_enrolment_records($user->id, $course->id);

        // Verify cache was set.
        $cachekey = $user->id . '_' . $course->id;
        $cached = $cache->get($cachekey);
        $this->assertNotFalse($cached);
        $this->assertEquals(count($records1), count($cached));

        // Second call should use cache.
        $records2 = block_enrolmenttimer_get_enrolment_records($user->id, $course->id);
        $this->assertEquals($records1, $records2);
    }
}
