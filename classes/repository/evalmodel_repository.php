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

namespace block_catquiz_statistics\repository;

use block_catquiz_statistics\analytics\analytic_role;

/**
 * Persistence of evaluation models and their role mappings.
 *
 * The analytic role of a data selector is a property of the model, not of the
 * data point: the same variable can play different roles in different models.
 * Every change to the role mapping increments the model version.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evalmodel_repository {
    /** @var string Model table. */
    public const TABLE_MODEL = 'block_catquiz_statistics_evalmodel';

    /** @var string Role mapping table. */
    public const TABLE_ROLE = 'block_catquiz_statistics_evalrole';

    /** @var string[] Allowed selector types. */
    public const SELECTORTYPES = ['variable', 'milestone', 'catquiz', 'gradeitem', 'activity'];

    /**
     * Create an evaluation model.
     *
     * @param int $contextid Context the model is configured in.
     * @param string $name Name.
     * @param array $options Optional: description, template, config (array), issynthetic.
     * @return int Model id.
     */
    public function create_model(int $contextid, string $name, array $options = []): int {
        global $DB, $USER;

        $now = time();
        return (int) $DB->insert_record(self::TABLE_MODEL, (object) [
            'contextid' => $contextid,
            'name' => $name,
            'description' => $options['description'] ?? null,
            'template' => $options['template'] ?? null,
            'version' => 1,
            'config' => isset($options['config']) ? json_encode($options['config']) : null,
            'issynthetic' => (int) !empty($options['issynthetic']),
            'usermodified' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Fetch a model.
     *
     * @param int $modelid Model id.
     * @return \stdClass|null
     */
    public function get_model(int $modelid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE_MODEL, ['id' => $modelid]) ?: null;
    }

    /**
     * Models configured in a context.
     *
     * @param int $contextid Context id.
     * @return \stdClass[]
     */
    public function get_models_for_context(int $contextid): array {
        global $DB;
        return $DB->get_records(self::TABLE_MODEL, ['contextid' => $contextid], 'name');
    }

    /**
     * Assign (or re-assign) an analytic role to a selector within a model.
     *
     * @param int $modelid Model id.
     * @param analytic_role $role Role.
     * @param string $selectortype One of self::SELECTORTYPES.
     * @param string $selector Selector, e.g. a variable key.
     * @param string|null $label Display label.
     * @param int $sortorder Order within the role.
     * @return int Role mapping id.
     * @throws \coding_exception On invalid selector type.
     */
    public function assign_role(
        int $modelid,
        analytic_role $role,
        string $selectortype,
        string $selector,
        ?string $label = null,
        int $sortorder = 0
    ): int {
        global $DB;

        if (!in_array($selectortype, self::SELECTORTYPES, true)) {
            throw new \coding_exception('Invalid selector type: ' . $selectortype);
        }
        $existing = $DB->get_record(self::TABLE_ROLE, [
            'modelid' => $modelid, 'selectortype' => $selectortype, 'selector' => $selector,
        ]);
        if ($existing) {
            $existing->role = $role->value;
            $existing->label = $label;
            $existing->sortorder = $sortorder;
            $DB->update_record(self::TABLE_ROLE, $existing);
            $id = (int) $existing->id;
        } else {
            $id = (int) $DB->insert_record(self::TABLE_ROLE, (object) [
                'modelid' => $modelid,
                'role' => $role->value,
                'selectortype' => $selectortype,
                'selector' => $selector,
                'label' => $label,
                'sortorder' => $sortorder,
                'timecreated' => time(),
            ]);
        }
        $this->bump_version($modelid);
        return $id;
    }

    /**
     * Remove a selector from a model.
     *
     * @param int $modelid Model id.
     * @param string $selectortype Selector type.
     * @param string $selector Selector.
     */
    public function unassign(int $modelid, string $selectortype, string $selector): void {
        global $DB;
        $DB->delete_records(self::TABLE_ROLE, ['modelid' => $modelid, 'selectortype' => $selectortype, 'selector' => $selector]);
        $this->bump_version($modelid);
    }

    /**
     * Role mappings of a model, ordered by role chain and sort order.
     *
     * @param int $modelid Model id.
     * @return \stdClass[] Rows with an additional 'roleenum' property.
     */
    public function get_roles(int $modelid): array {
        global $DB;
        $rows = $DB->get_records(self::TABLE_ROLE, ['modelid' => $modelid], 'sortorder, id');
        foreach ($rows as $row) {
            $row->roleenum = analytic_role::from($row->role);
        }
        return $rows;
    }

    /**
     * Role of a selector in a model, if assigned.
     *
     * @param int $modelid Model id.
     * @param string $selectortype Selector type.
     * @param string $selector Selector.
     * @return analytic_role|null
     */
    public function get_role_of(int $modelid, string $selectortype, string $selector): ?analytic_role {
        global $DB;
        $role = $DB->get_field(self::TABLE_ROLE, 'role', [
            'modelid' => $modelid, 'selectortype' => $selectortype, 'selector' => $selector,
        ]);
        return $role ? analytic_role::from($role) : null;
    }

    /**
     * Delete a model with its role mappings (never touches data points).
     *
     * @param int $modelid Model id.
     */
    public function delete_model(int $modelid): void {
        global $DB;
        $DB->delete_records(self::TABLE_ROLE, ['modelid' => $modelid]);
        $DB->delete_records(self::TABLE_MODEL, ['id' => $modelid]);
    }

    /**
     * Increment the model version after a semantic change.
     *
     * @param int $modelid Model id.
     */
    private function bump_version(int $modelid): void {
        global $DB, $USER;
        $DB->execute(
            'UPDATE {' . self::TABLE_MODEL . '} SET version = version + 1, timemodified = :now, usermodified = :userid
              WHERE id = :id',
            ['now' => time(), 'userid' => (int) ($USER->id ?? 0), 'id' => $modelid]
        );
    }
}
