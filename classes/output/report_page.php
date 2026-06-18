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
 * Renderable for the course-level statistics report page.
 *
 * Accepts raw data from report.php, pre-formats all values for display,
 * and exports a flat data structure to the Mustache template.
 *
 * Table columns shown in the browser view (display subset):
 *   Name | Start time | Duration | Items | Status | Scale | PP | SE
 *
 * The full column set (all 20+ columns including subscales) is only in the
 * export.  Export URLs are generated here and passed to the template.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\output;

use moodle_url;
use renderable;
use renderer_base;
use templatable;
use block_catquiz_statistics\repository\attempt_filter;

/**
 * Data container for the report_page.mustache template.
 */
class report_page implements renderable, templatable {
    /** @var int Course ID. */
    private int $courseid;

    /** @var attempt_filter Active query filter. */
    private attempt_filter $filter;

    /** @var array Array of instance stdClass objects (instanceid, testname, attemptcount). */
    private array $instances;

    /** @var array[] Flat attempt rows from attempt_results_report::get_flat_rows(). */
    private array $flatrows;

    /** @var array<string,string> Wide column key to header label map. */
    private array $widecols;

    /** @var string Current startdate form value (YYYY-MM-DD or ''). */
    private string $startdate;

    /** @var string Current enddate form value (YYYY-MM-DD or ''). */
    private string $enddate;

    /** @var bool Whether local_catquiz schema is available. */
    private bool $schemaok;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID.
     * @param attempt_filter $filter Active filter.
     * @param array $instances List of catquiz instances in the course.
     * @param array $flatrows Flat attempt rows.
     * @param array $widecols Wide column definitions.
     * @param string $startdate Current startdate form value (YYYY-MM-DD or '').
     * @param string $enddate Current enddate form value (YYYY-MM-DD or '').
     * @param bool $schemaok Whether local_catquiz tables exist.
     */
    public function __construct(
        int $courseid,
        attempt_filter $filter,
        array $instances,
        array $flatrows,
        array $widecols,
        string $startdate = '',
        string $enddate = '',
        bool $schemaok = true
    ) {
        $this->courseid  = $courseid;
        $this->filter    = $filter;
        $this->instances = $instances;
        $this->flatrows  = $flatrows;
        $this->widecols  = $widecols;
        $this->startdate = $startdate;
        $this->enddate   = $enddate;
        $this->schemaok  = $schemaok;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output Renderer instance.
     * @return array<string,mixed> Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $plugin = 'block_catquiz_statistics';

        // Display column headers (browser table, not export).
        $displayheaders = [
            get_string('report:col_firstname', $plugin) . ' ' . get_string('report:col_lastname', $plugin),
            get_string('report:col_starttime', $plugin),
            get_string('report:col_duration_fmt', $plugin),
            get_string('report:col_used_testitems', $plugin),
            get_string('report:col_status', $plugin),
            get_string('report:col_scale_name', $plugin),
            'PP',
            'SE',
        ];
        $headers = [];
        foreach ($displayheaders as $h) {
            $headers[] = ['label' => $h];
        }

        // Build table rows.
        $tablerows = [];
        foreach ($this->flatrows as $row) {
            $tablerows[] = ['cells' => $this->build_display_cells($row)];
        }

        // Build instance dropdown options.
        $instanceoptions = [
            [
                'value'    => '0',
                'label'    => get_string('report:filter_all_instances', $plugin),
                'selected' => $this->filter->instanceid === null,
            ],
        ];
        foreach ($this->instances as $inst) {
            $label = !empty($inst->testname) ? $inst->testname : 'Instance ' . $inst->instanceid;
            $label .= ' (' . $inst->attemptcount . ')';
            $instanceoptions[] = [
                'value'    => (string) $inst->instanceid,
                'label'    => $label,
                'selected' => $this->filter->instanceid === (int) $inst->instanceid,
            ];
        }

        // Build export URLs.
        $baseurl   = new moodle_url('/blocks/catquiz_statistics/report.php');
        $urlparams = $this->filter_to_url_params();

        $csvurl = new moodle_url($baseurl, array_merge($urlparams, ['export' => 'csv']));
        $xlsurl = new moodle_url($baseurl, array_merge($urlparams, ['export' => 'excel']));

        return [
            'courseid'        => $this->courseid,
            'reporturl'       => $baseurl->out(false),
            'heading'         => get_string('reporttitle', $plugin),
            'schemaerror'     => !$this->schemaok,
            'schemaerrortext' => !$this->schemaok
                ? get_string('report_schema_missing', $plugin) : '',
            'filterlabel'     => get_string('filter', 'moodle'),
            'instancelabel'   => get_string('report:filter_instance', $plugin),
            'startdatelabel'  => get_string('report:filter_startdate', $plugin),
            'enddatelabel'    => get_string('report:filter_enddate', $plugin),
            'applylabel'      => get_string('report:filter_apply', $plugin),
            'instanceoptions' => $instanceoptions,
            'startdate'       => $this->startdate,
            'enddate'         => $this->enddate,
            'attemptcountlabel' => get_string('report:n_attempts', $plugin),
            'attemptcount'    => count($this->flatrows),
            'hasrows'         => !empty($this->flatrows),
            'noattemptsmsg'   => get_string('report:noattempts', $plugin),
            'tableheaders'    => $headers,
            'tablerows'       => $tablerows,
            'exportcsvlabel'  => get_string('report:export_csv', $plugin),
            'exportexcellabel' => get_string('report:export_excel', $plugin),
            'exportcsvurl'    => $csvurl->out(false),
            'exportexcelurl'  => $xlsurl->out(false),
        ];
    }

