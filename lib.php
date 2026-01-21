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
 * Tool for deleting old quiz and question attempts.
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2019 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend course navigation to add cleanup link.
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass $course The course object
 * @param context_course $context The course context
 */
function local_deleteoldquizattempts_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('moodle/site:config', $context)) {
        $url = new moodle_url('/local/deleteoldquizattempts/cleanup.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('cleanupquestions', 'local_deleteoldquizattempts'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'local_deleteoldquizattempts_cleanup',
            new pix_icon('i/cleanup', '')
        );
    }
}
