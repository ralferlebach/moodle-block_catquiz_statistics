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
 * Plugin version definition for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'block_catquiz_statistics';
$plugin->version      = 2026061712;
$plugin->requires     = 2024100700;   // Moodle 4.5.
$plugin->supported    = [405, 405];   // Tested on Moodle 4.5; extend after 5.x testing.
$plugin->maturity     = MATURITY_ALPHA;
$plugin->release      = '0.3.13';
$plugin->dependencies = [
    'local_catquiz'          => 2024120500,
    'mod_adaptivequiz'       => 2024031502,
    'local_wunderbyte_table' => 2024040200,
];
