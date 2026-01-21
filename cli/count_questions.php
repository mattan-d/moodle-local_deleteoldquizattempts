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
 * CLI script to count total questions in a course.
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
        'top' => false,
        'verbose' => false,
        'help' => false,
    ],
    [
        'v' => 'verbose',
        'h' => 'help',
    ]
);

if ($options['help']) {
    $help = "Count total questions in a course or all courses

Options:
--courseid=           Course ID to count questions from (optional, if not provided counts all courses)
--top=                Show top N courses with highest question count (default: 10)
-v, --verbose         Show detailed information
-h, --help            Print out this help

Examples:
 php local/deleteoldquizattempts/cli/count_questions.php --courseid=5
 php local/deleteoldquizattempts/cli/count_questions.php --courseid=5 --verbose
 php local/deleteoldquizattempts/cli/count_questions.php --verbose (all courses)
 php local/deleteoldquizattempts/cli/count_questions.php --top=10 (top 10 courses)
 php local/deleteoldquizattempts/cli/count_questions.php --top=20 --verbose (top 20 with details)
";
    echo $help;
    exit(0);
}

// Ensure errors are well explained.
set_debugging(DEBUG_DEVELOPER, true);

if ($options['courseid']) {
    // Single course mode
    $courseid = (int)$options['courseid'];
    
    // Verify course exists.
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        cli_error("Course with ID {$courseid} does not exist.");
    }
    
    count_course_questions($courseid, $options['verbose']);
} else if ($options['top'] !== false) {
    // Top courses mode
    $limit = $options['top'] === true ? 10 : (int)$options['top'];
    if ($limit < 1) {
        $limit = 10;
    }
    
    echo "\nFinding top {$limit} courses with highest question count...\n";
    echo str_repeat("=", 80) . "\n\n";
    
    // Get all courses except site course
    $courses = $DB->get_records_select('course', 'id > 1', null, 'id ASC', 'id, fullname, shortname');
    
    $coursedata = [];
    
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id);
        
        // Count questions in this course
        $sql = "SELECT COUNT(DISTINCT q.id)
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = :contextid";
        
        $totalquestions = $DB->count_records_sql($sql, ['contextid' => $coursecontext->id]);
        
        if ($totalquestions > 0) {
            // Count unused questions
            $sqlunused = "SELECT COUNT(DISTINCT q.id)
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = :contextid
                    AND NOT EXISTS (
                        SELECT 1
                        FROM {question_attempts} qa
                        JOIN {question_usages} quba ON qa.questionusageid = quba.id
                        WHERE qa.questionid = q.id
                        AND quba.component <> 'core_question_preview'
                    )";
            
            $unusedquestions = $DB->count_records_sql($sqlunused, ['contextid' => $coursecontext->id]);
            
            // Count duplicates
            $sqlduplicates = "SELECT q.name, COUNT(DISTINCT q.id) as count
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = :contextid
                    GROUP BY q.name
                    HAVING COUNT(DISTINCT q.id) > 1";
            
            $duplicategroups = $DB->get_records_sql($sqlduplicates, ['contextid' => $coursecontext->id]);
            $totalduplicates = 0;
            foreach ($duplicategroups as $group) {
                $totalduplicates += ($group->count - 1);
            }
            
            $coursedata[] = [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'total' => $totalquestions,
                'unused' => $unusedquestions,
                'duplicates' => $totalduplicates
            ];
        }
    }
    
    // Sort by total questions descending
    usort($coursedata, function($a, $b) {
        return $b['total'] - $a['total'];
    });
    
    // Take top N courses
    $topcourses = array_slice($coursedata, 0, $limit);
    
    // Display results
    $rank = 1;
    foreach ($topcourses as $data) {
        echo "#{$rank}. Course ID: {$data['id']}\n";
        echo "    Name: {$data['fullname']}\n";
        if ($options['verbose']) {
            echo "    Short name: {$data['shortname']}\n";
        }
        echo "    Total questions: {$data['total']}\n";
        echo "    Unused questions: {$data['unused']}\n";
        echo "    Duplicate questions: {$data['duplicates']}\n";
        echo "\n";
        $rank++;
    }
    
    echo str_repeat("=", 80) . "\n";
    echo "Showing top {$limit} courses out of " . count($coursedata) . " courses with questions.\n";
    echo "\n";
} else {
    // All courses mode
    echo "\nCounting questions for all courses...\n";
    echo str_repeat("=", 80) . "\n\n";
    
    // Get all courses except site course
    $courses = $DB->get_records_select('course', 'id > 1', null, 'id ASC', 'id, fullname, shortname');
    
    $grandtotal = 0;
    $grandunused = 0;
    $grandduplicates = 0;
    $courseswitquestions = 0;
    
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id);
        
        // Quick check if course has any questions
        $sql = "SELECT COUNT(DISTINCT q.id)
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = :contextid";
        
        $totalquestions = $DB->count_records_sql($sql, ['contextid' => $coursecontext->id]);
        
        if ($totalquestions > 0) {
            $courseswitquestions++;
            
            // Count unused questions
            $sqlunused = "SELECT COUNT(DISTINCT q.id)
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = :contextid
                    AND NOT EXISTS (
                        SELECT 1
                        FROM {question_attempts} qa
                        JOIN {question_usages} quba ON qa.questionusageid = quba.id
                        WHERE qa.questionid = q.id
                        AND quba.component <> 'core_question_preview'
                    )";
            
            $unusedquestions = $DB->count_records_sql($sqlunused, ['contextid' => $coursecontext->id]);
            
            // Count duplicates
            $sqlduplicates = "SELECT q.name, COUNT(DISTINCT q.id) as count
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                    WHERE qc.contextid = :contextid
                    GROUP BY q.name
                    HAVING COUNT(DISTINCT q.id) > 1";
            
            $duplicategroups = $DB->get_records_sql($sqlduplicates, ['contextid' => $coursecontext->id]);
            $totalduplicates = 0;
            foreach ($duplicategroups as $group) {
                $totalduplicates += ($group->count - 1);
            }
            
            echo "Course ID: {$course->id} - {$course->fullname}\n";
            echo "  Total: {$totalquestions} | Unused: {$unusedquestions} | Duplicates: {$totalduplicates}\n\n";
            
            $grandtotal += $totalquestions;
            $grandunused += $unusedquestions;
            $grandduplicates += $totalduplicates;
        }
    }
    
    echo str_repeat("=", 80) . "\n";
    echo "Summary:\n";
    echo "  Courses with questions: {$courseswitquestions}\n";
    echo "  Total questions: {$grandtotal}\n";
    echo "  Total unused questions: {$grandunused}\n";
    echo "  Total duplicate questions: {$grandduplicates}\n";
    echo "\n";
}

