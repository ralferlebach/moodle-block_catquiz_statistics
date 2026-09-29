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
 * PHPUnit tests for the longitudinal data foundation (Issue #2).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\analytics;

use block_catquiz_statistics\analytics\provider\imported_observation_provider;
use block_catquiz_statistics\analytics\provider\milestone_provider;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\evalmodel_repository;
use block_catquiz_statistics\repository\milestone_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Tests for observations, milestones, evaluation-model separation and block independence.
 *
 * @covers \block_catquiz_statistics\repository\observation_repository
 * @covers \block_catquiz_statistics\repository\milestone_repository
 * @covers \block_catquiz_statistics\repository\evalmodel_repository
 * @covers \block_catquiz_statistics\repository\dataset_repository
 * @covers \block_catquiz_statistics\analytics\analytics_query_service
 * @covers \block_catquiz_statistics\analytics\observation_query
 */
final class foundation_test extends \advanced_testcase {
    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Build a numeric survey observation for a course.
     *
     * @param int $userid User.
     * @param \stdClass $course Course.
     * @param int $variableid Variable.
     * @param int $datasetid Dataset.
     * @param float $value Value.
     * @param int $time Time.
     * @param string $timepoint Timepoint label.
     * @return observation
     */
    private function make_obs(
        int $userid,
        \stdClass $course,
        int $variableid,
        int $datasetid,
        float $value,
        int $time,
        string $timepoint = 'T0'
    ): observation {
        return new observation(
            userid: $userid,
            variablekey: 'var:' . $variableid,
            sourcecomponent: 'block_catquiz_statistics',
            sourcearea: 'csv',
            sourcekey: "csv:$datasetid:$userid:$variableid:$timepoint",
            origincontextid: \context_course::instance($course->id)->id,
            valuetype: value_type::NUMERIC,
            origincourseid: (int) $course->id,
            occurredat: $time,
            timepoint: $timepoint,
            valuenumeric: $value,
            datasetid: $datasetid,
            variableid: $variableid,
        );
    }

    /**
     * A data point keeps userid, time and origin context; the same person owns points in two courses.
     *
     * @return void
     */
    public function test_observation_keeps_identity_across_courses(): void {
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $datasets = new dataset_repository();
        $syscontext = \context_system::instance()->id;
        $varid = $datasets->ensure_variable($syscontext, 'selfeff', 'Self-efficacy', 'numeric', 'interval');
        $dsa = $datasets->create_dataset(\context_course::instance($coursea->id)->id, 'Survey A', 'csv');
        $dsb = $datasets->create_dataset(\context_course::instance($courseb->id)->id, 'Survey B', 'csv');

        $repo = new observation_repository();
        $repo->upsert($this->make_obs($user->id, $coursea, $varid, $dsa, 3.5, 1000, 'T0'));
        $repo->upsert($this->make_obs($user->id, $courseb, $varid, $dsb, 4.0, 2000, 'T1'));

        $service = new analytics_query_service([new imported_observation_provider()]);
        $timeline = $service->get_timeline((int) $user->id);

        $this->assertCount(2, $timeline);
        $this->assertSame((int) $user->id, $timeline[0]->userid);
        $this->assertSame(1000, $timeline[0]->occurredat);
        $this->assertSame((int) $coursea->id, $timeline[0]->origincourseid);
        $this->assertSame(\context_course::instance($coursea->id)->id, $timeline[0]->origincontextid);
        $this->assertSame((int) $courseb->id, $timeline[1]->origincourseid);
        $this->assertSame('T1', $timeline[1]->timepoint);

        // Kursfilter schränkt ein, ohne die Identität zu verändern.
        $onlyb = $service->get_observations(new observation_query(courseids: [$courseb->id]));
        $this->assertCount(1, $onlyb);
        $this->assertSame(4.0, $onlyb[0]->get_value());
    }

    /**
     * Upserting the same source key twice never duplicates.
     *
     * @return void
     */
    public function test_upsert_is_idempotent(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course();
        $datasets = new dataset_repository();
        $varid = $datasets->ensure_variable(\context_system::instance()->id, 'age', 'Age', 'integer', 'interval');
        $dsid = $datasets->create_dataset(\context_course::instance($course->id)->id, 'Demo', 'csv');

        $repo = new observation_repository();
        $id1 = $repo->upsert($this->make_obs($user->id, $course, $varid, $dsid, 20, 1000));
        $id2 = $repo->upsert($this->make_obs($user->id, $course, $varid, $dsid, 21, 1000));

        $this->assertSame($id1, $id2);
        $this->assertSame(1, $DB->count_records(observation_repository::TABLE));
        $this->assertEquals(21, $DB->get_field(observation_repository::TABLE, 'valuenumeric', ['id' => $id1]));
    }

    /**
     * Two block instances in different courses do not create competing person identities,
     * and deleting a block instance keeps all historic data points.
     *
     * @return void
     */
    public function test_block_instances_do_not_own_data(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/blocklib.php');

        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $blocka = $gen->create_block('catquiz_statistics', ['parentcontextid' => \context_course::instance($coursea->id)->id]);
        $gen->create_block('catquiz_statistics', ['parentcontextid' => \context_course::instance($courseb->id)->id]);

        $milestones = new milestone_repository();
        foreach ([$coursea, $courseb] as $i => $course) {
            $milestones->record(
                userid: (int) $user->id,
                action: semantic_action::VIEWED,
                objecttype: object_type::FEEDBACK,
                sourcecomponent: 'mod_adaptivequiz',
                sourceevent: '\\mod_adaptivequiz\\event\\result_page_viewed',
                sourcekey: 'test:resultview:' . $i,
                origincontextid: \context_course::instance($course->id)->id,
                occurredat: 1000 + $i,
                origincourseid: (int) $course->id,
            );
        }
        $this->assertSame(1, (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT userid) FROM {' . milestone_repository::TABLE . '}'
        ));

        blocks_delete_instance($DB->get_record('block_instances', ['id' => $blocka->id]));

        $this->assertSame(2, $DB->count_records(milestone_repository::TABLE));
        $service = new analytics_query_service([new milestone_provider()]);
        $this->assertCount(2, $service->get_timeline((int) $user->id));
    }

    /**
     * Repeated milestones keep firstoccurred, advance lastoccurred and count occurrences.
     *
     * @return void
     */
    public function test_milestone_first_last_count(): void {
        $user = $this->getDataGenerator()->create_user();
        $repo = new milestone_repository();
        $args = [
            'userid' => (int) $user->id,
            'action' => semantic_action::VIEWED,
            'objecttype' => object_type::FEEDBACK,
            'sourcecomponent' => 'mod_adaptivequiz',
            'sourceevent' => '\\mod_adaptivequiz\\event\\result_page_viewed',
            'sourcekey' => 'mod_adaptivequiz:resultview:42',
            'origincontextid' => \context_system::instance()->id,
        ];
        $repo->record(...$args, occurredat: 2000);
        $repo->record(...$args, occurredat: 3000);
        $row = $repo->record(...$args, occurredat: 2500);

        $this->assertEquals(2000, $row->firstoccurred);
        $this->assertEquals(3000, $row->lastoccurred);
        $this->assertEquals(3, $row->occurrences);

        $obs = (new milestone_provider())->get_observations(new observation_query(userids: [$user->id]));
        $this->assertCount(1, $obs);
        $this->assertSame('event:viewed:feedback', $obs[0]->variablekey);
        $this->assertSame(2000, $obs[0]->occurredat);
        $this->assertSame(3, $obs[0]->attributes['occurrences']);
    }

    /**
     * The role mapping lives in the model, not on the data point; one variable, two models, two roles.
     *
     * @return void
     */
    public function test_same_variable_different_roles_in_two_models(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $ctx = \context_course::instance($course->id)->id;
        $varid = (new dataset_repository())->ensure_variable($ctx, 'selfeff_t0', 'Self-efficacy T0', 'numeric', 'interval');

        $models = new evalmodel_repository();
        $ma = $models->create_model($ctx, 'Model A');
        $mb = $models->create_model($ctx, 'Model B');
        $models->assign_role($ma, analytic_role::DISPOSITION, 'variable', 'var:' . $varid);
        $models->assign_role($mb, analytic_role::COVARIATE, 'variable', 'var:' . $varid);

        $this->assertSame(analytic_role::DISPOSITION, $models->get_role_of($ma, 'variable', 'var:' . $varid));
        $this->assertSame(analytic_role::COVARIATE, $models->get_role_of($mb, 'variable', 'var:' . $varid));

        // Das Observation-Schema kennt keine Rollenspalte.
        $columns = array_keys($DB->get_columns(observation_repository::TABLE));
        $this->assertNotContains('role', $columns);
        $this->assertNotContains('analyticrole', $columns);

        // Umzuordnen erhöht die Modellversion.
        $before = (int) $models->get_model($ma)->version;
        $models->assign_role($ma, analytic_role::PERFORMANCE, 'variable', 'var:' . $varid);
        $this->assertSame($before + 1, (int) $models->get_model($ma)->version);
        $this->assertCount(1, $models->get_roles($ma));
    }

    /**
     * Deleting a dataset removes its own observations and identity audit, but keeps register variables.
     *
     * @return void
     */
    public function test_delete_dataset_scope(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course();
        $datasets = new dataset_repository();
        $varid = $datasets->ensure_variable(\context_system::instance()->id, 'mmq01', 'MMQ 01', 'ordinal', 'ordinal');
        $dsid = $datasets->create_dataset(\context_course::instance($course->id)->id, 'MMQ', 'csv');
        (new observation_repository())->upsert($this->make_obs($user->id, $course, $varid, $dsid, 4, 1000));

        $datasets->delete_dataset($dsid);

        $this->assertSame(0, $DB->count_records(observation_repository::TABLE));
        $this->assertTrue($DB->record_exists(dataset_repository::TABLE_VARIABLE, ['id' => $varid]));
    }

    /**
     * Variable-key filters with prefixes work and let providers skip whole key families.
     *
     * @return void
     */
    public function test_query_variable_filter(): void {
        $q = new observation_query(variablekeys: ['catquiz:ability:*', 'event:viewed:feedback']);
        $this->assertTrue($q->matches_variable('catquiz:ability:7'));
        $this->assertTrue($q->matches_variable('event:viewed:feedback'));
        $this->assertFalse($q->matches_variable('event:completed:assessment'));
        $this->assertFalse($q->excludes_prefix('catquiz:'));
        $this->assertFalse($q->excludes_prefix('event:'));
        $this->assertTrue($q->excludes_prefix('var:'));
    }

    /**
     * No code path of the plugin references the removed attemptscale structures.
     *
     * @return void
     */
    public function test_no_attemptscale_reference(): void {
        global $CFG;
        $root = $CFG->dirroot . '/blocks/catquiz_statistics';
        $needle = 'attempt' . 'scale';
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/tests/') || str_contains($path, '/.git/')) {
                continue;
            }
            if (stripos(file_get_contents($path), $needle) !== false) {
                $offenders[] = substr($path, strlen($root));
            }
        }
        $this->assertSame([], $offenders);
    }
}
