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

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\key_labeller;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\occasion;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\repository\evalmodel_repository;

/**
 * Builds the long (tidy) research extract and the wide analytic dataset of an evaluation model (Issue #8).
 *
 * Long is the canonical extract (one row per observation, with provenance and
 * analytic role). Wide is a materialised view per model: one column per role
 * mapping (selector x occasion), one row per person — never persisted.
 *
 * Wide value rules (documented in the codebook):
 *   events      number of selected milestones; 0 = no milestone recorded (usage indicator,
 *               not proof of reading/learning)
 *   boolean     1 / 0; empty = not available
 *   numeric     value of the last selected observed measurement; empty = not available
 *   categorical text; empty = not available
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dataset_builder {
    /** @var analytics_query_service Service. */
    private analytics_query_service $service;

    /** @var key_labeller Labeller. */
    private key_labeller $labeller;

    /**
     * Constructor.
     *
     * @param int $modelid Evaluation model.
     * @param analytics_query_service|null $service Observation service.
     */
    public function __construct(
        /** @var int Model id. */
        private readonly int $modelid,
        ?analytics_query_service $service = null,
    ) {
        $this->service = $service ?? analytics_query_service::create_default();
        $this->labeller = new key_labeller();
    }

    /**
     * Column definitions of the wide dataset (codebook).
     *
     * @return array list of [column, label, role, selector, occasion, kind, missing]
     */
    public function columns(): array {
        $columns = [];
        $used = [];
        foreach ((new evalmodel_repository())->get_roles($this->modelid) as $r) {
            $base = $this->column_base($r->selector, $r->label);
            $name = $base . ($r->occasion !== 'any' ? '_' . self::slug($r->occasion) : '');
            $name = $this->unique($name, $used);
            $kind = str_starts_with($r->selector, 'event:') ? 'eventcount' : 'value';
            $columns[] = [
                'column' => $name,
                'label' => $r->label ? format_string($r->label) : $this->labeller->label($r->selector),
                'role' => $r->role,
                'selector' => $r->selector,
                'occasion' => $r->occasion,
                'kind' => $kind,
                'missing' => $kind === 'eventcount' ? 'zero_means_no_record' : 'empty_means_not_available',
            ];
        }
        return $columns;
    }

    /**
     * Wide analytic dataset.
     *
     * @param int[] $userids Population.
     * @param pseudonymiser $subjects Subject identifiers.
     * @return array{columns:array, rows:array, values:array} rows: subject => [column => value]; values: userid => numeric row
     */
    public function wide(array $userids, pseudonymiser $subjects): array {
        $columns = $this->columns();
        $keys = array_values(array_unique(array_column($columns, 'selector')));
        $byuser = [];
        foreach ($this->service->get_observations(new observation_query(userids: $userids, variablekeys: $keys)) as $o) {
            $byuser[$o->userid][$o->variablekey][] = $o;
        }
        $rows = [];
        $values = [];
        foreach ($userids as $userid) {
            $row = [];
            foreach ($columns as $c) {
                $selected = occasion::from_string($c['occasion'])->select($byuser[$userid][$c['selector']] ?? []);
                $row[$c['column']] = $this->wide_value($c, $selected);
            }
            $rows[$subjects->subject($userid)] = $row;
            $values[$userid] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows, 'values' => $values];
    }

    /**
     * Long / tidy extract with provenance and analytic role.
     *
     * @param int[] $userids Population.
     * @param pseudonymiser $subjects Subject identifiers.
     * @return array list of rows
     */
    public function long(array $userids, pseudonymiser $subjects): array {
        $roles = [];
        foreach ((new evalmodel_repository())->get_roles($this->modelid) as $r) {
            $roles[$r->selector][] = $r->role . ($r->occasion !== 'any' ? '@' . $r->occasion : '');
        }
        $rows = [];
        foreach ($this->service->get_observations(new observation_query(userids: $userids)) as $o) {
            $rows[] = [
                'subject' => $subjects->subject($o->userid),
                'course' => $o->origincourseid,
                'context' => $o->origincontextid,
                'time' => $o->get_sorttime() ? gmdate('Y-m-d\TH:i:s\Z', $o->get_sorttime()) : '',
                'timepoint' => $o->timepoint ?? '',
                'dataset' => $o->datasetid ?? '',
                'variable' => $o->variablekey,
                'variablelabel' => $this->labeller->label($o->variablekey),
                'construct' => $o->constructid ?? '',
                'value' => $this->long_value($o),
                'valuetype' => $o->valuetype->value,
                'status' => $o->status->value,
                'source' => $o->sourcecomponent . '/' . $o->sourcearea,
                'analyticrole' => implode(';', $roles[$o->variablekey] ?? []),
                'provenance' => json_encode($o->provenance + ['sourcekeyhash' => substr(sha1($o->sourcekey), 0, 12)]),
                'synthetic' => (int) $o->issynthetic,
            ];
        }
        return $rows;
    }

    /**
     * Value of a wide cell.
     *
     * @param array $column Column definition.
     * @param observation[] $selected Selected observations.
     * @return float|int|string
     */
    private function wide_value(array $column, array $selected): float|int|string {
        if ($column['kind'] === 'eventcount') {
            return count(array_filter($selected, static fn($o) => $o->status->has_value()));
        }
        $observed = array_values(array_filter($selected, static fn($o) => $o->status->has_value()));
        if (!$observed) {
            return '';
        }
        $last = end($observed);
        $v = $last->get_value();
        if ($last->valuetype === value_type::BOOLEAN) {
            return $v ? 1 : 0;
        }
        return is_float($v) ? $v : (string) $v;
    }

    /**
     * Value of a long row (missing statuses stay empty, the status column explains them).
     *
     * @param observation $o Observation.
     * @return string
     */
    private function long_value(observation $o): string {
        $v = $o->get_value();
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return (string) $v;
    }

    /**
     * Base column name from the selector.
     *
     * @param string $selector Selector.
     * @param string|null $label Mapping label.
     * @return string
     */
    private function column_base(string $selector, ?string $label): string {
        global $DB;
        [$prefix, $rest] = array_pad(explode(':', $selector, 2), 2, '');
        $short = match ($prefix) {
            'var' => $DB->get_field('block_catquiz_statistics_variable', 'shortname', ['id' => (int) $rest]),
            'construct' => $DB->get_field('block_catquiz_statistics_construct', 'shortname', ['id' => (int) $rest]),
            'outcome' => $DB->get_field('block_catquiz_statistics_outcome', 'shortname', ['id' => (int) $rest]),
            'event' => str_replace(':', '_', $rest),
            default => str_replace(':', '_', $selector),
        };
        return self::slug($short ?: ($label ?: $selector));
    }

    /**
     * Lower-case identifier safe for R/SPSS/JASP.
     *
     * @param string $text Text.
     * @return string
     */
    public static function slug(string $text): string {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $text), '_'));
        $s = preg_match('/^[a-z]/', $s) ? $s : 'v_' . $s;
        return substr($s, 0, 60);
    }

    /**
     * Make a column name unique.
     *
     * @param string $name Candidate.
     * @param array $used Used names (by reference).
     * @return string
     */
    private function unique(string $name, array &$used): string {
        $candidate = $name;
        $i = 2;
        while (isset($used[$candidate])) {
            $candidate = $name . '_' . $i++;
        }
        $used[$candidate] = true;
        return $candidate;
    }
}
