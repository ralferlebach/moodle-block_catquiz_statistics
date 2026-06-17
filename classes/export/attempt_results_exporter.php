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
 * Exporter for Module a – Attempt Results.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\export;

/**
 * Module a exporter.
 *
 * Inherits single- and multi-sheet routing from base_exporter.
 * Phase 1 will override export_multisheet() to write 8 named sheets:
 *   attempts_raw, attempts_wide, scale_summary, subscale_scores,
 *   subscale_se, subscale_n, subscale_frac, metadata.
 */
class attempt_results_exporter extends base_exporter {
    // Phase 1: override export_multisheet() here.
}
