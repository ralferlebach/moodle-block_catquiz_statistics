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
 * Upgrade steps for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute block_catquiz_statistics upgrade steps.
 *
 * @param int $oldversion Version currently installed.
 * @return bool
 */
function xmldb_block_catquiz_statistics_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092900) {
        // Longitudinales Datenfundament (Issue #2): alle Tabellen aus install.xml
        // anlegen, sofern sie noch nicht existieren. Bis 0.4.x besaß das Plugin
        // keine eigenen Tabellen, daher gibt es keine Datenmigration.
        $tables = [
            'block_catquiz_statistics_dataset',
            'block_catquiz_statistics_variable',
            'block_catquiz_statistics_observation',
            'block_catquiz_statistics_milestone',
            'block_catquiz_statistics_subjectmap',
            'block_catquiz_statistics_evalmodel',
            'block_catquiz_statistics_evalrole',
        ];
        foreach ($tables as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $tablename);
            }
        }

        upgrade_block_savepoint(true, 2026092900, 'catquiz_statistics');
    }

    if ($oldversion < 2026092902) {
        // Rollenzuordnung: Messanlass (occasion) als Teil der Selektor-Identität,
        // damit z. B. Baseline (first) und Re-Test (last) derselben Skala in einem
        // Modell unterschiedliche Rollen erhalten können. Feldlängen gekürzt, damit
        // der zusammengesetzte Unique-Index Moodles Limit (333 Zeichen) einhält.
        $table = new xmldb_table('block_catquiz_statistics_evalrole');
        $oldindex = new xmldb_index('modelid_selector', XMLDB_INDEX_UNIQUE, ['modelid', 'selectortype', 'selector']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $dbman->change_field_precision(
            $table,
            new xmldb_field('selectortype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, 'role')
        );
        $dbman->change_field_precision(
            $table,
            new xmldb_field('selector', XMLDB_TYPE_CHAR, '160', null, XMLDB_NOTNULL, null, null, 'selectortype')
        );
        $field = new xmldb_field('occasion', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, 'any', 'selector');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $newindex = new xmldb_index(
            'modelid_selector_occasion',
            XMLDB_INDEX_UNIQUE,
            ['modelid', 'selectortype', 'selector', 'occasion']
        );
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // Advanced-Modus der semantischen Event-Schicht (Issue #4).
        if (!$dbman->table_exists('block_catquiz_statistics_eventmap')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'block_catquiz_statistics_eventmap');
        }

        upgrade_block_savepoint(true, 2026092902, 'catquiz_statistics');
    }

    return true;
}
