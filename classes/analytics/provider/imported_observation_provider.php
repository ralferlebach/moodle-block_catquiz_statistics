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

use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Provider for observations persisted by this plugin (imports, surveys, external outcomes).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imported_observation_provider implements observation_provider_interface {
    /**
     * Constructor.
     *
     * @param observation_repository $repository Repository.
     */
    public function __construct(
        /** @var observation_repository Repository. */
        private readonly observation_repository $repository = new observation_repository(),
    ) {
    }

    /**
     * Provider key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'imported';
    }

    /**
     * Observations matching the query.
     *
     * @param observation_query $query Query scope.
     * @return \block_catquiz_statistics\analytics\observation[]
     */
    public function get_observations(observation_query $query): array {
        return $this->repository->find($query);
    }
}
