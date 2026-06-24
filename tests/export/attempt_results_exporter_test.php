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
 * @covers \block_catquiz_statistics\export\test_usage_exporter
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

    /**
     * Factory creates every defined report module without error.
     *
     * @return void
     */
    public function test_factory_creates_all_modules(): void {
        $repo = new attempt_repository();
        foreach (['results', 'usage', 'progress', 'activity', 'items'] as $moduleid) {
            $report = exporter_factory::create_report($moduleid, $repo);
            $this->assertSame($moduleid, $report->get_module_id());
        }
    }

    /**
     * create_exporter returns the multi-sheet usage exporter for module 'usage'.
     *
     * @return void
     */
    public function test_create_exporter_routes_usage_to_usage_exporter(): void {
        $exporter = exporter_factory::create_exporter('usage');
        $this->assertInstanceOf(test_usage_exporter::class, $exporter);
    }

    /**
     * create_exporter returns the default results exporter for non-usage modules.
     *
     * @return void
     */
    public function test_create_exporter_routes_others_to_results_exporter(): void {
        foreach (['results', 'progress', 'items', 'activity'] as $moduleid) {
            $exporter = exporter_factory::create_exporter($moduleid);
            $this->assertInstanceOf(attempt_results_exporter::class, $exporter);
        }
    }

    /**
     * Both exporters share the base_exporter type.
     *
     * @return void
     */
    public function test_exporters_extend_base_exporter(): void {
        $this->assertInstanceOf(base_exporter::class, exporter_factory::create_exporter('usage'));
        $this->assertInstanceOf(base_exporter::class, exporter_factory::create_exporter('results'));
    }
}
