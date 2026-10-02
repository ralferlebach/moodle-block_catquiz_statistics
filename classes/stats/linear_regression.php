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
 * Ordinary least squares regression (Issue #8), validated against R's lm().
 *
 * Output per coefficient: B, SE, t, p, 95 %-CI and the standardised beta
 * (B * sd(x) / sd(y), not reported for the intercept and dummy columns).
 * Model: N, R², adjusted R², residual SE, AIC/BIC (as R's logLik for lm),
 * residual quantiles, Cook's distance indicator (> 4/N, flagged only — never
 * removed) and VIF per predictor column.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class linear_regression {
    /**
     * Fit a model on a design built by design::build().
     *
     * @param array $d Design.
     * @param bool $diagnostics Also compute VIF (one auxiliary regression per column).
     * @return array
     */
    public static function fit(array $d, bool $diagnostics = true): array {
        $y = $d['y'];
        $x = $d['x'];
        $n = count($y);
        $p = count($d['names']);
        $xtxi = matrix::inverse(matrix::xtwx($x));
        $beta = matrix::mulvec($xtxi, matrix::xtwz($x, $y));
        $fitted = matrix::mulvec($x, $beta);
        $res = [];
        $rss = 0.0;
        foreach ($y as $i => $yi) {
            $res[$i] = $yi - $fitted[$i];
            $rss += $res[$i] ** 2;
        }
        $ymean = array_sum($y) / $n;
        $tss = 0.0;
        foreach ($y as $yi) {
            $tss += ($yi - $ymean) ** 2;
        }
        $df = $n - $p;
        $sigma2 = $rss / $df;
        $tcrit = distribution::t_quantile(0.975, $df);
        $sdy = descriptive::sd($y);

        $coefs = [];
        foreach ($d['names'] as $j => $name) {
            $se = sqrt($sigma2 * $xtxi[$j][$j]);
            $t = $beta[$j] / $se;
            $std = null;
            if ($j > 0 && empty($d['dummy'][$name])) {
                $std = $beta[$j] * descriptive::sd(array_column($x, $j)) / $sdy;
            }
            $coefs[$name] = ['b' => $beta[$j], 'se' => $se, 't' => $t, 'p' => distribution::t_pvalue($t, $df),
                'cilow' => $beta[$j] - $tcrit * $se, 'cihigh' => $beta[$j] + $tcrit * $se, 'beta' => $std];
        }
        $r2 = 1.0 - $rss / $tss;
        $loglik = 0.5 * (-$n * (log(2 * M_PI) + 1 - log($n) + log($rss)));
        $k = $p + 1;

        $cook = 0;
        foreach ($x as $i => $row) {
            $h = 0.0;
            foreach ($row as $a => $va) {
                foreach ($row as $b => $vb) {
                    $h += $va * $xtxi[$a][$b] * $vb;
                }
            }
            $di = ($res[$i] ** 2) / ($p * $sigma2) * $h / ((1 - $h) ** 2);
            $cook += $di > 4 / $n ? 1 : 0;
        }
        $sorted = $res;
        sort($sorted);

        $vif = [];
        if ($diagnostics && $p > 2) {
            for ($j = 1; $j < $p; $j++) {
                $aux = ['y' => array_column($x, $j), 'x' => array_map(static fn($row) => array_values(array_merge(
                    [1.0],
                    array_slice($row, 1, $j - 1),
                    array_slice($row, $j + 1)
                )), $x),
                    'names' => array_fill(0, $p - 1, 'v'), 'dummy' => []];
                try {
                    $r2j = self::fit($aux, false)['r2'];
                    $vif[$d['names'][$j]] = $r2j < 1 ? 1 / (1 - $r2j) : null;
                } catch (singular_matrix_exception $e) {
                    $vif[$d['names'][$j]] = null;
                }
            }
        }

        return [
            'type' => 'linear',
            'n' => $n, 'ntotal' => $d['ntotal'] ?? $n, 'excluded' => $d['excluded'] ?? 0,
            'coefficients' => $coefs,
            'r2' => $r2,
            'adjr2' => 1.0 - (1.0 - $r2) * ($n - 1) / $df,
            'sigma' => sqrt($sigma2),
            'df' => $df,
            'rss' => $rss,
            'aic' => -2 * $loglik + 2 * $k,
            'bic' => -2 * $loglik + log($n) * $k,
            'residuals' => ['min' => $sorted[0], 'q1' => descriptive::quantile($sorted, .25),
                'median' => descriptive::quantile($sorted, .5), 'q3' => descriptive::quantile($sorted, .75),
                'max' => $sorted[$n - 1]],
            'cookover' => $cook,
            'vif' => $vif,
        ];
    }
}
