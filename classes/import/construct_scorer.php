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

use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_status;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\repository\construct_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Reproducible construct/subscale scoring from raw item observations (Issue #3).
 *
 * Raw values stay untouched. Scores are stored as derived observations
 * (constructid set) per person and timepoint, each with provenance: construct
 * version, scoring rule, source variables, reverse-coded items, number of
 * valid items and time of calculation. Rescoring replaces earlier scores of
 * the construct in the dataset, so only the current rule version exists.
 *
 * Rules: a valid item is an OBSERVED numeric value. Reverse coding maps x to
 * min + max - x using the item's allowedvalues range. With fewer than
 * minvaliditems valid items the score is MISSING_INSUFFICIENT.
 * Aggregations: mean; weightedmean (weights of valid items); sum = prorated
 * sum (mean of valid items x number of items).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class construct_scorer {
    /**
     * Constructor.
     *
     * @param construct_repository $constructs Construct repository.
     * @param observation_repository $observations Observation repository.
     */
    public function __construct(
        /** @var construct_repository Constructs. */
        private readonly construct_repository $constructs = new construct_repository(),
        /** @var observation_repository Observations. */
        private readonly observation_repository $observations = new observation_repository(),
    ) {
    }

    /**
     * Score a construct for all persons/timepoints of a dataset.
     *
     * @param int $constructid Construct id.
     * @param int $datasetid Dataset id.
     * @return array{scored:int, insufficient:int}
     * @throws \coding_exception For unknown constructs or constructs without items.
     */
    public function score(int $constructid, int $datasetid): array {
        global $DB;

        $construct = $this->constructs->get($constructid);
        if (!$construct || empty($construct->items)) {
            throw new \coding_exception('Construct without items: ' . $constructid);
        }
        $items = [];
        foreach ($construct->items as $item) {
            $items[(int) $item->variableid] = $item;
        }
        $groups = [];
        foreach ($this->observations->find_for_dataset($datasetid, array_keys($items)) as $obs) {
            $groups[$obs->userid . '|' . ($obs->timepoint ?? '')][] = $obs;
        }

        $counts = ['scored' => 0, 'insufficient' => 0];
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $this->observations->delete_construct_scores($constructid, $datasetid);
        foreach ($groups as $group) {
            $first = $group[0];
            [$value, $valid] = $this->aggregate($construct, $items, $group);
            $status = $value === null ? observation_status::MISSING_INSUFFICIENT : observation_status::OBSERVED;
            $counts[$value === null ? 'insufficient' : 'scored']++;
            $this->observations->upsert(new observation(
                userid: $first->userid,
                variablekey: 'construct:' . $constructid,
                sourcecomponent: csv_importer::COMPONENT,
                sourcearea: 'construct',
                sourcekey: 'construct:' . $constructid . ':ds' . $datasetid . ':u' . $first->userid
                    . ':tp' . ($first->timepoint ?? ''),
                origincontextid: $first->origincontextid,
                valuetype: value_type::NUMERIC,
                status: $status,
                origincourseid: $first->origincourseid,
                occurredat: max(array_map(static fn($o) => $o->occurredat ?? 0, $group)) ?: null,
                timepoint: $first->timepoint,
                valuenumeric: $value,
                datasetid: $datasetid,
                constructid: $constructid,
                provenance: [
                    'constructversion' => (int) $construct->version,
                    'aggregation' => $construct->aggregation,
                    'minvaliditems' => (int) $construct->minvaliditems,
                    'sourcevariables' => array_values(array_map(static fn($i) => $i->shortname, $items)),
                    'reversecoded' => array_values(array_map(
                        static fn($i) => $i->shortname,
                        array_filter($items, static fn($i) => (int) $i->reversecoded === 1)
                    )),
                    'validitems' => $valid,
                    'calculatedat' => $now,
                ],
            ));
        }
        $transaction->allow_commit();
        return $counts;
    }

    /**
     * Aggregate the item observations of one person/timepoint.
     *
     * @param \stdClass $construct Construct incl. items.
     * @param array $items variableid => item row.
     * @param observation[] $group Observations of one person/timepoint.
     * @return array [float|null value, int validitems]
     */
    public function aggregate(\stdClass $construct, array $items, array $group): array {
        $values = [];
        $weights = [];
        foreach ($group as $obs) {
            $item = $items[$obs->variableid] ?? null;
            if ($item === null || !$obs->status->has_value() || $obs->valuenumeric === null) {
                continue;
            }
            $x = $obs->valuenumeric;
            if ((int) $item->reversecoded === 1) {
                [$min, $max] = construct_repository::range_of($item);
                $x = $min + $max - $x;
            }
            $values[] = $x;
            $weights[] = (float) $item->weight;
        }
        $valid = count($values);
        if ($valid < (int) $construct->minvaliditems || $valid === 0) {
            return [null, $valid];
        }
        $mean = array_sum($values) / $valid;
        $value = match ($construct->aggregation) {
            'sum' => $mean * count($items),
            'weightedmean' => array_sum(array_map(static fn($x, $w) => $x * $w, $values, $weights)) / array_sum($weights),
            default => $mean,
        };
        return [round($value, 8), $valid];
    }
}
