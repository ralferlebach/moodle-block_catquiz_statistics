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
 * Test Results report (Phase 1).
 *
 * Produces flat/wide rows for export from attempt_data DTOs returned by
 * attempt_repository::get_attempts().
 *
 * Fixed columns: id, userid, username, firstname, lastname, email, testid,
 *   attemptid, starttime, endtime, duration_s, teststrategy, status,
 *   total_testitems, used_testitems, global_scale_id/name/pp/se,
 *   primary_scale_id/name/pp/se.
 *
 * Dynamic columns (one set per CAT scale found in the result set):
 *   scale_{id}_pp, scale_{id}_se, scale_{id}_n, scale_{id}_frac.
 *
 * SE validity: NULL is written for scales whose SE exceeds the quiz-configured
 * threshold or whose item count is below the minimum. See feedbacksettings.php
 * filter_nminscale() / filter_semax() in local_catquiz.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\local\se_validator;
use block_catquiz_statistics\local\statistics_helper;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Test Results report.
 */
class attempt_results_report implements report_interface {
    /** @var attempt_repository Injected repository. */
    private attempt_repository $repository;

    /**
     * Scale IDs encountered in the most recent get_flat_rows() call.
     *
     * Populated as a side-effect so that get_columns() can return the full
     * dynamic column set after the data has been fetched.
     *
     * @var int[]
     */
    private array $activescaleids = [];

    /**
     * Scale names keyed by scale ID; populated by ensure_dtos().
     *
     * @var array<int,string>
     */
    private array $activescalenames = [];

    /**
     * Cached DTOs for the current filter to avoid repeated DB round-trips.
     *
     * Invalidated when a new filter with a different courseid or instanceid
     * is passed to any method.
     *
     * @var attempt_data[]|null
     */
    private ?array $dtocache = null;

    /** @var int|null Courseid used for the current cache. */
    private ?int $cachedcourseid = null;

    /** @var int|null Instanceid used for the current cache. */
    private ?int $cachedinstanceid = null;

    /**
     * Constructor.
     *
     * @param attempt_repository $repository Injected repository.
     */
    public function __construct(attempt_repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Return module identifier.
     *
     * @return string
     */
    public function get_module_id(): string {
        return 'a';
    }

    /**
     * Return localised module name.
     *
     * @return string
     */
    public function get_module_name(): string {
        return get_string('module_a', 'block_catquiz_statistics');
    }

    /**
     * Return flat attempt rows for export.
     *
     * Each row is an associative array of column_key => scalar_value.
     * The full column set (including dynamic scale columns) is stored
     * internally so that a subsequent call to get_columns() returns
     * the matching header definitions.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[] Rows ordered by starttime DESC.
     */
    public function get_flat_rows(attempt_filter $filter): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        $rows = [];
        foreach ($dtos as $dto) {
            $rows[] = $this->dto_to_flat_row($dto, $this->activescaleids);
        }
        return $rows;
    }

