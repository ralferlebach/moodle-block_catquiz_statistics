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

namespace block_catquiz_statistics\hook;

use block_catquiz_statistics\analytics\provider\observation_provider_interface;

/**
 * Hook: lets other plugins contribute analytic observation providers.
 *
 * This is the extension point for a future course-spanning local plugin or for
 * source adapters (questionnaire, gradebook, learning paths) living in other
 * components. The block never depends on its consumers.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Collects observation providers for the block_catquiz_statistics analytics service.')]
#[\core\attribute\tags('block_catquiz_statistics', 'analytics')]
final class collect_observation_providers {
    /** @var observation_provider_interface[] Providers keyed by provider key. */
    private array $providers = [];

    /**
     * Register a provider. A later registration with the same key replaces the earlier one.
     *
     * @param observation_provider_interface $provider Provider.
     */
    public function add_provider(observation_provider_interface $provider): void {
        $this->providers[$provider->get_key()] = $provider;
    }

    /**
     * All registered providers.
     *
     * @return observation_provider_interface[]
     */
    public function get_providers(): array {
        return $this->providers;
    }
}
