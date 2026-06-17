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
 * Data Transfer Object for a single catquiz attempt.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\dto;

/**
 * Immutable representation of a fully hydrated catquiz attempt.
 *
 * Fields are grouped by source:
 *   Structured columns  – local_catquiz_attempts (typed DB fields)
 *   Parsed JSON         – local_catquiz_attempts.json  (per-scale data, SE, primaryscale)
 *   Graphical summary   – attempts.json → graphicalsummary_data  (per-step trajectory)
 *   Computed            – derived values populated by the repository
 */
class attempt_data {
    /** @var int local_catquiz_attempts.id */
    public int $id = 0;

    /** @var int User ID. */
    public int $userid = 0;

    /** @var string|null Username (joined from {user}). */
    public ?string $username = null;

    /** @var string|null First name. */
    public ?string $firstname = null;

    /** @var string|null Last name. */
    public ?string $lastname = null;

    /** @var string|null E-mail. */
    public ?string $email = null;

    /** @var int|null CAT scale ID. */
    public ?int $scaleid = null;

    /** @var int|null CAT context ID. */
    public ?int $contextid = null;

    /** @var int|null Course ID. */
    public ?int $courseid = null;

    /** @var int local_catquiz_attempts.attemptid = adaptivequiz_attempt.id */
    public int $attemptid = 0;

    /** @var int|null mod_adaptivequiz instance ID. */
    public ?int $instanceid = null;

    /** @var int|null Test strategy constant (LOCAL_CATQUIZ_STRATEGY_*). */
    public ?int $teststrategy = null;

    /** @var int|null Attempt status constant. */
    public ?int $status = null;

    /** @var int|null Total items available in the item pool. */
    public ?int $totaltestitems = null;

    /** @var int|null Number of items actually presented. */
    public ?int $usedtestitems = null;

    /** @var float|null Person ability before this attempt. */
    public ?float $personabilitybeforeattempt = null;

    /** @var float|null Person ability after this attempt (global / root scale). */
    public ?float $personabilityafterattempt = null;

    /** @var int|null Attempt start Unix timestamp. */
    public ?int $starttime = null;

    /** @var int|null Attempt end Unix timestamp. */
    public ?int $endtime = null;


    /** @var int|null Global / root scale ID (json.catscaleid). */
    public ?int $globalscaleid = null;

    /**
     * Person ability per scale ID (json.personabilities or json.personabilities_abilities).
     *
     * @var array<int,float>
     */
    public array $personabilities = [];

    /**
     * Standard error of measurement per scale ID (json.se).
     *
     * @var array<int,float>
     */
    public array $se = [];

    /** @var object|null Primary / result scale metadata (json.primaryscale). */
    public ?object $primaryscale = null;

    /**
     * Scale metadata keyed by scale ID (json.catscales).
     *
     * @var array<int,object>
     */
    public array $catscales = [];

    /** @var int|null Test ID (json.testid). */
    public ?int $testid = null;


    /**
     * Per-step trajectory data from graphicalsummary_data.
     *
     * Each entry is a stdClass with:
     *   id, questionname, lastresponse (fraction), difficulty,
     *   questionscale, questionscale_name, fisherinformation, personability_after.
     *
     * Available for all strategies that activate the graphicalsummary
     * feedbackgenerator (all 6 standard strategies).
     *
     * @var object[]
     */
    public array $graphicalsummary = [];


    /** @var float|null Duration in seconds (endtime − starttime); null if incomplete. */
    public ?float $durationseconds = null;
}