    /**
     * Return raw attempt rows containing only fixed columns (no subscale expansion).
     *
     * Used as Sheet 1 (attempts_raw) in the multi-sheet export.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[] Rows with only the fixed column set.
     */
    public function get_raw_rows(attempt_filter $filter): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        $rows = [];
        foreach ($dtos as $dto) {
            $rows[] = [
                'id' => $dto->id,
                'userid' => $dto->userid,
                'username' => $dto->username,
                'firstname' => $dto->firstname,
                'lastname' => $dto->lastname,
                'email' => $dto->email,
                'testid' => $dto->testid,
                'attemptid' => $dto->attemptid,
                'starttime' => $dto->starttime,
                'endtime' => $dto->endtime,
                'duration_s' => $dto->durationseconds,
                'teststrategy' => $dto->teststrategy,
                'status' => $dto->status,
                'total_testitems' => $dto->totaltestitems,
                'used_testitems' => $dto->usedtestitems,
            ];
        }
        return $rows;
    }

    /**
     * Return scale summary rows for the scale_summary sheet.
     *
     * One row per scale with n, mean, median, sd, min, max, q1, q3 of PP.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[] One row per scale; columns: scale_id, scale_name, plus stats keys.
     */
    public function get_scale_summary_rows(attempt_filter $filter): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        $rows = [];
        foreach ($this->activescaleids as $scaleid) {
            $ppvalues = [];
            foreach ($dtos as $dto) {
                $ppvalues[] = $dto->personabilities[$scaleid] ?? null;
            }
            $stats = statistics_helper::descriptive($ppvalues);
            $row = [
                'scale_id' => $scaleid,
                'scale_name' => $this->activescalenames[$scaleid] ?? ('Scale ' . $scaleid),
            ];
            foreach ($stats as $k => $v) {
                $row[$k] = $v;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Return subscale pivot rows for one metric across all attempts.
     *
     * Each row: userid, username, firstname, lastname, plus one column per scale.
     * Used for subscale_scores, subscale_se, subscale_n, subscale_frac sheets.
     *
     * @param attempt_filter $filter Query scope.
     * @param string $metric One of: 'pp', 'se', 'n', 'frac'.
     * @return array[] Pivot rows.
     */
    public function get_subscale_pivot_rows(attempt_filter $filter, string $metric): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        $rows = [];
        foreach ($dtos as $dto) {
            $nitems = se_validator::count_items_per_scale($dto->graphicalsummary);
            $row = [
                'attemptid' => $dto->attemptid,
                'userid' => $dto->userid,
                'firstname' => $dto->firstname,
                'lastname' => $dto->lastname,
            ];
            foreach ($this->activescaleids as $scaleid) {
                switch ($metric) {
                    case 'pp':
                        $row['scale_' . $scaleid] = $dto->personabilities[$scaleid] ?? null;
                        break;
                    case 'se':
                        $row['scale_' . $scaleid] = $dto->se[$scaleid] ?? null;
                        break;
                    case 'n':
                        $row['scale_' . $scaleid] = $nitems[$scaleid] ?? null;
                        break;
                    case 'frac':
                        $row['scale_' . $scaleid] = $this->scale_frac($dto->graphicalsummary, $scaleid);
                        break;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Return aggregate statistics over the filtered attempt set.
     *
     * Returns a map of scaleid => descriptive stats array (n, mean, median,
     * sd, min, max, q1, q3) for personabilityafterattempt.
     *
     * @param attempt_filter $filter Query scope.
     * @return array<int,array> Map of scaleid to statistics_helper::descriptive() result.
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        $stats = [];
        foreach ($this->activescaleids as $scaleid) {
            $ppvalues = [];
            foreach ($dtos as $dto) {
                $ppvalues[] = $dto->personabilities[$scaleid] ?? null;
            }
            $stats[$scaleid] = statistics_helper::descriptive($ppvalues);
        }
        return $stats;
    }

    /**
     * Return column definitions for export headers.
     *
     * Returns all fixed columns plus one set of four dynamic columns per
     * scale encountered in the most recent data fetch.
     * When called before any data fetch, only fixed columns are returned.
     *
     * @return array<string,string> Column key to localised header string.
     */
    public function get_columns(): array {
        $cols = $this->get_fixed_columns();
        foreach ($this->activescaleids as $scaleid) {
            $sname = $this->activescalenames[$scaleid] ?? ('Scale ' . $scaleid);
            $cols['scale_' . $scaleid . '_pp'] = $sname . ' PP';
            $cols['scale_' . $scaleid . '_se'] = $sname . ' SE';
            $cols['scale_' . $scaleid . '_n'] = $sname . ' N';
            $cols['scale_' . $scaleid . '_frac'] = $sname . ' %';
        }
        return $cols;
    }

    /**
     * Return fixed (non-dynamic) column definitions.
     *
     * These are the same for attempts_raw and attempts_wide base columns.
     * Also used by the exporter to build the raw-sheet header.
     *
     * @return array<string,string> Column key to localised header string.
     */
    public function get_fixed_columns(): array {
        $c = 'block_catquiz_statistics';
        return [
            'id' => get_string('report:col_id', $c),
            'userid' => get_string('report:col_userid', $c),
            'username' => get_string('report:col_username', $c),
            'firstname' => get_string('report:col_firstname', $c),
            'lastname' => get_string('report:col_lastname', $c),
            'email' => get_string('report:col_email', $c),
            'testid' => get_string('report:col_testid', $c),
            'attemptid' => get_string('report:col_attemptid', $c),
            'starttime' => get_string('report:col_starttime', $c),
            'endtime' => get_string('report:col_endtime', $c),
            'duration_s' => get_string('report:col_duration_s', $c),
            'teststrategy' => get_string('report:col_teststrategy', $c),
            'status' => get_string('report:col_status', $c),
            'total_testitems' => get_string('report:col_total_testitems', $c),
            'used_testitems' => get_string('report:col_used_testitems', $c),
        ];
    }

    /**
     * Return column definitions for the scale summary sheet.
     *
     * @return array<string,string> Column key to localised header string.
     */
    public function get_scale_summary_columns(): array {
        $c = 'block_catquiz_statistics';
        return [
            'scale_id' => get_string('report:col_scale_id', $c),
            'scale_name' => get_string('report:col_scale_name', $c),
            'n' => get_string('report:col_n', $c),
            'mean' => get_string('report:col_mean', $c),
            'median' => get_string('report:col_median', $c),
            'sd' => get_string('report:col_sd', $c),
            'min' => get_string('report:col_min', $c),
            'max' => get_string('report:col_max', $c),
            'q1' => get_string('report:col_q1', $c),
            'q3' => get_string('report:col_q3', $c),
        ];
    }

    /**
     * Return column definitions for a subscale pivot sheet.
     *
     * Columns: attemptid, userid, firstname, lastname, plus one column per scale.
     *
     * @return array<string,string> Column key to localised header string.
     */
    public function get_subscale_pivot_columns(): array {
        $c = 'block_catquiz_statistics';
        $cols = [
            'attemptid' => get_string('report:col_attemptid', $c),
            'userid' => get_string('report:col_userid', $c),
            'firstname' => get_string('report:col_firstname', $c),
            'lastname' => get_string('report:col_lastname', $c),
        ];
        foreach ($this->activescaleids as $scaleid) {
            $sname = $this->activescalenames[$scaleid] ?? ('Scale ' . $scaleid);
            $cols['scale_' . $scaleid] = $sname;
        }
        return $cols;
    }

    /**
     * Return the active scale IDs from the most recent data fetch.
     *
     * @return int[]
     */
    public function get_active_scale_ids(): array {
        return $this->activescaleids;
    }

    /**
     * Ensure DTOs are loaded for the given filter; use cache when possible.
     *
     * Cache is invalidated when courseid or instanceid changes between calls.
     *
     * @param attempt_filter $filter Query scope.
     * @return attempt_data[] Hydrated DTOs, possibly from cache.
     */
    private function ensure_dtos(attempt_filter $filter): array {
        if (
            $this->dtocache !== null
            && $this->cachedcourseid === $filter->courseid
            && $this->cachedinstanceid === $filter->instanceid
        ) {
            return $this->dtocache;
        }

        $this->dtocache = $this->repository->get_attempts($filter);
        $this->cachedcourseid = $filter->courseid;
        $this->cachedinstanceid = $filter->instanceid;

        // Collect all scale IDs and names from this result set.
        $scaleids = [];
        $scalenames = [];
        foreach ($this->dtocache as $dto) {
            foreach ($dto->catscales as $scaleid => $scale) {
                $sid = (int) $scaleid;
                $scaleids[] = $sid;
                $scalenames[$sid] = $scale->name ?? ('Scale ' . $sid);
            }
        }
        $scaleids = array_unique($scaleids);
        sort($scaleids);
        $this->activescaleids = $scaleids;
        $this->activescalenames = $scalenames;

        return $this->dtocache;
    }

    /**
     * Convert one attempt_data DTO to an associative flat row.
     *
     * Dynamic scale columns (pp, se, n, frac) are added for every scale ID
     * in $scaleids; null is written when the DTO has no data for that scale.
     *
     * @param attempt_data $dto Hydrated attempt DTO.
     * @param int[] $scaleids Complete set of scale IDs for this export run.
     * @return array<string,mixed> Flat row ready for export.
     */
    private function dto_to_flat_row(attempt_data $dto, array $scaleids): array {
        $globalscaleid = $dto->globalscaleid;
        $primaryid = isset($dto->primaryscale->id) ? (int) $dto->primaryscale->id : null;

        $row = [
            'id' => $dto->id,
            'userid' => $dto->userid,
            'username' => $dto->username,
            'firstname' => $dto->firstname,
            'lastname' => $dto->lastname,
            'email' => $dto->email,
            'testid' => $dto->testid,
            'attemptid' => $dto->attemptid,
            'starttime' => $dto->starttime,
            'endtime' => $dto->endtime,
            'duration_s' => $dto->durationseconds,
            'teststrategy' => $dto->teststrategy,
            'status' => $dto->status,
            'total_testitems' => $dto->totaltestitems,
            'used_testitems' => $dto->usedtestitems,
            'global_scale_id' => $globalscaleid,
            'global_scale_name' => $globalscaleid !== null
                ? ($dto->catscales[$globalscaleid]->name ?? null) : null,
            'global_pp' => $globalscaleid !== null
                ? ($dto->personabilities[$globalscaleid] ?? null) : null,
            'global_se' => $globalscaleid !== null
                ? ($dto->se[$globalscaleid] ?? null) : null,
            'primary_scale_id' => $primaryid,
            'primary_scale_name' => $dto->primaryscale->name ?? null,
            'primary_pp' => $primaryid !== null
                ? ($dto->personabilities[$primaryid] ?? null) : null,
            'primary_se' => $primaryid !== null
                ? ($dto->se[$primaryid] ?? null) : null,
        ];

        // Item count and fraction per scale from graphicalsummary.
        $nitems = se_validator::count_items_per_scale($dto->graphicalsummary);
        foreach ($scaleids as $scaleid) {
            $row['scale_' . $scaleid . '_pp'] = $dto->personabilities[$scaleid] ?? null;
            $row['scale_' . $scaleid . '_se'] = $dto->se[$scaleid] ?? null;
            $row['scale_' . $scaleid . '_n'] = $nitems[$scaleid] ?? null;
            $row['scale_' . $scaleid . '_frac'] = $this->scale_frac($dto->graphicalsummary, $scaleid);
        }

        return $row;
    }

    /**
     * Compute mean fraction correct for a given scale from graphicalsummary steps.
     *
     * @param array $graphicalsummary Steps from attempt_data::$graphicalsummary.
     * @param int $scaleid Target scale ID.
     * @return float|null Null when no steps belong to this scale.
     */
    private function scale_frac(array $graphicalsummary, int $scaleid): ?float {
        $fracs = [];
        foreach ($graphicalsummary as $step) {
            if ((int) ($step->questionscale ?? 0) === $scaleid && $step->lastresponse !== null) {
                $fracs[] = (float) $step->lastresponse;
            }
        }
        if (empty($fracs)) {
            return null;
        }
        return array_sum($fracs) / count($fracs);
    }
}
