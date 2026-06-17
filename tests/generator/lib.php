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
 * Test data generator for block_catquiz_statistics.
 *
 * Provides factory methods that create catquiz attempt records and related
 * fixtures for PHPUnit integration tests.  Requires local_catquiz to be
 * installed; tests calling these methods should guard with markTestSkipped()
 * when the plugin is absent.
 *
 * @package    block_catquiz_statistics
 * @category   test
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Generator for block_catquiz_statistics test data.
 */
class block_catquiz_statistics_generator extends testing_block_generator {
    /**
     * Insert a minimal local_catquiz_attempts record for testing.
     *
     * The json payload mimics a real attempt with personabilities, se and a
     * primaryscale entry so that repository/report tests have realistic data.
     *
     * @param array $overrides Field overrides for the attempt record.
     * @return int Inserted record id.
     */
    public function create_catquiz_attempt(array $overrides = []): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquiz_attempts')) {
            throw new coding_exception(
                'local_catquiz is not installed. ' .
                'Guard with markTestSkipped() when local_catquiz is absent.'
            );
        }

        $defaults = [
            'userid'                      => 2,
            'scaleid'                     => 1,
            'contextid'                   => 1,
            'courseid'                    => 1,
            'attemptid'                   => 1,
            'component'                   => 'mod_adaptivequiz',
            'instanceid'                  => 1,
            'teststrategy'                => 1,
            'status'                      => 1,
            'total_number_of_testitems'   => 20,
            'number_of_testitems_used'    => 8,
            'personability_before_attempt' => 0.0,
            'personability_after_attempt'  => 0.5,
            'starttime'                   => time() - 600,
            'endtime'                     => time(),
            'json'                        => json_encode([
                'catscaleid'     => 1,
                'testid'         => 1,
                'personabilities' => [1 => 0.5],
                'se'             => [1 => 0.3],
                'primaryscale'   => (object) ['id' => 1, 'name' => 'TestScale'],
                'catscales'      => [1 => (object) ['name' => 'TestScale']],
                'graphicalsummary_data' => [],
            ]),
            'debug_info'  => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $record = array_merge($defaults, $overrides);
        return $DB->insert_record('local_catquiz_attempts', (object) $record);
    }
}
