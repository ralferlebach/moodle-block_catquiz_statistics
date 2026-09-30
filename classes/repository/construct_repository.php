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

/**
 * Construct/subscale register (Issue #3).
 *
 * A construct groups register variables (items) and carries a validated
 * scoring rule (aggregation, minimum valid items, reverse-coded items,
 * weights). Every change to items or rule increments the construct version,
 * which is recorded in the provenance of each derived score.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class construct_repository {
    /** @var string Construct table. */
    public const TABLE = 'block_catquiz_statistics_construct';

    /** @var string Item table. */
    public const TABLE_ITEM = 'block_catquiz_statistics_citem';

    /** @var string[] Allowed aggregations (no free formula language in this scope). */
    public const AGGREGATIONS = ['mean', 'sum', 'weightedmean'];

    /**
     * Create a construct or subscale.
     *
     * @param int $contextid Scope context.
     * @param string $shortname Shortname (unique within the context).
     * @param string $label Label.
     * @param string $aggregation One of self::AGGREGATIONS.
     * @param int $minvaliditems Minimum number of valid items for a score.
     * @param array $options Optional: instrument, parentid, description, issynthetic.
     * @return int Construct id.
     * @throws \coding_exception On invalid rule.
     */
    public function create(
        int $contextid,
        string $shortname,
        string $label,
        string $aggregation = 'mean',
        int $minvaliditems = 1,
        array $options = []
    ): int {
        global $DB, $USER;

        self::validate_rule($aggregation, $minvaliditems);
        $now = time();
        return (int) $DB->insert_record(self::TABLE, (object) [
            'contextid' => $contextid,
            'shortname' => $shortname,
            'label' => $label,
            'instrument' => $options['instrument'] ?? null,
            'parentid' => $options['parentid'] ?? null,
            'description' => $options['description'] ?? null,
            'aggregation' => $aggregation,
            'minvaliditems' => $minvaliditems,
            'version' => 1,
            'issynthetic' => (int) !empty($options['issynthetic']),
            'usermodified' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Replace the items of a construct.
     *
     * @param int $constructid Construct id.
     * @param array $items variableid => ['reversecoded' => bool, 'weight' => float]
     * @throws \coding_exception When a reverse-coded item has no numeric range (min/max).
     */
    public function set_items(int $constructid, array $items): void {
        global $DB;

        $variables = (new dataset_repository())->get_variables(array_keys($items));
        foreach ($items as $variableid => $item) {
            if (!isset($variables[$variableid])) {
                throw new \coding_exception('Unknown variable: ' . $variableid);
            }
            if (!empty($item['reversecoded']) && self::range_of($variables[$variableid]) === null) {
                throw new \coding_exception('Reverse coding requires allowedvalues min/max: ' . $variables[$variableid]->shortname);
            }
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE_ITEM, ['constructid' => $constructid]);
        $sort = 0;
        foreach ($items as $variableid => $item) {
            $DB->insert_record(self::TABLE_ITEM, (object) [
                'constructid' => $constructid,
                'variableid' => (int) $variableid,
                'reversecoded' => (int) !empty($item['reversecoded']),
                'weight' => (float) ($item['weight'] ?? 1.0),
                'sortorder' => $sort++,
            ]);
        }
        $this->bump_version($constructid);
        $transaction->allow_commit();
    }

    /**
     * Change the scoring rule.
     *
     * @param int $constructid Construct id.
     * @param string $aggregation Aggregation.
     * @param int $minvaliditems Minimum valid items.
     */
    public function update_rule(int $constructid, string $aggregation, int $minvaliditems): void {
        global $DB;
        self::validate_rule($aggregation, $minvaliditems);
        $DB->update_record(self::TABLE, (object) [
            'id' => $constructid, 'aggregation' => $aggregation, 'minvaliditems' => $minvaliditems,
        ]);
        $this->bump_version($constructid);
    }

    /**
     * Construct with its items (joined with variable metadata).
     *
     * @param int $constructid Construct id.
     * @return \stdClass|null Construct with property 'items' (list of rows incl. shortname, allowedvalues).
     */
    public function get(int $constructid): ?\stdClass {
        global $DB;
        $construct = $DB->get_record(self::TABLE, ['id' => $constructid]);
        if (!$construct) {
            return null;
        }
        $construct->items = array_values($DB->get_records_sql(
            'SELECT i.*, v.shortname, v.label, v.allowedvalues, v.datatype
               FROM {' . self::TABLE_ITEM . '} i
               JOIN {block_catquiz_statistics_variable} v ON v.id = i.variableid
              WHERE i.constructid = :cid
           ORDER BY i.sortorder, i.id',
            ['cid' => $constructid]
        ));
        return $construct;
    }

    /**
     * Constructs of a context, ordered for a hierarchical display (instrument, parent, shortname).
     *
     * @param int $contextid Context id.
     * @return \stdClass[]
     */
    public function get_for_context(int $contextid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['contextid' => $contextid], 'instrument, parentid, shortname');
    }

    /**
     * Numeric range [min, max] of a variable from its allowedvalues, or null.
     *
     * @param \stdClass $variable Variable row.
     * @return array|null [float min, float max]
     */
    public static function range_of(\stdClass $variable): ?array {
        $allowed = $variable->allowedvalues ? json_decode($variable->allowedvalues, true) : null;
        if (!is_array($allowed) || !isset($allowed['min'], $allowed['max'])) {
            return null;
        }
        return [(float) $allowed['min'], (float) $allowed['max']];
    }

    /**
     * Validate a scoring rule.
     *
     * @param string $aggregation Aggregation.
     * @param int $minvaliditems Minimum valid items.
     * @throws \coding_exception On invalid values.
     */
    private static function validate_rule(string $aggregation, int $minvaliditems): void {
        if (!in_array($aggregation, self::AGGREGATIONS, true) || $minvaliditems < 1) {
            throw new \coding_exception("Invalid scoring rule: $aggregation / $minvaliditems");
        }
    }

    /**
     * Increment the construct version.
     *
     * @param int $constructid Construct id.
     */
    private function bump_version(int $constructid): void {
        global $DB, $USER;
        $DB->execute(
            'UPDATE {' . self::TABLE . '} SET version = version + 1, timemodified = :now, usermodified = :uid WHERE id = :id',
            ['now' => time(), 'uid' => (int) ($USER->id ?? 0), 'id' => $constructid]
        );
    }
}
