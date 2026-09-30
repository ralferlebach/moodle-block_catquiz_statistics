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

namespace block_catquiz_statistics\analytics\provider;

use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\observation_query;
use block_catquiz_statistics\analytics\observation_status;
use block_catquiz_statistics\analytics\value_type;
use block_catquiz_statistics\demo\cohort_generator;
use block_catquiz_statistics\repository\outcome_repository;

/**
 * Derives outcome observations live from gradebook and activities (Issue #6).
 *
 * Variable key: outcome:<definitionid>. Nothing is copied from the gradebook.
 * Population: the users of the query, or else the gradable users of the
 * source course. For persons without a record the configured absence
 * semantics applies (default: missing_norecord). A grade row without a grade
 * is missing_notgraded. Excluded grades are not_applicable. Outcomes of a
 * registered demo course are flagged synthetic.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcome_provider implements observation_provider_interface {
    /** @var string Component of derived observations. */
    public const COMPONENT = 'block_catquiz_statistics';

    /**
     * Constructor.
     *
     * @param outcome_repository $repository Definitions.
     */
    public function __construct(
        /** @var outcome_repository Definitions. */
        private readonly outcome_repository $repository = new outcome_repository(),
    ) {
    }

    /**
     * Provider key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'outcomes';
    }

    /**
     * Observations of all matching outcome definitions.
     *
     * @param observation_query $query Query.
     * @return observation[]
     */
    public function get_observations(observation_query $query): array {
        if ($query->excludes_prefix('outcome:')) {
            return [];
        }
        $result = [];
        foreach ($this->repository->get_all($query->courseids) as $def) {
            if (!$query->matches_variable('outcome:' . $def->id)) {
                continue;
            }
            if ($query->synthetic !== null && $query->synthetic !== cohort_generator::is_demo_course((int) $def->courseid)) {
                continue;
            }
            foreach ($this->for_definition($def, $query->userids) as $obs) {
                if ($obs->status->has_value() ? $query->matches_time($obs->occurredat) : true) {
                    $result[] = $obs;
                }
            }
        }
        return $result;
    }

    /**
     * Observations of one definition.
     *
     * @param \stdClass $def Definition.
     * @param int[]|null $userids Population (null = gradable users of the source course).
     * @return observation[]
     */
    public function for_definition(\stdClass $def, ?array $userids = null): array {
        global $CFG;
        require_once($CFG->libdir . '/grade/constants.php');
        $population = $userids ?? $this->gradable_users((int) $def->courseid);
        if (empty($population)) {
            return [];
        }
        if ($def->sourcetype === 'gradeitem' || $def->outcomesignal === 'grade') {
            [$item, $records] = $this->grades($def, $population);
            $contextid = $this->context_for_item($item);
        } else {
            $records = $this->activity_signals($def, $population);
            $contextid = (int) \context_module::instance((int) $def->itemid)->id;
            $item = null;
        }

        $result = [];
        foreach ($population as $userid) {
            $result[] = $this->build($def, (int) $userid, $records[(int) $userid] ?? null, $item, $contextid);
        }
        return $result;
    }

    /**
     * Grade item metadata incl. pass grade (for data quality).
     *
     * @param \stdClass $def Definition.
     * @return \stdClass|null grade_items row (grademin, grademax, gradepass, gradetype).
     */
    public function get_grade_item(\stdClass $def): ?\stdClass {
        return $this->resolve_grade_item($def);
    }

    /**
     * Build one observation.
     *
     * @param \stdClass $def Definition.
     * @param int $userid User.
     * @param \stdClass|null $rec Source record (grade or signal) or null.
     * @param \stdClass|null $item Grade item.
     * @param int $contextid Origin context.
     * @return observation
     */
    private function build(\stdClass $def, int $userid, ?\stdClass $rec, ?\stdClass $item, int $contextid): observation {
        $status = observation_status::OBSERVED;
        $numeric = null;
        $bool = null;
        $time = $rec ? ((int) $rec->timemodified ?: null) : null;
        $valuetype = value_type::BOOLEAN;
        $grade = null;
        if ($rec && isset($rec->excluded) && (int) $rec->excluded > 0) {
            $status = observation_status::NOT_APPLICABLE;
        } else if ($rec && property_exists($rec, 'finalgrade')) {
            $grade = $def->gradevalue === 'raw' ? $rec->rawgrade : $rec->finalgrade;
        }

        $signal = $def->outcomesignal;
        if ($status === observation_status::NOT_APPLICABLE) {
            $valuetype = $this->valuetype($def, $item);
        } else if ($signal === 'gradepresent') {
            $bool = $grade !== null;
        } else if ($signal === 'grade' && $def->valuemode === 'value') {
            $valuetype = $this->valuetype($def, $item);
            if ($grade === null) {
                $status = $rec ? observation_status::MISSING_NOTGRADED : $this->absent_status($def);
            } else {
                $numeric = (float) $grade;
            }
        } else if ($signal === 'passfail') {
            if ($grade === null) {
                $status = $rec ? observation_status::MISSING_NOTGRADED : $this->absent_status($def);
            } else {
                $threshold = $def->passthreshold !== null ? (float) $def->passthreshold : (float) $item->gradepass;
                $bool = (float) $grade >= $threshold;
            }
        } else {
            // Activity signal or "grade exists" of an activity: indicator or date.
            $present = $rec !== null && ($signal !== 'grade' || $grade !== null);
            if ($def->valuemode === 'date') {
                $valuetype = value_type::DATETIME;
                if ($present) {
                    $numeric = (float) $time;
                } else {
                    $status = $this->absent_status($def);
                }
            } else if ($present) {
                $bool = true;
            } else if ($def->absence === 'false') {
                $bool = false;
            } else {
                $status = $this->absent_status($def);
            }
        }
        if (!$status->has_value()) {
            $numeric = null;
            $bool = null;
        }

        return new observation(
            userid: $userid,
            variablekey: 'outcome:' . $def->id,
            sourcecomponent: self::COMPONENT,
            sourcearea: 'outcome:' . $def->sourcetype . ':' . $signal,
            sourcekey: 'outcome:' . $def->id . ':u' . $userid,
            origincontextid: $contextid,
            valuetype: $valuetype,
            status: $status,
            origincourseid: (int) $def->courseid,
            sourceitemid: (int) $def->itemid,
            occurredat: $status->has_value() ? $time : null,
            timepoint: $def->timepoint,
            valuenumeric: $numeric,
            valuebool: $bool,
            label: $def->label,
            attributes: ['shortname' => $def->shortname, 'signal' => $signal, 'absence' => $def->absence],
            provenance: ['definitionid' => (int) $def->id, 'definitionmodified' => (int) $def->timemodified],
            issynthetic: cohort_generator::is_demo_course((int) $def->courseid),
        );
    }

    /**
     * Status for a person without a source record.
     *
     * @param \stdClass $def Definition.
     * @return observation_status
     */
    private function absent_status(\stdClass $def): observation_status {
        return match ($def->absence) {
            'notparticipated' => observation_status::MISSING_NOTPARTICIPATED,
            'unknown' => observation_status::MISSING_UNKNOWN,
            default => observation_status::MISSING_NORECORD,
        };
    }

    /**
     * Value type of a grade: scale grades are ordinal, value grades continuous.
     *
     * @param \stdClass $def Definition.
     * @param \stdClass|null $item Grade item.
     * @return value_type
     */
    private function valuetype(\stdClass $def, ?\stdClass $item): value_type {
        if ($def->outcomesignal === 'passfail' || $def->outcomesignal === 'gradepresent') {
            return value_type::BOOLEAN;
        }
        return ($item && (int) $item->gradetype === GRADE_TYPE_SCALE) ? value_type::ORDINAL : value_type::NUMERIC;
    }

    /**
     * Grade records of the population.
     *
     * @param \stdClass $def Definition.
     * @param int[] $population Users.
     * @return array [grade item, userid => grade_grades row]
     */
    private function grades(\stdClass $def, array $population): array {
        global $DB;
        $item = $this->resolve_grade_item($def);
        if (!$item) {
            return [null, []];
        }
        [$insql, $params] = $DB->get_in_or_equal($population, SQL_PARAMS_NAMED, 'gu');
        $params['itemid'] = $item->id;
        $rows = $DB->get_records_sql(
            "SELECT userid, rawgrade, finalgrade, excluded, timemodified
               FROM {grade_grades}
              WHERE itemid = :itemid AND userid $insql",
            $params
        );
        return [$item, $rows];
    }

    /**
     * Grade item of a definition (direct or of the activity).
     *
     * @param \stdClass $def Definition.
     * @return \stdClass|null
     */
    private function resolve_grade_item(\stdClass $def): ?\stdClass {
        global $DB;
        $fields = 'id, courseid, itemtype, itemmodule, iteminstance, grademin, grademax, gradepass, gradetype';
        if ($def->sourcetype === 'gradeitem') {
            return $DB->get_record('grade_items', ['id' => $def->itemid], $fields) ?: null;
        }
        $cm = get_coursemodule_from_id('', (int) $def->itemid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }
        return $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance, 'itemnumber' => 0], $fields) ?: null;
    }

    /**
     * Origin context of a grade item: module context for activity items, else course context.
     *
     * @param \stdClass|null $item Grade item.
     * @return int
     */
    private function context_for_item(?\stdClass $item): int {
        if ($item && $item->itemtype === 'mod') {
            $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid, false, IGNORE_MISSING);
            if ($cm) {
                return (int) \context_module::instance($cm->id)->id;
            }
        }
        return (int) \context_course::instance($item ? (int) $item->courseid : SITEID)->id;
    }

    /**
     * First occurrence of an activity signal per user.
     *
     * @param \stdClass $def Definition.
     * @param int[] $population Users.
     * @return array userid => record with timemodified
     */
    private function activity_signals(\stdClass $def, array $population): array {
        global $DB;
        $cm = get_coursemodule_from_id('', (int) $def->itemid, 0, false, MUST_EXIST);
        [$insql, $params] = $DB->get_in_or_equal($population, SQL_PARAMS_NAMED, 'au');
        $params['instance'] = $cm->instance;
        switch ($def->outcomesignal) {
            case 'attempt':
                if ($cm->modname === 'quiz') {
                    $sql = "SELECT userid, MIN(timefinish) AS timemodified FROM {quiz_attempts}
                             WHERE quiz = :instance AND state = 'finished' AND preview = 0 AND userid $insql GROUP BY userid";
                } else {
                    $sql = "SELECT userid, MIN(timemodified) AS timemodified FROM {adaptivequiz_attempt}
                             WHERE instance = :instance AND attemptstate = 'complete' AND userid $insql GROUP BY userid";
                }
                break;
            case 'submission':
                $sql = "SELECT userid, MIN(timemodified) AS timemodified FROM {assign_submission}
                         WHERE assignment = :instance AND status = 'submitted' AND userid $insql GROUP BY userid";
                break;
            default:
                $params['cmid'] = $cm->id;
                $sql = "SELECT userid, MIN(timemodified) AS timemodified FROM {course_modules_completion}
                         WHERE coursemoduleid = :cmid AND completionstate > 0 AND userid $insql GROUP BY userid";
        }
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Gradable users of a course (active enrolments with a gradebook role).
     *
     * @param int $courseid Course.
     * @return int[]
     */
    private function gradable_users(int $courseid): array {
        global $CFG;
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || empty($CFG->gradebookroles)) {
            return [];
        }
        // Several gradebook roles: role assignment id must be the unique first column.
        $rows = get_role_users(explode(',', $CFG->gradebookroles), $context, false, 'ra.id, u.id AS userid', 'u.id', false);
        return array_values(array_unique(array_map(static fn($r) => (int) $r->userid, $rows)));
    }
}
