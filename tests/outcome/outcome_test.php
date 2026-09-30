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
 * PHPUnit tests for outcome definitions and adapters (Issue #6).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\outcome;

use block_catquiz_statistics\analytics\analytic_role;
use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\provider\outcome_provider;
use block_catquiz_statistics\import\csv_importer;
use block_catquiz_statistics\import\csv_table;
use block_catquiz_statistics\import\data_quality;
use block_catquiz_statistics\repository\evalmodel_repository;
use block_catquiz_statistics\repository\outcome_repository;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for outcome_repository, outcome_provider and outcome data quality.
 *
 * @covers \block_catquiz_statistics\repository\outcome_repository
 * @covers \block_catquiz_statistics\analytics\provider\outcome_provider
 * @covers \block_catquiz_statistics\import\data_quality::for_outcome
 */
final class outcome_test extends \advanced_testcase {
    /** @var \stdClass Course that configures the evaluation. */
    private \stdClass $course;

    /** @var \stdClass[] Learners A, B, C. */
    private array $u = [];

    /**
     * Course with three learners.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        foreach (['A', 'B', 'C'] as $k) {
            $this->u[$k] = $gen->create_and_enrol($this->course, 'student', ['idnumber' => $k]);
        }
    }

    /**
     * Manual exam grade item (0..100, pass 50) with grades for A (72) and B (40), a gradeless row for C.
     *
     * @param \stdClass|null $course Course (default: the evaluation course).
     * @return \grade_item
     */
    private function exam_item(?\stdClass $course = null): \grade_item {
        $course = $course ?? $this->course;
        $item = new \grade_item($this->getDataGenerator()->create_grade_item([
            'courseid' => $course->id, 'itemname' => 'Exam', 'grademin' => 0, 'grademax' => 100, 'gradepass' => 50,
        ]), false);
        $item->update_final_grade($this->u['A']->id, 72, 'test');
        $item->update_final_grade($this->u['B']->id, 40, 'test');
        $item->update_final_grade($this->u['C']->id, null, 'test');
        return $item;
    }

    /**
     * Observations of one definition keyed by learner letter.
     *
     * @param int $defid Definition id.
     * @return array letter => observation
     */
    private function by_user(int $defid): array {
        $def = (new outcome_repository())->get($defid);
        $result = [];
        foreach ((new outcome_provider())->for_definition($def) as $obs) {
            foreach ($this->u as $k => $user) {
                if ((int) $user->id === $obs->userid) {
                    $result[$k] = $obs;
                }
            }
        }
        return $result;
    }

    /**
     * A numeric gradebook item is read live; gradeless rows are "not graded", never zero.
     *
     * @return void
     */
    public function test_numeric_grade_item(): void {
        global $DB;
        $item = $this->exam_item();
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $id = (new outcome_repository())->create($ctx, 'exam', 'Exam grade', 'gradeitem', (int) $item->id, 'grade');
        $obs = $this->by_user($id);

        $this->assertEquals(72.0, $obs['A']->get_value());
        $this->assertEquals(40.0, $obs['B']->get_value());
        $this->assertSame('missing_notgraded', $obs['C']->status->value);
        $this->assertSame('outcome:' . $id, $obs['A']->variablekey);
        $this->assertSame(0, $DB->count_records('block_catquiz_statistics_observation'), 'No replication.');

        $service = analytics_query_service::create_default();
        $this->assertContains('outcomes', $service->get_provider_keys());
        $this->assertCount(3, $service->get_observations(new observation_query(variablekeys: ['outcome:*'])));
    }

    /**
     * pass/fail only with an explicit rule; threshold or the item's pass grade.
     *
     * @return void
     */
    public function test_passfail_requires_explicit_rule(): void {
        $item = $this->exam_item();
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $repo = new outcome_repository();
        try {
            $repo->create($ctx, 'pass', 'Passed', 'gradeitem', (int) $item->id, 'passfail');
            $this->fail('pass/fail without rule accepted.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('explicit rule', $e->getMessage());
        }

        $obs = $this->by_user($repo->create(
            $ctx,
            'pass',
            'Passed',
            'gradeitem',
            (int) $item->id,
            'passfail',
            ['usegradepass' => true]
        ));
        $this->assertTrue($obs['A']->get_value());
        $this->assertFalse($obs['B']->get_value());
        $this->assertNull($obs['C']->get_value());

        $obs = $this->by_user($repo->create(
            $ctx,
            'pass35',
            'Passed (35)',
            'gradeitem',
            (int) $item->id,
            'passfail',
            ['passthreshold' => 35]
        ));
        $this->assertTrue($obs['B']->get_value());
    }

    /**
     * Missing is never read as non-participation unless explicitly configured.
     *
     * @return void
     */
    public function test_missing_is_not_nonparticipation(): void {
        $gen = $this->getDataGenerator();
        $assign = $gen->create_module('assign', ['course' => $this->course->id]);
        $this->submit($assign->id, $this->u['A']->id, 'submitted', 1000);
        $this->submit($assign->id, $this->u['B']->id, 'new', 1100);
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $repo = new outcome_repository();

        $obs = $this->by_user($repo->create($ctx, 'sub', 'Submitted', 'activity', (int) $assign->cmid, 'submission'));
        $this->assertTrue($obs['A']->get_value());
        $this->assertSame('missing_norecord', $obs['B']->status->value, 'Draft is not a submission, but not "not participated".');
        $this->assertSame('missing_norecord', $obs['C']->status->value);

        // Explicitly configured participation indicator: absence counts as "false".
        $obs = $this->by_user($repo->create(
            $ctx,
            'part',
            'Participated',
            'activity',
            (int) $assign->cmid,
            'submission',
            ['absence' => 'false']
        ));
        $this->assertFalse($obs['C']->get_value());

        $obs = $this->by_user($repo->create(
            $ctx,
            'part2',
            'Participated',
            'activity',
            (int) $assign->cmid,
            'submission',
            ['absence' => 'notparticipated']
        ));
        $this->assertSame('missing_notparticipated', $obs['C']->status->value);
    }

    /**
     * An adaptivequiz attempt can be configured as participation indicator (date mode = time-to-event).
     *
     * @return void
     */
    public function test_attempt_indicator_and_date(): void {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $this->course->id]);
        $DB->insert_record('adaptivequiz_attempt', (object) ['instance' => $quiz->id, 'userid' => $this->u['A']->id,
            'uniqueid' => 1, 'attemptstate' => 'complete', 'attemptstopcriteria' => '', 'questionsattempted' => 3,
            'difficultysum' => 0, 'standarderror' => 0.5, 'measure' => 0, 'timecreated' => 900, 'timemodified' => 950]);
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $repo = new outcome_repository();

        $obs = $this->by_user($repo->create($ctx, 'test', 'Test taken', 'activity', (int) $quiz->cmid, 'attempt'));
        $this->assertTrue($obs['A']->get_value());
        $this->assertSame(950, $obs['A']->occurredat);

        $obs = $this->by_user($repo->create(
            $ctx,
            'testdate',
            'Test date',
            'activity',
            (int) $quiz->cmid,
            'attempt',
            ['valuemode' => 'date']
        ));
        $this->assertEquals(950.0, $obs['A']->get_value());
        $this->assertSame('datetime', $obs['A']->valuetype->value);

        $this->expectException(\coding_exception::class);
        $repo->create($ctx, 'bad', 'Bad', 'activity', (int) $quiz->cmid, 'submission');
    }

    /**
     * An outcome from another course keeps its origin; roles are model-specific.
     *
     * @return void
     */
    public function test_cross_course_provenance_and_roles(): void {
        $other = $this->getDataGenerator()->create_course();
        foreach ($this->u as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $other->id, 'student');
        }
        $item = $this->exam_item($other);
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $id = (new outcome_repository())->create($ctx, 'exam2', 'Exam (other course)', 'gradeitem', (int) $item->id, 'grade');
        $obs = $this->by_user($id);

        $this->assertSame((int) $other->id, $obs['A']->origincourseid);
        $this->assertSame((int) \context_course::instance($other->id)->id, $obs['A']->origincontextid);

        $models = new evalmodel_repository();
        $ma = $models->create_model($ctx, 'A');
        $mb = $models->create_model($ctx, 'B');
        $models->assign_role($ma, analytic_role::OUTCOME, 'outcome', 'outcome:' . $id);
        $models->assign_role($mb, analytic_role::PERFORMANCE, 'outcome', 'outcome:' . $id);
        $this->assertSame(analytic_role::OUTCOME, $models->get_role_of($ma, 'outcome', 'outcome:' . $id));
        $this->assertSame(analytic_role::PERFORMANCE, $models->get_role_of($mb, 'outcome', 'outcome:' . $id));
    }

    /**
     * External outcomes are imported through the shared CSV path, with measurement time.
     *
     * @return void
     */
    public function test_external_outcome_csv(): void {
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $table = csv_table::parse("idnumber,exam_participation,exam_grade,study_status,date\n"
            . "A,1,1.7,enrolled,2027-02-15\nB,0,,exmatriculated,2027-02-15\nC,1,5.0,enrolled,2027-02-16\n");
        $result = (new csv_importer())->import($ctx, $table, [
            'name' => 'Exam office', 'idcolumn' => 'idnumber', 'matchfield' => 'idnumber', 'timepoint' => 'S1',
            'measuredatcolumn' => 'date',
            'columns' => [
                'exam_participation' => ['datatype' => 'boolean', 'measurementlevel' => 'nominal', 'allowedvalues' => null],
                'exam_grade' => ['datatype' => 'numeric', 'measurementlevel' => 'interval',
                    'allowedvalues' => ['min' => 1, 'max' => 5]],
            ],
        ]);
        $this->assertSame(3, $result['variables']);
        $this->assertSame(1, $result['missing'], 'Missing grade of B stays missing.');

        $obs = analytics_query_service::create_default()->get_observations(
            new observation_query(userids: [$this->u['B']->id], variablekeys: ['var:*'])
        );
        $values = [];
        foreach ($obs as $o) {
            $values[$o->attributes['shortname']] = $o;
        }
        $this->assertFalse($values['exam_participation']->get_value());
        $this->assertSame('exmatriculated', $values['study_status']->get_value());
        $this->assertSame(strtotime('2027-02-15'), $values['study_status']->occurredat);
        $this->assertSame('S1', $values['exam_grade']->timepoint);
    }

    /**
     * Data quality per outcome reports availability, missing reasons and grade bounds.
     *
     * @return void
     */
    public function test_outcome_quality(): void {
        $item = $this->exam_item();
        $ctx = (int) \context_course::instance($this->course->id)->id;
        $def = (new outcome_repository())->get(
            (new outcome_repository())->create($ctx, 'exam', 'Exam', 'gradeitem', (int) $item->id, 'grade')
        );
        $q = (new data_quality())->for_outcome($def);

        $this->assertSame(3, $q['total']);
        $this->assertSame(2, $q['n']);
        $this->assertSame(['missing_notgraded' => 1], $q['missing']);
        $this->assertEquals(40.0, $q['min']);
        $this->assertEquals(72.0, $q['max']);
        $this->assertEquals(100.0, $q['grademax']);
        $this->assertEquals(50.0, $q['gradepass']);
    }

    /**
     * Store an assignment submission.
     *
     * @param int $assignid Assignment id.
     * @param int $userid User.
     * @param string $status Status.
     * @param int $time Time.
     * @return void
     */
    private function submit(int $assignid, int $userid, string $status, int $time): void {
        global $DB;
        $DB->insert_record('assign_submission', (object) ['assignment' => $assignid, 'userid' => $userid,
            'timecreated' => $time, 'timemodified' => $time, 'status' => $status, 'groupid' => 0,
            'attemptnumber' => 0, 'latest' => 1]);
    }
}
