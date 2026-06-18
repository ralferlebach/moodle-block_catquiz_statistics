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
 * PHPUnit tests for the block widget output class (main).
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\output;

/**
 * Tests for block_catquiz_statistics\output\main.
 *
 * @covers \block_catquiz_statistics\output\main
 */
final class main_test extends \advanced_testcase {
    /**
     * export_for_template contains all keys required by block_main.mustache.
     *
     * @return void
     */
    public function test_export_for_template_has_required_keys(): void {
        $renderer = $this->createMock(\renderer_base::class);

        $main = new main(
            courseid: 42,
            reporturl: 'https://example.com/report',
            hascatquiz: true,
            nocatquizmessage: '',
            canviewall: false,
            adminreporturl: '',
            attemptcount: 0,
            instancecount: 0
        );

        $data = $main->export_for_template($renderer);

        $required = [
            'courseid',
            'reporturl',
            'hascatquiz',
            'nocatquizmessage',
            'canviewall',
            'adminreporturl',
            'attemptcount',
            'instancecount',
            'hasattempts',
            'attemptsummary',
            'instancesummary',
            'neattemptsmsg',
            'viewreportlabel',
            'viewadminreportlabel',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $data, "Template key '{$key}' must be present.");
        }
    }

    /**
     * hasattempts is false when attemptcount is zero.
     *
     * @return void
     */
    public function test_export_for_template_hasattempts_false_when_zero(): void {
        $renderer = $this->createMock(\renderer_base::class);

        $main = new main(
            courseid: 1,
            reporturl: '/report',
            hascatquiz: true,
            nocatquizmessage: '',
            canviewall: false,
            adminreporturl: '',
            attemptcount: 0,
            instancecount: 0
        );

        $data = $main->export_for_template($renderer);
        $this->assertFalse($data['hasattempts'], 'hasattempts must be false when attemptcount = 0.');
    }

    /**
     * hasattempts is true when attemptcount is greater than zero.
     *
     * @return void
     */
    public function test_export_for_template_hasattempts_true_when_nonzero(): void {
        $renderer = $this->createMock(\renderer_base::class);

        $main = new main(
            courseid: 1,
            reporturl: '/report',
            hascatquiz: true,
            nocatquizmessage: '',
            canviewall: false,
            adminreporturl: '',
            attemptcount: 113,
            instancecount: 3
        );

        $data = $main->export_for_template($renderer);
        $this->assertTrue($data['hasattempts'], 'hasattempts must be true when attemptcount > 0.');
        $this->assertSame(113, $data['attemptcount']);
        $this->assertSame(3, $data['instancecount']);
    }

    /**
     * courseid and reporturl are passed through unchanged.
     *
     * @return void
     */
    public function test_export_for_template_passes_through_urls(): void {
        $renderer = $this->createMock(\renderer_base::class);
        $url = 'https://moodle.example.com/blocks/catquiz_statistics/report.php?courseid=99';

        $main = new main(
            courseid: 99,
            reporturl: $url,
            hascatquiz: true,
            nocatquizmessage: '',
            canviewall: true,
            adminreporturl: 'https://moodle.example.com/blocks/catquiz_statistics/adminreport.php',
            attemptcount: 0,
            instancecount: 0
        );

        $data = $main->export_for_template($renderer);
        $this->assertSame(99, $data['courseid']);
        $this->assertSame($url, $data['reporturl']);
        $this->assertTrue($data['canviewall']);
    }

    /**
     * hascatquiz = false sets nocatquizmessage and leaves reporturl intact.
     *
     * @return void
     */
    public function test_export_for_template_no_catquiz(): void {
        $renderer = $this->createMock(\renderer_base::class);

        $main = new main(
            courseid: 5,
            reporturl: '/report',
            hascatquiz: false,
            nocatquizmessage: 'local_catquiz not installed.',
            canviewall: false,
            adminreporturl: ''
        );

        $data = $main->export_for_template($renderer);
        $this->assertFalse($data['hascatquiz']);
        $this->assertSame('local_catquiz not installed.', $data['nocatquizmessage']);
    }
}
