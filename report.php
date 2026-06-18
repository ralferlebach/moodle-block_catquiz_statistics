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
 * Course-level statistics report page for block_catquiz_statistics.
 *
 * Displays a filterable table of CAT quiz attempts with export buttons for
 * CSV and multi-sheet Excel.  Access requires both the block capability
 * viewdetails AND local/catquiz:view_users_feedback.
 *
 * URL parameters:
 *   courseid   (int, required) Course ID.
 *   instanceid (int, optional) Filter to a single mod_adaptivequiz instance.
 *   startdate  (string YYYY-MM-DD, optional) Attempt start lower bound.
 *   enddate    (string YYYY-MM-DD, optional) Attempt start upper bound.
 *   export     (string 'csv'|'excel', optional) Trigger file download and exit.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use block_catquiz_statistics\export\attempt_results_exporter;
use block_catquiz_statistics\report\attempt_results_report;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

$courseid   = required_param('courseid', PARAM_INT);
$instanceid = optional_param('instanceid', 0, PARAM_INT) ?: null;
$startdate  = optional_param('startdate', '', PARAM_ALPHANUMEXT);
$enddate    = optional_param('enddate', '', PARAM_ALPHANUMEXT);
$export     = optional_param('export', '', PARAM_ALPHA);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
\block_catquiz_statistics\access::require_viewdetails($context);

// Convert date strings (YYYY-MM-DD) to Unix timestamps.
$starttime = $startdate ? (int) strtotime($startdate . ' 00:00:00') ?: null : null;
$endtime   = $enddate ? (int) strtotime($enddate   . ' 23:59:59') ?: null : null;

$filter = new attempt_filter(
    courseid: $courseid,
    instanceid: $instanceid,
    starttime: $starttime,
    endtime: $endtime
);

$repo    = new attempt_repository();
$report  = new attempt_results_report($repo);

// Handle export before any HTML output.
if ($export !== '') {
    require_capability('block/catquiz_statistics:export', $context);
    $mode = ($export === 'excel') ? 'multi' : 'wide';
    (new attempt_results_exporter())->export($report, $filter, $export, $mode);
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/catquiz_statistics/report.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('reporttitle', 'block_catquiz_statistics'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('report');
$PAGE->navbar->add(
    get_string('reporttitle', 'block_catquiz_statistics'),
    new moodle_url('/blocks/catquiz_statistics/report.php', ['courseid' => $courseid])
);

$instances = $repo->check_schema_compatibility()
    ? $repo->get_catquiz_instances_for_course($courseid) : [];

$flatrows = $repo->check_schema_compatibility()
    ? $report->get_flat_rows($filter) : [];

$widecols = $report->get_columns();

$reportpage = new \block_catquiz_statistics\output\report_page(
    courseid: $courseid,
    filter: $filter,
    instances: $instances,
    flatrows: $flatrows,
    widecols: $widecols,
    startdate: $startdate,
    enddate: $enddate,
    schemaok: $repo->check_schema_compatibility()
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'block_catquiz_statistics/report_page',
    $reportpage->export_for_template($OUTPUT)
);
echo $OUTPUT->footer();
