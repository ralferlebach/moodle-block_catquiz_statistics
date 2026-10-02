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
 * Binary logistic regression via IRLS (Issue #8), validated against R's glm(binomial).
 *
 * The algorithm mirrors R's glm.fit: start at mu = (y + 0.5) / 2, convergence when
 * |dev - devold| / (|dev| + 0.1) < 1e-8, and the covariance matrix is taken
 * from the weights of the last iteration (as summary.glm), so results agree
 * with R to about 1e-9.
 *
 * Output per coefficient: B, SE, z, p, odds ratio and Wald 95 %-CIs (as R's
 * confint.default) for B and OR. Model: N, deviance, null deviance, AIC, BIC,
 * McFadden and Nagelkerke pseudo-R². Non-convergence or (quasi-)separation is
 * reported as a warning, never silently ignored.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class logistic_regression {
    /**
     * Fit a model on a design built by design::build() (outcome coded 0/1).
     *
     * @param array $d Design.
     * @param int $maxit Maximum IRLS iterations.
     * @return array
     * @throws \moodle_exception When the outcome is not binary.
     */
    public static function fit(array $d, int $maxit = 50): array {
        $y = $d['y'];
        $x = $d['x'];
        $n = count($y);
        $p = count($d['names']);
        foreach ($y as $v) {
            if ($v != 0.0 && $v != 1.0) {
                throw new \moodle_exception('stats:error:notbinary', 'block_catquiz_statistics');
            }
        }
        // Mirror R's glm.fit: start at mu = (y + 0.5) / 2, iterate weighted least squares on the
        // working response, check |dev - devold| / (|dev| + 0.1) < 1e-8 after each update.
        $mu = array_map(static fn($v) => ($v + 0.5) / 2, $y);
        $eta = array_map(static fn($m) => log($m / (1 - $m)), $mu);
        $dev = self::deviance_mu($y, $mu);
        $beta = array_fill(0, $p, 0.0);
        $converged = false;
        $warnings = [];
        $xtwxi = null;
        for ($it = 0; $it < $maxit; $it++) {
            $w = [];
            $z = [];
            foreach ($eta as $i => $e) {
                $w[$i] = max($mu[$i] * (1 - $mu[$i]), 1e-300);
                $z[$i] = $e + ($y[$i] - $mu[$i]) / $w[$i];
            }
            $xtwxi = matrix::inverse(matrix::xtwx($x, $w));
            $beta = matrix::mulvec($xtwxi, matrix::xtwz($x, $z, $w));
            $eta = matrix::mulvec($x, $beta);
            $mu = array_map(static fn($e) => 1 / (1 + exp(-$e)), $eta);
            $devold = $dev;
            $dev = self::deviance_mu($y, $mu);
            if (abs($dev - $devold) / (abs($dev) + 0.1) < 1e-8) {
                $converged = true;
                break;
            }
        }
        // As summary.glm: covariance from the weights of the last iteration.
        $cov = $xtwxi;
        if (!$converged) {
            $warnings[] = 'noconvergence';
        }
        foreach ($beta as $b) {
            if (abs($b) > 15) {
                $warnings[] = 'separation';
                break;
            }
        }

        $z975 = distribution::normal_quantile(0.975);
        $coefs = [];
        foreach ($d['names'] as $j => $name) {
            $se = sqrt($cov[$j][$j]);
            $zv = $beta[$j] / $se;
            $lo = $beta[$j] - $z975 * $se;
            $hi = $beta[$j] + $z975 * $se;
            $coefs[$name] = ['b' => $beta[$j], 'se' => $se, 'z' => $zv, 'p' => distribution::z_pvalue($zv),
                'cilow' => $lo, 'cihigh' => $hi, 'or' => exp($beta[$j]), 'orlow' => exp($lo), 'orhigh' => exp($hi)];
        }
        $ybar = array_sum($y) / $n;
        $nulldev = -2 * (array_sum($y) * log(max($ybar, 1e-12)) + ($n - array_sum($y)) * log(max(1 - $ybar, 1e-12)));
        $nagelkerke = (1 - exp(($dev - $nulldev) / $n)) / (1 - exp(-$nulldev / $n));
        return [
            'type' => 'logistic',
            'n' => $n, 'ntotal' => $d['ntotal'] ?? $n, 'excluded' => $d['excluded'] ?? 0,
            'coefficients' => $coefs,
            'deviance' => $dev,
            'nulldeviance' => $nulldev,
            'aic' => $dev + 2 * $p,
            'bic' => $dev + log($n) * $p,
            'mcfadden' => 1 - $dev / $nulldev,
            'nagelkerke' => $nagelkerke,
            'iterations' => $it + 1,
            'converged' => $converged,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Binomial deviance for fitted probabilities.
     *
     * @param float[] $y Outcome (0/1).
     * @param float[] $mu Fitted probabilities.
     * @return float
     */
    private static function deviance_mu(array $y, array $mu): float {
        $dev = 0.0;
        foreach ($mu as $i => $m) {
            $m = min(max($m, 1e-300), 1 - 1e-16);
            $dev -= 2 * ($y[$i] > 0.5 ? log($m) : log(1 - $m));
        }
        return $dev;
    }
}
