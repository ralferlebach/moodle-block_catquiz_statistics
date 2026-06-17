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
 * Capabilities for block_catquizstatistics.
 *
 * Access model (all personal-data capabilities additionally require
 * local/catquiz:view_users_feedback; this is enforced in access.php):
 *
 *   view         – see the block widget and aggregate (anonymous) summaries.
 *   viewdetails  – access the full report with per-user data (RISK_PERSONAL).
 *   viewdebug    – see per-attempt trajectories from graphicalsummary/debug_info.
 *   export       – download CSV / JSON / XLSX / ODS exports (RISK_PERSONAL).
 *   viewall      – cross-course, system-wide aggregation (manager only).
 *   addinstance  – place the block on a course page.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Place the block on a course page.
    'block/catquizstatistics:addinstance' => [
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // My-page instance – explicitly disabled.
    'block/catquizstatistics:myaddinstance' => [
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // See the block widget and anonymous aggregate statistics.
    'block/catquizstatistics:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Access the full report including per-user ability, SE and response data.
    // Additionally enforced: local/catquiz:view_users_feedback.
    'block/catquizstatistics:viewdetails' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // View per-attempt trajectories (graphicalsummary_data / debug_info).
    // Additionally enforced: local/catquiz:view_users_feedback.
    'block/catquizstatistics:viewdebug' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Trigger CSV / JSON / XLSX / ODS exports containing personal data.
    // Additionally enforced: local/catquiz:view_users_feedback.
    'block/catquizstatistics:export' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // System-wide cross-course aggregation and item analysis (adminreport.php).
    // Additionally enforced: local/catquiz:canmanage.
    'block/catquizstatistics:viewall' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

];
