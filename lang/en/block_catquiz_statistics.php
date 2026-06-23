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
 * English language strings for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
$string['adminreport:comingsoon'] = 'The system-wide item and response analysis will be available in a future release.';
$string['adminreporttitle'] = 'CAT Quiz Statistics – System Report';
$string['block:attempts'] = 'Attempts';
$string['block:instances'] = 'Active tests';
$string['block:noattempts'] = 'No attempts recorded yet.';
$string['block_catquiz_statistics:addinstance'] = 'Add CAT Quiz Statistics block';
$string['block_catquiz_statistics:export'] = 'Export statistics data';
$string['block_catquiz_statistics:myaddinstance'] = 'Add CAT Quiz Statistics block to My Moodle';
$string['block_catquiz_statistics:view'] = 'View aggregate statistics widget';
$string['block_catquiz_statistics:viewall'] = 'View system-wide statistics';
$string['block_catquiz_statistics:viewdebug'] = 'View attempt trajectory and debug data';
$string['block_catquiz_statistics:viewdetails'] = 'View per-user report details';
$string['error:nocatquiz'] = 'This report requires local_catquiz to be installed.';
$string['error:nopermission'] = 'You do not have permission to view this report.';
$string['module_a'] = 'Test Results';
$string['module_b'] = 'Test Usage';
$string['module_c'] = 'Test Progress';
$string['module_d'] = 'Learning Activity';
$string['module_e'] = 'Item & Response Analysis';
$string['nocatquiz'] = 'The required plugin local_catquiz is not installed or not active.';
$string['noinstances'] = 'No CAT Quiz instances found in this course.';
$string['pluginname'] = 'CAT Quiz Statistics';
$string['pluginname:desc'] = 'Extended statistics and multi-format data export for CAT quiz attempts.';
$string['privacy:metadata'] = 'This plugin only reads data managed by local_catquiz and the Moodle question engine. It does not store personal data of its own (Phase 1 / MVP).';
$string['privacy:metadata:adaptivequiz_attempt'] = 'The adaptivequiz_attempt.uniqueid field is read to join catquiz attempts to the Moodle question engine. No data is stored by this plugin.';
$string['privacy:metadata:local_catquiz_attempts'] = 'Attempt data (ability, SE, strategy, status, JSON payload) is read from this table to generate statistics and exports. No data is stored by this plugin.';
$string['privacy:metadata:local_catquiz_personparams'] = 'Person-ability parameters per scale and context are read from this table. No data is stored by this plugin.';
$string['privacy:metadata:question_attempt_step_data'] = 'Step data key-value pairs are read for distractor / response-option frequency analysis. No data is stored by this plugin.';
$string['privacy:metadata:question_attempt_steps'] = 'Question attempt steps (fraction, timecreated) are read for per-question timing and response analysis. No data is stored by this plugin.';
$string['report:col_attempt_rank'] = 'Attempt no.';
$string['report:col_attemptid'] = 'Attempt ID';
$string['report:col_delta_ability'] = 'Ability change';
$string['report:col_diff_max'] = 'Difficulty max';
$string['report:col_diff_mean'] = 'Difficulty mean';
$string['report:col_diff_min'] = 'Difficulty min';
$string['report:col_diff_sd'] = 'Difficulty SD';
$string['report:col_duration_fmt'] = 'Duration';
$string['report:col_duration_s'] = 'Duration (s)';
$string['report:col_email'] = 'E-mail';
$string['report:col_endtime'] = 'End time';
$string['report:col_firstname'] = 'First name';
$string['report:col_global_pp'] = 'Global PP';
$string['report:col_global_scale_id'] = 'Global scale ID';
$string['report:col_global_scale_name'] = 'Global scale';
$string['report:col_global_se'] = 'Global SE';
$string['report:col_group_difficulty'] = 'Item difficulties';
$string['report:col_group_items'] = 'Items';
$string['report:col_group_results'] = 'Results';
$string['report:col_group_scaleinfo'] = 'Scale information';
$string['report:col_id'] = 'ID';
$string['report:col_instanceid'] = 'Instance ID';
$string['report:col_items_parametrized'] = 'Items (parametrized)';
$string['report:col_items_productive'] = 'Items (productive)';
$string['report:col_items_total'] = 'Items (total)';
$string['report:col_lastname'] = 'Last name';
$string['report:col_max'] = 'Max';
$string['report:col_mean'] = 'Mean';
$string['report:col_median'] = 'Median';
$string['report:col_min'] = 'Min';
$string['report:col_n'] = 'N';
$string['report:col_parent_label'] = 'Parent scale';
$string['report:col_primary_pp'] = 'Primary PP';
$string['report:col_primary_scale_id'] = 'Primary scale ID';
$string['report:col_primary_scale_name'] = 'Primary scale';
$string['report:col_primary_se'] = 'Primary SE';
$string['report:col_q1'] = 'Q1';
$string['report:col_q3'] = 'Q3';
$string['report:col_rci'] = 'RCI';
$string['report:col_scale_id'] = 'Scale ID';
$string['report:col_scale_label'] = 'Label';
$string['report:col_scale_name'] = 'Scale name';
$string['report:col_scale_parent'] = 'Assigned to';
$string['report:col_sd'] = 'SD';
$string['report:col_starttime'] = 'Start time';
$string['report:col_status'] = 'Status';
$string['report:col_testid'] = 'Test ID';
$string['report:col_testname'] = 'Test name';
$string['report:col_teststrategy'] = 'Test strategy';
$string['report:col_total_testitems'] = 'Items total';
$string['report:col_used_testitems'] = 'Items answered';
$string['report:col_userid'] = 'User ID';
$string['report:col_username'] = 'User';
$string['report:comingsoon'] = 'The detailed report will be available in the next release (Phase 1: Test Results).';
$string['report:export_button'] = 'Export';
$string['report:export_csv'] = 'Export CSV';
$string['report:export_excel'] = 'Export Excel (8 sheets)';
$string['report:export_format'] = 'Export format';
$string['report:filter_all'] = '(all)';
$string['report:filter_all_courses'] = '(all courses)';
$string['report:filter_all_instances'] = '(all instances)';
$string['report:filter_apply'] = 'Apply filter';
$string['report:filter_course'] = 'Course';
$string['report:filter_enddate'] = 'To date';
$string['report:filter_instance'] = 'CAT Quiz instance';
$string['report:filter_instances'] = 'CAT Quiz instances';
$string['report:filter_no_instances'] = 'No CAT Quiz instances found in this course.';
$string['report:filter_none'] = '(no filter)';
$string['report:filter_startdate'] = 'From date';
$string['report:format_csv'] = 'CSV';
$string['report:format_excel'] = 'Excel (XLSX, 8 sheets)';
$string['report:format_json'] = 'JSON';
$string['report:format_ods'] = 'ODS (8 sheets)';
$string['report:grp_item_difficulty'] = 'Item difficulties';
$string['report:grp_items'] = 'Items';
$string['report:grp_results'] = 'Results';
$string['report:grp_scale_info'] = 'Scale information';
$string['report:meta_courseid'] = 'Course ID';
$string['report:meta_coursename'] = 'Course name';
$string['report:meta_datetime'] = 'Export timestamp';
$string['report:meta_format'] = 'Format';
$string['report:meta_instanceid'] = 'Instance ID (Adaptivequiz)';
$string['report:meta_key'] = 'Key';
$string['report:meta_moodle'] = 'Moodle version';
$string['report:meta_participants'] = 'Participants';
$string['report:meta_plugin'] = 'Plugin';
$string['report:meta_rootscale'] = 'root scale';
$string['report:meta_s_course'] = 'Course & Test';
$string['report:meta_s_export'] = 'Export information';
$string['report:meta_s_filter'] = 'Filter';
$string['report:meta_s_hierarchy'] = 'Scale hierarchy';
$string['report:meta_s_results'] = 'Results';
$string['report:meta_s_se'] = 'SE validity settings';
$string['report:meta_s_sheets'] = 'Sheet overview';
$string['report:meta_section'] = 'Section';
$string['report:meta_sheet_attempts_raw'] = 'Raw data (DB columns, no wide format)';
$string['report:meta_sheet_attempts_wide'] = 'Flat/wide including all subscale columns';
$string['report:meta_sheet_scale_summary'] = 'Descriptive stats per scale (n/mean/median/sd/…)';
$string['report:meta_sheet_subscale_frac'] = 'Fraction correct per attempt × subscale';
$string['report:meta_sheet_subscale_n'] = 'Item count per attempt × subscale';
$string['report:meta_sheet_subscale_scores'] = 'Person ability (PP) per attempt × subscale';
$string['report:meta_sheet_subscale_se'] = 'Standard error per attempt × subscale (validity check)';
$string['report:meta_subof'] = 'subscale of';
$string['report:meta_testid'] = 'Test ID (catquiz)';
$string['report:meta_testname'] = 'Test name';
$string['report:meta_totalattempts'] = 'Total attempts';
$string['report:meta_value'] = 'Value';
$string['report:n_attempts'] = 'Attempts found:';
$string['report:noattempts'] = 'No attempts match the current filter.';
$string['report:rci_note'] = 'RCI = ability change / sqrt(SE1^2 + SE2^2); |RCI| >= 1.96 indicates reliable change (p < .05).';
$string['report:se_invalid_note'] = 'NULL = SE validity not met (SE > SEmax={$a->semax} or N < Nmin={$a->nmin})';
$string['report:sheet_attempts_raw'] = 'Attempts';
$string['report:sheet_attempts_wide'] = 'Results (all)';
$string['report:sheet_metadata'] = 'Metadata';
$string['report:sheet_scale_summary'] = 'Scale report';
$string['report:sheet_subscale_frac'] = 'Results (% correct)';
$string['report:sheet_subscale_n'] = 'Results (item count)';
$string['report:sheet_subscale_scores'] = 'Results (scores)';
$string['report:sheet_subscale_se'] = 'Results (standard error)';
$string['report_schema_missing'] = 'CAT Quiz Statistics requires local_catquiz to be installed. Please contact your Moodle administrator.';
$string['reporttitle'] = 'CAT Quiz Statistics – Course Report';
$string['setting:defaultformat'] = 'Default export format';
$string['setting:defaultformat_desc'] = 'Format used by default when exporting data.';
$string['setting:enablemoduled'] = 'Enable learning activity tracking';
$string['setting:enablemoduled_desc'] = 'When enabled, learning activity log data is collected and stored beyond the site retention policy. Requires explicit data-protection justification. Disabled by default.';
$string['setting:enableqejoin'] = 'Enable Question Engine data';
$string['setting:enableqejoin_desc'] = 'When enabled, per-question timing and response data from the Moodle question engine is included in Test Progress and Item & Response Analysis.';
$string['setting:maxsheets'] = 'Maximum sheets per workbook export';
$string['setting:maxsheets_desc'] = 'Maximum number of sheets in a multi-sheet XLSX / ODS export. High values may cause memory issues.';
$string['viewadminreport'] = 'System-wide report';
$string['viewreport'] = 'Open report';
