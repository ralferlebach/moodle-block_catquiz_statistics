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

use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\semantic\milestone_spec;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\analytics\value_type;

/**
 * Persistence of semantic milestones (Issues #1 and #4).
 *
 * One row per source identity (sourcekey). Repeated occurrences of the same
 * milestone keep firstoccurred, move lastoccurred forward and increment
 * occurrences — e.g. repeated result-page views of one attempt.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class milestone_repository {
    /** @var string Table name. */
    public const TABLE = 'block_catquiz_statistics_milestone';

    /**
     * Record one occurrence of a semantic milestone.
     *
     * @param int $userid User.
     * @param semantic_action $action Semantic action.
     * @param object_type $objecttype Object type.
     * @param string $sourcecomponent Source component.
     * @param string $sourceevent Technical source event (class name or logical name).
     * @param string $sourcekey Unique source identity of the milestone.
     * @param int $origincontextid Origin context.
     * @param int $occurredat Time of this occurrence.
     * @param int|null $origincourseid Origin course.
     * @param int|null $sourceitemid Source object id.
     * @param array $attributes Only semantically required attributes.
     * @param bool $issynthetic Synthetic demo data flag.
     * @return \stdClass The stored milestone row.
     */
    public function record(
        int $userid,
        semantic_action $action,
        object_type $objecttype,
        string $sourcecomponent,
        string $sourceevent,
        string $sourcekey,
        int $origincontextid,
        int $occurredat,
        ?int $origincourseid = null,
        ?int $sourceitemid = null,
        array $attributes = [],
        bool $issynthetic = false
    ): \stdClass {
        return $this->merge(
            $userid,
            $action,
            $objecttype,
            $sourcecomponent,
            $sourceevent,
            $sourcekey,
            $origincontextid,
            $occurredat,
            $occurredat,
            1,
            $origincourseid,
            $sourceitemid,
            $attributes,
            $issynthetic
        );
    }

    /**
     * Record one occurrence described by an adapter result.
     *
     * @param milestone_spec $spec Mapped milestone.
     * @return \stdClass The stored milestone row.
     */
    public function record_spec(milestone_spec $spec): \stdClass {
        return $this->record(
            userid: $spec->userid,
            action: $spec->action,
            objecttype: $spec->objecttype,
            sourcecomponent: $spec->sourcecomponent,
            sourceevent: $spec->sourceevent,
            sourcekey: $spec->sourcekey,
            origincontextid: $spec->origincontextid,
            occurredat: $spec->occurredat,
            origincourseid: $spec->origincourseid,
            sourceitemid: $spec->sourceitemid,
            attributes: $spec->attributes,
        );
    }

    /**
     * Merge an aggregate of occurrences (first, last, count) into a milestone.
     *
     * Used by live observers (count = 1) and by incremental log materialisation.
     * Callers must guarantee each technical occurrence is merged only once.
     *
     * @param int $userid User.
     * @param semantic_action $action Semantic action.
     * @param object_type $objecttype Object type.
     * @param string $sourcecomponent Source component.
     * @param string $sourceevent Technical source event.
     * @param string $sourcekey Unique source identity.
     * @param int $origincontextid Origin context.
     * @param int $first Earliest occurrence in this aggregate.
     * @param int $last Latest occurrence in this aggregate.
     * @param int $count Number of occurrences in this aggregate.
     * @param int|null $origincourseid Origin course.
     * @param int|null $sourceitemid Source object id.
     * @param array $attributes Attributes (only stored on creation).
     * @param bool $issynthetic Synthetic demo data flag.
     * @return \stdClass The stored milestone row.
     */
    public function merge(
        int $userid,
        semantic_action $action,
        object_type $objecttype,
        string $sourcecomponent,
        string $sourceevent,
        string $sourcekey,
        int $origincontextid,
        int $first,
        int $last,
        int $count,
        ?int $origincourseid = null,
        ?int $sourceitemid = null,
        array $attributes = [],
        bool $issynthetic = false
    ): \stdClass {
        global $DB;

        $now = time();
        $existing = $DB->get_record(self::TABLE, ['sourcekey' => $sourcekey]);
        if (!$existing) {
            $record = (object) [
                'userid' => $userid,
                'action' => $action->value,
                'objecttype' => $objecttype->value,
                'sourcecomponent' => $sourcecomponent,
                'sourceevent' => $sourceevent,
                'sourceitemid' => $sourceitemid,
                'sourcekey' => $sourcekey,
                'origincontextid' => $origincontextid,
                'origincourseid' => $origincourseid,
                'firstoccurred' => $first,
                'lastoccurred' => $last,
                'occurrences' => $count,
                'attributes' => empty($attributes) ? null : json_encode($attributes),
                'issynthetic' => (int) $issynthetic,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            try {
                $record->id = $DB->insert_record(self::TABLE, $record);
                return $record;
            } catch (\dml_write_exception $e) {
                // Paralleles Insert derselben Quellidentität: als Wiederholung behandeln.
                $existing = $DB->get_record(self::TABLE, ['sourcekey' => $sourcekey], '*', MUST_EXIST);
            }
        }

        $existing->firstoccurred = min((int) $existing->firstoccurred, $first);
        $existing->lastoccurred = max((int) $existing->lastoccurred, $last);
        $existing->occurrences = (int) $existing->occurrences + $count;
        $existing->timemodified = $now;
        $DB->update_record(self::TABLE, $existing);
        return $existing;
    }

    /**
     * Find milestones matching a query as event observations.
     *
     * The observation time is the first occurrence; lastoccurred and the
     * occurrence count are exposed as attributes.
     *
     * @param observation_query $query Query scope.
     * @return observation[]
     */
    public function find(observation_query $query): array {
        global $DB;

        if ($query->excludes_prefix('event:')) {
            return [];
        }

        $where = ['1=1'];
        $params = [];
        if ($query->userids !== null) {
            if (empty($query->userids)) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($query->userids, SQL_PARAMS_NAMED, 'mu');
            $where[] = 'userid ' . $insql;
            $params += $inparams;
        }
        if ($query->courseids !== null) {
            if (empty($query->courseids)) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($query->courseids, SQL_PARAMS_NAMED, 'mc');
            $where[] = 'origincourseid ' . $insql;
            $params += $inparams;
        }
        if ($query->from !== null) {
            $where[] = 'firstoccurred >= :mfrom';
            $params['mfrom'] = $query->from;
        }
        if ($query->to !== null) {
            $where[] = 'firstoccurred <= :mto';
            $params['mto'] = $query->to;
        }
        if ($query->synthetic !== null) {
            $where[] = 'issynthetic = :msyn';
            $params['msyn'] = (int) $query->synthetic;
        }

        $records = $DB->get_records_select(self::TABLE, implode(' AND ', $where), $params, 'userid, firstoccurred, id');
        $result = [];
        foreach ($records as $r) {
            $action = semantic_action::from($r->action);
            $objecttype = object_type::from($r->objecttype);
            $key = observation::event_key($action, $objecttype);
            if (!$query->matches_variable($key)) {
                continue;
            }
            $attributes = $r->attributes ? (json_decode($r->attributes, true) ?: []) : [];
            $attributes['lastoccurred'] = (int) $r->lastoccurred;
            $attributes['occurrences'] = (int) $r->occurrences;
            $result[] = new observation(
                userid: (int) $r->userid,
                variablekey: $key,
                sourcecomponent: $r->sourcecomponent,
                sourcearea: 'milestone',
                sourcekey: $r->sourcekey,
                origincontextid: (int) $r->origincontextid,
                valuetype: value_type::EVENT,
                origincourseid: $r->origincourseid === null ? null : (int) $r->origincourseid,
                sourceitemid: $r->sourceitemid === null ? null : (int) $r->sourceitemid,
                occurredat: (int) $r->firstoccurred,
                attributes: $attributes,
                provenance: ['sourceevent' => $r->sourceevent],
                issynthetic: (bool) $r->issynthetic,
            );
        }
        return $result;
    }

    /**
     * Fetch a milestone row by its source identity.
     *
     * @param string $sourcekey Source key.
     * @return \stdClass|null
     */
    public function get_by_sourcekey(string $sourcekey): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['sourcekey' => $sourcekey]) ?: null;
    }
}
