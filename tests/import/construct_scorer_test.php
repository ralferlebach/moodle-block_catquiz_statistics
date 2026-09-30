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
 * PHPUnit tests for construct scoring (Issue #3).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\import;

use block_catquiz_statistics\repository\construct_repository;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Tests for construct_repository and construct_scorer.
 *
 * @covers \block_catquiz_statistics\repository\construct_repository
 * @covers \block_catquiz_statistics\import\construct_scorer
 * @covers \block_catquiz_statistics\import\data_quality
 */
final class construct_scorer_test extends \advanced_testcase {
    /**
     * Import a small MMQ subscale and define the construct.
     *
     * @param string $aggregation Aggregation.
     * @param int $minvalid Minimum valid items.
     * @param bool $reverse Reverse-code MMQ_03.
     * @return array [datasetid, constructid, users by idnumber]
     */
    private function setup_scale(string $aggregation = 'mean', int $minvalid = 2, bool $reverse = true): array {
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $ctxid = (int) \context_course::instance($course->id)->id;
        $users = [];
        foreach (['S1', 'S2'] as $id) {
            $users[$id] = $gen->create_user(['idnumber' => $id]);
        }
        $range = ['allowedvalues' => ['min' => 1, 'max' => 5]];
        $result = (new csv_importer())->import(
            $ctxid,
            csv_table::parse("idnumber,MMQ_01,MMQ_02,MMQ_03\nS1,4,5,2\nS2,3,-99,-99\n"),
            ['name' => 'MMQ', 'idcolumn' => 'idnumber', 'matchfield' => 'idnumber', 'timepoint' => 'T0',
                'missingcodes' => ['-99'], 'columns' => ['MMQ_01' => $range, 'MMQ_02' => $range, 'MMQ_03' => $range]]
        );
        global $DB;
        $vars = $DB->get_records(dataset_repository::TABLE_VARIABLE);
        $ids = [];
        foreach ($vars as $v) {
            $ids[$v->shortname] = (int) $v->id;
        }
        $constructs = new construct_repository();
        $cid = $constructs->create($ctxid, 'mmq_se', 'Self-efficacy', $aggregation, $minvalid, ['instrument' => 'MMQ']);
        $constructs->set_items($cid, [
            $ids['mmq_01'] => [],
            $ids['mmq_02'] => ['weight' => 2],
            $ids['mmq_03'] => ['reversecoded' => $reverse],
        ]);
        return [$result['datasetid'], $cid, $users];
    }

    /**
     * Score of a user.
     *
     * @param int $datasetid Dataset.
     * @param \stdClass $user User.
     * @return \block_catquiz_statistics\analytics\observation
     */
    private function score_of(int $datasetid, \stdClass $user) {
        foreach ((new observation_repository())->find_for_dataset($datasetid, null, true) as $obs) {
            if ($obs->userid === (int) $user->id) {
                return $obs;
            }
        }
        $this->fail('No score for user');
    }

    /**
     * Mean with reverse coding; minimum valid items yields MISSING_INSUFFICIENT; raw values untouched.
     *
     * @return void
     */
    public function test_mean_reverse_and_minvalid(): void {
        [$dsid, $cid, $users] = $this->setup_scale();
        $counts = (new construct_scorer())->score($cid, $dsid);

        $this->assertSame(['scored' => 1, 'insufficient' => 1], $counts);
        $s1 = $this->score_of($dsid, $users['S1']);
        // MMQ_03 = 2 reversed on 1..5 -> 4; mean(4, 5, 4) = 4.333...
        $this->assertEqualsWithDelta(13 / 3, $s1->get_value(), 1e-6);
        $this->assertSame('construct:' . $cid, $s1->variablekey);
        $this->assertSame(['mmq_03'], $s1->provenance['reversecoded']);
        $this->assertSame(3, $s1->provenance['validitems']);
        $this->assertSame(2, $s1->provenance['constructversion']);

        $s2 = $this->score_of($dsid, $users['S2']);
        $this->assertSame('missing_insufficient', $s2->status->value);
        $this->assertNull($s2->get_value());

        $raw = (new observation_repository())->find_for_dataset($dsid);
        $this->assertContains(2.0, array_map(static fn($o) => $o->valuenumeric, $raw), 'Raw value stays unreversed.');
    }

    /**
     * Prorated sum and weighted mean; rescoring replaces earlier scores and records the new version.
     *
     * @return void
     */
    public function test_sum_weighted_and_rescoring(): void {
        [$dsid, $cid, $users] = $this->setup_scale('sum', 1, false);
        $scorer = new construct_scorer();
        $scorer->score($cid, $dsid);
        $this->assertEqualsWithDelta(11.0, $this->score_of($dsid, $users['S1'])->get_value(), 1e-6);
        // S2: only MMQ_01 = 3 valid -> prorated 3 * 3 = 9.
        $this->assertEqualsWithDelta(9.0, $this->score_of($dsid, $users['S2'])->get_value(), 1e-6);

        (new construct_repository())->update_rule($cid, 'weightedmean', 1);
        $scorer->score($cid, $dsid);
        $s1 = $this->score_of($dsid, $users['S1']);
        // Weighted: (4*1 + 5*2 + 2*1) / 4 = 4.
        $this->assertEqualsWithDelta(4.0, $s1->get_value(), 1e-6);
        $this->assertSame(3, $s1->provenance['constructversion']);
        $this->assertCount(2, (new observation_repository())->find_for_dataset($dsid, null, true), 'No stale scores.');

        $quality = (new data_quality())->for_dataset($dsid);
        $this->assertArrayHasKey('construct:' . $cid, $quality);
        $this->assertSame(2, $quality['construct:' . $cid]['n']);
    }

    /**
     * Reverse coding without a numeric range and invalid rules are rejected.
     *
     * @return void
     */
    public function test_invalid_definitions_rejected(): void {
        $this->resetAfterTest(true);
        $ctx = \context_system::instance()->id;
        $varid = (new dataset_repository())->ensure_variable($ctx, 'free', 'Free', 'numeric', 'interval');
        $constructs = new construct_repository();
        $cid = $constructs->create($ctx, 'c', 'C');
        try {
            $constructs->set_items($cid, [$varid => ['reversecoded' => true]]);
            $this->fail('Reverse coding without range accepted.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Reverse coding requires', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        $constructs->create($ctx, 'd', 'D', 'median');
    }
}
