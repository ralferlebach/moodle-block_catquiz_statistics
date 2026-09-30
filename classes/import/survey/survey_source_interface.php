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

namespace block_catquiz_statistics\import\survey;

/**
 * Moodle-internal survey source (Issue #3): Questionnaire -> Dataset, Question -> Variable, Response -> Observation.
 *
 * Sources deliver raw values as strings; typing, missing codes and validation
 * happen centrally in the survey importer. Anonymous instances must never be
 * imported person-related (is_anonymous()).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface survey_source_interface {
    /**
     * Stable source key, e.g. 'questionnaire'.
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Frankenstyle component of the source activity.
     *
     * @return string
     */
    public function get_component(): string;

    /**
     * Whether the source plugin is installed.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Survey instances of a course.
     *
     * @param int $courseid Course id.
     * @return array cmid => name
     */
    public function get_instances(int $courseid): array;

    /**
     * Whether answers of this instance were collected anonymously.
     *
     * @param int $cmid Course module id.
     * @return bool
     */
    public function is_anonymous(int $cmid): bool;

    /**
     * Importable items of an instance.
     *
     * @param int $cmid Course module id.
     * @return array itemkey => ['label', 'datatype', 'measurementlevel', 'allowedvalues' (?array), 'missingcodes' (string[])]
     */
    public function get_items(int $cmid): array;

    /**
     * Completed, non-anonymous responses of an instance.
     *
     * @param int $cmid Course module id.
     * @return array list of ['responseid' => int, 'userid' => int, 'submitted' => int, 'values' => [itemkey => string]]
     */
    public function get_responses(int $cmid): array;
}
