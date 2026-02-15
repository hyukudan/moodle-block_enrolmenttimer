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
 * Backup task for block_enrolmenttimer.
 *
 * @package    block_enrolmenttimer
 * @copyright  2026 block_enrolmenttimer contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/enrolmenttimer/backup/moodle2/backup_enrolmenttimer_stepslib.php');

/**
 * Backup task for the enrolmenttimer block.
 */
class backup_enrolmenttimer_block_task extends backup_block_task {

    /**
     * Define block-specific settings.
     */
    protected function define_my_settings() {
        // No specific settings for this block backup.
    }

    /**
     * Define block-specific steps.
     */
    protected function define_my_steps() {
        // No extra steps needed — block_instances.configdata is handled automatically.
    }

    /**
     * File areas used by this block.
     * @return array
     */
    public function get_fileareas() {
        return [];
    }

    /**
     * Attributes to encode in content.
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * Encode content links.
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        return $content;
    }
}
