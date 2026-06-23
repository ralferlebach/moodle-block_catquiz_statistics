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
 * Displays module tabs (Testergebnisse, Testnutzung, …), a filter bar
 * and a result table with format selector for CSV / JSON / XLSX / ODS export.
 * Access requires viewdetails AND local/catquiz:view_users_feedback.
 *
 * URL parameters:
 *   courseid    (int, required)   Course ID; must be a positive integer.
 *   moduleid    (string, optional) Active module: 'results'|'usage'|…. Default 'results'.
 *   instanceids (int[], optional)  Filter to several mod_adaptivequiz instances.
 *   instanceid  (int, optional)   Legacy single-instance filter (fallback).
 *   startdate   (string YYYY-MM-DD, optional) Attempt start lower bound.
 *   enddate     (string YYYY-MM-DD, optional) Attempt start upper bound.
 *   export      (string 'csv'|'json'|'excel'|'ods', optional) Trigger download.
 *
 * Robustness note: courseid=0 can arrive from theme-generated pagination links.
 * The script redirects to the site home rather than throwing a DB exception.
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

$courseid    = required_param('courseid', PARAM_INT);
$moduleid    = optional_param('moduleid', 'results', PARAM_ALPHA);
$instanceid  = optional_param('instanceid', 0, PARAM_INT) ?: null;
$instanceids = optional_param_array('instanceids', [], PARAM_INT);
$startdate   = optional_param('startdate', '', PARAM_ALPHANUMEXT);
$enddate     = optional_param('enddate', '', PARAM_ALPHANUMEXT);
$export      = optional_param('export', '', PARAM_ALPHA);

// Guard: courseid=0 arrives from theme pagination links when no course context
// is set.  Redirect gracefully instead of crashing with a DB exception.
if ($courseid < 1) {
    redirect(new moodle_url('/'));
}

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
\block_catquiz_statistics\access::require_viewdetails($context);

// Resolve the active module; fall back to 'a' for unknown IDs.
$allowedmodules = ['results', 'usage', 'progress'];
if (!in_array($moduleid, $allowedmodules, true)) {
    $moduleid = 'results';
}

// Convert date strings (YYYY-MM-DD) to Unix timestamps.
$starttime = $startdate ? (int) strtotime($startdate . ' 00:00:00') ?: null : null;
$endtime = $enddate ? (int) strtotime($enddate . ' 23:59:59') ?: null : null;

$filter = new attempt_filter(
    courseid: $courseid,
    instanceid: $instanceid,
    starttime: $starttime,
    endtime: $endtime,
    instanceids: $instanceids
);

$repo   = new attempt_repository();
$report = exporter_factory::create_report($moduleid, $repo);

// Handle export before any HTML output.
if ($export !== '') {
    require_capability('block/catquiz_statistics:export', $context);
    // Multi-sheet (8 sheets) for spreadsheet formats; single sheet for csv/json.
    $mode = in_array($export, ['excel', 'ods'], true) ? 'multi' : 'wide';
    (new attempt_results_exporter())->export($report, $filter, $export, $mode);
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url('/blocks/catquiz_statistics/report.php', ['courseid' => $courseid])
);
$PAGE->set_title(get_string('reporttitle', 'block_catquiz_statistics'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('report');
$PAGE->navbar->add(
    get_string('reporttitle', 'block_catquiz_statistics'),
    new moodle_url('/blocks/catquiz_statistics/report.php', ['courseid' => $courseid])
);

$schemaok  = $repo->check_schema_compatibility();
$instances = $schemaok ? $repo->get_catquiz_instances_for_course($courseid) : [];
$flatrows  = $schemaok ? $report->get_flat_rows($filter) : [];
$widecols  = $report->get_columns();

$reportpage = new report_page(
    courseid: $courseid,
    filter: $filter,
    instances: $instances,
    flatrows: $flatrows,
    widecols: $widecols,
    startdate: $startdate,
    enddate: $enddate,
    schemaok: $schemaok,
    moduleid: $moduleid,
    reporturlpath: '/blocks/catquiz_statistics/report.php'
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'block_catquiz_statistics/report_page',
    $reportpage->export_for_template($OUTPUT)
);
echo $OUTPUT->footer();
