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
 * PHPUnit tests for measurement occasions in evaluation models.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\analytics;

use block_catquiz_statistics\repository\evalmodel_repository;

/**
 * Tests for occasion and occasion-aware role mappings.
 *
 * @covers \block_catquiz_statistics\analytics\occasion
 * @covers \block_catquiz_statistics\repository\evalmodel_repository
 */
final class occasion_test extends \advanced_testcase {
    /**
     * Ability observation at a given time.
     *
     * @param int $time Time.
     * @param float $theta Value.
     * @param string|null $timepoint Timepoint label.
     * @return observation
     */
    private function obs(int $time, float $theta, ?string $timepoint = null): observation {
        return new observation(
            userid: 5,
            variablekey: 'catquiz:ability:3',
            sourcecomponent: 'local_catquiz',
            sourcearea: 'ability',
            sourcekey: 'k' . $time,
            origincontextid: 1,
            valuetype: value_type::NUMERIC,
            occurredat: $time,
            timepoint: $timepoint,
            valuenumeric: $theta,
        );
    }

    /**
     * Parsing normalises valid forms and rejects invalid ones.
     *
     * @return void
     */
    public function test_parse(): void {
        foreach (['any', 'first', 'last', 'attempt:2', 'tp:T0', 'window:100-200'] as $valid) {
            $this->assertSame($valid, occasion::from_string($valid)->to_string());
        }
        $this->assertSame('any', occasion::from_string('')->to_string());
        $this->assertSame('first', occasion::from_string('  first ')->to_string());
        foreach (['attempt:0', 'window:200-100', 'tp:', 'second', 'tp:with space'] as $invalid) {
            try {
                occasion::from_string($invalid);
                $this->fail('Accepted invalid occasion: ' . $invalid);
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('Invalid occasion', $e->getMessage());
            }
        }
    }

    /**
     * Selection picks the right observation regardless of input order.
     *
     * @return void
     */
    public function test_select(): void {
        $list = [$this->obs(300, 0.9, 'T1'), $this->obs(100, 0.1, 'T0'), $this->obs(200, 0.5)];
        $values = fn(array $sel) => array_map(static fn(observation $o) => $o->valuenumeric, $sel);

        $this->assertSame([0.1], $values(occasion::from_string('first')->select($list)));
        $this->assertSame([0.9], $values(occasion::from_string('last')->select($list)));
        $this->assertSame([0.5], $values(occasion::from_string('attempt:2')->select($list)));
        $this->assertSame([], occasion::from_string('attempt:4')->select($list));
        $this->assertSame([0.1], $values(occasion::from_string('tp:T0')->select($list)));
        $this->assertSame([0.1, 0.5], $values(occasion::from_string('window:50-250')->select($list)));
        $this->assertCount(3, occasion::from_string('')->select($list));
    }

    /**
     * Pre/post: baseline and re-test of the same scale take different roles in ONE model,
     * while the same selector and occasion cannot be mapped twice.
     *
     * @return void
     */
    public function test_pre_post_in_one_model(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $models = new evalmodel_repository();
        $mid = $models->create_model(\context_course::instance($course->id)->id, 'Pre/Post');

        $models->assign_role($mid, analytic_role::PERFORMANCE, 'catquiz', 'catquiz:ability:3', 'first', 'Theta T0');
        $models->assign_role($mid, analytic_role::OUTCOME, 'catquiz', 'catquiz:ability:3', 'last', 'Theta T1');

        $this->assertSame(analytic_role::PERFORMANCE, $models->get_role_of($mid, 'catquiz', 'catquiz:ability:3', 'first'));
        $this->assertSame(analytic_role::OUTCOME, $models->get_role_of($mid, 'catquiz', 'catquiz:ability:3', 'last'));
        $this->assertNull($models->get_role_of($mid, 'catquiz', 'catquiz:ability:3'));
        $this->assertCount(2, $models->get_roles($mid));

        // Gleicher Selektor + gleicher Anlass: Umordnung statt zweiter Zeile.
        $models->assign_role($mid, analytic_role::BEHAVIOUR, 'catquiz', 'catquiz:ability:3', 'last');
        $this->assertCount(2, $models->get_roles($mid));
        $this->assertSame(analytic_role::BEHAVIOUR, $models->get_role_of($mid, 'catquiz', 'catquiz:ability:3', 'last'));

        $models->unassign($mid, 'catquiz', 'catquiz:ability:3', 'first');
        $this->assertCount(1, $models->get_roles($mid));
    }
}
