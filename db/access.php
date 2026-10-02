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
 * Capabilities for block_catquiz_statistics.
 *
 * Access model (all personal-data capabilities additionally require
 * local/catquiz:view_users_feedback; this is enforced in access.php):
 *
 *   view         – see the block widget and aggregate (anonymous) summaries.
 *   viewdetails  – access the full report with per-user data (RISK_PERSONAL).
 *   viewdebug    – see per-attempt trajectories from graphicalsummary/debug_info.
 *   export       – download CSV / JSON / XLSX / ODS exports (RISK_PERSONAL).
 *   viewall      – cross-course, system-wide aggregation (manager only).
 *
 * Learning analytics / evaluation model (since 0.5, Issues #3, #7, #9):
 *
 *   viewanalytics  – aggregated learning-analytics dashboard (no personal data).
 *   configuremodel – create and edit evaluation models and role mappings.
 *   importdata     – import datasets (surveys, demographics, outcomes) (RISK_PERSONAL).
 *   viewanalyses   – run and view descriptive/regression/path analyses (RISK_PERSONAL).
 *   managedemo     – generate and reset synthetic demo cohorts (admin only, setting-gated).
 *   exportidentified – research export with plain user ids instead of pseudonyms (manager only).
 *   addinstance  – place the block on a course page.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Place the block on a course page.
    'block/catquiz_statistics:addinstance' => [
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // My-page instance – explicitly disabled.
    'block/catquiz_statistics:myaddinstance' => [
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // See the block widget and anonymous aggregate statistics.
    'block/catquiz_statistics:view' => [
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
    'block/catquiz_statistics:viewdetails' => [
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
    'block/catquiz_statistics:viewdebug' => [
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
    'block/catquiz_statistics:export' => [
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
    'block/catquiz_statistics:viewall' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Aggregated learning-analytics dashboard: cohort overview, transitions, no person-level data.
    'block/catquiz_statistics:viewanalytics' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    // Create/edit evaluation models: population, role mapping, transitions, windows.
    'block/catquiz_statistics:configuremodel' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    // Import datasets with potentially highly sensitive personal characteristics; delete datasets.
    'block/catquiz_statistics:importdata' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    // Run and view statistical analyses computed on person-level data.
    'block/catquiz_statistics:viewanalyses' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    // Generate/reset synthetic demo cohorts. No archetype: site admins only; also gated by a setting.
    'block/catquiz_statistics:managedemo' => [
        'riskbitmask'  => RISK_CONFIG | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    // Research export with plain Moodle user ids instead of pseudonyms. Explicit, justified use only.
    'block/catquiz_statistics:exportidentified' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
