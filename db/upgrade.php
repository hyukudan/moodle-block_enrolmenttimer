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
 * Upgrade script
 *
 * @package    block_enrolmenttimer
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * When upgrading plugin, execute the following code.
 *
 * @param int $oldversion - previous version of plugin (from DB).
 * @return bool
 */
function xmldb_block_enrolmenttimer_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2017083000) {
        // Define table block_enrolmenttimer to be created.
        $table = new xmldb_table('block_enrolmenttimer');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('enrolid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('alerttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2017083000, 'enrolmenttimer');
    }

    if ($oldversion < 2026021500) {
        // Add index on enrolid for faster lookups.
        $table = new xmldb_table('block_enrolmenttimer');
        $index = new xmldb_index('enrolid_idx', XMLDB_INDEX_NOTUNIQUE, ['enrolid']);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add index on sent + alerttime for the pending alerts query.
        $index2 = new xmldb_index('sent_alerttime_idx', XMLDB_INDEX_NOTUNIQUE, ['sent', 'alerttime']);
        if (!$dbman->index_exists($table, $index2)) {
            $dbman->add_index($table, $index2);
        }

        upgrade_block_savepoint(true, 2026021500, 'enrolmenttimer');
    }

    if ($oldversion < 2026021502) {
        $table = new xmldb_table('block_enrolmenttimer');

        // Remove old non-unique index on enrolid if it exists.
        $oldindex = new xmldb_index('enrolid_idx', XMLDB_INDEX_NOTUNIQUE, ['enrolid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        // Remove any duplicate enrolid rows before adding UNIQUE constraint.
        $duplicates = $DB->get_records_sql(
            "SELECT enrolid FROM {block_enrolmenttimer} GROUP BY enrolid HAVING COUNT(*) > 1"
        );
        foreach ($duplicates as $dup) {
            $records = $DB->get_records('block_enrolmenttimer', ['enrolid' => $dup->enrolid], 'id ASC');
            $first = true;
            foreach ($records as $rec) {
                if ($first) {
                    $first = false;
                    continue;
                }
                $DB->delete_records('block_enrolmenttimer', ['id' => $rec->id]);
            }
        }

        // Add UNIQUE index on enrolid to prevent race condition duplicates.
        $newindex = new xmldb_index('enrolid_uq', XMLDB_INDEX_UNIQUE, ['enrolid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        upgrade_block_savepoint(true, 2026021502, 'enrolmenttimer');
    }

    return true;
}
