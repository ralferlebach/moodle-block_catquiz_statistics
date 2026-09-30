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

namespace block_catquiz_statistics\import;

use block_catquiz_statistics\analytics\provider\outcome_provider;
use block_catquiz_statistics\local\statistics_helper;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Data-quality overview per variable and construct of a dataset (Issue #3).
 *
 * Reports only; never modifies or "cleans" data.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_quality {
    /**
     * Constructor.
     *
     * @param observation_repository $observations Observation repository.
     */
    public function __construct(
        /** @var observation_repository Observations. */
        private readonly observation_repository $observations = new observation_repository(),
    ) {
    }

    /**
     * Quality figures for all variables and construct scores of a dataset.
     *
     * @param int $datasetid Dataset id.
     * @return array variablekey => [label, shortname, total, n, missing, missingpct, invalid, categories,
     *               min, max, mean, sd, median, iqr]
     */
    public function for_dataset(int $datasetid): array {
        $groups = [];
        foreach ([false, true] as $constructs) {
            foreach ($this->observations->find_for_dataset($datasetid, null, $constructs) as $obs) {
                $groups[$obs->variablekey][] = $obs;
            }
        }
        $result = [];
        foreach ($groups as $key => $list) {
            $observed = array_filter($list, static fn($o) => $o->status->has_value());
            $missing = count(array_filter($list, static fn($o) => $o->status->is_missing()));
            $numeric = array_values(array_filter(array_map(static fn($o) => $o->valuenumeric, $observed), 'is_numeric'));
            $values = array_map(static fn($o) => (string) $o->get_value(), $observed);
            $desc = statistics_helper::descriptive($numeric);
            $result[$key] = [
                'label' => $list[0]->label,
                'shortname' => $list[0]->attributes['shortname'] ?? null,
                'total' => count($list),
                'n' => count($observed),
                'missing' => $missing,
                'missingpct' => count($list) ? round(100 * $missing / count($list), 1) : 0.0,
                'invalid' => count(array_filter($list, static fn($o) => $o->status->value === 'invalid')),
                'categories' => count(array_unique($values)),
                'min' => $desc['min'],
                'max' => $desc['max'],
                'mean' => $desc['mean'],
                'sd' => $desc['sd'],
                'median' => $desc['median'],
                'iqr' => ($desc['q1'] !== null && $desc['q3'] !== null) ? $desc['q3'] - $desc['q1'] : null,
            ];
        }
        return $result;
    }

    /**
     * Quality figures of an outcome definition (Issue #6).
     *
     * @param \stdClass $definition Outcome definition.
     * @param int[]|null $userids Population (null = gradable users of the source course).
     * @return array n, missing (by status), timemin, timemax, min, max, categories, grademin, grademax, gradepass
     */
    public function for_outcome(\stdClass $definition, ?array $userids = null): array {
        $provider = new outcome_provider();
        $list = $provider->for_definition($definition, $userids);
        $observed = array_values(array_filter($list, static fn($o) => $o->status->has_value()));
        $missing = [];
        foreach ($list as $o) {
            if (!$o->status->has_value()) {
                $missing[$o->status->value] = ($missing[$o->status->value] ?? 0) + 1;
            }
        }
        $numbers = array_values(array_filter(array_map(static fn($o) => $o->valuenumeric, $observed), 'is_numeric'));
        $times = array_values(array_filter(array_map(static fn($o) => $o->occurredat, $observed)));
        $item = $provider->get_grade_item($definition);
        return [
            'total' => count($list),
            'n' => count($observed),
            'missing' => $missing,
            'timemin' => $times ? min($times) : null,
            'timemax' => $times ? max($times) : null,
            'min' => $numbers ? min($numbers) : null,
            'max' => $numbers ? max($numbers) : null,
            'categories' => count(array_unique(array_map(static fn($o) => var_export($o->get_value(), true), $observed))),
            'grademin' => $item ? (float) $item->grademin : null,
            'grademax' => $item ? (float) $item->grademax : null,
            'gradepass' => ($item && (float) $item->gradepass > 0) ? (float) $item->gradepass : null,
        ];
    }
}
