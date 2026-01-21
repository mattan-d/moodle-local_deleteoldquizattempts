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
// MERCHANTABILITY FOR A PARTICULAR PURPOSE.  See the
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

namespace local_deleteoldquizattempts;

use core_question\local\bank\question_version_status;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Class with main functions
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2019 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * @var int|null optional quiz id to filter attempts
     */
    public $quizid = null;

    /**
     * @var int|null optional course id to filter attempts
     */
    public $courseid = null;

    /**
     * Deletes quiz attempts older than timestamp
     *
     * @param int $timestamp
     * @param int $stoptime
     * @param \progress_trace|null $trace
     * @return int deleted attempts count
     */
    public function delete_attempts($timestamp, $stoptime = 0, $trace = null) {
        global $DB;

        $where = "timestart < :timestamp";
        $params = ['timestamp' => $timestamp];
        if ($this->courseid) {
            $quizids = $DB->get_fieldset_select('quiz', 'id', 'course = :course', [
                'course' => $this->courseid,
            ]);
            [$quizwhere, $qizparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quiz');
            $where .= ' AND quiz ' . $quizwhere;
            $params = array_merge($params, $qizparams);
        } else if ($this->quizid) {
            $where .= ' AND quiz = :quizid';
            $params = array_merge($params, ['quizid' => $this->quizid]);
        }
        if ($trace) {
            $total = $DB->count_records_select('quiz_attempts', $where, $params);
        } else {
            $total = 0;
        }
        $deleted = 0;
        do {
            $rs = $DB->get_recordset_select('quiz_attempts', $where, $params, '', '*', 0, 10000);
            $rsempty = true;
            foreach ($rs as $attempt) {
                $rsempty = false;
                $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
                quiz_delete_attempt($attempt, $quiz);
                $deleted++;
                if ($trace) {
                    $trace->output(get_string('attemptsprogress', 'local_deleteoldquizattempts', [
                        'deleted' => $deleted,
                        'total' => $total,
                    ]));
                }
                if ($stoptime && (time() >= $stoptime)) {
                    if ($trace) {
                        $trace->output(get_string('maxexecutiontime_reached', 'local_deleteoldquizattempts'));
                    }
                    break 2;
                }
            }
            $rs->close();
        } while (!$rsempty);
        return $deleted;
    }

    /**
     * Deletes unused hidden question versions
     *
     * @param int $stoptime
     * @param \progress_trace|null $trace
     * @return array deleted and skipped questions count
     */
    public function delete_unused_questions($stoptime = 0, $trace = null) {
        global $DB;

        $coursejoin = '';
        $coursewhere = '';
        if ($this->courseid) {
            $coursejoin = "JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid";
            $coursewhere = "AND qc.contextid IN (
                SELECT ctx.id
                FROM {context} ctx
                WHERE ctx.contextlevel = :contextlevel
                AND ctx.instanceid = :courseid
            )";
        }

        $sqlfromwhere = "
            FROM
                {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                $coursejoin
            WHERE
                qv.status = :hidden
                $coursewhere
                AND NOT EXISTS (
                    SELECT 1
                    FROM
                        {question_attempts} qa
                        JOIN {question_usages} quba ON qa.questionusageid = quba.id
                    WHERE
                        qa.questionid = q.id
                        AND quba.component <> 'core_question_preview'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM {question_references} qr
                    WHERE
                        qr.questionbankentryid = qbe.id
                        AND qv.version = (
                            CASE
                                WHEN qr.version IS NOT NULL THEN qr.version
                                ELSE (
                                    SELECT MAX(qv2.version)
                                    FROM {question_versions} qv2
                                    WHERE qv2.questionbankentryid = qbe.id
                                )
                            END
                        )
                )
            ";
        $sql = 'SELECT q.id ' . $sqlfromwhere;
        $sqlcount = 'SELECT count(q.id) ' . $sqlfromwhere;
        $params = [
            'hidden' => question_version_status::QUESTION_STATUS_HIDDEN,
            'preview' => 'core_question_preview',
        ];
        
        if ($this->courseid) {
            $params['contextlevel'] = CONTEXT_COURSE;
            $params['courseid'] = $this->courseid;
        }
        
        $total = $DB->count_records_sql($sqlcount, $params);
        
        if ($trace) {
            $trace->output(get_string('unusedquestionsfound', 'local_deleteoldquizattempts', $total));
        }
        
        if ($total == 0) {
            if ($trace) {
                $trace->output(get_string('nounusedquestions', 'local_deleteoldquizattempts'));
            }
            return [0, 0];
        }
        
        $deleted = 0;
        $skipped = 0;
        
        do {
            $rs = $DB->get_recordset_sql($sql, $params, 0, 10000);
            $rsempty = true;
            foreach ($rs as $question) {
                $rsempty = false;
                question_delete_question($question->id);
                if ($DB->record_exists('question', ['id' => $question->id])) {
                    $skipped++;
                } else {
                    $deleted++;
                }
                if ($trace && (($deleted + $skipped) % 100 == 0)) {
                    $trace->output(get_string('questionsprogress', 'local_deleteoldquizattempts', [
                        'deleted' => $deleted,
                        'skipped' => $skipped,
                        'total' => $total,
                    ]));
                }
                if ($stoptime && (time() >= $stoptime)) {
                    if ($trace) {
                        $trace->output(get_string('maxexecutiontime_reached', 'local_deleteoldquizattempts'));
                    }
                    break 2;
                }
            }
            $rs->close();
        } while (!$rsempty);
        
        if ($trace) {
            $trace->output(get_string('questionsprogress', 'local_deleteoldquizattempts', [
                'deleted' => $deleted,
                'skipped' => $skipped,
                'total' => $total,
            ]));
        }
        
        return [$deleted, $skipped];
    }

    /**
     * Task hander
     */
    public function task_handler() {
        $timelimit = (int)get_config('local_deleteoldquizattempts', 'maxexecutiontime');
        $lifetime = (int)get_config('local_deleteoldquizattempts', 'attemptlifetime');
        $deletequestions = (int)get_config('local_deleteoldquizattempts', 'deleteunusedquestions');

        if ($timelimit) {
            $stoptime = time() + $timelimit;
        } else {
            $stoptime = 0;
        }

        if (!empty($lifetime)) {
            $timestamp = time() - ($lifetime * 3600 * 24);

            $attempts = $this->delete_attempts($timestamp, $stoptime);
            mtrace('    ' . get_string('attemptsdeleted', 'local_deleteoldquizattempts', $attempts));
        }

        if ($stoptime && time() > $stoptime) {
            return;
        }

        if ($deletequestions) {
            [$deleted, $skipped] = $this->delete_unused_questions($stoptime);
            mtrace('    ' . get_string('questionsdeleted', 'local_deleteoldquizattempts', [
                'deleted' => $deleted,
                'skipped' => $skipped,
            ]));
        }
    }

    /**
     * CLI hander for delete_attempts
     *
     * @param array $options
     */
    public function delete_attempts_cli_handler($options) {

        $exclusiveoptions = array_intersect(
            array_keys(array_filter($options)),
            ['days', 'timestamp', 'date']
        );
        $exclusiveoptions2 = array_intersect(
            array_keys(array_filter($options)),
            ['courseid', 'quizid']
        );
        if (!empty($options['help']) || count($exclusiveoptions) != 1 || count($exclusiveoptions2) > 1) {
            $help = "Delete old quiz and question attempts

Options:
--days=               Delete attempts that are older than specified number of days
--timestamp=          Delete attempts that are created before specified UTC timestamp
--date=               Delete attempts that are created before specified date.
                      Use \"YYYY-MM-DD HH:MM:SS\" format in UTC
--courseid=           Delete only attempts for quizzes in course with specified id.
--quizid=             Delete only attempts for quiz with specified id.
--timelimit=          Stop execution after specified number of seconds
-v, --verbose         Show progress
-h, --help            Print out this help

Only one of --days, --timestamp and --date options should be specified.

Examples:
 php local/deleteoldquizattempts/cli/delete_attempts.php --days=90 --verbose
 php local/deleteoldquizattempts/cli/delete_attempts.php --timestamp=1514764800 --timelimit=300
 php local/deleteoldquizattempts/cli/delete_attempts.php --date=\"2018-01-01 00:00:00\"
";
            echo $help;
            return;
        }

        // Ensure errors are well explained.
        set_debugging(DEBUG_DEVELOPER, true);

        if (!empty($options['days'])) {
            $timestamp = time() - ((int)$options['days'] * 3600 * 24);
        } else if (!empty($options['timestamp'])) {
            $timestamp = (int)$options['timestamp'];
        } else if (!empty($options['date'])) {
            $tz = new \DateTimeZone('UTC');
            $date = \DateTime::createFromFormat('Y-m-d H:i:s', $options['date'], $tz);
            $timestamp = $date->getTimestamp();
        }
        if (!empty($options['quizid'])) {
            $this->quizid = $options['quizid'];
        } else if (!empty($options['courseid'])) {
            $this->courseid = $options['courseid'];
        }
        if (!empty($options['verbose'])) {
            /** @var \text_progress_trace $trace */
            $trace = new \text_progress_trace();
        } else {
            $trace = null;
        }

        if (!empty($options['timelimit'])) {
            $stoptime = time() + (int)$options['timelimit'];
        } else {
            $stoptime = 0;
        }

        $this->delete_attempts($timestamp, $stoptime, $trace);

        if ($trace) {
            $trace->finished();
        }
    }

    /**
     * CLI hander for delete_unused_questions
     *
     * @param array $options
     */
    public function delete_questions_cli_handler($options) {
        if ($options['help']) {
            $help = "Delete unused hidden questions

Options:
--courseid=           Delete only unused questions from course with specified id
--timelimit=          Stop execution after specified number of seconds
-v, --verbose         Show progress
-h, --help            Print out this help

Examples:
 php local/deleteoldquizattempts/cli/delete_unused_questions.php --timelimit=300 --verbose
 php local/deleteoldquizattempts/cli/delete_unused_questions.php --courseid=5 --verbose
";
            echo $help;
            return;
        }

        // Ensure errors are well explained.
        set_debugging(DEBUG_DEVELOPER, true);

        if (!empty($options['courseid'])) {
            $this->courseid = (int)$options['courseid'];
        }

        if ($options['verbose']) {
            /** @var \text_progress_trace $trace */
            $trace = new \text_progress_trace();
        } else {
            $trace = null;
        }

        if ($options['timelimit']) {
            $stoptime = time() + (int)$options['timelimit'];
        } else {
            $stoptime = 0;
        }

        $this->delete_unused_questions($stoptime, $trace);

        if ($trace) {
            $trace->finished();
        }
    }

    /**
     * Deletes duplicate questions, keeping only the oldest version
     *
     * @param int $stoptime
     * @param \progress_trace|null $trace
     * @return array deleted and skipped questions count
     */
    public function delete_duplicate_questions($stoptime = 0, $trace = null) {
        global $DB;

        if (!$this->courseid) {
            // Process all courses
            $courses = $DB->get_records('course', null, '', 'id');
            $totaldeleted = 0;
            $totalskipped = 0;
            
            foreach ($courses as $course) {
                if ($course->id == SITEID) {
                    continue; // Skip site course
                }
                
                $this->courseid = $course->id;
                
                if ($trace) {
                    $courseobj = $DB->get_record('course', ['id' => $course->id], 'id, fullname');
                    $trace->output("\n" . get_string('processingcourse', 'local_deleteoldquizattempts', [
                        'name' => $courseobj->fullname,
                        'id' => $courseobj->id,
                    ]));
                }
                
                [$deleted, $skipped] = $this->delete_duplicate_questions_for_course($stoptime, $trace);
                $totaldeleted += $deleted;
                $totalskipped += $skipped;
                
                if ($stoptime && (time() >= $stoptime)) {
                    break;
                }
            }
            
            if ($trace) {
                $trace->output("\n" . get_string('duplicatequestionsfinal', 'local_deleteoldquizattempts', [
                    'deleted' => $totaldeleted,
                    'skipped' => $totalskipped,
                ]));
            }
            
            $this->courseid = null;
            return [$totaldeleted, $totalskipped];
        }
        
        return $this->delete_duplicate_questions_for_course($stoptime, $trace);
    }
    
    protected function delete_duplicate_questions_for_course($stoptime = 0, $trace = null) {
        global $DB;

        // Get all questions in the course context grouped by name
        $sql = "
            SELECT 
                q.name,
                COUNT(DISTINCT q.id) as count
            FROM
                {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
            WHERE
                qc.contextid IN (
                    SELECT ctx.id
                    FROM {context} ctx
                    WHERE ctx.contextlevel = :contextlevel
                    AND ctx.instanceid = :courseid
                )
            GROUP BY q.name
            HAVING COUNT(DISTINCT q.id) > 1
        ";
        
        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'courseid' => $this->courseid,
        ];
        
        $duplicategroups = $DB->get_records_sql($sql, $params);
        
        // Calculate total duplicates to delete
        $totalduplicates = 0;
        foreach ($duplicategroups as $group) {
            $totalduplicates += ($group->count - 1); // Keep one, delete the rest
        }
        
        if ($trace) {
            $trace->output(get_string('duplicatequestionsfound', 'local_deleteoldquizattempts', $totalduplicates));
        }
        
        if ($totalduplicates == 0) {
            if ($trace) {
                $trace->output(get_string('noduplicatequestions', 'local_deleteoldquizattempts'));
            }
            return [0, 0];
        }
        
        $deleted = 0;
        $skipped = 0;
        
        // Process each group of duplicates
        foreach ($duplicategroups as $group) {
            // Get all questions with this name, ordered by creation time (oldest first)
            $sql = "
                SELECT 
                    q.id,
                    q.name,
                    q.timecreated
                FROM
                    {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE
                    qc.contextid IN (
                        SELECT ctx.id
                        FROM {context} ctx
                        WHERE ctx.contextlevel = :contextlevel
                        AND ctx.instanceid = :courseid
                    )
                    AND q.name = :name
                ORDER BY q.timecreated ASC, q.id ASC
            ";
            
            $questions = $DB->get_records_sql($sql, [
                'contextlevel' => CONTEXT_COURSE,
                'courseid' => $this->courseid,
                'name' => $group->name,
            ]);
            
            // Skip the first one (oldest), delete the rest
            $first = true;
            foreach ($questions as $question) {
                if ($first) {
                    $first = false;
                    continue; // Keep the oldest question
                }
                
                // Check if question is used in any attempts and get usage details
                $usageinfo = $DB->get_record_sql("
                    SELECT quba.component, quba.contextid, ctx.instanceid as cmid
                    FROM {question_attempts} qa
                    JOIN {question_usages} quba ON qa.questionusageid = quba.id
                    LEFT JOIN {context} ctx ON ctx.id = quba.contextid AND ctx.contextlevel = :contextmodule
                    WHERE qa.questionid = :questionid
                    AND quba.component <> :preview
                    LIMIT 1
                ", [
                    'questionid' => $question->id,
                    'preview' => 'core_question_preview',
                    'contextmodule' => CONTEXT_MODULE,
                ]);
                
                if ($usageinfo) {
                    $skipped++;
                    if ($trace) {
                        $activityinfo = '';
                        if ($usageinfo->cmid) {
                            // Get the course module and activity details
                            $cm = $DB->get_record('course_modules', ['id' => $usageinfo->cmid], 'course, instance, module');
                            if ($cm) {
                                $module = $DB->get_record('modules', ['id' => $cm->module], 'name');
                                $activityname = '';
                                
                                if ($module && $cm->instance) {
                                    $activity = $DB->get_record($module->name, ['id' => $cm->instance], 'name');
                                    if ($activity) {
                                        $activityname = $activity->name;
                                    }
                                }
                                
                                $activityinfo = get_string('duplicatequestionusageinfo', 'local_deleteoldquizattempts', [
                                    'component' => $usageinfo->component,
                                    'activity' => $activityname ? $activityname : 'Unknown',
                                    'courseid' => $cm->course,
                                    'cmid' => $usageinfo->cmid,
                                ]);
                            }
                        }
                        
                        $trace->output(get_string('duplicatequestionskippedusedinquiz', 'local_deleteoldquizattempts', [
                            'name' => $question->name,
                            'id' => $question->id,
                            'info' => $activityinfo,
                        ]));
                    }
                } else {
                    // Try to delete the question.
                    try {
                        question_delete_question($question->id);
                        
                        // Verify deletion was successful.
                        if ($DB->record_exists('question', ['id' => $question->id])) {
                            $skipped++;
                            if ($trace) {
                                $trace->output(get_string('duplicatequestionskippeddeletionfailed', 'local_deleteoldquizattempts', [
                                    'name' => $question->name,
                                    'id' => $question->id,
                                    'reason' => 'Question still exists after deletion attempt',
                                ]));
                            }
                        } else {
                            $deleted++;
                            if ($trace) {
                                $trace->output(get_string('duplicatequestiondeleted', 'local_deleteoldquizattempts', [
                                    'name' => $question->name,
                                    'id' => $question->id,
                                ]));
                            }
                        }
                    } catch (\Exception $e) {
                        $skipped++;
                        if ($trace) {
                            $trace->output(get_string('duplicatequestionskippederror', 'local_deleteoldquizattempts', [
                                'name' => $question->name,
                                'id' => $question->id,
                                'error' => $e->getMessage(),
                            ]));
                        }
                    }
                }
                
                if ($trace && (($deleted + $skipped) % 100 == 0)) {
                    $trace->output(get_string('duplicatequestionsprogress', 'local_deleteoldquizattempts', [
                        'deleted' => $deleted,
                        'skipped' => $skipped,
                        'total' => $totalduplicates,
                    ]));
                }
                
                if ($stoptime && (time() >= $stoptime)) {
                    if ($trace) {
                        $trace->output(get_string('maxexecutiontime_reached', 'local_deleteoldquizattempts'));
                    }
                    break 2;
                }
            }
        }
        
        if ($trace) {
            $trace->output(get_string('duplicatequestionsprogress', 'local_deleteoldquizattempts', [
                'deleted' => $deleted,
                'skipped' => $skipped,
                'total' => $totalduplicates,
            ]));
        }
        
        return [$deleted, $skipped];
    }

    /**
     * Deletes duplicate empty question categories, keeping only the oldest version
     *
     * @param int $stoptime
     * @param \progress_trace|null $trace
     * @return array deleted and skipped categories count
     */
    public function delete_empty_duplicate_categories($stoptime = 0, $trace = null) {
        global $DB;

        $coursejoin = '';
        $coursewhere = '';
        $params = [];
        
        if ($this->courseid) {
            // Get course context
            $coursecontext = \context_course::instance($this->courseid);
            $coursewhere = "WHERE qc.contextid = :contextid";
            $params['contextid'] = $coursecontext->id;
        }

        // Get all categories grouped by name with duplicates
        $sql = "
            SELECT 
                qc.name,
                COUNT(DISTINCT qc.id) as count" . ($this->courseid ? "" : ",
                MIN(qc.contextid) as contextid") . "
            FROM
                {question_categories} qc
            $coursewhere
            GROUP BY qc.name" . ($this->courseid ? "" : ", qc.contextid") . "
            HAVING COUNT(DISTINCT qc.id) > 1
        ";
        
        $duplicategroups = $DB->get_records_sql($sql, $params);
        
        // Calculate total duplicates to delete
        $totalduplicates = 0;
        foreach ($duplicategroups as $group) {
            $totalduplicates += ($group->count - 1); // Keep one, delete the rest
        }
        
        if ($trace) {
            $trace->output(get_string('duplicatecategoriesfound', 'local_deleteoldquizattempts', $totalduplicates));
        }
        
        if ($totalduplicates == 0) {
            if ($trace) {
                $trace->output(get_string('noduplicatecategories', 'local_deleteoldquizattempts'));
            }
            return [0, 0];
        }
        
        $deleted = 0;
        $skipped = 0;
        
        // Process each group of duplicates
        foreach ($duplicategroups as $group) {
            $contextid = $this->courseid ? $params['contextid'] : $group->contextid;
            
            // Get all categories with this name, ordered by creation time (oldest first)
            $sql = "
                SELECT 
                    qc.id,
                    qc.name,
                    qc.stamp
                FROM
                    {question_categories} qc
                WHERE
                    qc.contextid = :contextid
                    AND qc.name = :name
                ORDER BY qc.stamp ASC, qc.id ASC
            ";
            
            $categories = $DB->get_records_sql($sql, [
                'contextid' => $contextid,
                'name' => $group->name,
            ]);
            
            // Skip the first one (oldest), delete the rest if empty
            $first = true;
            foreach ($categories as $category) {
                if ($first) {
                    $first = false;
                    continue; // Keep the oldest category
                }
                
                // Check if category has any questions
                $questioncount = $DB->count_records_sql("
                    SELECT COUNT(DISTINCT q.id)
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    WHERE qbe.questioncategoryid = :categoryid
                ", [
                    'categoryid' => $category->id,
                ]);
                
                if ($questioncount > 0) {
                    $skipped++;
                    if ($trace) {
                        $trace->output(get_string('duplicatecategoryskipped', 'local_deleteoldquizattempts', [
                            'name' => $category->name,
                            'id' => $category->id,
                            'count' => $questioncount,
                        ]));
                    }
                } else {
                    // Category is empty, safe to delete
                    try {
                        $DB->delete_records('question_categories', ['id' => $category->id]);
                        $deleted++;
                        if ($trace) {
                            $trace->output(get_string('duplicatecategorydeleted', 'local_deleteoldquizattempts', [
                                'name' => $category->name,
                                'id' => $category->id,
                            ]));
                        }
                    } catch (\Exception $e) {
                        $skipped++;
                        if ($trace) {
                            $trace->output(get_string('duplicatecategorydeletefailed', 'local_deleteoldquizattempts', [
                                'name' => $category->name,
                                'id' => $category->id,
                                'error' => $e->getMessage(),
                            ]));
                        }
                    }
                }
                
                if ($trace && (($deleted + $skipped) % 100 == 0)) {
                    $trace->output(get_string('duplicatecategoriesprogress', 'local_deleteoldquizattempts', [
                        'deleted' => $deleted,
                        'skipped' => $skipped,
                        'total' => $totalduplicates,
                    ]));
                }
                
                if ($stoptime && (time() >= $stoptime)) {
                    if ($trace) {
                        $trace->output(get_string('maxexecutiontime_reached', 'local_deleteoldquizattempts'));
                    }
                    break 2;
                }
            }
        }
        
        if ($trace) {
            $trace->output(get_string('duplicatecategoriesprogress', 'local_deleteoldquizattempts', [
                'deleted' => $deleted,
                'skipped' => $skipped,
                'total' => $totalduplicates,
            ]));
        }
        
        return [$deleted, $skipped];
    }

    /**
     * Deletes all empty question categories
     *
     * @param \progress_trace|null $trace
     * @return array deleted and skipped categories count
     */
    public function delete_empty_categories($trace = null) {
        global $DB;

        $coursejoin = '';
        $coursewhere = '';
        $params = [];
        
        if ($this->courseid) {
            // Get course context
            $coursecontext = \context_course::instance($this->courseid);
            $coursewhere = "WHERE qc.contextid = :contextid";
            $params['contextid'] = $coursecontext->id;
        }

        // Get all categories
        $sql = "
            SELECT 
                qc.id,
                qc.name,
                qc.contextid
            FROM
                {question_categories} qc
            $coursewhere
            ORDER BY qc.id ASC
        ";
        
        $categories = $DB->get_records_sql($sql, $params);
        
        if ($trace) {
            $trace->output(get_string('scanningemptycategories', 'local_deleteoldquizattempts', count($categories)));
        }
        
        $deleted = 0;
        $skipped = 0;
        
        foreach ($categories as $category) {
            // Skip the default "top" category for each context
            if ($category->name === 'top') {
                $skipped++;
                continue;
            }
            
            // Check if category has any questions
            $questioncount = $DB->count_records_sql("
                SELECT COUNT(DISTINCT q.id)
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                WHERE qbe.questioncategoryid = :categoryid
            ", [
                'categoryid' => $category->id,
            ]);
            
            if ($questioncount > 0) {
                $skipped++;
                if ($trace) {
                    $trace->output(get_string('categoryskipped', 'local_deleteoldquizattempts', [
                        'name' => $category->name,
                        'id' => $category->id,
                        'count' => $questioncount,
                    ]));
                }
            } else {
                // Category is empty, safe to delete
                try {
                    $DB->delete_records('question_categories', ['id' => $category->id]);
                    $deleted++;
                    if ($trace) {
                        $trace->output(get_string('categorydeleted', 'local_deleteoldquizattempts', [
                            'name' => $category->name,
                            'id' => $category->id,
                        ]));
                    }
                } catch (\Exception $e) {
                    $skipped++;
                    if ($trace) {
                        $trace->output(get_string('categorydeletefailed', 'local_deleteoldquizattempts', [
                            'name' => $category->name,
                            'id' => $category->id,
                            'error' => $e->getMessage(),
                        ]));
                    }
                }
            }
            
            if ($trace && (($deleted + $skipped) % 100 == 0)) {
                $trace->output(get_string('categoriesprogress', 'local_deleteoldquizattempts', [
                    'deleted' => $deleted,
                    'skipped' => $skipped,
                ]));
            }
        }
        
        if ($trace) {
            $trace->output(get_string('emptycategoriesdeleted', 'local_deleteoldquizattempts', [
                'deleted' => $deleted,
                'skipped' => $skipped,
            ]));
        }
        
        return [$deleted, $skipped];
    }

    /**
     * Fix or delete questions with missing options
     *
     * @param string $action 'fix', 'delete', or 'report'
     * @param \progress_trace|null $trace
     * @return array fixed and failed questions count
     */
    public function fix_missing_question_options($action = 'report', $trace = null) {
        global $DB;

        $coursejoin = '';
        $coursewhere = '';
        if ($this->courseid) {
            $coursejoin = "JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid";
            $coursewhere = "AND qc.contextid IN (
                SELECT ctx.id
                FROM {context} ctx
                WHERE ctx.contextlevel = :contextlevel
                AND ctx.instanceid = :courseid
            )";
        }

        if ($trace) {
            $trace->output(get_string('scanningbrokenquestions', 'local_deleteoldquizattempts'));
        }

        $fixed = 0;
        $failed = 0;

        // Check different question types for missing options
        $questiontypes = [
            'multichoice' => 'qtype_multichoice_options',
            'truefalse' => 'qtype_truefalse_options',
            'shortanswer' => 'qtype_shortanswer_options',
            'numerical' => 'qtype_numerical_options',
            'essay' => 'qtype_essay_options',
            'match' => 'qtype_match_options',
            'calculated' => 'qtype_calculated_options',
        ];

        foreach ($questiontypes as $qtype => $optionstable) {
            // Check if the options table exists
            if (!$DB->get_manager()->table_exists($optionstable)) {
                continue;
            }

            // Find questions of this type without options
            $sql = "
                SELECT q.id, q.name, q.qtype, qbe.questioncategoryid
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                $coursejoin
                WHERE q.qtype = :qtype
                $coursewhere
                AND NOT EXISTS (
                    SELECT 1
                    FROM {{$optionstable}} qo
                    WHERE qo.questionid = q.id
                )
            ";

            $params = ['qtype' => $qtype];
            if ($this->courseid) {
                $params['contextlevel'] = CONTEXT_COURSE;
                $params['courseid'] = $this->courseid;
            }

            $brokenquestions = $DB->get_records_sql($sql, $params);

            foreach ($brokenquestions as $question) {
                if ($action === 'report') {
                    $failed++;
                    if ($trace) {
                        $trace->output(get_string('brokenquestionfound', 'local_deleteoldquizattempts', [
                            'id' => $question->id,
                            'name' => $question->name,
                            'type' => $question->qtype,
                        ]));
                    }
                    continue;
                }
                
                if ($trace) {
                    $trace->output(get_string('brokenquestionfound', 'local_deleteoldquizattempts', [
                        'id' => $question->id,
                        'name' => $question->name,
                        'type' => $question->qtype,
                    ]));
                }

                if ($action === 'delete') {
                    // Delete the broken question
                    try {
                        question_delete_question($question->id);
                        if (!$DB->record_exists('question', ['id' => $question->id])) {
                            $fixed++;
                            if ($trace) {
                                $trace->output(get_string('brokenquestiondeleted', 'local_deleteoldquizattempts', [
                                    'id' => $question->id,
                                    'name' => $question->name,
                                ]));
                            }
                        } else {
                            $failed++;
                            if ($trace) {
                                $trace->output(get_string('brokenquestiondeletefailed', 'local_deleteoldquizattempts', [
                                    'id' => $question->id,
                                    'name' => $question->name,
                                ]));
                            }
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        if ($trace) {
                            $trace->output(get_string('brokenquestiondeletefailed', 'local_deleteoldquizattempts', [
                                'id' => $question->id,
                                'name' => $question->name,
                            ]) . ' - ' . $e->getMessage());
                        }
                    }
                } else if ($action === 'fix') {
                    // Try to fix by adding default options
                    try {
                        $result = $this->add_default_question_options($question);
                        if ($result) {
                            $fixed++;
                            if ($trace) {
                                $trace->output(get_string('brokenquestionfixed', 'local_deleteoldquizattempts', [
                                    'id' => $question->id,
                                    'name' => $question->name,
                                ]));
                            }
                        } else {
                            $failed++;
                            if ($trace) {
                                $trace->output(get_string('brokenquestionfixfailed', 'local_deleteoldquizattempts', [
                                    'id' => $question->id,
                                    'name' => $question->name,
                                ]));
                            }
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        if ($trace) {
                            $trace->output(get_string('brokenquestionfixfailed', 'local_deleteoldquizattempts', [
                                'id' => $question->id,
                                'name' => $question->name,
                            ]) . ' - ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        // Also check for questions with missing answers
        $sql = "
            SELECT q.id, q.name, q.qtype, qbe.questioncategoryid
            FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            $coursejoin
            WHERE q.qtype IN ('multichoice', 'truefalse', 'shortanswer', 'numerical', 'essay', 'match', 'ordering')
            $coursewhere
            AND NOT EXISTS (
                SELECT 1
                FROM {question_answers} qa
                WHERE qa.question = q.id
            )
        ";

        $params = [];
        if ($this->courseid) {
            $params['contextlevel'] = CONTEXT_COURSE;
            $params['courseid'] = $this->courseid;
        }

        $brokenquestions = $DB->get_records_sql($sql, $params);

        foreach ($brokenquestions as $question) {
            if ($action === 'report') {
                $failed++;
                if ($trace) {
                    $trace->output(get_string('brokenquestionnoanswersfound', 'local_deleteoldquizattempts', [
                        'id' => $question->id,
                        'name' => $question->name,
                        'type' => $question->qtype,
                    ]));
                }
                continue;
            }
            
            if ($trace) {
                $trace->output(get_string('brokenquestionnoanswersfound', 'local_deleteoldquizattempts', [
                    'id' => $question->id,
                    'name' => $question->name,
                    'type' => $question->qtype,
                ]));
            }

            if ($action === 'delete') {
                try {
                    question_delete_question($question->id);
                    if (!$DB->record_exists('question', ['id' => $question->id])) {
                        $fixed++;
                        if ($trace) {
                            $trace->output(get_string('brokenquestiondeleted', 'local_deleteoldquizattempts', [
                                'id' => $question->id,
                                'name' => $question->name,
                            ]));
                        }
                    } else {
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    if ($trace) {
                        $trace->output(get_string('brokenquestiondeletefailed', 'local_deleteoldquizattempts', [
                            'id' => $question->id,
                            'name' => $question->name,
                        ]) . ' - ' . $e->getMessage());
                    }
                }
            } else {
                // Cannot easily fix questions without answers, recommend deletion
                $failed++;
                if ($trace) {
                    $trace->output(get_string('brokenquestioncannotfix', 'local_deleteoldquizattempts', [
                        'id' => $question->id,
                        'name' => $question->name,
                    ]));
                }
            }
        }

        return [$fixed, $failed];
    }

    /**
     * Add default options to a question
     *
     * @param \stdClass $question
     * @return bool success
     */
    private function add_default_question_options($question) {
        global $DB;

        $now = time();

        switch ($question->qtype) {
            case 'multichoice':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->single = 1;
                $options->shuffleanswers = 1;
                $options->correctfeedback = '';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->answernumbering = 'abc';
                $options->shownumcorrect = 0;
                $DB->insert_record('qtype_multichoice_options', $options);
                return true;

            case 'truefalse':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->trueanswer = 0;
                $options->falseanswer = 0;
                $DB->insert_record('qtype_truefalse_options', $options);
                return true;

            case 'shortanswer':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->usecase = 0;
                $DB->insert_record('qtype_shortanswer_options', $options);
                return true;

            case 'numerical':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->showunits = 0;
                $options->unitsleft = 0;
                $options->unitgradingtype = 0;
                $options->unitpenalty = 0;
                $DB->insert_record('qtype_numerical_options', $options);
                return true;

            case 'essay':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->responseformat = 'editor';
                $options->responserequired = 1;
                $options->responsefieldlines = 15;
                $options->attachments = 0;
                $options->attachmentsrequired = 0;
                $options->graderinfo = '';
                $options->graderinfoformat = FORMAT_HTML;
                $options->responsetemplate = '';
                $options->responsetemplateformat = FORMAT_HTML;
                $DB->insert_record('qtype_essay_options', $options);
                return true;

            case 'match':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->shuffleanswers = 1;
                $options->correctfeedback = '';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->shownumcorrect = 0;
                $DB->insert_record('qtype_match_options', $options);
                return true;

            case 'calculated':
                $options = new \stdClass();
                $options->questionid = $question->id;
                $options->synchronize = 0;
                $options->single = 0;
                $options->shuffleanswers = 1;
                $options->correctfeedback = '';
                $options->correctfeedbackformat = FORMAT_HTML;
                $options->partiallycorrectfeedback = '';
                $options->partiallycorrectfeedbackformat = FORMAT_HTML;
                $options->incorrectfeedback = '';
                $options->incorrectfeedbackformat = FORMAT_HTML;
                $options->answernumbering = 'abc';
                $options->shownumcorrect = 0;
                $DB->insert_record('qtype_calculated_options', $options);
                return true;

            default:
                return false;
        }
    }

    /**
     * Get question statistics for a course
     *
     * @param int $courseid Course ID
     * @return object Statistics object with total, unused, and duplicate counts
     */
    public static function get_course_statistics($courseid) {
        global $DB;
        
        $coursecontext = \context_course::instance($courseid);
        
        // Count all questions in the course context.
        $sql = "SELECT COUNT(DISTINCT q.id)
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                WHERE qc.contextid = :contextid";
        
        $totalquestions = $DB->count_records_sql($sql, ['contextid' => $coursecontext->id]);
        
        // Count unused questions.
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
        
        // Count duplicates.
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
        
        // Count empty categories.
        $sqlcategories = "SELECT qc.id, qc.name
                FROM {question_categories} qc
                WHERE qc.contextid = :contextid
                AND qc.name <> 'top'";
        
        $categories = $DB->get_records_sql($sqlcategories, ['contextid' => $coursecontext->id]);
        $emptycategories = 0;
        
        foreach ($categories as $category) {
            $sqlcount = "SELECT COUNT(DISTINCT q.id)
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    WHERE qbe.questioncategoryid = :categoryid";
            
            $questioncount = $DB->count_records_sql($sqlcount, ['categoryid' => $category->id]);
            if ($questioncount == 0) {
                $emptycategories++;
            }
        }
        
        // Count duplicate empty categories.
        $sqldupcategories = "SELECT qc.name, COUNT(qc.id) as count
                FROM {question_categories} qc
                WHERE qc.contextid = :contextid
                AND qc.name <> 'top'
                GROUP BY qc.name
                HAVING COUNT(qc.id) > 1";
        
        $dupcategorygroups = $DB->get_records_sql($sqldupcategories, ['contextid' => $coursecontext->id]);
        $emptyduplicatecategories = 0;
        
        foreach ($dupcategorygroups as $group) {
            $sqlcatids = "SELECT id FROM {question_categories}
                    WHERE contextid = :contextid AND name = :name
                    ORDER BY id ASC";
            $dupcategories = $DB->get_records_sql($sqlcatids, [
                'contextid' => $coursecontext->id,
                'name' => $group->name
            ]);
            
            $first = true;
            foreach ($dupcategories as $category) {
                if ($first) {
                    $first = false;
                    continue;
                }
                
                $sqlcount = "SELECT COUNT(DISTINCT q.id)
                        FROM {question} q
                        JOIN {question_versions} qv ON qv.questionid = q.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                        WHERE qbe.questioncategoryid = :categoryid";
                
                $questioncount = $DB->count_records_sql($sqlcount, ['categoryid' => $category->id]);
                if ($questioncount == 0) {
                    $emptyduplicatecategories++;
                }
            }
        }
        
        return (object)[
            'total' => $totalquestions,
            'unused' => $unusedquestions,
            'duplicates' => $totalduplicates,
            'emptycategories' => $emptycategories,
            'emptyduplicatecategories' => $emptyduplicatecategories,
        ];
    }

}
