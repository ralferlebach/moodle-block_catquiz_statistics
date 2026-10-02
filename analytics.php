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
 * Learning-analytics workspaces of a course (Issue #7).
 *
 * Workspaces: data | analytics | model | analysis. Aggregates require
 * block/catquiz_statistics:viewanalytics; the person timeline additionally
 * requires block/catquiz_statistics:viewdetails.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_catquiz_statistics\output\analytics_page;

$courseid = required_param('courseid', PARAM_INT);
$workspace = optional_param('workspace', 'analytics', PARAM_ALPHA);
$modelid = optional_param('modelid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$analysis = [
    'type' => optional_param('atype', '', PARAM_ALPHA),
    'outcome' => optional_param('outcome', '', PARAM_ALPHANUMEXT),
    'predictors' => optional_param_array('predictors', [], PARAM_ALPHANUMEXT),
    'covariates' => optional_param_array('covariates', [], PARAM_ALPHANUMEXT),
    'rtype' => optional_param('rtype', 'auto', PARAM_ALPHA),
    'sequence' => optional_param('sequence', 0, PARAM_BOOL),
    'syntax' => \core_text::substr(optional_param('syntax', '', PARAM_TEXT), 0, 2000),
    'bootstrap' => optional_param('bootstrap', 1000, PARAM_INT),
    'seed' => optional_param('seed', 2026, PARAM_INT),
];

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('block/catquiz_statistics:viewanalytics', $context);
if (!in_array($workspace, analytics_page::WORKSPACES, true)) {
    $workspace = 'analytics';
}
$canviewdetails = has_capability('block/catquiz_statistics:viewdetails', $context);

$PAGE->set_url(new moodle_url(
    '/blocks/catquiz_statistics/analytics.php',
    ['courseid' => $courseid, 'workspace' => $workspace, 'modelid' => $modelid]
));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$title = get_string('analytics:title', 'block_catquiz_statistics');
$PAGE->set_title($title . ': ' . format_string($course->shortname));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template(
    'block_catquiz_statistics/analytics_page',
    (new analytics_page($course, $workspace, $modelid, $canviewdetails ? $userid : 0, $canviewdetails, $analysis))
        ->export_for_template($OUTPUT)
);
echo $OUTPUT->footer();
