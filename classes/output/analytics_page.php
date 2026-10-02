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

namespace block_catquiz_statistics\output;

use block_catquiz_statistics\analytics\analytic_role;
use block_catquiz_statistics\analytics\analytics_query_service;
use block_catquiz_statistics\analytics\evaluation\coverage_state;
use block_catquiz_statistics\analytics\evaluation\evaluation_service;
use block_catquiz_statistics\analytics\key_labeller;
use block_catquiz_statistics\analytics\observation;
use block_catquiz_statistics\repository\evalmodel_repository;
use block_catquiz_statistics\research\analysis_runner;
use block_catquiz_statistics\research\dataset_builder;
use block_catquiz_statistics\research\path_diagram;
use block_catquiz_statistics\research\pseudonymiser;

/**
 * Learning-analytics workspace page: Data, Learning Analytics, Evaluation model, Analysis (Issue #7).
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analytics_page implements \renderable, \templatable {
    /** @var string[] Workspaces in navigation order. */
    public const WORKSPACES = ['data', 'analytics', 'model', 'analysis'];

    /**
     * Constructor.
     *
     * @param \stdClass $course Course.
     * @param string $workspace Active workspace.
     * @param int $modelid Selected model (0 = first of the course).
     * @param int $userid Selected person for the timeline (0 = none).
     * @param bool $canviewdetails Viewer may see person-level data in this course.
     * @param array $analysis Analysis request (type, outcome, predictors, covariates, rtype, sequence, syntax,
     *                        bootstrap, seed).
     */
    public function __construct(
        /** @var \stdClass Course. */
        private readonly \stdClass $course,
        /** @var string Workspace. */
        private readonly string $workspace,
        /** @var int Model id. */
        private readonly int $modelid,
        /** @var int Person id. */
        private readonly int $userid,
        /** @var bool Person-level access. */
        private readonly bool $canviewdetails,
        /** @var array Analysis request. */
        private readonly array $analysis = [],
    ) {
    }

    /**
     * Export for the mustache template.
     *
     * @param \renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $ctx = \context_course::instance($this->course->id);
        $models = (new evalmodel_repository())->get_models_for_context((int) $ctx->id);
        $modelid = $this->modelid && isset($models[$this->modelid]) ? $this->modelid : (int) (array_key_first($models) ?? 0);
        $str = static fn(string $id, $a = null) => get_string($id, 'block_catquiz_statistics', $a);

        $data = [
            'courseid' => (int) $this->course->id,
            'coursename' => format_string($this->course->fullname),
            'tabs' => array_map(fn($w) => [
                'label' => $str('workspace:' . $w),
                'url' => $this->url(['workspace' => $w, 'modelid' => $modelid])->out(false),
                'active' => $w === $this->workspace,
            ], self::WORKSPACES),
            'detailreporturl' => (new \moodle_url('/blocks/catquiz_statistics/report.php', ['courseid' => $this->course->id]))
                ->out(false),
            'models' => array_values(array_map(fn($m) => [
                'id' => (int) $m->id, 'name' => format_string($m->name), 'selected' => (int) $m->id === $modelid,
            ], $models)),
            'hasmodel' => $modelid > 0,
            'is' . $this->workspace => true,
            'actionurl' => $this->url([])->out_omit_querystring(),
            'workspace' => $this->workspace,
            'synthetic' => \block_catquiz_statistics\demo\cohort_generator::is_demo_course((int) $this->course->id)
                || ($modelid && !empty($models[$modelid]->issynthetic)),
            'banner' => $str('demo:banner'),
            'disclaimer' => $str('demo:disclaimer'),
        ];

        if ($this->workspace === 'data') {
            $data += $this->export_data((int) $ctx->id);
        }
        if (!$modelid) {
            return $data;
        }
        $svc = new evaluation_service($modelid, analytics_query_service::create_default());
        $data['model'] = $svc->get_reference() + ['description' => format_text((string) $models[$modelid]->description)];
        $population = $svc->get_population((int) $this->course->id);
        $data['population'] = [
            'n' => count($population['userids']),
            'steps' => array_map(
                fn($s) => ['label' => $this->criterion_label($s['criterion']), 'n' => $s['n']],
                $population['steps']
            ),
        ];

        if ($this->workspace === 'analytics') {
            $data += $this->export_dashboard($svc, $population['userids']);
        } else if ($this->workspace === 'model') {
            $data += $this->export_model($svc, $modelid);
        } else if ($this->workspace === 'analysis') {
            $data += $this->export_analysis($modelid, $population['userids'], $svc->get_reference());
        }
        return $data;
    }

    /**
     * Dashboard: cohort overview, funnel, performance change and person timeline.
     *
     * @param evaluation_service $svc Service.
     * @param int[] $userids Population.
     * @return array
     */
    private function export_dashboard(evaluation_service $svc, array $userids): array {
        $str = static fn(string $id, $a = null) => get_string($id, 'block_catquiz_statistics', $a);
        $total = count($userids);
        $overview = $svc->cohort_overview($userids);
        $roles = [];
        foreach ($overview['roles'] as $role => $info) {
            if ($info['selectors'] === 0) {
                continue;
            }
            $roles[] = ['label' => $str('role:' . $role), 'n' => $info['n'], 'total' => $total,
                'pct' => $total ? round(100 * $info['n'] / $total) : 0];
        }

        $states = array_map(static fn($s) => $s->value, coverage_state::cases());
        $funnel = [];
        foreach ($svc->funnel($userids)['steps'] as $step) {
            $segments = [];
            foreach ($states as $state) {
                $n = $step['counts'][$state];
                $segments[] = ['state' => $state, 'label' => $str('coverage:' . $state), 'n' => $n,
                    'pct' => $step['eligible'] ? round(100 * $n / $step['eligible'], 2) : 0, 'visible' => $n > 0];
            }
            $funnel[] = [
                'index' => $step['index'],
                'label' => format_string($step['label']),
                'definition' => $step['definition'] ? format_string($step['definition']) : null,
                'reached' => $step['counts']['reached'],
                'eligible' => $step['eligible'],
                'ratepct' => $step['rate'] === null ? null : round(100 * $step['rate']),
                'denominator' => $str('denominator:' . $step['denominator']),
                'segments' => $segments,
            ];
        }

        $change = null;
        $pairs = $svc->performance_pairs();
        if ($pairs) {
            $rc = $svc->reliable_change($userids, $pairs[0]);
            $change = ['label' => (new key_labeller())->label($pairs[0]['key']) . ' (' . $pairs[0]['from'] . ' / '
                . $pairs[0]['to'] . ')', 'assumption' => $rc['assumption']];
            foreach ($rc['summary'] as $k => $n) {
                $change['rows'][] = ['label' => $str('change:' . $k), 'n' => $n];
            }
        }

        $data = [
            'overview' => $roles,
            'funnel' => $funnel,
            'hasfunnel' => !empty($funnel),
            'legend' => array_map(fn($s) => ['state' => $s, 'label' => $str('coverage:' . $s)], $states),
            'change' => $change,
            'canviewdetails' => $this->canviewdetails,
        ];
        if ($this->canviewdetails && $userids) {
            $data += $this->export_timeline($svc, $userids);
        }
        return $data;
    }

    /**
     * Person selector and chronological timeline.
     *
     * @param evaluation_service $svc Service.
     * @param int[] $userids Population.
     * @return array
     */
    private function export_timeline(evaluation_service $svc, array $userids): array {
        global $DB;
        $users = $DB->get_records_list(
            'user',
            'id',
            array_slice($userids, 0, 500),
            'lastname, firstname',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        $selected = in_array($this->userid, $userids, true) ? $this->userid : 0;
        $persons = array_values(array_map(fn($u) => ['id' => (int) $u->id, 'name' => fullname($u),
            'selected' => (int) $u->id === $selected], $users));
        if (!$selected) {
            return ['persons' => $persons];
        }
        $labeller = new key_labeller();
        $courses = [];
        $items = [];
        $previous = null;
        foreach ($svc->timeline($selected) as $obs) {
            $cid = (int) ($obs->origincourseid ?? 0);
            $courses[$cid] = $courses[$cid] ?? ($cid ? format_string(get_course($cid)->fullname) : '');
            $items[] = [
                'coursechange' => $cid !== $previous,
                'time' => $obs->get_sorttime()
                    ? userdate($obs->get_sorttime(), get_string('strftimedatetimeshort', 'langconfig')) : '–',
                'isotime' => $obs->get_sorttime() ? gmdate('Y-m-d\TH:i:s\Z', $obs->get_sorttime()) : '',
                'label' => $labeller->label($obs->variablekey),
                'timepoint' => $obs->timepoint,
                'value' => $this->format_value($obs),
                'course' => $courses[$cid],
                'synthetic' => $obs->issynthetic,
            ];
            $previous = $cid;
        }
        return ['persons' => $persons, 'timeline' => $items, 'hastimeline' => !empty($items),
            'selectedname' => fullname($users[$selected])];
    }

    /**
     * Analysis workspace: descriptives, regression, path model, research export (Issue #8).
     *
     * @param int $modelid Model id.
     * @param int[] $userids Population.
     * @param array $reference Model reference.
     * @return array
     */
    private function export_analysis(int $modelid, array $userids, array $reference): array {
        $ctx = \context_course::instance($this->course->id);
        $str = static fn(string $id, $a = null) => get_string($id, 'block_catquiz_statistics', $a);
        if (!has_capability('block/catquiz_statistics:viewanalyses', $ctx)) {
            return ['analysisdenied' => true];
        }
        $builder = new dataset_builder($modelid);
        $wide = $builder->wide($userids, new pseudonymiser('export', 'view'));
        $runner = new analysis_runner($wide['columns'], array_values($wide['values']));
        $plugin = \core_plugin_manager::instance()->get_plugin_info('block_catquiz_statistics');
        $req = $this->analysis;

        $numeric = [];
        $categorical = [];
        foreach ($runner->describe() as $d) {
            $base = ['label' => $d['label'], 'column' => $d['column'], 'role' => $str('role:' . $d['role']),
                'n' => $d['n'], 'missing' => $d['missing'], 'missingpct' => self::f($d['missingpct'], 1)];
            if ($d['kind'] === 'numeric') {
                $numeric[] = $base + ['mean' => self::f($d['mean']), 'sd' => self::f($d['sd']), 'median' => self::f($d['median']),
                    'iqr' => self::f($d['iqr']), 'min' => self::f($d['min']), 'max' => self::f($d['max'])];
            } else {
                $freq = [];
                foreach ($d['frequencies'] as $value => $f) {
                    $freq[] = s($value) . ' ' . $f['n'] . ' (' . self::f($f['pct'], 1) . ' %)';
                }
                $categorical[] = $base + ['frequencies' => implode(' · ', $freq)];
            }
        }

        $columns = array_map(fn($c) => ['column' => $c['column'], 'label' => $c['label'],
            'role' => $str('role:' . $c['role']), 'numeric' => $runner->is_numeric($c['column']),
            'outcomeselected' => ($req['outcome'] ?? '') === $c['column'],
            'predictorselected' => in_array($c['column'], $req['predictors'] ?? [], true),
            'covariateselected' => in_array($c['column'], $req['covariates'] ?? [], true)], $wide['columns']);

        $data = [
            'analysisallowed' => true,
            'reference' => $str('analysis:reference', (object) ['model' => format_string($reference['name']),
                'version' => $reference['version'], 'n' => count($userids), 'release' => $plugin->release,
                'build' => $plugin->versiondisk, 'time' => userdate(time(), get_string('strftimedatetimeshort', 'langconfig'))]),
            'numeric' => $numeric,
            'categorical' => $categorical,
            'columns' => array_values($columns),
            'numericcolumns' => array_values(array_filter($columns, static fn($c) => $c['numeric'])),
            'rtypeauto' => ($req['rtype'] ?? 'auto') === 'auto',
            'rtypelinear' => ($req['rtype'] ?? '') === 'linear',
            'rtypelogistic' => ($req['rtype'] ?? '') === 'logistic',
            'sequencechecked' => !empty($req['sequence']),
            'syntax' => $req['syntax'] ?? '',
            'bootstrap' => (int) ($req['bootstrap'] ?? 1000),
            'seed' => (int) ($req['seed'] ?? 2026),
            'canexportidentified' => has_capability('block/catquiz_statistics:exportidentified', $ctx),
            'canexport' => \block_catquiz_statistics\access::has_export($ctx),
            'exporturl' => (new \moodle_url('/blocks/catquiz_statistics/export.php'))->out(false),
            'sesskey' => sesskey(),
        ];
        $labels = array_column($wide['columns'], 'label', 'column');
        if (($req['type'] ?? '') === 'regression' && !empty($req['outcome'])) {
            $data['regression'] = $this->export_regression($runner->regression(
                $req['outcome'],
                $req['predictors'] ?? [],
                $req['covariates'] ?? [],
                $req['rtype'] ?? 'auto',
                !empty($req['sequence'])
            ), $labels);
        }
        if (($req['type'] ?? '') === 'path' && trim($req['syntax'] ?? '') !== '') {
            $data['path'] = $this->export_path(
                $runner->path($req['syntax'], (int) ($req['bootstrap'] ?? 0), (int) ($req['seed'] ?? 1)),
                $labels
            );
        }
        return $data;
    }

    /**
     * Regression result for the template.
     *
     * @param array $r Runner result.
     * @param array $labels column => label
     * @return array
     */
    private function export_regression(array $r, array $labels): array {
        if (!$r['ok']) {
            return ['error' => $r['error']];
        }
        $fit = $r['fit'];
        $logistic = $r['type'] === 'logistic';
        $rows = [];
        foreach ($fit['coefficients'] as $term => $c) {
            $label = $labels[$term] ?? $term;
            foreach ($r['categorical'] ?? [] as $cat) {
                if ($term !== $cat && str_starts_with($term, $cat)) {
                    // Treatment-coded dummy: "<variable label>: <level>".
                    $label = ($labels[$cat] ?? $cat) . ': ' . substr($term, strlen($cat));
                }
            }
            $rows[] = ['term' => $label, 'b' => self::f($c['b'], 3), 'se' => self::f($c['se'], 3),
                'ci' => '[' . self::f($c['cilow'], 3) . ', ' . self::f($c['cihigh'], 3) . ']', 'p' => self::p($c['p']),
                'beta' => $logistic ? self::f($c['or'], 3) : (isset($c['beta']) ? self::f($c['beta'], 3) : ''),
                'orci' => $logistic ? '[' . self::f($c['orlow'], 3) . ', ' . self::f($c['orhigh'], 3) . ']' : null,
                'vif' => isset($fit['vif'][$term]) ? self::f($fit['vif'][$term], 2) : ''];
        }
        $out = ['logistic' => $logistic, 'rows' => $rows, 'n' => $fit['n'], 'ntotal' => $fit['ntotal'],
            'excluded' => $fit['excluded'], 'warnings' => array_map(
                static fn($w) => get_string('analysis:warning:' . $w, 'block_catquiz_statistics'),
                $fit['warnings'] ?? []
            )];
        if ($logistic) {
            $out['fitline'] = 'McFadden R² = ' . self::f($fit['mcfadden'], 3)
                . ' · Nagelkerke R² = ' . self::f($fit['nagelkerke'], 3)
                . ' · AIC = ' . self::f($fit['aic'], 1) . ' · BIC = ' . self::f($fit['bic'], 1);
        } else {
            $out['fitline'] = 'R² = ' . self::f($fit['r2'], 3) . ' · adj. R² = ' . self::f($fit['adjr2'], 3)
                . ' · AIC = ' . self::f($fit['aic'], 1) . ' · BIC = ' . self::f($fit['bic'], 1);
            $out['diagnostics'] = get_string('analysis:diagnostics', 'block_catquiz_statistics', (object) [
                'min' => self::f($fit['residuals']['min']), 'median' => self::f($fit['residuals']['median']),
                'max' => self::f($fit['residuals']['max']), 'cook' => $fit['cookover']]);
        }
        if ($r['sequence']) {
            $out['sequence'] = array_map(static fn($m) => [
                'step' => $m['step'],
                'predictors' => implode(', ', array_map(static fn($p) => $labels[$p] ?? $p, $m['predictors'])),
                'r2' => self::f($logistic ? $m['fit']['mcfadden'] : $m['fit']['r2'], 3),
                'change' => isset($m['change'])
                    ? 'Δ = ' . self::f($m['change']['deltar2'], 3) . ', p ' . self::p($m['change']['p']) : '',
            ], $r['sequence']['models']);
        }
        return $out;
    }

    /**
     * Path-model result for the template.
     *
     * @param array $r Runner result.
     * @param array $labels column => label
     * @return array
     */
    private function export_path(array $r, array $labels): array {
        if (!$r['ok']) {
            return ['error' => $r['error']];
        }
        $res = $r['result'];
        $paths = [];
        foreach ($res['equations'] as $to => $eq) {
            foreach ($eq['paths'] as $from => $c) {
                $paths[] = ['from' => $labels[$from] ?? $from, 'to' => $labels[$to] ?? $to, 'b' => self::f($c['b'], 3),
                    'beta' => isset($c['beta']) ? self::f($c['beta'], 3) : '', 'se' => self::f($c['se'], 3),
                    'ci' => '[' . self::f($c['cilow'], 3) . ', ' . self::f($c['cihigh'], 3) . ']', 'p' => self::p($c['p'])];
            }
        }
        $effects = [];
        foreach ($res['effects'] as $e) {
            if (empty($e['routes'])) {
                continue;
            }
            $effects[] = ['from' => $labels[$e['from']] ?? $e['from'], 'to' => $labels[$e['to']] ?? $e['to'],
                'direct' => $e['hasdirect'] ? self::f($e['direct'], 3) : '–',
                'indirect' => self::f($e['indirect'], 3), 'total' => self::f($e['total'], 3),
                'bootci' => isset($e['bootcilow']) && $e['bootcilow'] !== null
                    ? '[' . self::f($e['bootcilow'], 3) . ', ' . self::f($e['bootcihigh'], 3) . ']' : '–'];
        }
        return [
            'svg' => path_diagram::svg($r['equations'], $res, $labels),
            'paths' => $paths,
            'effects' => $effects,
            'r2' => array_map(
                static fn($k, $v) => ['variable' => $labels[$k] ?? $k, 'r2' => self::f($v['r2'], 3)],
                array_keys($res['equations']),
                $res['equations']
            ),
            'n' => $res['n'], 'ntotal' => $res['ntotal'], 'excluded' => $res['excluded'],
            'bootstrapinfo' => $res['bootstrap'] ? get_string(
                'analysis:bootstrapinfo',
                'block_catquiz_statistics',
                (object) ['b' => $res['bootstrap'], 'seed' => $res['seed']]
            ) : null,
            'warnings' => array_map(
                static fn($w) => get_string('analysis:warning:' . $w, 'block_catquiz_statistics'),
                $res['warnings']
            ),
        ];
    }

    /**
     * Format a number with fixed decimals (empty for null).
     *
     * @param float|int|null $v Value.
     * @param int $decimals Decimals.
     * @return string
     */
    private static function f(float|int|null $v, int $decimals = 2): string {
        return $v === null ? '' : format_float((float) $v, $decimals);
    }

    /**
     * Format a p-value.
     *
     * @param float $p p-value.
     * @return string
     */
    private static function p(float $p): string {
        return $p < 0.001 ? '< ' . format_float(0.001, 3) : '= ' . format_float($p, 3);
    }

    /**
     * Evaluation-model view: role mappings with operationalisation, transitions and revisions.
     *
     * @param evaluation_service $svc Service.
     * @param int $modelid Model id.
     * @return array
     */
    private function export_model(evaluation_service $svc, int $modelid): array {
        global $DB;
        $labeller = new key_labeller();
        $byrole = $svc->get_roles_by_role();
        $roles = [];
        foreach (array_merge(analytic_role::chain(), [analytic_role::COVARIATE]) as $role) {
            $items = array_map(fn($r) => ['label' => $r->label ? format_string($r->label) : $labeller->label($r->selector),
                'source' => $labeller->source($r->selector), 'occasion' => $r->occasion], $byrole[$role->value] ?? []);
            $roles[] = ['label' => get_string('role:' . $role->value, 'block_catquiz_statistics'), 'items' => $items,
                'empty' => empty($items)];
        }
        $transitions = [];
        foreach ($svc->get_transitions() as $i => $t) {
            $transitions[] = ['index' => $i + 1, 'label' => format_string($t['label'] ?? $labeller->label($t['key'])),
                'source' => $labeller->source($t['key']), 'variable' => $labeller->label($t['key']),
                'occasion' => $t['occasion'] ?? 'any', 'definition' => $t['definition'] ?? null, 'window' => $t['window'] ?? null];
        }
        $revisions = array_values(array_map(static fn($r) => ['version' => (int) $r->version,
            'time' => userdate($r->timecreated)], $DB->get_records(
                'block_catquiz_statistics_evalrevision',
                ['modelid' => $modelid],
                'version DESC',
                'id, version, timecreated',
                0,
                10
            )));
        return ['roles' => $roles, 'transitions' => $transitions, 'revisions' => $revisions];
    }

    /**
     * Data workspace: datasets, constructs and outcome definitions of the course.
     *
     * @param int $ctxid Course context id.
     * @return array
     */
    private function export_data(int $ctxid): array {
        global $DB;
        $datasets = $DB->get_records_sql(
            'SELECT d.id, d.name, d.sourcetype, d.version, d.issynthetic, d.timecreated,
                    (SELECT COUNT(DISTINCT o.userid)
                       FROM {block_catquiz_statistics_observation} o WHERE o.datasetid = d.id) AS persons,
                    (SELECT COUNT(1) FROM {block_catquiz_statistics_dataset} n WHERE n.versionof = d.id) AS superseded
               FROM {block_catquiz_statistics_dataset} d
              WHERE d.contextid = :ctx
           ORDER BY d.timecreated DESC, d.id DESC',
            ['ctx' => $ctxid]
        );
        return [
            'datasets' => array_values(array_map(static fn($d) => ['name' => format_string($d->name),
                'source' => $d->sourcetype, 'version' => (int) $d->version, 'persons' => (int) $d->persons,
                'superseded' => (int) $d->superseded > 0, 'synthetic' => (bool) $d->issynthetic,
                'time' => userdate($d->timecreated, get_string('strftimedatetimeshort', 'langconfig'))], $datasets)),
            'constructs' => array_values(array_map(static fn($c) => ['label' => format_string($c->label),
                'instrument' => $c->instrument, 'aggregation' => $c->aggregation, 'minvalid' => (int) $c->minvaliditems,
                'version' => (int) $c->version], $DB->get_records(
                    'block_catquiz_statistics_construct',
                    ['contextid' => $ctxid],
                    'instrument, shortname'
                ))),
            'outcomes' => array_values(array_map(static fn($o) => ['label' => format_string($o->label),
                'source' => $o->sourcetype, 'signal' => $o->outcomesignal, 'absence' => $o->absence,
                'timepoint' => $o->timepoint], $DB->get_records(
                    'block_catquiz_statistics_outcome',
                    ['contextid' => $ctxid],
                    'shortname'
                ))),
        ];
    }

    /**
     * Display value of an observation incl. missing semantics.
     *
     * @param observation $obs Observation.
     * @return string
     */
    private function format_value(observation $obs): string {
        if (!$obs->status->has_value()) {
            return get_string('status:' . $obs->status->value, 'block_catquiz_statistics');
        }
        $value = $obs->get_value();
        if (str_starts_with($obs->variablekey, 'event:')) {
            $n = (int) ($obs->attributes['occurrences'] ?? 1);
            return $n > 1 ? get_string('occurrences', 'block_catquiz_statistics', $n) : '';
        }
        if (is_bool($value)) {
            return get_string($value ? 'yes' : 'no');
        }
        if (is_float($value)) {
            $text = format_float($value, 2);
            if (isset($obs->attributes['se'])) {
                $text .= ' (SE ' . format_float((float) $obs->attributes['se'], 2) . ')';
            }
            return $text;
        }
        return s((string) $value);
    }

    /**
     * Label of a population criterion.
     *
     * @param array $c Criterion.
     * @return string
     */
    private function criterion_label(array $c): string {
        $labeller = new key_labeller();
        $a = (object) [
            'time' => isset($c['time']) ? userdate((int) $c['time'], get_string('strftimedate', 'langconfig')) : '',
            'variable' => isset($c['key']) ? $labeller->label($c['key']) : '',
            'value' => $c['value'] ?? '',
            'dataset' => $c['datasetid'] ?? '',
        ];
        return get_string('population:' . $c['type'], 'block_catquiz_statistics', $a);
    }

    /**
     * Page URL with parameters.
     *
     * @param array $params Extra parameters.
     * @return \moodle_url
     */
    private function url(array $params): \moodle_url {
        return new \moodle_url('/blocks/catquiz_statistics/analytics.php', ['courseid' => $this->course->id] + $params);
    }
}
