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

namespace block_catquiz_statistics\analytics\provider;

use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;

/**
 * Public provider boundary for analytic observations.
 *
 * Providers read their canonical source (own tables, CATquiz, gradebook ...)
 * and return observations. They never perform capability checks; that is the
 * consumer's responsibility. Other plugins can contribute providers through
 * the {@see \block_catquiz_statistics\hook\collect_observation_providers} hook.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface observation_provider_interface {
    /**
     * Stable, unique provider key (e.g. 'catquiz', 'imported', 'milestones').
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Observations matching the query.
     *
     * @param observation_query $query Query scope.
     * @return observation[]
     */
    public function get_observations(observation_query $query): array;
}
