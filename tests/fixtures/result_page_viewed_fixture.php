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
 * Test fixture: stand-in for \mod_adaptivequiz\event\result_page_viewed.
 *
 * The real event is specified in ralferlebach/moodle-mod_adaptivequiz#15 but not
 * yet released. This fixture is only declared when the real class is absent,
 * using the specified data model: objectid = adaptivequiz_attempt.id, module
 * context, userid = attempt owner, other = instanceid/timefinished/resultstatus/resultvalid.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaptivequiz\event;

if (!class_exists('\mod_adaptivequiz\event\result_page_viewed')) {
    /**
     * Fixture event (see file docblock).
     */
    class result_page_viewed extends \core\event\base {
        /**
         * Init.
         *
         * @return void
         */
        protected function init() {
            $this->data['crud'] = 'r';
            $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
            $this->data['objecttable'] = 'adaptivequiz_attempt';
        }
    }
}
