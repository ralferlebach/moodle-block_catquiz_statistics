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
 * Factory that maps module IDs to their exporter and report implementations.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\report\report_interface;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Creates the correct report + exporter pair for a given module ID.
 *
 * Module IDs: 'results' Test Results | 'usage' Test Usage | 'progress' Test Progress |
 *             'activity' Learning Activity | 'items' Item & Response Analysis
 */
class exporter_factory {
    /**
     * Build a report object for the given module ID.
     *
     * @param string             $moduleid  One of 'results', 'usage', 'progress', 'activity', 'items'.
     * @param attempt_repository $repository Injected repository.
     * @return report_interface
     * @throws \coding_exception When the module ID is not recognised.
     */
    public static function create_report(
        string $moduleid,
        attempt_repository $repository
    ): report_interface {
        switch ($moduleid) {
            case 'results':
                return new \block_catquiz_statistics\report\attempt_results_report($repository);
            case 'usage':
                return new \block_catquiz_statistics\report\test_usage_report($repository);
            case 'progress':
                return new \block_catquiz_statistics\report\test_progress_report($repository);
            case 'activity':
                return new \block_catquiz_statistics\report\learning_activity_report($repository);
            case 'items':
                return new \block_catquiz_statistics\report\item_analysis_report($repository);
            default:
                throw new \coding_exception(
                    'Unknown report module id: ' . $moduleid
                    . '. further reporting features are not yet implemented.'
                );
        }
    }
}
