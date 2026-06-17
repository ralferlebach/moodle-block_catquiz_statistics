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
 * Custom Behat step definitions for block_catquiz_statistics.
 *
 * @package    block_catquiz_statistics
 * @category   test
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL check in Behat step-definition files.

use Behat\Mink\Exception\ExpectationException;

/**
 * Step definitions for block_catquiz_statistics.
 */
class behat_block_catquiz_statistics extends behat_base {
    /**
     * Add the catquiz_statistics block to a course programmatically.
     *
     * Faster than driving the block-drawer through the UI.
     *
     * @Given the catquiz_statistics block is added to the :shortname course
     * @param string $shortname Course shortname.
     * @return void
     */
    public function the_block_is_added_to_course(string $shortname): void {
        global $DB;

        $course  = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        $alreadyexists = $DB->record_exists(
            'block_instances',
            ['blockname' => 'catquiz_statistics', 'parentcontextid' => $context->id]
        );
        if ($alreadyexists) {
            return;
        }

        $DB->insert_record('block_instances', (object) [
            'blockname'         => 'catquiz_statistics',
            'parentcontextid'   => $context->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => '',
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        rebuild_course_cache($course->id, true);
    }

    /**
     * Assert that the catquiz_statistics block report link is visible.
     *
     * @Then the catquiz_statistics report link should be visible
     * @return void
     */
    public function the_report_link_should_be_visible(): void {
        $this->find('css', '.block-catquiz-statistics-widget a');
    }

    /**
     * Assert that the catquiz_statistics block report link is not visible.
     *
     * @Then the catquiz_statistics report link should not be visible
     * @return void
     */
    public function the_report_link_should_not_be_visible(): void {
        try {
            $node = $this->find('css', '.block-catquiz-statistics-widget');
            if ($node) {
                throw new ExpectationException(
                    'catquiz_statistics report link is visible but should not be.',
                    $this->getSession()
                );
            }
        } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
            unset($e); // Expected: element is absent, assertion passes.
        }
    }
}
