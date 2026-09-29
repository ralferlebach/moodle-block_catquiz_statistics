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

namespace block_catquiz_statistics\analytics\semantic;

use block_catquiz_statistics\analytics\semantic\adapter\attempt_completed_adapter;
use block_catquiz_statistics\analytics\semantic\adapter\feedbacktab_clicked_adapter;
use block_catquiz_statistics\analytics\semantic\adapter\result_page_viewed_adapter;
use block_catquiz_statistics\hook\collect_semantic_adapters;

/**
 * Registry of semantic event adapters.
 *
 * Unknown events are never interpreted automatically: only events with a
 * registered, enabled adapter produce milestones. Standard adapters can be
 * disabled via the setting 'disabledadapters'.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adapter_registry {
    /** @var semantic_adapter_interface[] Adapters keyed by adapter key. */
    private array $adapters = [];

    /**
     * Constructor.
     *
     * @param semantic_adapter_interface[] $adapters Adapters.
     */
    public function __construct(array $adapters) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->get_key()] = $adapter;
        }
    }

    /**
     * The built-in standard adapters.
     *
     * @return semantic_adapter_interface[]
     */
    public static function get_standard_adapters(): array {
        return [
            new attempt_completed_adapter(),
            new result_page_viewed_adapter(),
            new feedbacktab_clicked_adapter(),
        ];
    }

    /**
     * Registry with standard adapters plus hook contributions.
     *
     * @return self
     */
    public static function create_default(): self {
        $hook = new collect_semantic_adapters();
        foreach (self::get_standard_adapters() as $adapter) {
            $hook->add_adapter($adapter);
        }
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return new self($hook->get_adapters());
    }

    /**
     * All adapters.
     *
     * @return semantic_adapter_interface[]
     */
    public function get_adapters(): array {
        return $this->adapters;
    }

    /**
     * Whether an adapter is enabled (not listed in 'disabledadapters').
     *
     * @param string $key Adapter key.
     * @return bool
     */
    public static function is_enabled(string $key): bool {
        $disabled = (string) get_config('block_catquiz_statistics', 'disabledadapters');
        return !in_array($key, array_filter(explode(',', $disabled)), true);
    }

    /**
     * Enabled adapters responsible for an event class.
     *
     * @param string $eventname Event class name (with or without leading backslash).
     * @return semantic_adapter_interface[]
     */
    public function get_adapters_for_event(string $eventname): array {
        $eventname = '\\' . ltrim($eventname, '\\');
        return array_values(array_filter(
            $this->adapters,
            static fn(semantic_adapter_interface $a) => $a->get_eventname() === $eventname && self::is_enabled($a->get_key())
        ));
    }

    /**
     * Whether any registered adapter (enabled or not) claims this event class.
     *
     * Used to prevent advanced mappings that would double-interpret an event.
     *
     * @param string $eventname Event class name.
     * @return bool
     */
    public function claims_event(string $eventname): bool {
        $eventname = '\\' . ltrim($eventname, '\\');
        foreach ($this->adapters as $adapter) {
            if ($adapter->get_eventname() === $eventname) {
                return true;
            }
        }
        return false;
    }
}
