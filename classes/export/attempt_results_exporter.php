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
 * Exporter for Test Results with 8-sheet multi-sheet XLSX/ODS output.
 *
 * Sheet order:
 *   1. attempts_raw     – fixed columns, no subscale expansion
 *   2. scale_summary    – aggregate descriptive stats per scale
 *   3. attempts_wide    – full flat/wide with all subscale columns
 *   4. subscale_scores  – pivot: attempt x scale, PP values
 *   5. subscale_se      – pivot: attempt x scale, SE values (null = invalid)
 *   6. subscale_n       – pivot: attempt x scale, item counts
 *   7. subscale_frac    – pivot: attempt x scale, fraction correct
 *   8. metadata         – export parameters and scale hierarchy
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\report\attempt_results_report;
use block_catquiz_statistics\report\report_interface;
use block_catquiz_statistics\repository\attempt_filter;

/**
 * Test Results exporter — overrides export_multisheet() with 8 named sheets.
 */
class attempt_results_exporter extends base_exporter {

    /**
     * Write 8 named sheets to the XLSX or ODS writer.
     *
     * Uses \core\dataformat::get_format_instance() (available since Moodle 4.3)
     * to obtain a writer that supports multi-sheet output. Sheets beyond the
     * configured maxsheets limit are silently omitted.
     *
     * Falls back to single-sheet export when $report is not an
     * attempt_results_report (e.g. future report modules).
     *
     * @param report_interface $report Report module providing the data.
     * @param attempt_filter $filter Query scope.
     * @param string $format 'excel' or 'ods'.
     * @param string $filename Download filename (without extension).
     * @return void
     */
    protected function export_multisheet(
        report_interface $report,
        attempt_filter $filter,
        string $format,
        string $filename
    ): void {
        if (!($report instanceof attempt_results_report)) {
            $this->export_single($report, $filter, $format, $filename);
            return;
        }

        $maxsheets = (int) get_config('block_catquiz_statistics', 'maxsheets') ?: 50;
        $plugin = 'block_catquiz_statistics';

        // Pre-fetch all data; the report caches DTOs internally.
        $widerows = $report->get_flat_rows($filter);
        $widecols = $report->get_columns();

        $writer = \core\dataformat::get_format_instance($format);
        $writer->set_filename($filename);
        $writer->start_output();

        $sheetnum = 0;

        // Sheet 1: attempts_raw.
        if ($sheetnum < $maxsheets) {
            $writer->set_sheettitle(get_string('report:sheet_attempts_raw', $plugin));
            $rawcols = $report->get_fixed_columns();
            $rawrows = $report->get_raw_rows($filter);
            $writer->start_sheet($rawcols);
            foreach ($rawrows as $i => $row) {
                $writer->write_record($row, $i);
            }
            $writer->close_sheet($rawcols);
            $sheetnum++;
        }

        // Sheet 2: scale_summary.
        if ($sheetnum < $maxsheets) {
            $writer->set_sheettitle(get_string('report:sheet_scale_summary', $plugin));
            $sumcols = $report->get_scale_summary_columns();
            $sumrows = $report->get_scale_summary_rows($filter);
            $writer->start_sheet($sumcols);
            foreach ($sumrows as $i => $row) {
                $writer->write_record($row, $i);
            }
            $writer->close_sheet($sumcols);
            $sheetnum++;
        }

        // Sheet 3: attempts_wide.
        if ($sheetnum < $maxsheets) {
            $writer->set_sheettitle(get_string('report:sheet_attempts_wide', $plugin));
            $writer->start_sheet($widecols);
            foreach ($widerows as $i => $row) {
                $writer->write_record($row, $i);
            }
            $writer->close_sheet($widecols);
            $sheetnum++;
        }

        // Sheets 4-7: subscale pivots.
        $pivots = [
            'pp'   => get_string('report:sheet_subscale_scores', $plugin),
            'se'   => get_string('report:sheet_subscale_se',     $plugin),
            'n'    => get_string('report:sheet_subscale_n',      $plugin),
            'frac' => get_string('report:sheet_subscale_frac',   $plugin),
        ];
        $pivcols = $report->get_subscale_pivot_columns();
        foreach ($pivots as $metric => $sheettitle) {
            if ($sheetnum >= $maxsheets) {
                break;
            }
            $writer->set_sheettitle($sheettitle);
            $pivrows = $report->get_subscale_pivot_rows($filter, $metric);
            $writer->start_sheet($pivcols);
            foreach ($pivrows as $i => $row) {
                $writer->write_record($row, $i);
            }
            $writer->close_sheet($pivcols);
            $sheetnum++;
        }

        // Sheet 8: metadata.
        if ($sheetnum < $maxsheets) {
            $writer->set_sheettitle(get_string('report:sheet_metadata', $plugin));
            [$metacols, $metarows] = $this->build_metadata($filter, $format, count($widerows));
            $writer->start_sheet($metacols);
            foreach ($metarows as $i => $row) {
                $writer->write_record($row, $i);
            }
            $writer->close_sheet($metacols);
        }

        $writer->close_output('download');
    }

    /**
     * Build metadata columns and rows describing this export.
     *
     * @param attempt_filter $filter Active query scope.
     * @param string $format Export format identifier.
     * @param int $attemptcount Number of attempt rows exported.
     * @return array Two-element array: [columns array, rows array].
     */
    private function build_metadata(attempt_filter $filter, string $format, int $attemptcount): array {
        $cols = [
            'key' => 'Key',
            'value' => 'Value',
        ];
        $rows = [
            ['key' => 'Plugin',       'value' => 'block_catquiz_statistics'],
            ['key' => 'Export date',  'value' => date('Y-m-d H:i:s')],
            ['key' => 'Format',       'value' => $format],
            ['key' => 'Course ID',    'value' => $filter->courseid],
            ['key' => 'Instance ID',  'value' => $filter->instanceid ?? '(all)'],
            ['key' => 'Scale ID',     'value' => $filter->scaleid ?? '(all)'],
            ['key' => 'Start filter', 'value' => $filter->starttime !== null
                ? date('Y-m-d H:i:s', $filter->starttime) : '(no filter)'],
            ['key' => 'End filter',   'value' => $filter->endtime !== null
                ? date('Y-m-d H:i:s', $filter->endtime) : '(no filter)'],
            ['key' => 'Attempts',     'value' => $attemptcount],
        ];
        return [$cols, $rows];
    }
}
