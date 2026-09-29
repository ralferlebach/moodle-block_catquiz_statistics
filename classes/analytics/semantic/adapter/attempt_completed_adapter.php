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

namespace block_catquiz_statistics\analytics\semantic\adapter;

use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\semantic\milestone_spec;
use block_catquiz_statistics\analytics\semantic\semantic_adapter_interface;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * \local_catquiz\event\attempt_completed -> COMPLETED + assessment.
 *
 * NOT persisted: the completed CAT attempt is canonical in local_catquiz and
 * read by the catquiz provider (Issue #1: "attempt_completed nicht redundant
 * persistieren"). The mapping is used as a live signal only.
 *
 * Accepts the current payload (system context, other.attemptid = adaptivequiz
 * attempt id) and the payload planned in local_catquiz#122
 * (other.adaptiveattemptid, module context).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_completed_adapter implements semantic_adapter_interface {
    /** @var string Event class. */
    public const EVENT = '\\local_catquiz\\event\\attempt_completed';

    /**
     * Adapter key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'catquiz_attempt_completed';
    }

    /**
     * Event class.
     *
     * @return string
     */
    public function get_eventname(): string {
        return self::EVENT;
    }

    /**
     * Action.
     *
     * @return semantic_action
     */
    public function get_action(): semantic_action {
        return semantic_action::COMPLETED;
    }

    /**
     * Object type.
     *
     * @return object_type
     */
    public function get_objecttype(): object_type {
        return object_type::ASSESSMENT;
    }

    /**
     * Not persisted — canonical source exists.
     *
     * @return bool
     */
    public function is_persisted(): bool {
        return false;
    }

    /**
     * Map the event.
     *
     * @param \core\event\base $event Event.
     * @return milestone_spec|null
     */
    public function map(\core\event\base $event): ?milestone_spec {
        $data = $event->get_data();
        $other = (array) ($data['other'] ?? []);
        $adaptiveattemptid = (int) ($other['adaptiveattemptid'] ?? $other['attemptid'] ?? 0);
        $userid = (int) ($other['userid'] ?? 0) ?: (int) ($data['relateduserid'] ?? 0) ?: (int) ($data['userid'] ?? 0);
        if ($adaptiveattemptid <= 0 || $userid <= 0) {
            return null;
        }

        $contextid = (int) $data['contextid'];
        $courseid = empty($data['courseid']) ? null : (int) $data['courseid'];
        $instanceid = isset($other['instanceid']) ? (int) $other['instanceid'] : null;
        if ((int) $data['contextlevel'] === CONTEXT_SYSTEM && $instanceid) {
            $modctx = (new attempt_repository())->get_module_contextids([$instanceid])[$instanceid] ?? null;
            if ($modctx) {
                $contextid = $modctx;
                $courseid = (int) \context::instance_by_id($modctx)->get_course_context()->instanceid;
            }
        }

        return new milestone_spec(
            userid: $userid,
            action: $this->get_action(),
            objecttype: $this->get_objecttype(),
            sourcecomponent: 'local_catquiz',
            sourceevent: self::EVENT,
            sourcekey: 'local_catquiz:attempt_completed:' . $adaptiveattemptid,
            origincontextid: $contextid,
            occurredat: (int) $data['timecreated'],
            origincourseid: $courseid,
            sourceitemid: $adaptiveattemptid,
            attributes: array_filter(['instanceid' => $instanceid], static fn($v) => $v !== null),
        );
    }
}
