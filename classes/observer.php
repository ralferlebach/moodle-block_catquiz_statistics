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

namespace block_catquiz_statistics;

use block_catquiz_statistics\analytics\semantic\adapter_registry;
use block_catquiz_statistics\repository\milestone_repository;

/**
 * Event observer: technical event -> adapter -> semantic milestone.
 *
 * Registered in db/events.php for the events of the standard adapters.
 * Observers are non-internal, i.e. they run after the triggering transaction
 * has been committed. Failures never break the triggering user action.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Handle an event.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    public static function handle(\core\event\base $event): void {
        try {
            $registry = adapter_registry::create_default();
            $repository = new milestone_repository();
            foreach ($registry->get_adapters_for_event(get_class($event)) as $adapter) {
                if (!$adapter->is_persisted()) {
                    continue;
                }
                $spec = $adapter->map($event);
                if ($spec !== null) {
                    $repository->record_spec($spec);
                }
            }
        } catch (\Throwable $e) {
            debugging('block_catquiz_statistics observer failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
