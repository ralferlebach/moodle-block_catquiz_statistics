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
 * Renderable for the full statistics report page (course and system-wide).
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
 * Data container for the report_page.mustache template.
 */
class report_page implements renderable, templatable {
    /** @var int Course ID (0 for system-wide admin report). */
    private int $courseid;

    /** @var string Page heading string. */
    private string $heading;

    /** @var string Placeholder message shown in stub phase. */
    private string $comingsoon;

    /** @var bool True when rendering the admin system-wide report. */
    private bool $issystemwide;

    /**
     * Constructor.
     *
     * @param int    $courseid     Course ID (0 for system-wide admin report).
     * @param string $heading      Page heading string.
     * @param string $comingsoon   Placeholder message for stub phase.
     * @param bool   $issystemwide True when rendering the admin report.
     */
    public function __construct(
        int $courseid,
        string $heading,
        string $comingsoon,
        bool $issystemwide
    ) {
        $this->courseid     = $courseid;
        $this->heading      = $heading;
        $this->comingsoon   = $comingsoon;
        $this->issystemwide = $issystemwide;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output Renderer instance.
     * @return array<string,mixed>
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'courseid'     => $this->courseid,
            'heading'      => $this->heading,
            'comingsoon'   => $this->comingsoon,
            'issystemwide' => $this->issystemwide,
        ];
    }
}
