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
 * Event fired when an enrolment expiry alert is sent.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_enrolmenttimer\event;

/**
 * Event class for alert_sent.
 */
class alert_sent extends \core\event\base {

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return the event name.
     * @return string
     */
    public static function get_name() {
        return get_string('event_alert_sent', 'block_enrolmenttimer');
    }

    /**
     * Return the event description.
     * @return string
     */
    public function get_description() {
        return "Enrolment expiry alert sent to user {$this->relateduserid} in course {$this->courseid}.";
    }

    /**
     * Return the URL related to this event.
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
