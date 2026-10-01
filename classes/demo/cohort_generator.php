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

namespace block_catquiz_statistics\demo;

use block_catquiz_statistics\analytics\analytic_role;
use block_catquiz_statistics\analytics\evaluation\model_templates;
use block_catquiz_statistics\analytics\object_type;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\analytics\semantic_action;
use block_catquiz_statistics\import\construct_scorer;
use block_catquiz_statistics\import\csv_importer;
use block_catquiz_statistics\repository\construct_repository;
use block_catquiz_statistics\repository\dataset_repository;
use block_catquiz_statistics\repository\evalmodel_repository;
use block_catquiz_statistics\repository\milestone_repository;
use block_catquiz_statistics\repository\observation_repository;
use block_catquiz_statistics\repository\outcome_repository;

/**
 * Reproducible synthetic demo cohort (Issue #9).
 *
 * Invariant: synthetic data is always recognisable as synthetic. The generator
 * creates its own demo course ("SYNTHETIC DEMO DATA"), non-login demo users
 * without real identifiers (example.invalid, no idnumber), and flags every
 * plugin record with issynthetic = 1. Everything is registered in the demo
 * registry; reset() removes exactly what a run created — never real data.
 *
 * Level A (analytics fixture): survey/constructs, CAT results T0/T1, milestones.
 * Level B (real Moodle objects): course, users, enrolments, gradebook exam item,
 * outcome definitions. CAT attempts in the question engine are not simulated.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_generator {
    /** @var string Registry table. */
    public const TABLE = 'block_catquiz_statistics_demo';

    /** @var string Demo user table. */
    public const TABLE_USER = 'block_catquiz_statistics_demouser';

    /** @var string Prefix of demo course shortnames and usernames. */
    public const PREFIX = 'synthdemo';

    /** @var string[] Generatable data classes (plus covariates). */
    public const CLASSES = ['covariates', 'disposition', 'exposure', 'behaviour', 'performance', 'outcome'];

    /** @var int Default start of the simulated semester (2026-09-01 00:00 UTC). */
    public const DEFAULT_START = 1788220800;

    /** @var array Request cache: courseid => is demo course. */
    private static array $democourses = [];

    /** @var array Simulated disposition items (Likert 1..5). */
    private const DISPOSITION_ITEMS = [
        'se1' => 'Self-efficacy 1', 'se2' => 'Self-efficacy 2', 'se3r' => 'Self-efficacy 3 (reversed)',
        'mot1' => 'Motivation 1', 'mot2' => 'Motivation 2', 'acc1' => 'Acceptance', 'bar1' => 'Barriers',
    ];

    /** @var string[] Simulated learning offers. */
    private const OFFERS = ['Fractions', 'Equations', 'Functions'];

    /**
     * Generate a cohort.
     *
     * @param int $seed Seed.
     * @param int $size Cohort size (1..2000).
     * @param string $profile Scenario profile (see scenario::PROFILES).
     * @param string[] $classes Data classes to generate (default all).
     * @param int $start Start time of the simulated semester.
     * @return int Demo run id.
     * @throws \coding_exception On invalid configuration.
     */
    public function generate(
        int $seed,
        int $size,
        string $profile = 'balanced',
        array $classes = self::CLASSES,
        int $start = self::DEFAULT_START
    ): int {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        if ($size < 1 || $size > 2000) {
            throw new \coding_exception('Cohort size must be between 1 and 2000');
        }
        scenario::params($profile, new rng($seed));
        $unknown = array_diff($classes, self::CLASSES);
        if ($unknown) {
            throw new \coding_exception('Unknown data classes: ' . implode(', ', $unknown));
        }
        $classes = array_values(array_intersect(self::CLASSES, $classes));
        $banner = get_string('demo:banner', 'block_catquiz_statistics');

        $shortname = self::PREFIX . '-' . $seed . '-' . $profile . '-' . substr(sha1(microtime()), 0, 6);
        $course = create_course((object) [
            'fullname' => "$banner – CATQuiz Statistics (seed $seed, $profile, n = $size)",
            'shortname' => $shortname,
            'idnumber' => $shortname,
            'category' => \core_course_category::get_default()->id,
            'summary' => $banner . ': ' . get_string('demo:disclaimer', 'block_catquiz_statistics'),
            'visible' => 0,
        ]);
        $ctxid = (int) \context_course::instance($course->id)->id;
        self::$democourses = [];
        // Place the block in the demo course so it shows up in the course navigation and block area.
        $now = time();
        $blockid = $DB->insert_record('block_instances', (object) ['blockname' => 'catquiz_statistics',
            'parentcontextid' => $ctxid, 'showinsubcontexts' => 0, 'requiredbytheme' => 0, 'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null, 'defaultregion' => 'side-pre', 'defaultweight' => 0, 'configdata' => '',
            'timecreated' => $now, 'timemodified' => $now]);
        \context_block::instance($blockid);
        $demoid = (int) $DB->insert_record(self::TABLE, (object) [
            'seed' => $seed, 'profile' => $profile, 'cohortsize' => $size, 'courseid' => $course->id,
            'config' => json_encode(['classes' => $classes, 'start' => $start, 'simulated' => scenario::PROFILES]),
            'usermodified' => (int) ($USER->id ?? 0), 'timecreated' => time(),
        ]);

        $people = $this->simulate($seed, $size, $profile, $start);
        $userids = $this->create_users($demoid, $seed, $course, $people);

        $datasets = new dataset_repository();
        $obsrepo = new observation_repository();
        $syn = ['issynthetic' => true];
        $vars = [];
        $var = function (
            string $short,
            string $label,
            string $type,
            string $level,
            ?array $allowed = null
        ) use (
            $datasets,
            $ctxid,
            $syn,
            &$vars
        ): int {
            return $vars[$short] = $datasets->ensure_variable(
                $ctxid,
                'demo_' . $short,
                "$label (SYNTHETIC)",
                $type,
                $level,
                $syn + ['allowedvalues' => $allowed, 'source' => 'synthetic']
            );
        };
        $write = function (
            int $dsid,
            int $pi,
            string $short,
            $value,
            ?string $tp,
            int $time
        ) use (
            $obsrepo,
            $userids,
            $ctxid,
            $course,
            &$vars,
            $datasets
        ): void {
            $v = $datasets->get_variables([$vars[$short]])[$vars[$short]];
            $type = csv_importer::value_type_for($v->datatype);
            $obsrepo->upsert(new observation(
                userid: $userids[$pi],
                variablekey: 'var:' . $vars[$short],
                sourcecomponent: 'block_catquiz_statistics',
                sourcearea: 'synthetic',
                sourcekey: 'demo:ds' . $dsid . ':p' . $pi . ':' . $short . ':' . ($tp ?? ''),
                origincontextid: $ctxid,
                valuetype: $type,
                origincourseid: (int) $course->id,
                occurredat: $time,
                timepoint: $tp,
                valuenumeric: is_string($value) || is_bool($value) ? null : (float) $value,
                valuetext: is_string($value) ? $value : null,
                valuebool: is_bool($value) ? $value : null,
                datasetid: $dsid,
                variableid: $vars[$short],
                issynthetic: true,
            ));
        };

        $likert = ['min' => 1, 'max' => 5];
        if (in_array('covariates', $classes, true) || in_array('disposition', $classes, true)) {
            $dsid = $datasets->create_dataset($ctxid, "$banner – Survey T0", 'synthetic', $syn + ['matchfield' => 'userid']);
            if (in_array('covariates', $classes, true)) {
                $var('degree', 'Degree programme', 'categorical', 'nominal', ['categories' => ['MB', 'ST']]);
                $var('agegroup', 'Age group', 'categorical', 'nominal', ['categories' => ['18-20', '21-23', '24+']]);
                $var(
                    'priorschool',
                    'Prior schooling',
                    'categorical',
                    'nominal',
                    ['categories' => ['Abitur', 'Fachabitur', 'Other']]
                );
            }
            if (in_array('disposition', $classes, true)) {
                foreach (self::DISPOSITION_ITEMS as $s => $l) {
                    $var($s, $l, 'ordinal', 'ordinal', $likert);
                }
            }
            foreach ($people as $pi => $p) {
                $t = $p['t']['survey'];
                if (in_array('covariates', $classes, true)) {
                    foreach (['degree', 'agegroup', 'priorschool'] as $s) {
                        $write($dsid, $pi, $s, $p[$s], 'T0', $t);
                    }
                }
                if (in_array('disposition', $classes, true)) {
                    foreach (['se1', 'se2', 'se3r', 'mot1', 'mot2', 'acc1', 'bar1'] as $s) {
                        $write($dsid, $pi, $s, $p['items'][$s], 'T0', $t);
                    }
                }
            }
            if (in_array('disposition', $classes, true)) {
                $constructs = new construct_repository();
                $se = $constructs->create(
                    $ctxid,
                    'demo_selfefficacy',
                    'Self-efficacy (SYNTHETIC)',
                    'mean',
                    2,
                    ['instrument' => 'SYNTHETIC MMQ-like', 'issynthetic' => true]
                );
                $constructs->set_items($se, [$vars['se1'] => [], $vars['se2'] => [], $vars['se3r'] => ['reversecoded' => true]]);
                $mot = $constructs->create(
                    $ctxid,
                    'demo_motivation',
                    'Motivation (SYNTHETIC)',
                    'mean',
                    1,
                    ['instrument' => 'SYNTHETIC MMQ-like', 'issynthetic' => true]
                );
                $constructs->set_items($mot, [$vars['mot1'] => [], $vars['mot2'] => []]);
                (new construct_scorer())->score($se, $dsid);
                (new construct_scorer())->score($mot, $dsid);
            }
        }

        if (in_array('performance', $classes, true)) {
            $dsid = $datasets->create_dataset($ctxid, "$banner – CAT results", 'synthetic', $syn + ['matchfield' => 'userid']);
            $var('theta', 'CAT ability (theta)', 'numeric', 'interval');
            $var('theta_se', 'CAT standard error', 'numeric', 'interval');
            foreach ($people as $pi => $p) {
                if ($p['completed1']) {
                    $write($dsid, $pi, 'theta', $p['theta0'], 'T0', $p['t']['cat1end']);
                    $write($dsid, $pi, 'theta_se', $p['se0'], 'T0', $p['t']['cat1end']);
                }
                if ($p['completed2']) {
                    $write($dsid, $pi, 'theta', $p['theta1'], 'T1', $p['t']['cat2end']);
                    $write($dsid, $pi, 'theta_se', $p['se1'], 'T1', $p['t']['cat2end']);
                }
            }
        }

        $this->write_milestones($classes, $people, $userids, $ctxid, (int) $course->id);
        $outcomes = [];
        if (in_array('outcome', $classes, true)) {
            $outcomes = $this->write_outcomes($people, $userids, $course, $ctxid, $var, $write);
        }
        $this->create_model($ctxid, $vars, $outcomes);

        return $demoid;
    }

    /**
     * Simulate all persons (pure computation, deterministic for a seed).
     *
     * @param int $seed Seed.
     * @param int $size Size.
     * @param string $profile Profile.
     * @param int $start Semester start.
     * @return array personindex => simulated person
     */
    public function simulate(int $seed, int $size, string $profile, int $start): array {
        $rng = new rng($seed);
        $day = 86400;
        $people = [];
        for ($i = 1; $i <= $size; $i++) {
            $par = scenario::params($profile, $rng);
            $prior = $rng->normal($par['abilitymean'], 1.0);
            $selfeff = 0.4 * $prior + $rng->normal(0, 0.9);
            $likert = static fn(float $x) => max(1, min(5, (int) round($x)));
            $items = [
                'se1' => $likert(3 + 0.9 * $selfeff + $rng->normal(0, 0.6)),
                'se2' => $likert(3 + 0.8 * $selfeff + $rng->normal(0, 0.7)),
                'se3r' => $likert(3 - 0.8 * $selfeff + $rng->normal(0, 0.7)),
                'mot1' => $likert(3.2 + 0.5 * $selfeff + $rng->normal(0, 0.8)),
                'mot2' => $likert(3.0 + 0.5 * $selfeff + $rng->normal(0, 0.8)),
                'acc1' => $likert(3.4 + 0.3 * $selfeff + $rng->normal(0, 0.9)),
                'bar1' => $likert(2.6 - 0.4 * $selfeff + $rng->normal(0, 0.9)),
            ];
            $started1 = $rng->chance(0.93);
            $completed1 = $started1 && $rng->chance(0.92);
            $se0 = 0.28 + 0.15 * $rng->uniform();
            $theta0 = $prior + $rng->normal(0, $se0);
            $viewed = $completed1 && $rng->chance(0.55 + 0.3 * scenario::logistic($selfeff));
            $uptake = $completed1 && $rng->chance(scenario::logistic($par['uptakebase'] + $par['uptakeslope'] * $selfeff));
            $offersviewed = $uptake ? $rng->int(1, 3) : 0;
            $offerscompleted = $uptake ? $rng->int(0, $offersviewed) : 0;
            $started2 = $completed1 && $rng->chance(0.35 + 0.45 * ($uptake ? 1 : 0));
            $completed2 = $started2 && $rng->chance(0.9);
            $se1 = 0.25 + 0.15 * $rng->uniform();
            $theta1 = $prior + $par['growth'] + $par['learneffect'] * $offerscompleted + $rng->normal(0, 0.35);
            $participates = $rng->chance(scenario::logistic(1.2 + 0.8 * $theta1));
            $points = max(0, min(100, (int) round(55 + 15 * $theta1 + $rng->normal(0, 8))));
            $t0 = $start + $rng->int(0, 6) * $day + $rng->int(8, 20) * 3600;
            $people[$i] = [
                'profile' => $par['profile'],
                'degree' => (string) $rng->pick(['MB' => 3, 'ST' => 2]),
                'agegroup' => (string) $rng->pick(['18-20' => 5, '21-23' => 3, '24+' => 2]),
                'priorschool' => (string) $rng->pick(['Abitur' => 6, 'Fachabitur' => 3, 'Other' => 1]),
                'items' => $items,
                'started1' => $started1, 'completed1' => $completed1, 'viewed' => $viewed, 'uptake' => $uptake,
                'offersviewed' => $offersviewed, 'offerscompleted' => $offerscompleted,
                'started2' => $started2, 'completed2' => $completed2, 'participates' => $participates,
                'theta0' => round($theta0, 4), 'se0' => round($se0, 4), 'theta1' => round($theta1, 4), 'se1' => round($se1, 4),
                'points' => $points,
                't' => [
                    'survey' => $t0,
                    'cat1start' => $t0 + 7 * $day,
                    'cat1end' => $t0 + 7 * $day + 1800,
                    'feedback' => $t0 + 7 * $day + 2000,
                    'offers' => $t0 + 14 * $day,
                    'cat2start' => $t0 + 60 * $day,
                    'cat2end' => $t0 + 60 * $day + 1700,
                    'exam' => $start + 120 * $day,
                ],
            ];
        }
        return $people;
    }

    /**
     * Remove everything a demo run created — and nothing else.
     *
     * @param int $demoid Demo run id.
     * @return void
     * @throws \moodle_exception When the registered course is not a demo course.
     */
    public function reset(int $demoid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $run = $DB->get_record(self::TABLE, ['id' => $demoid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $run->courseid]);
        if ($course && !str_starts_with($course->shortname, self::PREFIX . '-')) {
            throw new \moodle_exception('demo:error:notdemo', 'block_catquiz_statistics');
        }
        if ($course) {
            $ctxid = (int) \context_course::instance($course->id)->id;
            $syn = ['contextid' => $ctxid, 'issynthetic' => 1];
            foreach ($DB->get_records('block_catquiz_statistics_evalmodel', $syn) as $m) {
                (new evalmodel_repository())->delete_model((int) $m->id);
            }
            foreach ((new outcome_repository())->get_for_context($ctxid) as $o) {
                (new outcome_repository())->delete((int) $o->id);
            }
            foreach ($DB->get_records('block_catquiz_statistics_dataset', $syn) as $ds) {
                (new dataset_repository())->delete_dataset((int) $ds->id);
            }
            $constructids = $DB->get_fieldset_select(
                construct_repository::TABLE,
                'id',
                'contextid = :contextid AND issynthetic = :issynthetic',
                $syn
            );
            if ($constructids) {
                $DB->delete_records_list(construct_repository::TABLE_ITEM, 'constructid', $constructids);
                $DB->delete_records_list(construct_repository::TABLE, 'id', $constructids);
            }
            $DB->delete_records(dataset_repository::TABLE_VARIABLE, $syn);
            $DB->delete_records(milestone_repository::TABLE, ['origincourseid' => $course->id, 'issynthetic' => 1]);
            $DB->delete_records(observation_repository::TABLE, ['origincourseid' => $course->id, 'issynthetic' => 1]);
        }

        foreach ($DB->get_records(self::TABLE_USER, ['demoid' => $demoid]) as $du) {
            $user = $DB->get_record('user', ['id' => $du->userid, 'deleted' => 0]);
            if ($user && str_starts_with($user->username, self::PREFIX)) {
                $DB->delete_records(milestone_repository::TABLE, ['userid' => $user->id, 'issynthetic' => 1]);
                delete_user($user);
            }
        }
        if ($course) {
            delete_course($course, false);
        }
        $DB->delete_records(self::TABLE_USER, ['demoid' => $demoid]);
        $DB->delete_records(self::TABLE, ['id' => $demoid]);
        self::$democourses = [];
    }

    /**
     * Registered demo runs.
     *
     * @return \stdClass[]
     */
    public static function get_runs(): array {
        global $DB;
        return $DB->get_records(self::TABLE, null, 'id DESC');
    }

    /**
     * Whether a course is a registered demo course (cached per request).
     *
     * @param int $courseid Course id.
     * @return bool
     */
    public static function is_demo_course(int $courseid): bool {
        global $DB;
        if (!array_key_exists($courseid, self::$democourses)) {
            self::$democourses[$courseid] = $DB->record_exists(self::TABLE, ['courseid' => $courseid]);
        }
        return self::$democourses[$courseid];
    }

    /**
     * Create non-login demo users without real identifiers and enrol them.
     *
     * @param int $demoid Demo run.
     * @param int $seed Seed.
     * @param \stdClass $course Demo course.
     * @param array $people Simulated persons.
     * @return array personindex => userid
     */
    private function create_users(int $demoid, int $seed, \stdClass $course, array $people): array {
        global $DB, $CFG;
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $userids = [];
        foreach (array_keys($people) as $pi) {
            // The demo course id is unique for the lifetime of the site (unlike the registry id, which
            // restarts after a plugin reinstall), so usernames can never collide with leftovers.
            $username = sprintf('%s%d_c%d_%04d', self::PREFIX, $seed, $course->id, $pi);
            $userid = user_create_user((object) [
                'username' => $username,
                'auth' => 'nologin',
                'firstname' => 'Synthetic',
                'lastname' => sprintf('Demo %04d', $pi),
                'email' => $username . '@example.invalid',
                'idnumber' => '',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'description' => get_string('demo:banner', 'block_catquiz_statistics'),
            ], false, false);
            $enrol->enrol_user($instance, $userid, $studentrole);
            $DB->insert_record(self::TABLE_USER, (object) ['demoid' => $demoid, 'userid' => $userid, 'personindex' => $pi]);
            $userids[$pi] = (int) $userid;
        }
        return $userids;
    }

    /**
     * Exposure and behaviour milestones.
     *
     * @param string[] $classes Data classes.
     * @param array $people Simulated persons.
     * @param array $userids personindex => userid
     * @param int $ctxid Course context.
     * @param int $courseid Course.
     * @return void
     */
    private function write_milestones(array $classes, array $people, array $userids, int $ctxid, int $courseid): void {
        $repo = new milestone_repository();
        $exposure = in_array('exposure', $classes, true);
        $behaviour = in_array('behaviour', $classes, true);
        $m = function (
            int $pi,
            semantic_action $a,
            object_type $o,
            string $key,
            int $time,
            int $count = 1,
            array $attr = []
        ) use (
            $repo,
            $userids,
            $ctxid,
            $courseid
        ): void {
            $repo->merge(
                $userids[$pi],
                $a,
                $o,
                'block_catquiz_statistics',
                'synthetic',
                'demo:' . $courseid . ':p' . $pi . ':' . $key,
                $ctxid,
                $time,
                $time + ($count - 1) * 3600,
                $count,
                $courseid,
                null,
                $attr + ['synthetic' => true],
                true
            );
        };
        foreach ($people as $pi => $p) {
            $t = $p['t'];
            if ($exposure && $p['started1']) {
                $m($pi, semantic_action::STARTED, object_type::ASSESSMENT, 'cat1start', $t['cat1start'], 1, ['attempt' => 1]);
            }
            if ($exposure && $p['completed1']) {
                $m($pi, semantic_action::COMPLETED, object_type::ASSESSMENT, 'cat1end', $t['cat1end'], 1, ['attempt' => 1]);
                $m($pi, semantic_action::DELIVERED, object_type::RECOMMENDATION, 'reco', $t['feedback']);
            }
            if ($exposure && $p['viewed']) {
                $m($pi, semantic_action::VIEWED, object_type::FEEDBACK, 'feedback', $t['feedback'], 1 + ($pi % 3));
            }
            if ($behaviour) {
                for ($o = 0; $o < $p['offersviewed']; $o++) {
                    $m(
                        $pi,
                        semantic_action::VIEWED,
                        object_type::LEARNING_ACTIVITY,
                        'offer' . $o . 'view',
                        $t['offers'] + $o * 86400,
                        1,
                        ['offer' => self::OFFERS[$o]]
                    );
                }
                for ($o = 0; $o < $p['offerscompleted']; $o++) {
                    $m(
                        $pi,
                        semantic_action::COMPLETED,
                        object_type::LEARNING_ACTIVITY,
                        'offer' . $o . 'done',
                        $t['offers'] + $o * 86400 + 5400,
                        1,
                        ['offer' => self::OFFERS[$o]]
                    );
                }
                if ($p['started2']) {
                    $m($pi, semantic_action::RESTARTED, object_type::ASSESSMENT, 'cat2start', $t['cat2start'], 1, ['attempt' => 2]);
                }
                if ($p['completed2']) {
                    $m($pi, semantic_action::COMPLETED, object_type::ASSESSMENT, 'cat2end', $t['cat2end'], 1, ['attempt' => 2]);
                }
            }
        }
    }

    /**
     * Outcomes: real gradebook exam item in the demo course plus an exam-office style participation variable.
     *
     * @param array $people Simulated persons.
     * @param array $userids personindex => userid
     * @param \stdClass $course Demo course.
     * @param int $ctxid Course context.
     * @param callable $var Variable factory.
     * @param callable $write Observation writer.
     * @return array outcome key => outcome definition id / variable
     */
    private function write_outcomes(
        array $people,
        array $userids,
        \stdClass $course,
        int $ctxid,
        callable $var,
        callable $write
    ): array {
        global $DB;
        $banner = get_string('demo:banner', 'block_catquiz_statistics');
        $item = new \grade_item(['courseid' => $course->id, 'itemtype' => 'manual',
            'itemname' => "$banner – Exam", 'grademin' => 0, 'grademax' => 100, 'gradepass' => 50], false);
        $item->insert('synthetic');
        $dsid = (new dataset_repository())->create_dataset(
            $ctxid,
            "$banner – Exam office",
            'synthetic',
            ['issynthetic' => true, 'matchfield' => 'userid']
        );
        $var('exam_participation', 'Exam participation', 'boolean', 'nominal');
        foreach ($people as $pi => $p) {
            $write($dsid, $pi, 'exam_participation', $p['participates'], 'S1', $p['t']['exam']);
            if ($p['participates']) {
                $item->update_final_grade($userids[$pi], $p['points'], 'synthetic');
                // Simulated exam date instead of "now", so the same seed yields identical time-to-event data.
                $DB->set_field('grade_grades', 'timemodified', $p['t']['exam'], ['itemid' => $item->id, 'userid' => $userids[$pi]]);
            }
        }
        $outcomes = new outcome_repository();
        return [
            'exam_points' => $outcomes->create(
                $ctxid,
                'demo_exam_points',
                "Exam points ($banner)",
                'gradeitem',
                (int) $item->id,
                'grade',
                ['timepoint' => 'S1']
            ),
            'exam_passed' => $outcomes->create(
                $ctxid,
                'demo_exam_passed',
                "Exam passed ($banner)",
                'gradeitem',
                (int) $item->id,
                'passfail',
                ['usegradepass' => true, 'timepoint' => 'S1']
            ),
        ];
    }

    /**
     * Demo evaluation model along the effect chain of the paper.
     *
     * @param int $ctxid Course context.
     * @param array $vars shortname => variable id
     * @param array $outcomes Outcome definition ids.
     * @return void
     */
    private function create_model(int $ctxid, array $vars, array $outcomes): void {
        global $DB;
        $banner = get_string('demo:banner', 'block_catquiz_statistics');
        $models = new evalmodel_repository();
        $config = model_templates::config('effectchain');
        if (isset($vars['theta'])) {
            $config['transitions'][] = ['key' => 'var:' . $vars['theta'], 'occasion' => 'tp:T1',
                'label' => get_string('step:retestresult', 'block_catquiz_statistics'),
                'definition' => get_string('step:retestresult:definition', 'block_catquiz_statistics')];
        }
        if (isset($vars['exam_participation'])) {
            $config['transitions'][] = ['key' => 'var:' . $vars['exam_participation'], 'occasion' => 'any',
                'label' => get_string('step:examparticipation', 'block_catquiz_statistics'),
                'definition' => get_string('step:examparticipation:definition', 'block_catquiz_statistics')];
        }
        $mid = $models->create_model($ctxid, "$banner – Effect chain", ['issynthetic' => true, 'template' => 'effectchain',
            'description' => 'Acceptance -> use -> effective use -> competence gain -> success (SIMULATED data)',
            'config' => $config]);
        $sort = 0;
        // Initial model: all mappings belong to version 1 (no version bump per mapping).
        $assign = static function (
            analytic_role $role,
            string $type,
            string $selector,
            string $occasion,
            ?string $label = null
        ) use (
            $models,
            $mid,
            &$sort
): void {
            $models->assign_role($mid, $role, $type, $selector, $occasion, $label, $sort++, newversion: false);
        };
        foreach (['degree', 'agegroup', 'priorschool'] as $s) {
            if (isset($vars[$s])) {
                $assign(analytic_role::COVARIATE, 'variable', 'var:' . $vars[$s], 'any');
            }
        }
        foreach ($DB->get_records(construct_repository::TABLE, ['contextid' => $ctxid, 'issynthetic' => 1]) as $c) {
            $assign(analytic_role::DISPOSITION, 'construct', 'construct:' . $c->id, 'any');
        }
        foreach (
            ['event:started:assessment', 'event:completed:assessment', 'event:viewed:feedback',
                'event:delivered:recommendation'] as $key
        ) {
            $assign(analytic_role::EXPOSURE, 'milestone', $key, 'first');
        }
        foreach (['event:viewed:learning_activity', 'event:completed:learning_activity', 'event:restarted:assessment'] as $key) {
            $assign(analytic_role::BEHAVIOUR, 'milestone', $key, 'any');
        }
        if (isset($vars['theta'])) {
            $theta = 'var:' . $vars['theta'];
            $assign(analytic_role::PERFORMANCE, 'variable', $theta, 'tp:T0', 'Theta T0');
            $assign(analytic_role::PERFORMANCE, 'variable', $theta, 'tp:T1', 'Theta T1');
        }
        foreach ($outcomes as $id) {
            $assign(analytic_role::OUTCOME, 'outcome', 'outcome:' . $id, 'any');
        }
        if (isset($vars['exam_participation'])) {
            $assign(analytic_role::OUTCOME, 'variable', 'var:' . $vars['exam_participation'], 'any');
        }
    }
}
