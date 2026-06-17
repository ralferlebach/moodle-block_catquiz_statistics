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
 * Standard-error validity validator for CAT quiz attempts.
 *
 * Mirrors the two SE-filtering rules from local_catquiz feedbacksettings.php:
 *   filter_nminscale() - minimum N items required per subscale
 *   filter_semax()     - maximum SE threshold per scale
 *
 * All methods are static; no DB access, no Moodle globals.
 * The repository loads quizsettings from local_catquiz_tests.json and passes
 * the decoded object here; this class only applies the rules.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\local;

/**
 * SE validity helper.
 *
 * Behaviour when a threshold is absent (null):
 * The corresponding filter is SKIPPED and all values pass (permissive fallback).
 * This matches feedbacksettings.php: thresholds are optional per quiz instance.
 *
 * Note on the global or root scale:
 * graphicalsummary_data tracks items by their assigned subscale (questionscale).
 * If nminscale is set, the global scale will typically show 0 directly-assigned
 * items and therefore fail the filter. Callers that always want to expose global
 * SE should skip the result entry for the global scale id or pass nminscale=null.
 */
class se_validator {
    /**
     * Extract SE validity thresholds from decoded quiz settings.
     *
     * Reads the object returned by local_catquiz testenvironment::return_settings()
     * (i.e. json_decode of local_catquiz_tests.json).
     *
     * Keys extracted:
     *   catquiz_standarderrorgroup -> catquiz_standarderror_max  (semax)
     *   maxquestionsscalegroup     -> catquiz_minquestionspersubscale (nminscale)
     *
     * @param object|null $quizsettings Decoded local_catquiz_tests.json; null means permissive.
     * @return array Associative array with keys nminscale (int|null) and semax (float|null).
     */
    public static function extract_thresholds(?object $quizsettings): array {
        if ($quizsettings === null) {
            return ['nminscale' => null, 'semax' => null];
        }

        $semax = null;
        if (
            isset($quizsettings->catquiz_standarderrorgroup->catquiz_standarderror_max)
            && is_numeric($quizsettings->catquiz_standarderrorgroup->catquiz_standarderror_max)
        ) {
            $semax = (float) $quizsettings->catquiz_standarderrorgroup->catquiz_standarderror_max;
        }

        $nminscale = null;
        if (
            isset($quizsettings->maxquestionsscalegroup->catquiz_minquestionspersubscale)
            && (int) $quizsettings->maxquestionsscalegroup->catquiz_minquestionspersubscale > 0
        ) {
            $nminscale = (int) $quizsettings->maxquestionsscalegroup->catquiz_minquestionspersubscale;
        }

        return ['nminscale' => $nminscale, 'semax' => $semax];
    }

    /**
     * Count test items used per CAT scale from graphicalsummary_data steps.
     *
     * Each step object produced by attempt_repository::extract_graphicalsummary()
     * carries a questionscale property (int scale ID) identifying the subscale
     * the presented question belongs to.
     *
     * @param array $graphicalsummary Steps extracted by attempt_repository; may be empty.
     * @return array Map of scaleid (int) to item count (int) for that scale.
     */
    public static function count_items_per_scale(array $graphicalsummary): array {
        $counts = [];
        foreach ($graphicalsummary as $step) {
            $scaleid = isset($step->questionscale) ? (int) $step->questionscale : null;
            if ($scaleid !== null) {
                $counts[$scaleid] = ($counts[$scaleid] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Apply SE validity filters; returns null for scales that fail any check.
     *
     * Rules (independent; both applied when configured):
     *   nminscale: items in scale (from graphicalsummary) must be >= nminscale.
     *   semax:     actual SE value must be <= semax.
     *
     * When a threshold is null, that filter is SKIPPED (permissive).
     *
     * @param array $se SE per scaleid (array<int,float>) from attempts.json.
     * @param array $graphicalsummary Steps from graphicalsummary_data (parsed by repository).
     * @param int|null $nminscale Minimum N items per subscale (null = skip check).
     * @param float|null $semax SE ceiling (null = skip check).
     * @return array SE value (float) per scale ID, or null if that scale failed a check.
     */
    public static function validate(
        array $se,
        array $graphicalsummary,
        ?int $nminscale,
        ?float $semax
    ): array {
        $nitems = self::count_items_per_scale($graphicalsummary);
        $result = [];

        foreach ($se as $rawscaleid => $value) {
            $scaleid = (int) $rawscaleid;
            $valid = true;

            if ($nminscale !== null) {
                $n = $nitems[$scaleid] ?? 0;
                if ($n < $nminscale) {
                    $valid = false;
                }
            }

            if ($valid && $semax !== null && (float) $value > $semax) {
                $valid = false;
            }

            $result[$scaleid] = $valid ? (float) $value : null;
        }

        return $result;
    }
}
