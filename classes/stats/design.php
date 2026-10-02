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
 * Design matrix from named rows with listwise deletion (complete cases).
 *
 * Categorical predictors are treatment-coded like R (reference = first level
 * in sorted order; columns named <variable><level>). Interactions "a:b" are
 * products of numeric predictors. No imputation: rows with any missing
 * required value are excluded and counted.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class design {
    /**
     * Build y, X and metadata.
     *
     * @param array $rows List of assoc rows (variable => value|null).
     * @param string $outcome Outcome variable.
     * @param string[] $predictors Predictor terms (variables or "a:b" interactions).
     * @param string[] $categorical Variables to treat as categorical.
     * @param string[] $required Additional variables required for the common sample (nested models, paths).
     * @return array{y:float[], x:array, names:string[], ntotal:int, n:int, excluded:int, dummy:array, rows:array}
     * @throws \moodle_exception When too few complete cases remain.
     */
    public static function build(
        array $rows,
        string $outcome,
        array $predictors,
        array $categorical = [],
        array $required = []
    ): array {
        $vars = array_unique(array_merge([$outcome], $required, ...array_map(static fn($t) => explode(':', $t), $predictors)));
        $complete = array_values(array_filter($rows, static function ($r) use ($vars) {
            foreach ($vars as $v) {
                if (!isset($r[$v]) || $r[$v] === '' || (is_float($r[$v]) && is_nan($r[$v]))) {
                    return false;
                }
            }
            return true;
        }));
        $levels = [];
        foreach ($categorical as $c) {
            if (in_array($c, $vars, true)) {
                $l = array_values(array_unique(array_map(static fn($r) => (string) $r[$c], $complete)));
                sort($l, SORT_STRING);
                $levels[$c] = $l;
            }
        }
        $names = ['(Intercept)'];
        $dummy = ['(Intercept)' => false];
        foreach ($predictors as $t) {
            if (isset($levels[$t])) {
                foreach (array_slice($levels[$t], 1) as $level) {
                    $names[] = $t . $level;
                    $dummy[$t . $level] = true;
                }
            } else {
                $names[] = $t;
                $dummy[$t] = false;
            }
        }
        $y = [];
        $x = [];
        foreach ($complete as $r) {
            $y[] = (float) $r[$outcome];
            $row = [1.0];
            foreach ($predictors as $t) {
                if (isset($levels[$t])) {
                    foreach (array_slice($levels[$t], 1) as $level) {
                        $row[] = (string) $r[$t] === $level ? 1.0 : 0.0;
                    }
                } else {
                    $v = 1.0;
                    foreach (explode(':', $t) as $part) {
                        $v *= (float) $r[$part];
                    }
                    $row[] = $v;
                }
            }
            $x[] = $row;
        }
        if (count($y) <= count($names)) {
            throw new \moodle_exception(
                'stats:error:toofewcases',
                'block_catquiz_statistics',
                '',
                (object) ['n' => count($y), 'p' => count($names)]
            );
        }
        return ['y' => $y, 'x' => $x, 'names' => $names, 'ntotal' => count($rows), 'n' => count($y),
            'excluded' => count($rows) - count($y), 'dummy' => $dummy, 'rows' => $complete];
    }
}
