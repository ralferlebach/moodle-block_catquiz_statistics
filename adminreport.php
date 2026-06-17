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
 * System-wide statistics report page for block_catquizstatistics.
 *
 * Restricted to users holding block/catquizstatistics:viewall at system
 * context AND local/catquiz:canmanage.  Intended for CAT managers who need
 * cross-course item analysis (Module e) or site-wide aggregations.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

require_login();
\block_catquizstatistics\access::require_viewall();

$systemcontext = context_system::instance();

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/blocks/catquizstatistics/adminreport.php'));
$PAGE->set_title(get_string('adminreporttitle', 'block_catquizstatistics'));
$PAGE->set_heading(get_string('adminreporttitle', 'block_catquizstatistics'));
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('adminreporttitle', 'block_catquizstatistics'));

echo $OUTPUT->header();

$reportpage = new \block_catquizstatistics\output\report_page(
    courseid: 0,
    heading: get_string('adminreporttitle', 'block_catquizstatistics'),
    comingsoon: get_string('adminreport:comingsoon', 'block_catquizstatistics'),
    issystemwide: true,
);

echo $OUTPUT->render_from_template(
    'block_catquizstatistics/report_page',
    $reportpage->export_for_template($OUTPUT)
);

echo $OUTPUT->footer();
