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
 * Renderable for the block_catquizstatistics widget.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\output;

use renderable;
use renderer_base;
use templatable;

/**
 * Data container for the block_main.mustache template.
 */
class main implements renderable, templatable {

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
        private readonly int $courseid,
        private readonly string $reporturl,
        private readonly bool $hascatquiz,
        private readonly string $nocatquizmessage,
        private readonly bool $canviewall,
        private readonly string $adminreporturl,
    ) {
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
