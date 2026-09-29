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

namespace block_catquiz_statistics\analytics;

/**
 * Small, fixed set of generic semantic actions (Issue #4).
 *
 * The semantics of a milestone is always action + object type. VIEWED means
 * "opened", never "read" or "understood".
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum semantic_action: string {
    case STARTED = 'started';
    case COMPLETED = 'completed';
    case DELIVERED = 'delivered';
    case VIEWED = 'viewed';
    case INTERACTED = 'interacted';
    case ABANDONED = 'abandoned';
    case RESTARTED = 'restarted';
}
