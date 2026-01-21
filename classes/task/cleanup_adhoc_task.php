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
 * Adhoc task for cleanup operations.
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_deleteoldquizattempts\task;

use core\task\adhoc_task;
use local_deleteoldquizattempts\helper;
use moodle_url;
use core_user;
use core\message\message;

/**
 * Adhoc task for cleanup operations.
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_adhoc_task extends adhoc_task {

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleanuptaskname', 'local_deleteoldquizattempts');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;
        
        $data = $this->get_custom_data();
        $courseid = isset($data->courseid) ? $data->courseid : 0;
        $userid = isset($data->userid) ? $data->userid : 0;

        $trace = new \text_progress_trace();
        
        $coursename = 'all courses';
        if ($courseid) {
            $course = $DB->get_record('course', ['id' => $courseid], 'fullname');
            $coursename = $course ? $course->fullname : "course ID: $courseid";
        }
        
        mtrace("Starting cleanup for $coursename");
        $trace->output("Starting cleanup for $coursename");

        $helper = new helper($courseid, true);

        // Step 1: Delete duplicate questions.
        mtrace("Step 1/3: Deleting duplicate questions...");
        $trace->output("\nStep 1/3: Deleting duplicate questions...");
        [$dupdeleted, $dupskipped] = $helper->delete_duplicate_questions(0, $trace);

        // Step 2: Delete empty duplicate categories.
        mtrace("Step 2/3: Deleting empty duplicate categories...");
        $trace->output("\nStep 2/3: Deleting empty duplicate categories...");
        [$empdupdeleted, $empdupskipped] = $helper->delete_empty_duplicate_categories(0, $trace);

        // Step 3: Delete empty categories.
        mtrace("Step 3/3: Deleting empty categories...");
        $trace->output("\nStep 3/3: Deleting empty categories...");
        [$emptydeleted, $emptyskipped] = $helper->delete_empty_categories(0, $trace);

        mtrace("Cleanup completed successfully!");
        $trace->output("\nCleanup completed successfully!");
        
        $summary = [
            'coursename' => $coursename,
            'courseid' => $courseid,
            'duplicate_questions' => ['deleted' => $dupdeleted, 'skipped' => $dupskipped],
            'empty_duplicate_categories' => ['deleted' => $empdupdeleted, 'skipped' => $empdupskipped],
            'empty_categories' => ['deleted' => $emptydeleted, 'skipped' => $emptyskipped],
        ];
        
        if ($userid) {
            $this->send_summary_message($userid, $summary);
        }
        
        $trace->finished();
    }
    
    /**
     * Send summary message to user.
     *
     * @param int $userid User ID
     * @param array $summary Summary data
     */
    protected function send_summary_message($userid, $summary) {
        global $DB;
        
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }
        
        $message = new message();
        $message->component = 'local_deleteoldquizattempts';
        $message->name = 'cleanupcomplete';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('cleanupcompletesubject', 'local_deleteoldquizattempts');
        
        // Build detailed message
        $messagetext = get_string('cleanupcompletefor', 'local_deleteoldquizattempts', $summary['coursename']) . "\n\n";
        
        $messagetext .= get_string('cleanupresults', 'local_deleteoldquizattempts') . "\n";
        $messagetext .= "----------------------------------------\n";
        $messagetext .= get_string('duplicatequestionsresult', 'local_deleteoldquizattempts', $summary['duplicate_questions']) . "\n";
        $messagetext .= get_string('emptyduplicatecategoriesresult', 'local_deleteoldquizattempts', $summary['empty_duplicate_categories']) . "\n";
        $messagetext .= get_string('emptycategoriesresult', 'local_deleteoldquizattempts', $summary['empty_categories']) . "\n";
        $messagetext .= "----------------------------------------\n";
        
        $totaldeleted = $summary['duplicate_questions']['deleted'] + 
                       $summary['empty_duplicate_categories']['deleted'] + 
                       $summary['empty_categories']['deleted'];
        $totalskipped = $summary['duplicate_questions']['skipped'] + 
                       $summary['empty_duplicate_categories']['skipped'] + 
                       $summary['empty_categories']['skipped'];
        
        $messagetext .= "\n" . get_string('totalsummary', 'local_deleteoldquizattempts', [
            'deleted' => $totaldeleted,
            'skipped' => $totalskipped,
        ]);
        
        $message->fullmessage = $messagetext;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<pre>' . htmlspecialchars($messagetext) . '</pre>';
        $message->smallmessage = get_string('cleanupcompletesmall', 'local_deleteoldquizattempts');
        $message->notification = 1;
        
        if ($summary['courseid']) {
            $message->contexturl = new moodle_url('/course/view.php', ['id' => $summary['courseid']]);
            $message->contexturlname = $summary['coursename'];
        }
        
        message_send($message);
    }
}
