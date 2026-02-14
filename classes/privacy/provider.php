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
 * Privacy API provider.
 *
 * @package    block_enrolmenttimer
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for block_enrolmenttimer.
 *
 * The block_enrolmenttimer table stores enrolid (referencing user_enrolments.id)
 * which links to a specific user. This constitutes personal data.
 *
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'block_enrolmenttimer',
            [
                'enrolid' => 'privacy:metadata:enrolid',
                'alerttime' => 'privacy:metadata:alerttime',
                'sent' => 'privacy:metadata:sent',
            ],
            'privacy:metadata:block_enrolmenttimer'
        );

        $collection->add_user_preference(
            'block_enrolmenttimer_completion',
            'privacy:metadata:completion_preference'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Find course contexts where this user has enrolment timer records.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {enrol} e ON e.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
                  JOIN {block_enrolmenttimer} bet ON bet.enrolid = ue.id";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT ue.userid
                  FROM {block_enrolmenttimer} bet
                  JOIN {user_enrolments} ue ON ue.id = bet.enrolid
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified user in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $sql = "SELECT bet.id, bet.enrolid, bet.alerttime, bet.sent
                      FROM {block_enrolmenttimer} bet
                      JOIN {user_enrolments} ue ON ue.id = bet.enrolid
                      JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE ue.userid = :userid AND e.courseid = :courseid";

            $records = $DB->get_records_sql($sql, [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            if (!empty($records)) {
                $data = [];
                foreach ($records as $record) {
                    $data[] = (object)[
                        'enrolid' => $record->enrolid,
                        'alerttime' => \core_privacy\local\request\transform::datetime($record->alerttime),
                        'sent' => \core_privacy\local\request\transform::yesno($record->sent),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_enrolmenttimer')],
                    (object)['alerts' => $data]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT bet.id
                  FROM {block_enrolmenttimer} bet
                  JOIN {user_enrolments} ue ON ue.id = bet.enrolid
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid";

        $recordids = $DB->get_fieldset_sql($sql, ['courseid' => $context->instanceid]);
        if (!empty($recordids)) {
            list($insql, $params) = $DB->get_in_or_equal($recordids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('block_enrolmenttimer', "id $insql", $params);
        }
    }

    /**
     * Delete all user data for the specified user in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $sql = "SELECT bet.id
                      FROM {block_enrolmenttimer} bet
                      JOIN {user_enrolments} ue ON ue.id = bet.enrolid
                      JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE ue.userid = :userid AND e.courseid = :courseid";

            $recordids = $DB->get_fieldset_sql($sql, [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            if (!empty($recordids)) {
                list($insql, $params) = $DB->get_in_or_equal($recordids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('block_enrolmenttimer', "id $insql", $params);
            }
        }
    }

    /**
     * Delete multiple users' data within a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $sql = "SELECT bet.id
                  FROM {block_enrolmenttimer} bet
                  JOIN {user_enrolments} ue ON ue.id = bet.enrolid
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid $usersql AND e.courseid = :courseid";

        $params = array_merge($userparams, ['courseid' => $context->instanceid]);
        $recordids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($recordids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($recordids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('block_enrolmenttimer', "id $insql", $inparams);
        }
    }
}
