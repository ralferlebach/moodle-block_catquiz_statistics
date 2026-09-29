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
 * PHPUnit tests for advanced-mode event mappings (Issue #4).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\task;

use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\repository\eventmap_repository;
use block_catquiz_statistics\repository\milestone_repository;

/**
 * Tests for eventmap_repository and materialise_custom_milestones.
 *
 * @covers \block_catquiz_statistics\repository\eventmap_repository
 * @covers \block_catquiz_statistics\task\materialise_custom_milestones
 */
final class materialise_custom_milestones_test extends \advanced_testcase {
    /**
     * Set up with a synchronously writing standard log store.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * Trigger a course view by a user.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $user User.
     * @return void
     */
    private function view_course(\stdClass $course, \stdClass $user): void {
        $this->setUser($user);
        \core\event\course_viewed::create(['context' => \context_course::instance($course->id)])->trigger();
        $this->setUser(null);
    }

    /**
     * Mapped events are materialised incrementally and idempotently.
     *
     * @return void
     */
    public function test_incremental_and_idempotent(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_and_enrol($course, 'student');
        $mapid = (new eventmap_repository())->create(
            '\core\event\course_viewed',
            semantic_action::VIEWED,
            object_type::LEARNING_RESOURCE,
            (int) $course->id
        );
        $this->view_course($course, $user);
        $this->view_course($course, $user);

        $task = new materialise_custom_milestones();
        $task->execute();
        $this->assertSame(0, $DB->count_records(milestone_repository::TABLE), 'Disabled unless advanced mapping is on.');

        set_config('enableadvancedmapping', 1, 'block_catquiz_statistics');
        $task->execute();
        $row = $DB->get_record(milestone_repository::TABLE, ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame('viewed', $row->action);
        $this->assertSame('learning_resource', $row->objecttype);
        $this->assertEquals(2, $row->occurrences);
        $this->assertEquals(\context_course::instance($course->id)->id, $row->origincontextid);

        $task->execute();
        $this->assertEquals(2, $DB->get_field(milestone_repository::TABLE, 'occurrences', ['id' => $row->id]), 'Idempotent.');

        $this->view_course($course, $user);
        $task->execute();
        $this->assertEquals(3, $DB->get_field(milestone_repository::TABLE, 'occurrences', ['id' => $row->id]));

        (new eventmap_repository())->set_enabled($mapid, false);
        $this->view_course($course, $user);
        $task->execute();
        $this->assertEquals(3, $DB->get_field(milestone_repository::TABLE, 'occurrences', ['id' => $row->id]));
    }

    /**
     * The course filter restricts materialisation to one course.
     *
     * @return void
     */
    public function test_course_filter(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $coursea->id);
        $gen->enrol_user($user->id, $courseb->id);
        (new eventmap_repository())->create(
            '\core\event\course_viewed',
            semantic_action::VIEWED,
            object_type::LEARNING_RESOURCE,
            (int) $coursea->id
        );
        set_config('enableadvancedmapping', 1, 'block_catquiz_statistics');
        $this->view_course($coursea, $user);
        $this->view_course($courseb, $user);

        (new materialise_custom_milestones())->execute();

        $rows = $DB->get_records(milestone_repository::TABLE);
        $this->assertCount(1, $rows);
        $this->assertEquals($coursea->id, reset($rows)->origincourseid);
    }

    /**
     * Events claimed by a standard adapter and non-event classes cannot be mapped.
     *
     * @return void
     */
    public function test_invalid_mappings_rejected(): void {
        $repo = new eventmap_repository();
        foreach (['\local_catquiz\event\feedbacktab_clicked', '\core\output\html_writer', '\nonexistent\event\x'] as $event) {
            try {
                $repo->create($event, semantic_action::VIEWED, object_type::FEEDBACK);
                $this->fail('Accepted invalid mapping for ' . $event);
            } catch (\coding_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
