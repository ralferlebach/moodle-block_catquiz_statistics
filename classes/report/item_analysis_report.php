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
 * Item & Response Analysis report (Phase 3 — Modul items).
 *
 * Aggregates item-level statistics from graphicalsummary_data (Schicht 2,
 * always available) across all attempts matching the filter.
 *
 * One output row per unique question (identified by questionname):
 *   questionname        – item identifier
 *   questionscale       – scale ID the item was assigned to
 *   questionscale_name  – scale name
 *   difficulty          – IRT difficulty (from graphicalsummary, same value per item)
 *   n_presented         – total times the item was presented
 *   n_correct           – times lastresponse = 1.0 (full credit)
 *   n_incorrect         – times lastresponse = 0.0 (no credit)
 *   n_partial           – times 0 < lastresponse < 1 (partial credit)
 *   frac_correct        – n_correct / n_presented
 *   mean_response       – mean(lastresponse) across all presentations
 *   mean_fisher         – mean(fisherinformation) across all presentations
 *   mean_ability_before – mean(personability_after from previous step) — proxy for
 *                         ability level at time of item presentation
 *
 * Sort order: questionscale ASC, frac_correct DESC, n_presented DESC.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Item & Response Analysis report — aggregated item statistics.
 */
class item_analysis_report implements report_interface {
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
        return 'items';
    }

    /**
     * Human-readable module name (localised).
     *
     * @return string
     */
    public function get_module_name(): string {
        return get_string('module_e', 'block_catquiz_statistics');
    }

    /**
     * Return flat rows — one row per unique question, aggregated across attempts.
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_flat_rows(attempt_filter $filter): array {
        $dtos = $this->repository->get_attempts($filter);
        if (empty($dtos)) {
            return [];
        }

        // Accumulate per-item statistics across all attempts.
        $items = [];
        foreach ($dtos as $dto) {
            $prevability = null;
            foreach ($dto->graphicalsummary as $step) {
                $name = $step->questionname ?? null;
                if ($name === null) {
                    $prevability = $step->personability_after ?? null;
                    continue;
                }

                if (!isset($items[$name])) {
                    $items[$name] = [
                        'questionname'       => $name,
                        'questionscale'      => isset($step->questionscale)
                            ? (int) $step->questionscale : null,
                        'questionscale_name' => $step->questionscale_name ?? null,
                        'difficulty'         => isset($step->difficulty)
                            ? (float) $step->difficulty : null,
                        'n_presented'        => 0,
                        'n_correct'          => 0,
                        'n_incorrect'        => 0,
                        'n_partial'          => 0,
                        'sum_response'       => 0.0,
                        'sum_fisher'         => 0.0,
                        'sum_ability_before' => 0.0,
                        'n_ability_before'   => 0,
                    ];
                }

                $resp = $step->lastresponse ?? null;
                $items[$name]['n_presented']++;
                if ($resp !== null) {
                    $fr = (float) $resp;
                    $items[$name]['sum_response'] += $fr;
                    if ($fr >= 1.0) {
                        $items[$name]['n_correct']++;
                    } else if ($fr <= 0.0) {
                        $items[$name]['n_incorrect']++;
                    } else {
                        $items[$name]['n_partial']++;
                    }
                }
                if (isset($step->fisherinformation)) {
                    $items[$name]['sum_fisher'] += (float) $step->fisherinformation;
                }
                if ($prevability !== null) {
                    $items[$name]['sum_ability_before'] += (float) $prevability;
                    $items[$name]['n_ability_before']++;
                }
                $prevability = $step->personability_after ?? null;
            }
        }

        // Build output rows.
        $rows = [];
        foreach ($items as $item) {
            $n = $item['n_presented'];
            $rows[] = [
                'questionname'       => $item['questionname'],
                'questionscale'      => $item['questionscale'],
                'questionscale_name' => $item['questionscale_name'],
                'difficulty'         => $item['difficulty'],
                'n_presented'        => $n,
                'n_correct'          => $item['n_correct'],
                'n_incorrect'        => $item['n_incorrect'],
                'n_partial'          => $item['n_partial'],
                'frac_correct'       => $n > 0
                    ? round($item['n_correct'] / $n, 4) : null,
                'mean_response'      => $n > 0
                    ? round($item['sum_response'] / $n, 4) : null,
                'mean_fisher'        => $n > 0
                    ? round($item['sum_fisher'] / $n, 4) : null,
                'mean_ability_before' => $item['n_ability_before'] > 0
                    ? round($item['sum_ability_before'] / $item['n_ability_before'], 4) : null,
            ];
        }

        // Sort: scale ASC, frac_correct DESC, n_presented DESC.
        usort($rows, static function (array $a, array $b): int {
            $scalecmp = ($a['questionscale'] ?? 0) <=> ($b['questionscale'] ?? 0);
            if ($scalecmp !== 0) {
                return $scalecmp;
            }
            $fraccmp = ($b['frac_correct'] ?? 0) <=> ($a['frac_correct'] ?? 0);
            if ($fraccmp !== 0) {
                return $fraccmp;
            }
            return ($b['n_presented'] ?? 0) <=> ($a['n_presented'] ?? 0);
        });

        return $rows;
    }

    /**
     * Return aggregate statistics over frac_correct values across all items.
     *
     * @param attempt_filter $filter Query scope.
     * @return array<string,mixed>
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        $rows = $this->get_flat_rows($filter);
        if (empty($rows)) {
            return [];
        }
        $fracs = array_filter(
            array_column($rows, 'frac_correct'),
            static fn($v) => $v !== null
        );
        if (empty($fracs)) {
            return [];
        }
        $fracs = array_values($fracs);
        sort($fracs);
        $n = count($fracs);
        $mean = array_sum($fracs) / $n;
        return [
            'n'    => $n,
            'mean' => round($mean, 4),
            'min'  => $fracs[0],
            'max'  => $fracs[$n - 1],
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
            'questionname'        => get_string('report:col_questionname', $c),
            'questionscale'       => get_string('report:col_questionscale', $c),
            'questionscale_name'  => get_string('report:col_questionscale_name', $c),
            'difficulty'          => get_string('report:col_difficulty', $c),
            'n_presented'         => get_string('report:col_n_presented', $c),
            'n_correct'           => get_string('report:col_n_correct', $c),
            'n_incorrect'         => get_string('report:col_n_incorrect', $c),
            'n_partial'           => get_string('report:col_n_partial', $c),
            'frac_correct'        => get_string('report:col_frac_correct', $c),
            'mean_response'       => get_string('report:col_mean_response', $c),
            'mean_fisher'         => get_string('report:col_mean_fisher', $c),
            'mean_ability_before' => get_string('report:col_mean_ability_before', $c),
        ];
    }
}
