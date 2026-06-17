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
 * Admin settings for block_catquizstatistics.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Default export format.
    $settings->add(new admin_setting_configselect(
        'block_catquizstatistics/defaultformat',
        get_string('setting:defaultformat', 'block_catquizstatistics'),
        get_string('setting:defaultformat_desc', 'block_catquizstatistics'),
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
        'block_catquizstatistics/maxsheets',
        get_string('setting:maxsheets', 'block_catquizstatistics'),
        get_string('setting:maxsheets_desc', 'block_catquizstatistics'),
        50,
        PARAM_INT
    ));

    // Question Engine join toggle (for Modules c/e).
    $settings->add(new admin_setting_configcheckbox(
        'block_catquizstatistics/enableqejoin',
        get_string('setting:enableqejoin', 'block_catquizstatistics'),
        get_string('setting:enableqejoin_desc', 'block_catquizstatistics'),
        1
    ));

    // Module d: log archival — disabled by default; requires data protection justification.
    $settings->add(new admin_setting_configcheckbox(
        'block_catquizstatistics/enablemoduled',
        get_string('setting:enablemoduled', 'block_catquizstatistics'),
        get_string('setting:enablemoduled_desc', 'block_catquizstatistics'),
        0
    ));
}
