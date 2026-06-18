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
 * Abstract base for all export format writers.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\export;

use block_catquiz_statistics\report\report_interface;
use block_catquiz_statistics\repository\attempt_filter;

/**
 * Base exporter - wraps Moodle's \core\dataformat API.
 *
 * Formats:
 *   csv   - single sheet via \core\dataformat::download_data()
 *   json  - single sheet via \core\dataformat::download_data()
 *   excel - single or multi-sheet (dataformat 'excel' = XLSX)
 *   ods   - single or multi-sheet
 *
 * Multi-sheet mode is only available for excel/ods. All other formats
 * silently fall back to wide/flat mode.
 *
 * Important: export_single() calls get_flat_rows() BEFORE get_columns() so
 * that the report object can populate dynamic scale columns as a side-effect
 * of fetching data, which get_columns() then reads back.
 */
abstract class base_exporter {
    /** @var string[] Supported single-sheet and multi-sheet formats. */
    protected const SUPPORTED_FORMATS = ['csv', 'json', 'excel', 'ods'];

    /** @var string[] Formats that support multiple sheets. */
    protected const MULTISHEET_FORMATS = ['excel', 'ods'];

    /**
     * Execute the export: build data, call dataformat writer, send to browser.
     *
     * @param report_interface $report Report module providing the data.
     * @param attempt_filter $filter Query scope.
     * @param string $format One of self::SUPPORTED_FORMATS.
     * @param string $mode 'wide' (default) or 'multi' (xlsx/ods only).
     * @return void
     */
    public function export(
        report_interface $report,
        attempt_filter $filter,
        string $format = 'csv',
        string $mode = 'wide'
    ): void {
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new \coding_exception('Unsupported export format: ' . $format);
        }

        $filename   = $this->build_filename($report, $filter, $format);
        $multisheet = ($mode === 'multi' && in_array($format, self::MULTISHEET_FORMATS, true));

        if ($multisheet) {
            $this->export_multisheet($report, $filter, $format, $filename);
        } else {
            $this->export_single($report, $filter, $format, $filename);
        }
    }

    /**
     * Single-sheet export via \core\dataformat::download_data().
     *
     * get_flat_rows() is called before get_columns() so that dynamic scale
     * columns discovered during data fetch are included in the headers.
     *
     * @param report_interface $report Report module.
     * @param attempt_filter $filter Query scope.
     * @param string $format Dataformat identifier.
     * @param string $filename Download filename (without extension).
     * @return void
     */
    protected function export_single(
        report_interface $report,
        attempt_filter $filter,
        string $format,
        string $filename
    ): void {
        $rows    = $report->get_flat_rows($filter);
        $columns = $report->get_columns();
        \core\dataformat::download_data($filename, $format, $columns, new \ArrayIterator($rows));
    }

    /**
     * Multi-sheet export for excel/ods.
     *
     * TODO Phase 1: use dataformat writer class directly to write multiple
     * named sheets (attempts_raw, attempts_wide, scale_summary, subscale_scores,
     * subscale_se, subscale_n, subscale_frac, metadata).
     *
     * Respects block_catquiz_statistics/maxsheets setting.
     *
     * @param report_interface $report Report module.
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
        // Fall back to single-sheet until Phase 1 implements the writer.
        $this->export_single($report, $filter, $format, $filename);
    }

    /**
     * Build a descriptive, date-stamped download filename.
     *
     * @param report_interface $report Report module.
     * @param attempt_filter $filter Query scope.
     * @param string $format Format identifier.
     * @return string Clean filename without extension.
     */
    protected function build_filename(
        report_interface $report,
        attempt_filter $filter,
        string $format
    ): string {
        $parts = [
            'catquizstats',
            'module' . $report->get_module_id(),
            'course' . $filter->courseid,
            date('Ymd-Hi'),
        ];
        if ($filter->instanceid !== null) {
            $parts[] = 'instance' . $filter->instanceid;
        }
        return clean_filename(implode('_', $parts));
    }
}
