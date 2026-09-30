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
 * PHPUnit tests for the survey adapters (Issue #3).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\import;

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\import\survey\feedback_source;
use block_catquiz_statistics\import\survey\questionnaire_source;
use block_catquiz_statistics\repository\dataset_repository;

/**
 * Tests for survey_importer with mod_feedback and mod_questionnaire.
 *
 * @covers \block_catquiz_statistics\import\survey_importer
 * @covers \block_catquiz_statistics\import\survey\feedback_source
 * @covers \block_catquiz_statistics\import\survey\questionnaire_source
 */
final class survey_import_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass[] Learners. */
    private array $users;

    /**
     * Course with two learners.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->users = [$gen->create_and_enrol($this->course), $gen->create_and_enrol($this->course)];
    }

    /**
     * Feedback with a rated item (0..4), a numeric and a single-choice item.
     *
     * @param int $anonymous FEEDBACK_ANONYMOUS_YES (1) or _NO (2).
     * @return array [cm, items]
     */
    private function create_feedback(int $anonymous): array {
        $gen = $this->getDataGenerator();
        $feedback = $gen->create_module('feedback', ['course' => $this->course->id, 'anonymous' => $anonymous]);
        $fgen = $gen->get_plugin_generator('mod_feedback');
        $items = [
            'rated' => $fgen->create_item_multichoicerated($feedback, ['label' => 'mmq01', 'name' => 'I like maths',
                'hasvalue' => 1, 'values' => "0/never\n1/rarely\n2/sometimes\n3/often\n4/always"]),
            'age' => $fgen->create_item_numeric($feedback, ['label' => 'age', 'name' => 'Age', 'hasvalue' => 1]),
            'degree' => $fgen->create_item_multichoice($feedback, ['label' => 'degree', 'name' => 'Degree', 'hasvalue' => 1,
                'values' => "MB\nST", 'subtype' => 'r']),
            'info' => $fgen->create_item_label($feedback),
        ];
        return [get_coursemodule_from_instance('feedback', $feedback->id), $items];
    }

    /**
     * Store a completed feedback response directly.
     *
     * @param \stdClass $cm Course module.
     * @param int $userid User.
     * @param array $values item id => stored value
     * @param int $time Time.
     * @return void
     */
    private function answer_feedback(\stdClass $cm, int $userid, array $values, int $time): void {
        global $DB;
        $cid = $DB->insert_record('feedback_completed', (object) ['feedback' => $cm->instance, 'userid' => $userid,
            'timemodified' => $time, 'random_response' => 0, 'anonymous_response' => 2, 'courseid' => $this->course->id]);
        foreach ($values as $itemid => $value) {
            $DB->insert_record('feedback_value', (object) ['course_id' => $this->course->id, 'item' => $itemid,
                'completed' => $cid, 'tmp_completed' => 0, 'value' => $value]);
        }
    }

    /**
     * Rated options become their weights, choices their texts; layout items are ignored.
     *
     * @return void
     */
    public function test_feedback_import(): void {
        [$cm, $items] = $this->create_feedback(2);
        $this->answer_feedback($cm, (int) $this->users[0]->id, [
            $items['rated']->id => '4', $items['age']->id => '19', $items['degree']->id => '2',
        ], 1000);
        $this->answer_feedback($cm, (int) $this->users[1]->id, [
            $items['rated']->id => '0', $items['age']->id => '21', $items['degree']->id => '1',
        ], 1100);

        $source = new feedback_source();
        $this->assertCount(3, $source->get_items((int) $cm->id), 'Label item is not importable.');
        $result = (new survey_importer())->import($source, (int) $cm->id, ['timepoint' => 'T0']);

        $this->assertSame(2, $result['responses']);
        $this->assertSame(5, $result['observed']);
        $this->assertSame(1, $result['missing'], '"Not selected" is missing, not a value.');

        $obs = analytics_query_service::create_default()->get_observations(
            new observation_query(userids: [$this->users[0]->id], variablekeys: ['var:*'])
        );
        $values = [];
        foreach ($obs as $o) {
            $values[$o->attributes['shortname']] = $o->get_value();
            $this->assertSame((int) \context_module::instance($cm->id)->id, $o->origincontextid);
            $this->assertSame('T0', $o->timepoint);
        }
        $this->assertEquals(3.0, $values['feedback' . $cm->id . '_mmq01'], 'Option 4 carries weight 3.');
        $this->assertEquals(19.0, $values['feedback' . $cm->id . '_age']);
        $this->assertSame('ST', $values['feedback' . $cm->id . '_degree']);
    }

    /**
     * Anonymous surveys are refused.
     *
     * @return void
     */
    public function test_anonymous_feedback_refused(): void {
        [$cm] = $this->create_feedback(1);
        $this->expectException(\moodle_exception::class);
        (new survey_importer())->import(new feedback_source(), (int) $cm->id);
    }

    /**
     * Re-import: identical data returns the dataset; new answers create a superseding version.
     *
     * @return void
     */
    public function test_reimport_versions(): void {
        [$cm, $items] = $this->create_feedback(2);
        $this->answer_feedback($cm, (int) $this->users[0]->id, [$items['age']->id => '19'], 1000);
        $importer = new survey_importer();
        $first = $importer->import(new feedback_source(), (int) $cm->id, ['timepoint' => 'T0']);
        $again = $importer->import(new feedback_source(), (int) $cm->id, ['timepoint' => 'T0']);
        $this->assertTrue($again['identical']);
        $this->assertSame($first['datasetid'], $again['datasetid']);

        $this->answer_feedback($cm, (int) $this->users[1]->id, [$items['age']->id => '22'], 1200);
        $second = $importer->import(new feedback_source(), (int) $cm->id, ['timepoint' => 'T0']);
        $this->assertFalse($second['identical']);
        $this->assertSame(2, $second['version']);
        $this->assertTrue((new dataset_repository())->is_superseded($first['datasetid']));
        $all = analytics_query_service::create_default()->get_observations(new observation_query(variablekeys: ['var:*']));
        $this->assertCount(6, $all, 'Only the current version (2 users x 3 items) is visible.');
    }

    /**
     * Questionnaire rate questions become one ordinal item per row; N/A is missing.
     *
     * @return void
     */
    public function test_questionnaire_rate_and_radio(): void {
        global $DB;
        $source = new questionnaire_source();
        if (!$source->is_available()) {
            $this->markTestSkipped('mod_questionnaire is not installed.');
        }
        $gen = $this->getDataGenerator();
        $q = $gen->create_module('questionnaire', ['course' => $this->course->id, 'respondenttype' => 'fullname']);
        $sid = (int) $DB->get_field('questionnaire', 'sid', ['id' => $q->id]);
        $rate = $DB->insert_record('questionnaire_question', (object) ['surveyid' => $sid, 'name' => 'MMQ', 'type_id' => 8,
            'length' => 5, 'precise' => 0, 'position' => 1, 'content' => 'Motivation', 'required' => 'n', 'deleted' => null]);
        $c1 = $DB->insert_record('questionnaire_quest_choice', (object) ['question_id' => $rate, 'content' => 'I enjoy maths']);
        $c2 = $DB->insert_record('questionnaire_quest_choice', (object) ['question_id' => $rate, 'content' => 'Maths is useful']);
        $radio = $DB->insert_record('questionnaire_question', (object) ['surveyid' => $sid, 'name' => 'degree', 'type_id' => 4,
            'length' => 0, 'precise' => 0, 'position' => 2, 'content' => 'Degree', 'required' => 'n', 'deleted' => null]);
        $mb = $DB->insert_record('questionnaire_quest_choice', (object) ['question_id' => $radio, 'content' => 'MB']);
        $DB->insert_record('questionnaire_quest_choice', (object) ['question_id' => $radio, 'content' => 'ST']);

        $rid = $DB->insert_record('questionnaire_response', (object) ['questionnaireid' => $q->id, 'submitted' => 1500,
            'complete' => 'y', 'grade' => 0, 'userid' => $this->users[0]->id]);
        $DB->insert_record('questionnaire_response_rank', (object) ['response_id' => $rid, 'question_id' => $rate,
            'choice_id' => $c1, 'rankvalue' => 4]);
        $DB->insert_record('questionnaire_response_rank', (object) ['response_id' => $rid, 'question_id' => $rate,
            'choice_id' => $c2, 'rankvalue' => -1]);
        $DB->insert_record('questionnaire_resp_single', (object) ['response_id' => $rid, 'question_id' => $radio,
            'choice_id' => $mb]);
        $DB->insert_record('questionnaire_response', (object) ['questionnaireid' => $q->id, 'submitted' => 1600,
            'complete' => 'n', 'grade' => 0, 'userid' => $this->users[1]->id]);

        $items = $source->get_items((int) $q->cmid);
        $this->assertCount(3, $items);
        $result = (new survey_importer())->import($source, (int) $q->cmid, ['timepoint' => 'T0']);

        $this->assertSame(1, $result['responses'], 'Incomplete responses are not imported.');
        $this->assertSame(2, $result['observed']);
        $this->assertSame(1, $result['missing'], 'N/A (-1) is a missing code.');

        $DB->set_field('questionnaire', 'respondenttype', 'anonymous', ['id' => $q->id]);
        $this->expectException(\moodle_exception::class);
        (new survey_importer())->import($source, (int) $q->cmid, ['timepoint' => 'T1']);
    }
}
