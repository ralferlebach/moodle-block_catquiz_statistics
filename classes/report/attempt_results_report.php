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
 * Fixed columns: id, userid, username, firstname, lastname, email, testid,
 *   attemptid, starttime, endtime, duration_s, teststrategy, status,
 *   total_testitems, used_testitems, global_scale_id/name/pp/se,
 *   primary_scale_id/name/pp/se.
 *
 * Dynamic columns (one set per CAT scale found in the result set):
 *   scale_{id}_pp, scale_{id}_se, scale_{id}_n, scale_{id}_frac.
 *
 * Scales are sorted hierarchically (depth-first, alphabetical by label within
 * each parent) using local_catquiz_catscales.parentid.  Column headers use the
 * catscale label (falling back to name when label is empty).
 *
 * SE validity: NULL is written for scales whose SE exceeds the quiz-configured
 * threshold or whose item count is below the minimum.
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
     * Scale IDs encountered in the most recent get_flat_rows() call, sorted
     * hierarchically (depth-first, alpha-by-label within level).
     *
     * @var int[]
     */
    private array $activescaleids = [];

    /**
     * Scale names keyed by scale ID (from attempt JSON catscales).
     *
     * @var array<int,string>
     */
    private array $activescalenames = [];

    /**
     * Scale display labels keyed by scale ID.
     *
     * Uses local_catquiz_catscales.label; falls back to name when label is empty.
     *
     * @var array<int,string>
     */
    private array $activescalelabels = [];

    /**
     * Full scale metadata (name, label, parentid) from local_catquiz_catscales.
     *
     * Loaded once per filter cycle; used for hierarchical sorting and labels.
     *
     * @var array
     */
    private array $allscalemeta = [];

    /**
     * Cached DTOs for the current filter to avoid repeated DB round-trips.
     *
     * @var attempt_data[]|null
     */
    private ?array $dtocache = null;

    /** @var int|null Courseid used for the current cache. */
    private ?int $cachedcourseid = null;

    /** @var int|null Instanceid used for the current cache. */
    private ?int $cachedinstanceid = null;

    /** @var int[]|null Instanceids array used for the current cache. */
    private ?array $cachedinstanceids = null;

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
        return 'results';
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
            $rows[] = $this->dto_to_fixed_array($dto);
        }
        return $rows;
    }

    /**
     * Return scale summary rows for the scale_summary sheet.
     *
     * One row per scale (hierarchically sorted) with:
     * - scale metadata (id, label, name, parent label)
     * - item statistics (total, productive, difficulty min/max/mean/SD)
     * - PP statistics (n, mean, median, SD, min, max, Q1, Q3)
     *
     * @param attempt_filter $filter Query scope.
     * @return array[] One row per scale.
     */
    public function get_scale_summary_rows(attempt_filter $filter): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        $allstats = $this->compute_pp_stats($dtos);
        $rawitemstats = $this->repository->get_item_stats_by_scale($this->activescaleids);
        $itemstats = $this->aggregate_item_stats_to_parents($rawitemstats);

        $rows = [];
        foreach ($this->activescaleids as $scaleid) {
            $meta = $this->allscalemeta[$scaleid] ?? null;
            $label = $this->activescalelabels[$scaleid] ?? ('Scale ' . $scaleid);
            $name = $this->activescalenames[$scaleid] ?? '';

            $parentlabel = '';
            if ($meta && (int) $meta['parentid'] > 0) {
                $pm = $this->allscalemeta[(int) $meta['parentid']] ?? null;
                if ($pm) {
                    $parentlabel = !empty($pm['label']) ? $pm['label'] : $pm['name'];
                }
            }

            $istat = $itemstats[$scaleid] ?? [
                'total' => null, 'productive' => null,
                'diff_min' => null, 'diff_max' => null,
                'diff_mean' => null, 'diff_sd' => null,
            ];

            $ppstat = $allstats[$scaleid] ?? [
                'n' => 0, 'mean' => null, 'median' => null,
                'sd' => null, 'min' => null, 'max' => null,
                'q1' => null, 'q3' => null,
            ];

            $row = [
                'scale_id' => $scaleid,
                'scale_label' => $label,
                'scale_name' => $name,
                'parent_label' => $parentlabel,
                'items_total' => $istat['total'],
                'items_productive' => $istat['productive'],
                'diff_min' => $istat['diff_min'],
                'diff_max' => $istat['diff_max'],
                'diff_mean' => $istat['diff_mean'],
                'diff_sd' => $istat['diff_sd'],
            ];
            foreach ($ppstat as $k => $v) {
                $row[$k] = $v;
            }
            $rows[] = $row;
        }
        return $rows;
    }


    /**
     * Aggregate item statistics upward through the scale hierarchy.
     *
     * leaf scale items are direct DB counts. Parent scales receive the SUM
     * of all their direct and indirect child scale totals/productives.
     * Difficulty stats for parent scales are computed from the union of
     * child difficulty values.
     *
     * @param array $rawstats Raw stats from get_item_stats_by_scale (scale_id => stats).
     * @return array Same structure as input with parent scales filled in.
     */
    private function aggregate_item_stats_to_parents(array $rawstats): array {
        if (empty($this->allscalemeta)) {
            return $rawstats;
        }
        // Build children map: parent_id => [child_id, ...]
        $children = [];
        foreach ($this->allscalemeta as $sid => $meta) {
            $pid = (int) ($meta['parentid'] ?? 0);
            if ($pid > 0) {
                $children[$pid][] = $sid;
            }
        }

        // Recursive sum via depth-first traversal.
        $result = $rawstats;
        $visited = [];

        $recurse = function (int $sid) use (&$recurse, &$result, $children, &$visited): array {
            if (isset($visited[$sid])) {
                return $result[$sid] ?? ['total' => 0, 'productive' => 0,
                    'diff_min' => null, 'diff_max' => null,
                    'diff_mean' => null, 'diff_sd' => null];
            }
            $visited[$sid] = true;
            if (empty($children[$sid])) {
                // Leaf — return as-is.
                return $result[$sid] ?? ['total' => 0, 'productive' => 0,
                    'diff_min' => null, 'diff_max' => null,
                    'diff_mean' => null, 'diff_sd' => null];
            }
            // Parent — aggregate children.
            $total = $result[$sid]['total'] ?? 0;
            $productive = $result[$sid]['productive'] ?? 0;
            foreach ($children[$sid] as $cid) {
                $child = $recurse($cid);
                $total += (int) ($child['total'] ?? 0);
                $productive += (int) ($child['productive'] ?? 0);
            }
            $result[$sid] = array_merge($result[$sid] ?? [], [
                'total' => $total,
                'productive' => $productive,
            ]);
            return $result[$sid];
        };

        foreach (array_keys($rawstats) as $sid) {
            $recurse($sid);
        }
        return $result;
    }

    /**
     * Return subscale pivot rows for one metric across all attempts.
     *
     * Each row: attemptid, userid, firstname, lastname, plus one column per scale.
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
                    case 'score':
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
                    default:
                        $row['scale_' . $scaleid] = null;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Return aggregate statistics over the filtered attempt set.
     *
     * @param attempt_filter $filter Query scope.
     * @return array Map of scaleid to descriptive stats.
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        $dtos = $this->ensure_dtos($filter);
        if (empty($dtos)) {
            return [];
        }
        return $this->compute_pp_stats($dtos);
    }

    /**
     * Return all column definitions for the wide export sheet.
     *
     * Scale column headers use catscale label (fallback to name).
     *
     * @return array<string,string> Column key to header string.
     */
    public function get_columns(): array {
        $c = 'block_catquiz_statistics';
        $cols = $this->get_fixed_columns();
        // Global and primary scale columns (between fixed and subscale columns).
        $cols['global_scale_id'] = get_string('report:col_global_scale_id', $c);
        $cols['global_scale_name'] = get_string('report:col_global_scale_name', $c);
        $cols['global_score'] = get_string('report:col_global_score', $c);
        $cols['global_se'] = get_string('report:col_global_se', $c);
        $cols['result_scale_id'] = get_string('report:col_result_scale_id', $c);
        $cols['result_scale_name'] = get_string('report:col_result_scale_name', $c);
        $cols['result_score'] = get_string('report:col_result_score', $c);
        $cols['result_se'] = get_string('report:col_result_se', $c);
        foreach ($this->activescaleids as $scaleid) {
            $slabel = $this->activescalelabels[$scaleid] ?? ('Scale ' . $scaleid);
            $cols['scale_' . $scaleid . '_score'] = $slabel . ' PP';
            $cols['scale_' . $scaleid . '_se'] = $slabel . ' SE';
            $cols['scale_' . $scaleid . '_n'] = $slabel . ' N';
            $cols['scale_' . $scaleid . '_frac'] = $slabel . ' %';
        }
        return $cols;
    }

    /**
     * Return fixed (non-dynamic) column definitions for the raw sheet.
     *
     * @return array<string,string>
     */
    public function get_fixed_columns(): array {
        $c = 'block_catquiz_statistics';
        return [
            'attemptid' => get_string('report:col_attemptid', $c),
            'testid' => get_string('report:col_testid', $c),
            'userid' => get_string('report:col_userid', $c),
            'username' => get_string('report:col_username', $c),
            'firstname' => get_string('report:col_firstname', $c),
            'lastname' => get_string('report:col_lastname', $c),
            'email' => get_string('report:col_email', $c),
            'starttime' => get_string('report:col_starttime', $c),
            'endtime' => get_string('report:col_endtime', $c),
            'duration_fmt' => get_string('report:col_duration_fmt', $c),
            'teststrategy' => get_string('report:col_teststrategy', $c),
            'status' => get_string('report:col_status', $c),
            'total_testitems' => get_string('report:col_total_testitems', $c),
            'used_testitems' => get_string('report:col_used_testitems', $c),
        ];
    }

    /**
     * Return column definitions for the scale summary sheet.
     *
     * Includes metadata, item statistics, and PP descriptive statistics.
     *
     * @return array<string,string>
     */
    public function get_scale_summary_columns(): array {
        $c = 'block_catquiz_statistics';
        return [
            'scale_id' => get_string('report:col_scale_id', $c),
            'scale_label' => get_string('report:col_scale_label', $c),
            'scale_name' => get_string('report:col_scale_name', $c),
            'parent_label' => get_string('report:col_parent_label', $c),
            'items_total' => get_string('report:col_items_total', $c),
            'items_productive' => get_string('report:col_items_productive', $c),
            'diff_min' => get_string('report:col_diff_min', $c),
            'diff_max' => get_string('report:col_diff_max', $c),
            'diff_mean' => get_string('report:col_diff_mean', $c),
            'diff_sd' => get_string('report:col_diff_sd', $c),
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
     * Scale column headers use catscale label (fallback to name).
     *
     * @return array<string,string>
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
            $slabel = $this->activescalelabels[$scaleid] ?? ('Scale ' . $scaleid);
            $cols['scale_' . $scaleid] = $slabel;
        }
        return $cols;
    }

    /**
     * Return the active scale IDs in hierarchical order.
     *
     * @return int[]
     */
    public function get_active_scale_ids(): array {
        return $this->activescaleids;
    }

    /**
     * Return structured metadata for the export metadata sheet.
     *
     * Queries Moodle core tables (course, adaptivequiz) for display data.
     * This method is only called during export, not during normal report rendering.
     *
     * @param attempt_filter $filter Active filter.
     * @param string $format Export format identifier (e.g. 'excel').
     * @return array Structured metadata keyed by section.
     */
    public function get_metadata_for_export(attempt_filter $filter, string $format): array {
        global $DB, $CFG;

        $dtos = $this->ensure_dtos($filter);

        // Course info.
        $coursename = $DB->get_field('course', 'fullname', ['id' => $filter->courseid]) ?? '';

        // Instance / test info.
        $testname = '';
        $instanceid = $filter->instanceid;
        if ($instanceid) {
            $testname = $DB->get_field('adaptivequiz', 'name', ['id' => $instanceid]) ?? '';
        }
        $testid = null;
        if (!empty($dtos)) {
            $testid = $dtos[0]->testid;
        }

        // Results summary.
        $totalattempts = count($dtos);
        $userids = [];
        foreach ($dtos as $d) {
            $userids[$d->userid] = true;
        }
        $participants = count($userids);

        // SE validity settings.
        $semax = null;
        $nmin = null;
        if ($instanceid) {
            $sesettings = $this->repository->get_semax_nmin_for_instance($instanceid);
            $semax = $sesettings['semax'];
            $nmin = $sesettings['nmin'];
        }

        // Moodle version string.
        $moodleversion = isset($CFG->release) ? $CFG->release : (string) ($CFG->version ?? '');

        // Plugin version.
        $pluginversion = get_config('block_catquiz_statistics', 'version');

        // Scale hierarchy for display.
        $hierarchyrows = [];
        foreach ($this->activescaleids as $sid) {
            $m = $this->allscalemeta[$sid] ?? null;
            $label = $this->activescalelabels[$sid] ?? ('Scale ' . $sid);
            $parentid = $m ? (int) $m['parentid'] : 0;
            $parentlabel = '';
            if ($parentid && isset($this->allscalemeta[$parentid])) {
                $pm = $this->allscalemeta[$parentid];
                $parentlabel = !empty($pm['label']) ? $pm['label'] : $pm['name'];
            }
            $hierarchyrows[] = [
                'id' => $sid,
                'label' => $label,
                'name' => $m ? $m['name'] : '',
                'parent_label' => $parentlabel,
            ];
        }

        return [
            'plugin_version' => 'block_catquiz_statistics v' . ($pluginversion ?? '?'),
            'export_datetime' => date('Y-m-d H:i:s'),
            'moodle_version' => $moodleversion,
            'format' => $format,
            'coursename' => $coursename,
            'courseid' => $filter->courseid,
            'testname' => $testname,
            'instanceid' => $instanceid ?? '',
            'testid' => $testid ?? '',
            'filter_instanceid' => $instanceid ?? get_string('report:filter_all', 'block_catquiz_statistics'),
            'filter_starttime' => $filter->starttime !== null
                ? date('Y-m-d H:i:s', $filter->starttime) : get_string('report:filter_none', 'block_catquiz_statistics'),
            'filter_endtime' => $filter->endtime !== null
                ? date('Y-m-d H:i:s', $filter->endtime) : get_string('report:filter_none', 'block_catquiz_statistics'),
            'filter_scaleid' => $filter->scaleid ?? get_string('report:filter_all', 'block_catquiz_statistics'),
            'total_attempts' => $totalattempts,
            'participants' => $participants,
            'semax' => $semax,
            'nmin' => $nmin,
            'hierarchy' => $hierarchyrows,
        ];
    }

    /**
     * Sort scale IDs hierarchically: depth-first, alphabetical by label within
     * each parent level.  Uses full catscale metadata (including non-active
     * ancestor scales) so the tree is correctly structured.
     *
     * @param int[] $scaleids Active scale IDs to sort.
     * @param array $allscalemeta Full scale metadata from DB (name, label, parentid).
     * @return array Scale IDs in hierarchical order.
     */
    public static function sort_scale_ids_hierarchically(array $scaleids, array $allscalemeta): array {
        if (empty($scaleids) || empty($allscalemeta)) {
            return $scaleids;
        }

        // Build parent→children map for all known scales.
        $children = [];
        foreach ($allscalemeta as $id => $meta) {
            $pid = (int) ($meta['parentid'] ?? 0);
            $children[$pid][] = $id;
        }

        // Sort children at each level by label, fallback to name.
        foreach ($children as &$childlist) {
            usort($childlist, function (int $a, int $b) use ($allscalemeta): int {
                $la = !empty($allscalemeta[$a]['label'])
                    ? $allscalemeta[$a]['label'] : ($allscalemeta[$a]['name'] ?? '');
                $lb = !empty($allscalemeta[$b]['label'])
                    ? $allscalemeta[$b]['label'] : ($allscalemeta[$b]['name'] ?? '');
                return strcmp($la, $lb);
            });
        }
        unset($childlist);

        // Root IDs: parentid = 0 or parentid not in known scales.
        $allids = array_keys($allscalemeta);
        $roots = array_values(array_filter($allids, function (int $id) use ($allscalemeta, $allids): bool {
            $pid = (int) ($allscalemeta[$id]['parentid'] ?? 0);
            return $pid === 0 || !in_array($pid, $allids, true);
        }));
        usort($roots, function (int $a, int $b) use ($allscalemeta): int {
            $la = !empty($allscalemeta[$a]['label'])
                ? $allscalemeta[$a]['label'] : ($allscalemeta[$a]['name'] ?? '');
            $lb = !empty($allscalemeta[$b]['label'])
                ? $allscalemeta[$b]['label'] : ($allscalemeta[$b]['name'] ?? '');
            return strcmp($la, $lb);
        });

        // Iterative depth-first traversal.
        $sorted = [];
        $stack = array_reverse($roots);
        while (!empty($stack)) {
            $id = array_pop($stack);
            $sorted[] = $id;
            $childlist = array_reverse($children[$id] ?? []);
            foreach ($childlist as $child) {
                $stack[] = $child;
            }
        }

        // Filter to only the active IDs, preserving hierarchical order.
        $activeset = array_flip($scaleids);
        return array_values(array_filter($sorted, function (int $id) use ($activeset): bool {
            return isset($activeset[$id]);
        }));
    }

    /**
     * Compute descriptive statistics for person abilities (PP) per active scale.
     *
     * @param attempt_data[] $dtos Hydrated DTOs.
     * @return array
     */
    private function compute_pp_stats(array $dtos): array {
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
     * Load DTOs from repository or cache; collect and sort active scale IDs.
     *
     * Also loads full catscale metadata from DB for hierarchical sorting and
     * label-based column headers.
     *
     * @param attempt_filter $filter Query scope.
     * @return attempt_data[]
     */
    private function ensure_dtos(attempt_filter $filter): array {
        // Cache is valid only when all filter dimensions match.
        $sameinstanceids = $this->cachedinstanceids === $filter->instanceids
            || (empty($this->cachedinstanceids) && empty($filter->instanceids));
        if (
            $this->dtocache !== null
            && $this->cachedcourseid === $filter->courseid
            && $this->cachedinstanceid === $filter->instanceid
            && $sameinstanceids
        ) {
            return $this->dtocache;
        }

        $this->dtocache = $this->repository->get_attempts($filter);
        $this->cachedcourseid = $filter->courseid;
        $this->cachedinstanceid = $filter->instanceid;
        $this->cachedinstanceids = $filter->instanceids;

        // Collect scale IDs and names from JSON (catscales field).
        $scaleids = [];
        $scalenames = [];
        foreach ($this->dtocache as $dto) {
            foreach ($dto->catscales as $scaleid => $scale) {
                $sid = (int) $scaleid;
                $scaleids[] = $sid;
                $scalenames[$sid] = $scale->name ?? ('Scale ' . $sid);
            }
        }
        $scaleids = array_values(array_unique($scaleids));

        // Load full catscale metadata from DB for hierarchy + labels.
        $this->allscalemeta = $this->repository->get_all_catscale_meta();

        // Sort hierarchically.
        if (!empty($this->allscalemeta)) {
            $scaleids = self::sort_scale_ids_hierarchically($scaleids, $this->allscalemeta);
        } else {
            sort($scaleids);
        }
        $this->activescaleids = $scaleids;
        $this->activescalenames = $scalenames;

        // Build display labels: DB label > DB name > JSON name.
        $this->activescalelabels = [];
        foreach ($scaleids as $sid) {
            $dbmeta = $this->allscalemeta[$sid] ?? null;
            if ($dbmeta && !empty($dbmeta['label'])) {
                $this->activescalelabels[$sid] = $dbmeta['label'];
            } else if ($dbmeta && !empty($dbmeta['name'])) {
                $this->activescalelabels[$sid] = $dbmeta['name'];
            } else {
                $this->activescalelabels[$sid] = $scalenames[$sid] ?? ('Scale ' . $sid);
            }
        }

        return $this->dtocache;
    }

    /**
     * Return the 15 fixed column values for one attempt DTO.
     *
     * Extracted to avoid code duplication between get_raw_rows() and
     * dto_to_flat_row().
     *
     * @param attempt_data $dto Hydrated attempt DTO.
     * @return array<string,mixed> Fixed column key-value pairs.
     */

    /**
     * Convert a teststrategy integer to a human-readable German label.
     *
     * Values 1–6 are defined in local_catquiz (Blueprint §1.1).
     * Values above 6 are extended Wunderbyte strategies; shown as "Strategie N".
     *
     * @param int|null $strategy Strategy constant.
     * @return string
     */
    private function strategy_label(?int $strategy): string {
        $map = [
            1 => 'Alle Subskalen ableiten',
            2 => 'Niedrigste Subskala',
            3 => 'Höchste Subskala',
            4 => 'Zufällige Subskala',
            5 => 'Pilot-Item',
            6 => 'Pilot',
        ];
        if ($strategy === null) {
            return '';
        }
        return $map[$strategy] ?? ('Strategie ' . $strategy);
    }

    /**
     * Convert an attempt status integer to a human-readable German label.
     *
     * 0 = completed (adaptivequiz default); other values = in progress/abandoned.
     *
     * @param int|null $status Status constant.
     * @return string
     */
    private function status_label(?int $status): string {
        $map = [
            0 => 'Abgeschlossen',
            1 => 'In Bearbeitung',
            2 => 'Abgebrochen',
            3 => 'Timeout',
            4 => 'In Bearbeitung',
        ];
        if ($status === null) {
            return '';
        }
        return $map[$status] ?? ('Status ' . $status);
    }

    /**
     * Convert a Unix timestamp to an Excel serial date (days since 1900-01-01).
     *
     * Excel's date system uses 1900-01-00 as epoch (with the 1900 leap-year bug).
     * This matches what Excel and LibreOffice expect for numeric date cells.
     * Returns null for zero/null timestamps (meaning "not set").
     *
     * @param int|null $timestamp Unix timestamp.
     * @return float|null Excel serial date, or null if not set.
     */
    private function unix_to_excel_date(?int $timestamp): ?float {
        if (!$timestamp) {
            return null;
        }
        // Excel epoch: 1899-12-30 (accounts for the 1900 leap-year bug).
        return ($timestamp / 86400.0) + 25569.0;
    }

    /**
     * Format a duration in seconds as "Xh Ymin Zs" or "Xmin Zs" or "Zs".
     * Returns null for zero or null durations.
     *
     * @param int|null $seconds Duration in seconds.
     * @return string|null
     */
    private function format_duration(?int $seconds): ?string {
        if (!$seconds) {
            return null;
        }
        $h = (int) ($seconds / 3600);
        $m = (int) (($seconds % 3600) / 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return $h . 'h ' . $m . 'min ' . $s . 's';
        }
        if ($m > 0) {
            return $m . 'min ' . $s . 's';
        }
        return $s . 's';
    }

    /**
     * Suppress scale values when SE = -1 (no computed value for this scale).
     *
     * SE = -1 is catquiz's sentinel meaning the scale was presented but not
     * yet computed (e.g. the attempt is still in progress or the scale had
     * too few items). In that case pp, se, n and frac are all meaningless.
     *
     * @param float|null $se SE value from the DTO.
     * @param mixed $value The value to return if SE is valid.
     * @return mixed|null Null when SE = -1, otherwise $value.
     */
    private function guard_se(?float $se, mixed $value): mixed {
        if ($se !== null && $se < 0) {
            return null;
        }
        return $value;
    }

    private function dto_to_fixed_array(attempt_data $dto): array {
        // Endtime: if 0 and attempt is completed, fall back to last graphicalsummary timestamp.
        $endtime = ($dto->endtime && $dto->endtime > 0) ? $dto->endtime : null;
        if ($endtime === null && !empty($dto->graphicalsummary)) {
            // Use timestamp from debug_info if available; otherwise leave null.
            $last = end($dto->graphicalsummary);
            if ($last !== false && isset($last->timestamp) && $last->timestamp > 0) {
                $endtime = (int) $last->timestamp;
            }
        }
        $durationsecs = ($dto->durationseconds && $dto->durationseconds > 0)
            ? (int) $dto->durationseconds : null;

        return [
            'attemptid' => $dto->attemptid,
            'testid' => $dto->testid,
            'userid' => $dto->userid,
            'username' => $dto->username,
            'firstname' => $dto->firstname,
            'lastname' => $dto->lastname,
            'email' => $dto->email,
            'starttime' => $this->unix_to_excel_date($dto->starttime),
            'endtime' => $this->unix_to_excel_date($endtime),
            'duration_fmt' => $this->format_duration($durationsecs),
            'teststrategy' => $this->strategy_label($dto->teststrategy),
            'status' => $this->status_label($dto->status),
            'total_testitems' => $dto->totaltestitems,
            'used_testitems' => $dto->usedtestitems,
        ];
    }

    /**
     * Convert one attempt_data DTO to an associative flat row.
     *
     * @param attempt_data $dto Hydrated attempt DTO.
     * @param int[] $scaleids Complete set of scale IDs for this export run.
     * @return array<string,mixed>
     */
    private function dto_to_flat_row(attempt_data $dto, array $scaleids): array {
        $globalscaleid = $dto->globalscaleid;
        $primaryid = isset($dto->primaryscale->id) ? (int) $dto->primaryscale->id : null;

        $row = $this->dto_to_fixed_array($dto);
        $globalse = $globalscaleid !== null ? ($dto->se[$globalscaleid] ?? null) : null;
        $primaryse = $primaryid !== null ? ($dto->se[$primaryid] ?? null) : null;

        $row += [
            'global_scale_id' => $globalscaleid,
            'global_scale_name' => $globalscaleid !== null
                ? ($dto->catscales[$globalscaleid]->name ?? null) : null,
            'global_score' => $this->guard_se($globalse,
                $globalscaleid !== null ? ($dto->personabilities[$globalscaleid] ?? null) : null),
            'global_se' => $globalse && $globalse >= 0 ? $globalse : null,
            'result_scale_id' => $primaryid,
            'result_scale_name' => $dto->primaryscale->name ?? null,
            'result_score' => $this->guard_se($primaryse,
                $primaryid !== null ? ($dto->personabilities[$primaryid] ?? null) : null),
            'result_se' => $primaryse && $primaryse >= 0 ? $primaryse : null,
        ];

        $nitems = se_validator::count_items_per_scale($dto->graphicalsummary);
        foreach ($scaleids as $scaleid) {
            $scalese = $dto->se[$scaleid] ?? null;
            $row['scale_' . $scaleid . '_score'] = $this->guard_se(
                $scalese, $dto->personabilities[$scaleid] ?? null
            );
            $row['scale_' . $scaleid . '_se'] = ($scalese !== null && $scalese >= 0) ? $scalese : null;
            $row['scale_' . $scaleid . '_n'] = $this->guard_se($scalese, $nitems[$scaleid] ?? null);
            $row['scale_' . $scaleid . '_frac'] = $this->guard_se(
                $scalese, $this->scale_frac($dto->graphicalsummary, $scaleid)
            );
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
