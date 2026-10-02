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
 * PHPUnit tests for research exports and pseudonymisation (Issue #8).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\research;

use block_catquiz_statistics\demo\cohort_generator;
use block_catquiz_statistics\repository\evalmodel_repository;
use block_catquiz_statistics\stats\design;
use block_catquiz_statistics\stats\linear_regression;

/**
 * Tests for pseudonymiser, dataset_builder and export_bundle.
 *
 * @covers \block_catquiz_statistics\research\pseudonymiser
 * @covers \block_catquiz_statistics\research\dataset_builder
 * @covers \block_catquiz_statistics\research\export_bundle
 */
final class research_export_test extends \advanced_testcase {
    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Stable pseudonyms within a scope; different scopes and export mode are not linkable.
     *
     * @return void
     */
    public function test_pseudonyms(): void {
        $a = new pseudonymiser('stable', 'model:1');
        $b = new pseudonymiser('stable', 'model:1');
        $c = new pseudonymiser('stable', 'model:2');
        $this->assertSame($a->subject(42), $b->subject(42));
        $this->assertNotSame($a->subject(42), $a->subject(43));
        $this->assertNotSame($a->subject(42), $c->subject(42));
        $this->assertMatchesRegularExpression('/^P[0-9a-f]{20}$/', $a->subject(42));
        $this->assertNotSame(substr(hash('sha256', '42'), 0, 20), substr($a->subject(42), 1), 'Not a plain hash.');

        $e1 = new pseudonymiser('export', 'model:1');
        $e2 = new pseudonymiser('export', 'model:1');
        $this->assertNotSame($e1->subject(42), $e2->subject(42), 'Independent exports are not linkable.');
        $this->assertSame($e1->subject(42), $e1->subject(42));
        $this->assertSame('42', (new pseudonymiser('identified', 'x'))->subject(42));
    }

    /**
     * Wide export follows the model mapping; long export carries provenance and roles; manifest is complete.
     *
     * @return void
     */
    public function test_bundle_from_demo_model(): void {
        global $DB;
        $demoid = (new cohort_generator())->generate(2026, 30, 'balanced');
        $courseid = (int) $DB->get_field(cohort_generator::TABLE, 'courseid', ['id' => $demoid]);
        $model = $DB->get_record('block_catquiz_statistics_evalmodel', ['issynthetic' => 1], '*', MUST_EXIST);
        $roles = (new evalmodel_repository())->get_roles((int) $model->id);

        $files = (new export_bundle())->build($courseid, (int) $model->id, 'stable');
        $this->assertEqualsCanonicalizing(
            ['data_long.csv', 'data_wide.csv', 'codebook.csv', 'manifest.json'],
            array_keys($files)
        );

        $wide = array_map('str_getcsv', explode("\n", trim($files['data_wide.csv'])));
        $this->assertCount(count($roles) + 1, $wide[0], 'One column per role mapping plus subject.');
        $this->assertCount(31, $wide, '30 persons + header.');
        $this->assertContains('demo_theta_tp_t0', $wide[0]);
        $this->assertContains('demo_theta_tp_t1', $wide[0]);
        foreach (array_slice($wide, 1) as $row) {
            $this->assertMatchesRegularExpression('/^P[0-9a-f]{20}$/', $row[0]);
        }

        $long = array_map('str_getcsv', explode("\n", trim($files['data_long.csv'])));
        $head = $long[0];
        foreach (
            ['subject', 'course', 'context', 'time', 'dataset', 'variable', 'construct', 'value', 'source',
                'analyticrole', 'provenance', 'status', 'synthetic'] as $col
        ) {
            $this->assertContains($col, $head);
        }
        $rolecol = array_search('analyticrole', $head);
        $this->assertNotEmpty(array_filter(array_column(array_slice($long, 1), $rolecol)));

        $manifest = json_decode($files['manifest.json'], true);
        $this->assertSame((int) $model->version, $manifest['evaluationmodel']['version']);
        $this->assertNotEmpty($manifest['modelsnapshot']['roles']);
        $this->assertNotEmpty($manifest['datasets']);
        $this->assertTrue($manifest['synthetic']);
        $this->assertSame('stable', $manifest['pseudonymisation']['mode']);
        $this->assertStringNotContainsString(get_config('block_catquiz_statistics', 'pseudonymsecret'), $files['manifest.json']);
        $this->assertStringContainsString('not anonymisation', $manifest['notice']);

        $codebook = array_map('str_getcsv', explode("\n", trim($files['codebook.csv'])));
        $this->assertCount(count($roles) + 1, $codebook);

        $this->assertFileExists(export_bundle::zip($files, 'export'));
    }

    /**
     * The wide dataset feeds the regression engine directly (outcome ~ predictors).
     *
     * @return void
     */
    public function test_wide_feeds_regression(): void {
        global $DB;
        $demoid = (new cohort_generator())->generate(2026, 60, 'balanced');
        $model = $DB->get_record('block_catquiz_statistics_evalmodel', ['issynthetic' => 1], '*', MUST_EXIST);
        $userids = array_map('intval', $DB->get_fieldset_select(cohort_generator::TABLE_USER, 'userid', 'demoid = ?', [$demoid]));
        $wide = (new dataset_builder((int) $model->id))->wide($userids, new pseudonymiser('export', 'x'));

        $fit = linear_regression::fit(design::build(array_values($wide['values']), 'demo_theta_tp_t1', ['demo_theta_tp_t0']));
        $this->assertGreaterThan(0.5, $fit['coefficients']['demo_theta_tp_t0']['b'], 'Simulated stability T0 -> T1 recovered.');
        $this->assertLessThan(60, $fit['n'], 'Only persons with both measurements (complete cases).');
    }
}
