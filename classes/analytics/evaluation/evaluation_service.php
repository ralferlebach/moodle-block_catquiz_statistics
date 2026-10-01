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

namespace block_catquiz_statistics\analytics\evaluation;

use block_catquiz_statistics\analytics\analytic_role;
use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\occasion;
use block_catquiz_statistics\repository\evalmodel_repository;

/**
 * Evaluates an evaluation model for a course (Issue #7).
 *
 * The model defines view, population, role mapping and transitions; this
 * service computes the cohort overview, the transition funnel (each rate with
 * its explicit denominator), person timelines and performance change. No
 * global engagement score is ever formed. Every result references the model
 * version it was computed with.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluation_service {
    /** @var \stdClass Model record. */
    private \stdClass $model;

    /** @var array Model config. */
    private array $config;

    /** @var \stdClass[] Role mappings. */
    private array $roles;

    /**
     * Constructor.
     *
     * @param int $modelid Model id.
     * @param analytics_query_service $service Observation service.
     * @param evalmodel_repository $models Model repository.
     */
    public function __construct(
        int $modelid,
        /** @var analytics_query_service Service. */
        private readonly analytics_query_service $service,
        evalmodel_repository $models = new evalmodel_repository(),
    ) {
        $this->model = $models->get_model($modelid) ?? throw new \coding_exception('Unknown model ' . $modelid);
        $this->config = $models->get_config($modelid);
        $this->roles = $models->get_roles($modelid);
    }

    /**
     * Model id and version every result refers to.
     *
     * @return array{modelid:int, version:int, name:string, issynthetic:bool}
     */
    public function get_reference(): array {
        return ['modelid' => (int) $this->model->id, 'version' => (int) $this->model->version,
            'name' => $this->model->name, 'issynthetic' => (bool) $this->model->issynthetic];
    }

    /**
     * Population with transparent denominator steps.
     *
     * @param int $courseid Course.
     * @return array{userids:int[], steps:array}
     */
    public function get_population(int $courseid): array {
        return (new population($this->service))->resolve($courseid, $this->config['population'] ?? []);
    }

    /**
     * Cohort overview: N population and N with data per analytic role (no score).
     *
     * @param int[] $userids Population.
     * @return array role => ['n' => int, 'selectors' => int]
     */
    public function cohort_overview(array $userids): array {
        $result = [];
        foreach (array_merge(analytic_role::chain(), [analytic_role::COVARIATE]) as $role) {
            $mapped = array_filter($this->roles, static fn($r) => $r->roleenum === $role);
            $withdata = [];
            foreach ($mapped as $r) {
                foreach ($this->select($userids, $r->selector, (string) $r->occasion) as $userid => $list) {
                    if (coverage_state::classify($list)->has_data()) {
                        $withdata[$userid] = true;
                    }
                }
            }
            $result[$role->value] = ['n' => count($withdata), 'selectors' => count($mapped)];
        }
        return ['population' => count($userids), 'roles' => $result, 'model' => $this->get_reference()];
    }

    /**
     * Transition funnel along the configured steps.
     *
     * Step 1 uses the population as denominator; every further step uses the
     * persons who reached the previous step. Persons without data are reported
     * per coverage state and never counted as negative.
     *
     * @param int[] $userids Population.
     * @return array{model:array, population:int, steps:array}
     */
    public function funnel(array $userids): array {
        $eligible = $userids;
        $steps = [];
        foreach ($this->config['transitions'] ?? [] as $i => $step) {
            $selected = $this->select($eligible, $step['key'], (string) ($step['occasion'] ?? ''));
            $counts = array_fill_keys(array_map(static fn($s) => $s->value, coverage_state::cases()), 0);
            $reached = [];
            foreach ($eligible as $userid) {
                $state = coverage_state::classify($selected[$userid] ?? []);
                $counts[$state->value]++;
                if ($state === coverage_state::REACHED) {
                    $reached[] = $userid;
                }
            }
            $steps[] = [
                'index' => $i + 1,
                'label' => $step['label'] ?? $step['key'],
                'key' => $step['key'],
                'occasion' => occasion::from_string((string) ($step['occasion'] ?? ''))->to_string(),
                'definition' => $step['definition'] ?? null,
                'window' => $step['window'] ?? null,
                'denominator' => $i === 0 ? 'population' : 'previousstep',
                'eligible' => count($eligible),
                'counts' => $counts,
                'rate' => count($eligible) ? count($reached) / count($eligible) : null,
                'ratepopulation' => count($userids) ? count($reached) / count($userids) : null,
            ];
            $eligible = $reached;
        }
        return ['model' => $this->get_reference(), 'population' => count($userids), 'steps' => $steps];
    }

    /**
     * Chronological, course-spanning timeline of one person — restricted to courses the viewer may see.
     *
     * A teacher of the current course does not automatically see personal data
     * from other courses: observations of a course are only included when the
     * viewer holds block/catquiz_statistics:viewdetails there.
     *
     * @param int $userid Person.
     * @param int|null $viewerid Viewer (null = current user).
     * @return observation[]
     */
    public function timeline(int $userid, ?int $viewerid = null): array {
        $allowed = [];
        $result = [];
        foreach ($this->service->get_timeline($userid) as $obs) {
            $courseid = (int) ($obs->origincourseid ?? 0);
            if (!array_key_exists($courseid, $allowed)) {
                $allowed[$courseid] = $courseid > 0
                    ? has_capability('block/catquiz_statistics:viewdetails', \context_course::instance($courseid), $viewerid)
                    : has_capability('block/catquiz_statistics:viewall', \context_system::instance(), $viewerid);
            }
            if ($allowed[$courseid]) {
                $result[] = $obs;
            }
        }
        return $result;
    }

    /**
     * Performance pairs: performance selectors with the same key at two occasions (e.g. theta T0 / T1).
     *
     * @return array list of ['key', 'from', 'to', 'label']
     */
    public function performance_pairs(): array {
        $bykey = [];
        foreach ($this->roles as $r) {
            if ($r->roleenum === analytic_role::PERFORMANCE && $r->occasion !== 'any') {
                $bykey[$r->selector][] = $r;
            }
        }
        $pairs = [];
        foreach ($bykey as $key => $list) {
            if (count($list) >= 2) {
                $pairs[] = ['key' => $key, 'from' => $list[0]->occasion, 'to' => $list[1]->occasion,
                    'label' => trim(($list[0]->label ?? '') . ' / ' . ($list[1]->label ?? ''), ' /')];
            }
        }
        return $pairs;
    }

    /**
     * Reliable change per person for a performance pair, plus a cohort summary.
     *
     * The standard error comes from the observation attribute 'se' (CAT) or from a
     * register variable named <shortname>_se measured at the same time.
     *
     * @param int[] $userids Population.
     * @param array $pair Pair from performance_pairs().
     * @return array{model:array, persons:array, summary:array, assumption:string}
     */
    public function reliable_change(array $userids, array $pair): array {
        $from = $this->select($userids, $pair['key'], $pair['from']);
        $to = $this->select($userids, $pair['key'], $pair['to']);
        $all = $this->service->get_observations_by_user(new observation_query(userids: $userids));
        $persons = [];
        $summary = ['improved' => 0, 'declined' => 0, 'nochange' => 0, 'notcomputable' => 0, 'incomplete' => 0];
        foreach ($userids as $userid) {
            $a = $from[$userid][0] ?? null;
            $b = $to[$userid][0] ?? null;
            if (!$a || !$b || !$a->status->has_value() || !$b->status->has_value()) {
                $summary['incomplete']++;
                continue;
            }
            $rc = reliable_change::compute(
                (float) $a->valuenumeric,
                $this->se_for($a, $all[$userid] ?? []),
                (float) $b->valuenumeric,
                $this->se_for($b, $all[$userid] ?? [])
            );
            $summary[$rc['status'] === 'ok' ? $rc['direction'] : 'notcomputable']++;
            $persons[$userid] = $rc + ['theta1' => $a->valuenumeric, 'theta2' => $b->valuenumeric,
                'time1' => $a->occurredat, 'time2' => $b->occurredat];
        }
        return ['model' => $this->get_reference(), 'persons' => $persons, 'summary' => $summary,
            'assumption' => reliable_change::ASSUMPTION];
    }

    /**
     * Role mappings grouped by role (for the operationalisation view).
     *
     * @return array role => list of mappings
     */
    public function get_roles_by_role(): array {
        $result = [];
        foreach ($this->roles as $r) {
            $result[$r->role][] = $r;
        }
        return $result;
    }

    /**
     * Transitions as configured.
     *
     * @return array
     */
    public function get_transitions(): array {
        return $this->config['transitions'] ?? [];
    }

    /**
     * Observations of a key for the given users, selected by occasion.
     *
     * @param int[] $userids Users.
     * @param string $key Variable key.
     * @param string $occasion Occasion.
     * @return array userid => observation[]
     */
    private function select(array $userids, string $key, string $occasion): array {
        if (empty($userids)) {
            return [];
        }
        $occ = occasion::from_string($occasion);
        $grouped = [];
        foreach ($this->service->get_observations(new observation_query(userids: $userids, variablekeys: [$key])) as $o) {
            $grouped[$o->userid][] = $o;
        }
        $result = [];
        foreach ($grouped as $userid => $list) {
            $result[$userid] = $occ->select($list);
        }
        return $result;
    }

    /**
     * Standard error belonging to a person-parameter observation, or null.
     *
     * @param observation $obs Estimate.
     * @param observation[] $all All observations of the person.
     * @return float|null
     */
    private function se_for(observation $obs, array $all): ?float {
        if (array_key_exists('se', $obs->attributes)) {
            return $obs->attributes['se'] === null ? null : (float) $obs->attributes['se'];
        }
        $shortname = $obs->attributes['shortname'] ?? null;
        if ($shortname === null) {
            return null;
        }
        foreach ($all as $o) {
            if (
                ($o->attributes['shortname'] ?? null) === $shortname . '_se' && $o->timepoint === $obs->timepoint
                    && $o->occurredat === $obs->occurredat && $o->status->has_value()
            ) {
                return (float) $o->valuenumeric;
            }
        }
        return null;
    }
}
