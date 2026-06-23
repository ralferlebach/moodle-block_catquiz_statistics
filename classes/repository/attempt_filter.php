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

    /** @var int|null Restrict to a single mod_adaptivequiz instance (legacy single-select fallback). */
    public readonly ?int $instanceid;

    /**
     * Restrict to several mod_adaptivequiz instances (multi-select).
     *
     * When this array is non-empty it takes precedence over {@see self::$instanceid}.
     * An empty array (or null) means "no instance restriction" (all instances).
     * Holds integer instance IDs.
     *
     * @var int[]|null
     */
    public readonly ?array $instanceids;

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
     * @param int      $courseid    Mandatory course scope.
     * @param int|null $instanceid  Restrict to a single mod_adaptivequiz instance (legacy fallback).
     * @param int|null $scaleid     Restrict to a specific CAT scale.
     * @param int|null $starttime   Unix timestamp lower bound.
     * @param int|null $endtime     Unix timestamp upper bound.
     * @param bool     $systemwide  Allow cross-course query.
     * @param int[]|null $instanceids Restrict to several instances (takes precedence over $instanceid when non-empty).
     */
    public function __construct(
        int $courseid,
        ?int $instanceid = null,
        ?int $scaleid = null,
        ?int $starttime = null,
        ?int $endtime = null,
        bool $systemwide = false,
        ?array $instanceids = null
    ) {
        $this->courseid   = $courseid;
        $this->instanceid = $instanceid;
        $this->scaleid    = $scaleid;
        $this->starttime  = $starttime;
        $this->endtime    = $endtime;
        $this->systemwide = $systemwide;
        // Normalise to a clean list of distinct positive integers, or null when empty.
        if (!empty($instanceids)) {
            $clean = array_values(array_unique(array_filter(
                array_map('intval', $instanceids),
                static fn($id) => $id > 0
            )));
            $this->instanceids = !empty($clean) ? $clean : null;
        } else {
            $this->instanceids = null;
        }
    }

    /**
     * Build a filter from current HTTP request parameters.
     *
     * Reads the multi-select instanceids[] array; falls back to the single
     * instanceid parameter when no array is provided.
     *
     * @param int      $courseid   Course ID (already resolved by caller).
     * @param int|null $instanceid Optional single instance override (legacy fallback).
     * @return self
     */
    public static function from_request(int $courseid, ?int $instanceid = null): self {
        $instanceids = optional_param_array('instanceids', [], PARAM_INT);
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
            instanceids: $instanceids,
        );
    }
    /**
     * Build a system-wide filter from HTTP request parameters.
     *
     * For use by adminreport.php.  Sets systemwide=true and treats courseid=0
     * as "all courses".  Passing a non-zero courseid restricts the query to
     * that course while still running at system context.
     *
     * @return self
     */
    public static function from_request_systemwide(): self {
        $courseid   = optional_param('courseid', 0, PARAM_INT);
        $instanceid = optional_param('instanceid', 0, PARAM_INT) ?: null;
        $instanceids = optional_param_array('instanceids', [], PARAM_INT);
        $scaleid    = optional_param('scaleid', 0, PARAM_INT) ?: null;
        $starttime  = null;
        $endtime    = null;
        $startdate  = optional_param('startdate', '', PARAM_ALPHANUMEXT);
        $enddate    = optional_param('enddate', '', PARAM_ALPHANUMEXT);
        if ($startdate) {
            $starttime = (int) strtotime($startdate . ' 00:00:00') ?: null;
        }
        if ($enddate) {
            $endtime = (int) strtotime($enddate . ' 23:59:59') ?: null;
        }
        return new self(
            courseid: $courseid,
            instanceid: $instanceid,
            scaleid: $scaleid,
            starttime: $starttime,
            endtime: $endtime,
            systemwide: true,
            instanceids: $instanceids,
        );
    }
}
