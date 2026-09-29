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
 * Value types of an observation.
 *
 * EVENT marks observations that represent a semantic milestone rather than a
 * measured value; their "value" is the fact that they occurred.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum value_type: string {
    case NUMERIC = 'numeric';
    case INTEGER = 'integer';
    case BOOLEAN = 'boolean';
    case TEXT = 'string';
    case DATETIME = 'datetime';
    case CATEGORICAL = 'categorical';
    case ORDINAL = 'ordinal';
    case EVENT = 'event';

    /**
     * Whether the value is stored in the numeric column.
     *
     * @return bool
     */
    public function is_numeric(): bool {
        return in_array($this, [self::NUMERIC, self::INTEGER, self::DATETIME, self::ORDINAL], true);
    }
}
