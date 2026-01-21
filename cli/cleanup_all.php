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
 * Run all cleanup operations together.
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
        'courseids' => '',
        'courseid' => '',
        'timelimit' => 0,
        'verbose' => false,
        'help' => false,
    ],
    [
        'v' => 'verbose',
        'h' => 'help',
    ]
);

if ($options['help']) {
    $help = "Run all cleanup operations together.

This script performs the following operations:
1. Delete duplicate questions (keep oldest)
2. Delete empty duplicate categories
3. Delete empty categories
4. Delete unused questions

Options:
--courseids=<ids>     Comma-separated list of course IDs to process (e.g., 100,200,300)
--courseid=<id>       Single course ID to process (alternative to --courseids)
                      If neither is specified, processes all courses
--timelimit=<sec>     Stop execution after specified number of seconds
-v, --verbose         Show detailed progress
-h, --help            Print out this help

Examples:
 php cleanup_all.php --courseids=100,200,300 --verbose
 php cleanup_all.php --courseid=5 --verbose
 php cleanup_all.php --verbose (processes all courses)
 php cleanup_all.php --courseids=10,20 --timelimit=300
";
    echo $help;
    exit(0);
}

// Ensure errors are well explained.
set_debugging(DEBUG_DEVELOPER, true);

// Parse course IDs
$courseids = [];
if (!empty($options['courseids'])) {
    $courseids = array_map('intval', explode(',', $options['courseids']));
} else if (!empty($options['courseid'])) {
    $courseids = [(int)$options['courseid']];
}

if (empty($courseids)) {
    // Get all course IDs
    $courses = $DB->get_records('course', null, '', 'id');
    foreach ($courses as $course) {
        if ($course->id != SITEID) {
            $courseids[] = $course->id;
        }
    }
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

$helper = new \local_deleteoldquizattempts\helper();

// Summary statistics
$summary = [
    'courses_processed' => 0,
    'duplicate_questions_deleted' => 0,
    'duplicate_questions_skipped' => 0,
    'empty_duplicate_categories_deleted' => 0,
    'empty_duplicate_categories_skipped' => 0,
    'empty_categories_deleted' => 0,
    'empty_categories_skipped' => 0,
    'unused_questions_deleted' => 0,
    'unused_questions_skipped' => 0,
];

if ($trace) {
    $trace->output("=======================================================");
    $trace->output("Starting cleanup operations for " . count($courseids) . " course(s)");
    $trace->output("=======================================================\n");
}

foreach ($courseids as $courseid) {
    // Verify course exists
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        if ($trace) {
            $trace->output("Skipping non-existent course ID: $courseid\n");
        }
        continue;
    }

    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
    
    if ($trace) {
        $trace->output("\n=======================================================");
        $trace->output("Processing Course: {$course->fullname} (ID: {$course->id})");
        $trace->output("=======================================================\n");
    }

    $helper->courseid = $courseid;

    // Operation 1: Delete duplicate questions
    if ($trace) {
        $trace->output("\n--- Step 1: Deleting duplicate questions ---");
    }
    [$deleted, $skipped] = $helper->delete_duplicate_questions($stoptime, $trace);
    $summary['duplicate_questions_deleted'] += $deleted;
    $summary['duplicate_questions_skipped'] += $skipped;
    if ($trace) {
        $trace->output("Result: Deleted $deleted, Skipped $skipped duplicate questions\n");
    }

    if ($stoptime && (time() >= $stoptime)) {
        if ($trace) {
            $trace->output("\nTime limit reached. Stopping cleanup operations.");
        }
        break;
    }

    // Operation 2: Delete empty duplicate categories
    if ($trace) {
        $trace->output("\n--- Step 2: Deleting empty duplicate categories ---");
    }
    [$deleted, $skipped] = $helper->delete_empty_duplicate_categories($stoptime, $trace);
    $summary['empty_duplicate_categories_deleted'] += $deleted;
    $summary['empty_duplicate_categories_skipped'] += $skipped;
    if ($trace) {
        $trace->output("Result: Deleted $deleted, Skipped $skipped empty duplicate categories\n");
    }

    if ($stoptime && (time() >= $stoptime)) {
        if ($trace) {
            $trace->output("\nTime limit reached. Stopping cleanup operations.");
        }
        break;
    }

    // Operation 3: Delete empty categories
    if ($trace) {
        $trace->output("\n--- Step 3: Deleting empty categories ---");
    }
    [$deleted, $skipped] = $helper->delete_empty_categories($stoptime, $trace);
    $summary['empty_categories_deleted'] += $deleted;
    $summary['empty_categories_skipped'] += $skipped;
    if ($trace) {
        $trace->output("Result: Deleted $deleted, Skipped $skipped empty categories\n");
    }

    if ($stoptime && (time() >= $stoptime)) {
        if ($trace) {
            $trace->output("\nTime limit reached. Stopping cleanup operations.");
        }
        break;
    }

    // Operation 4: Delete unused questions
    if ($trace) {
        $trace->output("\n--- Step 4: Deleting unused questions ---");
    }
    [$deleted, $skipped] = $helper->delete_unused_questions($stoptime, $trace);
    $summary['unused_questions_deleted'] += $deleted;
    $summary['unused_questions_skipped'] += $skipped;
    if ($trace) {
        $trace->output("Result: Deleted $deleted, Skipped $skipped unused questions\n");
    }

    $summary['courses_processed']++;

    if ($stoptime && (time() >= $stoptime)) {
        if ($trace) {
            $trace->output("\nTime limit reached. Stopping cleanup operations.");
        }
        break;
    }
}

// Display final summary
if ($trace) {
    $trace->output("\n=======================================================");
    $trace->output("FINAL SUMMARY");
    $trace->output("=======================================================");
    $trace->output("Courses processed: " . $summary['courses_processed']);
    $trace->output("");
    $trace->output("Duplicate questions:");
    $trace->output("  - Deleted: " . $summary['duplicate_questions_deleted']);
    $trace->output("  - Skipped: " . $summary['duplicate_questions_skipped']);
    $trace->output("");
    $trace->output("Empty duplicate categories:");
    $trace->output("  - Deleted: " . $summary['empty_duplicate_categories_deleted']);
    $trace->output("  - Skipped: " . $summary['empty_duplicate_categories_skipped']);
    $trace->output("");
    $trace->output("Empty categories:");
    $trace->output("  - Deleted: " . $summary['empty_categories_deleted']);
    $trace->output("  - Skipped: " . $summary['empty_categories_skipped']);
    $trace->output("");
    $trace->output("Unused questions:");
    $trace->output("  - Deleted: " . $summary['unused_questions_deleted']);
    $trace->output("  - Skipped: " . $summary['unused_questions_skipped']);
    $trace->output("=======================================================\n");
    $trace->finished();
} else {
    echo "Cleanup complete. Processed {$summary['courses_processed']} course(s).\n";
    echo "Total deleted: " . 
        ($summary['duplicate_questions_deleted'] + 
         $summary['empty_duplicate_categories_deleted'] + 
         $summary['empty_categories_deleted'] + 
         $summary['unused_questions_deleted']) . " items\n";
}

exit(0);
