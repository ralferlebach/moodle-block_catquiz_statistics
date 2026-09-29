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
 * PHPUnit tests for the learning-analytics capabilities.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics;

/**
 * Tests for the capabilities added in 0.5 and their guards.
 *
 * @covers \block_catquiz_statistics\access
 */
final class access_analytics_test extends \advanced_testcase {
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
     * Non-editing teachers see aggregates only; editing teachers may import and configure.
     *
     * @return void
     */
    public function test_course_archetypes(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $ctx = \context_course::instance($course->id);
        $teacher = $gen->create_and_enrol($course, 'teacher');
        $editor = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');

        $this->setUser($teacher);
        $this->assertTrue(access::has_viewanalytics($ctx));
        $this->assertFalse(access::has_importdata($ctx));
        $this->assertFalse(access::has_configuremodel($ctx));
        $this->assertFalse(access::has_viewanalyses($ctx));

        $this->setUser($editor);
        $this->assertTrue(access::has_importdata($ctx));
        $this->assertTrue(access::has_configuremodel($ctx));
        $this->assertTrue(access::has_viewanalyses($ctx));

        $this->setUser($student);
        $this->assertFalse(access::has_viewanalytics($ctx));
    }

    /**
     * Demo management needs site admin rights and the enabledemo setting.
     *
     * @return void
     */
    public function test_managedemo_is_setting_gated(): void {
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);

        $this->setAdminUser();
        $this->assertFalse(access::has_managedemo(), 'Disabled by default.');
        set_config('enabledemo', 1, 'block_catquiz_statistics');
        $this->assertTrue(access::has_managedemo());

        $this->setUser($manager);
        $this->assertFalse(access::has_managedemo(), 'No archetype: managers do not get it by default.');
    }
}
