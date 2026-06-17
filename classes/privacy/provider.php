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
 * Privacy provider for block_catquizstatistics.
 *
 * Phase 1 / MVP: this plugin does not store personal data of its own.
 * It reads from tables owned by local_catquiz and the Moodle question
 * engine; those plugins handle export and deletion for their own data.
 *
 * Phase 3 (Module d – learning activity archival) will add own tables
 * and upgrade this provider to implement plugin\provider as well.
 *
 * @package    block_catquizstatistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquizstatistics\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider — metadata only.
 *
 * Documents which external tables are read; no own personal data is stored.
 */
class provider implements \core_privacy\local\metadata\provider {

    /**
     * Describe all personal data this plugin accesses.
     *
     * @param collection $collection Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        // Attempt data: userid, personability, status, teststrategy, JSON payload.
        $collection->add_database_table(
            'local_catquiz_attempts',
            [
                'userid'                    => 'privacy:metadata:local_catquiz_attempts',
                'personability_after_attempt' => 'privacy:metadata:local_catquiz_attempts',
                'status'                    => 'privacy:metadata:local_catquiz_attempts',
                'json'                      => 'privacy:metadata:local_catquiz_attempts',
            ],
            'privacy:metadata:local_catquiz_attempts'
        );

        // Person-ability parameters per scale and context.
        $collection->add_database_table(
            'local_catquiz_personparams',
            [
                'userid'   => 'privacy:metadata:local_catquiz_personparams',
                'ability'  => 'privacy:metadata:local_catquiz_personparams',
                'standarderror' => 'privacy:metadata:local_catquiz_personparams',
            ],
            'privacy:metadata:local_catquiz_personparams'
        );

        // Link between catquiz attempts and question engine (via uniqueid).
        $collection->add_database_table(
            'adaptivequiz_attempt',
            [
                'userid'   => 'privacy:metadata:adaptivequiz_attempt',
                'uniqueid' => 'privacy:metadata:adaptivequiz_attempt',
            ],
            'privacy:metadata:adaptivequiz_attempt'
        );

        // Per-question timing and fraction data.
        $collection->add_database_table(
            'question_attempt_steps',
            [
                'userid'      => 'privacy:metadata:question_attempt_steps',
                'fraction'    => 'privacy:metadata:question_attempt_steps',
                'timecreated' => 'privacy:metadata:question_attempt_steps',
            ],
            'privacy:metadata:question_attempt_steps'
        );

        // Raw response key-value pairs for distractor analysis.
        $collection->add_database_table(
            'question_attempt_step_data',
            [
                'name'  => 'privacy:metadata:question_attempt_step_data',
                'value' => 'privacy:metadata:question_attempt_step_data',
            ],
            'privacy:metadata:question_attempt_step_data'
        );

        return $collection;
    }
}
