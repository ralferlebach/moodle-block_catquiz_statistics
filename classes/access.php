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
 * Centralised capability checks for block_catquiz_statistics.
 *
 * Every access decision in the plugin flows through this class so that
 * the "strict" dual-capability rule (own capability + local/catquiz cap)
 * is enforced consistently without being duplicated across entry points,
 * block get_content(), and report classes.
 *
 * Strict rules:
 *   viewdetails / viewdebug / export  →  own cap  AND  local/catquiz:view_users_feedback
 *   viewall                           →  own cap  AND  local/catquiz:canmanage (system ctx)
 *   view                              →  own cap only  (aggregate stats, no personal data)
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics;

/**
 * Static capability helper.
 */
class access {
    /** local_catquiz capability required for personal data access. */
    private const CATQUIZ_VIEW_USERS = 'local/catquiz:view_users_feedback';

    /** local_catquiz capability required for system-wide / management access. */
    private const CATQUIZ_MANAGE = 'local/catquiz:canmanage';


    /**
     * Whether the current user may see the block widget / aggregate stats.
     *
     * @param \context $context Course context (or course-parent of block context).
     * @return bool
     */
    public static function has_view(\context $context): bool {
        return has_capability('block/catquiz_statistics:view', $context);
    }


    /**
     * Whether the current user may access per-user report data.
     *
     * Requires both own capability and local/catquiz:view_users_feedback.
     *
     * @param \context $context Course context.
     * @return bool
     */
    public static function has_viewdetails(\context $context): bool {
        return has_capability('block/catquiz_statistics:viewdetails', $context)
            && has_capability(self::CATQUIZ_VIEW_USERS, $context);
    }

    /**
     * Require viewdetails access; throw exception if not granted.
     *
     * @param \context $context Course context.
     * @return void
     */
    public static function require_viewdetails(\context $context): void {
        require_capability('block/catquiz_statistics:viewdetails', $context);
        require_capability(self::CATQUIZ_VIEW_USERS, $context);
    }


    /**
     * Whether the current user may view trajectory / debug data.
     *
     * @param \context $context Course context.
     * @return bool
     */
    public static function has_viewdebug(\context $context): bool {
        return has_capability('block/catquiz_statistics:viewdebug', $context)
            && has_capability(self::CATQUIZ_VIEW_USERS, $context);
    }


    /**
     * Whether the current user may trigger exports.
     *
     * @param \context $context Course context.
     * @return bool
     */
    public static function has_export(\context $context): bool {
        return has_capability('block/catquiz_statistics:export', $context)
            && has_capability(self::CATQUIZ_VIEW_USERS, $context);
    }

    /**
     * Require export access; throw exception if not granted.
     *
     * @param \context $context Course context.
     * @return void
     */
    public static function require_export(\context $context): void {
        require_capability('block/catquiz_statistics:export', $context);
        require_capability(self::CATQUIZ_VIEW_USERS, $context);
    }


    /**
     * Whether the current user may access the system-wide admin report.
     *
     * Checked against system context.
     *
     * @return bool
     */
    public static function has_viewall(): bool {
        $sysctx = \context_system::instance();
        return has_capability('block/catquiz_statistics:viewall', $sysctx)
            && has_capability(self::CATQUIZ_MANAGE, $sysctx);
    }

    /**
     * Require system-wide access; throw exception if not granted.
     *
     * @return void
     */
    public static function require_viewall(): void {
        $sysctx = \context_system::instance();
        require_capability('block/catquiz_statistics:viewall', $sysctx);
        require_capability(self::CATQUIZ_MANAGE, $sysctx);
    }


    /**
     * Whether the local_catquiz plugin is installed and available.
     *
     * Used by the block to show a graceful "not installed" message instead
     * of an exception when local_catquiz is absent.
     *
     * @return bool
     */
    public static function is_catquiz_available(): bool {
        return class_exists('\\local_catquiz\\catquiz');
    }
}
