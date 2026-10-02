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

use block_catquiz_statistics\demo\rng;

/**
 * Observed-variable path analysis (Issue #8).
 *
 * A path model is a set of linear regression equations on one common
 * complete-case sample. Reported: unstandardised and standardised path
 * coefficients with SE, 95 %-CI and p; R² per endogenous variable; direct,
 * indirect (product of coefficients, summed over all directed paths) and
 * total effects; percentile bootstrap CIs for indirect effects (configurable
 * replications, deterministic seed). The model must be recursive (acyclic).
 *
 * Wording: "statistical effect" / "path coefficient" / "association" — the
 * model never claims causation.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class path_model {
    /** @var int Below this N a warning about unstable estimates is issued. */
    public const SMALL_N = 50;

    /**
     * Fit the path model.
     *
     * @param array $rows Data rows (numeric variables).
     * @param array $equations endogenous variable => predictor list
     * @param int $bootstrap Bootstrap replications for indirect effects (0 = none).
     * @param int $seed Bootstrap seed.
     * @return array
     * @throws \coding_exception For cyclic models.
     */
    public static function fit(array $rows, array $equations, int $bootstrap = 0, int $seed = 1): array {
        self::assert_acyclic($equations);
        $vars = array_values(array_unique(array_merge(array_keys($equations), ...array_values($equations))));
        $sample = array_values(array_filter($rows, static function ($r) use ($vars) {
            foreach ($vars as $v) {
                if (!isset($r[$v]) || $r[$v] === '') {
                    return false;
                }
            }
            return true;
        }));
        $estimate = self::estimate($sample, $equations);

        $result = [
            'n' => count($sample), 'ntotal' => count($rows), 'excluded' => count($rows) - count($sample),
            'equations' => $estimate['equations'],
            'effects' => self::effects($estimate['coef'], $equations),
            'warnings' => count($sample) < self::SMALL_N ? ['smalln'] : [],
            'bootstrap' => $bootstrap,
            'seed' => $seed,
        ];

        if ($bootstrap > 0) {
            $rng = new rng($seed);
            $draws = [];
            $n = count($sample);
            for ($b = 0; $b < $bootstrap; $b++) {
                $resample = [];
                for ($i = 0; $i < $n; $i++) {
                    $resample[] = $sample[$rng->int(0, $n - 1)];
                }
                try {
                    $coef = self::estimate($resample, $equations)['coef'];
                } catch (singular_matrix_exception $e) {
                    continue;
                }
                foreach (self::effects($coef, $equations) as $key => $eff) {
                    $draws[$key][] = $eff['indirect'];
                }
            }
            foreach ($result['effects'] as $key => &$eff) {
                $d = $draws[$key] ?? [];
                sort($d);
                $eff['bootcilow'] = $d ? descriptive::quantile($d, 0.025) : null;
                $eff['bootcihigh'] = $d ? descriptive::quantile($d, 0.975) : null;
                $eff['bootn'] = count($d);
            }
            unset($eff);
        }
        return $result;
    }

    /**
     * Estimate all equations on one sample.
     *
     * @param array $sample Complete cases.
     * @param array $equations Equations.
     * @return array{equations:array, coef:array}
     */
    private static function estimate(array $sample, array $equations): array {
        $out = [];
        $coef = [];
        foreach ($equations as $endo => $predictors) {
            $fit = linear_regression::fit(design::build($sample, $endo, $predictors), false);
            $out[$endo] = ['r2' => $fit['r2'], 'n' => $fit['n'], 'paths' => []];
            foreach ($predictors as $pred) {
                $c = $fit['coefficients'][$pred];
                $out[$endo]['paths'][$pred] = $c;
                $coef[$pred][$endo] = $c['b'];
            }
        }
        return ['equations' => $out, 'coef' => $coef];
    }

    /**
     * Direct, indirect and total effects for every (source, target) pair connected by a directed path.
     *
     * @param array $coef from => [to => b]
     * @param array $equations Equations.
     * @return array "from->to" => [from, to, direct, indirect, total, paths]
     */
    private static function effects(array $coef, array $equations): array {
        $nodes = array_unique(array_merge(array_keys($equations), ...array_values($equations)));
        $result = [];
        foreach ($nodes as $from) {
            foreach (array_keys($equations) as $to) {
                if ($from === $to) {
                    continue;
                }
                $paths = self::paths($coef, $from, $to, [$from]);
                if (!$paths) {
                    continue;
                }
                $direct = $coef[$from][$to] ?? 0.0;
                $indirect = 0.0;
                $routes = [];
                foreach ($paths as $path) {
                    $prod = 1.0;
                    for ($i = 0; $i < count($path) - 1; $i++) {
                        $prod *= $coef[$path[$i]][$path[$i + 1]];
                    }
                    if (count($path) > 2) {
                        $indirect += $prod;
                        $routes[] = ['path' => $path, 'product' => $prod];
                    }
                }
                $result[$from . '->' . $to] = ['from' => $from, 'to' => $to, 'direct' => $direct, 'indirect' => $indirect,
                    'total' => $direct + $indirect, 'routes' => $routes];
            }
        }
        return $result;
    }

    /**
     * All directed paths from a node to a target.
     *
     * @param array $coef Adjacency with coefficients.
     * @param string $from Current node.
     * @param string $to Target.
     * @param string[] $visited Path so far.
     * @return array list of node lists
     */
    private static function paths(array $coef, string $from, string $to, array $visited): array {
        $result = [];
        foreach (array_keys($coef[$from] ?? []) as $next) {
            if ($next === $to) {
                $result[] = array_merge($visited, [$to]);
            } else if (!in_array($next, $visited, true)) {
                $result = array_merge($result, self::paths($coef, $next, $to, array_merge($visited, [$next])));
            }
        }
        return $result;
    }

    /**
     * Reject cyclic (non-recursive) models.
     *
     * @param array $equations Equations.
     * @throws \coding_exception When a cycle exists.
     */
    private static function assert_acyclic(array $equations): void {
        $state = [];
        $visit = function (string $v) use (&$visit, &$state, $equations): void {
            if (($state[$v] ?? 0) === 1) {
                throw new \coding_exception('Path model contains a cycle at ' . $v);
            }
            if (($state[$v] ?? 0) === 2) {
                return;
            }
            $state[$v] = 1;
            foreach ($equations[$v] ?? [] as $pred) {
                $visit($pred);
            }
            $state[$v] = 2;
        };
        foreach (array_keys($equations) as $v) {
            $visit($v);
        }
    }
}
