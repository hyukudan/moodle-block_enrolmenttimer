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
 * Per-instance configuration form for the enrolment timer block.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Block instance settings form.
 */
class block_enrolmenttimer_edit_form extends block_edit_form {

    /**
     * Add instance-specific settings fields.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {
        $mform->addElement('header', 'config_header',
            get_string('instance_title', 'block_enrolmenttimer'));

        $mform->addElement('text', 'config_title',
            get_string('instance_title', 'block_enrolmenttimer'));
        $mform->setDefault('config_title', '');
        $mform->setType('config_title', PARAM_TEXT);

        $mform->addElement('selectyesno', 'config_override_showprogressbar',
            get_string('showprogressbar', 'block_enrolmenttimer'));
        $mform->setDefault('config_override_showprogressbar', -1);

        $mform->addElement('selectyesno', 'config_override_showexpirydate',
            get_string('showexpirydate', 'block_enrolmenttimer'));
        $mform->setDefault('config_override_showexpirydate', -1);

        $mform->addElement('textarea', 'config_custommessage',
            get_string('instance_custommessage', 'block_enrolmenttimer'),
            ['rows' => 3, 'cols' => 40]);
        $mform->setDefault('config_custommessage', '');
        $mform->setType('config_custommessage', PARAM_TEXT);
    }
}
