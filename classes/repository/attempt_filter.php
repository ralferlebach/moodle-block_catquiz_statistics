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

/**
 * Immutable filter value object for attempt queries.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\repository;

/**
 * Encapsulates every dimension by which attempt queries can be scoped.
 *
 * All properties are readonly; create a new instance to change filters.
 * Use {@see self::from_request()} to build from HTTP parameters.
 */
class attempt_filter {
    /** @var int Mandatory course scope (0 = system-wide, requires viewall). */
    public readonly int $courseid;

    /** @var int|null Restrict to a single mod_adaptivequiz instance. */
    public readonly ?int $instanceid;

    /** @var int|null Restrict to a specific CAT scale. */
    public readonly ?int $scaleid;

    /** @var int|null Unix timestamp lower bound (attempt starttime). */
    public readonly ?int $starttime;

    /** @var int|null Unix timestamp upper bound (attempt starttime). */
    public readonly ?int $endtime;

    /** @var bool Allow cross-course query (requires viewall capability). */
    public readonly bool $systemwide;

    /**
     * Constructor – all parameters optional except courseid.
     *
     * @param int      $courseid   Mandatory course scope.
     * @param int|null $instanceid Restrict to a single mod_adaptivequiz instance.
     * @param int|null $scaleid    Restrict to a specific CAT scale.
     * @param int|null $starttime  Unix timestamp lower bound.
     * @param int|null $endtime    Unix timestamp upper bound.
     * @param bool     $systemwide Allow cross-course query.
     */
    public function __construct(
        int $courseid,
        ?int $instanceid = null,
        ?int $scaleid = null,
        ?int $starttime = null,
        ?int $endtime = null,
        bool $systemwide = false
    ) {
        $this->courseid   = $courseid;
        $this->instanceid = $instanceid;
        $this->scaleid    = $scaleid;
        $this->starttime  = $starttime;
        $this->endtime    = $endtime;
        $this->systemwide = $systemwide;
    }

    /**
     * Build a filter from current HTTP request parameters.
     *
     * @param int      $courseid   Course ID (already resolved by caller).
     * @param int|null $instanceid Optional instance override.
     * @return self
     */
    public static function from_request(int $courseid, ?int $instanceid = null): self {
        $instanceid = $instanceid ?? (optional_param('instanceid', 0, PARAM_INT) ?: null);
        $scaleid = optional_param('scaleid', 0, PARAM_INT) ?: null;
        $starttime = optional_param('starttime', 0, PARAM_INT) ?: null;
        $endtime = optional_param('endtime', 0, PARAM_INT) ?: null;

        return new self(
            courseid: $courseid,
            instanceid: $instanceid,
            scaleid: $scaleid,
            starttime: $starttime,
            endtime: $endtime,
        );
    }
}
