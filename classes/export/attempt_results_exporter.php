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
 *   1. attempts_raw     - fixed columns, no subscale expansion
 *   2. scale_summary    - aggregate descriptive stats per scale
 *   3. attempts_wide    - full flat/wide with all subscale columns
 *   4. subscale_scores  - pivot: attempt x scale, PP values
 *   5. subscale_se      - pivot: attempt x scale, SE values (null = invalid)
 *   6. subscale_n       - pivot: attempt x scale, item counts
 *   7. subscale_frac    - pivot: attempt x scale, fraction correct
 *   8. metadata         - export parameters and scale hierarchy
 *
 * Multi-sheet output uses OpenSpout directly (not Moodle's dataformat wrapper)
 * so that header formatting (bold, background colour) and column widths can
 * be applied.  OpenSpout is always present in Moodle 4.5+.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\report\attempt_results_report;
use block_catquiz_statistics\report\report_interface;
use block_catquiz_statistics\repository\attempt_filter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use OpenSpout\Writer\ODS\Writer as ODSWriter;

/**
 * Test Results exporter — 8 named sheets with header formatting.
 */
class attempt_results_exporter extends base_exporter {
    /**
     * Write 8 named, formatted sheets using OpenSpout directly.
     *
     * Bypasses Moodle's dataformat wrapper so that header background colour,
     * bold font, and column widths can be applied.  The OpenSpout writer is
     * always available in Moodle 4.5+ (used internally by spout_base.php).
     *
     * Falls back to single-sheet export when $report is not an
     * attempt_results_report.
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

        \core_php_time_limit::raise();
        \core\session\manager::write_close();

        $maxsheets = (int) get_config('block_catquiz_statistics', 'maxsheets') ?: 50;
        $plugin = 'block_catquiz_statistics';

        // Pre-fetch all data; the report caches DTOs internally.
        $widerows = $report->get_flat_rows($filter);
        $widecols = $report->get_columns();

        // Styles: header (blue bg, white bold), meta-section (light blue bold).
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

        // Open writer and configure column widths (XLSX only).
        $ext = ($format === 'ods') ? '.ods' : '.xlsx';
        if ($format === 'ods') {
            $writer = new ODSWriter();
        } else {
            $writer = new XLSXWriter();
            $this->set_default_column_widths($writer);
        }

        if (method_exists($writer->getOptions(), 'setTempFolder')) {
            $writer->getOptions()->setTempFolder(make_request_directory());
        }
        $writer->openToBrowser($filename . $ext);

        $sheetnum = 0;

        // Sheet 1: metadata (first so educators see export context immediately).
        if ($sheetnum < $maxsheets) {
            [$metacols, $metarows] = $this->build_metadata(
                $report,
                $filter,
                $format,
                count($widerows)
            );
            $this->write_meta_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_metadata', $plugin),
                $metacols,
                $metarows,
                $headerstyle,
                $metasectionstyle
            );
        }

        // Sheet 2: attempts_raw.
        if ($sheetnum < $maxsheets) {
            $this->write_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_attempts_raw', $plugin),
                $report->get_fixed_columns(),
                $report->get_raw_rows($filter),
                $headerstyle
            );
        }

        // Sheet 3: scale_summary (with group header row above column headers).
        if ($sheetnum < $maxsheets) {
            $sumcols = $report->get_scale_summary_columns();
            $this->write_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_scale_summary', $plugin),
                $sumcols,
                $report->get_scale_summary_rows($filter),
                $headerstyle,
                $this->build_scale_summary_group_header($sumcols)
            );
        }

        // Sheet 4: attempts_wide.
        if ($sheetnum < $maxsheets) {
            $this->write_sheet(
                $writer,
                $sheetnum,
                get_string('report:sheet_attempts_wide', $plugin),
                $widecols,
                $widerows,
                $headerstyle
            );
        }

        // Sheets 5-8: subscale pivots.
        $pivots = [
            'pp' => get_string('report:sheet_subscale_scores', $plugin),
            'se' => get_string('report:sheet_subscale_se', $plugin),
            'n' => get_string('report:sheet_subscale_n', $plugin),
            'frac' => get_string('report:sheet_subscale_frac', $plugin),
        ];
        $pivcols = $report->get_subscale_pivot_columns();
        foreach ($pivots as $metric => $sheettitle) {
            if ($sheetnum >= $maxsheets) {
                break;
            }
            $this->write_sheet(
                $writer,
                $sheetnum,
                $sheettitle,
                $pivcols,
                $report->get_subscale_pivot_rows($filter, $metric),
                $headerstyle
            );
        }

        $writer->close();
    }

    /**
     * Write one standard data sheet.
     *
     * @param object $writer OpenSpout writer instance.
     * @param int $sheetnum Current sheet index (incremented by reference).
     * @param string $title Sheet tab name.
     * @param array $cols Column key => header label map.
     * @param array $rows Data rows.
     * @param Style $headerstyle Style applied to the header row.
     * @return void
     */
    private function write_sheet(
        object $writer,
        int &$sheetnum,
        string $title,
        array $cols,
        array $rows,
        Style $headerstyle,
        ?array $groupheader = null
    ): void {
        $this->activate_sheet($writer, $sheetnum, $title);
        if ($groupheader !== null) {
            $writer->addRow(Row::fromValues($groupheader, $headerstyle));
        }
        $writer->addRow(Row::fromValues(array_values($cols), $headerstyle));
        $colkeys = array_keys($cols);
        foreach ($rows as $row) {
            $vals = [];
            foreach ($colkeys as $key) {
                $v = $row[$key] ?? null;
                $vals[] = is_float($v) ? round($v, 3) : $v;
            }
            $writer->addRow(Row::fromValues($vals));
        }
        $sheetnum++;
    }

    /**
     * Write the metadata sheet with section-divider row formatting.
     *
     * Rows whose 'section' field is non-empty are written with the
     * $metasectionstyle (dark blue, white bold); all other rows use the default.
     *
     * @param object $writer OpenSpout writer instance.
     * @param int $sheetnum Current sheet index (incremented by reference).
     * @param string $title Sheet tab name.
     * @param array $cols Column key => header label map.
     * @param array $rows Metadata rows (each may have a 'section' key).
     * @param Style $headerstyle Style for the header row.
     * @param Style $sectionstyle Style for section-divider rows.
     * @return void
     */
    private function write_meta_sheet(
        object $writer,
        int &$sheetnum,
        string $title,
        array $cols,
        array $rows,
        Style $headerstyle,
        Style $sectionstyle
    ): void {
        $this->activate_sheet($writer, $sheetnum, $title);
        $writer->addRow(Row::fromValues(array_values($cols), $headerstyle));
        $colkeys = array_keys($cols);
        foreach ($rows as $row) {
            $style = (!empty($row['section'])) ? $sectionstyle : null;
            $vals = [];
            foreach ($colkeys as $key) {
                $vals[] = $row[$key] ?? null;
            }
            $writer->addRow(Row::fromValues($vals, $style));
        }
        $sheetnum++;
    }

    /**
     * Activate the correct sheet: rename the first (auto-created) sheet, or
     * add a new sheet for all subsequent ones.
     *
     * @param object $writer OpenSpout writer instance.
     * @param int $sheetnum 0 = first sheet (already exists); > 0 = new sheet.
     * @param string $title Sheet tab name.
     * @return void
     */
    private function activate_sheet(object $writer, int $sheetnum, string $title): void {
        if ($sheetnum === 0) {
            $writer->getCurrentSheet()->setName($title);
        } else {
            $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName($title);
        }
    }

    /**
     * Configure sensible default column widths on the XLSX writer.
     *
     * Called before openToBrowser() so the options are locked in.
     * Silently skips if the Options API is unavailable.
     *
     * @param XLSXWriter $writer XLSX writer instance.
     * @return void
     */
    private function set_default_column_widths(XLSXWriter $writer): void {
        $opts = $writer->getOptions();
        if (method_exists($opts, 'setDefaultColumnWidth')) {
            $opts->setDefaultColumnWidth(13.0);
        }
        if (method_exists($opts, 'setColumnWidth')) {
            // Name columns (firstname, lastname, email) wider for readability.
            $opts->setColumnWidth(22.0, 3, 4, 5);
            // Timestamp columns (starttime, endtime).
            $opts->setColumnWidth(18.0, 9, 10);
        }
    }

    /**
     * Build a group-header row for the scale_summary sheet.
     *
     * Returns an array of strings where the first column of each logical group
     * contains the group label; remaining columns within the group are empty.
     * This simulates merged-cell section headers without requiring XLSX merge support.
     *
     * @param array $cols Column key => header label map from get_scale_summary_columns().
     * @return array Group header values aligned to $cols.
     */
    private function build_scale_summary_group_header(array $cols): array {
        $plugin = 'block_catquiz_statistics';
        $groups = [
            'scale_id' => get_string('report:col_group_scaleinfo', $plugin),
            'scale_label' => '',
            'scale_name' => '',
            'parent_label' => '',
            'items_total' => get_string('report:col_group_items', $plugin),
            'items_productive' => '',
            'diff_min' => get_string('report:col_group_difficulty', $plugin),
            'diff_max' => '',
            'diff_mean' => '',
            'diff_sd' => '',
            'n' => get_string('report:col_group_results', $plugin),
            'mean' => '',
            'median' => '',
            'sd' => '',
            'min' => '',
            'max' => '',
            'q1' => '',
            'q3' => '',
        ];
        $header = [];
        foreach (array_keys($cols) as $key) {
            $header[] = $groups[$key] ?? '';
        }
        return $header;
    }

    /**
     * Build the metadata sheet columns and rows from the report's structured metadata.
     *
     * Sections match the Lastenheft template:
     *   Export-Informationen | Kurs & Test | Filter | Ergebnis | SE-Einstellungen |
     *   Skalenhierarchie | Blatt-Ubersicht.
     *
     * @param report_interface $report Report providing metadata via get_metadata_for_export().
     * @param attempt_filter $filter Active query scope.
     * @param string $format Export format identifier.
     * @param int $attemptcount Number of attempt rows exported.
     * @return array Two-element array: [columns array, rows array].
     */
    private function build_metadata(
        report_interface $report,
        attempt_filter $filter,
        string $format,
        int $attemptcount
    ): array {
        $plugin = 'block_catquiz_statistics';
        $cols = [
            'section' => get_string('report:meta_section', $plugin),
            'key' => get_string('report:meta_key', $plugin),
            'value' => get_string('report:meta_value', $plugin),
        ];

        $meta = $report->get_metadata_for_export($filter, $format);

        $sheets = [
            get_string('report:sheet_attempts_raw', $plugin)
                => get_string('report:meta_sheet_attempts_raw', $plugin),
            get_string('report:sheet_attempts_wide', $plugin)
                => get_string('report:meta_sheet_attempts_wide', $plugin),
            get_string('report:sheet_scale_summary', $plugin)
                => get_string('report:meta_sheet_scale_summary', $plugin),
            get_string('report:sheet_subscale_scores', $plugin)
                => get_string('report:meta_sheet_subscale_scores', $plugin),
            get_string('report:sheet_subscale_se', $plugin)
                => get_string('report:meta_sheet_subscale_se', $plugin),
            get_string('report:sheet_subscale_n', $plugin)
                => get_string('report:meta_sheet_subscale_n', $plugin),
            get_string('report:sheet_subscale_frac', $plugin)
                => get_string('report:meta_sheet_subscale_frac', $plugin),
        ];

        $rows = [];

        // Section 1: Export info.
        $rows[] = [
            'section' => get_string('report:meta_s_export', $plugin),
            'key' => get_string('report:meta_plugin', $plugin),
            'value' => $meta['plugin_version'],
        ];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_datetime', $plugin), 'value' => $meta['export_datetime']];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_moodle', $plugin), 'value' => $meta['moodle_version']];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_format', $plugin), 'value' => $meta['format']];

        // Section 2: Course & Test.
        $rows[] = [
            'section' => get_string('report:meta_s_course', $plugin),
            'key' => get_string('report:meta_coursename', $plugin),
            'value' => $meta['coursename'],
        ];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_courseid', $plugin), 'value' => $meta['courseid']];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_testname', $plugin), 'value' => $meta['testname']];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_instanceid', $plugin), 'value' => $meta['instanceid']];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_testid', $plugin), 'value' => $meta['testid']];

        // Section 3: Filter.
        $rows[] = [
            'section' => get_string('report:meta_s_filter', $plugin),
            'key' => 'instanceid',
            'value' => $meta['filter_instanceid'],
        ];
        $rows[] = ['section' => '', 'key' => 'starttime', 'value' => $meta['filter_starttime']];
        $rows[] = ['section' => '', 'key' => 'endtime', 'value' => $meta['filter_endtime']];
        $rows[] = ['section' => '', 'key' => 'scaleid', 'value' => $meta['filter_scaleid']];

        // Section 4: Results.
        $rows[] = [
            'section' => get_string('report:meta_s_results', $plugin),
            'key' => get_string('report:meta_totalattempts', $plugin),
            'value' => $meta['total_attempts'],
        ];
        $rows[] = ['section' => '', 'key' => get_string('report:meta_participants', $plugin), 'value' => $meta['participants']];

        // Section 5: SE settings.
        $rows[] = [
            'section' => get_string('report:meta_s_se', $plugin),
            'key' => 'catquiz_standarderror_max',
            'value' => $meta['semax'] ?? get_string('report:filter_none', $plugin),
        ];
        $rows[] = [
            'section' => '',
            'key' => 'catquiz_minquestionspersubscale',
            'value' => $meta['nmin'] ?? get_string('report:filter_none', $plugin),
        ];

        // Section 6: Scale hierarchy.
        foreach ($meta['hierarchy'] as $hrow) {
            $hierarchydesc = $hrow['name'];
            if (!empty($hrow['parent_label'])) {
                $hierarchydesc .= ' (' . get_string('report:meta_subof', $plugin) . ' ' . $hrow['parent_label'] . ')';
            } else {
                $hierarchydesc .= ' (' . get_string('report:meta_rootscale', $plugin) . ')';
            }
            $rows[] = [
                'section' => get_string('report:meta_s_hierarchy', $plugin),
                'key' => 'ID ' . $hrow['id'] . ' – ' . $hrow['label'],
                'value' => $hierarchydesc,
            ];
        }

        // Section 7: Sheet overview.
        foreach ($sheets as $sheetname => $desc) {
            $rows[] = ['section' => get_string('report:meta_s_sheets', $plugin), 'key' => $sheetname, 'value' => $desc];
        }

        return [$cols, $rows];
    }
}
