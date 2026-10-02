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
 * PHPUnit tests for the evaluation-model engine (Issue #7).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\analytics\evaluation;

use block_catquiz_statistics\analytics\analytic_role;
use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_status;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\demo\cohort_generator;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\evalmodel_repository;
use block_catquiz_statistics\repository\milestone_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Tests for population, funnel, coverage, timeline, reliable change, versions and templates.
 *
 * @covers \block_catquiz_statistics\analytics\evaluation\evaluation_service
 * @covers \block_catquiz_statistics\analytics\evaluation\population
 * @covers \block_catquiz_statistics\analytics\evaluation\coverage_state
 * @covers \block_catquiz_statistics\analytics\evaluation\reliable_change
 * @covers \block_catquiz_statistics\analytics\evaluation\model_templates
 * @covers \block_catquiz_statistics\repository\evalmodel_repository
 */
final class evaluation_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var int Course context id. */
    private int $ctxid;

    /** @var \stdClass[] Learners 1..5. */
    private array $u = [];

    /** @var int Variable id of a boolean "exam taken". */
    private int $examvar;

    /** @var int Variable id of "degree". */
    private int $degreevar;

    /**
     * Five learners with a chain of milestones and a boolean outcome.
     *
     * Started: 1,2,3,4 · completed: 1,2,3 · feedback: 1,2 · exam: 1 yes, 2 no, 3 missing, 4/5 no record.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->ctxid = (int) \context_course::instance($this->course->id)->id;
        for ($i = 1; $i <= 5; $i++) {
            $this->u[$i] = $gen->create_and_enrol($this->course, 'student');
        }
        $ms = new milestone_repository();
        $chain = ['started' => [1, 2, 3, 4], 'completed' => [1, 2, 3], 'viewed' => [1, 2]];
        $map = ['started' => [semantic_action::STARTED, object_type::ASSESSMENT],
            'completed' => [semantic_action::COMPLETED, object_type::ASSESSMENT],
            'viewed' => [semantic_action::VIEWED, object_type::FEEDBACK]];
        foreach ($chain as $what => $users) {
            foreach ($users as $i) {
                $ms->record(
                    (int) $this->u[$i]->id,
                    $map[$what][0],
                    $map[$what][1],
                    'test',
                    'x',
                    "t:$what:$i",
                    $this->ctxid,
                    1000 + $i,
                    (int) $this->course->id
                );
            }
        }
        $datasets = new dataset_repository();
        $this->examvar = $datasets->ensure_variable($this->ctxid, 'exam', 'Exam taken', 'boolean', 'nominal');
        $this->degreevar = $datasets->ensure_variable($this->ctxid, 'degree', 'Degree', 'categorical', 'nominal');
        $this->obs(1, $this->examvar, value_type::BOOLEAN, ['valuebool' => true]);
        $this->obs(2, $this->examvar, value_type::BOOLEAN, ['valuebool' => false]);
        $this->obs(3, $this->examvar, value_type::BOOLEAN, ['status' => observation_status::MISSING_UNKNOWN]);
        foreach ([1 => 'MB', 2 => 'MB', 3 => 'ST', 4 => 'MB'] as $i => $deg) {
            $this->obs($i, $this->degreevar, value_type::CATEGORICAL, ['valuetext' => $deg]);
        }
    }

    /**
     * Store an observation for learner $i.
     *
     * @param int $i Learner.
     * @param int $varid Variable.
     * @param value_type $type Value type.
     * @param array $extra Named constructor args.
     * @return void
     */
    private function obs(int $i, int $varid, value_type $type, array $extra): void {
        (new observation_repository())->upsert(new observation(...array_merge([
            'userid' => (int) $this->u[$i]->id, 'variablekey' => 'var:' . $varid, 'sourcecomponent' => 'test',
            'sourcearea' => 'x', 'sourcekey' => "o:$varid:$i", 'origincontextid' => $this->ctxid, 'valuetype' => $type,
            'origincourseid' => (int) $this->course->id, 'occurredat' => 2000 + $i, 'variableid' => $varid,
        ], $extra)));
    }

    /**
     * Model with the given transitions and population.
     *
     * @param array $transitions Steps.
     * @param array $population Criteria.
     * @return evaluation_service
     */
    private function service(array $transitions, array $population = []): evaluation_service {
        $models = new evalmodel_repository();
        $id = $models->create_model($this->ctxid, 'M', ['config' => ['population' => $population, 'transitions' => $transitions]]);
        $models->assign_role($id, analytic_role::EXPOSURE, 'milestone', 'event:started:assessment', 'first');
        $models->assign_role($id, analytic_role::OUTCOME, 'variable', 'var:' . $this->examvar);
        $models->assign_role($id, analytic_role::COVARIATE, 'variable', 'var:' . $this->degreevar);
        return new evaluation_service($id, analytics_query_service::create_default());
    }

    /**
     * Population criteria report the N after each criterion.
     *
     * @return void
     */
    public function test_population_transparent_denominator(): void {
        $svc = $this->service([], [
            ['type' => 'enrolled'],
            ['type' => 'hasobservation', 'key' => 'var:' . $this->degreevar],
            ['type' => 'variablevalue', 'key' => 'var:' . $this->degreevar, 'value' => 'MB'],
        ]);
        $pop = $svc->get_population((int) $this->course->id);
        $this->assertSame([5, 4, 3], array_column($pop['steps'], 'n'));
        $this->assertEqualsCanonicalizing(
            [(int) $this->u[1]->id, (int) $this->u[2]->id, (int) $this->u[4]->id],
            $pop['userids']
        );
    }

    /**
     * Each rate uses the previous step as denominator; skipped steps simply are not configured.
     *
     * @return void
     */
    public function test_funnel_denominators_and_skipping(): void {
        $steps = [
            ['key' => 'event:started:assessment', 'occasion' => 'first', 'label' => 'Started'],
            ['key' => 'event:completed:assessment', 'occasion' => 'first', 'label' => 'Completed'],
            ['key' => 'event:viewed:feedback', 'occasion' => 'first', 'label' => 'Feedback'],
        ];
        $svc = $this->service($steps);
        $funnel = $svc->funnel($svc->get_population((int) $this->course->id)['userids']);
        $this->assertSame([5, 4, 3], array_column($funnel['steps'], 'eligible'));
        $this->assertSame([4, 3, 2], array_map(static fn($s) => $s['counts']['reached'], $funnel['steps']));
        $this->assertEqualsWithDelta(3 / 4, $funnel['steps'][1]['rate'], 1e-9);
        $this->assertEqualsWithDelta(2 / 5, $funnel['steps'][2]['ratepopulation'], 1e-9);
        $this->assertSame('previousstep', $funnel['steps'][1]['denominator']);

        $skip = $this->service([$steps[0], $steps[2]]);
        $f2 = $skip->funnel($skip->get_population((int) $this->course->id)['userids']);
        $this->assertCount(2, $f2['steps']);
        $this->assertSame(4, $f2['steps'][1]['eligible'], 'Feedback is evaluated directly among starters.');
    }

    /**
     * Not observed and not available are never counted as negative.
     *
     * @return void
     */
    public function test_not_observable_is_not_negative(): void {
        $svc = $this->service([['key' => 'var:' . $this->examvar, 'label' => 'Exam taken']]);
        $step = $svc->funnel($svc->get_population((int) $this->course->id)['userids'])['steps'][0];
        $this->assertSame(
            ['reached' => 1, 'negative' => 1, 'notapplicable' => 0, 'unavailable' => 1, 'notobserved' => 2],
            $step['counts']
        );
        $this->assertTrue(coverage_state::NEGATIVE->has_data());
        $this->assertFalse(coverage_state::NOT_OBSERVED->has_data());
    }

    /**
     * Cohort overview counts persons with data per role — no score.
     *
     * @return void
     */
    public function test_cohort_overview(): void {
        $svc = $this->service([]);
        $overview = $svc->cohort_overview($svc->get_population((int) $this->course->id)['userids']);
        $this->assertSame(5, $overview['population']);
        $this->assertSame(4, $overview['roles']['exposure']['n']);
        $this->assertSame(2, $overview['roles']['outcome']['n'], 'Yes and no count as data; missing does not.');
        $this->assertSame(4, $overview['roles']['covariate']['n']);
        $this->assertSame(0, $overview['roles']['performance']['n']);
        $this->assertArrayNotHasKey('score', $overview);
    }

    /**
     * The timeline is chronological across courses but only shows courses the viewer may see.
     *
     * @return void
     */
    public function test_timeline_cross_course_and_restricted(): void {
        $gen = $this->getDataGenerator();
        $other = $gen->create_course();
        $gen->enrol_user($this->u[1]->id, $other->id, 'student');
        (new milestone_repository())->record(
            (int) $this->u[1]->id,
            semantic_action::VIEWED,
            object_type::LEARNING_ACTIVITY,
            'test',
            'x',
            't:other',
            (int) \context_course::instance($other->id)->id,
            500,
            (int) $other->id
        );
        $svc = $this->service([]);

        $this->setAdminUser();
        $all = $svc->timeline((int) $this->u[1]->id);
        $times = array_map(static fn($o) => $o->get_sorttime(), $all);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame((int) $other->id, $all[0]->origincourseid);

        $teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $viewdetails = $this->getDataGenerator()->create_role();
        assign_capability('block/catquiz_statistics:viewdetails', CAP_ALLOW, $viewdetails, \context_system::instance());
        role_assign($viewdetails, $teacher->id, \context_course::instance($this->course->id));
        $mine = $svc->timeline((int) $this->u[1]->id, (int) $teacher->id);
        $this->assertNotEmpty($mine);
        foreach ($mine as $o) {
            $this->assertSame((int) $this->course->id, $o->origincourseid, 'No data from courses without permission.');
        }
    }

    /**
     * Reliable change: correct value, and a missing or zero SE is not computable (never 0).
     *
     * @return void
     */
    public function test_reliable_change(): void {
        $rc = reliable_change::compute(0.0, 0.3, 1.0, 0.4);
        $this->assertEqualsWithDelta(0.5, $rc['sediff'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $rc['rci'], 1e-9);
        $this->assertSame('improved', $rc['direction']);
        $this->assertEqualsWithDelta(1.0 - 1.96 * 0.5, $rc['cilow'], 1e-9);
        $this->assertStringContainsString('independent', $rc['assumption']);
        $this->assertSame('nochange', reliable_change::compute(0.0, 0.3, 0.5, 0.4)['direction']);

        foreach ([[0.0, null, 1.0, 0.4], [0.0, 0.0, 1.0, 0.4], [0.0, -1.0, 1.0, 0.4]] as $args) {
            $r = reliable_change::compute(...$args);
            $this->assertSame('notcomputable', $r['status']);
            $this->assertSame('missingse', $r['reason']);
            $this->assertNull($r['rci']);
        }
    }

    /**
     * Each model change creates a new version with a revision-safe snapshot; results reference the version.
     *
     * @return void
     */
    public function test_model_versions_referenced(): void {
        $models = new evalmodel_repository();
        $id = $models->create_model($this->ctxid, 'Versioned', ['config' => model_templates::config('prepost')]);
        $this->assertSame(1, $models->get_revision($id)['version']);
        $models->assign_role($id, analytic_role::OUTCOME, 'variable', 'var:' . $this->examvar);
        $models->update_config($id, model_templates::config('acceptance_use'));

        $svc = new evaluation_service($id, analytics_query_service::create_default());
        $ref = $svc->funnel([])['model'];
        $this->assertSame(3, $ref['version']);
        $this->assertSame('prepost', $models->get_revision($id, 1)['config']['template']);
        $this->assertSame([], $models->get_revision($id, 1)['roles']);
        $this->assertCount(1, $models->get_revision($id, 3)['roles']);
        $this->assertSame('acceptance_use', $models->get_revision($id, 3)['config']['template']);
    }

    /**
     * Templates are generic starting points; unknown templates are rejected.
     *
     * @return void
     */
    public function test_templates(): void {
        foreach (array_keys(model_templates::TEMPLATES) as $t) {
            $config = model_templates::config($t);
            $this->assertNotEmpty($config['transitions']);
            $json = json_encode($config);
            $this->assertStringNotContainsStringIgnoringCase('alise', $json);
            $this->assertStringNotContainsStringIgnoringCase('start.smart', $json);
        }
        $this->expectException(\coding_exception::class);
        model_templates::config('learningloop');
    }

    /**
     * End-to-end with the synthetic cohort: funnel, overview and reliable change on the demo model.
     *
     * @return void
     */
    public function test_demo_end_to_end(): void {
        global $DB;
        $this->setAdminUser();
        $demoid = (new cohort_generator())->generate(2026, 40, 'balanced');
        $courseid = (int) $DB->get_field(cohort_generator::TABLE, 'courseid', ['id' => $demoid]);
        $model = $DB->get_record('block_catquiz_statistics_evalmodel', ['issynthetic' => 1], '*', MUST_EXIST);
        $svc = new evaluation_service((int) $model->id, analytics_query_service::create_default());
        $users = $svc->get_population($courseid)['userids'];

        $this->assertCount(40, $users);
        $funnel = $svc->funnel($users);
        $this->assertCount(9, $funnel['steps']);
        $this->assertTrue($funnel['model']['issynthetic']);
        $last = end($funnel['steps']);
        $this->assertSame($last['eligible'], array_sum($last['counts']));

        $pairs = $svc->performance_pairs();
        $this->assertCount(1, $pairs);
        $rc = $svc->reliable_change($users, $pairs[0]);
        $this->assertSame(40, array_sum($rc['summary']));
        $this->assertSame(0, $rc['summary']['notcomputable'], 'Demo SEs are present.');
    }
}
