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
 * System-wide statistics report page for block_catquiz_statistics.
 *
 * Restricted to users holding block/catquiz_statistics:viewall at system
 * context AND local/catquiz:canmanage.  Displays module tabs and attempt data
 * across all courses; optional courseid parameter restricts to a single course.
 *
 * URL parameters:
 *   courseid    (int, optional)   Course ID; 0 = all courses.
 *   moduleid    (string, optional) Active module: 'results'|'usage'|…. Default 'results'.
 *   instanceids (int[], optional)  Filter to several mod_adaptivequiz instances.
 *   instanceid  (int, optional)   Legacy single-instance filter (fallback).
 *   startdate   (string YYYY-MM-DD, optional) Attempt start lower bound.
 *   enddate     (string YYYY-MM-DD, optional) Attempt start upper bound.
 *   export      (string 'csv'|'json'|'excel'|'ods', optional) Trigger download.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use block_catquiz_statistics\export\attempt_results_exporter;
use block_catquiz_statistics\export\exporter_factory;
use block_catquiz_statistics\output\report_page;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

$courseid  = optional_param('courseid', 0, PARAM_INT);
$moduleid  = optional_param('moduleid', 'results', PARAM_ALPHA);
$startdate = optional_param('startdate', '', PARAM_ALPHANUMEXT);
$enddate   = optional_param('enddate', '', PARAM_ALPHANUMEXT);
$export    = optional_param('export', '', PARAM_ALPHA);

$systemcontext = context_system::instance();

require_login();
\block_catquiz_statistics\access::require_viewall();

// Resolve the active module; fall back to 'a' for unknown IDs.
$allowedmodules = ['results', 'usage', 'progress', 'activity', 'items'];
if (!in_array($moduleid, $allowedmodules, true)) {
    $moduleid = 'results';
}

$filter = attempt_filter::from_request_systemwide();

$repo   = new attempt_repository();
$report = exporter_factory::create_report($moduleid, $repo);

// Handle export before any HTML output.
if ($export !== '') {
    $mode = in_array($export, ['excel', 'ods'], true) ? 'multi' : 'wide';
    (new attempt_results_exporter())->export($report, $filter, $export, $mode);
    exit;
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/blocks/catquiz_statistics/adminreport.php'));
$PAGE->set_title(get_string('adminreporttitle', 'block_catquiz_statistics'));
$PAGE->set_heading(get_string('adminreporttitle', 'block_catquiz_statistics'));
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(
    get_string('adminreporttitle', 'block_catquiz_statistics'),
    new moodle_url('/blocks/catquiz_statistics/adminreport.php')
);

$schemaok = $repo->check_schema_compatibility();

$courses  = $schemaok ? $repo->get_courses_with_attempts() : [];

if ($courseid > 0 && $schemaok) {
    $instances = $repo->get_catquiz_instances_for_course($courseid);
} else {
    $instances = $schemaok ? $repo->get_catquiz_instances_systemwide() : [];
}

$flatrows = $schemaok ? $report->get_flat_rows($filter) : [];
$widecols = $report->get_columns();
$summaryrows = ($schemaok && $moduleid === 'usage' && method_exists($report, 'get_summary_rows'))
    ? $report->get_summary_rows($filter) : [];

$reportpage = new report_page(
    courseid: 0,
    filter: $filter,
    instances: $instances,
    flatrows: $flatrows,
    widecols: $widecols,
    startdate: $startdate,
    enddate: $enddate,
    schemaok: $schemaok,
    issystemwide: true,
    courses: $courses,
    selectedcourseid: $courseid,
    reporturlpath: '/blocks/catquiz_statistics/adminreport.php',
    moduleid: $moduleid,
    summaryrows: $summaryrows
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'block_catquiz_statistics/report_page',
    $reportpage->export_for_template($OUTPUT)
);
echo $OUTPUT->footer();
