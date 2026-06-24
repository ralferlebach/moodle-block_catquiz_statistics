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

    /** @var array[] Aggregated summary rows for browser display (module usage). */
    private array $summaryrows;

    /** @var array<string,string> Wide column key to header label map. */
    private array $widecols;

    /** @var string Current startdate form value (YYYY-MM-DD or ''). */
    private string $startdate;

    /** @var string Current enddate form value (YYYY-MM-DD or ''). */
    private string $enddate;

    /** @var bool Whether local_catquiz schema is available. */
    private bool $schemaok;

    /** @var bool True for the system-wide admin report (adminreport.php). */
    private bool $issystemwide;

    /**
     * Course options for the course selector (system-wide mode only).
     *
     * Each element: stdClass {id, fullname, shortname}.
     *
     * @var array
     */
    private array $courses;

    /** @var int Selected course ID (0 = all courses) in system-wide mode. */
    private int $selectedcourseid;

    /**
     * URL path to the report PHP file; used for filter form action and export URLs.
     *
     * Defaults to the course-level report; adminreport.php passes its own path.
     *
     * @var string
     */
    private string $reporturlpath;

    /** @var string Active report module ID ('results', 'usage', …). */
    private string $moduleid;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID (0 = system-wide).
     * @param attempt_filter $filter Active filter.
     * @param array $instances List of catquiz instances in the course/system.
     * @param array $flatrows Flat attempt rows.
     * @param array $widecols Wide column definitions.
     * @param string $startdate Current startdate form value (YYYY-MM-DD or '').
     * @param string $enddate Current enddate form value (YYYY-MM-DD or '').
     * @param bool $schemaok Whether local_catquiz tables exist.
     * @param bool $issystemwide True for the system-wide admin report.
     * @param array $courses Courses with attempts (system-wide mode only).
     * @param int $selectedcourseid Currently selected course in system-wide mode.
     * @param string $reporturlpath URL path to report PHP file.
     * @param string $moduleid Active report module ID ('results', 'usage', …).
     * @param array $summaryrows Aggregated summary rows for module usage browser view.
     */
    public function __construct(
        int $courseid,
        attempt_filter $filter,
        array $instances,
        array $flatrows,
        array $widecols,
        string $startdate = '',
        string $enddate = '',
        bool $schemaok = true,
        bool $issystemwide = false,
        array $courses = [],
        int $selectedcourseid = 0,
        string $reporturlpath = '/blocks/catquiz_statistics/report.php',
        string $moduleid = 'results',
        array $summaryrows = []
    ) {
        $this->courseid = $courseid;
        $this->filter = $filter;
        $this->instances = $instances;
        $this->flatrows = $flatrows;
        $this->widecols = $widecols;
        $this->startdate = $startdate;
        $this->enddate = $enddate;
        $this->schemaok = $schemaok;
        $this->issystemwide = $issystemwide;
        $this->courses = $courses;
        $this->selectedcourseid = $selectedcourseid;
        $this->reporturlpath = $reporturlpath;
        $this->moduleid = $moduleid;
        $this->summaryrows = $summaryrows;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output Renderer instance.
     * @return array<string,mixed> Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $plugin = 'block_catquiz_statistics';

        // Module-aware display headers and row builder.
        $displayheaders = $this->get_display_headers($plugin);
        $headers = [];
        foreach ($displayheaders as $h) {
            $headers[] = ['label' => $h];
        }

        // For usage module use the pre-aggregated summary rows; others use flat rows.
        $displayrows = ($this->moduleid === 'usage' && !empty($this->summaryrows))
            ? $this->summaryrows : $this->flatrows;

        // Build table rows using the module-specific cell builder.
        $tablerows = [];
        foreach ($displayrows as $row) {
            $tablerows[] = ['cells' => $this->build_display_cells_for_module($row, $plugin)];
        }

        // Build instance checkbox list (multi-select). Empty selection = all instances.
        // Applied set respects the multi-select instanceids, falling back to the legacy single instanceid.
        $applied = $this->filter->instanceids
            ?? ($this->filter->instanceid !== null ? [$this->filter->instanceid] : []);
        $instancecheckboxes = [];
        foreach ($this->instances as $inst) {
            $label = !empty($inst->testname) ? $inst->testname : 'Instance ' . $inst->instanceid;
            $label .= ' (' . $inst->attemptcount . ')';
            $instancecheckboxes[] = [
                'value'   => (string) $inst->instanceid,
                'label'   => $label,
                'checked' => in_array((int) $inst->instanceid, $applied, true),
            ];
        }

        // Applied instance IDs as hidden inputs for the export form (keeps export in sync with the filter).
        $appliedinstanceids = [];
        foreach ($applied as $id) {
            $appliedinstanceids[] = ['value' => (string) $id];
        }

        // Build export format options. Default selection comes from the admin setting.
        $defaultformat = get_config('block_catquiz_statistics', 'defaultformat') ?: 'csv';
        // Sheet counts per module for multi-sheet formats.
        $sheetcounts = [
            'results' => 8,
            'usage'   => 1,
            'progress'=> 1,
            'activity'=> 0,
            'items'   => 1,
        ];
        $sheets = $sheetcounts[$this->moduleid] ?? 1;
        $xlsxlabel = $sheets > 1
            ? get_string('report:format_excel', $plugin)
            : 'Excel (XLSX, ' . $sheets . ' ' . get_string('report:sheet', $plugin) . ')';
        $odslabel = $sheets > 1
            ? get_string('report:format_ods', $plugin)
            : 'ODS (' . $sheets . ' ' . get_string('report:sheet', $plugin) . ')';

        $formatlabels = [
            'csv'   => get_string('report:format_csv', $plugin),
            'json'  => get_string('report:format_json', $plugin),
            'excel' => $xlsxlabel,
            'ods'   => $odslabel,
        ];
        $formatoptions = [];
        foreach ($formatlabels as $value => $flabel) {
            $formatoptions[] = [
                'value'    => $value,
                'label'    => $flabel,
                'selected' => $value === $defaultformat,
            ];
        }

        // Course ID carried into the export form (system-wide uses the selected course, 0 = all).
        $exportcourseid = $this->issystemwide ? $this->selectedcourseid : $this->courseid;

        $baseurl = new moodle_url($this->reporturlpath);

        // Build module tab definitions.
        $tabs = $this->build_tabs($baseurl);

        return [
            'courseid'        => $this->courseid,
            'reporturl'       => $baseurl->out(false),
            'heading'         => get_string('reporttitle', $plugin),
            'schemaerror'     => !$this->schemaok,
            'schemaerrortext' => !$this->schemaok
                ? get_string('report_schema_missing', $plugin) : '',
            'filterlabel'     => get_string('filter', 'moodle'),
            'instanceslabel'  => get_string('report:filter_instances', $plugin),
            'startdatelabel'  => get_string('report:filter_startdate', $plugin),
            'enddatelabel'    => get_string('report:filter_enddate', $plugin),
            'applylabel'      => get_string('report:filter_apply', $plugin),
            'issystemwide'  => $this->issystemwide,
            'courselabel'   => get_string('report:filter_course', $plugin),
            'courseoptions' => $this->build_course_options(),
            'instancecheckboxes' => $instancecheckboxes,
            'noinstancesmsg'  => get_string('report:filter_no_instances', $plugin),
            'startdate'       => $this->startdate,
            'enddate'         => $this->enddate,
            'attemptcountlabel' => get_string('report:n_attempts', $plugin),
            'attemptcount'    => count($this->flatrows),
            'hasrows'         => !empty($this->flatrows),
            'noattemptsmsg'   => get_string('report:noattempts', $plugin),
            'tableheaders'    => $headers,
            'tablerows'       => $tablerows,
            'exporturl'        => $baseurl->out(false),
            'exportcourseid'   => $exportcourseid,
            'appliedinstanceids' => $appliedinstanceids,
            'formatoptions'    => $formatoptions,
            'exportformatlabel' => get_string('report:export_format', $plugin),
            'exportbuttonlabel' => get_string('report:export_button', $plugin),
            'tabs'             => $tabs,
            'activemoduleid'   => $this->moduleid,
            'modulenotice'     => $this->get_module_notice($plugin),
            'hasmodulenotice'  => $this->get_module_notice($plugin) !== '',
        ];
    }

    /**
     * Return browser-table column headers for the active module.
     *
     * @param string $plugin Plugin identifier for get_string().
     * @return string[]
     */
    private function get_display_headers(string $plugin): array {
        switch ($this->moduleid) {
            case 'usage':
                return [
                    get_string('report:col_firstname', $plugin) . ' '
                        . get_string('report:col_lastname', $plugin),
                    get_string('report:col_global_scale_name', $plugin),
                    get_string('report:col_n_attempts', $plugin),
                    get_string('report:col_best_score', $plugin),
                    get_string('report:col_delta_ability', $plugin),
                    get_string('report:col_rci', $plugin),
                ];
            case 'progress':
                return [
                    get_string('report:col_firstname', $plugin) . ' '
                        . get_string('report:col_lastname', $plugin),
                    get_string('report:col_attemptid', $plugin),
                    get_string('report:col_step_nr', $plugin),
                    get_string('report:col_questionname', $plugin),
                    get_string('report:col_questionscale_name', $plugin),
                    get_string('report:col_difficulty', $plugin),
                    get_string('report:col_lastresponse', $plugin),
                    get_string('report:col_personability_after', $plugin),
                ];
            case 'items':
                return [
                    get_string('report:col_questionname', $plugin),
                    get_string('report:col_questionscale_name', $plugin),
                    get_string('report:col_difficulty', $plugin),
                    get_string('report:col_n_presented', $plugin),
                    get_string('report:col_frac_correct', $plugin),
                    get_string('report:col_mean_response', $plugin),
                    get_string('report:col_mean_fisher', $plugin),
                    get_string('report:col_mean_ability_before', $plugin),
                ];
            case 'activity':
                return [];
            default:
                return [
                    get_string('report:col_firstname', $plugin) . ' '
                        . get_string('report:col_lastname', $plugin),
                    get_string('report:col_starttime', $plugin),
                    get_string('report:col_duration_fmt', $plugin),
                    get_string('report:col_used_testitems', $plugin),
                    get_string('report:col_status', $plugin),
                    get_string('report:col_global_scale_name', $plugin),
                    get_string('report:col_global_pp', $plugin),
                    get_string('report:col_global_se', $plugin),
                ];
        }
    }

    /**
     * Dispatch to the module-specific cell builder.
     *
     * @param array $row Flat row from the active report module.
     * @param string $plugin Plugin identifier.
     * @return array[]
     */
    private function build_display_cells_for_module(array $row, string $plugin): array {
        switch ($this->moduleid) {
            case 'usage':
                return $this->cells_usage($row);
            case 'progress':
                return $this->cells_progress($row);
            case 'items':
                return $this->cells_items($row);
            case 'activity':
                return [];
            default:
                return $this->build_display_cells($row);
        }
    }

    /**
     * Display cells for Modul B (Testnutzung).
     *
     * @param array $row Flat row from test_usage_report.
     * @return array[]
     */
    private function cells_usage(array $row): array {
        $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($name === '') {
            $name = $row['username'] ?? '?';
        }
        $starttime = !empty($row['starttime'])
            ? userdate($row['starttime'], get_string('strftimerecentfull', 'langconfig')) : '-';
        $pp    = $row['global_pp'] !== null ? number_format((float) $row['global_pp'], 3) : '-';
        $se    = $row['global_se'] !== null ? number_format((float) $row['global_se'], 3) : '-';
        $delta = $row['delta_ability'] !== null ? number_format((float) $row['delta_ability'], 3) : '-';
        $rci   = $row['rci'] !== null ? number_format((float) $row['rci'], 2) : '-';
        return [
            ['v' => $name],
            ['v' => $row['testname'] ?? '-'],
            ['v' => $row['attempt_rank'] ?? '-'],
            ['v' => $starttime],
            ['v' => $row['status'] ?? '-'],
            ['v' => $pp],
            ['v' => $se],
            ['v' => $delta],
            ['v' => $rci],
        ];
    }

    /**
     * Display cells for Modul C (Testverlauf).
     *
     * @param array $row Flat row from test_progress_report.
     * @return array[]
     */
    private function cells_progress(array $row): array {
        $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($name === '') {
            $name = $row['username'] ?? '?';
        }
        $diff = $row['difficulty'] !== null ? number_format((float) $row['difficulty'], 3) : '-';
        $resp = $row['lastresponse'] !== null ? number_format((float) $row['lastresponse'], 2) : '-';
        $abil = $row['personability_after'] !== null ? number_format((float) $row['personability_after'], 3) : '-';
        return [
            ['v' => $name],
            ['v' => $row['attemptid'] ?? '-'],
            ['v' => $row['step_nr'] ?? '-'],
            ['v' => $row['questionname'] ?? '-'],
            ['v' => $row['questionscale_name'] ?? '-'],
            ['v' => $diff],
            ['v' => $resp],
            ['v' => $abil],
        ];
    }

    /**
     * Display cells for Modul E (Item- und Antwortanalyse).
     *
     * @param array $row Flat row from item_analysis_report.
     * @return array[]
     */
    private function cells_items(array $row): array {
        $diff = $row['difficulty'] !== null ? number_format((float) $row['difficulty'], 3) : '-';
        $frac = $row['frac_correct'] !== null
            ? number_format((float) $row['frac_correct'] * 100, 1) . ' %' : '-';
        $resp = $row['mean_response'] !== null ? number_format((float) $row['mean_response'], 3) : '-';
        $fish = $row['mean_fisher'] !== null ? number_format((float) $row['mean_fisher'], 3) : '-';
        $abil = $row['mean_ability_before'] !== null ? number_format((float) $row['mean_ability_before'], 3) : '-';
        return [
            ['v' => $row['questionname'] ?? '-'],
            ['v' => $row['questionscale_name'] ?? '-'],
            ['v' => $diff],
            ['v' => $row['n_presented'] ?? '-'],
            ['v' => $frac],
            ['v' => $resp],
            ['v' => $fish],
            ['v' => $abil],
        ];
    }

    /**
     * Display cells for Modul A (Testergebnisse).
     *
     * Only a subset of columns is shown in the browser table; full data via export.
     *
     * @param array $row Flat row from attempt_results_report.
     * @return array[]
     */
    private function build_display_cells(array $row): array {
        $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($name === '') {
            $name = $row['username'] ?? '?';
        }
        $starttime = !empty($row['starttime'])
            ? userdate($row['starttime'], get_string('strftimerecentfull', 'langconfig')) : '-';
        $duration = '-';
        if (isset($row['duration_s']) && $row['duration_s'] !== null) {
            $secs = (int) $row['duration_s'];
            $mins = (int) ($secs / 60);
            $rem  = $secs % 60;
            $duration = $mins > 0 ? $mins . ' min ' . $rem . ' s' : $rem . ' s';
        }
        $globalpp = isset($row['global_pp']) && $row['global_pp'] !== null
            ? number_format((float) $row['global_pp'], 2) : '-';
        $globalse = $row['global_se'] !== null
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
        // Systemwide: use the courseid from the filter (may be 0 = all courses).
        $params = ['courseid' => $this->issystemwide
            ? $this->selectedcourseid : $this->courseid];
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

    /**
     * Return an optional notice string for the active module.
     *
     * Returns a non-empty string when the module needs a UI notice
     * (e.g. disabled opt-in feature). Returns '' otherwise.
     *
     * @param string $plugin Plugin identifier.
     * @return string
     */
    private function get_module_notice(string $plugin): string {
        if ($this->moduleid === 'activity') {
            return get_string('report:activity_disabled', $plugin);
        }
        return '';
    }

    /**
     * Build the module tab definitions for the Mustache template.
     *
     * Active modules (a, b) are clickable links. Future modules (c–e) are
     * rendered as disabled tabs with a "coming soon" indicator.
     *
     * Each tab entry: {id, label, active, enabled, url}.
     *
     * @param moodle_url $baseurl Base report URL (without moduleid).
     * @return array[]
     */
    private function build_tabs(moodle_url $baseurl): array {
        $plugin = 'block_catquiz_statistics';

        // Modules a and b are implemented; c–e are planned.
        $modules = [
            'results' => ['label' => get_string('module_a', $plugin), 'enabled' => true],
            'usage'   => ['label' => get_string('module_b', $plugin), 'enabled' => true],
            'progress' => ['label' => get_string('module_c', $plugin), 'enabled' => true],
            'activity' => ['label' => get_string('module_d', $plugin), 'enabled' => true],
            'items'    => ['label' => get_string('module_e', $plugin), 'enabled' => true],
        ];

        $tabs = [];
        foreach ($modules as $id => $def) {
            $urlparams = ['moduleid' => $id];
            if (!$this->issystemwide) {
                $urlparams['courseid'] = $this->courseid;
            }
            $taburl = new moodle_url($baseurl, $urlparams);
            $tabs[] = [
                'id'      => $id,
                'label'   => $def['label'],
                'active'  => $id === $this->moduleid,
                'enabled' => $def['enabled'],
                'url'     => $def['enabled'] ? $taburl->out(false) : '#',
            ];
        }
        return $tabs;
    }

    /**
     * Build the course options array for the Mustache template.
     *
     * Returns an empty array when not in system-wide mode.
     *
     * @return array[]
     */
    private function build_course_options(): array {
        if (!$this->issystemwide) {
            return [];
        }
        $plugin = 'block_catquiz_statistics';
        $opts = [
            [
                'value' => '0',
                'label' => get_string('report:filter_all_courses', $plugin),
                'selected' => $this->selectedcourseid === 0,
            ],
        ];
        foreach ($this->courses as $course) {
            $opts[] = [
                'value' => (string) $course->id,
                'label' => $course->fullname . ' [' . $course->shortname . ']',
                'selected' => (int) $course->id === $this->selectedcourseid,
            ];
        }
        return $opts;
    }
}
