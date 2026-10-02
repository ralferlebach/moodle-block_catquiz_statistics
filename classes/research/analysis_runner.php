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

namespace block_catquiz_statistics\research;

use block_catquiz_statistics\stats\descriptive;
use block_catquiz_statistics\stats\design;
use block_catquiz_statistics\stats\linear_regression;
use block_catquiz_statistics\stats\logistic_regression;
use block_catquiz_statistics\stats\model_sequence;
use block_catquiz_statistics\stats\path_model;

/**
 * Runs analyses on the wide analytic dataset of an evaluation model (Issue #8).
 *
 * Input is the materialised wide view (one row per person, columns from the role
 * mapping). Errors from the statistics layer (too few cases, singular design,
 * non-binary outcome, cyclic path model) are returned as messages, never thrown
 * to the page. Results always state N before and after listwise deletion.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analysis_runner {
    /** @var int Maximum persons analysed in the browser request (larger populations: export or adhoc task). */
    public const MAX_ROWS = 5000;

    /** @var int Maximum bootstrap replications in the browser request. */
    public const MAX_BOOTSTRAP = 2000;

    /**
     * Constructor.
     *
     * @param array $columns Column definitions (dataset_builder::columns()).
     * @param array $rows Wide rows (list of column => value; '' = not available).
     */
    public function __construct(
        /** @var array Column definitions. */
        private readonly array $columns,
        /** @var array Wide rows. */
        private readonly array $rows,
    ) {
    }

    /**
     * Whether a column is numeric (all available values numeric).
     *
     * @param string $column Column.
     * @return bool
     */
    public function is_numeric(string $column): bool {
        foreach ($this->rows as $row) {
            $v = $row[$column] ?? '';
            if ($v !== '' && $v !== null && !is_numeric($v)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a column is binary 0/1.
     *
     * @param string $column Column.
     * @return bool
     */
    public function is_binary(string $column): bool {
        $values = array_unique(array_filter(array_map(
            static fn($r) => $r[$column] ?? '',
            $this->rows
        ), static fn($v) => $v !== '' && $v !== null));
        return $values && !array_diff(array_map('floatval', $values), [0.0, 1.0]);
    }

    /**
     * Descriptive statistics of every column.
     *
     * @return array list of column summaries (numeric or categorical)
     */
    public function describe(): array {
        $result = [];
        foreach ($this->columns as $c) {
            $values = array_map(static fn($r) => ($r[$c['column']] ?? '') === '' ? null : $r[$c['column']], $this->rows);
            if ($this->is_numeric($c['column'])) {
                $result[] = ['column' => $c['column'], 'label' => $c['label'], 'role' => $c['role'], 'kind' => 'numeric']
                    + descriptive::numeric($values);
            } else {
                $result[] = ['column' => $c['column'], 'label' => $c['label'], 'role' => $c['role'], 'kind' => 'categorical']
                    + descriptive::categorical($values);
            }
        }
        return $result;
    }

    /**
     * Regression (optionally as nested sequence: covariates first, then predictors).
     *
     * @param string $outcome Outcome column.
     * @param string[] $predictors Predictor columns.
     * @param string[] $covariates Covariate columns.
     * @param string $type linear | logistic | auto
     * @param bool $sequence Fit M1 (covariates) and M2 (covariates + predictors).
     * @return array{ok:bool, error:?string, type:string, fit:?array, sequence:?array}
     */
    public function regression(
        string $outcome,
        array $predictors,
        array $covariates = [],
        string $type = 'auto',
        bool $sequence = false
    ): array {
        $this->assert_columns(array_merge([$outcome], $predictors, $covariates));
        if ($type === 'auto') {
            $type = $this->is_binary($outcome) ? 'logistic' : 'linear';
        }
        $all = array_values(array_unique(array_merge($covariates, $predictors)));
        $categorical = array_values(array_filter($all, fn($c) => !$this->is_numeric($c)));
        try {
            if (!$this->is_numeric($outcome)) {
                throw new \moodle_exception('analysis:error:outcomenotnumeric', 'block_catquiz_statistics');
            }
            if (empty($all)) {
                throw new \moodle_exception('analysis:error:nopredictors', 'block_catquiz_statistics');
            }
            $rows = $this->numeric_rows(array_merge([$outcome], $all), $categorical);
            if ($sequence && $covariates && $predictors) {
                $seq = model_sequence::fit($rows, $outcome, [$covariates, $all], $type, $categorical);
                // Final model again with diagnostics (VIF) on the same common sample.
                $d = design::build($rows, $outcome, $all, $categorical);
                $fit = $type === 'logistic' ? logistic_regression::fit($d) : linear_regression::fit($d);
                return ['ok' => true, 'error' => null, 'type' => $type, 'fit' => $fit, 'sequence' => $seq,
                    'categorical' => $categorical];
            }
            $d = design::build($rows, $outcome, $all, $categorical);
            $fit = $type === 'logistic' ? logistic_regression::fit($d) : linear_regression::fit($d);
            return ['ok' => true, 'error' => null, 'type' => $type, 'fit' => $fit, 'sequence' => null,
                'categorical' => $categorical];
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'type' => $type, 'fit' => null, 'sequence' => null];
        }
    }

    /**
     * Observed-variable path model from equations in a lavaan-like syntax.
     *
     * One equation per line: "y ~ a + b". Empty lines and lines starting with '#' are ignored.
     *
     * @param string $syntax Equations.
     * @param int $bootstrap Replications for indirect-effect CIs (0 = none).
     * @param int $seed Seed.
     * @return array{ok:bool, error:?string, equations:array, result:?array}
     */
    public function path(string $syntax, int $bootstrap = 0, int $seed = 1): array {
        try {
            $equations = self::parse_equations($syntax);
            $vars = array_values(array_unique(array_merge(array_keys($equations), ...array_values($equations))));
            $this->assert_columns($vars);
            foreach ($vars as $v) {
                if (!$this->is_numeric($v)) {
                    throw new \moodle_exception('analysis:error:pathnumeric', 'block_catquiz_statistics', '', $v);
                }
            }
            $bootstrap = max(0, min($bootstrap, self::MAX_BOOTSTRAP));
            $result = path_model::fit($this->numeric_rows($vars, []), $equations, $bootstrap, $seed);
            return ['ok' => true, 'error' => null, 'equations' => $equations, 'result' => $result];
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'equations' => [], 'result' => null];
        } catch (\coding_exception $e) {
            return ['ok' => false, 'error' => get_string('analysis:error:pathcycle', 'block_catquiz_statistics'),
                'equations' => [], 'result' => null];
        }
    }

    /**
     * Parse "y ~ a + b" lines.
     *
     * @param string $syntax Equations.
     * @return array endogenous => predictors
     * @throws \moodle_exception On syntax errors.
     */
    public static function parse_equations(string $syntax): array {
        $equations = [];
        foreach (preg_split('/\R/', $syntax) as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([a-z][a-z0-9_]*)\s*~\s*([a-z][a-z0-9_]*(\s*\+\s*[a-z][a-z0-9_]*)*)$/', $line, $m)) {
                throw new \moodle_exception('analysis:error:syntax', 'block_catquiz_statistics', '', $n + 1);
            }
            $predictors = array_values(array_unique(array_map('trim', explode('+', $m[2]))));
            $equations[$m[1]] = array_values(array_unique(array_merge($equations[$m[1]] ?? [], $predictors)));
        }
        if (!$equations) {
            throw new \moodle_exception('analysis:error:noequations', 'block_catquiz_statistics');
        }
        return $equations;
    }

    /**
     * Rows restricted to the given columns: numeric strings become floats, '' becomes null.
     *
     * @param string[] $columns Columns.
     * @param string[] $categorical Columns kept as text.
     * @return array
     */
    private function numeric_rows(array $columns, array $categorical): array {
        if (count($this->rows) > self::MAX_ROWS) {
            throw new \moodle_exception('analysis:error:toolarge', 'block_catquiz_statistics', '', self::MAX_ROWS);
        }
        $out = [];
        foreach ($this->rows as $row) {
            $r = [];
            foreach ($columns as $c) {
                $v = $row[$c] ?? '';
                $r[$c] = ($v === '' || $v === null) ? null : (in_array($c, $categorical, true) ? (string) $v : (float) $v);
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Ensure the columns exist.
     *
     * @param string[] $columns Columns.
     * @throws \moodle_exception For unknown columns.
     */
    private function assert_columns(array $columns): void {
        $known = array_column($this->columns, 'column');
        foreach ($columns as $c) {
            if (!in_array($c, $known, true)) {
                throw new \moodle_exception('analysis:error:unknowncolumn', 'block_catquiz_statistics', '', s($c));
            }
        }
    }
}
