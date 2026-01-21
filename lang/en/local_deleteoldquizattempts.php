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

$string['attemptlifetime'] = 'Delete attempts older than';
$string['attemptlifetime_help'] = 'Quiz аttempts that are older than specified value will be deleted with scheduler task. If "Do not delete old attempts" value is selected, you can still delete atttempts with CLI command.';
$string['attemptsdeleted'] = 'Deleted {$a} quiz attempts.';
$string['attemptsprogress'] = 'Deleted {$a->deleted} of {$a->total}';
$string['deleteunusedhiddenquestions'] = 'Delete unused hidden questions';
$string['deleteunusedhiddenquestions_help'] = 'Hidden questions are questions, that were logically deleted, but were not deleted physically, because they were referenced in some quiz attempts. After quiz attempts deletion, such questions are probably no longer required.';
$string['donotdeleteonschedule'] = 'Do not delete old attempts';
$string['maxexecutiontime'] = 'Max execution time';
$string['maxexecutiontime_help'] = 'Deleting old attempts can cause high server load. This parameter limits the maximum execution time of scheduler task.';
$string['maxexecutiontime_reached'] = 'Operation stopped due to time limit';
$string['notlimited'] = 'Not limited';
$string['nounusedquestions'] = 'No unused hidden questions found.';
$string['unusedquestionsfound'] = 'Found {$a} unused hidden questions to delete.';
$string['pluginname'] = 'Old quiz and question attempts deletion';
$string['privacy:metadata'] = 'The plugin does not store any personal data.';
$string['questionsdeleted'] = 'Deleted {$a->deleted}, skipped {$a->skipped} unused hidden questions.';
$string['questionsprogress'] = 'Deleted {$a->deleted}, skipped {$a->skipped} of {$a->total}';
$string['taskname'] = 'Old quiz and question attempts deletion';

$string['duplicatequestionsfound'] = 'Found {$a} duplicate questions to delete.';
$string['noduplicatequestions'] = 'No duplicate questions found.';
$string['duplicatequestiondeleted'] = 'Deleted duplicate question: {$a->name} (ID: {$a->id})';
$string['duplicatequestionskippedusedinquiz'] = 'Skipped duplicate question (used in quiz attempts): {$a->name} (ID: {$a->id}){$a->info}';
$string['duplicatequestionusageinfo'] = ' - Used in {$a->component}: "{$a->activity}" (Course ID: {$a->courseid}, CM ID: {$a->cmid})';
$string['duplicatequestionskippeddeletionfailed'] = 'Skipped duplicate question (deletion failed - {$a->reason}): {$a->name} (ID: {$a->id})';
$string['duplicatequestionskippederror'] = 'Skipped duplicate question (error: {$a->error}): {$a->name} (ID: {$a->id})';
$string['duplicatequestionsprogress'] = 'Deleted {$a->deleted}, skipped {$a->skipped} of {$a->total}';
$string['processingcourse'] = 'Processing course: {$a->name} (ID: {$a->id})';
$string['duplicatequestionsfinal'] = 'Final summary: Deleted {$a->deleted} duplicate questions, skipped {$a->skipped}.';
$string['courseiderror'] = 'Error: Course ID is required for this operation.';

$string['duplicatecategoriesfound'] = 'Found {$a} duplicate categories to check.';
$string['noduplicatecategories'] = 'No duplicate categories found.';
$string['duplicatecategorydeleted'] = 'Deleted empty duplicate category: {$a->name} (ID: {$a->id})';
$string['duplicatecategoryskipped'] = 'Skipped duplicate category (has {$a->count} questions): {$a->name} (ID: {$a->id})';
$string['duplicatecategorydeletefailed'] = 'Failed to delete category: {$a->name} (ID: {$a->id}) - {$a->error}';
$string['duplicatecategoriesprogress'] = 'Deleted {$a->deleted}, skipped {$a->skipped} of {$a->total}';
$string['emptycategoriesdeleted'] = 'Deleted {$a->deleted} empty duplicate categories, skipped {$a->skipped}.';

