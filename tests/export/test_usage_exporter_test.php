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
 * Integration tests for the Modul B multi-sheet exporter (test_usage_exporter).
 *
 * These tests exercise the full export() path with a stub repository, capturing
 * the streamed XLSX bytes via an output buffer and re-opening the workbook to
 * assert sheet structure and cell contents.  This verifies the end-to-end
 * behaviour reported as a 404 in earlier development: that the inherited
 * protected write helpers resolve correctly and a valid workbook is produced.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;
use block_catquiz_statistics\report\test_usage_report;

/**
 * Tests for test_usage_exporter end-to-end workbook production.
 *
 * @covers \block_catquiz_statistics\export\test_usage_exporter
 */
final class test_usage_exporter_test extends \advanced_testcase {
    /**
     * Create a stub repository returning the given DTOs.
     *
     * @param attempt_data[] $dtos DTOs to return from get_attempts().
     * @return attempt_repository
     */
    private function make_repo(array $dtos): attempt_repository {
        return new class ($dtos) extends attempt_repository {
            /** @var attempt_data[] Pre-configured DTOs. */
            private array $dtos;
            /**
             * Constructor.
             * @param attempt_data[] $dtos DTOs to return.
             */
            public function __construct(array $dtos) {
                $this->dtos = $dtos;
            }
            /**
             * Return the pre-configured DTOs.
             * @param attempt_filter $filter Ignored.
             * @return attempt_data[]
             */
            public function get_attempts(attempt_filter $filter): array {
                return $this->dtos;
            }
        };
    }

    /**
     * Build an attempt DTO for one user × scale with given values.
     *
     * @param int $userid User ID.
     * @param int $globalscaleid Global scale ID.
     * @param int $starttime Start Unix timestamp.
     * @param float $pp Person ability.
     * @param float $se Standard error.
     * @param string $scalename Global scale name.
     * @return attempt_data
     */
    private function make_dto(
        int $userid,
        int $globalscaleid,
        int $starttime,
        float $pp,
        float $se,
        string $scalename = 'Simulation'
    ): attempt_data {
        $dto = new attempt_data();
        $dto->userid = $userid;
        $dto->username = 'user' . $userid;
        $dto->firstname = 'First' . $userid;
        $dto->lastname = 'Last' . $userid;
        $dto->email = 'user' . $userid . '@example.com';
        $dto->globalscaleid = $globalscaleid;
        $dto->starttime = $starttime;
        $dto->endtime = $starttime + 600;
        $dto->durationseconds = 600;
        $dto->attemptid = $starttime;
        $dto->testid = 7;
        $dto->teststrategy = 1;
        $dto->status = 0;
        $dto->totaltestitems = 20;
        $dto->usedtestitems = 10;
        $dto->personabilities = [$globalscaleid => $pp];
        $dto->se = [$globalscaleid => $se];
        $dto->primaryscale = (object) ['id' => $globalscaleid, 'name' => $scalename];
        $dto->catscales = [$globalscaleid => (object) ['name' => $scalename]];
        $dto->graphicalsummary = [];
        return $dto;
    }

    /**
     * Capture the streamed workbook bytes produced by export().
     *
     * @param test_usage_report $report Report providing the data.
     * @param attempt_filter $filter Query scope.
     * @param string $format 'excel' or 'ods'.
     * @return string Raw workbook bytes.
     */
    private function capture_export(
        test_usage_report $report,
        attempt_filter $filter,
        string $format
    ): string {
        // OpenSpout's openToBrowser() emits HTTP headers; under the PHPUnit CLI
        // SAPI these are silently discarded, but if a previous test already
        // produced output we cannot capture cleanly. Guard defensively.
        if (headers_sent()) {
            $this->markTestSkipped('Headers already sent; cannot capture export stream.');
        }
        $exporter = new test_usage_exporter();
        ob_start();
        $exporter->export($report, $filter, $format, 'testusage');
        return ob_get_clean();
    }

    /**
     * Open a captured XLSX byte stream and return [sheetnames, sheetrows].
     *
     * @param string $bytes Raw XLSX bytes.
     * @return array [string[] sheetnames, array<string,array>> rows-per-sheet]
     */
    private function read_xlsx(string $bytes): array {
        $tmp = tempnam(make_request_directory(), 'xlsx');
        file_put_contents($tmp, $bytes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'Exported file is not a valid ZIP/XLSX.');

        // Read workbook sheet names.
        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $sheetnames = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $sheetnames[] = (string) $sheet['name'];
        }

