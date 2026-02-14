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
 * Lib File
 *
 * @package    block_enrolmenttimer
 * @copyright  LearningWorks Ltd 2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Checks the timeleft on enrolment in a given course.
 *
 * @param string $unitstoshow Comma-separated unit IDs to display.
 * @return array|false Time remaining broken into units, or false if no end date.
 */
function block_enrolmenttimer_get_remaining_enrolment_period($unitstoshow) {
    global $COURSE, $USER, $DB;

    $context = context_course::instance($COURSE->id);

    if (has_capability('moodle/site:config', $context)) {
        return false;
    }

    // Find the enrolment with the soonest non-zero end date.
    $record = block_enrolmenttimer_get_best_enrolment_record($USER->id, $COURSE->id);

    if (!$record) {
        return false;
    }

    // Determine the effective end time.
    $endtime = 0;
    if ($record->timeend != 0) {
        $endtime = (int)$record->timeend;
    } else {
        // Check enrol method end date (any type, not just self-enrolment).
        $enrol = $DB->get_record('enrol', ['id' => $record->enrolid], 'enrolenddate');
        if ($enrol && !empty($enrol->enrolenddate) && (int)$enrol->enrolenddate > 0) {
            $endtime = (int)$enrol->enrolenddate;
        }
    }

    if ($endtime <= 0) {
        return false;
    }

    $timedifference = $endtime - time();
    if ($timedifference <= 0) {
        return false;
    }

    if (empty($unitstoshow)) {
        $units = block_enrolmenttimer_get_units();
    } else {
        $units = block_enrolmenttimer_sort_units_to_show($unitstoshow);
    }

    $result = [];
    foreach ($units as $text => $unit) {
        if ($timedifference >= $unit) {
            $count = floor($timedifference / $unit);
            $result[$text] = $count;
            $timedifference -= ($count * $unit);
        }
    }

    return $result;
}

/**
 * Find the best enrolment record for display (soonest non-zero end date).
 *
 * @param int $userid
 * @param int $courseid
 * @return stdClass|false The enrolment record, or false if none found.
 */
function block_enrolmenttimer_get_best_enrolment_record($userid, $courseid) {
    global $DB;

    $records = block_enrolmenttimer_get_enrolment_records($userid, $courseid);
    if (empty($records)) {
        return false;
    }

    // Prefer the enrolment with the soonest non-zero timeend.
    $best = null;
    foreach ($records as $r) {
        if ($r->timeend > 0) {
            if ($best === null || $r->timeend < $best->timeend) {
                $best = $r;
            }
        }
    }

    // If all have timeend=0, return first one to check enrol method dates.
    if ($best === null) {
        $best = reset($records);
    }

    return $best;
}

/**
 * Return enrolment records keyed by user_enrolments.id.
 *
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function block_enrolmenttimer_get_enrolment_records($userid, $courseid) {
    global $DB;

    $sql = '
        SELECT ue.id, ue.userid, ue.timestart, ue.timeend, ue.enrolid
        FROM {user_enrolments} ue
        JOIN {enrol} e ON ue.enrolid = e.id
        WHERE ue.userid = ? AND e.courseid = ?
        ORDER BY ue.timeend ASC
    ';
    return $DB->get_records_sql($sql, [$userid, $courseid]);
}

/**
 * Return the values for different time periods.
 * @return array
 */
function block_enrolmenttimer_get_units() {
    return [
        get_string('key_years', 'block_enrolmenttimer')   => 31536000,
        get_string('key_months', 'block_enrolmenttimer')  => 2592000,
        get_string('key_weeks', 'block_enrolmenttimer')   => 604800,
        get_string('key_days', 'block_enrolmenttimer')    => 86400,
        get_string('key_hours', 'block_enrolmenttimer')   => 3600,
        get_string('key_minutes', 'block_enrolmenttimer') => 60,
        get_string('key_seconds', 'block_enrolmenttimer') => 1,
    ];
}

/**
 * Sort units to show based on comma-separated ID string from config.
 *
 * @param string $idstring Comma-separated unit indices (e.g. "0,1,3").
 * @return array
 */
function block_enrolmenttimer_sort_units_to_show($idstring) {
    $idarray = explode(',', $idstring);
    $units = block_enrolmenttimer_get_units();
    $unitkeys = array_keys($units);
    $maxindex = count($unitkeys) - 1;
    $output = [];

    foreach ($idarray as $value) {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            continue;
        }
        $idx = (int)$value;
        if ($idx < 0 || $idx > $maxindex) {
            continue;
        }
        $unitkey = $unitkeys[$idx];
        $output[$unitkey] = $units[$unitkey];
    }

    if (empty($output)) {
        return block_enrolmenttimer_get_units();
    }

    return $output;
}
