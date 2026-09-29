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

namespace block_catquiz_statistics\analytics\identity;

/**
 * Resolves external identifiers to the canonical internal person identity (userid).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface subject_resolver_interface {
    /**
     * Resolve a list of external identifiers.
     *
     * @param string[] $externalids Raw identifiers from the source.
     * @param string $matchfield Strategy: userid | idnumber | username | profile_field_<shortname>.
     * @return resolution_result
     */
    public function resolve(array $externalids, string $matchfield): resolution_result;

    /**
     * Available match strategies (key => human readable label).
     *
     * @return array
     */
    public function get_matchfields(): array;
}
