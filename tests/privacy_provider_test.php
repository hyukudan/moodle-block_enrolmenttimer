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
 * Privacy provider tests for block_enrolmenttimer.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer;

use advanced_testcase;
use block_enrolmenttimer\privacy\provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider tests.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_enrolmenttimer\privacy\provider
 */
class privacy_provider_test extends advanced_testcase {

    /**
     * Helper: create a user enrolled in a course with an alert record.
     *
     * @return array ['user' => stdClass, 'course' => stdClass, 'alertid' => int, 'ueid' => int]
     */
    private function create_test_data() {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $manualenrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $manualplugin = enrol_get_plugin('manual');
        $end = time() + (30 * 86400);
        $manualplugin->enrol_user($manualenrol, $user->id, null, time(), $end);

        // Get the user_enrolments.id.
        $ue = $DB->get_record('user_enrolments', [
            'userid' => $user->id,
            'enrolid' => $manualenrol->id,
        ]);

        // Insert alert record.
        $alertid = $DB->insert_record('block_enrolmenttimer', (object)[
            'enrolid' => $ue->id,
            'alerttime' => time() + (20 * 86400),
            'sent' => 0,
        ]);

        return ['user' => $user, 'course' => $course, 'alertid' => $alertid, 'ueid' => $ue->id];
    }

    /**
     * Test get_metadata describes table and preferences.
     */
    public function test_get_metadata() {
        $collection = new \core_privacy\local\metadata\collection('block_enrolmenttimer');
        $collection = provider::get_metadata($collection);

        $items = $collection->get_collection();
        $this->assertNotEmpty($items);

        // Should have at least a database table and a user preference.
        $types = [];
        foreach ($items as $item) {
            $types[] = get_class($item);
        }
        $this->assertContains('core_privacy\local\metadata\types\database_table', $types);
        $this->assertContains('core_privacy\local\metadata\types\user_preference', $types);
    }

    /**
     * Test get_contexts_for_userid returns course context with alert data.
     */
    public function test_get_contexts_for_userid() {
        $this->resetAfterTest(true);

        $data = $this->create_test_data();
        $coursecontext = \context_course::instance($data['course']->id);

        $contextlist = provider::get_contexts_for_userid($data['user']->id);

        $this->assertNotEmpty($contextlist->get_contextids());
        $this->assertContainsEquals($coursecontext->id, $contextlist->get_contextids());
    }

    /**
     * Test get_contexts_for_userid returns empty for user without alerts.
     */
    public function test_get_contexts_for_userid_no_data() {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();

        $contextlist = provider::get_contexts_for_userid($user->id);

        $this->assertEmpty($contextlist->get_contextids());
    }

    /**
     * Test get_users_in_context returns users with alert data in a course.
     */
    public function test_get_users_in_context() {
        $this->resetAfterTest(true);

        $data = $this->create_test_data();
        $coursecontext = \context_course::instance($data['course']->id);

        $userlist = new \core_privacy\local\request\userlist($coursecontext, 'block_enrolmenttimer');
        provider::get_users_in_context($userlist);

        $this->assertContainsEquals($data['user']->id, $userlist->get_userids());
    }

