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
 * Pure statistical computation helper for CAT quiz statistics.
 *
 * All methods are static; no DB access, no Moodle globals.
 * Q1/Q3 use Excel-compatible linear interpolation (QUARTILE.INC method).
 * SD is the sample standard deviation (divides by n-1).
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\local;

/**
 * Descriptive statistics helper.
 *
 * Usage:
 *   $stats = statistics_helper::descriptive([0.52, 0.78, 0.33, 0.61]);
 *   // => ['n'=>4, 'mean'=>0.56, 'median'=>0.565, 'sd'=>0.1875, ...]
 */
class statistics_helper {
    /**
     * Compute descriptive statistics for a set of numeric values.
     *
     * Null values and non-finite entries are silently excluded before
     * calculation so callers can pass DTO fields directly without pre-filtering.
     *
     * Percentile method: Excel QUARTILE.INC (linear interpolation, inclusive).
     * SD: sample standard deviation (divisor n-1); null when n < 2.
     *
     * @param array $values Array of float|int|null mixed; non-numeric/infinite excluded.
     * @return array Associative array with keys: n, mean, median, sd, min, max, q1, q3.
     */
    public static function descriptive(array $values): array {
        $empty = [
            'n' => 0, 'mean' => null, 'median' => null, 'sd' => null,
            'min' => null, 'max' => null, 'q1' => null, 'q3' => null,
        ];

        $filtered = array_values(array_filter(
            $values,
            static fn($v) => $v !== null && is_numeric($v) && is_finite((float) $v)
        ));

        $n = count($filtered);
        if ($n === 0) {
            return $empty;
        }

        $sorted = $filtered;
        sort($sorted);

        $mean = array_sum($sorted) / $n;

        $sd = null;
        if ($n >= 2) {
            $sumsq = array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $sorted));
            $sd = sqrt($sumsq / ($n - 1));
        }

        return [
            'n'      => $n,
            'mean'   => $mean,
            'median' => self::percentile($sorted, 0.5),
            'sd'     => $sd,
            'min'    => (float) $sorted[0],
            'max'    => (float) $sorted[$n - 1],
            'q1'     => self::percentile($sorted, 0.25),
            'q3'     => self::percentile($sorted, 0.75),
        ];
    }

    /**
     * Linear-interpolated percentile (Excel QUARTILE.INC / PERCENTILE.INC method).
     *
     * Position formula: pos = (n - 1) * p (0-indexed, inclusive endpoints).
     * If pos falls on an integer index, that element is returned directly.
     * Otherwise, linear interpolation between floor(pos) and ceil(pos) is used.
     *
     * Compatible with Excel QUARTILE.INC, QUARTILE, PERCENTILE.INC.
     *
     * @param array $sorted Pre-sorted numeric array (ascending, no nulls).
     * @param float $p Percentile fraction 0.0 to 1.0 inclusive.
     * @return float Computed percentile value.
     */
    private static function percentile(array $sorted, float $p): float {
        $n = count($sorted);
        $pos = ($n - 1) * $p;
        $low = (int) floor($pos);
        $frac = $pos - $low;

        if ($frac === 0.0) {
            return (float) $sorted[$low];
        }
        return (float) $sorted[$low] + $frac * ((float) $sorted[$low + 1] - (float) $sorted[$low]);
    }

    /**
     * Convert a teststrategy integer to a human-readable German label.
     *
     * Values 1–6 are defined in local_catquiz (Blueprint §1.1).
     * Values above 6 are extended Wunderbyte strategies; shown as "Strategie N".
     *
     * @param int|null $strategy Strategy constant.
     * @return string
     */
    public static function strategy_label(?int $strategy): string {
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
     * 0 = completed (adaptivequiz default); other values = in progress or abandoned.
     *
     * @param int|null $status Status constant.
     * @return string
     */
    public static function status_label(?int $status): string {
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
}
