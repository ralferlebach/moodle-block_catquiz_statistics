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
 * Single DB isolation layer for all catquiz attempt data.
 *
 * This is the ONLY class that knows about:
 *   - local_catquiz_* table names and columns
 *   - adaptivequiz_attempt join path
 *   - question_attempts / question_attempt_steps join path
 *   - the structure of attempts.json and attempts.debug_info JSON blobs
 *
 * All other classes (reports, exporters, statistics) receive typed DTOs
 * from this repository and never touch raw DB records or raw JSON.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\repository;

use block_catquizstatistics\dto\attempt_data;

/**
 * Repository for CAT quiz attempt data.
 */
class attempt_repository {

    /**
     * Check that all tables and columns this plugin depends on actually exist.
     *
     * Call once per page load; return false → show a graceful degradation
     * message rather than DB errors.
     *
     * @return bool True when schema is compatible.
     */
    public function check_schema_compatibility(): bool {
        global $DB;
        $dbman = $DB->get_manager();

        $required = [
            'local_catquiz_attempts',
            'local_catquiz_tests',
            'local_catquiz_catscales',
            'local_catquiz_personparams',
            'adaptivequiz_attempt',
        ];
        foreach ($required as $table) {
            if (!$dbman->table_exists(new \xmldb_table($table))) {
                return false;
            }
        }

        // Verify critical columns that we parse from the JSON blob.
        $attempttable = new \xmldb_table('local_catquiz_attempts');
        foreach (['json', 'debug_info', 'instanceid', 'contextid', 'scaleid'] as $col) {
            if (!$dbman->field_exists($attempttable, $col)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return all mod_adaptivequiz instances that use catquiz in a course.
     *
     * @param int $courseid Course ID.
     * @return array Array of stdClass with fields: instanceid, name, catscaleid, catscalename, attemptcount.
     */
    public function get_catquiz_instances_for_course(int $courseid): array {
        // TODO Phase 1: implement query against local_catquiz_tests JOIN local_catquiz_catscales.
        return [];
    }

    /**
     * Return attempt rows matching the filter, hydrated as attempt_data DTOs.
     *
     * @param attempt_filter $filter Query scope.
     * @return attempt_data[]
     */
    public function get_attempts(attempt_filter $filter): array {
        // TODO Phase 1: build SELECT with dynamic WHERE clauses and return hydrated DTOs.
        return [];
    }

    /**
     * Return a single attempt including full JSON and graphicalsummary parsing.
     *
     * @param int $attemptid local_catquiz_attempts.id (not adaptivequiz_attempt.id).
     * @return attempt_data|null
     */
    public function get_attempt_with_detail(int $attemptid): ?attempt_data {
        // TODO Phase 1.
        return null;
    }

    /**
     * Return person-parameter rows (ability, SE per scale) for a filter scope.
     *
     * @param attempt_filter $filter Query scope.
     * @return array Array of stdClass with fields: userid, catscaleid, ability, standarderror.
     */
    public function get_personparams(attempt_filter $filter): array {
        // TODO Phase 1.
        return [];
    }

    /**
     * Return question-engine step data for a single adaptivequiz_attempt.
     *
     * Join path:
     *   adaptivequiz_attempt.uniqueid
     *   → question_attempts.questionusageid
     *   → question_attempt_steps.questionattemptid   (fraction, timecreated)
     *   → question_attempt_step_data.attemptstepid   (name, value – response options)
     *   → question_attempts.questionid               (joined for rightanswer, responsesummary)
     *
     * Requires setting block_catquizstatistics/enableqejoin = 1.
     *
     * @param int $adaptiveattemptid adaptivequiz_attempt.id value.
     * @return array Ordered array of step objects.
     */
    public function get_question_steps_for_attempt(int $adaptiveattemptid): array {
        // TODO Phase 2 (Modules c / e).
        return [];
    }

    // ── Internal JSON parsing ──────────────────────────────────────────────

    /**
     * Defensively decode attempts.json into an object.
     *
     * Returns null (and emits a developer debug notice) on any parse failure
     * so callers can handle missing data gracefully.
     *
     * @param string|null $json Raw JSON string from local_catquiz_attempts.json.
     * @return object|null Decoded object or null.
     */
    private function parse_attempt_json(?string $json): ?object {
        if (empty($json)) {
            return null;
        }
        $decoded = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
            debugging(
                'block_catquizstatistics: attempt json parse error: ' . json_last_error_msg(),
                DEBUG_DEVELOPER
            );
            return null;
        }
        return $decoded;
    }

    /**
     * Extract graphicalsummary_data from attempts.json.
     *
     * graphicalsummary_data is always present in attempts.json when the
     * graphicalsummary feedbackgenerator ran for that strategy.  It is NOT
     * gated by the store_debug_info setting (that only controls debug_info col).
     *
     * Each entry contains: id, questionname, lastresponse (fraction), difficulty,
     * questionscale, questionscale_name, fisherinformation, personability_after.
     *
     * @param object|null $jsondata Decoded attempts.json object.
     * @return array Array of step objects; empty when not present.
     */
    private function extract_graphicalsummary(?object $jsondata): array {
        if ($jsondata === null || !isset($jsondata->graphicalsummary_data)) {
            return [];
        }
        if (!is_array($jsondata->graphicalsummary_data)) {
            return [];
        }
        $steps = [];
        foreach ($jsondata->graphicalsummary_data as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            // Normalise defensively: every key is optional.
            $steps[] = (object) [
                'id'                => $entry->id ?? null,
                'questionname'      => $entry->questionname ?? '',
                'lastresponse'      => isset($entry->lastresponse) ? (float) $entry->lastresponse : null,
                'difficulty'        => isset($entry->difficulty)   ? (float) $entry->difficulty   : null,
                'questionscale'     => $entry->questionscale ?? null,
                'questionscale_name' => $entry->questionscale_name ?? '',
                'fisherinformation' => isset($entry->fisherinformation)
                    ? (float) $entry->fisherinformation : null,
                'personability_after' => isset($entry->personability_after)
                    ? (float) $entry->personability_after : null,
            ];
        }
        return $steps;
    }
}
