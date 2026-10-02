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
 * Distribution functions (normal, Student t, chi-square, F).
 *
 * Implemented via the regularised incomplete gamma and beta functions
 * (series / Lentz continued fractions), accurate to about 1e-13 —
 * validated against R in tests/fixtures/stats/reference.R.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class distribution {
    /** @var float Convergence tolerance. */
    private const EPS = 1e-15;

    /** @var int Maximum iterations. */
    private const MAXIT = 1000;

    /**
     * Standard normal CDF.
     *
     * @param float $x Value.
     * @return float
     */
    public static function normal_cdf(float $x): float {
        $q = self::gamma_q(0.5, $x * $x / 2.0) / 2.0;
        return $x < 0 ? $q : 1.0 - $q;
    }

    /**
     * Standard normal quantile (Acklam's approximation refined by Newton steps).
     *
     * @param float $p Probability in (0, 1).
     * @return float
     */
    public static function normal_quantile(float $p): float {
        $a = [-39.69683028665376, 220.9460984245205, -275.9285104469687, 138.3577518672690, -30.66479806614716, 2.506628277459239];
        $b = [-54.47609879822406, 161.5858368580409, -155.6989798598866, 66.80131188771972, -13.28068155288572];
        $c = [-0.007784894002430293, -0.3223964580411365, -2.400758277161838, -2.549732539343734, 4.374664141464968,
            2.938163982698783];
        $d = [0.007784695709041462, 0.3224671290700398, 2.445134137142996, 3.754408661907416];
        if ($p < 0.02425) {
            $q = sqrt(-2 * log($p));
            $x = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        } else if ($p > 1 - 0.02425) {
            $q = sqrt(-2 * log(1 - $p));
            $x = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        } else {
            $q = $p - 0.5;
            $r = $q * $q;
            $x = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $x -= (self::normal_cdf($x) - $p) * sqrt(2 * M_PI) * exp($x * $x / 2);
        }
        return $x;
    }

    /**
     * Student t CDF.
     *
     * @param float $t Value.
     * @param float $df Degrees of freedom.
     * @return float
     */
    public static function t_cdf(float $t, float $df): float {
        $tail = 0.5 * self::beta_i($df / 2.0, 0.5, $df / ($df + $t * $t));
        return $t >= 0 ? 1.0 - $tail : $tail;
    }

    /**
     * Two-sided p-value of a t statistic.
     *
     * @param float $t Statistic.
     * @param float $df Degrees of freedom.
     * @return float
     */
    public static function t_pvalue(float $t, float $df): float {
        return self::beta_i($df / 2.0, 0.5, $df / ($df + $t * $t));
    }

    /**
     * Two-sided p-value of a z statistic.
     *
     * @param float $z Statistic.
     * @return float
     */
    public static function z_pvalue(float $z): float {
        return self::gamma_q(0.5, $z * $z / 2.0);
    }

    /**
     * Student t quantile (bisection on the CDF).
     *
     * @param float $p Probability.
     * @param float $df Degrees of freedom.
     * @return float
     */
    public static function t_quantile(float $p, float $df): float {
        $lo = -1000.0;
        $hi = 1000.0;
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2.0;
            if (self::t_cdf($mid, $df) < $p) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
            if ($hi - $lo < 1e-13) {
                break;
            }
        }
        return ($lo + $hi) / 2.0;
    }

    /**
     * Upper tail of the chi-square distribution.
     *
     * @param float $x Value.
     * @param float $df Degrees of freedom.
     * @return float
     */
    public static function chi2_upper(float $x, float $df): float {
        return $x <= 0 ? 1.0 : self::gamma_q($df / 2.0, $x / 2.0);
    }

    /**
     * Upper tail of the F distribution.
     *
     * @param float $f Value.
     * @param float $d1 Numerator df.
     * @param float $d2 Denominator df.
     * @return float
     */
    public static function f_upper(float $f, float $d1, float $d2): float {
        return $f <= 0 ? 1.0 : self::beta_i($d2 / 2.0, $d1 / 2.0, $d2 / ($d2 + $d1 * $f));
    }

    /**
     * Regularised upper incomplete gamma Q(a, x).
     *
     * @param float $a Shape.
     * @param float $x Value.
     * @return float
     */
    public static function gamma_q(float $a, float $x): float {
        if ($x <= 0) {
            return 1.0;
        }
        $lng = self::lgamma($a);
        if ($x < $a + 1.0) {
            $ap = $a;
            $sum = 1.0 / $a;
            $del = $sum;
            for ($n = 0; $n < self::MAXIT; $n++) {
                $ap += 1.0;
                $del *= $x / $ap;
                $sum += $del;
                if (abs($del) < abs($sum) * self::EPS) {
                    break;
                }
            }
            return 1.0 - $sum * exp(-$x + $a * log($x) - $lng);
        }
        $b = $x + 1.0 - $a;
        $c = 1.0 / 1e-300;
        $d = 1.0 / $b;
        $h = $d;
        for ($i = 1; $i < self::MAXIT; $i++) {
            $an = -$i * ($i - $a);
            $b += 2.0;
            $d = $an * $d + $b;
            $d = abs($d) < 1e-300 ? 1e-300 : $d;
            $c = $b + $an / $c;
            $c = abs($c) < 1e-300 ? 1e-300 : $c;
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;
            if (abs($del - 1.0) < self::EPS) {
                break;
            }
        }
        return exp(-$x + $a * log($x) - $lng) * $h;
    }

    /**
     * Regularised incomplete beta I_x(a, b).
     *
     * @param float $a Parameter a.
     * @param float $b Parameter b.
     * @param float $x Value in [0, 1].
     * @return float
     */
    public static function beta_i(float $a, float $b, float $x): float {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }
        $bt = exp(self::lgamma($a + $b) - self::lgamma($a) - self::lgamma($b) + $a * log($x) + $b * log(1.0 - $x));
        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $bt * self::betacf($a, $b, $x) / $a;
        }
        return 1.0 - $bt * self::betacf($b, $a, 1.0 - $x) / $b;
    }

    /**
     * Continued fraction for the incomplete beta function (Lentz).
     *
     * @param float $a Parameter a.
     * @param float $b Parameter b.
     * @param float $x Value.
     * @return float
     */
    private static function betacf(float $a, float $b, float $x): float {
        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        $d = abs($d) < 1e-300 ? 1e-300 : $d;
        $d = 1.0 / $d;
        $h = $d;
        for ($m = 1; $m <= self::MAXIT; $m++) {
            $m2 = 2 * $m;
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            $d = abs($d) < 1e-300 ? 1e-300 : $d;
            $c = 1.0 + $aa / $c;
            $c = abs($c) < 1e-300 ? 1e-300 : $c;
            $d = 1.0 / $d;
            $h *= $d * $c;
            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            $d = abs($d) < 1e-300 ? 1e-300 : $d;
            $c = 1.0 + $aa / $c;
            $c = abs($c) < 1e-300 ? 1e-300 : $c;
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;
            if (abs($del - 1.0) < self::EPS) {
                break;
            }
        }
        return $h;
    }

    /**
     * Log-gamma (Lanczos, g = 7, n = 9), accurate to ~1e-15 for positive arguments.
     *
     * @param float $x Positive value.
     * @return float
     */
    public static function lgamma(float $x): float {
        $g = [0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313, -176.61502916214059,
            12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7];
        if ($x < 0.5) {
            return log(M_PI / abs(sin(M_PI * $x))) - self::lgamma(1.0 - $x);
        }
        $x -= 1.0;
        $a = $g[0];
        $t = $x + 7.5;
        for ($i = 1; $i < 9; $i++) {
            $a += $g[$i] / ($x + $i);
        }
        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }
}
