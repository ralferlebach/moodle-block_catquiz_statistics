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
 * PHPUnit tests for the CATquiz observation provider.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\analytics\provider;

use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\repository\attempt_repository;

/**
 * Tests for catquiz_provider.
 *
 * @covers \block_catquiz_statistics\analytics\provider\catquiz_provider
 * @covers \block_catquiz_statistics\repository\attempt_repository::get_module_contextids
 */
final class catquiz_provider_test extends \advanced_testcase {
    /**
     * Set up; skip when local_catquiz is absent.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        if (!(new attempt_repository())->check_schema_compatibility()) {
            $this->markTestSkipped('local_catquiz schema not available.');
        }
    }

    /**
     * A completed attempt yields started, completed and ability observations — read, not copied.
     *
     * @return void
     */
    public function test_completed_attempt_mapping(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course();
        $plugingen = $gen->get_plugin_generator('block_catquiz_statistics');
        $plugingen->create_catquiz_attempt([
            'userid' => $user->id,
            'courseid' => $course->id,
            'instanceid' => 999,
            'starttime' => 1000,
            'endtime' => 1600,
            'json' => \block_catquiz_statistics_generator::build_attempt_json(3, 0.8, 0.25),
        ]);

        $obs = (new catquiz_provider())->get_observations(new observation_query(userids: [$user->id]));
        $keys = array_map(static fn($o) => $o->variablekey, $obs);

        $this->assertEqualsCanonicalizing(
            ['event:started:assessment', 'event:completed:assessment', 'catquiz:ability:3'],
            $keys
        );
        $ability = $obs[array_search('catquiz:ability:3', $keys)];
        $this->assertSame(0.8, $ability->get_value());
        $this->assertSame(0.25, $ability->attributes['se']);
        $this->assertTrue($ability->attributes['isprimary']);
        $this->assertSame('TestScale', $ability->label);
        $this->assertSame((int) $course->id, $ability->origincourseid);
        // Kein Kursmodul für instanceid 999 → Kurskontext als Ursprung.
        $this->assertSame(\context_course::instance($course->id)->id, $ability->origincontextid);
        // Source-of-Truth: nichts wird in eigene Tabellen kopiert.
        $this->assertSame(0, $DB->count_records('block_catquiz_statistics_observation'));
    }

    /**
     * An invalid SE (-1) is exposed as null, never as 0; unfinished attempts yield only "started".
     *
     * @return void
     */
    public function test_invalid_se_and_unfinished_attempt(): void {
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course();
        $plugingen = $gen->get_plugin_generator('block_catquiz_statistics');
        $plugingen->create_catquiz_attempt([
            'userid' => $user->id, 'courseid' => $course->id, 'starttime' => 1000, 'endtime' => 1500,
            'json' => \block_catquiz_statistics_generator::build_attempt_json(1, 0.1, -1),
        ]);
        $plugingen->create_catquiz_attempt([
            'userid' => $user->id, 'courseid' => $course->id, 'starttime' => 2000, 'endtime' => 0,
        ]);

        $obs = (new catquiz_provider())->get_observations(new observation_query(userids: [$user->id]));
        $abilities = array_values(array_filter($obs, static fn($o) => $o->variablekey === 'catquiz:ability:1'));
        $started = array_filter($obs, static fn($o) => $o->variablekey === 'event:started:assessment');
        $completed = array_filter($obs, static fn($o) => $o->variablekey === 'event:completed:assessment');

        $this->assertCount(1, $abilities);
        $this->assertNull($abilities[0]->attributes['se']);
        $this->assertCount(2, $started);
        $this->assertCount(1, $completed);
    }

    /**
     * Course and variable filters are honoured; the default service merges providers chronologically.
     *
     * @return void
     */
    public function test_filters_and_default_service(): void {
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $plugingen = $gen->get_plugin_generator('block_catquiz_statistics');
        foreach ([[$coursea, 100], [$courseb, 300]] as [$course, $start]) {
            $plugingen->create_catquiz_attempt([
                'userid' => $user->id, 'courseid' => $course->id, 'starttime' => $start, 'endtime' => $start + 100,
            ]);
        }

        $provider = new catquiz_provider();
        $onlyb = $provider->get_observations(new observation_query(courseids: [$courseb->id]));
        $this->assertNotEmpty($onlyb);
        foreach ($onlyb as $o) {
            $this->assertSame((int) $courseb->id, $o->origincourseid);
        }
        $this->assertSame([], $provider->get_observations(new observation_query(variablekeys: ['var:*'])));

        $service = analytics_query_service::create_default();
        $this->assertContains('catquiz', $service->get_provider_keys());
        $timeline = $service->get_timeline((int) $user->id);
        $times = array_map(static fn($o) => $o->get_sorttime(), $timeline);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Timeline must be chronological across courses.');
        $this->assertSame((int) $coursea->id, $timeline[0]->origincourseid);
        $this->assertSame((int) $courseb->id, end($timeline)->origincourseid);
    }
}
