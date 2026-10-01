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
 * Uninstall hook for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove all synthetic demo cohorts before the plugin tables are dropped.
 *
 * Demo courses and demo users live in core tables; without this hook they would
 * be orphaned when the registry disappears with the plugin.
 *
 * @return bool
 */
function xmldb_block_catquiz_statistics_uninstall() {
    global $DB;
    if (!$DB->get_manager()->table_exists('block_catquiz_statistics_demo')) {
        return true;
    }
    $generator = new \block_catquiz_statistics\demo\cohort_generator();
    foreach (\block_catquiz_statistics\demo\cohort_generator::get_runs() as $run) {
        try {
            $generator->reset((int) $run->id);
        } catch (\Throwable $e) {
            debugging('Demo run ' . $run->id . ' could not be removed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    return true;
}
