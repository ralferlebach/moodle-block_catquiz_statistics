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
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\repository;

use block_catquiz_statistics\dto\attempt_data;
use block_catquiz_statistics\local\statistics_helper;
use block_catquiz_statistics\local\se_validator;

/**
 * Repository for CAT quiz attempt data.
 */
class attempt_repository {
    /**
     * Check that all tables and columns this plugin depends on actually exist.
     *
     * Call once per page load; return false to show a graceful degradation
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
            'adaptivequiz',
        ];
        foreach ($required as $table) {
            if (!$dbman->table_exists(new \xmldb_table($table))) {
                return false;
            }
        }

        $attempttable = new \xmldb_table('local_catquiz_attempts');
        foreach (['json', 'debug_info', 'instanceid', 'contextid', 'scaleid'] as $col) {
            if (!$dbman->field_exists($attempttable, new \xmldb_field($col))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return all mod_adaptivequiz instances that use catquiz in a course.
     *
     * Uses a LEFT JOIN against {adaptivequiz} to fetch the human-readable
     * activity name as set by the teacher (not the CAT template name from
     * local_catquiz_tests, which is generic, e.g. "Benutzerdefinierter Test").
     *
     * LEFT JOIN is intentional: attempts whose adaptivequiz activity has been
     * deleted since the attempt was recorded remain visible with empty testname
     * so educators are aware they exist.  An INNER JOIN would silently drop them.
     *
     * @param int $courseid Course ID.
     * @return array Array of stdClass with fields: instanceid, catscaleid, testname, catscalename, attemptcount.
     */
    public function get_catquiz_instances_for_course(int $courseid): array {
        global $DB;

        if (!$this->check_schema_compatibility()) {
            return [];
        }

        $sql = 'SELECT a.instanceid, a.scaleid,'
             . '       COUNT(a.id) AS attemptcount,'
             . '       aq.name AS testname,'
             . '       cs.name AS catscalename'
             . '  FROM {local_catquiz_attempts} a'
             . '  LEFT JOIN {adaptivequiz} aq ON aq.id = a.instanceid'
             . '  LEFT JOIN {local_catquiz_catscales} cs ON cs.id = a.scaleid'
             . ' WHERE a.courseid = :courseid'
             . ' GROUP BY a.instanceid, a.scaleid, aq.name, cs.name'
             . ' ORDER BY aq.name ASC, a.instanceid ASC';

        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = (object) [
                'instanceid' => (int) $row->instanceid,
                'catscaleid' => (int) $row->scaleid,
                'testname' => $row->testname ?? '',
                'catscalename' => $row->catscalename ?? '',
                'attemptcount' => (int) $row->attemptcount,
            ];
        }
        return $result;
    }

    /**
     * Return attempt rows matching the filter, hydrated as attempt_data DTOs.
     *
     * Join path: local_catquiz_attempts + {user}.
     * Quiz settings (SE thresholds) are loaded separately per unique instanceid
     * from local_catquiz_tests to keep the main SQL simple.
     * SE validity is applied via se_validator::validate().
     *
     * @param attempt_filter $filter Query scope.
     * @return attempt_data[] Hydrated DTOs ordered by starttime DESC.
     */
    public function get_attempts(attempt_filter $filter): array {
        global $DB;

        if (!$this->check_schema_compatibility()) {
            return [];
        }

        $sql = 'SELECT a.id, a.userid, u.username, u.firstname, u.lastname, u.email,'
             . '       a.scaleid, a.contextid, a.courseid, a.attemptid, a.instanceid,'
             . '       a.teststrategy, a.status,'
             . '       a.total_number_of_testitems, a.number_of_testitems_used,'
             . '       a.personability_before_attempt, a.personability_after_attempt,'
             . '       a.starttime, a.endtime, a.json'
             . '  FROM {local_catquiz_attempts} a'
             . '  JOIN {user} u ON u.id = a.userid'
             . ' WHERE 1=1';

        $params = [];

        if (!$filter->systemwide) {
            $sql .= ' AND a.courseid = :courseid';
            $params['courseid'] = $filter->courseid;
        }
        if ($filter->instanceid !== null) {
            $sql .= ' AND a.instanceid = :instanceid';
            $params['instanceid'] = $filter->instanceid;
        }
        if ($filter->scaleid !== null) {
            $sql .= ' AND a.scaleid = :scaleid';
            $params['scaleid'] = $filter->scaleid;
        }
        if ($filter->starttime !== null) {
            $sql .= ' AND a.starttime >= :starttime';
            $params['starttime'] = $filter->starttime;
        }
        if ($filter->endtime !== null) {
            $sql .= ' AND a.starttime <= :endtime';
            $params['endtime'] = $filter->endtime;
        }

        $sql .= ' ORDER BY a.starttime DESC, a.id DESC';

        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return [];
        }

        // Load quiz settings once per unique instanceid.
        $instanceids = array_unique(
            array_map(static fn($r) => (int) $r->instanceid, array_values($records))
        );
        $quizsettings = $this->load_quizsettings($instanceids);

        $dtos = [];
        foreach ($records as $record) {
            $dtos[] = $this->hydrate_attempt(
                $record,
                $quizsettings[(int) $record->instanceid] ?? null
            );
        }
        return $dtos;
    }

    /**
     * Return a single attempt including full JSON and graphicalsummary parsing.
     *
     * @param int $attemptid local_catquiz_attempts.id (not adaptivequiz_attempt.id).
     * @return attempt_data|null Null when the record does not exist.
     */
    public function get_attempt_with_detail(int $attemptid): ?attempt_data {
        global $DB;

        if (!$this->check_schema_compatibility()) {
            return null;
        }

        $sql = 'SELECT a.id, a.userid, u.username, u.firstname, u.lastname, u.email,'
             . '       a.scaleid, a.contextid, a.courseid, a.attemptid, a.instanceid,'
             . '       a.teststrategy, a.status,'
             . '       a.total_number_of_testitems, a.number_of_testitems_used,'
             . '       a.personability_before_attempt, a.personability_after_attempt,'
             . '       a.starttime, a.endtime, a.json'
             . '  FROM {local_catquiz_attempts} a'
             . '  JOIN {user} u ON u.id = a.userid'
             . ' WHERE a.id = :id';

        $record = $DB->get_record_sql($sql, ['id' => $attemptid]);
        if (!$record) {
            return null;
        }

        $quizsettings = $this->load_quizsettings([(int) $record->instanceid]);
        return $this->hydrate_attempt(
            $record,
            $quizsettings[(int) $record->instanceid] ?? null
        );
    }

    /**
     * Return person-parameter rows (ability, SE per scale) for a filter scope.
     *
     * Stub — not yet implemented.
     *
     * @param attempt_filter $filter Query scope.
     * @return array Array of stdClass with fields: userid, catscaleid, ability, standarderror.
     */
    public function get_personparams(attempt_filter $filter): array {
        return [];
    }

    /**
     * Return question-engine step data for a single adaptivequiz_attempt.
     *
     * Join path:
     *   adaptivequiz_attempt.uniqueid
     *   -> question_attempts.questionusageid
     *   -> question_attempt_steps.questionattemptid   (fraction, timecreated)
     *   -> question_attempt_step_data.attemptstepid   (name, value)
     *
     * Requires setting block_catquiz_statistics/enableqejoin = 1.
     *
     * Stub — not yet implemented.
     *
     * @param int $adaptiveattemptid adaptivequiz_attempt.id value.
     * @return array Ordered array of step objects.
     */
    public function get_question_steps_for_attempt(int $adaptiveattemptid): array {
        return [];
    }

    /**
     * Load quiz settings (json column) from local_catquiz_tests for given instance IDs.
     *
     * Returns the most recent active test record per instanceid.
     * ORDER BY id DESC ensures the most recently inserted record is processed
     * first; the first match per instanceid wins.
     *
     * @param int[] $instanceids mod_adaptivequiz instance IDs to query.
     * @return array<int,object> Decoded settings objects keyed by instanceid.
     */
    private function load_quizsettings(array $instanceids): array {
        global $DB;

        if (empty($instanceids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED, 'inst');
        $inparams['component'] = 'mod_adaptivequiz';
        $inparams['tstatus']   = 1;

        $sql = 'SELECT id, componentid, json'
             . '  FROM {local_catquiz_tests}'
             . ' WHERE componentid ' . $insql
             . '   AND component = :component'
             . '   AND status    = :tstatus'
             . ' ORDER BY id DESC';

        $rows     = $DB->get_records_sql($sql, $inparams);
        $settings = [];
        foreach ($rows as $row) {
            $iid = (int) $row->componentid;
            if (!isset($settings[$iid])) {
                $decoded = json_decode($row->json);
                if (is_object($decoded)) {
                    $settings[$iid] = $decoded;
                }
            }
        }
        return $settings;
    }

    /**
     * Hydrate a single DB record into a fully parsed attempt_data DTO.
     *
     * Applies SE validity filtering via se_validator using thresholds extracted
     * from the quiz settings for this attempt's instance.
     *
     * @param object $record Raw DB row from get_attempts() SQL.
     * @param object|null $quizsettings Decoded local_catquiz_tests.json for this instance.
     * @return attempt_data Hydrated DTO.
     */
    private function hydrate_attempt(object $record, ?object $quizsettings): attempt_data {
        $dto = new attempt_data();

        $dto->id        = (int) $record->id;
        $dto->userid = (int) $record->userid;
        $dto->username = $record->username ?? null;
        $dto->firstname = $record->firstname ?? null;
        $dto->lastname = $record->lastname ?? null;
        $dto->email = $record->email ?? null;

        $dto->scaleid = isset($record->scaleid) ? (int) $record->scaleid : null;
        $dto->contextid = isset($record->contextid) ? (int) $record->contextid : null;
        $dto->courseid = isset($record->courseid) ? (int) $record->courseid : null;
        $dto->attemptid = (int) $record->attemptid;
        $dto->instanceid = isset($record->instanceid) ? (int) $record->instanceid : null;

        $dto->teststrategy = isset($record->teststrategy) ? (int) $record->teststrategy : null;
        $dto->status = isset($record->status) ? (int) $record->status : null;
        $dto->totaltestitems = isset($record->total_number_of_testitems)
            ? (int) $record->total_number_of_testitems : null;
        $dto->usedtestitems = isset($record->number_of_testitems_used)
            ? (int) $record->number_of_testitems_used : null;

        $dto->personabilitybeforeattempt = isset($record->personability_before_attempt)
            ? (float) $record->personability_before_attempt : null;
        $dto->personabilityafterattempt = isset($record->personability_after_attempt)
            ? (float) $record->personability_after_attempt : null;

        $dto->starttime = isset($record->starttime) ? (int) $record->starttime : null;
        $dto->endtime = isset($record->endtime) ? (int) $record->endtime : null;
        $dto->durationseconds = ($dto->endtime && $dto->starttime)
            ? (float) ($dto->endtime - $dto->starttime) : null;

        // Parse attempts.json payload.
        $json = $this->parse_attempt_json($record->json ?? null);
        if ($json !== null) {
            $dto->globalscaleid = isset($json->catscaleid) ? (int) $json->catscaleid : null;
            $dto->testid = isset($json->testid) ? (int) $json->testid : null;
            $dto->primaryscale = $json->primaryscale ?? null;
            $dto->catscales = $this->extract_catscales($json->catscales ?? null);
            $dto->graphicalsummary = $this->extract_graphicalsummary($json);

            // Personabilities can appear under two key names depending on catquiz version.
            $rawpa = $json->personabilities ?? $json->personabilities_abilities ?? null;
            $dto->personabilities = $this->extract_float_map($rawpa);

            // SE: extract raw values then apply validity filters.
            $rawse = $this->extract_float_map($json->se ?? null);
            $thresholds = se_validator::extract_thresholds($quizsettings);
            $dto->se = se_validator::validate(
                $rawse,
                $dto->graphicalsummary,
                $thresholds['nminscale'],
                $thresholds['semax']
            );
        }

        return $dto;
    }

    /**
     * Convert a JSON object or array with numeric string keys to array<int,float>.
     *
     * JSON encodes object keys as strings (e.g. "1": 0.5); this method casts
     * them back to integers so callers can use $map[$scaleid] directly.
     *
     * @param mixed $input Decoded JSON object, array, or null.
     * @return array<int,float> Keyed by integer scale ID.
     */
    private function extract_float_map($input): array {
        if (!is_object($input) && !is_array($input)) {
            return [];
        }
        $result = [];
        foreach ((array) $input as $key => $value) {
            if (is_numeric($key) && is_numeric($value)) {
                $result[(int) $key] = (float) $value;
            }
        }
        return $result;
    }

    /**
     * Convert the catscales JSON object to array<int,object>.
     *
     * @param mixed $input Decoded JSON catscales object or null.
     * @return array<int,object> Scale metadata keyed by integer scale ID.
     */
    private function extract_catscales($input): array {
        if (!is_object($input) && !is_array($input)) {
            return [];
        }
        $result = [];
        foreach ((array) $input as $key => $scale) {
            if (is_numeric($key) && is_object($scale)) {
                $result[(int) $key] = $scale;
            }
        }
        return $result;
    }

    /**
     * Defensively decode attempts.json into an object.
     *
     * Returns null and emits a developer debug notice on any parse failure
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
                'block_catquiz_statistics: attempt json parse error: ' . json_last_error_msg(),
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
     * graphicalsummary feedbackgenerator ran for that strategy. It is NOT
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
            $steps[] = (object) [
                'id'               => $entry->id ?? null,
                'questionname'     => $entry->questionname ?? '',
                'lastresponse'     => isset($entry->lastresponse) ? (float) $entry->lastresponse : null,
                'difficulty'       => isset($entry->difficulty) ? (float) $entry->difficulty : null,
                'questionscale'    => $entry->questionscale ?? null,
                'questionscalename' => $entry->questionscale_name ?? '',
                'fisherinformation' => isset($entry->fisherinformation)
                    ? (float) $entry->fisherinformation : null,
                'personabilityafter' => isset($entry->personability_after)
                    ? (float) $entry->personability_after : null,
            ];
        }
        return $steps;
    }
    /**
     * Return all catscale metadata (id, name, label, parentid) from the DB.
     *
     * Used for hierarchical scale sorting and label-based column headers.
     * Returns an empty array when local_catquiz_catscales is not available.
     *
     * @return array<int,array> Map of scale id to ['name', 'label', 'parentid'].
     */
    public function get_all_catscale_meta(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_catquiz_catscales')) {
            return [];
        }

        $records = $DB->get_records('local_catquiz_catscales', null, 'id ASC', 'id, name, label, parentid');
        $meta = [];
        foreach ($records as $r) {
            $meta[(int) $r->id] = [
                'name' => $r->name ?? '',
                'label' => $r->label ?? '',
                'parentid' => (int) ($r->parentid ?? 0),
            ];
        }
        return $meta;
    }

    /**
     * Return item statistics per scale: total items, productive items, and
     * descriptive statistics (min/max/mean/SD) of active-item difficulties.
     *
     * "Productive" means activeparamid IS NOT NULL and a matching row exists
     * in local_catquiz_itemparams (i.e. the item has calibrated IRT parameters).
     *
     * @param int[] $scaleids Scale IDs to query.
     * @return array<int,array> Map of scale id to stat arrays.
     *   Keys: total, productive, diff_min, diff_max, diff_mean, diff_sd.
     *   All difficulty keys are null when no productive items exist.
     */
    public function get_item_stats_by_scale(array $scaleids): array {
        global $DB;

        if (empty($scaleids)) {
            return [];
        }
        if (
            !$DB->get_manager()->table_exists('local_catquiz_items')
            || !$DB->get_manager()->table_exists('local_catquiz_itemparams')
        ) {
            $empty = ['total' => 0, 'productive' => 0,
                'diff_min' => null, 'diff_max' => null,
                'diff_mean' => null, 'diff_sd' => null];
            return array_fill_keys($scaleids, $empty);
        }

        [$insqla, $inparamsa] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED, 'tsa');
        $totalsql = "SELECT catscaleid, COUNT(*) AS cnt
                       FROM {local_catquiz_items}
                      WHERE catscaleid $insqla
                      GROUP BY catscaleid";
        $totalrows = $DB->get_records_sql($totalsql, $inparamsa);

        [$insqlb, $inparamsb] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED, 'tsb');
        $diffsql = "SELECT i.catscaleid, ip.difficulty
                      FROM {local_catquiz_items} i
                      JOIN {local_catquiz_itemparams} ip ON ip.id = i.activeparamid
                     WHERE i.catscaleid $insqlb
                       AND i.activeparamid IS NOT NULL
                       AND ip.difficulty IS NOT NULL
                     ORDER BY i.catscaleid";
        $diffrs = $DB->get_recordset_sql($diffsql, $inparamsb);

        $diffsbyscale = [];
        foreach ($diffrs as $row) {
            $diffsbyscale[(int) $row->catscaleid][] = (float) $row->difficulty;
        }
        $diffrs->close();

        $result = [];
        foreach ($scaleids as $sid) {
            $total = 0;
            foreach ($totalrows as $row) {
                if ((int) $row->catscaleid === $sid) {
                    $total = (int) $row->cnt;
                    break;
                }
            }
            $diffs = $diffsbyscale[$sid] ?? [];
            $productive = count($diffs);
            $diffstats = $productive > 0 ? statistics_helper::descriptive($diffs) : null;
            $result[$sid] = [
                'total' => $total,
                'productive' => $productive,
                'diff_min' => $diffstats ? $diffstats['min'] : null,
                'diff_max' => $diffstats ? $diffstats['max'] : null,
                'diff_mean' => $diffstats ? $diffstats['mean'] : null,
                'diff_sd' => $diffstats ? $diffstats['sd'] : null,
            ];
        }
        return $result;
    }

    /**
     * Return semax and nmin thresholds for a single adaptivequiz instance.
     *
     * Reads local_catquiz_tests.json for the instance and extracts the two
     * SE-validity thresholds used in the export metadata sheet.
     *
     * @param int $instanceid mod_adaptivequiz instance ID.
     * @return array Two-element array ['semax' => float|null, 'nmin' => int|null].
     */
    public function get_semax_nmin_for_instance(int $instanceid): array {
        $settings = $this->load_quizsettings([$instanceid]);
        $qs = $settings[$instanceid] ?? null;
        if ($qs === null) {
            return ['semax' => null, 'nmin' => null];
        }
        $semax = null;
        $raw = $qs->catquiz_standarderrorgroup->catquiz_standarderror_max ?? null;
        if ($raw !== null && is_numeric($raw)) {
            $semax = (float) $raw;
        }
        $nmin = null;
        $rawn = $qs->maxquestionsscalegroup->catquiz_minquestionspersubscale ?? null;
        if ($rawn !== null && is_numeric($rawn)) {
            $nmin = (int) $rawn;
        }
        return ['semax' => $semax, 'nmin' => $nmin];
    }


    /**
     * Return all courses that have at least one catquiz attempt.
     *
     * Used to populate the course selector on the system-wide admin report.
     *
     * @return array Array of stdClass with fields: id, fullname, shortname.
     */
    public function get_courses_with_attempts(): array {
        global $DB;

        if (!$this->check_schema_compatibility()) {
            return [];
        }

        $sql = 'SELECT DISTINCT c.id, c.fullname, c.shortname'
             . '  FROM {local_catquiz_attempts} a'
             . '  JOIN {course} c ON c.id = a.courseid'
             . ' ORDER BY c.fullname ASC';

        return array_values($DB->get_records_sql($sql));
    }

    /**
     * Return all catquiz instances across all courses (system-wide).
     *
     * Prefixes the testname with the course short name so instances from
     * different courses are distinguishable in a single dropdown.
     *
     * @return array Array of stdClass with fields: instanceid, catscaleid,
     *   testname, catscalename, attemptcount.
     */
    public function get_catquiz_instances_systemwide(): array {
        global $DB;

        if (!$this->check_schema_compatibility()) {
            return [];
        }

        $sql = 'SELECT a.instanceid, a.scaleid,'
             . '       COUNT(a.id) AS attemptcount,'
             . '       aq.name AS testname,'
             . '       cs.name AS catscalename,'
             . '       c.shortname AS courseshortname'
             . '  FROM {local_catquiz_attempts} a'
             . '  LEFT JOIN {adaptivequiz} aq ON aq.id = a.instanceid'
             . '  LEFT JOIN {local_catquiz_catscales} cs ON cs.id = a.scaleid'
             . '  JOIN {course} c ON c.id = a.courseid'
             . ' GROUP BY a.instanceid, a.scaleid, aq.name, cs.name, c.shortname'
             . ' ORDER BY c.shortname ASC, aq.name ASC, a.instanceid ASC';

        $rows = $DB->get_records_sql($sql);
        $result = [];
        foreach ($rows as $row) {
            $label = ($row->courseshortname ?? '') . ' / ' . ($row->testname ?? '');
            $result[] = (object) [
                'instanceid' => (int) $row->instanceid,
                'catscaleid' => (int) $row->scaleid,
                'testname' => trim($label, ' /'),
                'catscalename' => $row->catscalename ?? '',
                'attemptcount' => (int) $row->attemptcount,
            ];
        }
        return $result;
    }
}
