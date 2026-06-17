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
 * Moodle callbacks for block_catquiz_statistics.
 *
 * Contains the course navigation callback that adds a CAT-Quiz-Statistik
 * link to the "Berichte" section of the course navigation.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extend course navigation with a link to the CAT-Quiz-Statistik report.
 *
 * The link is added to the "Berichte" (Reports) node of the course navigation
 * and is visible to users who hold at least the view capability when either:
 *   (a) the CAT Quiz Statistics block is placed in this course, or
 *   (b) at least one mod_adaptivequiz instance exists in the course
 *       (indicating that catquiz-based adaptive testing is configured).
 *
 * Both conditions are intentional: a course manager may want access to the
 * report even after temporarily removing the block from the course page.
 *
 * @param navigation_node $navref  Root course navigation node.
 * @param stdClass        $course  Course record.
 * @param context_course  $context Course context.
 * @return void
 */
function block_catquiz_statistics_extend_navigation_course(
    navigation_node $navref,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('block/catquiz_statistics:view', $context)) {
        return;
    }

    if (!\block_catquiz_statistics\access::is_catquiz_available()) {
        return;
    }

    if (!block_catquiz_statistics_course_qualifies((int) $course->id, $context)) {
        return;
    }

    $url = new moodle_url(
        '/blocks/catquiz_statistics/report.php',
        ['courseid' => $course->id]
    );

    $reportsnode = $navref->find('coursereports', navigation_node::TYPE_CONTAINER);
    if (!$reportsnode) {
        // No reports node present — report remains accessible via block widget and direct URL.
        return;
    }

    $reportsnode->add(
        get_string('pluginname', 'block_catquiz_statistics'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'catquiz_statistics'
    );
}

/**
 * Decide whether a course qualifies for a CAT-Quiz-Statistik report link.
 *
 * Returns true when:
 *   (a) the block is placed in the course context, OR
 *   (b) at least one mod_adaptivequiz instance exists in the course.
 *       mod_adaptivequiz presence is used as a conservative proxy for
 *       "catquiz-based testing is configured".  A more precise check
 *       against local_catquiz_tests would require schema knowledge that
 *       may change between catquiz releases.
 *
 * @param int            $courseid Course ID.
 * @param context_course $context  Course context.
 * @return bool
 */
function block_catquiz_statistics_course_qualifies(int $courseid, context_course $context): bool {
    global $DB;

    $blockexists = $DB->record_exists(
        'block_instances',
        ['blockname' => 'catquiz_statistics', 'parentcontextid' => $context->id]
    );
    if ($blockexists) {
        return true;
    }

    $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'adaptivequiz']);
    if ($moduleid > 0) {
        $coursehasadaptivequiz = $DB->record_exists(
            'course_modules',
            ['course' => $courseid, 'module' => $moduleid]
        );
        if ($coursehasadaptivequiz) {
            return true;
        }
    }

    return false;
}