        // Read shared strings.
        $sst = [];
        $sstxml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sstxml !== false) {
            $sx = simplexml_load_string($sstxml);
            foreach ($sx->si as $si) {
                $sst[] = (string) $si->t;
            }
        }
        $zip->close();
        return [$sheetnames, $sst];
    }

    /**
     * Exporting usage data for one scale produces 4 sheets in the right order.
     *
     * @return void
     */
    public function test_export_single_scale_has_four_sheets(): void {
        $this->resetAfterTest(true);
        $dtos = [
            $this->make_dto(1, 1, 1000, 0.5, 0.3),
            $this->make_dto(1, 1, 1000 + 86400, 0.7, 0.25),
        ];
        $report = new test_usage_report($this->make_repo($dtos));
        $filter = new attempt_filter(courseid: 1);

        $bytes = $this->capture_export($report, $filter, 'excel');
        [$sheetnames] = $this->read_xlsx($bytes);

        // 1 Metadaten + 1 Testnutzung (gesamt) + 1 Skala + 1 Rohdaten.
        $this->assertCount(4, $sheetnames);
        $this->assertStringContainsString('Metadaten', $sheetnames[0]);
        $this->assertStringContainsString('gesamt', $sheetnames[1]);
        $this->assertStringContainsString('Skala 1)', $sheetnames[2]);
        $this->assertStringContainsString('Rohdaten', $sheetnames[3]);
    }

    /**
     * Two distinct global scales produce one scale sheet each (5 sheets total).
     *
     * @return void
     */
    public function test_export_two_scales_has_five_sheets(): void {
        $this->resetAfterTest(true);
        $dtos = [
            $this->make_dto(1, 1, 1000, 0.5, 0.3, 'Simulation'),
            $this->make_dto(1, 18, 2000, 0.4, 0.3, 'Lesen'),
        ];
        $report = new test_usage_report($this->make_repo($dtos));
        $filter = new attempt_filter(courseid: 1);

        $bytes = $this->capture_export($report, $filter, 'excel');
        [$sheetnames] = $this->read_xlsx($bytes);

        // 1 Metadaten + 1 gesamt + 2 Skala + 1 Rohdaten = 5.
        $this->assertCount(5, $sheetnames);
        $scalesheets = array_filter(
            $sheetnames,
            static fn($n) => strpos($n, 'Skala') !== false
        );
        $this->assertCount(2, $scalesheets);
    }

    /**
     * Scale sheet names carry a closing parenthesis.
     *
     * @return void
     */
    public function test_scale_sheet_name_has_closing_parenthesis(): void {
        $this->resetAfterTest(true);
        $dtos = [$this->make_dto(1, 1, 1000, 0.5, 0.3)];
        $report = new test_usage_report($this->make_repo($dtos));
        $filter = new attempt_filter(courseid: 1);

        $bytes = $this->capture_export($report, $filter, 'excel');
        [$sheetnames] = $this->read_xlsx($bytes);

        $scalesheet = null;
        foreach ($sheetnames as $name) {
            if (strpos($name, 'Skala') !== false) {
                $scalesheet = $name;
                break;
            }
        }
        $this->assertNotNull($scalesheet);
        $this->assertStringEndsWith(')', $scalesheet);
    }

    /**
     * The exported workbook is a non-empty, valid ZIP container.
     *
     * @return void
     */
    public function test_export_produces_valid_workbook(): void {
        $this->resetAfterTest(true);
        $dtos = [$this->make_dto(1, 1, 1000, 0.5, 0.3)];
        $report = new test_usage_report($this->make_repo($dtos));
        $filter = new attempt_filter(courseid: 1);

        $bytes = $this->capture_export($report, $filter, 'excel');
        $this->assertNotEmpty($bytes);
        // XLSX files are ZIP archives starting with the PK signature.
        $this->assertSame("PK", substr($bytes, 0, 2));
    }

    /**
     * Header labels for the summary sheet appear in the shared strings table.
     *
     * @return void
     */
    public function test_summary_headers_present_in_workbook(): void {
        $this->resetAfterTest(true);
        $dtos = [
            $this->make_dto(1, 1, 1000, 0.5, 0.3),
            $this->make_dto(1, 1, 1000 + 86400, 0.7, 0.25),
        ];
        $report = new test_usage_report($this->make_repo($dtos));
        $filter = new attempt_filter(courseid: 1);

        $bytes = $this->capture_export($report, $filter, 'excel');
        [, $sst] = $this->read_xlsx($bytes);

        // Spot-check that key muster column headers are present (language-independent).
        $plugin = 'block_catquiz_statistics';
        $this->assertContains(get_string('report:col_rci_start_end', $plugin), $sst);
        $this->assertContains(get_string('report:col_rci_min_max', $plugin), $sst);
        $this->assertContains(get_string('report:col_score_trend', $plugin), $sst);
    }
}
