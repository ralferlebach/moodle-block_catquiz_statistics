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
 * Unit tests for item_analysis_report (Phase 3 — Modul items).
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Tests for item_analysis_report.
 *
 * @covers \block_catquiz_statistics\report\item_analysis_report
 */
final class item_analysis_report_test extends \basic_testcase {
    /**
     * Create a stub repository returning the given DTOs.
     *
     * @param attempt_data[] $dtos DTOs to return from get_attempts().
     * @return attempt_repository
     */
    private function make_repo(array $dtos): attempt_repository {
        return new class ($dtos) extends attempt_repository {
            /** @var attempt_data[] Pre-configured DTOs. */
            private array $dtos;
            /**
             * Constructor.
             * @param attempt_data[] $dtos DTOs to return.
             */
            public function __construct(array $dtos) {
                $this->dtos = $dtos;
            }
            /**
             * Return the pre-configured DTOs.
             * @param attempt_filter $filter Ignored.
             * @return attempt_data[]
             */
            public function get_attempts(attempt_filter $filter): array {
                return $this->dtos;
            }
        };
    }

    /**
     * Build a graphicalsummary step.
     *
     * @param string $name Question name.
     * @param int $scale Scale ID.
     * @param float $difficulty Item difficulty.
     * @param float $response Fraction correct.
     * @param float $fisher Fisher information.
     * @param float $ability Ability after step.
     * @return object
     */
    private function make_step(
        string $name,
        int $scale,
        float $difficulty,
        float $response,
        float $fisher,
        float $ability
    ): object {
        return (object) [
            'questionname'        => $name,
            'questionscale'       => $scale,
            'questionscale_name'  => 'Scale' . $scale,
            'difficulty'          => $difficulty,
            'lastresponse'        => $response,
            'fisherinformation'   => $fisher,
            'personability_after' => $ability,
        ];
    }

    /**
     * Build a DTO with given graphicalsummary steps.
     *
     * @param object[] $steps Steps.
     * @return attempt_data
     */
    private function make_dto(array $steps): attempt_data {
        $dto = new attempt_data();
        $dto->graphicalsummary = $steps;
        return $dto;
    }

    /**
     * Module ID must be 'items'.
     */
    public function test_module_id(): void {
        $report = new item_analysis_report($this->make_repo([]));
        $this->assertSame('items', $report->get_module_id());
    }

    /**
     * Empty repository produces empty rows.
     */
    public function test_empty_returns_empty(): void {
        $filter = new attempt_filter(courseid: 1);
        $report = new item_analysis_report($this->make_repo([]));
        $this->assertSame([], $report->get_flat_rows($filter));
    }

    /**
     * Single presentation produces correct aggregated counts.
     */
    public function test_single_step_counts(): void {
        $dto = $this->make_dto([$this->make_step('q1', 5, 0.3, 1.0, 0.9, 0.5)]);
        $filter = new attempt_filter(courseid: 1);
        $report = new item_analysis_report($this->make_repo([$dto]));
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(1, $rows);
        $this->assertSame('q1', $rows[0]['questionname']);
        $this->assertSame(1, $rows[0]['n_presented']);
        $this->assertSame(1, $rows[0]['n_correct']);
        $this->assertSame(0, $rows[0]['n_incorrect']);
        $this->assertEqualsWithDelta(1.0, $rows[0]['frac_correct'], 1e-9);
    }

    /**
     * Same item presented twice aggregates correctly.
     */
    public function test_same_item_two_attempts(): void {
        $dto1 = $this->make_dto([$this->make_step('q1', 5, 0.3, 1.0, 0.9, 0.5)]);
        $dto2 = $this->make_dto([$this->make_step('q1', 5, 0.3, 0.0, 0.8, 0.4)]);
        $filter = new attempt_filter(courseid: 1);
        $report = new item_analysis_report($this->make_repo([$dto1, $dto2]));
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['n_presented']);
        $this->assertSame(1, $rows[0]['n_correct']);
        $this->assertSame(1, $rows[0]['n_incorrect']);
        $this->assertEqualsWithDelta(0.5, $rows[0]['frac_correct'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $rows[0]['mean_response'], 1e-9);
    }

    /**
     * Items are sorted by frac_correct descending within the same scale.
     */
    public function test_sort_by_frac_correct_desc(): void {
        $dto = $this->make_dto([
            $this->make_step('q_easy', 5, 0.1, 1.0, 0.9, 0.5),
            $this->make_step('q_hard', 5, 0.9, 0.0, 0.8, 0.4),
        ]);
        $filter = new attempt_filter(courseid: 1);
        $report = new item_analysis_report($this->make_repo([$dto]));
        $rows = $report->get_flat_rows($filter);

        $this->assertSame('q_easy', $rows[0]['questionname']);
        $this->assertSame('q_hard', $rows[1]['questionname']);
    }

    /**
     * get_columns() contains all expected keys.
     */
    public function test_get_columns_has_all_keys(): void {
        $report = new item_analysis_report($this->make_repo([]));
        $cols = $report->get_columns();
        foreach (['questionname', 'difficulty', 'n_presented', 'frac_correct', 'mean_fisher'] as $k) {
            $this->assertArrayHasKey($k, $cols);
        }
    }
}
