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
 * Course-level statistics report page for block_catquizstatistics.
 *
 * This page is the main entry point for the course-scoped statistical report
 * (Module a: Test Results, and later Modules b/c/d/e).  Access requires both
 * the block capability viewdetails AND local/catquiz:view_users_feedback.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
\block_catquizstatistics\access::require_viewdetails($context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/catquizstatistics/report.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('reporttitle', 'block_catquizstatistics'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('report');
$PAGE->navbar->add(
    get_string('reporttitle', 'block_catquizstatistics'),
    new moodle_url('/blocks/catquizstatistics/report.php', ['courseid' => $courseid])
);

echo $OUTPUT->header();

$reportpage = new \block_catquizstatistics\output\report_page(
    courseid: $courseid,
    heading: get_string('reporttitle', 'block_catquizstatistics'),
    comingsoon: get_string('report:comingsoon', 'block_catquizstatistics'),
    issystemwide: false,
);

echo $OUTPUT->render_from_template(
    'block_catquizstatistics/report_page',
    $reportpage->export_for_template($OUTPUT)
);

echo $OUTPUT->footer();
