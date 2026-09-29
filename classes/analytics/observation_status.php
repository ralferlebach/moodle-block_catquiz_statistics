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
 * Explicit observation status incl. missing semantics (Issues #6, #7).
 *
 * "Not observed" is never the same as "observed and negative". Missing data
 * is never silently equated with non-participation or drop-out.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum observation_status: string {
    case OBSERVED = 'observed';
    case MISSING_NORECORD = 'missing_norecord';
    case MISSING_NOTPARTICIPATED = 'missing_notparticipated';
    case MISSING_NOTGRADED = 'missing_notgraded';
    case MISSING_TECHNICAL = 'missing_technical';
    case MISSING_UNKNOWN = 'missing_unknown';
    case NOT_APPLICABLE = 'not_applicable';

    /**
     * Whether this status carries a usable value.
     *
     * @return bool
     */
    public function has_value(): bool {
        return $this === self::OBSERVED;
    }
}
