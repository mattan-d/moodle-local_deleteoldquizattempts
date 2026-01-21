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
 * CLI script to fix questions with missing options
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$usage = "Fix questions with missing options that cause restore errors.

Usage:
    php fix_missing_question_options.php [options]

Options:
    --courseid=<id>       Check only questions in specified course (optional, checks all if not specified)
    --delete              Delete broken questions instead of trying to fix them
    --fix                 Attempt to fix broken questions by adding default options
    -v, --verbose         Show detailed progress
    -h, --help            Print this help

Examples:
    php fix_missing_question_options.php --courseid=3700 --verbose
    php fix_missing_question_options.php --courseid=3700 --delete --verbose
    php fix_missing_question_options.php --fix --verbose

Note: If neither --delete nor --fix is specified, the script will only report broken questions without taking action.
";

list($options, $unrecognised) = cli_get_params([
    'help' => false,
    'verbose' => false,
    'courseid' => null,
    'delete' => false,
    'fix' => false,
], [
    'h' => 'help',
    'v' => 'verbose',
]);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    echo $usage;
    exit(0);
}

if ($options['delete'] && $options['fix']) {
    echo "Error: You cannot specify both --delete and --fix options.\n\n";
    echo $usage;
    exit(1);
}

// Ensure errors are well explained.
set_debugging(DEBUG_DEVELOPER, true);

$helper = new \local_deleteoldquizattempts\helper();

if ($options['courseid']) {
    $helper->courseid = (int)$options['courseid'];
}

if ($options['verbose']) {
    $trace = new text_progress_trace();
} else {
    $trace = null;
}

$action = 'report';
if ($options['delete']) {
    $action = 'delete';
} else if ($options['fix']) {
    $action = 'fix';
}

list($fixed, $failed) = $helper->fix_missing_question_options($action, $trace);

if ($trace) {
    $trace->output("\n" . get_string('missingoptionssummary', 'local_deleteoldquizattempts', [
        'fixed' => $fixed,
        'failed' => $failed,
        'action' => $action,
    ]));
    $trace->finished();
}

exit(0);
