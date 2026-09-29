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
 * Human readable labels for semantic milestones — without over-interpretation.
 *
 * The wording is deliberately literal: VIEWED is "opened", INTERACTED is
 * "interacted with". Labels never claim reading, understanding or learning.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class semantic_label {
    /**
     * Label for action + object type, e.g. "Feedback: opened".
     *
     * @param semantic_action $action Action.
     * @param object_type $objecttype Object type.
     * @return string
     */
    public static function get(semantic_action $action, object_type $objecttype): string {
        return get_string('semantic:label', 'block_catquiz_statistics', (object) [
            'object' => get_string('semantic:object:' . $objecttype->value, 'block_catquiz_statistics'),
            'action' => get_string('semantic:action:' . $action->value, 'block_catquiz_statistics'),
        ]);
    }

    /**
     * Label for a variable key of the form event:<action>:<objecttype>, or null.
     *
     * @param string $variablekey Variable key.
     * @return string|null
     */
    public static function for_key(string $variablekey): ?string {
        $parts = explode(':', $variablekey);
        if (count($parts) !== 3 || $parts[0] !== 'event') {
            return null;
        }
        $action = semantic_action::tryFrom($parts[1]);
        $objecttype = object_type::tryFrom($parts[2]);
        return ($action && $objecttype) ? self::get($action, $objecttype) : null;
    }

    /**
     * Label of an adapter for settings/UI: semantic interpretation plus source event.
     *
     * @param semantic_adapter_interface $adapter Adapter.
     * @return string
     */
    public static function for_adapter(semantic_adapter_interface $adapter): string {
        return self::get($adapter->get_action(), $adapter->get_objecttype()) . ' (' . $adapter->get_eventname() . ')';
    }
}
