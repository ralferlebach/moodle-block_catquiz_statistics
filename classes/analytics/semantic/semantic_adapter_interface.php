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

namespace block_catquiz_statistics\analytics\semantic;

use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\semantic_action;

/**
 * Translates one technical Moodle event into a semantic milestone (Issue #4).
 *
 * technical event -> adapter -> semantic milestone -> analytic use (evaluation model)
 *
 * An adapter never interprets beyond its documented semantics: VIEWED means
 * "opened", not "read" or "understood".
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface semantic_adapter_interface {
    /**
     * Stable adapter key (used for enabling/disabling).
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Fully qualified technical event class name, with leading backslash.
     *
     * @return string
     */
    public function get_eventname(): string;

    /**
     * Semantic action produced by this adapter.
     *
     * @return semantic_action
     */
    public function get_action(): semantic_action;

    /**
     * Object type produced by this adapter.
     *
     * @return object_type
     */
    public function get_objecttype(): object_type;

    /**
     * Whether the milestone is persisted by this plugin.
     *
     * False when a canonical source already exists (source-of-truth principle);
     * the event is then only used as a signal (e.g. cache invalidation).
     *
     * @return bool
     */
    public function is_persisted(): bool;

    /**
     * Map an event to a milestone, or null if the event is not applicable.
     *
     * @param \core\event\base $event Event.
     * @return milestone_spec|null
     */
    public function map(\core\event\base $event): ?milestone_spec;
}
