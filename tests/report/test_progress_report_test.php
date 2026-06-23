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
 * Unit tests for test_progress_report (Phase 2b — Modul progress).
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \block_catquiz_statistics\report\test_progress_report
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\repository\attempt_filter;

/**
 * Tests for test_progress_report.
 */
final class test_progress_report_test extends \basic_testcase {
    /**
     * Build a graphicalsummary step stdClass.
     *
     * @param int $scaleid Scale ID.
     * @param float $difficulty Item difficulty.
     * @param float $response Fraction correct (0.0–1.0).
     * @param float $ability Person ability after this step.
     * @return object
     */
    private function make_step(
        int $scaleid,
        float $difficulty,
        float $response,
        float $ability
    ): object {
        return (object) [
            'questionname'        => 'q' . $scaleid . '_d' . $difficulty,
            'questionscale'       => $scaleid,
            'questionscale_name'  => 'Scale ' . $scaleid,
            'difficulty'          => $difficulty,
            'lastresponse'        => $response,
            'fisherinformation'   => 0.9,
            'personability_after' => $ability,
        ];
    }

    /**
     * Build a minimal attempt_data DTO with graphicalsummary steps.
     *
     * @param int $userid User ID.
     * @param object[] $steps Graphicalsummary steps.
     * @return attempt_data
     */
    private function make_dto(int $userid, array $steps): attempt_data {
        $dto = new attempt_data();
        $dto->userid = $userid;
        $dto->attemptid = $userid * 100;
        $dto->instanceid = 1;
        $dto->graphicalsummary = $steps;
        return $dto;
    }

    /**
     * Module ID must be 'progress'.
     */
    public function test_module_id(): void {
        $report = new test_progress_report(new stub_repository_usage([]));
        $this->assertSame('progress', $report->get_module_id());
    }

    /**
     * Empty repository returns empty flat rows.
     */
    public function test_empty_returns_empty_rows(): void {
        $filter = new attempt_filter(courseid: 1);
        $report = new test_progress_report(new stub_repository_usage([]));
        $this->assertSame([], $report->get_flat_rows($filter));
    }

    /**
     * Attempt with no graphicalsummary steps produces no rows.
     */
    public function test_attempt_without_steps_skipped(): void {
        $dto = $this->make_dto(1, []);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_progress_report(new stub_repository_usage([$dto]));
        $this->assertSame([], $report->get_flat_rows($filter));
    }

    /**
     * Three steps produce three rows with correct step_nr sequence.
     */
    public function test_step_numbers_sequential(): void {
        $steps = [
            $this->make_step(5, 0.2, 1.0, 0.5),
            $this->make_step(5, 0.5, 0.0, 0.3),
            $this->make_step(5, 0.3, 1.0, 0.6),
        ];
        $dto = $this->make_dto(1, $steps);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_progress_report(new stub_repository_usage([$dto]));
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows[0]['step_nr']);
        $this->assertSame(2, $rows[1]['step_nr']);
        $this->assertSame(3, $rows[2]['step_nr']);
    }

    /**
     * Row values are correctly mapped from the graphicalsummary step.
     */
    public function test_row_values_correct(): void {
        $step = $this->make_step(7, 0.4, 0.75, 0.55);
        $dto = $this->make_dto(2, [$step]);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_progress_report(new stub_repository_usage([$dto]));
        $rows = $report->get_flat_rows($filter);

        $this->assertSame(2, $rows[0]['userid']);
        $this->assertSame(7, $rows[0]['questionscale']);
        $this->assertEqualsWithDelta(0.4, $rows[0]['difficulty'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $rows[0]['lastresponse'], 1e-9);
        $this->assertEqualsWithDelta(0.55, $rows[0]['personability_after'], 1e-9);
    }

    /**
     * Two attempts produce combined rows with independent step sequences.
     */
    public function test_two_attempts_combined(): void {
        $dto1 = $this->make_dto(1, [$this->make_step(5, 0.2, 1.0, 0.5)]);
        $dto2 = $this->make_dto(2, [
            $this->make_step(5, 0.3, 0.0, 0.3),
            $this->make_step(5, 0.1, 1.0, 0.4),
        ]);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_progress_report(new stub_repository_usage([$dto1, $dto2]));
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(3, $rows);
        // User 2 step numbers restart at 1.
        $user2rows = array_values(array_filter($rows, fn($r) => $r['userid'] === 2));
        $this->assertSame(1, $user2rows[0]['step_nr']);
        $this->assertSame(2, $user2rows[1]['step_nr']);
    }

    /**
     * get_columns() contains all expected step-level keys.
     */
    public function test_get_columns_has_step_keys(): void {
        $report = new test_progress_report(new stub_repository_usage([]));
        $cols = $report->get_columns();
        foreach (['step_nr', 'questionname', 'difficulty', 'lastresponse', 'personability_after'] as $key) {
            $this->assertArrayHasKey($key, $cols);
        }
    }
}
