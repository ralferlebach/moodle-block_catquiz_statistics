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

namespace block_catquiz_statistics\repository;

use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\observation_status;
use block_catquiz_statistics\analytics\value_type;

/**
 * Persistence of observations that have no other canonical source.
 *
 * Only data without a canonical home (imports, surveys, external outcomes)
 * is stored here. CAT results and gradebook values stay in their own tables
 * and are read through providers (source-of-truth principle).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observation_repository {
    /** @var string Table name. */
    public const TABLE = 'block_catquiz_statistics_observation';

    /**
     * Insert or update an observation identified by its source key (idempotent).
     *
     * @param observation $obs Observation to persist.
     * @return int Record id.
     */
    public function upsert(observation $obs): int {
        global $DB;

        $now = time();
        $record = (object) [
            'userid' => $obs->userid,
            'datasetid' => $obs->datasetid,
            'variableid' => $obs->variableid,
            'sourcecomponent' => $obs->sourcecomponent,
            'sourcearea' => $obs->sourcearea,
            'sourceitemid' => $obs->sourceitemid,
            'sourcekey' => $obs->sourcekey,
            'origincontextid' => $obs->origincontextid,
            'origincourseid' => $obs->origincourseid,
            'occurredat' => $obs->occurredat,
            'periodstart' => $obs->periodstart,
            'periodend' => $obs->periodend,
            'timepoint' => $obs->timepoint,
            'valuetype' => $obs->valuetype->value,
            'valuenumeric' => $obs->valuenumeric,
            'valuetext' => $obs->valuetext,
            'valuebool' => $obs->valuebool === null ? null : (int) $obs->valuebool,
            'status' => $obs->status->value,
            'provenance' => empty($obs->provenance) ? null : json_encode($obs->provenance),
            'sourceversion' => $obs->provenance['sourceversion'] ?? null,
            'issynthetic' => (int) $obs->issynthetic,
            'timemodified' => $now,
        ];

        $existing = $DB->get_field(self::TABLE, 'id', ['sourcekey' => $obs->sourcekey]);
        if ($existing) {
            $record->id = (int) $existing;
            $DB->update_record(self::TABLE, $record);
            return (int) $existing;
        }
        $record->timecreated = $now;
        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Find observations matching a query.
     *
     * @param observation_query $query Query scope.
     * @return observation[]
     */
    public function find(observation_query $query): array {
        global $DB;

        if ($query->excludes_prefix('var:')) {
            return [];
        }

        [$where, $params] = $this->build_where($query);
        $sql = "SELECT o.*, v.shortname AS variableshortname, v.label AS variablelabel
                  FROM {" . self::TABLE . "} o
             LEFT JOIN {block_catquiz_statistics_variable} v ON v.id = o.variableid
                 WHERE $where
              ORDER BY o.userid, o.occurredat, o.id";

        $result = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $obs = $this->to_observation($record);
            if ($query->matches_variable($obs->variablekey)) {
                $result[] = $obs;
            }
        }
        return $result;
    }

    /**
     * Delete all observations of a dataset.
     *
     * @param int $datasetid Dataset id.
     */
    public function delete_for_dataset(int $datasetid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['datasetid' => $datasetid]);
    }

    /**
     * Build the SQL WHERE clause for a query.
     *
     * @param observation_query $query Query scope.
     * @return array [string $where, array $params]
     */
    private function build_where(observation_query $query): array {
        global $DB;

        $where = ['1=1'];
        $params = [];
        if ($query->userids !== null) {
            if (empty($query->userids)) {
                return ['1=0', []];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($query->userids, SQL_PARAMS_NAMED, 'ou');
            $where[] = 'o.userid ' . $insql;
            $params += $inparams;
        }
        if ($query->courseids !== null) {
            if (empty($query->courseids)) {
                return ['1=0', []];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($query->courseids, SQL_PARAMS_NAMED, 'oc');
            $where[] = 'o.origincourseid ' . $insql;
            $params += $inparams;
        }
        if ($query->from !== null) {
            $where[] = '(o.occurredat IS NULL OR o.occurredat >= :ofrom)';
            $params['ofrom'] = $query->from;
        }
        if ($query->to !== null) {
            $where[] = '(o.occurredat IS NULL OR o.occurredat <= :oto)';
            $params['oto'] = $query->to;
        }
        if ($query->synthetic !== null) {
            $where[] = 'o.issynthetic = :osyn';
            $params['osyn'] = (int) $query->synthetic;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Hydrate a DB record into an observation.
     *
     * @param \stdClass $r Record incl. variable join columns.
     * @return observation
     */
    private function to_observation(\stdClass $r): observation {
        return new observation(
            userid: (int) $r->userid,
            variablekey: $r->variableid ? 'var:' . (int) $r->variableid : 'var:unregistered',
            sourcecomponent: $r->sourcecomponent,
            sourcearea: $r->sourcearea,
            sourcekey: $r->sourcekey,
            origincontextid: (int) $r->origincontextid,
            valuetype: value_type::from($r->valuetype),
            status: observation_status::tryFrom($r->status) ?? observation_status::MISSING_UNKNOWN,
            origincourseid: $r->origincourseid === null ? null : (int) $r->origincourseid,
            sourceitemid: $r->sourceitemid === null ? null : (int) $r->sourceitemid,
            occurredat: $r->occurredat === null ? null : (int) $r->occurredat,
            periodstart: $r->periodstart === null ? null : (int) $r->periodstart,
            periodend: $r->periodend === null ? null : (int) $r->periodend,
            timepoint: $r->timepoint,
            valuenumeric: $r->valuenumeric === null ? null : (float) $r->valuenumeric,
            valuetext: $r->valuetext,
            valuebool: $r->valuebool === null ? null : (bool) $r->valuebool,
            label: $r->variablelabel ?? null,
            datasetid: $r->datasetid === null ? null : (int) $r->datasetid,
            variableid: $r->variableid === null ? null : (int) $r->variableid,
            attributes: ['shortname' => $r->variableshortname ?? null],
            provenance: $r->provenance ? (json_decode($r->provenance, true) ?: []) : [],
            issynthetic: (bool) $r->issynthetic,
        );
    }
}
