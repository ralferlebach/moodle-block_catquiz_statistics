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

namespace block_catquiz_statistics\import\survey;

/**
 * mod_questionnaire as survey source.
 *
 * Supported question types (others are not imported):
 *   rate (8)          one ordinal item per row (choice); value = rankvalue (1-based or named degree value);
 *                     rankvalue -1 ("N/A") is a declared missing code
 *   radio/drop (4/6)  categorical, value = choice text
 *   yes/no (1)        boolean
 *   numeric (10)      numeric
 *   text/essay (2/3)  string
 *   date (9)          datetime
 * Only complete responses ('y') of instances with non-anonymous respondent type are read.
 * Deleted questions are skipped (supports the older 'y'/'n' and the newer timestamp flag).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class questionnaire_source implements survey_source_interface {
    /** @var int Question types. */
    private const YESNO = 1;
    /** @var int Question types. */
    private const TEXT = 2;
    /** @var int Question types. */
    private const ESSAY = 3;
    /** @var int Question types. */
    private const RADIO = 4;
    /** @var int Question types. */
    private const DROP = 6;
    /** @var int Question types. */
    private const RATE = 8;
    /** @var int Question types. */
    private const DATE = 9;
    /** @var int Question types. */
    private const NUMERIC = 10;

    /**
     * Source key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'questionnaire';
    }

    /**
     * Component.
     *
     * @return string
     */
    public function get_component(): string {
        return 'mod_questionnaire';
    }

    /**
     * Whether mod_questionnaire is installed.
     *
     * @return bool
     */
    public function is_available(): bool {
        return \core_component::get_component_directory('mod_questionnaire') !== null
            && \core_plugin_manager::instance()->get_plugin_info('mod_questionnaire') !== null;
    }

    /**
     * Instances of a course.
     *
     * @param int $courseid Course.
     * @return array cmid => name
     */
    public function get_instances(int $courseid): array {
        $result = [];
        foreach (get_fast_modinfo($courseid)->get_instances_of('questionnaire') as $cm) {
            $result[(int) $cm->id] = $cm->get_formatted_name();
        }
        return $result;
    }

    /**
     * Anonymous respondent type?
     *
     * @param int $cmid Course module.
     * @return bool
     */
    public function is_anonymous(int $cmid): bool {
        return $this->instance($cmid)->respondenttype === 'anonymous';
    }

    /**
     * Importable items.
     *
     * @param int $cmid Course module.
     * @return array
     */
    public function get_items(int $cmid): array {
        global $DB;

        $q = $this->instance($cmid);
        // Older questionnaire versions flag deletion with 'y'/'n', newer ones with a timestamp (NULL/0 = active).
        $deleted = $DB->get_columns('questionnaire_question')['deleted'] ?? null;
        $notdeleted = ($deleted && $deleted->meta_type === 'C') ? "deleted = 'n'" : '(deleted IS NULL OR deleted = 0)';
        $questions = $DB->get_records_select(
            'questionnaire_question',
            "surveyid = :sid AND $notdeleted",
            ['sid' => $q->sid],
            'position, id'
        );
        $choices = [];
        if ($questions) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($questions));
            foreach ($DB->get_records_select('questionnaire_quest_choice', "question_id $insql", $params, 'id') as $c) {
                $choices[(int) $c->question_id][(int) $c->id] = $c;
            }
        }

        $items = [];
        foreach ($questions as $question) {
            $name = trim((string) $question->name) !== '' ? $question->name : 'q' . $question->id;
            $label = trim(strip_tags((string) $question->content)) ?: $name;
            switch ((int) $question->type_id) {
                case self::RATE:
                    [$min, $max] = $this->rate_range($question);
                    foreach ($choices[(int) $question->id] ?? [] as $choice) {
                        $items['q' . $question->id . '_c' . $choice->id] = [
                            'label' => $label . ': ' . trim(strip_tags((string) $choice->content)),
                            'datatype' => 'ordinal', 'measurementlevel' => 'ordinal',
                            'allowedvalues' => ['min' => $min, 'max' => $max], 'missingcodes' => ['-1'],
                            'name' => $name . '_' . $choice->id,
                        ];
                    }
                    break;
                case self::RADIO:
                case self::DROP:
                    $categories = array_values(array_map(
                        static fn($c) => trim(strip_tags((string) $c->content)),
                        $choices[(int) $question->id] ?? []
                    ));
                    $items['q' . $question->id] = ['label' => $label, 'datatype' => 'categorical',
                        'measurementlevel' => 'nominal', 'allowedvalues' => ['categories' => $categories],
                        'missingcodes' => [], 'name' => $name];
                    break;
                case self::YESNO:
                    $items['q' . $question->id] = ['label' => $label, 'datatype' => 'boolean', 'measurementlevel' => 'nominal',
                        'allowedvalues' => null, 'missingcodes' => [], 'name' => $name];
                    break;
                case self::NUMERIC:
                    $items['q' . $question->id] = ['label' => $label, 'datatype' => 'numeric', 'measurementlevel' => 'interval',
                        'allowedvalues' => null, 'missingcodes' => [], 'name' => $name];
                    break;
                case self::DATE:
                    $items['q' . $question->id] = ['label' => $label, 'datatype' => 'datetime', 'measurementlevel' => 'interval',
                        'allowedvalues' => null, 'missingcodes' => [], 'name' => $name];
                    break;
                case self::TEXT:
                case self::ESSAY:
                    $items['q' . $question->id] = ['label' => $label, 'datatype' => 'string', 'measurementlevel' => 'nominal',
                        'allowedvalues' => null, 'missingcodes' => [], 'name' => $name];
                    break;
            }
        }
        return $items;
    }

    /**
     * Complete responses.
     *
     * @param int $cmid Course module.
     * @return array
     */
    public function get_responses(int $cmid): array {
        global $DB;

        $q = $this->instance($cmid);
        if ($q->respondenttype === 'anonymous') {
            return [];
        }
        $responses = $DB->get_records_select(
            'questionnaire_response',
            "questionnaireid = :qid AND complete = 'y' AND userid > 0",
            ['qid' => $q->id],
            'submitted, id',
            'id, userid, submitted'
        );
        if (!$responses) {
            return [];
        }
        $rows = [];
        foreach ($responses as $r) {
            $rows[(int) $r->id] = ['responseid' => (int) $r->id, 'userid' => (int) $r->userid,
                'submitted' => (int) $r->submitted, 'values' => []];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'rid');

        foreach ($DB->get_records_select('questionnaire_response_rank', "response_id $insql", $params) as $v) {
            $rows[(int) $v->response_id]['values']['q' . $v->question_id . '_c' . $v->choice_id] = (string) $v->rankvalue;
        }
        $sql = "SELECT s.id, s.response_id, s.question_id, c.content
                  FROM {questionnaire_resp_single} s
                  JOIN {questionnaire_quest_choice} c ON c.id = s.choice_id
                 WHERE s.response_id $insql";
        foreach ($DB->get_records_sql($sql, $params) as $v) {
            $rows[(int) $v->response_id]['values']['q' . $v->question_id] = trim(strip_tags((string) $v->content));
        }
        foreach ($DB->get_records_select('questionnaire_response_bool', "response_id $insql", $params) as $v) {
            $rows[(int) $v->response_id]['values']['q' . $v->question_id] = $v->choice_id === 'y' ? 'yes' : 'no';
        }
        foreach ($DB->get_records_select('questionnaire_response_text', "response_id $insql", $params) as $v) {
            $rows[(int) $v->response_id]['values']['q' . $v->question_id] = (string) $v->response;
        }
        foreach ($DB->get_records_select('questionnaire_response_date', "response_id $insql", $params) as $v) {
            $rows[(int) $v->response_id]['values']['q' . $v->question_id] = (string) $v->response;
        }
        return array_values($rows);
    }

    /**
     * Scale range of a rate question: named degrees if defined, else 1..length.
     *
     * @param \stdClass $question Question row.
     * @return array [min, max]
     */
    private function rate_range(\stdClass $question): array {
        $extra = $question->extradata ? json_decode($question->extradata, true) : null;
        if (is_array($extra) && !empty($extra)) {
            $keys = array_map('floatval', array_keys($extra));
            return [min($keys), max($keys)];
        }
        return [1, max(1, (int) $question->length)];
    }

    /**
     * Questionnaire record of a course module.
     *
     * @param int $cmid Course module.
     * @return \stdClass
     */
    private function instance(int $cmid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_id('questionnaire', $cmid, 0, false, MUST_EXIST);
        return $DB->get_record('questionnaire', ['id' => $cm->instance], '*', MUST_EXIST);
    }
}
