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
 * Unit tests for test_usage_report (Phase 2a — Modul usage).
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
 * Tests for test_usage_report.
 *
 * @covers \block_catquiz_statistics\report\test_usage_report
 */
final class test_usage_report_test extends \basic_testcase {
    /**
     * Create a stub repository returning the given DTOs.
     *
     * Uses an anonymous class so no extra file or require_once is needed.
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
     * Build a minimal attempt_data DTO for testing.
     *
     * @param int $userid User ID.
     * @param int $instanceid Instance ID.
     * @param int $starttime Start Unix timestamp.
     * @param int $globalscaleid Global scale ID.
     * @param float $pp Person ability on global scale.
     * @param float $se Standard error on global scale.
     * @return attempt_data
     */
    private function make_dto(
        int $userid,
        int $instanceid,
        int $starttime,
        int $globalscaleid,
        float $pp,
        float $se
    ): attempt_data {
        $dto = new attempt_data();
        $dto->userid = $userid;
        $dto->instanceid = $instanceid;
        $dto->starttime = $starttime;
        $dto->globalscaleid = $globalscaleid;
        $dto->personabilities = [$globalscaleid => $pp];
        $dto->se = [$globalscaleid => $se];
        $dto->attemptid = $starttime;
        return $dto;
    }

    /**
     * Module ID must be 'usage'.
     */
    public function test_module_id(): void {
        $report = new test_usage_report($this->make_repo([]));
        $this->assertSame('usage', $report->get_module_id());
    }

    /**
     * Empty repository returns empty flat rows.
     */
    public function test_empty_returns_empty_rows(): void {
        $filter = new attempt_filter(courseid: 1);
        $report = new test_usage_report($this->make_repo([]));
        $this->assertSame([], $report->get_flat_rows($filter));
    }

    /**
     * Single attempt gets rank 1; delta_ability and rci are null (no previous attempt).
     */
    public function test_single_attempt_rank_one(): void {
        $dto = $this->make_dto(1, 10, 1000, 5, 0.5, 0.3);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_usage_report($this->make_repo([$dto]));
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['attempt_rank']);
        $this->assertNull($rows[0]['delta_ability']);
        $this->assertNull($rows[0]['rci']);
    }

    /**
     * Two attempts by the same user on the same instance get ranks 1 and 2.
     * delta_ability and RCI are computed correctly for the second attempt.
     */
    public function test_two_attempts_rank_and_rci(): void {
        $dto1 = $this->make_dto(1, 10, 1000, 5, 0.5, 0.3);
        $dto2 = $this->make_dto(1, 10, 2000, 5, 0.8, 0.25);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_usage_report($this->make_repo([$dto1, $dto2]));
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['attempt_rank']);
        $this->assertNull($rows[0]['delta_ability']);

        $this->assertSame(2, $rows[1]['attempt_rank']);
        $this->assertEqualsWithDelta(0.3, $rows[1]['delta_ability'], 1e-9);

        // RCI = 0.3 / sqrt(0.3^2 + 0.25^2) = 0.3 / sqrt(0.09 + 0.0625).
        $expectedrci = 0.3 / sqrt(0.09 + 0.0625);
        $this->assertEqualsWithDelta($expectedrci, $rows[1]['rci'], 1e-9);
    }

    /**
     * Two users on the same instance get independent rank sequences starting at 1.
     */
    public function test_two_users_independent_ranks(): void {
        $dto1a = $this->make_dto(1, 10, 1000, 5, 0.5, 0.3);
        $dto1b = $this->make_dto(1, 10, 2000, 5, 0.7, 0.28);
        $dto2a = $this->make_dto(2, 10, 1500, 5, 0.4, 0.35);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_usage_report(
            $this->make_repo([$dto1a, $dto1b, $dto2a])
        );
        $rows = $report->get_flat_rows($filter);

        $this->assertCount(3, $rows);
        $bykey = [];
        foreach ($rows as $row) {
            $bykey[$row['userid'] . '_' . $row['starttime']] = $row;
        }
        $this->assertSame(1, $bykey['1_1000']['attempt_rank']);
        $this->assertSame(2, $bykey['1_2000']['attempt_rank']);
        $this->assertSame(1, $bykey['2_1500']['attempt_rank']);
        $this->assertNull($bykey['2_1500']['rci']);
    }

    /**
     * get_columns() contains rci, delta_ability, and attempt_rank keys.
     */
    public function test_get_columns_has_rci_and_delta(): void {
        $report = new test_usage_report($this->make_repo([]));
        $cols = $report->get_columns();
        $this->assertArrayHasKey('rci', $cols);
        $this->assertArrayHasKey('delta_ability', $cols);
        $this->assertArrayHasKey('attempt_rank', $cols);
    }

    /**
     * get_aggregate_stats() returns an empty array when no attempts exist.
     */
    public function test_aggregate_stats_empty(): void {
        $filter = new attempt_filter(courseid: 1);
        $report = new test_usage_report($this->make_repo([]));
        $this->assertSame([], $report->get_aggregate_stats($filter));
    }

    /**
     * get_aggregate_stats() computes the correct mean for two attempts.
     */
    public function test_aggregate_stats_mean(): void {
        $dto1 = $this->make_dto(1, 10, 1000, 5, 0.4, 0.3);
        $dto2 = $this->make_dto(2, 10, 2000, 5, 0.6, 0.3);
        $filter = new attempt_filter(courseid: 1);
        $report = new test_usage_report($this->make_repo([$dto1, $dto2]));
        $stats = $report->get_aggregate_stats($filter);
        $this->assertEqualsWithDelta(0.5, $stats['mean'], 1e-9);
        $this->assertSame(2, $stats['n']);
    }
}
