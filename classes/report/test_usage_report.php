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
 * Test Usage report (Phase 2a — Modul B).
 *
 * Groups attempts per user × instance, assigns an attempt rank (1 = first),
 * computes Δ ability and the Reliable Change Index (RCI) between consecutive
 * attempt pairs.
 *
 * RCI formula (Jacobson & Truax, 1991):
 *   RCI = Δability / sqrt(SE_earlier² + SE_later²)
 *
 * Interpretation:
 *   |RCI| ≥ 1.96  → reliable change at p < .05
 *   RCI > 0       → improvement
 *   RCI < 0       → decline
 *
 * The global scale (json.catscaleid) is used for all RCI calculations.
 * SE values are taken directly from json.se; no SE validity filtering is
 * applied here because the RCI needs the raw measurement uncertainty.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\local\statistics_helper;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Test Usage report — attempt ranks, Δ ability, and Reliable Change Index.
 */
class test_usage_report implements report_interface {
    /** @var attempt_repository Injected repository. */
    private attempt_repository $repository;

    /**
     * Constructor.
     *
     * @param attempt_repository $repository Injected repository.
     */
    public function __construct(attempt_repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Module identifier.
     *
     * @return string
     */
    public function get_module_id(): string {
        return 'usage';
    }

    /**
     * Human-readable module name (localised).
     *
     * @return string
     */
    public function get_module_name(): string {
        return get_string('module_b', 'block_catquiz_statistics');
    }

    /**
     * Return flat rows with attempt rank, Δ ability, and RCI columns.
     *
     * Rows are ordered by userid ASC, instanceid ASC, starttime ASC so that
     * attempt_rank increments correctly within each user × instance group.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_flat_rows(attempt_filter $filter): array {
        $dtos = $this->repository->get_attempts($filter);
        if (empty($dtos)) {
            return [];
        }

        $this->sort_dtos($dtos);

        // Group by user × globalscale for rank assignment.
        $groups = $this->group_by_user_globalscale($dtos);

        $rows = [];
        foreach ($groups as $group) {
            $rows = array_merge($rows, $this->build_group_rows($group));
        }
        return $rows;
    }

    /**
     * Return aggregate statistics over global-scale PP values.
     *
     * Only attempts with a valid global ability are included.
     *
     * @param attempt_filter $filter Query scope.
     * @return array<string,mixed> Keys: n, mean, median, sd, min, max, q1, q3.
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        $dtos = $this->repository->get_attempts($filter);
        $values = [];
        foreach ($dtos as $dto) {
            $pp = $this->global_pp($dto);
            if ($pp !== null) {
                $values[] = $pp;
            }
        }
        $stats = statistics_helper::descriptive($values);
        if ($stats['n'] === 0) {
            return [];
        }
        return $stats;
    }

    /**
     * Return column definitions for the flat export sheet.
     *
     * @return array<string,string>
     */
    public function get_columns(): array {
        $c = 'block_catquiz_statistics';
        return [
            'userid'          => get_string('report:col_userid', $c),
            'username'        => get_string('report:col_username', $c),
            'firstname'       => get_string('report:col_firstname', $c),
            'lastname'        => get_string('report:col_lastname', $c),
            'email'           => get_string('report:col_email', $c),
            'instanceid'      => get_string('report:col_instanceid', $c),
            'testname'        => get_string('report:col_testname', $c),
            'attemptid'       => get_string('report:col_attemptid', $c),
            'attempt_rank'    => get_string('report:col_attempt_rank', $c),
            'starttime'       => get_string('report:col_starttime', $c),
            'endtime'         => get_string('report:col_endtime', $c),
            'duration_s'      => get_string('report:col_duration_s', $c),
            'status'          => get_string('report:col_status', $c),
            'used_testitems'  => get_string('report:col_used_testitems', $c),
            'global_scale_id' => get_string('report:col_global_scale_id', $c),
            'global_score'    => get_string('report:col_global_score', $c),
            'global_se'       => get_string('report:col_global_se', $c),
            'delta_ability'   => get_string('report:col_delta_ability', $c),
            'rci'             => get_string('report:col_rci', $c),
        ];
    }


    /**
     * Return aggregated summary rows for the browser table.
     *
     * One row per user × global scale (sorted by userid ASC, globalscaleid ASC).
     * Each row summarises all attempts for that user × scale combination:
     *   - n_attempts: total attempts
     *   - best_score: highest global ability value
     *   - delta_ability: ability change from first to last valid attempt (last − first)
     *   - rci: RCI between first and last valid attempt
     *
     * The frontend renders this with simulated cell-merging: for consecutive rows
     * with the same userid, the name columns carry an 'is_first' flag so the
     * Mustache template can suppress repeated values.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_summary_rows(attempt_filter $filter): array {
        $dtos = $this->repository->get_attempts($filter);
        if (empty($dtos)) {
            return [];
        }

        $this->sort_dtos($dtos);

        // Group by user × globalscaleid.
        $groups = [];
        foreach ($dtos as $dto) {
            $key = $dto->userid . '_' . ($dto->globalscaleid ?? 0);
            $groups[$key][] = $dto;
        }

        $rows = [];
        $prevuserid = null;
        foreach ($groups as $group) {
            $first = $group[0];
            $globalscaleid = $first->globalscaleid;
            $scalename = $globalscaleid !== null
                ? ($first->catscales[$globalscaleid]->name ?? null) : null;

            // Collect valid (non-null) pp and se values in time order.
            $validpairs = [];
            foreach ($group as $dto) {
                $pp = $this->global_pp($dto);
                $se = $globalscaleid !== null ? ($dto->se[$globalscaleid] ?? null) : null;
                if ($pp !== null) {
                    $validpairs[] = ['pp' => $pp, 'se' => $se];
                }
            }

            $best = empty($validpairs) ? null
                : max(array_column($validpairs, 'pp'));

            $delta = null;
            $rci = null;
            if (count($validpairs) >= 2) {
                $firstpair = $validpairs[0];
                $lastpair = $validpairs[count($validpairs) - 1];
                $delta = $lastpair['pp'] - $firstpair['pp'];
                $se1 = $firstpair['se'];
                $se2 = $lastpair['se'];
                if ($se1 !== null && $se2 !== null && ($se1 ** 2 + $se2 ** 2) > 0) {
                    $rci = $delta / sqrt($se1 ** 2 + $se2 ** 2);
                }
            }

            $rows[] = [
                'is_first_for_user' => ($first->userid !== $prevuserid),
                'userid'        => $first->userid,
                'firstname'     => $first->firstname,
                'lastname'      => $first->lastname,
                'global_scale_id' => $globalscaleid,
                'global_scale_name' => $scalename,
                'n_attempts'    => count($group),
                'best_score'    => $best,
                'delta_ability' => $delta,
                'rci'           => $rci,
            ];

            $prevuserid = $first->userid;
        }
        return $rows;
    }

    /**
     * Group a sorted list of DTOs by user ID × instance ID.
     *
     * @param attempt_data[] $dtos Sorted DTOs.
     * @return array[] Array of groups; each group is an array of attempt_data.
     */

    /**
     * Sort DTOs by userid ASC, globalscaleid ASC, starttime ASC.
     *
     * @param attempt_data[] $dtos DTOs to sort in-place.
     * @return void
     */
    private function sort_dtos(array &$dtos): void {
        usort($dtos, static function (attempt_data $a, attempt_data $b): int {
            if ($a->userid !== $b->userid) {
                return $a->userid <=> $b->userid;
            }
            if (($a->globalscaleid ?? 0) !== ($b->globalscaleid ?? 0)) {
                return ($a->globalscaleid ?? 0) <=> ($b->globalscaleid ?? 0);
            }
            return ($a->starttime ?? 0) <=> ($b->starttime ?? 0);
        });
    }

    /**
     * Group DTOs by user ID × global scale ID.
     *
     * Used for get_flat_rows() rank assignment and download sort order.
     *
     * @param attempt_data[] $dtos Sorted DTOs.
     * @return array[] Array of groups; each group is an array of attempt_data.
     */
    private function group_by_user_globalscale(array $dtos): array {
        $groups = [];
        foreach ($dtos as $dto) {
            $key = $dto->userid . '_' . ($dto->globalscaleid ?? 0);
            $groups[$key][] = $dto;
        }
        return array_values($groups);
    }

    /**
     * Group DTOs by user ID × instance ID (legacy grouping, kept for test compatibility).
     *
     * @param attempt_data[] $dtos Sorted DTOs.
     * @return array[] Array of groups; each group is an array of attempt_data.
     */
    private function group_by_user_instance(array $dtos): array {
        $groups = [];
        foreach ($dtos as $dto) {
            $key = $dto->userid . '_' . ($dto->instanceid ?? 0);
            $groups[$key][] = $dto;
        }
        return array_values($groups);
    }

    /**
     * Build flat rows for one user × instance group.
     *
     * Assigns attempt_rank (1-based, ascending by starttime).
     * Computes delta_ability and RCI relative to the immediately preceding
     * attempt in the same group.  First attempt always has null for both.
     *
     * @param attempt_data[] $group Sorted attempts for one user × instance.
     * @return array[]
     */
    private function build_group_rows(array $group): array {
        $rows = [];
        $prev = null;
        $rank = 1;
        foreach ($group as $dto) {
            $globalscaleid = $dto->globalscaleid;
            $pp = $this->global_pp($dto);
            $se = $globalscaleid !== null ? ($dto->se[$globalscaleid] ?? null) : null;

            $delta = null;
            $rci = null;
            if ($prev !== null) {
                $prevpp = $this->global_pp($prev);
                $prevse = $prev->globalscaleid !== null
                    ? ($prev->se[$prev->globalscaleid] ?? null) : null;

                if ($prevpp !== null && $pp !== null) {
                    $delta = $pp - $prevpp;
                }
                if (
                    $delta !== null && $prevse !== null && $se !== null
                    && ($prevse ** 2 + $se ** 2) > 0
                ) {
                    $rci = $delta / sqrt($prevse ** 2 + $se ** 2);
                }
            }

            $globalscalename = $globalscaleid !== null
                ? ($dto->catscales[$globalscaleid]->name ?? null) : null;
            $primaryid   = $dto->primaryscale->id ?? null;
            $primaryname = $dto->primaryscale->name ?? null;
            $primarypp   = $primaryid !== null ? ($dto->personabilities[$primaryid] ?? null) : null;
            $primaryse   = $primaryid !== null ? ($dto->se[$primaryid] ?? null) : null;
            $endtime = ($dto->endtime && $dto->endtime > 0) ? $dto->endtime : null;

            $rows[] = [
                'userid'            => $dto->userid,
                'global_scale_id'   => $globalscaleid,
                'username'          => $dto->username,
                'firstname'         => $dto->firstname,
                'lastname'          => $dto->lastname,
                'email'             => $dto->email,
                'testid'            => $dto->testid,
                'attemptid'         => $dto->attemptid,
                'attempt_rank'      => $rank,
                'starttime'         => $dto->starttime,
                'endtime'           => $endtime,
                'duration_s'        => $dto->durationseconds,
                'teststrategy'      => statistics_helper::strategy_label($dto->teststrategy),
                'status'            => statistics_helper::status_label($dto->status),
                'total_testitems'   => $dto->totaltestitems,
                'used_testitems'    => $dto->usedtestitems,
                'globalscale_name'  => $globalscalename,
                'global_score'      => $pp,
                'global_se'         => $se,
                'result_scale_id'   => $primaryid,
                'result_scale_name' => $primaryname,
                'result_score'      => $primarypp,
                'result_se'         => $primaryse,
                'delta_ability'     => $delta,
                'rci'               => $rci,
            ];

            $prev = $dto;
            $rank++;
        }
        return $rows;
    }

    /**
     * Return the global-scale person ability for a DTO, or null.
     *
     * @param attempt_data $dto Hydrated attempt DTO.
     * @return float|null
     */
    private function global_pp(attempt_data $dto): ?float {
        if ($dto->globalscaleid === null) {
            return null;
        }
        return $dto->personabilities[$dto->globalscaleid] ?? null;
    }
}
