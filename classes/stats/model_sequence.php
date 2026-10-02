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
 * Nested model sequences / incremental prediction (Issue #8).
 *
 * All models are fitted on ONE common complete-case sample (cases complete for
 * the largest model), so R² / pseudo-R² and their changes are comparable.
 * Linear models report ΔR² with the F-change test; logistic models the
 * likelihood-ratio test. The output never states that a larger R² means a
 * "better" model — it only reports the incremental association.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class model_sequence {
    /**
     * Fit nested models.
     *
     * @param array $rows Data rows.
     * @param string $outcome Outcome.
     * @param array $steps List of predictor lists; each step must contain the previous one.
     * @param string $type linear | logistic
     * @param string[] $categorical Categorical variables.
     * @return array{n:int, ntotal:int, excluded:int, models:array}
     * @throws \coding_exception When the models are not nested.
     */
    public static function fit(
        array $rows,
        string $outcome,
        array $steps,
        string $type = 'linear',
        array $categorical = []
    ): array {
        for ($i = 1; $i < count($steps); $i++) {
            if (array_diff($steps[$i - 1], $steps[$i])) {
                throw new \coding_exception('Model sequence is not nested at step ' . ($i + 1));
            }
        }
        $all = array_unique(array_merge(...array_map(
            static fn($t) => explode(':', $t),
            array_merge(...$steps)
        )));
        $models = [];
        $previous = null;
        foreach ($steps as $i => $predictors) {
            $d = design::build($rows, $outcome, $predictors, $categorical, $all);
            $fit = $type === 'logistic' ? logistic_regression::fit($d) : linear_regression::fit($d, false);
            $entry = ['step' => $i + 1, 'predictors' => $predictors, 'fit' => $fit];
            if ($previous !== null) {
                $k = count($fit['coefficients']) - count($previous['coefficients']);
                if ($type === 'logistic') {
                    $lr = $previous['deviance'] - $fit['deviance'];
                    $entry['change'] = ['deltar2' => $fit['mcfadden'] - $previous['mcfadden'], 'chi2' => $lr, 'df' => $k,
                        'p' => distribution::chi2_upper($lr, $k)];
                } else {
                    $f = (($previous['rss'] - $fit['rss']) / $k) / ($fit['rss'] / $fit['df']);
                    $entry['change'] = ['deltar2' => $fit['r2'] - $previous['r2'], 'f' => $f, 'df1' => $k, 'df2' => $fit['df'],
                        'p' => distribution::f_upper($f, $k, $fit['df'])];
                }
            }
            $models[] = $entry;
            $previous = $fit;
        }
        return ['n' => $models[0]['fit']['n'], 'ntotal' => count($rows), 'excluded' => count($rows) - $models[0]['fit']['n'],
            'models' => $models];
    }
}