exit(0);

/**
 * Count questions for a specific course
 *
 * @param int $courseid Course ID
 * @param bool $verbose Show detailed information
 */
function count_course_questions($courseid, $verbose) {
    global $DB;
    
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    
    // Get course context.
    $coursecontext = context_course::instance($courseid);
    
    // Count all questions in the course context.
    $sql = "SELECT COUNT(DISTINCT q.id)
            FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
            WHERE qc.contextid = :contextid";
    
    $totalquestions = $DB->count_records_sql($sql, ['contextid' => $coursecontext->id]);
    
    echo "\n";
    echo "Course: {$course->fullname} (ID: {$courseid})\n";
    echo "Total questions: {$totalquestions}\n";
    
    $sqlunused = "SELECT COUNT(DISTINCT q.id)
            FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
            WHERE qc.contextid = :contextid
            AND NOT EXISTS (
                SELECT 1
                FROM {question_attempts} qa
                JOIN {question_usages} quba ON qa.questionusageid = quba.id
                WHERE qa.questionid = q.id
                AND quba.component <> 'core_question_preview'
            )";
    
    $unusedquestions = $DB->count_records_sql($sqlunused, ['contextid' => $coursecontext->id]);
    
    echo "Total unused questions: {$unusedquestions}\n";
    
    $sqlduplicates = "SELECT q.name, COUNT(DISTINCT q.id) as count
            FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
            WHERE qc.contextid = :contextid
            GROUP BY q.name
            HAVING COUNT(DISTINCT q.id) > 1";
    
    $duplicategroups = $DB->get_records_sql($sqlduplicates, ['contextid' => $coursecontext->id]);
    $totalduplicates = 0;
    foreach ($duplicategroups as $group) {
        // Count all duplicates except the first one (original)
        $totalduplicates += ($group->count - 1);
    }
    
    echo "Total duplicate questions: {$totalduplicates}\n";
    
    if ($verbose) {
        // Count by question type.
        $sql = "SELECT q.qtype, COUNT(DISTINCT q.id) as count
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = :contextid
                GROUP BY q.qtype
                ORDER BY count DESC";
        
        $questiontypes = $DB->get_records_sql($sql, ['contextid' => $coursecontext->id]);
        
        if (!empty($questiontypes)) {
            echo "\nBreakdown by question type:\n";
            foreach ($questiontypes as $type) {
                echo "  {$type->qtype}: {$type->count}\n";
            }
        }
        
        // Count by status.
        $sql = "SELECT qv.status, COUNT(DISTINCT q.id) as count
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = :contextid
                GROUP BY qv.status
                ORDER BY qv.status";
        
        $questionstatus = $DB->get_records_sql($sql, ['contextid' => $coursecontext->id]);
        
        if (!empty($questionstatus)) {
            echo "\nBreakdown by status:\n";
            foreach ($questionstatus as $status) {
                $statusname = $status->status == 1 ? 'Ready' : 'Hidden';
                echo "  {$statusname}: {$status->count}\n";
            }
        }
        
        // Count questions used in quizzes.
        $sql = "SELECT COUNT(DISTINCT q.id)
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = :contextid
                AND EXISTS (
                    SELECT 1
                    FROM {question_attempts} qa
                    JOIN {question_usages} quba ON qa.questionusageid = quba.id
                    WHERE qa.questionid = q.id
                    AND quba.component <> 'core_question_preview'
                )";
        
        $usedquestions = $DB->count_records_sql($sql, ['contextid' => $coursecontext->id]);
        $unusedquestions = $totalquestions - $usedquestions;
        
        echo "\nUsage statistics:\n";
        echo "  Used in attempts: {$usedquestions}\n";
        echo "  Never used: {$unusedquestions}\n";
    }
    
    echo "\n";
}
