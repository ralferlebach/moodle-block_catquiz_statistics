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
 * English language strings for block_catquizstatistics.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Core.
$string['pluginname']           = 'CAT Quiz Statistics';
$string['pluginname:desc']      = 'Extended statistics and multi-format data export for CAT quiz attempts.';

// Block display.
$string['viewreport']           = 'Open report';
$string['viewadminreport']      = 'System-wide report';
$string['noinstances']          = 'No CAT Quiz instances found in this course.';
$string['nocatquiz']            = 'The required plugin local_catquiz is not installed or not active.';

// Page titles.
$string['reporttitle']          = 'CAT Quiz Statistics – Course Report';
$string['adminreporttitle']     = 'CAT Quiz Statistics – System Report';

// Report page content.
$string['report:comingsoon']    = 'The detailed report will be available in the next release (Phase 1: Test Results).';
$string['adminreport:comingsoon'] = 'The system-wide item and response analysis will be available in a future release (Phase 3: Module e).';

// Capability strings.
$string['block_catquizstatistics:addinstance']   = 'Add CAT Quiz Statistics block';
$string['block_catquizstatistics:myaddinstance'] = 'Add CAT Quiz Statistics block to My Moodle';
$string['block_catquizstatistics:view']          = 'View aggregate statistics widget';
$string['block_catquizstatistics:viewdetails']   = 'View per-user report details';
$string['block_catquizstatistics:viewdebug']     = 'View attempt trajectory and debug data';
$string['block_catquizstatistics:export']        = 'Export statistics data';
$string['block_catquizstatistics:viewall']       = 'View system-wide statistics';

// Admin settings.
$string['setting:defaultformat']      = 'Default export format';
$string['setting:defaultformat_desc'] = 'Format used by default when exporting data.';
$string['setting:maxsheets']          = 'Maximum sheets per workbook export';
$string['setting:maxsheets_desc']     = 'Maximum number of sheets in a multi-sheet XLSX / ODS export. High values may cause memory issues.';
$string['setting:enableqejoin']       = 'Enable Question Engine data';
$string['setting:enableqejoin_desc']  = 'When enabled, per-question timing and response data from the Moodle question engine is included in Module c (Test progress) and Module e (Item & Response Analysis).';
$string['setting:enablemoduled']      = 'Enable learning activity module (Module d)';
$string['setting:enablemoduled_desc'] = 'When enabled, learning activity log data is collected and stored beyond the site retention policy. Requires explicit data-protection justification. Disabled by default.';

// Module names (used in UI tabs and export sheet titles).
$string['module_a']     = 'Test Results';
$string['module_b']     = 'Test Usage';
$string['module_c']     = 'Test Progress';
$string['module_d']     = 'Learning Activity';
$string['module_e']     = 'Item & Response Analysis';

// Error strings.
$string['error:nopermission']   = 'You do not have permission to view this report.';
$string['error:nocatquiz']      = 'This report requires local_catquiz to be installed.';

// Privacy.
$string['privacy:metadata']                          = 'This plugin only reads data managed by local_catquiz and the Moodle question engine. It does not store personal data of its own (Phase 1 / MVP).';
$string['privacy:metadata:local_catquiz_attempts']   = 'Attempt data (ability, SE, strategy, status, JSON payload) is read from this table to generate statistics and exports. No data is stored by this plugin.';
$string['privacy:metadata:local_catquiz_personparams'] = 'Person-ability parameters per scale and context are read from this table. No data is stored by this plugin.';
$string['privacy:metadata:adaptivequiz_attempt']     = 'The adaptivequiz_attempt.uniqueid field is read to join catquiz attempts to the Moodle question engine. No data is stored by this plugin.';
$string['privacy:metadata:question_attempt_steps']   = 'Question attempt steps (fraction, timecreated) are read for per-question timing and response analysis. No data is stored by this plugin.';
$string['privacy:metadata:question_attempt_step_data'] = 'Step data key-value pairs are read for distractor / response-option frequency analysis. No data is stored by this plugin.';
