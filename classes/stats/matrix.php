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
 * Minimal dense linear algebra for small statistical models.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class matrix {
    /**
     * Transpose.
     *
     * @param array $a Matrix (rows of floats).
     * @return array
     */
    public static function transpose(array $a): array {
        return $a ? array_map(null, ...$a) : [];
    }

    /**
     * X'WX for a design matrix and optional weights (avoids building the transpose).
     *
     * @param array $x Design matrix n x p.
     * @param float[]|null $w Weights (length n) or null.
     * @return array p x p
     */
    public static function xtwx(array $x, ?array $w = null): array {
        $p = count($x[0]);
        $r = array_fill(0, $p, array_fill(0, $p, 0.0));
        foreach ($x as $i => $row) {
            $wi = $w === null ? 1.0 : $w[$i];
            for ($j = 0; $j < $p; $j++) {
                $v = $row[$j] * $wi;
                for ($k = $j; $k < $p; $k++) {
                    $r[$j][$k] += $v * $row[$k];
                }
            }
        }
        for ($j = 0; $j < $p; $j++) {
            for ($k = 0; $k < $j; $k++) {
                $r[$j][$k] = $r[$k][$j];
            }
        }
        return $r;
    }

    /**
     * X'Wz.
     *
     * @param array $x Design matrix n x p.
     * @param float[] $z Vector length n.
     * @param float[]|null $w Weights or null.
     * @return float[]
     */
    public static function xtwz(array $x, array $z, ?array $w = null): array {
        $p = count($x[0]);
        $r = array_fill(0, $p, 0.0);
        foreach ($x as $i => $row) {
            $v = $z[$i] * ($w === null ? 1.0 : $w[$i]);
            for ($j = 0; $j < $p; $j++) {
                $r[$j] += $row[$j] * $v;
            }
        }
        return $r;
    }

    /**
     * Matrix-vector product.
     *
     * @param array $a Matrix.
     * @param float[] $v Vector.
     * @return float[]
     */
    public static function mulvec(array $a, array $v): array {
        return array_map(static function ($row) use ($v) {
            $s = 0.0;
            foreach ($row as $j => $x) {
                $s += $x * $v[$j];
            }
            return $s;
        }, $a);
    }

    /**
     * Inverse by Gauss-Jordan elimination with partial pivoting.
     *
     * @param array $a Square matrix.
     * @return array
     * @throws singular_matrix_exception When the matrix is (numerically) singular.
     */
    public static function inverse(array $a): array {
        $n = count($a);
        $m = [];
        foreach ($a as $i => $row) {
            $m[$i] = array_merge(array_values($row), array_fill(0, $n, 0.0));
            $m[$i][$n + $i] = 1.0;
        }
        $scale = 0.0;
        foreach ($a as $row) {
            foreach ($row as $v) {
                $scale = max($scale, abs($v));
            }
        }
        for ($c = 0; $c < $n; $c++) {
            $pivot = $c;
            for ($r = $c + 1; $r < $n; $r++) {
                if (abs($m[$r][$c]) > abs($m[$pivot][$c])) {
                    $pivot = $r;
                }
            }
            if (abs($m[$pivot][$c]) <= 1e-12 * max(1.0, $scale)) {
                throw new singular_matrix_exception();
            }
            [$m[$c], $m[$pivot]] = [$m[$pivot], $m[$c]];
            $div = $m[$c][$c];
            for ($k = 0; $k < 2 * $n; $k++) {
                $m[$c][$k] /= $div;
            }
            for ($r = 0; $r < $n; $r++) {
                if ($r !== $c && $m[$r][$c] != 0.0) {
                    $f = $m[$r][$c];
                    for ($k = 0; $k < 2 * $n; $k++) {
                        $m[$r][$k] -= $f * $m[$c][$k];
                    }
                }
            }
        }
        return array_map(static fn($row) => array_slice($row, $n), $m);
    }
}
