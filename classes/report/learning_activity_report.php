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
 * Learning Activity report (Phase 3 — Modul activity, opt-in).
 *
 * This module requires the admin setting block_catquiz_statistics/enablemoduled
 * to be explicitly enabled and a documented data-protection justification,
 * because it will store log data in plugin-owned tables (beyond the platform
 * retention policy).  Own tables → privacy provider must be upgraded to
 * plugin\provider when this module is activated.
 *
 * Until enablemoduled=1 is set, the report returns no data and
 * get_flat_rows() returns an empty array with a notice string instead,
 * so the UI and export give a clear explanation rather than silent emptiness.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\report;

use block_catquiz_statistics\repository\attempt_filter;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Learning Activity report — opt-in, requires enablemoduled=1.
 */
class learning_activity_report implements report_interface {
    /** @var attempt_repository Injected repository. */
    private attempt_repository $repository;

    /**
     * Constructor.
     *
     * @param attempt_repository $repository Injected repository.
     */
    public function __construct(attempt_repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Module identifier.
     *
     * @return string
     */
    public function get_module_id(): string {
        return 'activity';
    }

    /**
     * Human-readable module name (localised).
     *
     * @return string
     */
    public function get_module_name(): string {
        return get_string('module_d', 'block_catquiz_statistics');
    }

    /**
     * Return flat rows.
     *
     * Returns an empty array when enablemoduled is not set.
     * Full implementation requires plugin-owned tables (Phase 3+).
     *
     * @param attempt_filter $filter Query scope.
     * @return array[]
     */
    public function get_flat_rows(attempt_filter $filter): array {
        if (!$this->is_enabled()) {
            return [];
        }
        // Phase 3+: implement when own tables are available.
        return [];
    }

    /**
     * Return aggregate statistics.
     *
     * @param attempt_filter $filter Query scope.
     * @return array<string,mixed>
     */
    public function get_aggregate_stats(attempt_filter $filter): array {
        return [];
    }

    /**
     * Return column definitions.
     *
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [];
    }

    /**
     * Whether the learning activity module is enabled in admin settings.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool) get_config('block_catquiz_statistics', 'enablemoduled');
    }
}
