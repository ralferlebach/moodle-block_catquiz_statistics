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
 * Block class for block_catquizstatistics.
 *
 * Renders a compact entry widget on the course page that links to the full
 * report page (report.php) and, for managers, to the system-wide admin report
 * (adminreport.php).  No data is fetched or rendered inside the block itself;
 * all heavy lifting happens on the dedicated report pages.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CAT Quiz Statistics block.
 */
class block_catquizstatistics extends block_base {

    /**
     * Initialise block title.
     *
     * @return void
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_catquizstatistics');
    }

    /**
     * Plugin has site-level admin settings.
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * No per-instance configuration form in the stub.
     *
     * @return bool
     */
    public function instance_allow_config(): bool {
        return false;
    }

    /**
     * Only one instance per course is useful.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Available on course pages only; not on the dashboard.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'course-view' => true,
            'site'        => false,
            'my'          => false,
        ];
    }

    /**
     * Build and return the block content.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->footer = '';

        // Resolve course context; the block may live inside sub-contexts.
        $coursecontext = $this->page->context;
        if ($coursecontext->contextlevel !== CONTEXT_COURSE) {
            $coursecontext = $coursecontext->get_course_context(false);
        }
        if (!$coursecontext) {
            $this->content->text = '';
            return $this->content;
        }

        // Minimum requirement: capability to see the block widget.
        if (!has_capability('block/catquizstatistics:view', $coursecontext)) {
            $this->content->text = '';
            return $this->content;
        }

        $courseid   = (int) $coursecontext->instanceid;
        $hascatquiz = \block_catquizstatistics\access::is_catquiz_available();

        $reporturl = (new moodle_url(
            '/blocks/catquizstatistics/report.php',
            ['courseid' => $courseid]
        ))->out(false);

        $canviewall    = \block_catquizstatistics\access::has_viewall();
        $adminreporturl = $canviewall
            ? (new moodle_url('/blocks/catquizstatistics/adminreport.php'))->out(false)
            : '';

        $main = new \block_catquizstatistics\output\main(
            courseid: $courseid,
            reporturl: $reporturl,
            hascatquiz: $hascatquiz,
            nocatquizmessage: $hascatquiz ? '' : get_string('nocatquiz', 'block_catquizstatistics'),
            canviewall: $canviewall,
            adminreporturl: $adminreporturl,
        );

        $this->content->text = $OUTPUT->render_from_template(
            'block_catquizstatistics/block_main',
            $main->export_for_template($OUTPUT)
        );

        return $this->content;
    }
}
