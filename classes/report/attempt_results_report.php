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
 * Test Results report (Phase 1).
 *
 * Target columns for flat/wide export:
 *   userid, username, firstname, lastname, email,
 *   testid, attemptid, starttime, endtime, duration, teststrategy,
 *   status, usedTestitems, global_scale, global_pp, global_se,
 *   primary_scale, primary_pp, primary_se,
 *   [per subscale:] scale_{id}_pp, scale_{id}_se, scale_{id}_n, scale_{id}_frac.
 *
 * SE validity rule: output NULL when quiz settings specify nminscale or semax
 * and the attempt does not meet the threshold; assume fulfilled when settings
 * are absent.  See feedbacksettings.php filter_nminscale() / filter_semax().
 *
 * XLSX / ODS multi-sheet names: attempts_raw, attempts_wide, scale_summary,
 *   subscale_scores, subscale_se, subscale_n, subscale_frac, metadata.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Test Results report.
 */
class attempt_results_report implements report_interface {
    /** @var attempt_repository Injected repository. */
    private attempt_repository $repository;

    /**
     * Constructor.
     *
     * @param attempt_repository $repository Injected repository.
     */
    public function __construct(attempt_repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Return module identifier.
     *
     * @return string
     */
    public function get_module_id(): string {
        return 'a';
    }

    /**
     * Return localised module name.
     *
     * @return string
     */
    public function get_module_name(): string {
        return get_string('module_a', 'block_catquiz_statistics');
    }

    /**
     * Return flat attempt rows for single-sheet export.
     *
     * Stub — returns empty array until Phase 1 implementation.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_flat_rows(attempt_filter $filter): array {
        return [];
    }

    /**
     * Return aggregate statistics over the filtered attempt set.
     *
     * Computes n, mean, median, SD, min, max, Q1, Q3 for
     * personabilityAfterAttempt, duration, usedTestitems, SE.
     *
     * Reliable Change Index (RCI) = delta_ability / sqrt(SE_first^2 + SE_last^2)
     * is implemented in the Test Usage report, referenced here for context.
     *
     * Stub — not yet implemented.
     *
     * @param attempt_filter $filter Query scope.
     * @return array<string,mixed>
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        return [];
    }

    /**
     * Return column definitions for export headers.
     *
     * Stub — returns empty array until Phase 1 implementation.
     *
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [];
    }
}
