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
 * Privacy provider for block_catquiz_statistics.
 *
 * Since 0.5 the plugin stores own person-related data (observations,
 * semantic milestones, identity-resolution audits). This provider therefore
 * implements plugin\provider and core_userlist_provider in addition to the
 * metadata of the external tables it reads.
 *
 * @package    block_catquiz_statistics
 * @copyright  2025 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_catquiz_statistics\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Observation table. */
    private const T_OBS = 'block_catquiz_statistics_observation';

    /** @var string Milestone table. */
    private const T_MS = 'block_catquiz_statistics_milestone';

    /** @var string Subject map table. */
    private const T_MAP = 'block_catquiz_statistics_subjectmap';

    /** @var string Dataset table. */
    private const T_DS = 'block_catquiz_statistics_dataset';

    /** @var string Event map table (system level, authorship only). */
    private const T_EVMAP = 'block_catquiz_statistics_eventmap';

    /** @var string Model revision table (authorship via the model's context). */
    private const T_REV = 'block_catquiz_statistics_evalrevision';

    /** @var string[] Tables with a usermodified authorship column and a contextid. */
    private const AUTHORED = [
        'block_catquiz_statistics_dataset',
        'block_catquiz_statistics_variable',
        'block_catquiz_statistics_evalmodel',
        'block_catquiz_statistics_construct',
        'block_catquiz_statistics_outcome',
    ];

    /**
     * Describe all personal data this plugin stores or accesses.
     *
     * @param collection $collection Metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::T_OBS, [
            'userid' => 'privacy:metadata:observation:userid',
            'origincontextid' => 'privacy:metadata:observation:origin',
            'occurredat' => 'privacy:metadata:observation:occurredat',
            'timepoint' => 'privacy:metadata:observation:timepoint',
            'valuenumeric' => 'privacy:metadata:observation:value',
            'valuetext' => 'privacy:metadata:observation:value',
            'valuebool' => 'privacy:metadata:observation:value',
            'status' => 'privacy:metadata:observation:status',
        ], 'privacy:metadata:observation');

        $collection->add_database_table(self::T_MS, [
            'userid' => 'privacy:metadata:milestone:userid',
            'action' => 'privacy:metadata:milestone:semantics',
            'objecttype' => 'privacy:metadata:milestone:semantics',
            'firstoccurred' => 'privacy:metadata:milestone:times',
            'lastoccurred' => 'privacy:metadata:milestone:times',
            'occurrences' => 'privacy:metadata:milestone:times',
        ], 'privacy:metadata:milestone');

        $collection->add_database_table(self::T_MAP, [
            'userid' => 'privacy:metadata:subjectmap:userid',
            'externalid' => 'privacy:metadata:subjectmap:externalid',
            'status' => 'privacy:metadata:subjectmap:status',
        ], 'privacy:metadata:subjectmap');

        $collection->add_database_table('block_catquiz_statistics_demouser', [
            'userid' => 'privacy:metadata:demouser:userid',
        ], 'privacy:metadata:demouser');
        foreach (array_merge(self::AUTHORED, [self::T_EVMAP, self::T_REV, 'block_catquiz_statistics_demo']) as $table) {
            $collection->add_database_table($table, [
                'usermodified' => 'privacy:metadata:authored:usermodified',
            ], 'privacy:metadata:authored');
        }

        // Externe Tabellen, die gelesen, aber nicht verändert werden.
        $collection->add_database_table('local_catquiz_attempts', [
            'userid' => 'privacy:metadata:local_catquiz_attempts',
            'personability_after_attempt' => 'privacy:metadata:local_catquiz_attempts',
            'status' => 'privacy:metadata:local_catquiz_attempts',
            'json' => 'privacy:metadata:local_catquiz_attempts',
        ], 'privacy:metadata:local_catquiz_attempts');
        $collection->add_database_table('local_catquiz_personparams', [
            'userid' => 'privacy:metadata:local_catquiz_personparams',
            'ability' => 'privacy:metadata:local_catquiz_personparams',
            'standarderror' => 'privacy:metadata:local_catquiz_personparams',
        ], 'privacy:metadata:local_catquiz_personparams');
        $collection->add_database_table('adaptivequiz_attempt', [
            'userid' => 'privacy:metadata:adaptivequiz_attempt',
            'uniqueid' => 'privacy:metadata:adaptivequiz_attempt',
        ], 'privacy:metadata:adaptivequiz_attempt');
        $collection->add_database_table('question_attempt_steps', [
            'userid' => 'privacy:metadata:question_attempt_steps',
            'fraction' => 'privacy:metadata:question_attempt_steps',
            'timecreated' => 'privacy:metadata:question_attempt_steps',
        ], 'privacy:metadata:question_attempt_steps');
        $collection->add_database_table('question_attempt_step_data', [
            'name' => 'privacy:metadata:question_attempt_step_data',
            'value' => 'privacy:metadata:question_attempt_step_data',
        ], 'privacy:metadata:question_attempt_step_data');

        return $collection;
    }

    /**
     * Contexts holding data of the user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = ['userid' => $userid];
        $contextlist->add_from_sql('SELECT origincontextid FROM {' . self::T_OBS . '} WHERE userid = :userid', $params);
        $contextlist->add_from_sql('SELECT origincontextid FROM {' . self::T_MS . '} WHERE userid = :userid', $params);
        $contextlist->add_from_sql(
            'SELECT ds.contextid FROM {' . self::T_DS . '} ds
               JOIN {' . self::T_MAP . '} m ON m.datasetid = ds.id
              WHERE m.userid = :userid',
            $params
        );
        foreach (self::AUTHORED as $table) {
            $contextlist->add_from_sql('SELECT contextid FROM {' . $table . '} WHERE usermodified = :userid', $params);
        }
        $contextlist->add_from_sql(
            'SELECT m.contextid FROM {block_catquiz_statistics_evalmodel} m
               JOIN {' . self::T_REV . '} r ON r.modelid = m.id
              WHERE r.usermodified = :userid',
            $params
        );
        $contextlist->add_from_sql(
            'SELECT ctx.id FROM {context} ctx
              WHERE ctx.contextlevel = :syslevel
                AND EXISTS (SELECT 1 FROM {' . self::T_EVMAP . '} em WHERE em.usermodified = :userid)',
            $params + ['syslevel' => CONTEXT_SYSTEM]
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist User list to fill.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $params = ['contextid' => $userlist->get_context()->id];
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::T_OBS . '} WHERE origincontextid = :contextid', $params);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::T_MS . '} WHERE origincontextid = :contextid', $params);
        $userlist->add_from_sql(
            'userid',
            'SELECT m.userid FROM {' . self::T_MAP . '} m
               JOIN {' . self::T_DS . '} ds ON ds.id = m.datasetid
              WHERE ds.contextid = :contextid AND m.userid IS NOT NULL',
            $params
        );
        foreach (self::AUTHORED as $table) {
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {' . $table . '} WHERE contextid = :contextid',
                $params
            );
        }
        $userlist->add_from_sql(
            'usermodified',
            'SELECT r.usermodified FROM {' . self::T_REV . '} r
               JOIN {block_catquiz_statistics_evalmodel} m ON m.id = r.modelid
              WHERE m.contextid = :contextid',
            $params
        );
        if ($userlist->get_context()->contextlevel == CONTEXT_SYSTEM) {
            $userlist->add_from_sql('usermodified', 'SELECT usermodified FROM {' . self::T_EVMAP . '}', []);
        }
    }

    /**
     * Export all data of the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        $component = 'block_catquiz_statistics';
        foreach ($contextlist->get_contexts() as $context) {
            $params = ['userid' => $userid, 'contextid' => $context->id];

            $obs = $DB->get_records_sql(
                'SELECT o.id, o.sourcecomponent, o.sourcearea, o.occurredat, o.timepoint, o.valuetype,
                        o.valuenumeric, o.valuetext, o.valuebool, o.status, v.shortname, v.label
                   FROM {' . self::T_OBS . '} o
              LEFT JOIN {block_catquiz_statistics_variable} v ON v.id = o.variableid
                  WHERE o.userid = :userid AND o.origincontextid = :contextid
               ORDER BY o.occurredat, o.id',
                $params
            );
            $milestones = $DB->get_records(
                self::T_MS,
                ['userid' => $userid, 'origincontextid' => $context->id],
                'firstoccurred, id'
            );
            $mappings = $DB->get_records_sql(
                'SELECT m.id, m.externalid, m.matchfield, m.status, ds.name AS datasetname
                   FROM {' . self::T_MAP . '} m
                   JOIN {' . self::T_DS . '} ds ON ds.id = m.datasetid
                  WHERE m.userid = :userid AND ds.contextid = :contextid',
                $params
            );

            if ($obs) {
                $data = array_values(array_map(static fn($r) => [
                    'variable' => $r->shortname ?? '',
                    'label' => $r->label ?? '',
                    'source' => $r->sourcecomponent . '/' . $r->sourcearea,
                    'timepoint' => $r->timepoint,
                    'occurredat' => $r->occurredat ? transform::datetime($r->occurredat) : null,
                    'value' => $r->valuenumeric ?? $r->valuetext ?? $r->valuebool,
                    'status' => $r->status,
                ], $obs));
                writer::with_context($context)->export_data(
                    [get_string('privacy:export:observations', $component)],
                    (object) ['observations' => $data]
                );
            }
            if ($milestones) {
                $data = array_values(array_map(static fn($r) => [
                    'action' => $r->action,
                    'objecttype' => $r->objecttype,
                    'source' => $r->sourcecomponent,
                    'firstoccurred' => transform::datetime($r->firstoccurred),
                    'lastoccurred' => transform::datetime($r->lastoccurred),
                    'occurrences' => (int) $r->occurrences,
                ], $milestones));
                writer::with_context($context)->export_data(
                    [get_string('privacy:export:milestones', $component)],
                    (object) ['milestones' => $data]
                );
            }
            if ($mappings) {
                $data = array_values(array_map(static fn($r) => [
                    'dataset' => $r->datasetname,
                    'externalid' => $r->externalid,
                    'matchfield' => $r->matchfield,
                    'status' => $r->status,
                ], $mappings));
                writer::with_context($context)->export_data(
                    [get_string('privacy:export:identity', $component)],
                    (object) ['identity' => $data]
                );
            }
        }
    }

    /**
     * Delete the data of all users in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $DB->delete_records(self::T_OBS, ['origincontextid' => $context->id]);
        $DB->delete_records(self::T_MS, ['origincontextid' => $context->id]);
        $DB->delete_records_select(
            self::T_MAP,
            'datasetid IN (SELECT id FROM {' . self::T_DS . '} WHERE contextid = :contextid)',
            ['contextid' => $context->id]
        );
        foreach (self::AUTHORED as $table) {
            $DB->set_field($table, 'usermodified', 0, ['contextid' => $context->id]);
        }
        $DB->set_field_select(
            self::T_REV,
            'usermodified',
            0,
            'modelid IN (SELECT id FROM {block_catquiz_statistics_evalmodel} WHERE contextid = :contextid)',
            ['contextid' => $context->id]
        );
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->set_field(self::T_EVMAP, 'usermodified', 0);
        }
    }

    /**
     * Delete the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_users_in_context($context, [$userid]);
        }
    }

    /**
     * Delete the data of several users in one context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        self::delete_users_in_context($userlist->get_context(), $userlist->get_userids());
    }

    /**
     * Delete data of the given users in a context.
     *
     * @param \context $context Context.
     * @param int[] $userids Users.
     */
    private static function delete_users_in_context(\context $context, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'pu');
        $params['contextid'] = $context->id;
        $DB->delete_records_select(self::T_OBS, "origincontextid = :contextid AND userid $insql", $params);
        $DB->delete_records_select(self::T_MS, "origincontextid = :contextid AND userid $insql", $params);
        $DB->delete_records_select(
            self::T_MAP,
            "userid $insql AND datasetid IN (SELECT id FROM {" . self::T_DS . "} WHERE contextid = :contextid)",
            $params
        );
        foreach (self::AUTHORED as $table) {
            $DB->set_field_select($table, 'usermodified', 0, "contextid = :contextid AND usermodified $insql", $params);
        }
        $DB->set_field_select(
            self::T_REV,
            'usermodified',
            0,
            "usermodified $insql AND modelid IN (SELECT id FROM {block_catquiz_statistics_evalmodel} WHERE contextid = :contextid)",
            $params
        );
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->set_field_select(self::T_EVMAP, 'usermodified', 0, "usermodified $insql", $params);
        }
    }
}