$string['scanningbrokenquestions'] = 'Scanning for questions with missing options...';
$string['brokenquestionfound'] = 'Found broken question: {$a->name} (ID: {$a->id}, Type: {$a->type})';
$string['brokenquestionnoanswersfound'] = 'Found question without answers: {$a->name} (ID: {$a->id}, Type: {$a->type})';
$string['brokenquestiondeleted'] = 'Deleted broken question: {$a->name} (ID: {$a->id})';
$string['brokenquestiondeletefailed'] = 'Failed to delete broken question: {$a->name} (ID: {$a->id})';
$string['brokenquestionfixed'] = 'Fixed broken question: {$a->name} (ID: {$a->id})';
$string['brokenquestionfixfailed'] = 'Failed to fix broken question: {$a->name} (ID: {$a->id})';
$string['brokenquestioncannotfix'] = 'Cannot fix question without answers (recommend deletion): {$a->name} (ID: {$a->id})';
$string['missingoptionssummary'] = 'Operation complete ({$a->action}): Found/Fixed/Deleted: {$a->fixed}, Issues: {$a->failed}';

$string['scanningemptycategories'] = 'Scanning {$a} categories for empty ones...';
$string['categorydeleted'] = 'Deleted empty category: {$a->name} (ID: {$a->id})';
$string['categoryskipped'] = 'Skipped category (has {$a->count} questions): {$a->name} (ID: {$a->id})';
$string['categorydeletefailed'] = 'Failed to delete category: {$a->name} (ID: {$a->id}) - {$a->error}';
$string['categoriesprogress'] = 'Processed: Deleted {$a->deleted}, Skipped {$a->skipped}';
$string['emptycategoriesdeleted'] = 'Deleted {$a->deleted} empty categories, skipped {$a->skipped}.';

$string['cleanupquestions'] = 'Cleanup Questions';
$string['cleanupallcourses'] = 'Cleanup All Courses';
$string['cleanuptaskname'] = 'Question cleanup adhoc task';
$string['cleanuptaskqueued'] = 'Cleanup task has been queued and will run shortly. You will receive a notification when it completes.';
$string['confirmcleanup'] = 'Are you sure you want to run cleanup operations for course: {$a}?';
$string['confirmcleanupall'] = 'Are you sure you want to run cleanup operations for ALL courses?';
$string['cleanupwillremove'] = 'This will remove:';
$string['duplicatequestions'] = 'Duplicate questions (keeps oldest version)';
$string['emptyduplicatecategories'] = 'Empty duplicate categories';
$string['emptycategories'] = 'Empty categories';
$string['unusedquestions'] = 'Unused questions';

$string['taskrunning'] = 'A cleanup task is currently running for this course.';
$string['taskqueued'] = 'A cleanup task is queued and will run at: {$a}';
$string['taskalreadyrunning'] = 'Cannot queue new task - a cleanup task is already running or queued for this course.';
$string['taskstatusinfo'] = 'Please wait for the current task to complete before starting a new one.';
$string['cannotqueuewhilerunning'] = 'You cannot queue a new cleanup task while one is already running or queued for this course.';

$string['cleanupnotification'] = 'You will receive a notification message with the cleanup summary when the task completes.';
$string['cleanupcompletesubject'] = 'Question Cleanup Completed';
$string['cleanupcompletefor'] = 'Cleanup completed for: {$a}';
$string['cleanupresults'] = 'Cleanup Results:';
$string['duplicatequestionsresult'] = 'Duplicate Questions: Deleted {$a->deleted}, Skipped {$a->skipped}';
$string['emptyduplicatecategoriesresult'] = 'Empty Duplicate Categories: Deleted {$a->deleted}, Skipped {$a->skipped}';
$string['emptycategoriesresult'] = 'Empty Categories: Deleted {$a->deleted}, Skipped {$a->skipped}';
$string['unusedquestionsresult'] = 'Unused Questions: Deleted {$a->deleted}, Skipped {$a->skipped}';
$string['totalsummary'] = 'Total: {$a->deleted} items deleted, {$a->skipped} items skipped';
$string['cleanupcompletesmall'] = 'Question cleanup finished';
$string['messageprovider:cleanupcomplete'] = 'Cleanup task completion notification';

$string['currentstatistics'] = 'Current Statistics (Before Cleanup)';
$string['statisticstotal'] = 'Total Questions: {$a}';
$string['statisticsunused'] = 'Unused Questions: {$a}';
$string['statisticsduplicates'] = 'Duplicate Questions: {$a}';
$string['statisticsemptyduplicatecategories'] = 'Empty Duplicate Categories: {$a}';
$string['statisticsemptycategories'] = 'Empty Categories: {$a}';
?>
