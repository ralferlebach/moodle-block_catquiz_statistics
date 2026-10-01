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
 * PHPUnit tests for the synthetic demo cohort (Issue #9).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\demo;

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\milestone_repository;
use block_catquiz_statistics\repository\observation_repository;

/**
 * Tests for rng, scenario and cohort_generator.
 *
 * @covers \block_catquiz_statistics\demo\rng
 * @covers \block_catquiz_statistics\demo\scenario
 * @covers \block_catquiz_statistics\demo\cohort_generator
 */
final class cohort_generator_test extends \advanced_testcase {
    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Canonical fingerprint of a run: all observation values keyed by person index (ids excluded).
     *
     * @param int $demoid Demo run.
     * @return array
     */
    private function fingerprint(int $demoid): array {
        global $DB;
        $run = $DB->get_record(cohort_generator::TABLE, ['id' => $demoid]);
        $index = $DB->get_records_menu(cohort_generator::TABLE_USER, ['demoid' => $demoid], '', 'userid, personindex');
        $rows = [];
        $obs = analytics_query_service::create_default()->get_observations(new observation_query(courseids: [$run->courseid]));
        foreach ($obs as $o) {
            $key = $index[$o->userid] . '|' . ($o->attributes['shortname'] ?? preg_replace('/\d+$/', '', $o->variablekey))
                . '|' . $o->timepoint . '|' . $o->occurredat;
            $rows[$key] = [$o->status->value, $o->get_value(), $o->attributes['occurrences'] ?? null];
        }
        ksort($rows);
        return $rows;
    }

    /**
     * Same seed and configuration produce the same cohort; another seed differs.
     *
     * @return void
     */
    public function test_seed_reproducible(): void {
        $gen = new cohort_generator();
        $this->assertSame($gen->simulate(7, 20, 'mixed', 0), $gen->simulate(7, 20, 'mixed', 0));
        $this->assertNotSame($gen->simulate(7, 20, 'mixed', 0), $gen->simulate(8, 20, 'mixed', 0));

        $a = $gen->generate(7, 12, 'balanced');
        $fa = $this->fingerprint($a);
        $gen->reset($a);
        $b = $gen->generate(7, 12, 'balanced');
        $this->assertNotEmpty($fa);
        $this->assertSame($fa, $this->fingerprint($b));
    }

    /**
     * Cohort size is exact and no real identifiers are created.
     *
     * @return void
     */
    public function test_size_and_no_real_identifiers(): void {
        global $DB;
        $id = (new cohort_generator())->generate(11, 15, 'highuptake_lowlearning');
        $run = $DB->get_record(cohort_generator::TABLE, ['id' => $id]);
        $this->assertCount(15, get_enrolled_users(\context_course::instance($run->courseid)));

        $userids = $DB->get_fieldset_select(cohort_generator::TABLE_USER, 'userid', 'demoid = ?', [$id]);
        $this->assertCount(15, $userids);
        foreach ($DB->get_records_list('user', 'id', $userids) as $u) {
            $this->assertStringEndsWith('@example.invalid', $u->email);
            $this->assertSame('', $u->idnumber);
            $this->assertSame('nologin', $u->auth);
            $this->assertStringStartsWith(cohort_generator::PREFIX, $u->username);
            $this->assertSame('Synthetic', $u->firstname);
        }
        $course = $DB->get_record('course', ['id' => $run->courseid]);
        $this->assertStringContainsString('SYNTHETIC DEMO DATA', $course->fullname);
    }

    /**
     * Every data point of a demo run is flagged synthetic, incl. derived construct scores and live outcomes.
     *
     * @return void
     */
    public function test_all_demo_data_is_marked(): void {
        global $DB;
        $id = (new cohort_generator())->generate(3, 10, 'mixed');
        $run = $DB->get_record(cohort_generator::TABLE, ['id' => $id]);
        $obs = analytics_query_service::create_default()->get_observations(new observation_query(courseids: [$run->courseid]));
        $prefixes = [];
        foreach ($obs as $o) {
            $this->assertTrue($o->issynthetic, $o->variablekey . ' not marked synthetic');
            $prefixes[explode(':', $o->variablekey)[0]] = true;
        }
        $this->assertEqualsCanonicalizing(['var', 'construct', 'event', 'outcome'], array_keys($prefixes));
        $this->assertSame([], analytics_query_service::create_default()->get_observations(
            new observation_query(courseids: [$run->courseid], synthetic: false)
        ));
        $this->assertSame(0, $DB->count_records_select('block_catquiz_statistics_variable', 'issynthetic = 0'));
    }

    /**
     * Data classes can be generated selectively.
     *
     * @return void
     */
    public function test_classes_optional(): void {
        global $DB;
        $gen = new cohort_generator();
        $only = function (array $classes) use ($gen, $DB): array {
            $id = $gen->generate(5, 6, 'balanced', $classes);
            $run = $DB->get_record(cohort_generator::TABLE, ['id' => $id]);
            $keys = [];
            foreach (
                analytics_query_service::create_default()->get_observations(
                    new observation_query(courseids: [$run->courseid])
                ) as $o
            ) {
                $keys[explode(':', $o->variablekey)[0]] = true;
            }
            $gen->reset($id);
            return array_keys($keys);
        };
        $this->assertSame(['event'], $only(['exposure']));
        $this->assertEqualsCanonicalizing(['var', 'construct'], $only(['disposition']));
        $this->assertEqualsCanonicalizing(['var', 'outcome'], $only(['outcome']));
        $this->expectException(\coding_exception::class);
        $gen->generate(5, 6, 'balanced', ['nonsense']);
    }

    /**
     * Reset removes exactly the demo run — real courses, users and data points stay untouched.
     *
     * @return void
     */
    public function test_reset_removes_only_demo_data(): void {
        global $DB;
        $real = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($real);
        $ctx = (int) \context_course::instance($real->id)->id;
        $varid = (new dataset_repository())->ensure_variable($ctx, 'real_var', 'Real', 'numeric', 'interval');
        (new observation_repository())->upsert(new observation(
            userid: (int) $user->id,
            variablekey: 'var:' . $varid,
            sourcecomponent: 'test',
            sourcearea: 'x',
            sourcekey: 'real:1',
            origincontextid: $ctx,
            valuetype: value_type::NUMERIC,
            origincourseid: (int) $real->id,
            valuenumeric: 1.0,
            variableid: $varid,
        ));
        (new milestone_repository())->record(
            (int) $user->id,
            semantic_action::VIEWED,
            object_type::FEEDBACK,
            'mod_adaptivequiz',
            'x',
            'real:m1',
            $ctx,
            1000,
            (int) $real->id
        );
        $before = [
            'users' => $DB->count_records('user', ['deleted' => 0]),
            'courses' => $DB->count_records('course'),
        ];

        $gen = new cohort_generator();
        $id = $gen->generate(9, 8, 'lowuptake_highperformance');
        $democourse = (int) $DB->get_field(cohort_generator::TABLE, 'courseid', ['id' => $id]);
        $gen->reset($id);

        $this->assertSame($before['users'], $DB->count_records('user', ['deleted' => 0]));
        $this->assertSame($before['courses'], $DB->count_records('course'));
        $this->assertFalse($DB->record_exists('course', ['id' => $democourse]));
        $this->assertTrue($DB->record_exists('course', ['id' => $real->id]));
        $this->assertSame(1, $DB->count_records(observation_repository::TABLE));
        $this->assertSame(1, $DB->count_records(milestone_repository::TABLE));
        $this->assertSame(1, $DB->count_records(dataset_repository::TABLE_VARIABLE));
        foreach (
            ['block_catquiz_statistics_dataset', 'block_catquiz_statistics_construct',
                'block_catquiz_statistics_evalmodel', 'block_catquiz_statistics_outcome',
                cohort_generator::TABLE, cohort_generator::TABLE_USER] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    /**
     * Reset refuses registry entries that do not point to a demo course.
     *
     * @return void
     */
    public function test_reset_refuses_real_course(): void {
        global $DB;
        $real = $this->getDataGenerator()->create_course(['shortname' => 'MATH1']);
        $id = $DB->insert_record(cohort_generator::TABLE, (object) ['seed' => 1, 'profile' => 'balanced',
            'cohortsize' => 1, 'courseid' => $real->id, 'timecreated' => time()]);
        try {
            (new cohort_generator())->reset($id);
            $this->fail('Reset of a real course accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('demo:error:notdemo', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('course', ['id' => $real->id]));
    }

    /**
     * The generator produces the demo evaluation model along the effect chain.
     *
     * @return void
     */
    public function test_demo_model(): void {
        global $DB;
        (new cohort_generator())->generate(4, 5, 'balanced');
        $model = $DB->get_record('block_catquiz_statistics_evalmodel', ['issynthetic' => 1], '*', MUST_EXIST);
        $roles = array_count_values($DB->get_fieldset_select(
            'block_catquiz_statistics_evalrole',
            'role',
            'modelid = ?',
            [$model->id]
        ));
        foreach (['covariate', 'disposition', 'exposure', 'behaviour', 'performance', 'outcome'] as $role) {
            $this->assertArrayHasKey($role, $roles, $role);
        }
        $this->assertSame(1, (int) $model->version, 'The initial demo model is one version, not one per mapping.');
        $revision = (new \block_catquiz_statistics\repository\evalmodel_repository())->get_revision((int) $model->id);
        $this->assertSame(array_sum($roles), count($revision['roles']));
        $this->assertNotEmpty($revision['config']['transitions']);
    }

    /**
     * Usernames stay unique even when the registry was lost (e.g. reinstall) and ids restart.
     *
     * @return void
     */
    public function test_usernames_unique_after_registry_loss(): void {
        global $DB;
        $gen = new cohort_generator();
        $gen->generate(2026, 4, 'balanced');
        $DB->delete_records(cohort_generator::TABLE_USER);
        $DB->delete_records(cohort_generator::TABLE);
        $id = $gen->generate(2026, 4, 'balanced');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(8, $DB->count_records_select('user', "username LIKE 'synthdemo%' AND deleted = 0"));
    }

    /**
     * The uninstall hook removes all registered demo cohorts incl. users and courses.
     *
     * @return void
     */
    public function test_uninstall_hook_removes_demo_runs(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/blocks/catquiz_statistics/db/uninstall.php');
        $gen = new cohort_generator();
        $gen->generate(1, 3, 'balanced');
        $gen->generate(2, 3, 'mixed');
        $real = $this->getDataGenerator()->create_course();

        $this->assertTrue(xmldb_block_catquiz_statistics_uninstall());

        $this->assertSame(0, $DB->count_records_select('user', "username LIKE 'synthdemo%' AND deleted = 0"));
        $this->assertSame(0, $DB->count_records_select('course', "shortname LIKE 'synthdemo-%'"));
        $this->assertTrue($DB->record_exists('course', ['id' => $real->id]));
    }
}
