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
 * Multi-sheet Excel/ODS exporter for Modul B (Testnutzung / Test Usage).
 *
 * Sheet layout:
 *   1. Metadaten      — export context, filter, scale hierarchy, sheet index
 *   2. Testnutzung (gesamt) — one row per user × global scale, aggregated
 *   3. Testnutzung (Skala N) — one sheet per distinct global scale ID,
 *                              all attempts for users on that scale
 *   4. Versuche (Rohdaten)  — all individual attempts, flat
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\report\report_interface;
use block_catquiz_statistics\report\test_usage_report;
use block_catquiz_statistics\repository\attempt_filter;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use OpenSpout\Writer\ODS\Writer as ODSWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Exporter for Modul B — Testnutzung (Test Usage).
 */
class test_usage_exporter extends base_exporter {
    /**
     * Produce a multi-sheet workbook for Modul B.
     *
     * Falls back to single-sheet export for CSV/JSON.
     *
     * @param report_interface $report Must be a test_usage_report instance.
     * @param attempt_filter $filter Query scope.
     * @param string $format Export format: 'excel', 'ods', 'csv', 'json'.
     * @param string $filename Filename without extension.
     * @return void
     */
    protected function export_multisheet(
        report_interface $report,
        attempt_filter $filter,
        string $format,
        string $filename
    ): void {
        if (!($report instanceof test_usage_report) || !in_array($format, ['excel', 'ods'], true)) {
            $this->export_single($report, $filter, $format, $filename);
            return;
        }

        \core_php_time_limit::raise();
        \core\session\manager::write_close();

        $plugin = 'block_catquiz_statistics';
        $maxsheets = (int) get_config('block_catquiz_statistics', 'maxsheets') ?: 50;

        $headerstyle = (new Style())
            ->setFontBold()
            ->setFontSize(12)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('4472C4');

        $metasectionstyle = (new Style())
            ->setFontBold()
            ->setFontSize(12)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('244062');

        $ext = ($format === 'ods') ? '.ods' : '.xlsx';
        if ($format === 'ods') {
            $writer = new ODSWriter();
        } else {
            $writer = new XLSXWriter();
            if (method_exists($writer->getOptions(), 'setDefaultColumnWidth')) {
                $writer->getOptions()->setDefaultColumnWidth(14.0);
            }
        }

        if (method_exists($writer->getOptions(), 'setTempFolder')) {
            $writer->getOptions()->setTempFolder(make_request_directory());
        }
        $writer->openToBrowser($filename . $ext);

        $sheetnum = 0;

        // Fetch all data once.
        $flatrows = $report->get_flat_rows($filter);
        $summaryrows = $report->get_summary_rows($filter);

        // Collect distinct global scale IDs from summary rows (ordered by first appearance).
        $globalscaleids = [];
        $globalscalenames = [];
        foreach ($summaryrows as $row) {
            $sid = $row['global_scale_id'] ?? null;
            if ($sid !== null && !isset($globalscaleids[$sid])) {
                $globalscaleids[$sid] = $sid;
                $globalscalenames[$sid] = $row['global_scale_name'] ?? ('Skala ' . $sid);
            }
        }

        // Build dynamic sheet list for metadata.
        $sheetlist = [];
        $sheetlist[get_string('report:sheet_usage_metadata', $plugin)]
            = get_string('report:meta_sheet_metadata', $plugin);
        $sheetlist[get_string('report:sheet_usage_summary', $plugin)]
            = get_string('report:meta_sheet_usage_summary', $plugin);
        foreach ($globalscaleids as $sid) {
            $sheetname = get_string('report:sheet_usage_scale', $plugin) . ' ' . $sid . ')';
            $sheetlist[$sheetname] = ($globalscalenames[$sid] ?? 'Skala ' . $sid);
        }
        $sheetlist[get_string('report:sheet_usage_raw', $plugin)]
            = get_string('report:meta_sheet_usage_raw', $plugin);

        // Sheet 1: Metadaten.
        if ($sheetnum < $maxsheets) {
            [$metacols, $metarows] = $this->build_usage_metadata(
                $filter,
                $format,
                count($flatrows),
                $sheetlist
            );
            $this->write_meta_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_usage_metadata', $plugin),
                $metacols,
                $metarows,
                $headerstyle,
                $metasectionstyle
            );
        }

