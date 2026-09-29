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
use block_catquiz_statistics\analytics\semantic\adapter_registry;
use block_catquiz_statistics\analytics\semantic_action;

/**
 * Advanced-mode event mappings (Issue #4).
 *
 * Lets site administrators map an additional Moodle event to one of the fixed
 * semantic actions and object types. Events already claimed by a registered
 * adapter cannot be mapped again (no double interpretation).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eventmap_repository {
    /** @var string Table. */
    public const TABLE = 'block_catquiz_statistics_eventmap';

    /**
     * Create a mapping.
     *
     * @param string $eventname Event class name.
     * @param semantic_action $action Semantic action.
     * @param object_type $objecttype Object type.
     * @param int $courseid Restrict to a course (0 = all courses).
     * @param adapter_registry|null $registry Registry used for the double-interpretation check.
     * @return int Mapping id.
     * @throws \coding_exception For unknown events or events claimed by an adapter.
     */
    public function create(
        string $eventname,
        semantic_action $action,
        object_type $objecttype,
        int $courseid = 0,
        ?adapter_registry $registry = null
    ): int {
        global $DB, $USER;

        $eventname = '\\' . ltrim($eventname, '\\');
        if (!class_exists($eventname) || !is_subclass_of($eventname, \core\event\base::class)) {
            throw new \coding_exception('Not a Moodle event class: ' . $eventname);
        }
        $registry = $registry ?? adapter_registry::create_default();
        if ($registry->claims_event($eventname)) {
            throw new \coding_exception('Event is already interpreted by a standard adapter: ' . $eventname);
        }
        $now = time();
        return (int) $DB->insert_record(self::TABLE, (object) [
            'eventname' => $eventname,
            'action' => $action->value,
            'objecttype' => $objecttype->value,
            'courseid' => $courseid,
            'enabled' => 1,
            'lastlogid' => 0,
            'usermodified' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Enable or disable a mapping.
     *
     * @param int $id Mapping id.
     * @param bool $enabled New state.
     */
    public function set_enabled(int $id, bool $enabled): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) ['id' => $id, 'enabled' => (int) $enabled, 'timemodified' => time()]);
    }

    /**
     * Enabled mappings.
     *
     * @return \stdClass[]
     */
    public function get_enabled(): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['enabled' => 1], 'id');
    }

    /**
     * Advance the watermark of a mapping.
     *
     * @param int $id Mapping id.
     * @param int $lastlogid Highest processed log id.
     */
    public function set_watermark(int $id, int $lastlogid): void {
        global $DB;
        $DB->set_field(self::TABLE, 'lastlogid', $lastlogid, ['id' => $id]);
    }
}
