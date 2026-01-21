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
 * CLI script for deleting duplicate questions.
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2019 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'courseid' => false,
        'timelimit' => false,
        'verbose' => false,
        'help' => false,
    ],
    [
        'v' => 'verbose',
        'h' => 'help',
    ]
);

if ($options['help']) {
    $help = "Delete duplicate questions (keeps only the oldest version)

Options:
--courseid=           Delete only duplicate questions from course with specified id (optional, processes all if not specified)
--timelimit=          Stop execution after specified number of seconds
-v, --verbose         Show progress
-h, --help            Print out this help

Examples:
 php local/deleteoldquizattempts/cli/delete_duplicate_questions.php --courseid=5 --verbose
 php local/deleteoldquizattempts/cli/delete_duplicate_questions.php --verbose
 php local/deleteoldquizattempts/cli/delete_duplicate_questions.php --courseid=5 --timelimit=300
";
    echo $help;
    exit(0);
}

// Ensure errors are well explained.
set_debugging(DEBUG_DEVELOPER, true);

$helper = new \local_deleteoldquizattempts\helper();

if (!empty($options['courseid'])) {
    $helper->courseid = (int)$options['courseid'];
}

if ($options['verbose']) {
    $trace = new text_progress_trace();
} else {
    $trace = null;
}

if ($options['timelimit']) {
    $stoptime = time() + (int)$options['timelimit'];
} else {
    $stoptime = 0;
}

$helper->delete_duplicate_questions($stoptime, $trace);

if ($trace) {
    $trace->finished();
}
