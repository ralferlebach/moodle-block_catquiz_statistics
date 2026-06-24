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
     * Build a default attempts.json payload for testing.
     *
     * Used by create_catquiz_attempt() and can be overridden via the json override key.
     *
     * @param int $scaleid Global scale ID.
     * @param float $pp Person ability.
     * @param float $se Standard error.
     * @return string JSON-encoded payload.
     */
    public static function build_attempt_json(
        int $scaleid = 1,
        float $pp = 0.5,
        float $se = 0.3
    ): string {
        return json_encode([
            'catscaleid' => $scaleid,
            'testid' => 1,
            'personabilities' => [$scaleid => $pp],
            'se' => [$scaleid => $se],
            'primaryscale' => (object) ['id' => $scaleid, 'name' => 'TestScale'],
            'catscales' => [$scaleid => (object) ['name' => 'TestScale']],
            'graphicalsummary_data' => [],
        ]);
    }

    /**
     * Build a fully customisable attempts.json payload for testing.
     *
     * Unlike build_attempt_json() this allows multiple scales, an explicit
     * primaryscale, an explicit testid and a graphicalsummary_data array, so
     * that exporter and report tests can exercise SE=-1 suppression, the
     * Ergebnisskala column and per-step item analysis.
     *
     * @param array $opts {
     * @var int        $globalscaleid    Global (root) scale ID. Default 1.
     * @var int        $testid           local_catquiz_attempts.testid. Default 1.
     * @var array      $personabilities  Map scaleid => pp.
     * @var array      $se               Map scaleid => se (use -1 for "no value").
     * @var array|null $primaryscale     ['id'=>int,'name'=>string] or null.
     * @var array      $catscales        Map scaleid => ['name'=>string].
     * @var array      $graphical        graphicalsummary_data step objects.
     * }
     * @return string JSON-encoded payload.
     */
    public static function build_custom_json(array $opts = []): string {
        $globalscaleid = $opts['globalscaleid'] ?? 1;
        $personabilities = $opts['personabilities'] ?? [$globalscaleid => 0.5];
        $se = $opts['se'] ?? [$globalscaleid => 0.3];
        $catscales = [];
        foreach (($opts['catscales'] ?? [$globalscaleid => ['name' => 'TestScale']]) as $sid => $meta) {
            $catscales[$sid] = (object) $meta;
        }
        $primaryscale = null;
        if (array_key_exists('primaryscale', $opts)) {
            $primaryscale = $opts['primaryscale'] !== null
                ? (object) $opts['primaryscale'] : null;
        } else {
            $primaryscale = (object) ['id' => $globalscaleid, 'name' => 'TestScale'];
        }
        $graphical = [];
        foreach (($opts['graphical'] ?? []) as $step) {
            $graphical[] = (object) $step;
        }
        return json_encode([
            'catscaleid' => $globalscaleid,
            'testid' => $opts['testid'] ?? 1,
            'personabilities' => $personabilities,
            'se' => $se,
            'primaryscale' => $primaryscale,
            'catscales' => $catscales,
            'graphicalsummary_data' => $graphical,
        ]);
    }

    /**
     * Insert a minimal {adaptivequiz} row for testing.
     *
     * Creates only the {adaptivequiz} record, not a full course-module entry.
     * This is sufficient for tests that exercise get_catquiz_instances_for_course(),
     * which JOINs {local_catquiz_attempts} against {adaptivequiz} to fetch the
     * human-readable activity name.
     *
     * Tests that call this method should guard with markTestSkipped() when
     * mod_adaptivequiz is absent (check_schema_compatibility() covers this).
     *
     * @param array $overrides Field overrides; 'course' and 'name' are most useful.
     * @return int The id of the inserted {adaptivequiz} row.
     */
    public function create_adaptivequiz_instance(array $overrides = []): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('adaptivequiz')) {
            throw new coding_exception(
                'mod_adaptivequiz is not installed. '
                . 'Guard with markTestSkipped() when the schema is absent.'
            );
        }

        $defaults = [
            'course' => 1,
            'name' => 'Test CAT Quiz',
            'intro' => '',
            'introformat' => 1,
            'attempts' => 0,
            'starttime' => 0,
            'stoptime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $record = (object) array_merge($defaults, $overrides);
        return $DB->insert_record('adaptivequiz', $record);
    }

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
                'local_catquiz is not installed. '
                . 'Guard with markTestSkipped() when local_catquiz is absent.'
            );
        }

        $defaults = [
            'userid' => 2,
            'scaleid' => 1,
            'contextid' => 1,
            'courseid' => 1,
            'attemptid' => 1,
            'component' => 'mod_adaptivequiz',
            'instanceid' => 1,
            'teststrategy' => 1,
            'status' => 1,
            'total_number_of_testitems' => 20,
            'number_of_testitems_used' => 8,
            'personability_before_attempt' => 0.0,
            'personability_after_attempt' => 0.5,
            'starttime' => time() - 600,
            'endtime' => time(),
            'json' => self::build_attempt_json(),
            'debug_info' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $record = array_merge($defaults, $overrides);
        return $DB->insert_record('local_catquiz_attempts', (object) $record);
    }

    /**
     * Insert a local_catquiz_tests record for testing.
     *
     * Creates a test environment record matching the given instanceid, with
     * configurable SE and N-min thresholds so that SE validity tests can
     * exercise all code paths.
     *
     * @param array $overrides Field overrides; 'semax' and 'nminscale' are
     *   convenience shortcuts for the nested JSON keys.
     * @return int Inserted record id.
     */
    public function create_catquiz_test(array $overrides = []): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquiz_tests')) {
            throw new coding_exception(
                'local_catquiz is not installed. '
                . 'Guard with markTestSkipped() when local_catquiz is absent.'
            );
        }

        $semax = $overrides['semax'] ?? null;
        $nminscale = $overrides['nminscale'] ?? null;
        unset($overrides['semax'], $overrides['nminscale']);

        $settingsjson = json_encode((object) [
            'catquiz_catscales' => 1,
            'catquiz_selectteststrategy' => 1,
            'catquiz_standarderrorgroup' => (object) [
                'catquiz_standarderror_min' => '0.01',
                'catquiz_standarderror_max' => $semax !== null ? (string) $semax : '0.50',
            ],
            'maxquestionsscalegroup' => (object) [
                'catquiz_maxquestionspersubscale' => 20,
                'catquiz_minquestionspersubscale' => $nminscale !== null ? (int) $nminscale : 0,
            ],
            'maxquestionsgroup' => (object) [
                'catquiz_maxquestions' => 40,
                'catquiz_minquestions' => 3,
            ],
        ]);

        $defaults = [
            'parentid' => 0,
            'componentid' => 1,
            'component' => 'mod_adaptivequiz',
            'catscaleid' => 1,
            'courseid' => 1,
            'name' => 'Test CAT environment',
            'description' => '',
            'descriptionformat' => 1,
            'json' => $settingsjson,
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $record = array_merge($defaults, $overrides);
        return $DB->insert_record('local_catquiz_tests', (object) $record);
    }
}
