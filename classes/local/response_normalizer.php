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
 * Qtype-aware response normaliser for Module e (Item & Response Analysis).
 *
 * Transforms raw question_attempt_step_data key-value pairs into a canonical,
 * order-independent representation suitable for distractor frequency tables.
 *
 * Design goals:
 *   - MC / multi-select: order-independent (uses _order + choiceN + question_answers)
 *   - truefalse: canonical 'true' / 'false'
 *   - numerical / shortanswer: raw answer value
 *   - match / ddwtos / gapselect: 'SubQ→AnsText' pipe-joined string
 *   - multianswer (cloze): recursive per sub-question; fallback to fraction only
 *   - unknown types: fraction + responsesummary (as-is from QE)
 *
 * Output row for distractor analysis (Module e):
 *   response_canonical  – human-readable, order-independent  (Excel-friendly)
 *   response_fingerprint – canonical answer-ID set (for grouping)
 *   fraction            – 0.0–1.0 (always reliable)
 *   N                   – count (aggregated by caller)
 *   response_json       – raw step_data as JSON (last column, optional forensics)
 *
 * Sort order: fraction DESC, N DESC (correct answer always first).
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\local;

/**
 * Normalises raw question_attempt_step_data into order-independent response rows.
 */
class response_normalizer {

    /** Separator between options in multi-select canonical strings. */
    private const OPTION_SEP = ' | ';

    /**
     * Normalise a single step's response data into a canonical representation.
     *
     * @param string        $qtype    Moodle question type name (e.g. 'multichoice').
     * @param array         $stepdata Key-value pairs from question_attempt_step_data.
     * @param float|null    $fraction Fraction value from question_attempt_steps.
     * @param string        $responsesummary Raw responsesummary (fallback display).
     * @return object Normalised response with: response_canonical, response_fingerprint,
     *                fraction, response_json.
     */
    public function normalise(
        string $qtype,
        array $stepdata,
        ?float $fraction,
        string $responsesummary
    ): object {
        // TODO Phase 2: implement per-qtype adapter methods.
        // Stub: fall back to responsesummary | fraction for all types.
        return (object) [
            'response_canonical'   => $responsesummary,
            'response_fingerprint' => $responsesummary, // order-sensitive in stub; fixed Phase 2
            'fraction'             => $fraction,
            'response_json'        => json_encode($stepdata),
        ];
    }

    /**
     * Normalise a multichoice response using the _order key for shuffle-independence.
     *
     * Algorithm:
     *   1. Decode _order → ordered array of answer IDs (as presented).
     *   2. For each choiceN=1, resolve position N → answer ID via _order.
     *   3. Sort selected answer IDs ascending.
     *   4. Join answer texts from question_answers for the canonical string.
     *
     * TODO Phase 2: implement; requires joining question_answers.answer.
     *
     * @param array $stepdata  question_attempt_step_data key-value pairs.
     * @param int   $questionid Question ID for join.
     * @return object|null Normalised response or null when _order is absent.
     */
    private function normalise_multichoice(array $stepdata, int $questionid): ?object {
        // TODO Phase 2.
        return null;
    }

    /**
     * Normalise a match/ddwtos/gapselect response into 'SubQ→AnsText | …'.
     *
     * TODO Phase 2: implement.
     *
     * @param string $qtype    Question type ('match', 'ddwtos', 'gapselect').
     * @param array  $stepdata question_attempt_step_data key-value pairs.
     * @param int    $questionid Question ID.
     * @return object|null
     */
    private function normalise_structured(string $qtype, array $stepdata, int $questionid): ?object {
        // TODO Phase 2.
        return null;
    }

    /**
     * Normalise a cloze (multianswer) response recursively per sub-question.
     *
     * Falls back to fraction-only when sub-question parsing is not possible.
     *
     * TODO Phase 2: implement recursive adapter.
     *
     * @param array      $stepdata  question_attempt_step_data key-value pairs.
     * @param int        $questionid Cloze question ID.
     * @param float|null $fraction  Overall fraction.
     * @return object
     */
    private function normalise_cloze(array $stepdata, int $questionid, ?float $fraction): object {
        // TODO Phase 2: recursive per sub-question.
        // Stub: fraction + raw JSON.
        return (object) [
            'response_canonical'   => '',
            'response_fingerprint' => '',
            'fraction'             => $fraction,
            'response_json'        => json_encode($stepdata),
        ];
    }
}