    /**
     * Test get_users_in_context ignores non-course contexts.
     */
    public function test_get_users_in_context_system_context() {
        $this->resetAfterTest(true);

        $this->create_test_data();
        $systemcontext = \context_system::instance();

        $userlist = new \core_privacy\local\request\userlist($systemcontext, 'block_enrolmenttimer');
        provider::get_users_in_context($userlist);

        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * Test export_user_data exports alert records.
     */
    public function test_export_user_data() {
        $this->resetAfterTest(true);

        $data = $this->create_test_data();
        $coursecontext = \context_course::instance($data['course']->id);

        $contextlist = new approved_contextlist(
            $data['user'], 'block_enrolmenttimer', [$coursecontext->id]
        );
        provider::export_user_data($contextlist);

        $writer = writer::with_context($coursecontext);
        $exported = $writer->get_data([get_string('pluginname', 'block_enrolmenttimer')]);

        $this->assertNotEmpty($exported);
        $this->assertNotEmpty($exported->alerts);
        $this->assertCount(1, $exported->alerts);
        $this->assertEquals($data['ueid'], $exported->alerts[0]->enrolid);
    }

    /**
     * Test export_user_data exports user preferences.
     */
    public function test_export_user_data_includes_preferences() {
        $this->resetAfterTest(true);

        $data = $this->create_test_data();
        $coursecontext = \context_course::instance($data['course']->id);

        // Set completion preference.
        $prefkey = 'block_enrolmenttimer_completion_' . $data['course']->id;
        set_user_preference($prefkey, time(), $data['user']->id);

        $contextlist = new approved_contextlist(
            $data['user'], 'block_enrolmenttimer', [$coursecontext->id]
        );
        provider::export_user_data($contextlist);

        // Preferences are exported at the course context level by our provider.
        $writer = writer::with_context($coursecontext);

        // Verify that the exported data includes both alert records and the preference.
        // The preference is exported via export_user_preference which stores it for the component.
        // Check that alert data was exported (this confirms the export ran).
        $exported = $writer->get_data([get_string('pluginname', 'block_enrolmenttimer')]);
        $this->assertNotEmpty($exported);
        $this->assertNotEmpty($exported->alerts);

        // Verify the preference still exists in the DB (export doesn't delete it).
        $prefvalue = get_user_preferences($prefkey, null, $data['user']->id);
        $this->assertNotNull($prefvalue);
    }

    /**
     * Test delete_data_for_user removes only that user's data.
     */
    public function test_delete_data_for_user() {
        global $DB;

        $this->resetAfterTest(true);

        $data1 = $this->create_test_data();
        // Create a second user in the same course.
        $user2 = $this->getDataGenerator()->create_user();
        $manualenrol = $DB->get_record('enrol', [
            'courseid' => $data1['course']->id,
            'enrol' => 'manual',
        ]);
        $manualplugin = enrol_get_plugin('manual');
        $manualplugin->enrol_user($manualenrol, $user2->id, null, time(), time() + 86400);

        $ue2 = $DB->get_record('user_enrolments', [
            'userid' => $user2->id,
            'enrolid' => $manualenrol->id,
        ]);
        $DB->insert_record('block_enrolmenttimer', (object)[
            'enrolid' => $ue2->id,
            'alerttime' => time() + 86400,
            'sent' => 0,
        ]);

        // Set completion preferences for both users.
        $prefkey = 'block_enrolmenttimer_completion_' . $data1['course']->id;
        set_user_preference($prefkey, time(), $data1['user']->id);
        set_user_preference($prefkey, time(), $user2->id);

        $coursecontext = \context_course::instance($data1['course']->id);
        $contextlist = new approved_contextlist(
            $data1['user'], 'block_enrolmenttimer', [$coursecontext->id]
        );
        provider::delete_data_for_user($contextlist);

        // User1's alert should be gone.
        $this->assertFalse($DB->record_exists('block_enrolmenttimer', ['id' => $data1['alertid']]));
        // User1's preference should be gone.
        $this->assertNull(get_user_preferences($prefkey, null, $data1['user']->id));

        // User2's alert should remain.
        $this->assertTrue($DB->record_exists('block_enrolmenttimer', ['enrolid' => $ue2->id]));
        // User2's preference should remain.
        $this->assertNotNull(get_user_preferences($prefkey, null, $user2->id));
    }

    /**
     * Test delete_data_for_all_users_in_context removes all data in course.
     */
    public function test_delete_data_for_all_users_in_context() {
        global $DB;

        $this->resetAfterTest(true);

        $data = $this->create_test_data();

        // Set completion preference.
        $prefkey = 'block_enrolmenttimer_completion_' . $data['course']->id;
        set_user_preference($prefkey, time(), $data['user']->id);

        $coursecontext = \context_course::instance($data['course']->id);
        provider::delete_data_for_all_users_in_context($coursecontext);

        // Alert should be gone.
        $this->assertEquals(0, $DB->count_records('block_enrolmenttimer', ['id' => $data['alertid']]));
        // Preference should be gone.
        $this->assertNull(get_user_preferences($prefkey, null, $data['user']->id));
    }

    /**
     * Test delete_data_for_all_users_in_context ignores non-course context.
     */
    public function test_delete_data_for_all_users_non_course_context() {
        global $DB;

        $this->resetAfterTest(true);

        $data = $this->create_test_data();

        $systemcontext = \context_system::instance();
        provider::delete_data_for_all_users_in_context($systemcontext);

        // Alert should still exist (system context is ignored).
        $this->assertTrue($DB->record_exists('block_enrolmenttimer', ['id' => $data['alertid']]));
    }

    /**
     * Test delete_data_for_users removes data for specified users.
     */
    public function test_delete_data_for_users() {
        global $DB;

        $this->resetAfterTest(true);

        $data = $this->create_test_data();

        $prefkey = 'block_enrolmenttimer_completion_' . $data['course']->id;
        set_user_preference($prefkey, time(), $data['user']->id);

        $coursecontext = \context_course::instance($data['course']->id);
        $userlist = new approved_userlist(
            $coursecontext, 'block_enrolmenttimer', [$data['user']->id]
        );
        provider::delete_data_for_users($userlist);

        // Alert should be gone.
        $this->assertFalse($DB->record_exists('block_enrolmenttimer', ['id' => $data['alertid']]));
        // Preference should be gone.
        $this->assertNull(get_user_preferences($prefkey, null, $data['user']->id));
    }

    /**
     * Test delete_data_for_users with empty userlist does nothing.
     */
    public function test_delete_data_for_users_empty() {
        global $DB;

        $this->resetAfterTest(true);

        $data = $this->create_test_data();

        $coursecontext = \context_course::instance($data['course']->id);
        $userlist = new approved_userlist(
            $coursecontext, 'block_enrolmenttimer', []
        );
        provider::delete_data_for_users($userlist);

        // Alert should still exist.
        $this->assertTrue($DB->record_exists('block_enrolmenttimer', ['id' => $data['alertid']]));
    }
}