        // Sheet 2: Testnutzung (gesamt).
        if ($sheetnum < $maxsheets) {
            $this->write_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_usage_summary', $plugin),
                $this->summary_cols($plugin),
                $summaryrows,
                $headerstyle
            );
        }

        // Sheets 3 to N: one sheet per global scale — same structure as gesamt, filtered.
        foreach ($globalscaleids as $sid) {
            if ($sheetnum >= $maxsheets) {
                break;
            }
            $scalerows = array_values(array_filter(
                $summaryrows,
                static fn($r) => ($r['global_scale_id'] ?? null) === $sid
            ));
            $this->write_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_usage_scale', $plugin) . ' ' . $sid . ')',
                $this->scale_cols($plugin),
                $scalerows,
                $headerstyle
            );
        }

        // Final sheet: Versuche (Rohdaten).
        if ($sheetnum < $maxsheets) {
            $this->write_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_usage_raw', $plugin),
                $this->raw_cols($plugin),
                $flatrows,
                $headerstyle
            );
        }

        $writer->close();
    }

    /**
     * Column definitions for the summary sheet (one row per user × global scale).
     *
     * @param string $plugin Plugin component string.
     * @return array<string,string>
     */
    private function summary_cols(string $plugin): array {
        return [
            'userid'            => get_string('report:col_userid', $plugin),
            'global_scale_id'   => get_string('report:col_global_scale_id', $plugin),
            'username'          => get_string('report:col_username', $plugin),
            'firstname'         => get_string('report:col_firstname', $plugin),
            'lastname'          => get_string('report:col_lastname', $plugin),
            'email'             => get_string('report:col_email', $plugin),
            'n_valid'           => get_string('report:col_n_valid', $plugin),
            'n_attempts'        => get_string('report:col_n_attempts', $plugin),
            'first_starttime'   => get_string('report:col_first_starttime', $plugin),
            'last_starttime'    => get_string('report:col_last_starttime', $plugin),
            'total_items'       => get_string('report:col_total_items', $plugin),
            'items_per_attempt' => get_string('report:col_items_per_attempt', $plugin),
            'first_score'       => get_string('report:col_first_score', $plugin),
            'last_score'        => get_string('report:col_last_score', $plugin),
            'worst_score'       => get_string('report:col_worst_score', $plugin),
            'best_score'        => get_string('report:col_best_score', $plugin),
            'score_trend'       => get_string('report:col_score_trend', $plugin),
            'trend_start_end'   => get_string('report:col_trend_start_end', $plugin),
            'trend_min_max'     => get_string('report:col_trend_min_max', $plugin),
            'rci_start_end'     => get_string('report:col_rci_start_end', $plugin),
            'rci_min_max'       => get_string('report:col_rci_min_max', $plugin),
        ];
    }

    /**
     * Column definitions for a per-scale sheet.
     *
     * Per-scale sheets have the same 21-column structure as the summary sheet;
     * rows are filtered to the scale but the layout is identical.
     *
     * @param string $plugin Plugin component string.
     * @return array<string,string>
     */
    private function scale_cols(string $plugin): array {
        return $this->summary_cols($plugin);
    }

    /**
     * Column definitions for the raw attempts sheet.
     *
     * @param string $plugin Plugin component string.
     * @return array<string,string>
     */
    private function raw_cols(string $plugin): array {
        return [
            'userid'            => get_string('report:col_userid', $plugin),
            'global_scale_id'   => get_string('report:col_global_scale_id', $plugin),
            'username'          => get_string('report:col_username', $plugin),
            'firstname'         => get_string('report:col_firstname', $plugin),
            'lastname'          => get_string('report:col_lastname', $plugin),
            'email'             => get_string('report:col_email', $plugin),
            'testid'            => get_string('report:col_testid', $plugin),
            'attemptid'         => get_string('report:col_attemptid', $plugin),
            'starttime'         => get_string('report:col_starttime', $plugin),
            'endtime'           => get_string('report:col_endtime', $plugin),
            'duration_s'        => get_string('report:col_duration_s', $plugin),
            'teststrategy'      => get_string('report:col_teststrategy', $plugin),
            'status'            => get_string('report:col_status', $plugin),
            'total_testitems'   => get_string('report:col_total_testitems', $plugin),
            'used_testitems'    => get_string('report:col_used_testitems', $plugin),
            'globalscale_name'  => get_string('report:col_global_scale_name', $plugin),
            'global_score'      => get_string('report:col_global_score', $plugin),
            'global_se'         => get_string('report:col_global_se', $plugin),
            'result_scale_id'   => get_string('report:col_result_scale_id', $plugin),
            'result_scale_name' => get_string('report:col_result_scale_name', $plugin),
            'result_score'      => get_string('report:col_result_score', $plugin),
            'result_se'         => get_string('report:col_result_se', $plugin),
        ];
    }

    /**
     * Build metadata sheet rows for the usage export.
     *
     * @param attempt_filter $filter Active filter.
     * @param string $format Export format.
     * @param int $attemptcount Number of flat attempt rows.
     * @param array $sheetlist Ordered map of sheetname => description.
     * @return array Two-element array: [cols, rows].
     */
    private function build_usage_metadata(
        attempt_filter $filter,
        string $format,
        int $attemptcount,
        array $sheetlist
    ): array {
        global $DB, $CFG;

        $plugin = 'block_catquiz_statistics';
        $none = get_string('report:filter_none', $plugin);
        $all = get_string('report:filter_all', $plugin);

        $cols = [
            'section' => get_string('report:meta_section', $plugin),
            'key'     => get_string('report:meta_key', $plugin),
            'value'   => get_string('report:meta_value', $plugin),
        ];

        $pluginversion = get_config('block_catquiz_statistics', 'version');
        $moodleversion = $CFG->release ?? (string) ($CFG->version ?? '');
        $coursename = $filter->courseid
            ? ($DB->get_field('course', 'fullname', ['id' => $filter->courseid]) ?? '') : '';

        $filterinstanceid = $filter->instanceid ?? $all;
        $filterstarttime = $filter->starttime !== null
            ? date('Y-m-d H:i:s', $filter->starttime) : $none;
        $filterendtime = $filter->endtime !== null
            ? date('Y-m-d H:i:s', $filter->endtime) : $none;

        $rows = [];

        // Section: Export-Informationen.
        $rows[] = ['section' => get_string('report:meta_s_export', $plugin), 'key' => '', 'value' => ''];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_plugin', $plugin),
            'value' => 'block_catquiz_statistics v' . ($pluginversion ?? '?')];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_datetime', $plugin),
            'value' => date('Y-m-d H:i:s')];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_moodle', $plugin),
            'value' => $moodleversion];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_format', $plugin),
            'value' => $format];

        // Section: Kurs & Test.
        $rows[] = ['section' => get_string('report:meta_s_course', $plugin), 'key' => '', 'value' => ''];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_coursename', $plugin),
            'value' => $coursename];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_courseid', $plugin),
            'value' => $filter->courseid];

        // Section: Filter.
        $rows[] = ['section' => get_string('report:meta_s_filter', $plugin), 'key' => '', 'value' => ''];
        $rows[] = ['section' => '', 'key' => 'instanceid', 'value' => $filterinstanceid];
        $rows[] = ['section' => '', 'key' => 'starttime', 'value' => $filterstarttime];
        $rows[] = ['section' => '', 'key' => 'endtime', 'value' => $filterendtime];

        // Section: Ergebnis.
        $rows[] = ['section' => get_string('report:meta_s_results', $plugin), 'key' => '', 'value' => ''];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_totalattempts', $plugin),
            'value' => $attemptcount];

        // Section: Blatt-Übersicht.
        $rows[] = ['section' => get_string('report:meta_s_sheets', $plugin), 'key' => '', 'value' => ''];
        foreach ($sheetlist as $sheetname => $desc) {
            $rows[] = ['section' => '', 'key' => $sheetname, 'value' => $desc];
        }

        return [$cols, $rows];
    }
}
