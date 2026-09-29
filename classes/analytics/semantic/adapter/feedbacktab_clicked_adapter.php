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
 * \local_catquiz\event\feedbacktab_clicked -> INTERACTED + feedback.
 *
 * Only a learner clicking a feedback tab of their OWN attempt (role = student)
 * counts; teacher/manager views are not learner interaction. The click is a
 * usage indicator, not evidence of reading or understanding. One milestone
 * per attempt and feedback tab.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedbacktab_clicked_adapter implements semantic_adapter_interface {
    /** @var string Event class. */
    public const EVENT = '\\local_catquiz\\event\\feedbacktab_clicked';

    /**
     * Adapter key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'catquiz_feedbacktab_clicked';
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
        return semantic_action::INTERACTED;
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
     * Persisted.
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
        $other = (array) ($data['other'] ?? []);
        if (($other['role'] ?? '') !== 'student') {
            return null;
        }
        $attemptid = (int) ($other['attemptid'] ?? 0);
        $userid = (int) ($other['userid'] ?? 0) ?: (int) ($data['userid'] ?? 0);
        $feedback = clean_param((string) ($other['feedback'] ?? ''), PARAM_ALPHANUMEXT);
        if ($attemptid <= 0 || $userid <= 0 || $feedback === '') {
            return null;
        }
        return new milestone_spec(
            userid: $userid,
            action: $this->get_action(),
            objecttype: $this->get_objecttype(),
            sourcecomponent: 'local_catquiz',
            sourceevent: self::EVENT,
            sourcekey: 'local_catquiz:feedbacktab_clicked:' . $attemptid . ':' . $feedback,
            origincontextid: (int) $data['contextid'],
            occurredat: (int) $data['timecreated'],
            origincourseid: empty($data['courseid']) ? null : (int) $data['courseid'],
            sourceitemid: $attemptid,
            attributes: ['feedback' => $feedback],
        );
    }
}
