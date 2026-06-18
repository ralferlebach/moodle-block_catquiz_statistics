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
 * PHPUnit tests for attempt_repository.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\repository;

/**
 * Tests for attempt_repository.
 *
 * @covers \block_catquiz_statistics\repository\attempt_repository
 */
final class attempt_repository_test extends \advanced_testcase {
    /** @var attempt_repository|null Repository under test. */
    private ?attempt_repository $repo = null;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->repo = new attempt_repository();
    }

    /**
     * Release resources after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->repo = null;
        parent::tearDown();
    }

    /**
     * Schema check returns a boolean reflecting the install state.
     *
     * @return void
     */
    public function test_check_schema_compatibility_reflects_install_state(): void {
        $this->assertIsBool($this->repo->check_schema_compatibility());
    }

    /**
     * get_catquiz_instances_for_course returns empty array without data.
     *
     * @return void
     */
    public function test_get_catquiz_instances_for_course_returns_empty_without_data(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame([], $this->repo->get_catquiz_instances_for_course($course->id));
    }

    /**
     * get_attempts returns empty array when no attempts exist in course.
     *
     * @return void
     */
    public function test_get_attempts_returns_empty_without_data(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $filter = new attempt_filter(courseid: $course->id);
        $this->assertSame([], $this->repo->get_attempts($filter));
    }

    /**
     * get_attempts returns one DTO per inserted record.
     *
     * @return void
     */
    public function test_get_attempts_returns_dto_for_created_record(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id]);

        $filter = new attempt_filter(courseid: $course->id);
        $dtos = $this->repo->get_attempts($filter);

        $this->assertCount(1, $dtos, 'Expected exactly 1 DTO for 1 inserted attempt.');
        $this->assertSame((int) $user->id, $dtos[0]->userid, 'userid mismatch in DTO.');
        $this->assertSame((int) $course->id, $dtos[0]->courseid, 'courseid mismatch in DTO.');
    }

    /**
     * get_attempts correctly hydrates JSON fields into the DTO.
     *
     * @return void
     */
    public function test_get_attempts_hydrates_json_fields(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => $user->id,
            'courseid' => $course->id,
            'scaleid' => 1,
            'json' => \block_catquiz_statistics_generator::build_attempt_json(1, 0.75, 0.20),
        ]);

        $filter = new attempt_filter(courseid: $course->id);
        $dto = $this->repo->get_attempts($filter)[0];

        $this->assertSame(1, $dto->globalscaleid, 'globalscaleid should be 1.');
        $this->assertSame(1, $dto->testid, 'testid should be 1.');
        $this->assertArrayHasKey(1, $dto->personabilities, 'personabilities should have key 1.');
        $this->assertEqualsWithDelta(0.75, $dto->personabilities[1], 1e-9, 'PP should be 0.75.');
        $this->assertNotNull($dto->primaryscale, 'primaryscale should not be null.');
        $this->assertArrayHasKey(1, $dto->catscales, 'catscales should have key 1.');
    }

    /**
     * get_attempts filters correctly by instanceid.
     *
     * @return void
     */
    public function test_get_attempts_filters_by_instanceid(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id, 'instanceid' => 10]);
        $gen->create_catquiz_attempt(['userid' => $user->id, 'courseid' => $course->id, 'instanceid' => 20]);

        $filter = new attempt_filter(courseid: $course->id, instanceid: 10);
        $dtos = $this->repo->get_attempts($filter);

        $this->assertCount(1, $dtos, 'Filter instanceid=10 should return exactly 1 DTO.');
        $this->assertSame(10, $dtos[0]->instanceid, 'DTO instanceid should be 10.');
    }

    /**
     * SE values exceeding semax are set to null after validation.
     *
     * Creates a test environment with semax=0.30 and an attempt with SE=0.45
     * for scale 1. Expects scale 1 SE to be null in the hydrated DTO.
     *
     * @return void
     */
    public function test_get_attempts_nulls_se_above_semax(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_test(['componentid' => 5, 'courseid' => (int) $course->id, 'semax' => 0.30]);
        $gen->create_catquiz_attempt([
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'instanceid' => 5,
            'json' => \block_catquiz_statistics_generator::build_attempt_json(1, 0.5, 0.45),
        ]);

        $filter = new attempt_filter(courseid: (int) $course->id);
        $dtos = $this->repo->get_attempts($filter);

        $this->assertNotEmpty($dtos, 'Expected at least 1 DTO after creating an attempt.');
        $dto = $dtos[0];
        $this->assertArrayHasKey(1, $dto->se, 'se array should have key 1 after validation.');
        $this->assertNull($dto->se[1], 'SE=0.45 > semax=0.30 — expected null after validation.');
    }

    /**
     * SE values at or below semax remain as floats after validation.
     *
     * @return void
     */
    public function test_get_attempts_keeps_se_at_or_below_semax(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_test(['componentid' => 6, 'courseid' => (int) $course->id, 'semax' => 0.35]);
        $gen->create_catquiz_attempt([
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'instanceid' => 6,
            'json' => \block_catquiz_statistics_generator::build_attempt_json(1, 0.5, 0.35),
        ]);

        $filter = new attempt_filter(courseid: (int) $course->id);
        $dtos = $this->repo->get_attempts($filter);

        $this->assertNotEmpty($dtos, 'Expected at least 1 DTO.');
        $dto = $dtos[0];
        $this->assertArrayHasKey(1, $dto->se, 'se array should have key 1.');
        $this->assertNotNull($dto->se[1], 'SE=0.35 = semax=0.35 — expected valid (not null).');
        $this->assertEqualsWithDelta(0.35, $dto->se[1], 1e-9, 'SE value should remain 0.35.');
    }

    /**
     * get_catquiz_instances_for_course returns one entry per instanceid.
     *
     * Uses a LEFT JOIN against {adaptivequiz} so no adaptivequiz row is needed
     * for the attempt count to appear.  Orphaned attempts (adaptivequiz deleted)
     * still show up with an empty testname — this is intentional behaviour.
     *
     * @return void
     */
    public function test_get_catquiz_instances_for_course_returns_instances(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'instanceid' => 11,
            'attemptid' => 100,
        ]);
        $gen->create_catquiz_attempt([
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'instanceid' => 11,
            'attemptid' => 101,
        ]);

        $instances = $this->repo->get_catquiz_instances_for_course((int) $course->id);

        $this->assertNotEmpty($instances, 'Expected at least 1 instance entry.');
        $found = null;
        foreach ($instances as $inst) {
            if ((int) $inst->instanceid === 11) {
                $found = $inst;
                break;
            }
        }
        $this->assertNotNull($found, 'Expected to find instanceid=11 in the result.');
        $this->assertSame(2, (int) $found->attemptcount, 'Expected 2 attempts for instanceid=11.');
    }

    /**
     * get_catquiz_instances_for_course uses adaptivequiz.name as testname.
     *
     * Creates a real {adaptivequiz} row with a specific name and verifies that
     * get_catquiz_instances_for_course() returns that name — not the CAT
     * configuration template name from local_catquiz_tests.
     *
     * @return void
     */
    public function test_get_catquiz_instances_testname_comes_from_adaptivequiz(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');

        // The generator inserts a minimal {adaptivequiz} row.
        // If the Wunderbyte schema has extra NOT NULL columns without defaults,
        // the insert throws a dml_exception and we skip gracefully.
        try {
            $aqid = $gen->create_adaptivequiz_instance([
                'course' => (int) $course->id,
                'name' => 'Mathematik Sommersemester',
            ]);
        } catch (\dml_exception $e) {
            $this->markTestSkipped('Minimal adaptivequiz insert failed — schema may require extra fields: ' . $e->getMessage());
        }

        $gen->create_catquiz_attempt([
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'instanceid' => $aqid,
        ]);

        $instances = $this->repo->get_catquiz_instances_for_course((int) $course->id);

        $this->assertNotEmpty($instances);
        $found = null;
        foreach ($instances as $inst) {
            if ((int) $inst->instanceid === $aqid) {
                $found = $inst;
                break;
            }
        }
        $this->assertNotNull($found, "Expected instanceid={$aqid} in result.");
        $this->assertSame(
            'Mathematik Sommersemester',
            $found->testname,
            'testname must equal adaptivequiz.name, not the CAT template name.'
        );
    }

    /**
     * duration_seconds is computed from endtime minus starttime.
     *
     * @return void
     */
    public function test_get_attempts_computes_duration_seconds(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $start = mktime(9, 0, 0, 1, 10, 2025);

        /** @var \block_catquiz_statistics_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => (int) $user->id,
            'courseid' => (int) $course->id,
            'starttime' => $start,
            'endtime' => $start + 720,
        ]);

        $filter = new attempt_filter(courseid: (int) $course->id);
        $dtos = $this->repo->get_attempts($filter);

        $this->assertNotEmpty($dtos, 'Expected at least 1 DTO.');
        $this->assertEqualsWithDelta(720.0, $dtos[0]->durationseconds, 1e-9, 'Duration should be 720s.');
    }
}
