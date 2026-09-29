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
 * PHPUnit tests for the privacy provider.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\privacy;

use block_catquiz_statistics\analytics\identity\subject_resolver;
use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\milestone_repository;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests for the privacy provider.
 *
 * @covers \block_catquiz_statistics\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \context_course Course context. */
    private \context_course $ctx;

    /**
     * Set up a course with milestones and an identity audit for two users.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->course = $this->getDataGenerator()->create_course();
        $this->ctx = \context_course::instance($this->course->id);
    }

    /**
     * Record a result-view milestone for a user.
     *
     * @param int $userid User.
     * @return void
     */
    private function add_milestone(int $userid): void {
        (new milestone_repository())->record(
            userid: $userid,
            action: semantic_action::VIEWED,
            objecttype: object_type::FEEDBACK,
            sourcecomponent: 'mod_adaptivequiz',
            sourceevent: 'result_page_viewed',
            sourcekey: 'privacytest:' . $userid,
            origincontextid: $this->ctx->id,
            occurredat: 1000,
            origincourseid: (int) $this->course->id,
        );
    }

    /**
     * Contexts, users, export and deletion cover milestones and identity audits.
     *
     * @return void
     */
    public function test_full_cycle(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $u1 = $gen->create_user(['idnumber' => 'E1']);
        $u2 = $gen->create_user(['idnumber' => 'E2']);
        $this->add_milestone((int) $u1->id);
        $this->add_milestone((int) $u2->id);
        $datasets = new dataset_repository();
        $dsid = $datasets->create_dataset($this->ctx->id, 'Import', 'csv');
        $datasets->store_resolution($dsid, (new subject_resolver())->resolve(['E1', 'E2'], 'idnumber'));

        $contexts = provider::get_contexts_for_userid((int) $u1->id);
        $this->assertContains((int) $this->ctx->id, array_map('intval', $contexts->get_contextids()));

        $userlist = new userlist($this->ctx, 'block_catquiz_statistics');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([(int) $u1->id, (int) $u2->id], array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($u1, 'block_catquiz_statistics', [$this->ctx->id]));
        $writer = writer::with_context($this->ctx);
        $this->assertTrue($writer->has_any_data());
        $exported = $writer->get_data([get_string('privacy:export:milestones', 'block_catquiz_statistics')]);
        $this->assertCount(1, $exported->milestones);
        $identity = $writer->get_data([get_string('privacy:export:identity', 'block_catquiz_statistics')]);
        $this->assertSame('E1', $identity->identity[0]['externalid']);

        provider::delete_data_for_user(new approved_contextlist($u1, 'block_catquiz_statistics', [$this->ctx->id]));
        $this->assertFalse($DB->record_exists('block_catquiz_statistics_milestone', ['userid' => $u1->id]));
        $this->assertFalse($DB->record_exists('block_catquiz_statistics_subjectmap', ['userid' => $u1->id]));
        $this->assertTrue($DB->record_exists('block_catquiz_statistics_milestone', ['userid' => $u2->id]));

        provider::delete_data_for_users(new approved_userlist($this->ctx, 'block_catquiz_statistics', [(int) $u2->id]));
        $this->assertSame(0, $DB->count_records('block_catquiz_statistics_milestone'));
    }

    /**
     * Deleting all users in a context clears milestones and audits there, keeping the dataset itself.
     *
     * @return void
     */
    public function test_delete_all_in_context(): void {
        global $DB;
        $u1 = $this->getDataGenerator()->create_user(['idnumber' => 'E1']);
        $this->add_milestone((int) $u1->id);
        $datasets = new dataset_repository();
        $dsid = $datasets->create_dataset($this->ctx->id, 'Import', 'csv');
        $datasets->store_resolution($dsid, (new subject_resolver())->resolve(['E1'], 'idnumber'));

        provider::delete_data_for_all_users_in_context($this->ctx);

        $this->assertSame(0, $DB->count_records('block_catquiz_statistics_milestone'));
        $this->assertSame(0, $DB->count_records('block_catquiz_statistics_subjectmap'));
        $this->assertTrue($DB->record_exists('block_catquiz_statistics_dataset', ['id' => $dsid]));
    }
}
