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
 * Admin settings for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Default export format.
    $settings->add(new admin_setting_configselect(
        'block_catquiz_statistics/defaultformat',
        get_string('setting:defaultformat', 'block_catquiz_statistics'),
        get_string('setting:defaultformat_desc', 'block_catquiz_statistics'),
        'csv',
        [
            'csv'   => 'CSV',
            'json'  => 'JSON',
            'excel' => 'Excel (XLSX)',
            'ods'   => 'ODS',
        ]
    ));

    // Multi-sheet workbook size limit.
    $settings->add(new admin_setting_configtext(
        'block_catquiz_statistics/maxsheets',
        get_string('setting:maxsheets', 'block_catquiz_statistics'),
        get_string('setting:maxsheets_desc', 'block_catquiz_statistics'),
        50,
        PARAM_INT
    ));

    // Question Engine join toggle (for Test Progress and Item & Response Analysis).
    $settings->add(new admin_setting_configcheckbox(
        'block_catquiz_statistics/enableqejoin',
        get_string('setting:enableqejoin', 'block_catquiz_statistics'),
        get_string('setting:enableqejoin_desc', 'block_catquiz_statistics'),
        1
    ));

    // Learning Activity: log archival — disabled by default; requires data protection justification.
    $settings->add(new admin_setting_configcheckbox(
        'block_catquiz_statistics/enablemoduled',
        get_string('setting:enablemoduled', 'block_catquiz_statistics'),
        get_string('setting:enablemoduled_desc', 'block_catquiz_statistics'),
        0
    ));
}
