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

namespace block_catquiz_statistics\import;

use block_catquiz_statistics\analytics\identity\resolution_result;
use block_catquiz_statistics\analytics\identity\subject_resolver;
use block_catquiz_statistics\analytics\identity\subject_resolver_interface;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Wide-format CSV import into normalised observations (Issue #3).
 *
 * The import never decides the analytic role of a variable. It resolves
 * identities (preview first, no silent multiple matches), normalises each
 * cell into an observation (userid, dataset, variable, timepoint, value) and
 * is idempotent: an identical re-import returns the existing dataset.
 *
 * Plan (all keys optional unless stated):
 *   name            string  dataset name (required)
 *   idcolumn        string  column with the external identifier (required)
 *   matchfield      string  userid | idnumber | username | profile_field_<x> (required)
 *   timepoint       string  measurement occasion label, e.g. T0
 *   measuredat      int     fixed measurement time
 *   measuredatcolumn string column with a per-row date (overrides measuredat)
 *   missingcodes    string[] global missing codes, e.g. ['-99', 'NA']
 *   versionof       int     dataset this import supersedes (new version / correction)
 *   skipambiguous   bool    import although ambiguous identities exist (they are skipped); default false
 *   columns         array   column => ['action' => create|map|skip, 'variableid' => int (map),
 *                                      'shortname', 'label', 'datatype', 'measurementlevel',
 *                                      'allowedvalues' => array, 'missingcodes' => string[]]
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_importer {
    /** @var string Source component of imported observations. */
    public const COMPONENT = 'block_catquiz_statistics';

    /**
     * Constructor.
     *
     * @param dataset_repository $datasets Dataset/variable repository.
     * @param observation_repository $observations Observation repository.
     * @param subject_resolver_interface $resolver Identity resolver.
     */
    public function __construct(
        /** @var dataset_repository Datasets. */
        private readonly dataset_repository $datasets = new dataset_repository(),
        /** @var observation_repository Observations. */
        private readonly observation_repository $observations = new observation_repository(),
        /** @var subject_resolver_interface Resolver. */
        private readonly subject_resolver_interface $resolver = new subject_resolver(),
    ) {
    }

    /**
     * Preview: identity resolution, variable suggestions, duplicates and identical-import check.
     *
     * @param int $contextid Target context (course or system).
     * @param csv_table $table Parsed CSV.
     * @param array $plan Import plan.
     * @return array
     */
    public function preview(int $contextid, csv_table $table, array $plan): array {
        $plan = $this->validate_plan($table, $plan);
        $resolution = $this->resolve($table, $plan);
        $variables = $this->describe_columns($contextid, $table, $plan);
        $identical = $this->datasets->find_by_importhash($contextid, $this->importhash($contextid, $table, $plan));

        return [
            'rows' => count($table->rows),
            'resolution' => $resolution->get_counts(),
            'unmatched' => $resolution->unmatched,
            'ambiguous' => array_keys($resolution->ambiguous),
            'duplicaterows' => $this->count_duplicate_rows($table, $plan, $resolution),
            'variables' => $variables,
            'identicaldatasetid' => $identical ? (int) $identical->id : null,
            'canimport' => empty($resolution->ambiguous) || !empty($plan['skipambiguous']),
        ];
    }

    /**
     * Run the import.
     *
     * @param int $contextid Target context (course or system).
     * @param csv_table $table Parsed CSV.
     * @param array $plan Import plan.
     * @return array{datasetid:int, identical:bool, observed:int, missing:int, invalid:int,
     *               skippedrows:int, duplicaterows:int, variables:int}
     * @throws \moodle_exception When ambiguous identities exist and skipambiguous is not set.
     */
    public function import(int $contextid, csv_table $table, array $plan): array {
        global $DB;

        $plan = $this->validate_plan($table, $plan);
        $hash = $this->importhash($contextid, $table, $plan);
        $identical = $this->datasets->find_by_importhash($contextid, $hash);
        if ($identical) {
            return ['datasetid' => (int) $identical->id, 'identical' => true, 'observed' => 0, 'missing' => 0,
                'invalid' => 0, 'skippedrows' => 0, 'duplicaterows' => 0, 'variables' => 0];
        }

        $resolution = $this->resolve($table, $plan);
        if (!empty($resolution->ambiguous) && empty($plan['skipambiguous'])) {
            throw new \moodle_exception('import:error:ambiguous', 'block_catquiz_statistics', '', count($resolution->ambiguous));
        }

        $context = \context::instance_by_id($contextid);
        $courseid = $context->contextlevel == CONTEXT_COURSE ? (int) $context->instanceid : null;
        $counts = ['observed' => 0, 'missing' => 0, 'invalid' => 0, 'skippedrows' => 0, 'duplicaterows' => 0];

        $transaction = $DB->start_delegated_transaction();
        $datasetid = $this->datasets->create_dataset($contextid, $plan['name'], 'csv', [
            'sourceref' => $plan['sourceref'] ?? null,
            'versionof' => $plan['versionof'] ?? null,
            'matchfield' => $plan['matchfield'],
            'importhash' => $hash,
            'provenance' => [
                'delimiter' => $table->delimiter,
                'encoding' => $table->encoding,
                'timepoint' => $plan['timepoint'] ?? null,
                'missingcodes' => $plan['missingcodes'],
                'fingerprint' => $table->fingerprint(),
            ],
        ]);
        $this->datasets->store_resolution($datasetid, $resolution);

        $variables = [];
        foreach ($this->describe_columns($contextid, $table, $plan) as $column => $var) {
            if ($var['action'] === 'skip') {
                continue;
            }
            $variables[$column] = $var + ['id' => $var['variableid'] ?? $this->datasets->ensure_variable(
                $contextid,
                $var['shortname'],
                $var['label'],
                $var['datatype'],
                $var['measurementlevel'],
                ['allowedvalues' => $var['allowedvalues'], 'missingcodes' => $var['missingcodes'] ?: null, 'source' => 'csv']
            )];
        }

        $seen = [];
        foreach ($table->rows as $index => $row) {
            $userid = $resolution->get_userid((string) $row[$plan['idcolumn']]);
            if ($userid === null) {
                $counts['skippedrows']++;
                continue;
            }
            $time = $this->row_time($row, $plan);
            $rowkey = $userid . '|' . ($plan['timepoint'] ?? '') . '|' . ($time ?? 0);
            if (isset($seen[$rowkey])) {
                $counts['duplicaterows']++;
                continue;
            }
            $seen[$rowkey] = true;

            foreach ($variables as $column => $var) {
                $allowed = $var['allowedvalues'];
                $missing = array_merge($plan['missingcodes'], $var['missingcodes']);
                $parsed = value_parser::parse((string) $row[$column], $var['datatype'], $allowed, $missing);
                $status = $parsed['status'];
                $counts[$status->has_value() ? 'observed' : ($status->is_missing() ? 'missing' : 'invalid')]++;
                $this->observations->upsert(new observation(
                    userid: $userid,
                    variablekey: 'var:' . $var['id'],
                    sourcecomponent: self::COMPONENT,
                    sourcearea: 'import:csv',
                    sourcekey: 'ds:' . $datasetid . ':u' . $userid . ':v' . $var['id'] . ':tp' . ($plan['timepoint'] ?? '')
                        . ':t' . ($time ?? 0),
                    origincontextid: $contextid,
                    valuetype: self::value_type_for($var['datatype']),
                    status: $status,
                    origincourseid: $courseid,
                    sourceitemid: $index + 2,
                    occurredat: $time,
                    timepoint: $plan['timepoint'] ?? null,
                    valuenumeric: $parsed['numeric'],
                    valuetext: $parsed['text'],
                    valuebool: $parsed['bool'],
                    datasetid: $datasetid,
                    variableid: $var['id'],
                    provenance: ['row' => $index + 2],
                ));
            }
        }
        $transaction->allow_commit();

        return ['datasetid' => $datasetid, 'identical' => false, 'variables' => count($variables)] + $counts;
    }

    /**
     * Original rows that could not be resolved (unmatched or ambiguous), as CSV for correction.
     *
     * @param csv_table $table Parsed CSV.
     * @param array $plan Import plan.
     * @return string CSV with an additional column 'resolution'.
     */
    public function unresolved_csv(csv_table $table, array $plan): string {
        $plan = $this->validate_plan($table, $plan);
        $resolution = $this->resolve($table, $plan);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_merge($table->header, ['resolution']), ',', '"', '');
        foreach ($table->rows as $row) {
            $key = subject_resolver::normalise((string) $row[$plan['idcolumn']]);
            $status = null;
            if (isset($resolution->ambiguous[$key])) {
                $status = 'ambiguous';
            } else if ($resolution->get_userid($key) === null) {
                $status = 'unmatched';
            }
            if ($status !== null) {
                fputcsv($handle, array_merge(array_values($row), [$status]), ',', '"', '');
            }
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Observation value type for a register datatype.
     *
     * @param string $datatype Datatype.
     * @return value_type
     */
    public static function value_type_for(string $datatype): value_type {
        return match ($datatype) {
            'numeric' => value_type::NUMERIC,
            'integer' => value_type::INTEGER,
            'boolean' => value_type::BOOLEAN,
            'datetime' => value_type::DATETIME,
            'categorical' => value_type::CATEGORICAL,
            'ordinal' => value_type::ORDINAL,
            default => value_type::TEXT,
        };
    }

    /**
     * Validate and complete a plan.
     *
     * @param csv_table $table Parsed CSV.
     * @param array $plan Plan.
     * @return array Completed plan.
     * @throws \coding_exception On missing or invalid settings.
     */
    private function validate_plan(csv_table $table, array $plan): array {
        foreach (['name', 'idcolumn', 'matchfield'] as $required) {
            if (empty($plan[$required])) {
                throw new \coding_exception('Import plan misses ' . $required);
            }
        }
        if (!in_array($plan['idcolumn'], $table->header, true)) {
            throw new \coding_exception('Unknown id column: ' . $plan['idcolumn']);
        }
        if (!empty($plan['measuredatcolumn']) && !in_array($plan['measuredatcolumn'], $table->header, true)) {
            throw new \coding_exception('Unknown measuredat column: ' . $plan['measuredatcolumn']);
        }
        if (isset($plan['timepoint']) && !preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $plan['timepoint'])) {
            throw new \coding_exception('Invalid timepoint label');
        }
        $plan['missingcodes'] = array_values(array_map('strval', $plan['missingcodes'] ?? []));
        $plan['columns'] = $plan['columns'] ?? [];
        return $plan;
    }

    /**
     * Resolve the identities of all rows.
     *
     * @param csv_table $table Parsed CSV.
     * @param array $plan Validated plan.
     * @return resolution_result
     */
    private function resolve(csv_table $table, array $plan): resolution_result {
        return $this->resolver->resolve(array_column($table->rows, $plan['idcolumn']), $plan['matchfield']);
    }

    /**
     * Describe each data column: explicit plan settings merged over inferred suggestions.
     *
     * @param int $contextid Context.
     * @param csv_table $table Parsed CSV.
     * @param array $plan Validated plan.
     * @return array column => variable description
     * @throws \coding_exception On invalid column settings.
     */
    private function describe_columns(int $contextid, csv_table $table, array $plan): array {
        $exclude = array_filter([$plan['idcolumn'], $plan['measuredatcolumn'] ?? null]);
        $result = [];
        foreach ($table->header as $column) {
            if (in_array($column, $exclude, true)) {
                continue;
            }
            $cfg = $plan['columns'][$column] ?? [];
            $action = $cfg['action'] ?? 'create';
            if (!in_array($action, ['create', 'map', 'skip'], true)) {
                throw new \coding_exception('Invalid column action: ' . $action);
            }
            $colmissing = array_values(array_map('strval', $cfg['missingcodes'] ?? []));
            if ($action === 'map') {
                $existing = $this->datasets->get_variables([(int) ($cfg['variableid'] ?? 0)]);
                if (!$existing) {
                    throw new \coding_exception('Unknown variable to map for column ' . $column);
                }
                $v = reset($existing);
                $result[$column] = [
                    'action' => 'map', 'variableid' => (int) $v->id, 'shortname' => $v->shortname, 'label' => $v->label,
                    'datatype' => $v->datatype, 'measurementlevel' => $v->measurementlevel,
                    'allowedvalues' => $v->allowedvalues ? json_decode($v->allowedvalues, true) : null,
                    'missingcodes' => array_merge($colmissing, $v->missingcodes ? json_decode($v->missingcodes, true) : []),
                ];
                continue;
            }
            $inferred = value_parser::infer(
                array_column($table->rows, $column),
                array_merge($plan['missingcodes'], $colmissing)
            );
            $shortname = $cfg['shortname'] ?? self::shortname_for($column);
            $result[$column] = [
                'action' => $action,
                'variableid' => null,
                'shortname' => $shortname,
                'label' => $cfg['label'] ?? $column,
                'datatype' => $cfg['datatype'] ?? $inferred['datatype'],
                'measurementlevel' => $cfg['measurementlevel'] ?? $inferred['measurementlevel'],
                'allowedvalues' => array_key_exists('allowedvalues', $cfg) ? $cfg['allowedvalues'] : $inferred['allowedvalues'],
                'missingcodes' => $colmissing,
                'inferred' => $inferred,
            ];
        }
        return $result;
    }

    /**
     * Number of rows that repeat an already seen person/timepoint/time combination.
     *
     * @param csv_table $table Parsed CSV.
     * @param array $plan Validated plan.
     * @param resolution_result $resolution Resolution.
     * @return int
     */
    private function count_duplicate_rows(csv_table $table, array $plan, resolution_result $resolution): int {
        $seen = [];
        $dups = 0;
        foreach ($table->rows as $row) {
            $userid = $resolution->get_userid((string) $row[$plan['idcolumn']]);
            if ($userid === null) {
                continue;
            }
            $key = $userid . '|' . ($this->row_time($row, $plan) ?? 0);
            $dups += isset($seen[$key]) ? 1 : 0;
            $seen[$key] = true;
        }
        return $dups;
    }

    /**
     * Measurement time of a row.
     *
     * @param array $row Row.
     * @param array $plan Validated plan.
     * @return int|null
     */
    private function row_time(array $row, array $plan): ?int {
        if (!empty($plan['measuredatcolumn'])) {
            $ts = strtotime(trim((string) $row[$plan['measuredatcolumn']]));
            return $ts === false ? null : $ts;
        }
        return isset($plan['measuredat']) ? (int) $plan['measuredat'] : null;
    }

    /**
     * Import identity: context, content fingerprint and all import settings except the dataset name.
     *
     * @param int $contextid Context.
     * @param csv_table $table Parsed CSV.
     * @param array $plan Validated plan.
     * @return string
     */
    private function importhash(int $contextid, csv_table $table, array $plan): string {
        $settings = $plan;
        unset($settings['name'], $settings['skipambiguous']);
        ksort($settings);
        return sha1($contextid . '|' . $table->fingerprint() . '|' . json_encode($settings));
    }

    /**
     * Derive a register shortname from a column header.
     *
     * @param string $column Column header.
     * @return string
     */
    public static function shortname_for(string $column): string {
        $short = strtolower(trim(preg_replace('/[^A-Za-z0-9_]+/', '_', $column), '_'));
        return \core_text::substr($short === '' ? 'var' : $short, 0, 100);
    }
}
