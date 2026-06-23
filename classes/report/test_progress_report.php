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
 * Test Progress report (Phase 2b — Modul progress).
 *
 * Expands the graphicalsummary_data trajectory from attempts.json into one
 * row per presented question step.  Available for all 6 standard strategies
 * because graphicalsummary_data is always written by the graphicalsummary
 * feedback generator.
 *
 * Output columns per step:
 *   userid, username, firstname, lastname, attemptid, instanceid,
 *   step_nr         – 1-based position within the attempt
 *   questionname    – item identifier from graphicalsummary_data
 *   questionscale   – scale ID the item was assigned to
 *   questionscale_name
 *   difficulty      – item difficulty parameter (IRT)
 *   lastresponse    – fraction correct for this response (0.0 – 1.0)
 *   fisherinformation
 *   personability_after – estimated person ability after this step
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Test Progress report — per-step trajectory from graphicalsummary_data.
 */
class test_progress_report implements report_interface {
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
        return 'progress';
    }

    /**
     * Human-readable module name (localised).
     *
     * @return string
     */
    public function get_module_name(): string {
        return get_string('module_c', 'block_catquiz_statistics');
    }

    /**
     * Return flat rows — one row per question step per attempt.
     *
     * Attempts with an empty graphicalsummary (no steps recorded) are skipped.
     * Steps within each attempt are ordered by their position in the
     * graphicalsummary_data array (already ordered by the CAT engine).
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_flat_rows(attempt_filter $filter): array {
        $dtos = $this->repository->get_attempts($filter);
        if (empty($dtos)) {
            return [];
        }

        $rows = [];
        foreach ($dtos as $dto) {
            if (empty($dto->graphicalsummary)) {
                continue;
            }
            $stepnr = 1;
            foreach ($dto->graphicalsummary as $step) {
                $rows[] = $this->step_to_row($dto, $step, $stepnr);
                $stepnr++;
            }
        }
        return $rows;
    }

    /**
     * Return aggregate statistics over final personability_after values.
     *
     * Uses the last step of each attempt as the terminal ability estimate.
     *
     * @param attempt_filter $filter Query scope.
     * @return array<string,mixed> Keys: n, mean, median, sd, min, max, q1, q3.
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        $dtos = $this->repository->get_attempts($filter);
        $values = [];
        foreach ($dtos as $dto) {
            if (empty($dto->graphicalsummary)) {
                continue;
            }
            $last = end($dto->graphicalsummary);
            if ($last !== false && $last->personability_after !== null) {
                $values[] = (float) $last->personability_after;
            }
        }
        if (empty($values)) {
            return [];
        }
        sort($values);
        $n = count($values);
        return [
            'n'      => $n,
            'mean'   => array_sum($values) / $n,
            'median' => $this->percentile($values, 50),
            'sd'     => $this->stddev($values),
            'min'    => $values[0],
            'max'    => $values[$n - 1],
            'q1'     => $this->percentile($values, 25),
            'q3'     => $this->percentile($values, 75),
        ];
    }

    /**
     * Return column definitions for the flat export sheet.
     *
     * @return array<string,string>
     */
    public function get_columns(): array {
        $c = 'block_catquiz_statistics';
        return [
            'userid'             => get_string('report:col_userid', $c),
            'username'           => get_string('report:col_username', $c),
            'firstname'          => get_string('report:col_firstname', $c),
            'lastname'           => get_string('report:col_lastname', $c),
            'attemptid'          => get_string('report:col_attemptid', $c),
            'instanceid'         => get_string('report:col_instanceid', $c),
            'step_nr'            => get_string('report:col_step_nr', $c),
            'questionname'       => get_string('report:col_questionname', $c),
            'questionscale'      => get_string('report:col_questionscale', $c),
            'questionscale_name' => get_string('report:col_questionscale_name', $c),
            'difficulty'         => get_string('report:col_difficulty', $c),
            'lastresponse'       => get_string('report:col_lastresponse', $c),
            'fisherinformation'  => get_string('report:col_fisherinformation', $c),
            'personability_after'=> get_string('report:col_personability_after', $c),
        ];
    }

    /**
     * Convert one graphicalsummary step to an export row.
     *
     * @param attempt_data $dto Parent attempt DTO.
     * @param object $step Single step from graphicalsummary_data.
     * @param int $stepnr 1-based step position within the attempt.
     * @return array<string,mixed>
     */
    private function step_to_row(attempt_data $dto, object $step, int $stepnr): array {
        return [
            'userid'              => $dto->userid,
            'username'            => $dto->username,
            'firstname'           => $dto->firstname,
            'lastname'            => $dto->lastname,
            'attemptid'           => $dto->attemptid,
            'instanceid'          => $dto->instanceid,
            'step_nr'             => $stepnr,
            'questionname'        => $step->questionname ?? null,
            'questionscale'       => isset($step->questionscale) ? (int) $step->questionscale : null,
            'questionscale_name'  => $step->questionscale_name ?? null,
            'difficulty'          => isset($step->difficulty) ? (float) $step->difficulty : null,
            'lastresponse'        => isset($step->lastresponse) ? (float) $step->lastresponse : null,
            'fisherinformation'   => isset($step->fisherinformation) ? (float) $step->fisherinformation : null,
            'personability_after' => isset($step->personability_after)
                ? (float) $step->personability_after : null,
        ];
    }

    /**
     * Compute percentile from a sorted array using linear interpolation.
     *
     * @param float[] $sorted Sorted values (ascending).
     * @param float $p Percentile (0–100).
     * @return float|null
     */
    private function percentile(array $sorted, float $p): ?float {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $index = ($p / 100) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }
        $frac = $index - $lower;
        return (float) ($sorted[$lower] * (1 - $frac) + $sorted[$upper] * $frac);
    }

    /**
     * Compute population standard deviation.
     *
     * @param float[] $values Non-empty list of values.
     * @return float|null Null when fewer than two values.
     */
    private function stddev(array $values): ?float {
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(
            static fn($v) => ($v - $mean) ** 2,
            $values
        )) / $n;
        return sqrt($variance);
    }
}
