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
 * PHPUnit tests for attempt_results_report.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Tests for attempt_results_report.
 *
 * @covers \block_catquiz_statistics\report\attempt_results_report
 */
final class attempt_results_report_test extends \advanced_testcase {
    /** @var attempt_results_report|null Report under test. */
    private ?attempt_results_report $report = null;

    /** @var attempt_repository|null Underlying repository. */
    private ?attempt_repository $repo = null;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->repo   = new attempt_repository();
        $this->report = new attempt_results_report($this->repo);
    }

    /**
     * Release resources after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->report = null;
        $this->repo   = null;
        parent::tearDown();
    }

    /**
     * get_module_id returns the expected string 'a'.
     *
     * @return void
     */
    public function test_get_module_id(): void {
        $this->assertSame('a', $this->report->get_module_id());
    }

    /**
     * get_flat_rows returns empty array when no attempts exist.
     *
     * @return void
     */
    public function test_get_flat_rows_returns_empty_without_data(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $filter = new attempt_filter(courseid: $course->id);
        $this->assertSame([], $this->report->get_flat_rows($filter));
    }

    /**
     * get_flat_rows returns one row per inserted attempt.
     *
     * @return void
     */
    public function test_get_flat_rows_returns_row_per_attempt(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user1  = $this->getDataGenerator()->create_user();
        $user2  = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user1->id, 'courseid' => $course->id]);
        $gen->create_catquiz_attempt(['userid' => $user2->id, 'courseid' => $course->id]);

        $filter = new attempt_filter(courseid: $course->id);
        $rows   = $this->report->get_flat_rows($filter);
        $this->assertCount(2, $rows);
    }

    /**
     * Each flat row contains all required fixed column keys.
     *
     * @return void
     */
    public function test_get_flat_rows_row_has_required_keys(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id]);

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        $required = [
            'id', 'userid', 'username', 'firstname', 'lastname', 'email',
            'testid', 'attemptid', 'starttime', 'endtime', 'duration_s',
            'teststrategy', 'status', 'total_testitems', 'used_testitems',
            'global_scale_id', 'global_scale_name', 'global_pp', 'global_se',
            'primary_scale_id', 'primary_scale_name', 'primary_pp', 'primary_se',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    /**
     * After get_flat_rows(), get_columns() includes dynamic scale columns.
     *
     * @return void
     */
    public function test_get_columns_includes_dynamic_scale_columns_after_fetch(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        $json = json_encode([
            'catscaleid'           => 1,
            'testid'               => 1,
            'personabilities'      => [1 => 0.5, 2 => 0.3],
            'se'                   => [1 => 0.2, 2 => 0.25],
            'primaryscale'         => (object) ['id' => 1, 'name' => 'Math'],
            'catscales'            => [
                1 => (object) ['name' => 'Math'],
                2 => (object) ['name' => 'Arithmetic'],
            ],
            'graphicalsummary_data' => [],
        ]);

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id, 'json' => $json]);

        $filter = new attempt_filter(courseid: $course->id);
        $this->report->get_flat_rows($filter);
        $columns = $this->report->get_columns();

        $this->assertArrayHasKey('scale_1_pp', $columns);
        $this->assertArrayHasKey('scale_1_se', $columns);
        $this->assertArrayHasKey('scale_2_pp', $columns);
        $this->assertArrayHasKey('scale_2_n', $columns);
    }

    /**
     * get_aggregate_stats returns descriptive stats keyed by scale ID.
     *
     * @return void
     */
    public function test_get_aggregate_stats_returns_stats_per_scale(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user1  = $this->getDataGenerator()->create_user();
        $user2  = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => $user1->id, 'courseid' => $course->id,
            'json'   => \block_catquiz_statistics_generator::build_attempt_json(1, 0.4, 0.2),
        ]);
        $gen->create_catquiz_attempt([
            'userid' => $user2->id, 'courseid' => $course->id,
            'json'   => \block_catquiz_statistics_generator::build_attempt_json(1, 0.6, 0.2),
        ]);

        $filter = new attempt_filter(courseid: $course->id);
        $stats  = $this->report->get_aggregate_stats($filter);

        $this->assertArrayHasKey(1, $stats);
        $this->assertSame(2, $stats[1]['n']);
        $this->assertEqualsWithDelta(0.5, $stats[1]['mean'], 1e-9);
    }
}
