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
 * Immutable result of mapping one technical event to a semantic milestone.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class milestone_spec {
    /**
     * Constructor.
     *
     * @param int $userid Person the milestone belongs to.
     * @param semantic_action $action Semantic action.
     * @param object_type $objecttype Object type.
     * @param string $sourcecomponent Source component.
     * @param string $sourceevent Technical event class name.
     * @param string $sourcekey Unique source identity (repeated occurrences share it).
     * @param int $origincontextid Origin context.
     * @param int $occurredat Time of this occurrence.
     * @param int|null $origincourseid Origin course.
     * @param int|null $sourceitemid Source object id.
     * @param array $attributes Only semantically required attributes — never raw payloads.
     */
    public function __construct(
        /** @var int Person. */
        public readonly int $userid,
        /** @var semantic_action Action. */
        public readonly semantic_action $action,
        /** @var object_type Object type. */
        public readonly object_type $objecttype,
        /** @var string Source component. */
        public readonly string $sourcecomponent,
        /** @var string Technical event class. */
        public readonly string $sourceevent,
        /** @var string Source identity. */
        public readonly string $sourcekey,
        /** @var int Origin context. */
        public readonly int $origincontextid,
        /** @var int Time of occurrence. */
        public readonly int $occurredat,
        /** @var int|null Origin course. */
        public readonly ?int $origincourseid = null,
        /** @var int|null Source object id. */
        public readonly ?int $sourceitemid = null,
        /** @var array Attributes. */
        public readonly array $attributes = [],
    ) {
    }
}