    /**
     * Build the display cells array for one flat row.
     *
     * Only a subset of columns is shown in the browser table (full data is
     * available via export).
     *
     * @param array $row Flat row from attempt_results_report::get_flat_rows().
     * @return array[] Array of ['v' => value] cells.
     */
    private function build_display_cells(array $row): array {
        // Name.
        $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($name === '') {
            $name = $row['username'] ?? '?';
        }

        // Start time — localized via userdate().
        $starttime = !empty($row['starttime'])
            ? userdate($row['starttime'], get_string('strftimerecentfull', 'langconfig')) : '-';

        // Duration in minutes and seconds.
        $duration = '-';
        if (isset($row['duration_s']) && $row['duration_s'] !== null) {
            $secs = (int) $row['duration_s'];
            $mins = (int) ($secs / 60);
            $rem  = $secs % 60;
            $duration = $mins > 0 ? $mins . ' min ' . $rem . ' s' : $rem . ' s';
        }

        // PP and SE — formatted to 2 decimal places, or '-' / 'n/v' for null.
        $globalpp = isset($row['global_pp']) && $row['global_pp'] !== null
            ? number_format((float) $row['global_pp'], 2) : '-';
        $globalse = isset($row['global_se']) && $row['global_se'] !== null
            ? number_format((float) $row['global_se'], 2) : 'n/v';

        return [
            ['v' => $name],
            ['v' => $starttime],
            ['v' => $duration],
            ['v' => $row['used_testitems'] ?? '-'],
            ['v' => $row['status'] ?? '-'],
            ['v' => $row['global_scale_name'] ?? '-'],
            ['v' => $globalpp],
            ['v' => $globalse],
        ];
    }

    /**
     * Convert the active filter to URL parameters for export links.
     *
     * @return array<string,mixed> URL parameter array.
     */
    private function filter_to_url_params(): array {
        $params = ['courseid' => $this->courseid];
        if ($this->filter->instanceid !== null) {
            $params['instanceid'] = $this->filter->instanceid;
        }
        if ($this->startdate !== '') {
            $params['startdate'] = $this->startdate;
        }
        if ($this->enddate !== '') {
            $params['enddate'] = $this->enddate;
        }
        return $params;
    }
}
