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
 * Renderable for the block_catquiz_statistics widget.
 *
 * Shows a compact summary (attempt count, instance count) plus links to
 * the course report and, for managers, the system-wide admin report.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\output;

use renderable;
use renderer_base;
use templatable;

/**
 * Data container for the block_main.mustache template.
 */
class main implements renderable, templatable {
    /** @var int Course ID. */
    private int $courseid;

    /** @var string URL to report.php. */
    private string $reporturl;

    /** @var bool Whether local_catquiz is available. */
    private bool $hascatquiz;

    /** @var string Message shown when catquiz is absent. */
    private string $nocatquizmessage;

    /** @var bool Whether user has viewall capability. */
    private bool $canviewall;

    /** @var string URL to adminreport.php (empty if not allowed). */
    private string $adminreporturl;

    /** @var int Total number of attempts in the course. */
    private int $attemptcount;

    /** @var int Number of distinct CAT quiz instances in the course. */
    private int $instancecount;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID.
     * @param string $reporturl URL to report.php.
     * @param bool $hascatquiz Whether local_catquiz is available.
     * @param string $nocatquizmessage Message shown when catquiz is absent.
     * @param bool $canviewall Whether user has viewall capability.
     * @param string $adminreporturl URL to adminreport.php (empty if not allowed).
     * @param int $attemptcount Total attempts in the course.
     * @param int $instancecount Number of distinct instances in the course.
     */
    public function __construct(
        int $courseid,
        string $reporturl,
        bool $hascatquiz,
        string $nocatquizmessage,
        bool $canviewall,
        string $adminreporturl,
        int $attemptcount = 0,
        int $instancecount = 0
    ) {
        $this->courseid = $courseid;
        $this->reporturl = $reporturl;
        $this->hascatquiz = $hascatquiz;
        $this->nocatquizmessage = $nocatquizmessage;
        $this->canviewall = $canviewall;
        $this->adminreporturl = $adminreporturl;
        $this->attemptcount = $attemptcount;
        $this->instancecount = $instancecount;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output Renderer instance.
     * @return array<string,mixed>
     */
    public function export_for_template(renderer_base $output): array {
        $plugin = 'block_catquiz_statistics';
        return [
            'courseid' => $this->courseid,
            'reporturl' => $this->reporturl,
            'hascatquiz' => $this->hascatquiz,
            'nocatquizmessage' => $this->nocatquizmessage,
            'canviewall' => $this->canviewall,
            'adminreporturl' => $this->adminreporturl,
            'attemptcount' => $this->attemptcount,
            'instancecount' => $this->instancecount,
            'hasattempts' => $this->attemptcount > 0,
            'attemptsummary' => get_string('block:attempts', $plugin),
            'instancesummary' => get_string('block:instances', $plugin),
            'neattemptsmsg' => get_string('block:noattempts', $plugin),
            'viewreportlabel' => get_string('viewreport', $plugin),
            'viewadminreportlabel' => get_string('viewadminreport', $plugin),
        ];
    }
}
