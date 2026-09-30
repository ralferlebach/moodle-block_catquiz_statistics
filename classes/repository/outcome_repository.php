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
 * Outcome definitions (Issue #6) — configuration only, no values.
 *
 * A definition makes the operationalisation of an outcome explicit:
 * which source (grade item or activity), which signal, how values are
 * derived and what the absence of a record means. Nothing is interpreted
 * implicitly: pass/fail needs an explicit rule, and "no record" is only
 * turned into "not participated" or "false" when configured so.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcome_repository {
    /** @var string Table. */
    public const TABLE = 'block_catquiz_statistics_outcome';

    /** @var array Allowed signals per source type. */
    public const SIGNALS = [
        'gradeitem' => ['grade', 'passfail', 'gradepresent'],
        'activity' => ['attempt', 'submission', 'completion', 'grade'],
    ];

    /** @var array Modules supported per activity signal (completion: any module with completion). */
    public const ACTIVITY_MODULES = [
        'attempt' => ['quiz', 'adaptivequiz'],
        'submission' => ['assign'],
    ];

    /** @var string[] Allowed absence semantics. */
    public const ABSENCE = ['norecord', 'notparticipated', 'unknown', 'false'];

    /**
     * Create an outcome definition.
     *
     * @param int $contextid Context it is configured in.
     * @param string $shortname Shortname (unique within the context).
     * @param string $label Label.
     * @param string $sourcetype gradeitem | activity
     * @param int $itemid grade_items.id (gradeitem) or course_modules.id (activity).
     * @param string $signal Signal (see self::SIGNALS).
     * @param array $options valuemode (value|indicator|date), gradevalue (final|raw), passthreshold (float),
     *                       usegradepass (bool), absence (see self::ABSENCE), timepoint (string).
     * @return int Definition id.
     * @throws \coding_exception On inconsistent definitions.
     */
    public function create(
        int $contextid,
        string $shortname,
        string $label,
        string $sourcetype,
        int $itemid,
        string $signal,
        array $options = []
    ): int {
        global $DB, $USER;

        $record = $this->validate($sourcetype, $itemid, $signal, $options);
        $now = time();
        return (int) $DB->insert_record(self::TABLE, (object) ($record + [
            'contextid' => $contextid,
            'shortname' => $shortname,
            'label' => $label,
            'usermodified' => (int) ($USER->id ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }

    /**
     * Fetch a definition.
     *
     * @param int $id Definition id.
     * @return \stdClass|null
     */
    public function get(int $id): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Definitions, optionally restricted to source courses.
     *
     * @param int[]|null $courseids Source courses (null = all).
     * @return \stdClass[]
     */
    public function get_all(?array $courseids = null): array {
        global $DB;
        if ($courseids === null) {
            return $DB->get_records(self::TABLE, null, 'id');
        }
        if (empty($courseids)) {
            return [];
        }
        return $DB->get_records_list(self::TABLE, 'courseid', $courseids, 'id');
    }

    /**
     * Definitions configured in a context.
     *
     * @param int $contextid Context.
     * @return \stdClass[]
     */
    public function get_for_context(int $contextid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['contextid' => $contextid], 'shortname');
    }

    /**
     * Delete a definition (never touches gradebook or activity data).
     *
     * @param int $id Definition id.
     */
    public function delete(int $id): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Validate a definition and resolve the source course.
     *
     * @param string $sourcetype Source type.
     * @param int $itemid Item id.
     * @param string $signal Signal.
     * @param array $options Options.
     * @return array Record fields.
     * @throws \coding_exception On inconsistent definitions.
     */
    private function validate(string $sourcetype, int $itemid, string $signal, array $options): array {
        global $DB;

        if (!in_array($signal, self::SIGNALS[$sourcetype] ?? [], true)) {
            throw new \coding_exception("Signal '$signal' is not available for source '$sourcetype'");
        }
        $absence = $options['absence'] ?? 'norecord';
        if (!in_array($absence, self::ABSENCE, true)) {
            throw new \coding_exception('Invalid absence semantics: ' . $absence);
        }
        $valuemode = $options['valuemode'] ?? ($signal === 'grade' ? 'value' : 'indicator');
        if (
            !in_array($valuemode, ['value', 'indicator', 'date'], true)
                || ($valuemode === 'value' && $signal !== 'grade')
        ) {
            throw new \coding_exception("Value mode '$valuemode' is not available for signal '$signal'");
        }
        $threshold = isset($options['passthreshold']) ? (float) $options['passthreshold'] : null;
        $usegradepass = !empty($options['usegradepass']);
        if ($signal === 'passfail' && $threshold === null && !$usegradepass) {
            throw new \coding_exception('pass/fail requires an explicit rule (passthreshold or usegradepass)');
        }

        if ($sourcetype === 'gradeitem') {
            $item = $DB->get_record('grade_items', ['id' => $itemid], 'id, courseid, gradepass', MUST_EXIST);
            if ($usegradepass && (float) $item->gradepass <= 0) {
                throw new \coding_exception('The grade item has no pass grade');
            }
            $courseid = (int) $item->courseid;
        } else {
            $cm = get_coursemodule_from_id('', $itemid, 0, false, MUST_EXIST);
            $modules = self::ACTIVITY_MODULES[$signal] ?? null;
            if ($modules !== null && !in_array($cm->modname, $modules, true)) {
                throw new \coding_exception("Signal '$signal' is not supported for module '{$cm->modname}'");
            }
            $courseid = (int) $cm->course;
        }
        $timepoint = $options['timepoint'] ?? null;
        if ($timepoint !== null && !preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $timepoint)) {
            throw new \coding_exception('Invalid timepoint label');
        }

        return [
            'sourcetype' => $sourcetype,
            'courseid' => $courseid,
            'itemid' => $itemid,
            'outcomesignal' => $signal,
            'valuemode' => $valuemode,
            'gradevalue' => ($options['gradevalue'] ?? 'final') === 'raw' ? 'raw' : 'final',
            'passthreshold' => $threshold,
            'usegradepass' => (int) $usegradepass,
            'absence' => $absence,
            'timepoint' => $timepoint,
        ];
    }
}
