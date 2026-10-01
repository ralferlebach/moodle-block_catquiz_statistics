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
            'block_catquiz_statistics_construct',
            'block_catquiz_statistics_citem',
            'block_catquiz_statistics_outcome',
            'block_catquiz_statistics_demo',
            'block_catquiz_statistics_demouser',
            'block_catquiz_statistics_evalrevision',
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
        //
        // Der Schritt ist re-entrant: Kommt eine Site von 0.4.x, hat Schritt 2026092900
        // die Tabelle bereits aus der aktuellen install.xml angelegt (mit occasion und
        // neuem Index). Spaltenlängen lassen sich nur ohne abhängigen Index ändern,
        // daher werden BEIDE möglichen Indizes vorher entfernt und danach neu angelegt.
        $table = new xmldb_table('block_catquiz_statistics_evalrole');
        $oldindex = new xmldb_index('modelid_selector', XMLDB_INDEX_UNIQUE, ['modelid', 'selectortype', 'selector']);
        $newindex = new xmldb_index(
            'modelid_selector_occasion',
            XMLDB_INDEX_UNIQUE,
            ['modelid', 'selectortype', 'selector', 'occasion']
        );
        foreach ([$oldindex, $newindex] as $index) {
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
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
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // Advanced-Modus der semantischen Event-Schicht (Issue #4).
        if (!$dbman->table_exists('block_catquiz_statistics_eventmap')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'block_catquiz_statistics_eventmap');
        }

        upgrade_block_savepoint(true, 2026092902, 'catquiz_statistics');
    }

    if ($oldversion < 2026093001) {
        // Import-, Variablen- und Konstrukteregister (Issue #3). Re-entrant: kommt die
        // Site von 0.4.x, existieren Tabellen und Felder bereits aus Schritt 2026092900.
        foreach (['block_catquiz_statistics_construct', 'block_catquiz_statistics_citem'] as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $tablename);
            }
        }

        $table = new xmldb_table('block_catquiz_statistics_observation');
        $field = new xmldb_field('constructid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'variableid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Fremdschlüssel legen in Moodle einen Index an; nur hinzufügen, wenn er fehlt.
        if (!$dbman->index_exists($table, new xmldb_index('constructid', XMLDB_INDEX_NOTUNIQUE, ['constructid']))) {
            $key = new xmldb_key('constructid', XMLDB_KEY_FOREIGN, ['constructid'], 'block_catquiz_statistics_construct', ['id']);
            $dbman->add_key($table, $key);
        }

        $table = new xmldb_table('block_catquiz_statistics_dataset');
        $field = new xmldb_field('importhash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'matchfield');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('importhash', XMLDB_INDEX_NOTUNIQUE, ['importhash']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_block_savepoint(true, 2026093001, 'catquiz_statistics');
    }

    if ($oldversion < 2026093003) {
        // Outcome-Definitionen (Issue #6). Re-entrant.
        if (!$dbman->table_exists('block_catquiz_statistics_outcome')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'block_catquiz_statistics_outcome');
        }
        upgrade_block_savepoint(true, 2026093003, 'catquiz_statistics');
    }

    if ($oldversion < 2026093004) {
        // Registry synthetischer Demo-Kohorten (Issue #9). Re-entrant.
        foreach (['block_catquiz_statistics_demo', 'block_catquiz_statistics_demouser'] as $tablename) {
            if (!$dbman->table_exists($tablename)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $tablename);
            }
        }
        upgrade_block_savepoint(true, 2026093004, 'catquiz_statistics');
    }

    if ($oldversion < 2026093005) {
        // Revisionssichere Snapshots der Evaluationsmodelle (Issue #7). Re-entrant.
        if (!$dbman->table_exists('block_catquiz_statistics_evalrevision')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'block_catquiz_statistics_evalrevision');
        }
        upgrade_block_savepoint(true, 2026093005, 'catquiz_statistics');
    }

    return true;
}
