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
 * Contract that every report module must fulfil.
 *
 * Each module (a–e) implements this interface so that the export factory,
 * the report page renderer, and future UI components can work against a
 * stable API regardless of which module they are handling.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\repository\attempt_filter;

/**
 * Report module interface.
 */
interface report_interface {
    /**
     * Module identifier used in URLs and export filenames ('a', 'b', 'c', 'd', 'e').
     *
     * @return string
     */
    public function get_module_id(): string;

    /**
     * Human-readable module name (for UI tabs and export sheet titles).
     *
     * @return string
     */
    public function get_module_name(): string;

    /**
     * Return flat rows suitable for single-sheet CSV / JSON export.
     *
     * Each row is an associative array column_key → scalar_value.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_flat_rows(attempt_filter $filter): array;

    /**
     * Return aggregate statistics (n, mean, median, SD, min, max, Q1, Q3).
     *
     * @param attempt_filter $filter Query scope.
     * @return array<string,mixed>
     */
    public function get_aggregate_stats(attempt_filter $filter): array;

    /**
     * Return column definitions for export headers.
     *
     * Keys are internal names; values are localised header strings.
     *
     * @return array<string,string>
     */
    public function get_columns(): array;
}
