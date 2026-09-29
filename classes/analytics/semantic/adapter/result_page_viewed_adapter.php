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

/**
 * \mod_adaptivequiz\event\result_page_viewed -> VIEWED + feedback.
 *
 * Specified in ralferlebach/moodle-mod_adaptivequiz#15: objectid = adaptivequiz_attempt.id,
 * module context, userid = owner of the attempt. One milestone per attempt;
 * repeated views advance lastoccurred and the occurrence count (Issue #1).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_page_viewed_adapter implements semantic_adapter_interface {
    /** @var string Event class. */
    public const EVENT = '\\mod_adaptivequiz\\event\\result_page_viewed';

    /**
     * Adapter key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'adaptivequiz_result_page_viewed';
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
        return semantic_action::VIEWED;
    }

    /**
     * Object type.
     *
     * @return object_type
     */
    public function get_objecttype(): object_type {
        return object_type::FEEDBACK;
    }

    /**
     * Persisted: the view has no other canonical, efficiently queryable source.
     *
     * @return bool
     */
    public function is_persisted(): bool {
        return true;
    }

    /**
     * Map the event.
     *
     * @param \core\event\base $event Event.
     * @return milestone_spec|null
     */
    public function map(\core\event\base $event): ?milestone_spec {
        $data = $event->get_data();
        $attemptid = (int) ($data['objectid'] ?? 0);
        $userid = (int) ($data['relateduserid'] ?? 0) ?: (int) ($data['userid'] ?? 0);
        if ($attemptid <= 0 || $userid <= 0) {
            return null;
        }
        $other = (array) ($data['other'] ?? []);
        $attributes = array_filter([
            'instanceid' => isset($other['instanceid']) ? (int) $other['instanceid'] : null,
            'resultvalid' => isset($other['resultvalid']) ? (bool) $other['resultvalid'] : null,
        ], static fn($v) => $v !== null);

        return new milestone_spec(
            userid: $userid,
            action: $this->get_action(),
            objecttype: $this->get_objecttype(),
            sourcecomponent: 'mod_adaptivequiz',
            sourceevent: self::EVENT,
            sourcekey: 'mod_adaptivequiz:result_page_viewed:' . $attemptid,
            origincontextid: (int) $data['contextid'],
            occurredat: (int) $data['timecreated'],
            origincourseid: empty($data['courseid']) ? null : (int) $data['courseid'],
            sourceitemid: $attemptid,
            attributes: $attributes,
        );
    }
}
