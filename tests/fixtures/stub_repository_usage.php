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
 * Stub repository for test_usage_report PHPUnit tests.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Minimal stub repository that returns a pre-configured list of DTOs.
 *
 * Avoids any database access; suitable for use in basic_testcase tests.
 */
class stub_repository_usage extends attempt_repository {
    /** @var attempt_data[] DTOs returned by get_attempts(). */
    private array $dtos;

    /**
     * Constructor.
     *
     * @param attempt_data[] $dtos DTOs to return from get_attempts().
     */
    public function __construct(array $dtos) {
        // Parent constructor requires DB; skip it intentionally.
        $this->dtos = $dtos;
    }

    /**
     * Return the pre-configured DTOs regardless of the filter.
     *
     * @param attempt_filter $filter Ignored.
     * @return attempt_data[]
     */
    public function get_attempts(attempt_filter $filter): array {
        return $this->dtos;
    }
}
