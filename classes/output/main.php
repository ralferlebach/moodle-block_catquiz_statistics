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

    /**
     * Constructor.
     *
     * @param int    $courseid         Course ID.
     * @param string $reporturl        URL to report.php.
     * @param bool   $hascatquiz       Whether local_catquiz is available.
     * @param string $nocatquizmessage Message shown when catquiz is absent.
     * @param bool   $canviewall       Whether user has viewall capability.
     * @param string $adminreporturl   URL to adminreport.php (empty if not allowed).
     */
    public function __construct(
        int $courseid,
        string $reporturl,
        bool $hascatquiz,
        string $nocatquizmessage,
        bool $canviewall,
        string $adminreporturl
    ) {
        $this->courseid         = $courseid;
        $this->reporturl        = $reporturl;
        $this->hascatquiz       = $hascatquiz;
        $this->nocatquizmessage = $nocatquizmessage;
        $this->canviewall       = $canviewall;
        $this->adminreporturl   = $adminreporturl;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output Renderer instance.
     * @return array<string,mixed>
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'courseid'          => $this->courseid,
            'reporturl'         => $this->reporturl,
            'hascatquiz'        => $this->hascatquiz,
            'nocatquizmessage'  => $this->nocatquizmessage,
            'canviewall'        => $this->canviewall,
            'adminreporturl'    => $this->adminreporturl,
        ];
    }
}
