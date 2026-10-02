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

namespace block_catquiz_statistics\stats;

/**
 * Descriptive statistics (Issue #8). Quantiles use R's default type 7.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class descriptive {
    /**
     * Numeric summary; null entries count as missing.
     *
     * @param array $values Values (float|int|null).
     * @return array n, missing, missingpct, mean, sd, median, q1, q3, iqr, min, max
     */
    public static function numeric(array $values): array {
        $x = array_values(array_map('floatval', array_filter($values, static fn($v) => $v !== null)));
        $total = count($values);
        $n = count($x);
        $result = ['n' => $n, 'missing' => $total - $n, 'missingpct' => $total ? 100 * ($total - $n) / $total : 0.0,
            'mean' => null, 'sd' => null, 'median' => null, 'q1' => null, 'q3' => null, 'iqr' => null, 'min' => null,
            'max' => null];
        if ($n === 0) {
            return $result;
        }
        sort($x);
        $mean = array_sum($x) / $n;
        $ss = 0.0;
        foreach ($x as $v) {
            $ss += ($v - $mean) ** 2;
        }
        $q1 = self::quantile($x, 0.25);
        $q3 = self::quantile($x, 0.75);
        return array_merge($result, ['mean' => $mean, 'sd' => $n > 1 ? sqrt($ss / ($n - 1)) : null,
            'median' => self::quantile($x, 0.5), 'q1' => $q1, 'q3' => $q3, 'iqr' => $q3 - $q1,
            'min' => $x[0], 'max' => $x[$n - 1]]);
    }

    /**
     * Categorical summary; null entries count as missing.
     *
     * @param array $values Values.
     * @return array n, missing, missingpct, frequencies (value => [n, pct])
     */
    public static function categorical(array $values): array {
        $present = array_filter($values, static fn($v) => $v !== null);
        $counts = array_count_values(array_map('strval', $present));
        ksort($counts, SORT_STRING);
        $n = count($present);
        $freq = [];
        foreach ($counts as $value => $c) {
            $freq[(string) $value] = ['n' => $c, 'pct' => 100 * $c / $n];
        }
        return ['n' => $n, 'missing' => count($values) - $n,
            'missingpct' => count($values) ? 100 * (count($values) - $n) / count($values) : 0.0, 'frequencies' => $freq];
    }

    /**
     * Quantile type 7 of a sorted array.
     *
     * @param float[] $sorted Sorted values.
     * @param float $p Probability.
     * @return float
     */
    public static function quantile(array $sorted, float $p): float {
        $n = count($sorted);
        $h = ($n - 1) * $p;
        $lo = (int) floor($h);
        $hi = min($lo + 1, $n - 1);
        return $sorted[$lo] + ($h - $lo) * ($sorted[$hi] - $sorted[$lo]);
    }

    /**
     * Sample standard deviation.
     *
     * @param float[] $x Values.
     * @return float
     */
    public static function sd(array $x): float {
        $n = count($x);
        $m = array_sum($x) / $n;
        $ss = 0.0;
        foreach ($x as $v) {
            $ss += ($v - $m) ** 2;
        }
        return sqrt($ss / ($n - 1));
    }
}
