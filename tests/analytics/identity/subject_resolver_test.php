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
 * PHPUnit tests for identity resolution.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\analytics\identity;

use block_catquiz_statistics\repository\dataset_repository;

/**
 * Tests for subject_resolver.
 *
 * @covers \block_catquiz_statistics\analytics\identity\subject_resolver
 * @covers \block_catquiz_statistics\analytics\identity\resolution_result
 */
final class subject_resolver_test extends \advanced_testcase {
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
     * Exact idnumber matches resolve; unknown ids stay unmatched; duplicates are ambiguous.
     *
     * @return void
     */
    public function test_idnumber_matched_unmatched_ambiguous(): void {
        $gen = $this->getDataGenerator();
        $u1 = $gen->create_user(['idnumber' => 'A-100']);
        $gen->create_user(['idnumber' => 'DUP']);
        $gen->create_user(['idnumber' => 'DUP']);

        $result = (new subject_resolver())->resolve([' A-100 ', 'X-999', 'DUP', ''], 'idnumber');

        $this->assertSame(['matched' => 1, 'unmatched' => 1, 'ambiguous' => 1], $result->get_counts());
        $this->assertSame((int) $u1->id, $result->get_userid('A-100'));
        $this->assertNull($result->get_userid('X-999'), 'Unresolved identity must not be assigned.');
        $this->assertNull($result->get_userid('DUP'), 'Ambiguous identity must not be assigned.');
        $this->assertSame(2, $result->ambiguous['DUP']);
    }

    /**
     * No case-insensitive or fuzzy matching, and deleted users never match.
     *
     * @return void
     */
    public function test_no_fuzzy_and_no_deleted(): void {
        $gen = $this->getDataGenerator();
        $gen->create_user(['username' => 'student1']);
        $deleted = $gen->create_user(['idnumber' => 'GONE']);
        delete_user($deleted);

        $resolver = new subject_resolver();
        $this->assertSame(['STUDENT1'], $resolver->resolve(['STUDENT1'], 'username')->unmatched);
        $this->assertSame(['student'], $resolver->resolve(['student'], 'username')->unmatched);
        $this->assertSame(['GONE'], $resolver->resolve(['GONE'], 'idnumber')->unmatched);
    }

    /**
     * Custom profile fields can serve as the external identifier.
     *
     * @return void
     */
    public function test_profile_field_strategy(): void {
        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'pseudoid', 'name' => 'Pseudo ID']);
        $user = $gen->create_user(['profile_field_pseudoid' => 'P-7']);

        $resolver = new subject_resolver();
        $this->assertArrayHasKey('profile_field_pseudoid', $resolver->get_matchfields());
        $this->assertSame((int) $user->id, $resolver->resolve(['P-7'], 'profile_field_pseudoid')->get_userid('P-7'));
    }

    /**
     * The resolution is stored as auditable subject map.
     *
     * @return void
     */
    public function test_resolution_is_auditable(): void {
        $gen = $this->getDataGenerator();
        $user = $gen->create_user(['idnumber' => 'OK1']);
        $course = $gen->create_course();
        $repo = new dataset_repository();
        $dsid = $repo->create_dataset(\context_course::instance($course->id)->id, 'Import', 'csv', ['matchfield' => 'idnumber']);

        $repo->store_resolution($dsid, (new subject_resolver())->resolve(['OK1', 'NOPE'], 'idnumber'));

        $matched = array_values($repo->get_subjectmap($dsid, 'matched'));
        $unmatched = array_values($repo->get_subjectmap($dsid, 'unmatched'));
        $this->assertCount(1, $matched);
        $this->assertEquals($user->id, $matched[0]->userid);
        $this->assertCount(1, $unmatched);
        $this->assertNull($unmatched[0]->userid);
    }

    /**
     * Unknown strategies are rejected.
     *
     * @return void
     */
    public function test_unknown_matchfield_rejected(): void {
        $this->expectException(\coding_exception::class);
        (new subject_resolver())->resolve(['x'], 'email');
    }
}
