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
 * Event observers for block_catquiz_statistics (semantic milestone adapters, Issue #4).
 *
 * The observer for result_page_viewed is registered in advance: the event is
 * specified in ralferlebach/moodle-mod_adaptivequiz#15 and simply never fires
 * until mod_adaptivequiz provides it. attempt_completed is observed only as a
 * signal; its adapter does not persist (canonical source: local_catquiz).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_adaptivequiz\event\result_page_viewed',
        'callback' => '\block_catquiz_statistics\observer::handle',
        'internal' => false,
    ],
    [
        'eventname' => '\local_catquiz\event\feedbacktab_clicked',
        'callback' => '\block_catquiz_statistics\observer::handle',
        'internal' => false,
    ],
    [
        'eventname' => '\local_catquiz\event\attempt_completed',
        'callback' => '\block_catquiz_statistics\observer::handle',
        'internal' => false,
    ],
];
