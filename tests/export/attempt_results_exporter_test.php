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
 * PHPUnit tests for exporter_factory and base_exporter.
 *
 * These tests are pure unit tests: no DB access, no DataGenerator, no global
 * Moodle state modifications.  They therefore extend \basic_testcase rather
 * than \advanced_testcase.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\repository\attempt_repository;

/**
 * Tests for the export layer.
 *
 * @covers \block_catquiz_statistics\export\exporter_factory
 * @covers \block_catquiz_statistics\export\base_exporter
 */
final class attempt_results_exporter_test extends \basic_testcase {
    /**
     * Factory creates a valid report object for Test Results.
     *
     * @return void
     */
    public function test_factory_creates_test_results_report(): void {
        $repo = new attempt_repository();
        $report = exporter_factory::create_report('results', $repo);
        $this->assertSame('results', $report->get_module_id());
        $this->assertInstanceOf(\block_catquiz_statistics\report\report_interface::class, $report);
    }

    /**
     * Factory throws coding_exception for unknown module IDs.
     *
     * @return void
     */
    public function test_factory_throws_for_unknown_module(): void {
        $this->expectException(\coding_exception::class);
        exporter_factory::create_report('z', new attempt_repository());
    }

    /**
     * Test Results report: get_flat_rows returns an array (empty or not depends on DB state).
     *
     * @return void
     */
    public function test_test_results_get_flat_rows_returns_array(): void {
        $repo = new attempt_repository();
        $report = exporter_factory::create_report('results', $repo);
        $filter = new \block_catquiz_statistics\repository\attempt_filter(courseid: 999999);
        $result = $report->get_flat_rows($filter);
        $this->assertIsArray($result);
    }

    /**
     * Test Results report: get_aggregate_stats returns an array.
     *
     * @return void
     */
    public function test_test_results_get_aggregate_stats_returns_array(): void {
        $repo = new attempt_repository();
        $report = exporter_factory::create_report('results', $repo);
        $filter = new \block_catquiz_statistics\repository\attempt_filter(courseid: 999999);
        $result = $report->get_aggregate_stats($filter);
        $this->assertIsArray($result);
    }
}
