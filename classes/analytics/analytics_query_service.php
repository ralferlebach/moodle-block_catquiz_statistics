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

use block_catquiz_statistics\analytics\provider\catquiz_provider;
use block_catquiz_statistics\analytics\provider\imported_observation_provider;
use block_catquiz_statistics\analytics\provider\milestone_provider;
use block_catquiz_statistics\analytics\provider\observation_provider_interface;
use block_catquiz_statistics\hook\collect_observation_providers;

/**
 * Public analytics service: block UI -> service -> providers -> sources.
 *
 * The service is independent of report.php and of block instances, so a
 * future course-spanning consumer can use it unchanged. It performs no
 * capability checks: callers must authorise every context they query.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analytics_query_service {
    /** @var observation_provider_interface[] Providers keyed by provider key. */
    private array $providers = [];

    /**
     * Constructor.
     *
     * @param observation_provider_interface[] $providers Providers to use.
     */
    public function __construct(array $providers) {
        foreach ($providers as $provider) {
            $this->providers[$provider->get_key()] = $provider;
        }
    }

    /**
     * Service with the built-in providers plus all providers contributed via hook.
     *
     * @return self
     */
    public static function create_default(): self {
        $hook = new collect_observation_providers();
        $hook->add_provider(new catquiz_provider());
        $hook->add_provider(new milestone_provider());
        $hook->add_provider(new imported_observation_provider());
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return new self($hook->get_providers());
    }

    /**
     * Keys of the active providers.
     *
     * @return string[]
     */
    public function get_provider_keys(): array {
        return array_keys($this->providers);
    }

    /**
     * All observations matching the query, ordered by user and time.
     *
     * @param observation_query $query Query scope.
     * @return observation[]
     */
    public function get_observations(observation_query $query): array {
        $all = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->get_observations($query) as $obs) {
                $all[] = $obs;
            }
        }
        usort($all, static fn(observation $a, observation $b) =>
            [$a->userid, $a->get_sorttime() ?? PHP_INT_MAX, $a->sourcekey]
            <=> [$b->userid, $b->get_sorttime() ?? PHP_INT_MAX, $b->sourcekey]);
        return $all;
    }

    /**
     * Chronological, course-spanning timeline of one person.
     *
     * @param int $userid User.
     * @param observation_query|null $scope Optional further restrictions (userids are overridden).
     * @return observation[]
     */
    public function get_timeline(int $userid, ?observation_query $scope = null): array {
        $query = new observation_query(
            userids: [$userid],
            courseids: $scope?->courseids,
            from: $scope?->from,
            to: $scope?->to,
            variablekeys: $scope?->variablekeys,
            synthetic: $scope?->synthetic,
        );
        return $this->get_observations($query);
    }

    /**
     * Observations grouped by user id.
     *
     * @param observation_query $query Query scope.
     * @return array<int, observation[]>
     */
    public function get_observations_by_user(observation_query $query): array {
        $grouped = [];
        foreach ($this->get_observations($query) as $obs) {
            $grouped[$obs->userid][] = $obs;
        }
        return $grouped;
    }
}
