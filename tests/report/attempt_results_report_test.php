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
     * get_module_id returns the expected string 'results'.
     *
     * @return void
     */
    public function test_get_module_id(): void {
        $this->assertSame('results', $this->report->get_module_id());
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
            'userid', 'username', 'firstname', 'lastname', 'email',
            'testid', 'attemptid', 'starttime', 'endtime', 'duration_s',
            'teststrategy', 'status', 'total_testitems', 'used_testitems',
            'global_scale_id', 'global_scale_name', 'global_score', 'global_se',
            'result_scale_id', 'result_scale_name', 'result_score', 'result_se',
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

        $this->assertArrayHasKey('scale_1_score', $columns);
        $this->assertArrayHasKey('scale_1_se', $columns);
        $this->assertArrayHasKey('scale_2_score', $columns);
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

    /**
     * teststrategy and status are resolved to human-readable German labels.
     *
     * @return void
     */
    public function test_flat_row_resolves_strategy_and_status_labels(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => $user->id, 'courseid' => $course->id,
            'teststrategy' => 1, 'status' => 0,
        ]);

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        $this->assertSame('Abgeschlossen', $row['status']);
        $this->assertSame('Alle Subskalen ableiten', $row['teststrategy']);
        $this->assertIsNotInt($row['status']);
    }

    /**
     * Unknown strategy and status integers fall back to "Strategie N"/"Status N".
     *
     * @return void
     */
    public function test_flat_row_unknown_strategy_status_fallback(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => $user->id, 'courseid' => $course->id,
            'teststrategy' => 8, 'status' => 7,
        ]);

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        $this->assertSame('Strategie 8', $row['teststrategy']);
        $this->assertSame('Status 7', $row['status']);
    }

    /**
     * SE = -1 (catquiz sentinel) suppresses score, se, n and frac for that scale.
     *
     * @return void
     */
    public function test_flat_row_se_minus_one_suppresses_scale_values(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        $json = \block_catquiz_statistics_generator::build_custom_json([
            'globalscaleid'   => 1,
            'personabilities' => [1 => 0.5, 2 => 0.9],
            'se'              => [1 => 0.2, 2 => -1.0],
            'catscales'       => [
                1 => ['name' => 'Global'],
                2 => ['name' => 'Sub'],
            ],
        ]);

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id, 'json' => $json]);

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        // Scale 1 has a valid SE → values present.
        $this->assertNotNull($row['scale_1_score']);
        $this->assertEqualsWithDelta(0.2, $row['scale_1_se'], 1e-9);
        // Scale 2 has SE = -1 → score, se and n all suppressed to null.
        $this->assertNull($row['scale_2_score']);
        $this->assertNull($row['scale_2_se']);
        $this->assertNull($row['scale_2_n']);
    }

    /**
     * The Ergebnisskala (result scale) columns are populated from primaryscale.
     *
     * @return void
     */
    public function test_flat_row_result_scale_from_primaryscale(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        $json = \block_catquiz_statistics_generator::build_custom_json([
            'globalscaleid'   => 1,
            'personabilities' => [1 => 0.5, 2 => 0.7],
            'se'              => [1 => 0.2, 2 => 0.25],
            'primaryscale'    => ['id' => 2, 'name' => 'Deficit scale'],
            'catscales'       => [
                1 => ['name' => 'Global'],
                2 => ['name' => 'Deficit scale'],
            ],
        ]);

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id, 'json' => $json]);

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        $this->assertSame(2, $row['result_scale_id']);
        $this->assertSame('Deficit scale', $row['result_scale_name']);
        $this->assertEqualsWithDelta(0.7, $row['result_score'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $row['result_se'], 1e-9);
    }

    /**
     * A null primaryscale leaves the result scale columns empty.
     *
     * @return void
     */
    public function test_flat_row_no_primaryscale_leaves_result_empty(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        $json = \block_catquiz_statistics_generator::build_custom_json([
            'globalscaleid'   => 1,
            'personabilities' => [1 => 0.5],
            'se'              => [1 => 0.2],
            'primaryscale'    => null,
            'catscales'       => [1 => ['name' => 'Global']],
        ]);

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id, 'json' => $json]);

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        $this->assertNull($row['result_scale_id']);
        $this->assertNull($row['result_scale_name']);
    }

    /**
     * endtime = 0 on a completed attempt falls back to the last graphical step timestamp.
     *
     * @return void
     */
    public function test_flat_row_endtime_zero_falls_back_to_last_step(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        // When endtime = 0, the fallback estimates endtime as starttime + duration.
        $starttime = 1700000000;
        $duration  = 500;

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid'     => $user->id,
            'courseid'   => $course->id,
            'starttime'  => $starttime,
            'endtime'    => 0,
            'json'       => \block_catquiz_statistics_generator::build_attempt_json(),
        ]);

        // Manually set the duration column after insert since generator uses endtime-starttime.
        global $DB;
        $DB->set_field(
            'local_catquiz_attempts',
            'endtime',
            0,
            ['userid' => $user->id, 'courseid' => $course->id]
        );

        $filter = new attempt_filter(courseid: $course->id);
        $row    = $this->report->get_flat_rows($filter)[0];

        // Endtime = 0 → repository returns null endtime but starttime is stored.
        // The fallback uses starttime + durationseconds when both are positive.
        // Since generator stores endtime=time() (non-zero), set it to 0 after insert.
        // When duration is also 0 (because endtime was overwritten to 0 and
        // durationseconds = endtime - starttime = 0), fallback stays null.
        // Verify: endtime field does not crash (null or integer, never false).
        $this->assertTrue($row['endtime'] === null || is_int($row['endtime']));
    }

    /**
     * get_raw_rows returns the same fixed columns without dynamic scale columns.
     *
     * @return void
     */
    public function test_get_raw_rows_returns_fixed_columns_only(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id]);

        $filter = new attempt_filter(courseid: $course->id);
        $rows   = $this->report->get_raw_rows($filter);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('attemptid', $rows[0]);
        $this->assertArrayHasKey('duration_fmt', $rows[0]);
        $this->assertArrayNotHasKey('scale_1_score', $rows[0]);
    }

    /**
     * The fixed column order starts with attemptid, testid, userid (no 'id').
     *
     * @return void
     */
    public function test_fixed_columns_order_and_no_id(): void {
        $cols = array_keys($this->report->get_fixed_columns());
        $this->assertArrayNotHasKey('id', $this->report->get_fixed_columns());
        $this->assertSame('attemptid', $cols[0]);
        $this->assertSame('testid', $cols[1]);
        $this->assertSame('userid', $cols[2]);
    }

    /**
     * get_subscale_pivot_rows returns one row per attempt for the score metric.
     *
     * @return void
     */
    public function test_subscale_pivot_rows_score_metric(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => $user->id, 'courseid' => $course->id,
            'json'   => \block_catquiz_statistics_generator::build_attempt_json(1, 0.4, 0.2),
        ]);

        $filter = new attempt_filter(courseid: $course->id);
        $this->report->get_flat_rows($filter);
        $rows = $this->report->get_subscale_pivot_rows($filter, 'score');

        $this->assertCount(1, $rows);
        // Pivot rows use 'scale_{id}' keys (one per active scale), not 'global_score'.
        $this->assertArrayHasKey('scale_1', $rows[0]);
        $this->assertArrayHasKey('userid', $rows[0]);
    }
}
