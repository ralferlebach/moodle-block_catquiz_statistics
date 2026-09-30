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

use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\import\survey\feedback_source;
use block_catquiz_statistics\import\survey\questionnaire_source;
use block_catquiz_statistics\import\survey\survey_source_interface;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Imports Moodle survey activities into the normalised observation store (Issue #3).
 *
 * Mapping: survey instance -> dataset, question/item -> register variable,
 * response -> observations. Moodle userids are the canonical identity, so no
 * external identity resolution is involved. Anonymous instances are refused.
 *
 * Repeated imports of the same instance and timepoint: identical responses
 * return the existing dataset; changed responses create a new version that
 * supersedes the previous one. Per person the latest complete submission counts.
 *
 * Options: timepoint (string), name (string), variablemap (itemkey => existing variableid),
 * items (itemkey[] to import; default all).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class survey_importer {
    /**
     * Constructor.
     *
     * @param dataset_repository $datasets Datasets.
     * @param observation_repository $observations Observations.
     */
    public function __construct(
        /** @var dataset_repository Datasets. */
        private readonly dataset_repository $datasets = new dataset_repository(),
        /** @var observation_repository Observations. */
        private readonly observation_repository $observations = new observation_repository(),
    ) {
    }

    /**
     * Available survey sources (installed plugins only).
     *
     * @return survey_source_interface[] keyed by source key
     */
    public static function get_sources(): array {
        $result = [];
        foreach ([new questionnaire_source(), new feedback_source()] as $source) {
            if ($source->is_available()) {
                $result[$source->get_key()] = $source;
            }
        }
        return $result;
    }

    /**
     * Import one survey instance.
     *
     * @param survey_source_interface $source Source.
     * @param int $cmid Course module id.
     * @param array $options Options (see class docblock).
     * @return array{datasetid:int, identical:bool, version:int, responses:int, observed:int, missing:int, invalid:int}
     * @throws \moodle_exception For anonymous instances.
     */
    public function import(survey_source_interface $source, int $cmid, array $options = []): array {
        global $DB;

        if ($source->is_anonymous($cmid)) {
            throw new \moodle_exception('import:error:anonymous', 'block_catquiz_statistics');
        }
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $contextid = (int) \context_course::instance($cm->course)->id;
        $modcontextid = (int) \context_module::instance($cmid)->id;
        $timepoint = $options['timepoint'] ?? null;
        if ($timepoint !== null && !preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $timepoint)) {
            throw new \coding_exception('Invalid timepoint label');
        }

        $items = $source->get_items($cmid);
        if (isset($options['items'])) {
            $items = array_intersect_key($items, array_flip($options['items']));
        }
        $latest = [];
        foreach ($source->get_responses($cmid) as $response) {
            $latest[$response['userid']] = $response;
        }
        ksort($latest);

        $sourceref = $source->get_key() . ':' . $cmid . ':' . ($timepoint ?? '');
        $hash = sha1($contextid . '|' . $sourceref . '|' . json_encode([array_keys($items), $options['variablemap'] ?? [],
            array_map(static fn($r) => [$r['userid'], $r['submitted'], $r['values']], $latest)]));
        $identical = $this->datasets->find_by_importhash($contextid, $hash);
        if ($identical) {
            return ['datasetid' => (int) $identical->id, 'identical' => true, 'version' => (int) $identical->version,
                'responses' => 0, 'observed' => 0, 'missing' => 0, 'invalid' => 0];
        }
        $previous = $this->latest_dataset($contextid, $source->get_component(), $sourceref);

        $counts = ['observed' => 0, 'missing' => 0, 'invalid' => 0];
        $transaction = $DB->start_delegated_transaction();
        $datasetid = $this->datasets->create_dataset(
            $contextid,
            $options['name'] ?? ($cm->name . ($timepoint ? " ($timepoint)" : '')),
            $source->get_key(),
            [
                'sourcecomponent' => $source->get_component(),
                'sourceref' => $sourceref,
                'versionof' => $previous ? (int) $previous->id : null,
                'matchfield' => 'userid',
                'importhash' => $hash,
                'provenance' => ['cmid' => $cmid, 'timepoint' => $timepoint, 'items' => count($items)],
            ]
        );

        $variables = [];
        foreach ($items as $key => $item) {
            $variables[$key] = (int) ($options['variablemap'][$key] ?? $this->datasets->ensure_variable(
                $contextid,
                csv_importer::shortname_for($source->get_key() . $cmid . '_' . $item['name']),
                $item['label'],
                $item['datatype'],
                $item['measurementlevel'],
                ['allowedvalues' => $item['allowedvalues'], 'missingcodes' => $item['missingcodes'] ?: null,
                    'source' => $source->get_component()]
            ));
        }

        foreach ($latest as $userid => $response) {
            foreach ($items as $key => $item) {
                $parsed = value_parser::parse(
                    (string) ($response['values'][$key] ?? ''),
                    $item['datatype'],
                    $item['allowedvalues'],
                    $item['missingcodes']
                );
                $status = $parsed['status'];
                $counts[$status->has_value() ? 'observed' : ($status->is_missing() ? 'missing' : 'invalid')]++;
                $this->observations->upsert(new observation(
                    userid: (int) $userid,
                    variablekey: 'var:' . $variables[$key],
                    sourcecomponent: $source->get_component(),
                    sourcearea: 'response',
                    sourcekey: 'ds:' . $datasetid . ':u' . $userid . ':v' . $variables[$key] . ':tp' . ($timepoint ?? ''),
                    origincontextid: $modcontextid,
                    valuetype: csv_importer::value_type_for($item['datatype']),
                    status: $status,
                    origincourseid: (int) $cm->course,
                    sourceitemid: $response['responseid'],
                    occurredat: $response['submitted'] ?: null,
                    timepoint: $timepoint,
                    valuenumeric: $parsed['numeric'],
                    valuetext: $parsed['text'],
                    valuebool: $parsed['bool'],
                    datasetid: $datasetid,
                    variableid: $variables[$key],
                    provenance: ['responseid' => $response['responseid'], 'item' => $key],
                ));
            }
        }
        $transaction->allow_commit();

        return ['datasetid' => $datasetid, 'identical' => false,
            'version' => (int) $this->datasets->get_dataset($datasetid)->version, 'responses' => count($latest)] + $counts;
    }

    /**
     * Latest, not superseded dataset imported from the same source reference.
     *
     * @param int $contextid Context.
     * @param string $component Source component.
     * @param string $sourceref Source reference.
     * @return \stdClass|null
     */
    private function latest_dataset(int $contextid, string $component, string $sourceref): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select(
            dataset_repository::TABLE_DATASET,
            'contextid = :ctx AND sourcecomponent = :comp AND sourceref = :ref
             AND NOT EXISTS (SELECT 1 FROM {' . dataset_repository::TABLE_DATASET . '} n WHERE n.versionof = {'
                . dataset_repository::TABLE_DATASET . '}.id)',
            ['ctx' => $contextid, 'comp' => $component, 'ref' => $sourceref],
            'id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }
}
