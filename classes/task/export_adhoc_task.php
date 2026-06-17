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
 * Ad-hoc task for large / system-wide exports.
 *
 * Small course-level exports run synchronously in report.php.
 * Large exports (system-wide Module e, or courses with > configurable threshold
 * of attempts) are queued as ad-hoc tasks, written to a file area, and linked
 * for download once complete.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\task;

/**
 * Ad-hoc export task.
 */
class export_adhoc_task extends \core\task\adhoc_task {

    /**
     * Return a human-readable task name for the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('pluginname', 'block_catquizstatistics') . ': export';
    }

    /**
     * Execute the queued export.
     *
     * Custom data keys expected in get_custom_data():
     *   courseid    – int
     *   instanceid  – int|null
     *   moduleid    – string ('a'–'e')
     *   format      – string ('csv', 'json', 'excel', 'ods')
     *   mode        – string ('wide', 'multi')
     *   userid      – int  (user who triggered the export)
     *
     * TODO Phase 1: implement export, write to pluginfile area, notify user.
     *
     * @return void
     */
    public function execute(): void {
        // TODO Phase 1: implement.
        mtrace('block_catquizstatistics export_adhoc_task: not yet implemented.');
    }
}
