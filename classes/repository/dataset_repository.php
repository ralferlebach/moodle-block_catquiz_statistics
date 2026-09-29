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

namespace block_catquiz_statistics\repository;

use block_catquiz_statistics\analytics\identity\resolution_result;

/**
 * Persistence of datasets, the variable register and identity-resolution audits.
 *
 * Datasets and variables belong to a course or system context — never to a
 * block instance. Deleting a block instance does not touch this data.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dataset_repository {
    /** @var string Dataset table. */
    public const TABLE_DATASET = 'block_catquiz_statistics_dataset';

    /** @var string Variable table. */
    public const TABLE_VARIABLE = 'block_catquiz_statistics_variable';

    /** @var string Subject map table. */
    public const TABLE_SUBJECTMAP = 'block_catquiz_statistics_subjectmap';

    /** @var string[] Allowed data types of register variables. */
    public const DATATYPES = ['numeric', 'integer', 'boolean', 'string', 'datetime', 'categorical', 'ordinal'];

    /** @var string[] Allowed measurement levels. */
    public const LEVELS = ['nominal', 'ordinal', 'interval'];

    /**
     * Create a dataset.
     *
     * @param int $contextid Owning context (course or system).
     * @param string $name Name.
     * @param string $sourcetype Source type (csv, questionnaire, ...).
     * @param array $options Optional: description, sourcecomponent, sourceref, versionof, matchfield, provenance, issynthetic.
     * @return int Dataset id.
     */
    public function create_dataset(int $contextid, string $name, string $sourcetype, array $options = []): int {
        global $DB, $USER;

        $version = 1;
        if (!empty($options['versionof'])) {
            $version = (int) $DB->get_field(self::TABLE_DATASET, 'version', ['id' => $options['versionof']], MUST_EXIST) + 1;
        }
        $now = time();
        return (int) $DB->insert_record(self::TABLE_DATASET, (object) [
            'contextid' => $contextid,
            'name' => $name,
            'description' => $options['description'] ?? null,
            'sourcetype' => $sourcetype,
            'sourcecomponent' => $options['sourcecomponent'] ?? null,
            'sourceref' => $options['sourceref'] ?? null,
            'version' => $version,
            'versionof' => $options['versionof'] ?? null,
            'matchfield' => $options['matchfield'] ?? null,
            'provenance' => isset($options['provenance']) ? json_encode($options['provenance']) : null,
            'issynthetic' => (int) !empty($options['issynthetic']),
            'usermodified' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Fetch a dataset.
     *
     * @param int $datasetid Dataset id.
     * @return \stdClass|null
     */
    public function get_dataset(int $datasetid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE_DATASET, ['id' => $datasetid]) ?: null;
    }

    /**
     * Delete a dataset with all its own observations and identity audits.
     *
     * Register variables are kept: they may be used by other datasets/models.
     *
     * @param int $datasetid Dataset id.
     */
    public function delete_dataset(int $datasetid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        (new observation_repository())->delete_for_dataset($datasetid);
        $DB->delete_records(self::TABLE_SUBJECTMAP, ['datasetid' => $datasetid]);
        $DB->set_field(self::TABLE_DATASET, 'versionof', null, ['versionof' => $datasetid]);
        $DB->delete_records(self::TABLE_DATASET, ['id' => $datasetid]);
        $transaction->allow_commit();
    }

    /**
     * Create a register variable, or return the id of the existing one with this shortname.
     *
     * @param int $contextid Scope context.
     * @param string $shortname Shortname (unique within the scope).
     * @param string $label Label.
     * @param string $datatype One of self::DATATYPES.
     * @param string $measurementlevel One of self::LEVELS.
     * @param array $options Optional: description, allowedvalues, missingcodes, unit, reversecoded, source, issynthetic.
     * @return int Variable id.
     * @throws \coding_exception On invalid type or level.
     */
    public function ensure_variable(
        int $contextid,
        string $shortname,
        string $label,
        string $datatype,
        string $measurementlevel,
        array $options = []
    ): int {
        global $DB, $USER;

        if (!in_array($datatype, self::DATATYPES, true) || !in_array($measurementlevel, self::LEVELS, true)) {
            throw new \coding_exception("Invalid datatype/measurement level: $datatype/$measurementlevel");
        }
        $existing = $DB->get_field(self::TABLE_VARIABLE, 'id', ['contextid' => $contextid, 'shortname' => $shortname]);
        if ($existing) {
            return (int) $existing;
        }
        $now = time();
        return (int) $DB->insert_record(self::TABLE_VARIABLE, (object) [
            'contextid' => $contextid,
            'shortname' => $shortname,
            'label' => $label,
            'description' => $options['description'] ?? null,
            'datatype' => $datatype,
            'measurementlevel' => $measurementlevel,
            'allowedvalues' => isset($options['allowedvalues']) ? json_encode($options['allowedvalues']) : null,
            'missingcodes' => isset($options['missingcodes']) ? json_encode($options['missingcodes']) : null,
            'unit' => $options['unit'] ?? null,
            'reversecoded' => (int) !empty($options['reversecoded']),
            'source' => $options['source'] ?? null,
            'issynthetic' => (int) !empty($options['issynthetic']),
            'usermodified' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Fetch register variables by id.
     *
     * @param int[] $ids Variable ids.
     * @return \stdClass[] Keyed by id.
     */
    public function get_variables(array $ids): array {
        global $DB;
        if (empty($ids)) {
            return [];
        }
        return $DB->get_records_list(self::TABLE_VARIABLE, 'id', array_values(array_unique($ids)));
    }

    /**
     * Persist an identity-resolution result as auditable subject map.
     *
     * @param int $datasetid Dataset id.
     * @param resolution_result $result Resolution result.
     */
    public function store_resolution(int $datasetid, resolution_result $result): void {
        global $DB;

        $now = time();
        $rows = [];
        foreach ($result->matched as $ext => $userid) {
            $rows[] = ['externalid' => (string) $ext, 'userid' => $userid, 'status' => 'matched', 'candidates' => 1];
        }
        foreach ($result->unmatched as $ext) {
            $rows[] = ['externalid' => (string) $ext, 'userid' => null, 'status' => 'unmatched', 'candidates' => 0];
        }
        foreach ($result->ambiguous as $ext => $count) {
            $rows[] = ['externalid' => (string) $ext, 'userid' => null, 'status' => 'ambiguous', 'candidates' => $count];
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE_SUBJECTMAP, ['datasetid' => $datasetid]);
        foreach ($rows as $row) {
            $row['datasetid'] = $datasetid;
            $row['matchfield'] = $result->matchfield;
            $row['timecreated'] = $now;
            $DB->insert_record(self::TABLE_SUBJECTMAP, (object) $row);
        }
        $transaction->allow_commit();
    }

    /**
     * Subject-map rows of a dataset, optionally filtered by status.
     *
     * @param int $datasetid Dataset id.
     * @param string|null $status matched | unmatched | ambiguous.
     * @return \stdClass[]
     */
    public function get_subjectmap(int $datasetid, ?string $status = null): array {
        global $DB;
        $params = ['datasetid' => $datasetid];
        if ($status !== null) {
            $params['status'] = $status;
        }
        return $DB->get_records(self::TABLE_SUBJECTMAP, $params, 'externalid');
    }
}
