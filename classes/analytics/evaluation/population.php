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

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\occasion;

/**
 * Analysis population (cohort) with a transparent denominator (Issue #7).
 *
 * Population is not a data class. It is a list of criteria applied in order
 * (AND); for every criterion the remaining N is reported, so every rate can
 * name its denominator.
 *
 * Criteria (model config 'population'):
 *   {type: enrolled}                            active participants with a gradebook role in the course
 *   {type: enrolledat, time: <unix>}            enrolment active at a reference date
 *   {type: hasobservation, key, occasion?}      persons with an observed value (e.g. T0 survey)
 *   {type: variablevalue, key, value}           persons whose observed value equals value (e.g. degree = MB)
 *   {type: dataset, datasetid}                  persons contained in an (imported) dataset
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class population {
    /**
     * Constructor.
     *
     * @param analytics_query_service $service Observation service.
     */
    public function __construct(
        /** @var analytics_query_service Service. */
        private readonly analytics_query_service $service,
    ) {
    }

    /**
     * Resolve the population of a course.
     *
     * @param int $courseid Course the evaluation is run in.
     * @param array $criteria Criteria (default: enrolled).
     * @return array{userids:int[], steps:array} steps: list of [criterion, label, n]
     * @throws \coding_exception For unknown criteria.
     */
    public function resolve(int $courseid, array $criteria = []): array {
        global $DB;
        if (empty($criteria)) {
            $criteria = [['type' => 'enrolled']];
        }
        $userids = null;
        $steps = [];
        foreach ($criteria as $c) {
            $type = $c['type'] ?? '';
            $context = \context_course::instance($courseid);
            switch ($type) {
                case 'enrolled':
                    $set = $this->gradable_users($context);
                    break;
                case 'enrolledat':
                    $set = array_map('intval', $DB->get_fieldset_sql(
                        "SELECT DISTINCT ue.userid
                           FROM {user_enrolments} ue
                           JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                           JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                          WHERE ue.status = 0 AND ue.timestart <= :t1 AND (ue.timeend = 0 OR ue.timeend >= :t2)
                                AND ue.timecreated <= :t3",
                        ['courseid' => $courseid, 't1' => (int) $c['time'], 't2' => (int) $c['time'], 't3' => (int) $c['time']]
                    ));
                    break;
                case 'hasobservation':
                case 'variablevalue':
                    $set = $this->observed_users($c, $userids);
                    break;
                case 'dataset':
                    $set = array_map('intval', $DB->get_fieldset_select(
                        'block_catquiz_statistics_observation',
                        'DISTINCT userid',
                        'datasetid = ?',
                        [(int) $c['datasetid']]
                    ));
                    break;
                default:
                    throw new \coding_exception('Unknown population criterion: ' . $type);
            }
            $userids = $userids === null ? $set : array_values(array_intersect($userids, $set));
            sort($userids);
            $steps[] = ['criterion' => $c, 'n' => count($userids)];
        }
        return ['userids' => $userids ?? [], 'steps' => $steps];
    }

    /**
     * Persons with an observed (and optionally matching) value for a key.
     *
     * @param array $c Criterion.
     * @param int[]|null $within Restrict to these users.
     * @return int[]
     */
    private function observed_users(array $c, ?array $within): array {
        if ($within !== null && empty($within)) {
            return [];
        }
        $occasion = occasion::from_string((string) ($c['occasion'] ?? ''));
        $grouped = [];
        foreach ($this->service->get_observations(new observation_query(userids: $within, variablekeys: [$c['key']])) as $o) {
            $grouped[$o->userid][] = $o;
        }
        $result = [];
        foreach ($grouped as $userid => $list) {
            foreach ($occasion->select($list) as $o) {
                if (!$o->status->has_value()) {
                    continue;
                }
                if ($c['type'] === 'hasobservation' || (string) $o->get_value() === (string) $c['value']) {
                    $result[] = (int) $userid;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Active participants with a gradebook role.
     *
     * @param \context_course $context Course context.
     * @return int[]
     */
    private function gradable_users(\context_course $context): array {
        global $CFG;
        if (empty($CFG->gradebookroles)) {
            return [];
        }
        $rows = get_role_users(explode(',', $CFG->gradebookroles), $context, false, 'ra.id, u.id AS userid', 'u.id', false);
        return array_values(array_unique(array_map(static fn($r) => (int) $r->userid, $rows)));
    }
}
