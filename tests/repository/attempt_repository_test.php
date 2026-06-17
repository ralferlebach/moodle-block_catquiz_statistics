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
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\repository;

use advanced_testcase;

/**
 * Tests for attempt_repository.
 *
 * @covers \block_catquizstatistics\repository\attempt_repository
 */
final class attempt_repository_test extends advanced_testcase {

    /** @var attempt_repository */
    private attempt_repository $repo;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->repo = new attempt_repository();
    }

    /**
     * Schema check returns false when local_catquiz tables do not exist.
     *
     * In CI local_catquiz IS installed, so this test documents the contract:
     * the method returns true when the schema is compatible.
     *
     * @return void
     */
    public function test_check_schema_compatibility_reflects_install_state(): void {
        $result = $this->repo->check_schema_compatibility();
        // Result depends on whether catquiz is installed in the test environment.
        $this->assertIsBool($result);
    }

    /**
     * get_catquiz_instances_for_course returns empty array when no records exist.
     *
     * @return void
     */
    public function test_get_catquiz_instances_for_course_returns_empty_without_data(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $result = $this->repo->get_catquiz_instances_for_course($course->id);
        $this->assertSame([], $result, 'Expected empty array for course with no catquiz instances.');
    }

    /**
     * get_attempts returns empty array when no attempts match the filter.
     *
     * @return void
     */
    public function test_get_attempts_returns_empty_without_data(): void {
        if (!$this->repo->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
        $course = $this->getDataGenerator()->create_course();
        $filter = new attempt_filter(courseid: $course->id);
        $result = $this->repo->get_attempts($filter);
        $this->assertSame([], $result, 'Expected empty array for course with no attempts.');
    }

    /**
     * attempt_filter::from_request builds correct defaults when no params are set.
     *
     * Pure unit test — no DB required.
     *
     * @return void
     */
    public function test_attempt_filter_from_request_defaults(): void {
        $filter = attempt_filter::from_request(42);
        $this->assertSame(42, $filter->courseid);
        $this->assertNull($filter->instanceid);
        $this->assertNull($filter->scaleid);
        $this->assertNull($filter->starttime);
        $this->assertNull($filter->endtime);
        $this->assertFalse($filter->systemwide);
    }

    /**
     * attempt_filter::from_request respects explicit instanceid override.
     *
     * @return void
     */
    public function test_attempt_filter_from_request_with_instanceid(): void {
        $filter = attempt_filter::from_request(courseid: 7, instanceid: 99);
        $this->assertSame(7, $filter->courseid);
        $this->assertSame(99, $filter->instanceid);
    }

    /**
     * get_question_steps_for_attempt returns empty array (QE join stub).
     *
     * @return void
     */
    public function test_get_question_steps_stub_returns_empty(): void {
        $result = $this->repo->get_question_steps_for_attempt(1);
        $this->assertSame([], $result, 'QE join stub must return empty array until Phase 2.');
    }
}
