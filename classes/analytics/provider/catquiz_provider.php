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

namespace block_catquiz_statistics\analytics\provider;

use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Provider for CATquiz results — read from the canonical CATquiz structures.
 *
 * Nothing is copied: attempts stay canonical in local_catquiz. The provider
 * derives, per completed attempt:
 *   event:started:assessment    (starttime)
 *   event:completed:assessment  (endtime > 0)
 *   catquiz:ability:<scaleid>   (person ability, attribute 'se' = validated SE or null)
 *
 * An invalid or missing SE is exposed as null, never as 0.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catquiz_provider implements observation_provider_interface {
    /** @var string Source component of all observations of this provider. */
    public const COMPONENT = 'local_catquiz';

    /**
     * Constructor.
     *
     * @param attempt_repository $repository Repository (the only CATquiz DB layer).
     */
    public function __construct(
        /** @var attempt_repository Repository. */
        private readonly attempt_repository $repository = new attempt_repository(),
    ) {
    }

    /**
     * Provider key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'catquiz';
    }

    /**
     * Observations matching the query.
     *
     * @param observation_query $query Query scope.
     * @return observation[]
     */
    public function get_observations(observation_query $query): array {
        if ($query->excludes_prefix('catquiz:') && $query->excludes_prefix('event:')) {
            return [];
        }
        if ($query->synthetic === true) {
            // CATquiz-Versuche sind nie synthetisch markiert.
            return [];
        }

        $attempts = [];
        $filters = [];
        if ($query->courseids === null) {
            $filters[] = new attempt_filter(courseid: 0, systemwide: true, userids: $query->userids);
        } else {
            foreach ($query->courseids as $courseid) {
                $filters[] = new attempt_filter(courseid: $courseid, userids: $query->userids);
            }
        }
        foreach ($filters as $filter) {
            foreach ($this->repository->get_attempts($filter) as $attempt) {
                $attempts[] = $attempt;
            }
        }
        if (empty($attempts)) {
            return [];
        }

        $contextids = $this->repository->get_module_contextids(
            array_map(static fn(attempt_data $a) => (int) $a->instanceid, $attempts)
        );

        $result = [];
        foreach ($attempts as $attempt) {
            $contextid = $contextids[(int) $attempt->instanceid] ?? $this->course_contextid($attempt->courseid);
            foreach ($this->map_attempt($attempt, $contextid) as $obs) {
                $time = $obs->occurredat;
                if ($query->matches_variable($obs->variablekey) && $query->matches_time($time)) {
                    $result[] = $obs;
                }
            }
        }
        return $result;
    }

    /**
     * Map one attempt to its observations.
     *
     * @param attempt_data $a Attempt.
     * @param int $contextid Origin context id.
     * @return observation[]
     */
    private function map_attempt(attempt_data $a, int $contextid): array {
        $common = [
            'userid' => $a->userid,
            'sourcecomponent' => self::COMPONENT,
            'origincontextid' => $contextid,
            'origincourseid' => $a->courseid,
            'sourceitemid' => $a->attemptid,
            'provenance' => [
                'catquizattemptid' => $a->id,
                'adaptivequizid' => $a->instanceid,
                'testid' => $a->testid,
            ],
        ];
        $completed = !empty($a->endtime);

        $out = [];
        if (!empty($a->starttime)) {
            $out[] = new observation(
                ...$common,
                variablekey: observation::event_key(semantic_action::STARTED, object_type::ASSESSMENT),
                sourcearea: 'attempt',
                sourcekey: 'catquiz:attempt:' . $a->id . ':started',
                valuetype: value_type::EVENT,
                occurredat: $a->starttime,
            );
        }
        if (!$completed) {
            return $out;
        }
        $out[] = new observation(
            ...$common,
            variablekey: observation::event_key(semantic_action::COMPLETED, object_type::ASSESSMENT),
            sourcearea: 'attempt',
            sourcekey: 'catquiz:attempt:' . $a->id . ':completed',
            valuetype: value_type::EVENT,
            occurredat: $a->endtime,
        );

        $primaryid = isset($a->primaryscale->id) ? (int) $a->primaryscale->id : null;
        foreach ($a->personabilities as $scaleid => $ability) {
            $se = $a->se[$scaleid] ?? null;
            if ($se !== null && $se < 0) {
                // Konvention von local_catquiz: -1 = kein gültiger SE.
                $se = null;
            }
            $out[] = new observation(
                ...$common,
                variablekey: 'catquiz:ability:' . (int) $scaleid,
                sourcearea: 'ability',
                sourcekey: 'catquiz:attempt:' . $a->id . ':ability:' . (int) $scaleid,
                valuetype: value_type::NUMERIC,
                occurredat: $a->endtime,
                valuenumeric: (float) $ability,
                label: isset($a->catscales[$scaleid]->name) ? (string) $a->catscales[$scaleid]->name : null,
                attributes: [
                    'scaleid' => (int) $scaleid,
                    'se' => $se,
                    'isprimary' => $primaryid === (int) $scaleid,
                ],
            );
        }
        return $out;
    }

    /**
     * Course context id, falling back to the system context for deleted courses.
     *
     * @param int|null $courseid Course id.
     * @return int
     */
    private function course_contextid(?int $courseid): int {
        if ($courseid) {
            $ctx = \context_course::instance($courseid, IGNORE_MISSING);
            if ($ctx) {
                return (int) $ctx->id;
            }
        }
        return (int) \context_system::instance()->id;
    }
}
