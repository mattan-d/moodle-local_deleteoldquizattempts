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
 * Cleanup questions page.
 *
 * @package    local_deleteoldquizattempts
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$PAGE->set_url('/local/deleteoldquizattempts/cleanup.php', ['courseid' => $courseid]);

if ($courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($courseid);
    require_login($course);
    require_capability('moodle/site:config', $context);
    
    $PAGE->set_context($context);
    $PAGE->set_heading($course->fullname);
    $PAGE->set_title(get_string('cleanupquestions', 'local_deleteoldquizattempts'));
} else {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    admin_externalpage_setup('local_deleteoldquizattempts_cleanup');
    $PAGE->set_heading(get_string('cleanupallcourses', 'local_deleteoldquizattempts'));
}

$existingtask = $DB->get_record_sql(
    "SELECT * FROM {task_adhoc} 
     WHERE classname = :classname 
     AND (faildelay = 0 OR faildelay IS NULL)
     AND customdata LIKE :courseid
     ORDER BY id DESC 
     LIMIT 1",
    [
        'classname' => '\\local_deleteoldquizattempts\\task\\cleanup_adhoc_task',
        'courseid' => '%"courseid":' . $courseid . '%'
    ]
);

$taskrunning = false;
$taskstatus = '';
if ($existingtask) {
    $taskrunning = true;
    if ($existingtask->nextruntime <= time()) {
        $taskstatus = get_string('taskrunning', 'local_deleteoldquizattempts');
    } else {
        $scheduledtime = userdate($existingtask->nextruntime);
        $taskstatus = get_string('taskqueued', 'local_deleteoldquizattempts', $scheduledtime);
    }
}

if ($confirm && confirm_sesskey()) {
    if ($taskrunning) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('taskalreadyrunning', 'local_deleteoldquizattempts'), 'notifywarning');
        echo html_writer::tag('p', $taskstatus);
        echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
        echo $OUTPUT->footer();
        exit;
    }
    
    $task = new \local_deleteoldquizattempts\task\cleanup_adhoc_task();
    $task->set_custom_data([
        'courseid' => $courseid,
        'userid' => $USER->id,
    ]);
    \core\task\manager::queue_adhoc_task($task);
    
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('cleanuptaskqueued', 'local_deleteoldquizattempts'), 'notifysuccess');
    echo html_writer::tag('p', get_string('cleanupnotification', 'local_deleteoldquizattempts'));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
    echo $OUTPUT->footer();
} else {
    // Show confirmation page.
    echo $OUTPUT->header();
    
    if ($taskrunning) {
        echo $OUTPUT->notification($taskstatus, 'notifyinfo');
        echo html_writer::tag('p', get_string('taskstatusinfo', 'local_deleteoldquizattempts'));
    }
    
    if ($courseid) {
        $message = get_string('confirmcleanup', 'local_deleteoldquizattempts', $course->fullname);
    } else {
        $message = get_string('confirmcleanupall', 'local_deleteoldquizattempts');
    }
    
    echo $OUTPUT->heading(get_string('cleanupquestions', 'local_deleteoldquizattempts'));
    
    // Show statistics if courseid is provided.
    if ($courseid) {
        $stats = \local_deleteoldquizattempts\helper::get_course_statistics($courseid);
        
        echo $OUTPUT->box_start('generalbox');
        echo html_writer::tag('h4', get_string('currentstatistics', 'local_deleteoldquizattempts'));
        echo html_writer::start_tag('ul');
        echo html_writer::tag('li', get_string('statisticstotal', 'local_deleteoldquizattempts', $stats->total));
        echo html_writer::tag('li', get_string('statisticsunused', 'local_deleteoldquizattempts', $stats->unused));
        echo html_writer::tag('li', get_string('statisticsduplicates', 'local_deleteoldquizattempts', $stats->duplicates));
        echo html_writer::tag('li', get_string('statisticsemptyduplicatecategories', 'local_deleteoldquizattempts', $stats->emptyduplicatecategories));
        echo html_writer::tag('li', get_string('statisticsemptycategories', 'local_deleteoldquizattempts', $stats->emptycategories));
        echo html_writer::end_tag('ul');
        echo $OUTPUT->box_end();
        echo html_writer::empty_tag('br');
    }
    
    echo html_writer::tag('p', $message);
    
    echo html_writer::tag('p', get_string('cleanupwillremove', 'local_deleteoldquizattempts'));
    echo html_writer::start_tag('ul');
    echo html_writer::tag('li', get_string('duplicatequestions', 'local_deleteoldquizattempts'));
    echo html_writer::tag('li', get_string('emptyduplicatecategories', 'local_deleteoldquizattempts'));
    echo html_writer::tag('li', get_string('emptycategories', 'local_deleteoldquizattempts'));
    echo html_writer::tag('li', get_string('unusedquestions', 'local_deleteoldquizattempts'));
    echo html_writer::end_tag('ul');
    
    if ($taskrunning) {
        echo html_writer::tag('p', get_string('cannotqueuewhilerunning', 'local_deleteoldquizattempts'), 
            ['class' => 'alert alert-warning']);
        $cancelurl = $courseid ? new moodle_url('/course/view.php', ['id' => $courseid]) : new moodle_url('/');
        echo $OUTPUT->single_button($cancelurl, get_string('back'), 'get');
    } else {
        $continueurl = new moodle_url('/local/deleteoldquizattempts/cleanup.php', 
            ['courseid' => $courseid, 'confirm' => 1, 'sesskey' => sesskey()]);
        $cancelurl = $courseid ? new moodle_url('/course/view.php', ['id' => $courseid]) : new moodle_url('/');
        
        echo $OUTPUT->confirm($message, $continueurl, $cancelurl);
    }
    
    echo $OUTPUT->footer();
}
