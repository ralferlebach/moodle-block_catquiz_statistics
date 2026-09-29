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
 * PHPUnit tests for the semantic event layer (Issue #4).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\analytics\semantic;

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\semantic\adapter\attempt_completed_adapter;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\repository\milestone_repository;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/catquiz_statistics/tests/fixtures/result_page_viewed_fixture.php');

/**
 * Tests for adapters, registry and observer.
 *
 * @covers \block_catquiz_statistics\analytics\semantic\adapter_registry
 * @covers \block_catquiz_statistics\analytics\semantic\adapter\attempt_completed_adapter
 * @covers \block_catquiz_statistics\analytics\semantic\adapter\result_page_viewed_adapter
 * @covers \block_catquiz_statistics\analytics\semantic\adapter\feedbacktab_clicked_adapter
 * @covers \block_catquiz_statistics\observer
 * @covers \block_catquiz_statistics\analytics\semantic\semantic_label
 */
final class semantic_events_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass adaptivequiz module. */
    private \stdClass $quiz;

    /** @var \context_module Module context. */
    private \context_module $modctx;

    /** @var \stdClass Learner. */
    private \stdClass $user;

    /**
     * Set up a course with an adaptivequiz and a learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // Nicht-interne Observer laufen erst nach dem Commit; Rollback-Reset würde sie unterdrücken.
        $this->preventResetByRollback();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->quiz = $gen->create_module('adaptivequiz', ['course' => $this->course->id]);
        $this->modctx = \context_module::instance($this->quiz->cmid);
        $this->user = $gen->create_and_enrol($this->course, 'student');
    }

    /**
     * Trigger a result-page view for an attempt.
     *
     * @param int $attemptid adaptivequiz_attempt.id.
     * @return void
     */
    private function view_result(int $attemptid): void {
        \mod_adaptivequiz\event\result_page_viewed::create([
            'objectid' => $attemptid,
            'context' => $this->modctx,
            'userid' => $this->user->id,
            'relateduserid' => $this->user->id,
            'other' => ['instanceid' => $this->quiz->id, 'timefinished' => time(), 'resultstatus' => 1, 'resultvalid' => true],
        ])->trigger();
    }

    /**
     * Milestone rows of the learner.
     *
     * @return \stdClass[]
     */
    private function milestones(): array {
        global $DB;
        return array_values($DB->get_records(milestone_repository::TABLE, ['userid' => $this->user->id], 'id'));
    }

    /**
     * attempt_completed maps to COMPLETED + assessment (legacy and #122 payloads), but is not persisted.
     *
     * @return void
     */
    public function test_attempt_completed_mapping_not_persisted(): void {
        $adapter = new attempt_completed_adapter();
        $legacy = \local_catquiz\event\attempt_completed::create([
            'objectid' => 77,
            'context' => \context_system::instance(),
            'other' => ['attemptid' => 77, 'userid' => $this->user->id, 'instanceid' => $this->quiz->id,
                'catscaleid' => 1, 'component' => 'mod_adaptivequiz'],
        ]);
        $spec = $adapter->map($legacy);

        $this->assertSame(semantic_action::COMPLETED, $spec->action);
        $this->assertSame(object_type::ASSESSMENT, $spec->objecttype);
        $this->assertSame((int) $this->user->id, $spec->userid);
        $this->assertSame((int) $this->modctx->id, $spec->origincontextid, 'System context resolved to module context.');
        $this->assertSame((int) $this->course->id, $spec->origincourseid);
        $this->assertFalse($adapter->is_persisted());

        $new = \local_catquiz\event\attempt_completed::create([
            'objectid' => 5,
            'context' => $this->modctx,
            'other' => ['catattemptid' => 5, 'adaptiveattemptid' => 78, 'userid' => $this->user->id],
        ]);
        $this->assertSame('local_catquiz:attempt_completed:78', $adapter->map($new)->sourcekey);

        $legacy->trigger();
        $this->assertSame([], $this->milestones(), 'Canonical source exists; nothing is copied.');
    }

    /**
     * Result views are persisted once per attempt; repeated views keep firstoccurred and count up.
     *
     * @return void
     */
    public function test_result_page_viewed_first_last_count(): void {
        $this->view_result(42);
        $first = $this->milestones();
        $this->assertCount(1, $first);
        $this->assertSame('viewed', $first[0]->action);
        $this->assertSame('feedback', $first[0]->objecttype);
        $this->assertEquals($this->modctx->id, $first[0]->origincontextid);
        $this->assertEquals($this->course->id, $first[0]->origincourseid);
        $this->assertStringContainsString('"resultvalid":true', $first[0]->attributes);

        $this->view_result(42);
        $again = $this->milestones();
        $this->assertCount(1, $again, 'No duplicate first milestone.');
        $this->assertEquals(2, $again[0]->occurrences);
        $this->assertEquals($first[0]->firstoccurred, $again[0]->firstoccurred);

        $this->view_result(43);
        $this->assertCount(2, $this->milestones(), 'Other attempt, other milestone.');
    }

    /**
     * Completion and result view stay separate: completed without viewing yields no VIEWED milestone.
     *
     * @return void
     */
    public function test_completed_and_viewed_are_separate(): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('block_catquiz_statistics');
        $gen->create_catquiz_attempt([
            'userid' => $this->user->id, 'courseid' => $this->course->id, 'instanceid' => $this->quiz->id,
            'attemptid' => 501, 'starttime' => 1000, 'endtime' => 1600,
        ]);
        $service = analytics_query_service::create_default();
        $query = new observation_query(userids: [$this->user->id], variablekeys: ['event:*']);
        $keys = fn() => array_map(static fn($o) => $o->variablekey, $service->get_observations($query));

        $this->assertContains('event:completed:assessment', $keys());
        $this->assertNotContains('event:viewed:feedback', $keys());

        $this->view_result(501);
        $this->assertContains('event:completed:assessment', $keys());
        $this->assertContains('event:viewed:feedback', $keys());
    }

    /**
     * Feedback-tab clicks count only for the learner's own attempt.
     *
     * @return void
     */
    public function test_feedbacktab_clicked_only_for_learner(): void {
        $make = fn(string $role, string $tab) => \local_catquiz\event\feedbacktab_clicked::create([
            'context' => $this->modctx,
            'other' => ['attemptid' => 42, 'feedback' => $tab, 'feedback_translated' => 'x',
                'userid' => $this->user->id, 'role' => $role],
        ]);
        $make('teacher', 'customscalefeedback')->trigger();
        $this->assertSame([], $this->milestones());

        $make('student', 'customscalefeedback')->trigger();
        $make('student', 'customscalefeedback')->trigger();
        $make('student', 'learningprogress')->trigger();
        $rows = $this->milestones();
        $this->assertCount(2, $rows, 'One milestone per attempt and tab.');
        $this->assertSame('interacted', $rows[0]->action);
        $this->assertEquals(2, $rows[0]->occurrences);
    }

    /**
     * Unknown events are not interpreted; disabled adapters produce nothing.
     *
     * @return void
     */
    public function test_unknown_and_disabled(): void {
        $registry = adapter_registry::create_default();
        $this->assertSame([], $registry->get_adapters_for_event('\core\event\course_viewed'));
        \core\event\course_viewed::create(['context' => \context_course::instance($this->course->id)])->trigger();
        $this->assertSame([], $this->milestones());

        set_config('disabledadapters', 'adaptivequiz_result_page_viewed', 'block_catquiz_statistics');
        $this->view_result(42);
        $this->assertSame([], $this->milestones());
        $this->assertTrue($registry->claims_event('\mod_adaptivequiz\event\result_page_viewed'));
    }

    /**
     * Labels stay literal: no claim of reading, understanding or learning (EN and DE).
     *
     * @return void
     */
    public function test_labels_do_not_overinterpret(): void {
        $forbidden = '/\b(read|understood|understand|learned|learnt|gelesen|verstanden|gelernt|rezipiert)\b/iu';
        $sm = get_string_manager();
        foreach (['en', 'de'] as $lang) {
            foreach (semantic_action::cases() as $action) {
                $text = $sm->get_string('semantic:action:' . $action->value, 'block_catquiz_statistics', null, $lang);
                $this->assertDoesNotMatchRegularExpression($forbidden, $text, "$lang/$action->value");
            }
        }
        $this->assertSame('Feedback: opened', semantic_label::get(semantic_action::VIEWED, object_type::FEEDBACK));
        $this->assertSame('Feedback: opened', semantic_label::for_key('event:viewed:feedback'));
        $this->assertNull(semantic_label::for_key('catquiz:ability:1'));
    }
}
