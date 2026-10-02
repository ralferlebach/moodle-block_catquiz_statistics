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
 * PHPUnit tests for the analysis runner and the path diagram (Issue #8).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\research;

use block_catquiz_statistics\demo\cohort_generator;

/**
 * Tests for analysis_runner and path_diagram on the synthetic cohort.
 *
 * @covers \block_catquiz_statistics\research\analysis_runner
 * @covers \block_catquiz_statistics\research\path_diagram
 */
final class analysis_runner_test extends \advanced_testcase {
    /** @var analysis_runner Runner on a demo cohort. */
    private analysis_runner $runner;

    /** @var array Columns. */
    private array $columns;

    /**
     * Demo cohort of 120 persons.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $demoid = (new cohort_generator())->generate(2026, 120, 'balanced');
        $model = $DB->get_record('block_catquiz_statistics_evalmodel', ['issynthetic' => 1], '*', MUST_EXIST);
        $users = array_map('intval', $DB->get_fieldset_select(cohort_generator::TABLE_USER, 'userid', 'demoid = ?', [$demoid]));
        $wide = (new dataset_builder((int) $model->id))->wide($users, new pseudonymiser('export', 'test'));
        $this->columns = $wide['columns'];
        $this->runner = new analysis_runner($wide['columns'], array_values($wide['values']));
    }

    /**
     * Descriptives cover every column; types are detected.
     *
     * @return void
     */
    public function test_describe_and_types(): void {
        $desc = $this->runner->describe();
        $this->assertCount(count($this->columns), $desc);
        $bycol = array_column($desc, null, 'column');
        $this->assertSame('categorical', $bycol['demo_degree']['kind']);
        $this->assertSame('numeric', $bycol['demo_theta_tp_t0']['kind']);
        $this->assertTrue($this->runner->is_binary('demo_exam_participation'));
        $this->assertFalse($this->runner->is_binary('demo_theta_tp_t0'));
        $this->assertSame(120, $bycol['demo_degree']['n'] + $bycol['demo_degree']['missing']);
    }

    /**
     * Linear regression with a categorical covariate and a nested sequence.
     *
     * @return void
     */
    public function test_linear_and_sequence(): void {
        $r = $this->runner->regression(
            'demo_theta_tp_t1',
            ['demo_theta_tp_t0', 'completed_learning_activity'],
            ['demo_degree'],
            'auto',
            true
        );
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame('linear', $r['type']);
        $this->assertArrayHasKey('demo_degreeST', $r['fit']['coefficients']);
        $this->assertCount(2, $r['sequence']['models']);
        $this->assertSame($r['sequence']['models'][0]['fit']['n'], $r['sequence']['models'][1]['fit']['n']);
        $this->assertGreaterThan(0, $r['sequence']['models'][1]['change']['deltar2']);
        $this->assertGreaterThan(0, $r['fit']['excluded'], 'Persons without re-test are excluded listwise.');
    }

    /**
     * Logistic regression is chosen automatically for a 0/1 outcome.
     *
     * @return void
     */
    public function test_logistic_auto(): void {
        $r = $this->runner->regression('demo_exam_participation', ['demo_selfefficacy']);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame('logistic', $r['type']);
        $this->assertArrayHasKey('or', $r['fit']['coefficients']['demo_selfefficacy']);
    }

    /**
     * Errors are returned as messages, never thrown.
     *
     * @return void
     */
    public function test_errors_are_messages(): void {
        $this->assertFalse($this->runner->regression('demo_degree', ['demo_theta_tp_t0'])['ok']);
        $this->assertFalse($this->runner->regression('demo_theta_tp_t1', [])['ok']);
        $this->assertFalse($this->runner->path("demo_theta_tp_t1 ~ demo_degree")['ok']);
        $this->assertFalse($this->runner->path("a ~ b\nb ~ a")['ok']);
        $cycle = $this->runner->path("demo_theta_tp_t1 ~ demo_exam_points\ndemo_exam_points ~ demo_theta_tp_t1");
        $this->assertFalse($cycle['ok']);
        $this->assertStringContainsString('cycle', $cycle['error']);
        $this->expectException(\moodle_exception::class);
        analysis_runner::parse_equations("y = a + b");
    }

    /**
     * Path model with mediation, bootstrap and an escaped SVG diagram.
     *
     * @return void
     */
    public function test_path_and_diagram(): void {
        $syntax = "# effect chain\ncompleted_learning_activity ~ demo_selfefficacy\n"
            . "demo_theta_tp_t1 ~ demo_theta_tp_t0 + completed_learning_activity\n"
            . "demo_exam_points ~ demo_theta_tp_t1";
        $this->assertSame(['completed_learning_activity' => ['demo_selfefficacy'],
            'demo_theta_tp_t1' => ['demo_theta_tp_t0', 'completed_learning_activity'],
            'demo_exam_points' => ['demo_theta_tp_t1']], analysis_runner::parse_equations($syntax));

        $r = $this->runner->path($syntax, 100, 7);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $eff = $r['result']['effects']['demo_selfefficacy->demo_exam_points'];
        $this->assertNotEquals(0.0, $eff['indirect']);
        $this->assertEqualsWithDelta($eff['direct'] + $eff['indirect'], $eff['total'], 1e-12);
        $this->assertSame(100, $eff['bootn']);

        $svg = path_diagram::svg($r['equations'], $r['result'], ['demo_exam_points' => 'Exam <points> & more']);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('Exam &lt;points&gt; &amp; more', $svg);
        $this->assertStringNotContainsString('<points>', $svg);
        $this->assertSame(4, substr_count($svg, 'marker-end="url(#la-arrow)"'), 'One arrow per path.');
        $this->assertSame(1, substr_count($svg, ' Q'), 'The layer-skipping edge is drawn as an arc.');
        $this->assertSame(4, substr_count($svg, 'class="la-edgelabel"'));
        $this->assertSame(5, substr_count($svg, '<rect'));
        $this->assertDoesNotMatchRegularExpression('/caus|verursach/i', $svg);
    }
}
