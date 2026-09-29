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

namespace block_catquiz_statistics\task;

use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\repository\eventmap_repository;
use block_catquiz_statistics\repository\milestone_repository;

/**
 * Materialise milestones for advanced-mode event mappings from the standard log store.
 *
 * Incremental and idempotent: every mapping keeps its own watermark (lastlogid),
 * so each log row is merged exactly once. Queries are bounded by event name,
 * id range and batch size — no unbounded full scans.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class materialise_custom_milestones extends \core\task\scheduled_task {
    /** @var int Log rows per batch. */
    public const BATCH = 5000;

    /** @var int Maximum batches per mapping and run. */
    public const MAXBATCHES = 20;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:materialisecustommilestones', 'block_catquiz_statistics');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        if (!get_config('block_catquiz_statistics', 'enableadvancedmapping')) {
            return;
        }
        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            return;
        }
        $maps = new eventmap_repository();
        foreach ($maps->get_enabled() as $map) {
            $this->process_mapping($map, $maps);
        }
    }

    /**
     * Process one mapping in batches.
     *
     * @param \stdClass $map Mapping row.
     * @param eventmap_repository $maps Repository.
     * @return void
     */
    public function process_mapping(\stdClass $map, eventmap_repository $maps): void {
        global $DB;

        $milestones = new milestone_repository();
        $action = semantic_action::from($map->action);
        $objecttype = object_type::from($map->objecttype);
        $watermark = (int) $map->lastlogid;

        for ($batch = 0; $batch < self::MAXBATCHES; $batch++) {
            $params = ['eventname' => $map->eventname, 'lastid' => $watermark];
            $where = 'eventname = :eventname AND id > :lastid';
            if ((int) $map->courseid > 0) {
                $where .= ' AND courseid = :courseid';
                $params['courseid'] = (int) $map->courseid;
            }
            $rows = $DB->get_records_select(
                'logstore_standard_log',
                $where,
                $params,
                'id ASC',
                'id, component, objectid, contextid, courseid, userid, relateduserid, timecreated',
                0,
                self::BATCH
            );
            if (empty($rows)) {
                break;
            }

            $aggregates = [];
            foreach ($rows as $row) {
                $watermark = max($watermark, (int) $row->id);
                $userid = (int) $row->relateduserid ?: (int) $row->userid;
                if ($userid <= 0 || isguestuser($userid)) {
                    continue;
                }
                $key = 'custom:' . $map->id . ':' . $row->contextid . ':' . (int) $row->objectid . ':' . $userid;
                $time = (int) $row->timecreated;
                if (!isset($aggregates[$key])) {
                    $aggregates[$key] = ['row' => $row, 'userid' => $userid, 'first' => $time, 'last' => $time, 'count' => 0];
                }
                $aggregates[$key]['first'] = min($aggregates[$key]['first'], $time);
                $aggregates[$key]['last'] = max($aggregates[$key]['last'], $time);
                $aggregates[$key]['count']++;
            }

            $transaction = $DB->start_delegated_transaction();
            foreach ($aggregates as $key => $agg) {
                $milestones->merge(
                    userid: $agg['userid'],
                    action: $action,
                    objecttype: $objecttype,
                    sourcecomponent: (string) $agg['row']->component,
                    sourceevent: $map->eventname,
                    sourcekey: $key,
                    origincontextid: (int) $agg['row']->contextid,
                    first: $agg['first'],
                    last: $agg['last'],
                    count: $agg['count'],
                    origincourseid: empty($agg['row']->courseid) ? null : (int) $agg['row']->courseid,
                    sourceitemid: empty($agg['row']->objectid) ? null : (int) $agg['row']->objectid,
                    attributes: ['mappingid' => (int) $map->id],
                );
            }
            $maps->set_watermark((int) $map->id, $watermark);
            $transaction->allow_commit();

            if (count($rows) < self::BATCH) {
                break;
            }
        }
    }
}
