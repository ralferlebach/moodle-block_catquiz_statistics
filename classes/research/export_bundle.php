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

namespace block_catquiz_statistics\research;

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\evaluation\evaluation_service;
use block_catquiz_statistics\repository\evalmodel_repository;

/**
 * Reproducible research export bundle (Issue #8): CSV files plus codebook and manifest.
 *
 * Files: data_long.csv, data_wide.csv, codebook.csv, manifest.json. The manifest
 * references the evaluation model version (with its snapshot), the dataset
 * versions involved, population criteria, pseudonymisation mode and scope (never
 * the secret), plugin version and timestamp, and states that pseudonymised data
 * are still personal data.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_bundle {
    /**
     * Build the bundle content.
     *
     * @param int $courseid Course the evaluation runs in.
     * @param int $modelid Evaluation model.
     * @param string $mode Pseudonymisation mode (stable | export | identified).
     * @return array filename => content
     */
    public function build(int $courseid, int $modelid, string $mode = 'stable'): array {
        global $CFG, $DB;
        $service = analytics_query_service::create_default();
        $evaluation = new evaluation_service($modelid, $service);
        $population = $evaluation->get_population($courseid);
        $subjects = new pseudonymiser($mode, 'model:' . $modelid);
        $builder = new dataset_builder($modelid, $service);
        $wide = $builder->wide($population['userids'], $subjects);
        $long = $builder->long($population['userids'], $subjects);

        $widecsv = [array_merge(['subject'], array_column($wide['columns'], 'column'))];
        foreach ($wide['rows'] as $subject => $row) {
            $widecsv[] = array_merge([$subject], array_values($row));
        }
        $longcsv = $long ? [array_keys($long[0])] : [['subject']];
        foreach ($long as $row) {
            $longcsv[] = array_values($row);
        }
        $codebook = [['column', 'label', 'role', 'selector', 'occasion', 'kind', 'missing']];
        foreach ($wide['columns'] as $c) {
            $codebook[] = [$c['column'], $c['label'], $c['role'], $c['selector'], $c['occasion'], $c['kind'], $c['missing']];
        }

        $datasetids = array_values(array_unique(array_filter(array_column($long, 'dataset'))));
        $datasets = $datasetids ? array_values(array_map(static fn($d) => ['id' => (int) $d->id, 'name' => $d->name,
            'version' => (int) $d->version, 'versionof' => $d->versionof ? (int) $d->versionof : null,
            'synthetic' => (bool) $d->issynthetic], $DB->get_records_list('block_catquiz_statistics_dataset', 'id', $datasetids)))
            : [];
        $plugin = \core_plugin_manager::instance()->get_plugin_info('block_catquiz_statistics');
        $manifest = [
            'format' => 'block_catquiz_statistics research export 1',
            'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'plugin' => ['component' => 'block_catquiz_statistics', 'version' => $plugin->versiondisk,
                'release' => $plugin->release],
            'moodle' => $CFG->release,
            'course' => $courseid,
            'evaluationmodel' => $evaluation->get_reference(),
            'modelsnapshot' => (new evalmodel_repository())->get_revision($modelid),
            'population' => array_map(static fn($s) => ['criterion' => $s['criterion'], 'n' => $s['n']], $population['steps']),
            'datasets' => $datasets,
            'pseudonymisation' => ['mode' => $mode, 'scope' => $mode === 'identified' ? null : $subjects->scope,
                'method' => $mode === 'identified' ? 'none (Moodle user id)' : 'HMAC-SHA256, truncated to 80 bit',
                'linkable' => $mode === 'stable' ? 'within scope' : ($mode === 'export' ? 'no' : 'yes (identified)')],
            'notice' => get_string('export:notice', 'block_catquiz_statistics'),
            'synthetic' => (bool) $evaluation->get_reference()['issynthetic'],
            'missingdata' => 'No imputation. Long: status column explains every empty value. Wide: see codebook column "missing".',
            'files' => ['data_long.csv' => count($longcsv) - 1, 'data_wide.csv' => count($widecsv) - 1,
                'codebook.csv' => count($codebook) - 1],
        ];
        return [
            'data_long.csv' => self::csv($longcsv),
            'data_wide.csv' => self::csv($widecsv),
            'codebook.csv' => self::csv($codebook),
            'manifest.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Write the bundle as ZIP into the request directory.
     *
     * @param array $files filename => content
     * @param string $basename Base name of the archive.
     * @return string Path of the ZIP file.
     */
    public static function zip(array $files, string $basename): string {
        $dir = make_request_directory();
        $packer = get_file_packer('application/zip');
        $path = $dir . '/' . clean_filename($basename) . '.zip';
        $packer->archive_to_pathname(array_map(static fn($c) => [$c], $files), $path);
        return $path;
    }

    /**
     * CSV (RFC 4180, comma, UTF-8).
     *
     * @param array $rows Rows.
     * @return string
     */
    public static function csv(array $rows): string {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($h, array_map(static fn($v) => is_float($v) ? sprintf('%.10g', $v) : (string) $v, $row), ',', '"', '');
        }
        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);
        return $csv;
    }
}
