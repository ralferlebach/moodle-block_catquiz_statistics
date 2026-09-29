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

use block_catquiz_statistics\analytics\semantic\semantic_adapter_interface;

/**
 * Hook: lets other plugins contribute semantic event adapters.
 *
 * Contributed adapters are only consulted for events this plugin observes or
 * that another plugin routes to {@see \block_catquiz_statistics\observer::handle()}.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Collects semantic event adapters for block_catquiz_statistics.')]
#[\core\attribute\tags('block_catquiz_statistics', 'analytics')]
final class collect_semantic_adapters {
    /** @var semantic_adapter_interface[] Adapters keyed by adapter key. */
    private array $adapters = [];

    /**
     * Register an adapter; a later registration with the same key replaces the earlier one.
     *
     * @param semantic_adapter_interface $adapter Adapter.
     */
    public function add_adapter(semantic_adapter_interface $adapter): void {
        $this->adapters[$adapter->get_key()] = $adapter;
    }

    /**
     * All registered adapters.
     *
     * @return semantic_adapter_interface[]
     */
    public function get_adapters(): array {
        return $this->adapters;
    }
}
