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
 * PHPUnit tests for the CSV import (Issue #3).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\import;

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\provider\imported_observation_provider;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Tests for csv_table, value_parser and csv_importer.
 *
 * @covers \block_catquiz_statistics\import\csv_table
 * @covers \block_catquiz_statistics\import\value_parser
 * @covers \block_catquiz_statistics\import\csv_importer
 */
final class csv_import_test extends \advanced_testcase {
    /** @var int Course context id. */
    private int $ctxid;

    /** @var \stdClass[] Users by idnumber. */
    private array $users = [];

    /**
     * Course with three learners S1..S3.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->ctxid = (int) \context_course::instance($course->id)->id;
        foreach (['S1', 'S2', 'S3'] as $id) {
            $this->users[$id] = $gen->create_user(['idnumber' => $id]);
        }
    }

    /**
     * Base plan.
     *
     * @param array $extra Overrides.
     * @return array
     */
    private function plan(array $extra = []): array {
        return $extra + ['name' => 'MMQ', 'idcolumn' => 'idnumber', 'matchfield' => 'idnumber',
            'timepoint' => 'T0', 'missingcodes' => ['-99', 'NA']];
    }

    /**
     * Semicolon CSV with BOM, decimal comma and a Windows-1252 umlaut.
     *
     * @return void
     */
    public function test_parse_detects_delimiter_encoding(): void {
        $content = "\xEF\xBB\xBFidnumber;score;note\r\nS1;3,5;x\r\nS2;4;y\r\n";
        $table = csv_table::parse($content);
        $this->assertSame(';', $table->delimiter);
        $this->assertSame(['idnumber', 'score', 'note'], $table->header);
        $this->assertSame(3.5, value_parser::to_number($table->rows[0]['score']));

        $latin = csv_table::parse(mb_convert_encoding("idnumber,city\nS1,Köln\n", 'Windows-1252', 'UTF-8'));
        $this->assertSame('Windows-1252', $latin->encoding);
        $this->assertSame('Köln', $latin->rows[0]['city']);
    }

    /**
     * Type inference suggests numeric/categorical/ordinal and can be overridden.
     *
     * @return void
     */
    public function test_type_inference_and_override(): void {
        $this->assertSame('ordinal', value_parser::infer(['1', '4', '5', '-99'], ['-99'])['datatype']);
        $this->assertSame(['min' => 1.0, 'max' => 5.0], value_parser::infer(['1', '5'])['allowedvalues']);
        $this->assertSame('numeric', value_parser::infer(['1,5', '2.25', '3'])['datatype']);
        $ages = ['18', '19', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30'];
        $this->assertSame('integer', value_parser::infer($ages)['datatype']);
        $this->assertSame('categorical', value_parser::infer(['MB', 'ST', 'MB'])['datatype']);
        $this->assertSame('boolean', value_parser::infer(['ja', 'nein'])['datatype']);
        $this->assertSame('datetime', value_parser::infer(['2026-10-01', '2026-10-02'])['datatype']);

        $table = csv_table::parse("idnumber,semester\nS1,1\nS2,3\n");
        $preview = (new csv_importer())->preview($this->ctxid, $table, $this->plan([
            'columns' => ['semester' => ['datatype' => 'integer', 'measurementlevel' => 'interval', 'allowedvalues' => null]],
        ]));
        $this->assertSame('integer', $preview['variables']['semester']['datatype']);
        $this->assertSame('ordinal', $preview['variables']['semester']['inferred']['datatype']);
    }

    /**
     * A wide CSV is normalised into one observation per person x variable; roles are not assigned.
     *
     * @return void
     */
    public function test_wide_csv_normalised_without_roles(): void {
        global $DB;
        $table = csv_table::parse("idnumber,age,degree,MMQ_01,MMQ_02\nS1,19,MB,4,-99\nS2,21,ST,NA,2\nS3,,MB,5,9\n");
        $result = (new csv_importer())->import($this->ctxid, $table, $this->plan([
            'columns' => ['MMQ_02' => ['allowedvalues' => ['min' => 1, 'max' => 5]]],
        ]));

        $this->assertFalse($result['identical']);
        $this->assertSame(4, $result['variables']);
        $this->assertSame(12, $result['observed'] + $result['missing'] + $result['invalid']);
        $this->assertSame(3, $result['missing'], '-99, NA and the empty age cell.');
        $this->assertSame(1, $result['invalid'], 'MMQ_02 = 9 violates the range and is kept as invalid.');
        $this->assertSame(0, $DB->count_records('block_catquiz_statistics_evalrole'));
        $this->assertArrayNotHasKey('role', $DB->get_columns('block_catquiz_statistics_variable'));

        $s1 = (new imported_observation_provider())->get_observations(new observation_query(userids: [$this->users['S1']->id]));
        $this->assertCount(4, $s1);
        foreach ($s1 as $obs) {
            $this->assertSame('T0', $obs->timepoint);
            $this->assertSame($this->ctxid, $obs->origincontextid);
            $this->assertSame($result['datasetid'], $obs->datasetid);
        }
    }

    /**
     * Missing codes are never evaluated as real values.
     *
     * @return void
     */
    public function test_missing_codes_are_not_values(): void {
        $table = csv_table::parse("idnumber,MMQ_01\nS1,-99\nS2,NA\nS3,4\n");
        $result = (new csv_importer())->import($this->ctxid, $table, $this->plan());
        $quality = (new data_quality())->for_dataset($result['datasetid']);
        $q = reset($quality);

        $this->assertSame(1, $q['n']);
        $this->assertSame(2, $q['missing']);
        $this->assertEquals(4.0, $q['mean'], 'Missing codes must not enter the mean.');
        $this->assertEquals(66.7, $q['missingpct']);
    }

    /**
     * The same variable at two timepoints stays one variable with two occasions.
     *
     * @return void
     */
    public function test_multiple_timepoints_same_variable(): void {
        global $DB;
        $importer = new csv_importer();
        $importer->import($this->ctxid, csv_table::parse("idnumber,selfeff\nS1,3\n"), $this->plan(['timepoint' => 'T0']));
        $importer->import($this->ctxid, csv_table::parse("idnumber,selfeff\nS1,4\n"), $this->plan(['timepoint' => 'T1']));

        $this->assertSame(1, $DB->count_records('block_catquiz_statistics_variable', ['shortname' => 'selfeff']));
        $obs = (new imported_observation_provider())->get_observations(new observation_query(userids: [$this->users['S1']->id]));
        $this->assertEqualsCanonicalizing(['T0', 'T1'], array_map(static fn($o) => $o->timepoint, $obs));
        $this->assertCount(1, array_unique(array_map(static fn($o) => $o->variablekey, $obs)));
    }

    /**
     * Unresolved users are not imported; ambiguous identities stop the import unless explicitly skipped.
     *
     * @return void
     */
    public function test_unresolved_and_ambiguous(): void {
        global $DB;
        $this->getDataGenerator()->create_user(['idnumber' => 'DUP']);
        $this->getDataGenerator()->create_user(['idnumber' => 'DUP']);
        $table = csv_table::parse("idnumber,x\nS1,1\nNOPE,2\nDUP,3\n");
        $importer = new csv_importer();

        $preview = $importer->preview($this->ctxid, $table, $this->plan());
        $this->assertSame(['matched' => 1, 'unmatched' => 1, 'ambiguous' => 1], $preview['resolution']);
        $this->assertFalse($preview['canimport']);
        $unresolved = $importer->unresolved_csv($table, $this->plan());
        $this->assertStringContainsString('NOPE,2,unmatched', $unresolved);
        $this->assertStringContainsString('DUP,3,ambiguous', $unresolved);

        try {
            $importer->import($this->ctxid, $table, $this->plan());
            $this->fail('Ambiguous identities must stop the import.');
        } catch (\moodle_exception $e) {
            $this->assertSame('import:error:ambiguous', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(observation_repository::TABLE));

        $result = $importer->import($this->ctxid, $table, $this->plan(['skipambiguous' => true]));
        $this->assertSame(2, $result['skippedrows']);
        $this->assertSame(1, $DB->count_records(observation_repository::TABLE));
        $this->assertSame([(int) $this->users['S1']->id], array_map('intval', $DB->get_fieldset_select(
            observation_repository::TABLE,
            'DISTINCT userid',
            '1=1'
        )));
    }

    /**
     * An identical re-import returns the existing dataset; duplicate rows are not duplicated.
     *
     * @return void
     */
    public function test_identical_reimport_and_duplicate_rows(): void {
        global $DB;
        $importer = new csv_importer();
        $content = "idnumber,x\nS1,1\nS1,2\nS2,3\n";
        $first = $importer->import($this->ctxid, csv_table::parse($content), $this->plan());
        $this->assertSame(1, $first['duplicaterows']);
        $count = $DB->count_records(observation_repository::TABLE);

        $semicolon = csv_table::parse(str_replace(',', ';', $content));
        $again = $importer->import($this->ctxid, $semicolon, $this->plan(['name' => 'Other']));
        $this->assertTrue($again['identical']);
        $this->assertSame($first['datasetid'], $again['datasetid']);
        $this->assertSame($count, $DB->count_records(observation_repository::TABLE));
        $this->assertSame(1, $DB->count_records('block_catquiz_statistics_dataset'));
    }

    /**
     * A correction import supersedes the earlier version in all queries.
     *
     * @return void
     */
    public function test_correction_supersedes_version(): void {
        $importer = new csv_importer();
        $v1 = $importer->import($this->ctxid, csv_table::parse("idnumber,x\nS1,1\n"), $this->plan());
        $v2 = $importer->import(
            $this->ctxid,
            csv_table::parse("idnumber,x\nS1,2\n"),
            $this->plan(['versionof' => $v1['datasetid']])
        );

        $this->assertTrue((new dataset_repository())->is_superseded($v1['datasetid']));
        $this->assertSame(2, (int) (new dataset_repository())->get_dataset($v2['datasetid'])->version);
        $obs = analytics_query_service::create_default()->get_observations(
            new observation_query(userids: [$this->users['S1']->id], variablekeys: ['var:*'])
        );
        $this->assertCount(1, $obs);
        $this->assertEquals(2, $obs[0]->get_value());
    }
}
