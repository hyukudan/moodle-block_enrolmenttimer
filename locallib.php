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

    $endtime = block_enrolmenttimer_resolve_end_time($record);
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
 * Results are cached per-request to avoid repeated DB queries.
 *
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function block_enrolmenttimer_get_enrolment_records($userid, $courseid) {
    global $DB;

    $cache = cache::make('block_enrolmenttimer', 'enrolmentdata');
    $cachekey = $userid . '_' . $courseid;
    $records = $cache->get($cachekey);

    if ($records !== false) {
        return $records;
    }

    $sql = '
        SELECT ue.id, ue.userid, ue.timestart, ue.timeend, ue.enrolid
        FROM {user_enrolments} ue
        JOIN {enrol} e ON ue.enrolid = e.id
        WHERE ue.userid = ? AND e.courseid = ?
        ORDER BY ue.timeend ASC
    ';
    $records = $DB->get_records_sql($sql, [$userid, $courseid]);
    $cache->set($cachekey, $records);
    return $records;
}

/**
 * Return stable (untranslated) unit keys in order.
 * @return string[]
 */
function block_enrolmenttimer_get_stable_unit_keys() {
    return ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'];
}

/**
 * Return the values for different time periods.
 * Keys are translated strings for display.
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
 * Return a mapping from translated unit label to stable English key.
 * @return array ['días' => 'days', 'horas' => 'hours', ...]
 */
function block_enrolmenttimer_get_unit_key_map() {
    $stablekeys = block_enrolmenttimer_get_stable_unit_keys();
    $units = block_enrolmenttimer_get_units();
    $translatedkeys = array_keys($units);
    $map = [];
    foreach ($translatedkeys as $i => $translated) {
        $map[$translated] = $stablekeys[$i];
    }
    return $map;
}

/**
 * Resolve the effective end time for a user_enrolments record.
 * Checks user_enrolments.timeend first, falls back to enrol.enrolenddate.
 *
 * @param stdClass $uerecord A user_enrolments record with at least id, timeend, enrolid.
 * @return int The effective end timestamp, or 0 if none set.
 */
function block_enrolmenttimer_resolve_end_time($uerecord) {
    global $DB;

    if (!empty($uerecord->timeend) && (int)$uerecord->timeend > 0) {
        return (int)$uerecord->timeend;
    }

    $enrol = $DB->get_record('enrol', ['id' => $uerecord->enrolid], 'enrolenddate');
    if ($enrol && !empty($enrol->enrolenddate) && (int)$enrol->enrolenddate > 0) {
        return (int)$enrol->enrolenddate;
    }

    return 0;
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

/**
 * Get enrolment end timestamp and progress data for the current user in a course.
 *
 * @return array|false ['endtime' => int, 'starttime' => int, 'progress' => float, 'daysremaining' => int]
 */
function block_enrolmenttimer_get_enrolment_info() {
    global $COURSE, $USER, $DB;

    $context = context_course::instance($COURSE->id);
    if (has_capability('moodle/site:config', $context)) {
        return false;
    }

    $record = block_enrolmenttimer_get_best_enrolment_record($USER->id, $COURSE->id);
    if (!$record) {
        return false;
    }

    $endtime = block_enrolmenttimer_resolve_end_time($record);
    if ($endtime <= 0) {
        return false;
    }

    $starttime = (int)$record->timestart;
    $now = time();
    $timediff = $endtime - $now;

    if ($timediff <= 0) {
        return [
            'endtime' => $endtime,
            'starttime' => $starttime,
            'progress' => 100.0,
            'daysremaining' => 0,
            'expired' => true,
        ];
    }

    $progress = 0.0;
    if ($starttime > 0 && $endtime > $starttime) {
        $total = $endtime - $starttime;
        $elapsed = $now - $starttime;
        $progress = min(100.0, max(0.0, ($elapsed / $total) * 100));
    }

    return [
        'endtime' => $endtime,
        'starttime' => $starttime,
        'progress' => round($progress, 1),
        'daysremaining' => (int)ceil($timediff / 86400),
        'expired' => false,
    ];
}
