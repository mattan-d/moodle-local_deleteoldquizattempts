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
 * CLI script to delete empty question categories.
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'courseid' => null,
        'verbose' => false,
        'help' => false,
    ],
    [
        'v' => 'verbose',
        'h' => 'help',
    ]
);

if ($options['help']) {
    $help = "Delete empty question categories (categories with no questions)

Options:
--courseid=<id>       Delete only empty categories from course with specified id (optional, checks all if not specified)
-v, --verbose         Show detailed progress
-h, --help            Print out this help

Examples:
 php delete_empty_categories.php --courseid=3700 --verbose
 php delete_empty_categories.php --verbose
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

[$deleted, $skipped] = $helper->delete_empty_categories($trace);

echo "\n";
echo "Summary:\n";
echo "  Deleted: {$deleted}\n";
echo "  Skipped: {$skipped}\n";
echo "\n";

if ($trace) {
    $trace->finished();
}

exit(0);
